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
 * This task (6.2) implements room/object initialisation and snapshot/restore
 * only. `reset()` with SituationSpec application (task 7) and
 * `objects_visible_to()` (task 8) are added later.
 *
 * Requirements: Req 1.4–1.6, Req 2.1–2.2
 */
class WorldModel
{
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

    public function __construct()
    {
        $this->initRooms();
        $this->initObjects();

        // Placeholder agent and house state so the world is always in a
        // snapshot-able shape before reset() (task 7) positions the agents
        // and applies the SituationSpec.
        $this->robotStates = [
            'robot_a' => new RobotState('robot_a', 'living_room'),
            'robot_b' => new RobotState('robot_b', 'living_room'),
        ];
        $this->residentState = new ResidentState(
            bodyTemp: 36.2,
            water: 80,
            injury: 0,
            fear: 0,
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
                temperature: 22.0,
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

            // The pot is not registered at init. reset() (task 7) places it on
            // the gas stove only when the SituationSpec has potBoiling=true (Req 1.7).
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
}
