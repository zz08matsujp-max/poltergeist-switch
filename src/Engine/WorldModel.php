<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine;

use InvalidArgumentException;
use PoltergeistSwitch\Engine\Enums\Disaster;
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
 * world. Task 8.1 adds `objects_visible_to()`.
 *
 * Requirements: Req 1.1–1.12, Req 2.1–2.2, Req 8.5–8.9
 */
class WorldModel implements SituationView, HeatSituationView
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

    /**
     * The disaster this episode runs (heat-wave Requirement 1.6). Defaults to
     * earthquake so a world built or reset the old way behaves, reads, and
     * replays exactly as today; reset() sets it from its argument. Every heat
     * behaviour is gated on this being Disaster::heat_wave, so an earthquake
     * world never enters a heat path.
     */
    public Disaster $disaster = Disaster::earthquake;

    public ?int $earthquakeStartTurn = null;

    public bool $episodeEnded = false;

    public bool $disasterSubsided = false;

    /** Kept after reset() so EarthquakePhase can derive deterministic draws. */
    public Seed $seed;

    /** Kept after reset() so the text generator and rules can read the start-up layout. */
    public SituationSpec $situationSpec;

    /**
     * Per-turn flags the Engine (task 18) sets each turn so SituationView can
     * report them. They are not part of the physical world state and are not
     * snapshotted; the Engine recomputes them from the earthquake offset and
     * the resident's dialogue every turn, and reset() returns them to their
     * fixed defaults (false / null) for turn 0.
     */
    public bool $turnWasShakingPreviousTurn = false;

    public bool $turnDidLightsGoOut = false;

    public bool $turnResidentSpeaks = false;

    public ?string $turnResidentLine = null;

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
     * @param Seed               $seed     the episode seed (stored for EarthquakePhase)
     * @param SituationSpec|null  $spec     explicit start layout; derived from the
     *                                      seed when null
     * @param Disaster|null       $disaster the episode's disaster; null (the
     *                                      default) means earthquake, so every
     *                                      existing caller gets today's behaviour
     *                                      and a rebuilt world knows its disaster
     *                                      (heat-wave Requirement 1.6)
     */
    public function reset(Seed $seed, ?SituationSpec $spec = null, ?Disaster $disaster = null): void
    {
        // 1. keep the seed for later deterministic draws (EarthquakePhase).
        $this->seed = $seed;

        // 1b. record the disaster so a rebuilt world knows which rules run.
        // Null means earthquake, keeping every existing caller unchanged.
        $this->disaster = $disaster ?? Disaster::earthquake;

        // 2/3. resolve the start layout.
        $this->situationSpec = $spec ?? SituationSpec::fromSeed($seed);

        // 4/5. rebuild rooms and the object registry to their canonical state.
        $this->initRooms();
        $this->initObjects();

        // 6. fixed initial house state (Req 1.9).
        // A heat-wave episode starts every room at the hot initial temperature
        // and records the fixed outside temperature the rooms drift toward
        // (heat-wave Requirements 2.1, 4.1 of task 4.1); an earthquake episode
        // keeps the existing INITIAL_ROOM_TEMPERATURE path byte-for-byte, with
        // outsideTemperature at its 0.0 "not a heat wave" default.
        $isHeatWave = $this->disaster === Disaster::heat_wave;

        $this->houseState = new HouseState(
            power: PowerState::on,
            gasMain: GasState::open,
            floorWaterLevel: 0.0,
            shaking: false,
            fallingObjects: false,
            timeOfDay: $this->situationSpec->time,
            outsideTemperature: $isHeatWave ? $this->config->outsideTemperature : 0.0,
        );

        $initialRoomTemp = $isHeatWave
            ? $this->config->heatRoomInitialTemp
            : self::INITIAL_ROOM_TEMPERATURE;

        foreach ($this->rooms as $room) {
            $room->temperature = $initialRoomTemp;
            $room->lightLevel = LightLevel::normal;
            $room->hotWaterInjuryHazard = false;
            // The window starts closed every episode; the heat rules open it
            // only through a `use window` action. It is never listed as an
            // object, so the earthquake read is unchanged.
            $room->windowOpen = false;
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

        // Per-turn SituationView flags start at their fixed defaults; the
        // Engine recomputes them each turn (task 18).
        $this->turnWasShakingPreviousTurn = false;
        $this->turnDidLightsGoOut = false;
        $this->turnResidentSpeaks = false;
        $this->turnResidentLine = null;
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
            ManActivity::lying_in_futon->value   => ['bedroom', 'futon'],
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
     * The EpisodeConfig this world was built with. A read-only accessor so the
     * pure phases (HeatWavePhase) can read the heat numbers that live only in
     * EpisodeConfig (heat-wave Requirement 2.7) without a literal in logic. It
     * never mutates anything and does not affect any earthquake path.
     */
    public function config(): EpisodeConfig
    {
        return $this->config;
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
     * Objects a robot can currently perceive in its own room (Req 2.3, Req 2.4).
     *
     * Steps (design §a):
     *   1. look up the robot's current room;
     *   2. if that room is dark, the robot sees nothing → return [];
     *   3. otherwise collect every ObjectState whose current room matches, in
     *      the fixed room order of Req 1.6.
     *
     * Fixtures (window, counter, wall, doorway) are never registered in
     * `$this->objects`; they live only in `Room::fixtureKeys`, so filtering the
     * object registry by room already excludes them. Objects iterate in
     * registration order (initObjects()), which is the fixed room order, so a
     * straight filter preserves that order. The `$turn` parameter is accepted
     * for interface symmetry with the per-turn callers and is not used here.
     *
     * @return ObjectState[] visible objects in fixed room order (may be empty)
     */
    public function objects_visible_to(string $robotId, int $turn): array
    {
        // 1. the robot's current room.
        if (!array_key_exists($robotId, $this->robotStates)) {
            throw new InvalidArgumentException("Unknown robot id: {$robotId}");
        }
        $roomKey = $this->robotStates[$robotId]->room;

        // 2. a dark room hides everything (Req 2.4).
        if ($this->getRoom($roomKey)->lightLevel === LightLevel::dark) {
            return [];
        }

        // 3. objects in that room, in registration (fixed room) order.
        $visible = [];
        foreach ($this->objects as $object) {
            if ($object->room === $roomKey) {
                $visible[] = $object;
            }
        }

        return $visible;
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

    public function residentSpot(): ?string
    {
        return $this->residentState->spot;
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
     * Whether the previous turn was also shaking. The Engine (task 18) sets
     * this each turn from the earthquake offset so the shaking paragraph can
     * open with "The floor is still shaking hard." on a continuing quake.
     */
    public function wasShakingPreviousTurn(): bool
    {
        return $this->turnWasShakingPreviousTurn;
    }

    /**
     * True on the single turn the power changes to an outage (earthquake
     * offset 2), so the shaking paragraph appends "The lights go out."; set by
     * the Engine each turn (task 18).
     */
    public function didLightsGoOutThisTurn(): bool
    {
        return $this->turnDidLightsGoOut;
    }

    /**
     * True when the resident delivers a line this turn; set by the Engine from
     * ResidentController::emitDialogueLine (task 18).
     */
    public function residentSpeaksThisTurn(): bool
    {
        return $this->turnResidentSpeaks;
    }

    /**
     * The resident's spoken line this turn, or null when he says nothing; set
     * by the Engine alongside residentSpeaksThisTurn (task 18).
     */
    public function residentLine(): ?string
    {
        return $this->turnResidentLine;
    }

    // ==================================================================
    // Heat reads (heat-wave spec). Pure accessors over existing world
    // state; they never mutate anything and never change any earthquake
    // read. The heat rules, texts, recorder input, serializer, and screens
    // read the heat objects through these so one place defines each read.
    // In an earthquake episode these simply report the default off/closed
    // state (the air conditioner and fan start off and no window is opened),
    // which is exactly what an earthquake world has always held.
    // ==================================================================

    /**
     * Whether this world is running the given disaster. A convenience so heat
     * code can gate on the disaster without reaching for the enum directly.
     */
    public function disasterIs(Disaster $disaster): bool
    {
        return $this->disaster === $disaster;
    }

    /**
     * The disaster this world runs (HeatSituationView). The SituationTextGenerator
     * reads this to choose the heat-wave text path; an earthquake world returns
     * Disaster::earthquake and takes the unchanged earthquake path.
     */
    public function disaster(): Disaster
    {
        return $this->disaster;
    }

    /**
     * The 1-based turn number this world is on (HeatSituationView). The heat
     * generator reads this to advance the clock and to fire the opening line on
     * turn 1 only, so no turn literal lives in the generator.
     */
    public function currentTurn(): int
    {
        return $this->currentTurn;
    }

    /**
     * The minutes the heat clock advances per turn (HeatSituationView), read
     * from EpisodeConfig so the generator holds no heat number (heat-wave
     * Requirement 6.4).
     */
    public function heatMinutesPerTurn(): int
    {
        return $this->config->heatMinutesPerTurn;
    }

    /**
     * The resident's visible heat stage for the "Right now:" paragraph
     * (HeatSituationView, heat-wave Requirement 6.5), resolved here so the
     * generator holds no heat threshold:
     *   'weakened' — body_temp >= weakenedThreshold (the strongest stage);
     *   'hot'      — his room temperature is above bodyHeatThreshold;
     *   'normal'   — otherwise.
     * weakened wins over hot wins over normal.
     */
    public function residentHeatStage(): string
    {
        if ($this->residentState->weakened) {
            return 'weakened';
        }

        $roomTemp = $this->getRoom($this->residentState->room)->temperature;
        if ($roomTemp > $this->config->bodyHeatThreshold) {
            return 'hot';
        }

        return 'normal';
    }

    /** Whether the window air conditioner is switched on. */
    public function acOn(): bool
    {
        return isset($this->objects['window_air_conditioner'])
            && $this->objects['window_air_conditioner']->on;
    }

    /** Whether the electric fan is switched on. */
    public function fanOn(): bool
    {
        return isset($this->objects['electric_fan'])
            && $this->objects['electric_fan']->on;
    }

    /**
     * The room the electric fan is in, or null when the fan is not registered.
     * When a robot holds the fan the object's room is the robot's room (the
     * carry mechanism keeps the fan's room in step with its carrier), so this
     * reports where the fan physically is for the cooling and drawing rules.
     */
    public function fanRoom(): ?string
    {
        return $this->objects['electric_fan']->room ?? null;
    }

    /**
     * Whether the window of the given room is open. Always false for a room
     * whose window has not been opened, which is every room in an earthquake
     * episode.
     */
    public function windowOpen(string $roomKey): bool
    {
        return $this->getRoom($roomKey)->windowOpen;
    }

    /** The live temperature of the given room, in °C. */
    public function roomTemperature(string $roomKey): float
    {
        return $this->getRoom($roomKey)->temperature;
    }

    /**
     * The wall thermometer reading for the given room, the room temperature
     * rounded to a whole °C. The thermometer is a fixture read into the heat
     * text only; it is never listed among a room's objects.
     */
    public function thermometerReading(string $roomKey): int
    {
        return (int) round($this->getRoom($roomKey)->temperature);
    }
}
