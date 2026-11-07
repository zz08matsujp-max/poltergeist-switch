<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine;

use InvalidArgumentException;
use PoltergeistSwitch\Engine\Enums\GasState;
use PoltergeistSwitch\Engine\Enums\LightLevel;
use PoltergeistSwitch\Engine\Enums\ManActivity;
use PoltergeistSwitch\Engine\Enums\PowerState;
use PoltergeistSwitch\Engine\Values\WorldSnapshot;

/**
 * Owns all mutable world state for one episode: rooms, objects, robots,
 * the resident, and house-wide flags. It holds no rules logic.
 *
 * Task 6.2 implemented room/object initialisation and snapshot/restore.
 * Task 7.1 adds `reset()` with SituationSpec application and makes WorldModel
 * implement SituationView so SituationTextGenerator can read from the live
 * world. `objects_visible_to()` (task 8) is added later.
 *
 * Requirements: Req 1.1–1.12, Req 2.1–2.2, Req 8.5–8.9
 */
class WorldModel implements SituationView
{
    /** Initial temperature for every room on reset, in °C (Req 1.9). */
    private const INITIAL_ROOM_TEMPERATURE = 22.0;

    /** @var array<string,Room> room key => Room */
    public array $rooms = [];

    /** @var array<string,ObjectState> object id => ObjectState */
    public array $objects = [];

    /** @var array<string,RobotState> 'robot_a'|'robot_b' => RobotState */
    public array $robotStates = [];

    public ResidentState $residentState;

    public HouseState $houseState;

    public int $currentTurn = 0;

    public ?int $earthquakeStartTurn = null;

    public bool $episodeEnded = false;

    public bool $disasterSubsided = false;

    /** Kept after reset() so EarthquakePhase can derive deterministic draws. */
    public Seed $seed;

    /** Kept after reset() so the text generator and rules can read the start-up layout. */
    public SituationSpec $situationSpec;

    /**
     * EpisodeConfig supplies the resident's initial state values. It is held as
     * a dependency because reset()'s signature (per design §a) takes only a
     * Seed and an optional SituationSpec, so the config cannot be passed in per
     * call. A default EpisodeConfig is used when the caller supplies none.
     */
    private EpisodeConfig $config;

    public function __construct(?EpisodeConfig $config = null)
    {
        $this->config = $config ?? new EpisodeConfig();

        $this->initRooms();
        $this->initObjects();

        // Build an initial snapshot-able shape. reset() is the authoritative
        // entry point for positioning agents and applying a SituationSpec; the
        // constructor only guarantees every field is set before first use.
        $this->robotStates = [
            'robot_a' => new RobotState('robot_a', 'living_room'),
            'robot_b' => new RobotState('robot_b', 'living_room'),
        ];
        $this->residentState = new ResidentState(
            bodyTemp: $this->config->residentInitBodyTemp,
            water: $this->config->residentInitWater,
            injury: $this->config->residentInitInjury,
            fear: $this->config->residentInitFear,
            weakened: false,
            room: 'living_room',
            spot: null,
            activity: ManActivity::sitting_at_table->value,
        );
        $this->houseState = new HouseState(
            power: PowerState::on,
            gasMain: GasState::open,
            floorWaterLevel: 0.0,
            shaking: false,
            fallingObjects: false,
            timeOfDay: '',
        );
    }

    /**
     * Rebuild the whole world for the start of an episode (Req 1.1–1.12).
     *
     * reset() is deterministic: two calls with the same seed and the same spec
     * (or no spec, so both derive the same SituationSpec from the seed) produce
     * identical observable state (Req 1.11). The passed Seed is stored for
     * EarthquakePhase; when no explicit spec is given the spec is derived from
     * that same seed via SituationSpec::fromSeed().
     *
     * Steps (design §a):
     *   1. store the seed
     *   2/3. resolve the SituationSpec (derive from seed, or use the given one)
     *   4/5. re-initialise rooms and objects
     *   6. apply the fixed initial HouseState
     *   7. apply the resident's initial state from EpisodeConfig
     *   8. place robots and the resident per the SituationSpec
     *      (incl. the boiling pot when potBoiling=true)
     *   9. reset the episode counters/flags
     *
     * @param Seed               $seed the episode seed (stored for EarthquakePhase)
     * @param SituationSpec|null  $spec explicit start layout; derived from the
     *                                  seed when null
     */
    public function reset(Seed $seed, ?SituationSpec $spec = null): void
    {
        // 1. keep the seed for later deterministic draws (EarthquakePhase).
        $this->seed = $seed;

        // 2/3. resolve the start layout.
        $this->situationSpec = $spec ?? SituationSpec::fromSeed($seed);

        // 4/5. rebuild rooms and the object registry to their canonical state.
        $this->initRooms();
        $this->initObjects();

        // 6. fixed initial house state (Req 1.9).
        $this->houseState = new HouseState(
            power: PowerState::on,
            gasMain: GasState::open,
            floorWaterLevel: 0.0,
            shaking: false,
            fallingObjects: false,
            timeOfDay: $this->situationSpec->time,
        );
        foreach ($this->rooms as $room) {
            $room->temperature = self::INITIAL_ROOM_TEMPERATURE;
            $room->lightLevel = LightLevel::normal;
            $room->hotWaterInjuryHazard = false;
        }

        // 7/8. resident initial state from EpisodeConfig, placed per the spec.
        $this->placeResident($this->situationSpec->manActivity);

        // 8. place the two robots per the spec.
        $this->robotStates = [
            'robot_a' => new RobotState('robot_a', $this->situationSpec->robotARoom),
            'robot_b' => new RobotState('robot_b', $this->situationSpec->robotBRoom),
        ];

        // 8 (cont.). a boiling pot means the stove is lit and a pot sits on it
        // (Req 1.7, Req 8.8).
        if ($this->situationSpec->potBoiling) {
            $this->lightStoveWithPot();
        }

        // 9. reset episode counters and flags.
        $this->currentTurn = 0;
        $this->earthquakeStartTurn = null;
        $this->episodeEnded = false;
        $this->disasterSubsided = false;
    }

    /**
     * Build the resident's initial ResidentState from EpisodeConfig and place
     * them in the room and pose implied by manActivity (Req 1.10, Req 8.7):
     *   sitting_at_table → living room, seated at the low table
     *   asleep_in_futon  → bedroom, lying in the futon
     *   bathing          → bathroom, in the bathtub
     *   cooking          → kitchen, standing at the gas stove
     */
    private function placeResident(string $manActivity): void
    {
        [$room, $spot] = match ($manActivity) {
            ManActivity::sitting_at_table->value => ['living_room', 'low_table'],
            ManActivity::asleep_in_futon->value  => ['bedroom', 'futon'],
            ManActivity::bathing->value          => ['bathroom', 'bathtub'],
            ManActivity::cooking->value          => ['kitchen', 'gas_stove'],
            default => throw new InvalidArgumentException(
                "Unknown manActivity '{$manActivity}'."
            ),
        };

        $this->residentState = new ResidentState(
            bodyTemp: $this->config->residentInitBodyTemp,
            water: $this->config->residentInitWater,
            injury: $this->config->residentInitInjury,
            fear: $this->config->residentInitFear,
            weakened: false,
            room: $room,
            spot: $spot,
            activity: $manActivity,
            coverFlag: false,
            lastLineDeliveredOn: [],
        );
    }

    /**
     * Light the gas stove and place a boiling pot on it (Req 1.7, Req 8.8).
     * The pot is not part of the canonical object registry; it only exists when
     * the SituationSpec says the resident is cooking / a pot is boiling.
     */
    private function lightStoveWithPot(): void
    {
        $this->objects['gas_stove']->on = true;

        $this->objects['pot'] = new ObjectState(
            id: 'pot',
            displayName: 'a pot',
            room: 'kitchen',
            spot: 'gas_stove',
            on: false,
            open: false,
            contents: [],
            broken: false,
            fallen: false,
            isPot: true,
            isWaterContainer: true,
        );
    }

    /**
     * Create the six rooms with their fixed adjacency graph, display names,
     * and fixture lists. No other room links exist (Req 1.4, Req 1.5, Req 1.12).
     */
    private function initRooms(): void
    {
        $definitions = [
            // key            display name      adjacent rooms                              fixtures
            ['living_room', 'living room', ['kitchen', 'bedroom', 'entrance'], ['counter', 'window', 'wall', 'doorway']],
            ['kitchen',     'kitchen',     ['living_room', 'bathroom', 'storeroom'], ['counter', 'window', 'wall', 'doorway']],
            ['bathroom',    'bathroom',    ['kitchen'],                              ['window', 'wall', 'doorway']],
            ['bedroom',     'bedroom',     ['living_room'],                          ['window', 'wall', 'doorway']],
            ['entrance',    'entrance',    ['living_room'],                          ['window', 'wall', 'doorway']],
            ['storeroom',   'storeroom',   ['kitchen'],                              ['wall', 'doorway']],
        ];

        $this->rooms = [];
        foreach ($definitions as [$key, $displayName, $adjacent, $fixtures]) {
            $this->rooms[$key] = new Room(
                key: $key,
                displayName: $displayName,
                adjacentRooms: $adjacent,
                lightLevel: LightLevel::normal,
                temperature: self::INITIAL_ROOM_TEMPERATURE,
                hotWaterInjuryHazard: false,
                fixtureKeys: $fixtures,
            );
        }
    }

    /**
     * Register all ~20 objects in their canonical rooms, in the fixed room
     * order of Req 1.6. The mutable state flags (on, open, broken, fallen)
     * start false and contents lists start empty (Req 1.7, Req 2.1); the fixed
     * property flags (breakable, isFireSource, …) describe what each object is
     * and are set here per the object identity.
     */
    private function initObjects(): void
    {
        // id, display name, room, [property flags]
        // Property flags default to false; only the ones that apply are listed.
        $definitions = [
            // --- living_room ---
            ['low_table',             'a low table',             'living_room', ['isProtective' => true, 'isHeavy' => true]],
            ['tv',                    'a TV',                    'living_room', ['breakable' => true, 'isElectric' => true, 'isHeavy' => true]],
            ['black_telephone',       'a black telephone',       'living_room', []],
            ['transistor_radio',      'a transistor radio',      'living_room', ['isElectric' => true]],
            ['buddhist_altar',        'a Buddhist altar',        'living_room', ['breakable' => true, 'isHeavy' => true]],
            ['window_air_conditioner', 'a window air conditioner', 'living_room', ['isElectric' => true, 'isHeavy' => true]],

            // --- kitchen ---
            ['gas_stove',             'a gas stove',             'kitchen', ['isFireSource' => true]],
            ['refrigerator',          'a refrigerator',          'kitchen', ['isElectric' => true, 'isHeavy' => true]],
            ['sink',                  'a sink',                  'kitchen', ['isWaterContainer' => true]],
            ['dish_cupboard',         'a dish cupboard',         'kitchen', ['breakable' => true, 'isHeavy' => true]],
            ['flashlight',            'a flashlight',            'kitchen', ['isLightSource' => true]],

            // --- bathroom ---
            ['gas_bath_heater',       'a gas bath heater',       'bathroom', ['isFireSource' => true]],
            ['bathtub',               'a bathtub',               'bathroom', ['isWaterContainer' => true]],

            // --- bedroom ---
            ['futon',                 'a futon',                 'bedroom', ['isProtective' => true]],
            ['closet',                'a closet',                'bedroom', ['isHeavy' => true]],
            ['electric_fan',          'an electric fan',         'bedroom', ['isElectric' => true]],

            // --- entrance ---
            ['rain_shutters',         'rain shutters',           'entrance', []],
            ['shoe_cabinet',          'a shoe cabinet',          'entrance', ['isHeavy' => true]],

            // --- storeroom ---
            ['candles',               'candles',                 'storeroom', ['isLightSource' => true, 'isFireSource' => true]],
            ['matches',               'matches',                 'storeroom', ['isFireSource' => true]],
            ['toolbox',               'a toolbox',               'storeroom', ['isHeavy' => true]],

            // The pot is not registered at init. reset() places it on the gas
            // stove only when the SituationSpec has potBoiling=true (Req 1.7).
        ];

        $this->objects = [];
        foreach ($definitions as [$id, $displayName, $room, $props]) {
            $this->objects[$id] = new ObjectState(
                id: $id,
                displayName: $displayName,
                room: $room,
                spot: null,
                on: false,
                open: false,
                contents: [],
                broken: false,
                fallen: false,
                breakable: $props['breakable'] ?? false,
                isFireSource: $props['isFireSource'] ?? false,
                isWaterContainer: $props['isWaterContainer'] ?? false,
                isHeavy: $props['isHeavy'] ?? false,
                isElectric: $props['isElectric'] ?? false,
                isLightSource: $props['isLightSource'] ?? false,
                isProtective: $props['isProtective'] ?? false,
                isPot: $props['isPot'] ?? false,
            );
        }
    }

    /**
     * Look up a room by key. Throws on an unknown key (Req 1.4).
     */
    public function getRoom(string $key): Room
    {
        if (!array_key_exists($key, $this->rooms)) {
            throw new InvalidArgumentException("Unknown room key: {$key}");
        }

        return $this->rooms[$key];
    }

    /**
     * Take a deep copy of the four mutable world parts so that later mutation
     * of the world does not change the returned snapshot. Relied on by the
     * ActionExecutor rollback pattern (design §f).
     */
    public function snapshot(): WorldSnapshot
    {
        $objects = [];
        foreach ($this->objects as $id => $object) {
            $objects[$id] = clone $object;
        }

        $robotStates = [];
        foreach ($this->robotStates as $id => $robot) {
            $robotStates[$id] = clone $robot;
        }

        return new WorldSnapshot(
            objects: $objects,
            robotStates: $robotStates,
            residentState: clone $this->residentState,
            houseState: clone $this->houseState,
        );
    }

    /**
     * Replace the four mutable world parts from a snapshot. The snapshot's
     * own objects are cloned back in so the snapshot stays immutable and can
     * be restored from again.
     */
    public function restore(WorldSnapshot $snap): void
    {
        $objects = [];
        foreach ($snap->objects as $id => $object) {
            $objects[$id] = clone $object;
        }

        $robotStates = [];
        foreach ($snap->robotStates as $id => $robot) {
            $robotStates[$id] = clone $robot;
        }

        $this->objects = $objects;
        $this->robotStates = $robotStates;
        $this->residentState = clone $snap->residentState;
        $this->houseState = clone $snap->houseState;
    }

    // ==================================================================
    // SituationView — read-only accessors for SituationTextGenerator.
    // Pure reads; they never mutate world state (Req 9.1–9.15).
    // ==================================================================

    public function timeOfDay(): string
    {
        return $this->houseState->timeOfDay;
    }

    public function robotRoom(string $robotId): string
    {
        if (!array_key_exists($robotId, $this->robotStates)) {
            throw new InvalidArgumentException("Unknown robot id: {$robotId}");
        }

        return $this->robotStates[$robotId]->room;
    }

    public function residentRoom(): string
    {
        return $this->residentState->room;
    }

    public function residentActivity(): string
    {
        return $this->residentState->activity;
    }

    public function isRoomDark(string $roomKey): bool
    {
        return $this->getRoom($roomKey)->lightLevel === LightLevel::dark;
    }

    /**
     * Object display phrases visible in the given room, in the fixed room order
     * of Req 1.6, with fixtures excluded. Objects iterate in registration order
     * (initObjects()), so a straight filter preserves the canonical order.
     *
     * @return string[]
     */
    public function visibleObjectPhrases(string $roomKey): array
    {
        $phrases = [];
        foreach ($this->objects as $object) {
            if ($object->room === $roomKey) {
                $phrases[] = $object->displayName;
            }
        }

        return $phrases;
    }

    public function isShaking(): bool
    {
        return $this->houseState->shaking;
    }

    public function isPotBoiling(): bool
    {
        return isset($this->objects['pot'])
            && $this->objects['pot']->room === 'kitchen'
            && ($this->objects['gas_stove']->on ?? false);
    }

    /**
     * Whether the previous turn was also shaking. The engine (task 18) sets the
     * flags SituationView reads; until it owns a dedicated previous-turn flag,
     * this is false. Tracked via EarthquakePhase offsets in later tasks.
     */
    public function wasShakingPreviousTurn(): bool
    {
        return false;
    }

    public function didLightsGoOutThisTurn(): bool
    {
        return false;
    }

    public function residentSpeaksThisTurn(): bool
    {
        return false;
    }

    public function residentLine(): ?string
    {
        return null;
    }
}
