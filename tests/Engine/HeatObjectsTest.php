<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\ActionExecutor;
use PoltergeistSwitch\Engine\Enums\Disaster;
use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\ResidentController;
use PoltergeistSwitch\Engine\RulesEvaluator;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\SituationSpec;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\WorldModel;

/**
 * Example tests for the heat objects in the action layer (task 6.2):
 * the window air conditioner, the electric fan, the cold-water bottle, and
 * the window toggle. Every behaviour is heat-gated — an earthquake episode
 * never enters any of these branches.
 *
 * Covers:
 *   - AC turned on in the living room (4.1), and AC `use` outside the living
 *     room is impossible and leaves the world unchanged (4.2);
 *   - the electric fan taken, carried, and switched on by a `use` while held,
 *     which puts it down in the robot's room and switches it on (4.3, 4.8);
 *   - cold barley tea: both `use refrigerator` and `take refrigerator` in the
 *     kitchen give the robot the held bottle, never listing it among the
 *     kitchen objects (4.4); `hand_over` raises water and clamps at 100 (4.9,
 *     3.3);
 *   - the window `use` toggles open/closed and the window is never listed
 *     among a room's objects (4.7).
 *
 * The evaluator and executor are driven directly (no Engine, no network) so
 * each object behaviour is pinned on its own.
 *
 * Requirements: 3.3, 4.1, 4.3, 4.4, 4.7, 4.8, 4.9
 */
class HeatObjectsTest extends TestCase
{
    private RulesEvaluator $evaluator;
    private ActionExecutor $executor;
    private EpisodeConfig $config;

    protected function setUp(): void
    {
        $this->evaluator = new RulesEvaluator();
        $this->executor = new ActionExecutor();
        $this->config = new EpisodeConfig();
    }

    /**
     * A heat-wave world. The robots' rooms are set explicitly per test via the
     * SituationSpec so each object scenario is deterministic.
     */
    private function makeHeatWorld(
        string $robotARoom = 'living_room',
        string $robotBRoom = 'bedroom',
        string $manActivity = 'sitting_at_table'
    ): WorldModel {
        $world = new WorldModel($this->config);
        $world->reset(
            Seed::fromInt(1),
            new SituationSpec(
                time: 'afternoon',
                robotARoom: $robotARoom,
                robotBRoom: $robotBRoom,
                manActivity: $manActivity,
                potBoiling: false,
            ),
            Disaster::heat_wave
        );

        return $world;
    }

    /**
     * Evaluate then execute one action against the world, returning the
     * Outcome. This mirrors the Engine's evaluate→execute order so the
     * evaluator's possibility verdict actually gates the executor.
     */
    private function runAction(Action $action, WorldModel $world): \PoltergeistSwitch\Engine\Values\Outcome
    {
        $judgement = $this->evaluator->evaluate($action, $world, $this->config, [$action]);

        return $this->executor->execute($action, $judgement, $world, $this->config);
    }

    // ------------------------------------------------------------------
    // Window air conditioner (4.1, 4.2)
    // ------------------------------------------------------------------

    /**
     * A `use` on the window air conditioner in the living room switches it on
     * (4.1). The air conditioner is a living-room object, so robot_a in the
     * living room can use it.
     */
    public function testAirConditionerTurnsOnInTheLivingRoom(): void
    {
        $world = $this->makeHeatWorld(robotARoom: 'living_room');

        $this->assertFalse($world->acOn(), 'the air conditioner starts off');

        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::use->value,
            targetId: 'window_air_conditioner',
        );
        $outcome = $this->runAction($action, $world);

        $this->assertTrue($outcome->success, 'using the AC in the living room succeeds');
        $this->assertTrue($world->acOn(), 'the air conditioner is on after the use');
    }

    /**
     * A `use` targeting the air conditioner in a room other than the living
     * room is impossible with a reason and leaves the world unchanged (4.2).
     * The AC object is moved to the bedroom for the test and the robot stands
     * there, so the only thing blocking the use is the living-room rule.
     */
    public function testAirConditionerCannotBeUsedOutsideTheLivingRoom(): void
    {
        $world = $this->makeHeatWorld(robotARoom: 'bedroom');
        // Put the AC where the robot is so only the living-room rule blocks it.
        $world->objects['window_air_conditioner']->room = 'bedroom';

        $before = $world->snapshot();

        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::use->value,
            targetId: 'window_air_conditioner',
        );
        $judgement = $this->evaluator->evaluate($action, $world, $this->config, [$action]);

        $this->assertFalse($judgement->possible, 'the AC cannot be used outside the living room');
        $this->assertSame('ac_only_in_living_room', $judgement->reason);

        $outcome = $this->executor->execute($action, $judgement, $world, $this->config);
        $this->assertFalse($outcome->success, 'an impossible AC use fails');
        $this->assertFalse($world->acOn(), 'the AC stays off');

        // The world is unchanged: the AC's on flag is still false.
        $after = $world->snapshot();
        $this->assertSame(
            $before->objects['window_air_conditioner']->on,
            $after->objects['window_air_conditioner']->on,
            'the world is left unchanged by the impossible use'
        );
    }

    // ------------------------------------------------------------------
    // Electric fan: take → carry → use-while-held (4.3, 4.8)
    // ------------------------------------------------------------------

    /**
     * The electric fan is taken in the bedroom, carried to the living room, and
     * a `use` while held puts it down in the living room and switches it on in
     * one step (4.3, 4.8).
     */
    public function testFanTakeCarryThenUseWhileHeldPutsItDownAndSwitchesItOn(): void
    {
        // robot_a starts in the bedroom, where the fan is.
        $world = $this->makeHeatWorld(robotARoom: 'bedroom');

        $this->assertSame('bedroom', $world->objects['electric_fan']->room);
        $this->assertFalse($world->objects['electric_fan']->on);

        // take the fan.
        $take = new Action('robot_a', Operation::take->value, targetId: 'electric_fan');
        $this->assertTrue($this->runAction($take, $world)->success, 'taking the fan succeeds');
        $this->assertSame('electric_fan', $world->robotStates['robot_a']->heldObjectId, 'the robot holds the fan');

        // carry it to the living room (bedroom ↔ living_room are adjacent).
        $move = new Action('robot_a', Operation::move->value, targetId: 'living_room');
        $this->assertTrue($this->runAction($move, $world)->success, 'carrying the fan to the living room succeeds');
        $this->assertSame('living_room', $world->robotStates['robot_a']->room);

        // use the held fan: put down in the living room and switch on.
        $use = new Action('robot_a', Operation::use->value, targetId: 'electric_fan');
        $outcome = $this->runAction($use, $world);

        $this->assertTrue($outcome->success, 'using the held fan succeeds');
        $this->assertNull($world->robotStates['robot_a']->heldObjectId, 'the fan leaves the held slot');
        $this->assertSame('living_room', $world->objects['electric_fan']->room, 'the fan is in the living room');
        $this->assertTrue($world->objects['electric_fan']->on, 'the fan is switched on');
        $this->assertSame('living_room', $world->fanRoom());
        $this->assertTrue($world->fanOn());
    }

    /**
     * A `use` on the fan while it stands in a room toggles it on and off (4.8).
     */
    public function testFanUseWhileStandingTogglesOnOff(): void
    {
        $world = $this->makeHeatWorld(robotARoom: 'bedroom');

        $use = new Action('robot_a', Operation::use->value, targetId: 'electric_fan');

        $this->assertTrue($this->runAction($use, $world)->success);
        $this->assertTrue($world->objects['electric_fan']->on, 'first use switches the standing fan on');

        $this->assertTrue($this->runAction($use, $world)->success);
        $this->assertFalse($world->objects['electric_fan']->on, 'second use switches it off');
    }

    // ------------------------------------------------------------------
    // Cold water (4.4, 4.9, 3.3)
    // ------------------------------------------------------------------

    /**
     * The cold barley tea is discoverable in the kitchen and a `use` on the
     * refrigerator gives the robot the held bottle (4.4), without ever listing
     * the bottle among the kitchen's objects.
     */
    public function testUseRefrigeratorGivesTheColdWaterBottle(): void
    {
        $world = $this->makeHeatWorld(robotARoom: 'kitchen');

        $this->assertArrayNotHasKey('barley_tea', $world->objects, 'the bottle is not registered until drawn');

        $use = new Action('robot_a', Operation::use->value, targetId: 'refrigerator');
        $outcome = $this->runAction($use, $world);

        $this->assertTrue($outcome->success, 'using the refrigerator succeeds in the kitchen');
        $this->assertSame('barley_tea', $world->robotStates['robot_a']->heldObjectId, 'the robot holds the bottle');
        $this->assertTrue($world->objects['barley_tea']->isWaterContainer, 'the bottle is a water container');

        // The bottle is held (parked on the robot), never listed in the kitchen.
        $this->assertNotContains(
            'a bottle of cold barley tea',
            $world->visibleObjectPhrases('kitchen'),
            'the bottle is never added to the kitchen listed-object order'
        );
    }

    /**
     * A `take` on the refrigerator yields the same held bottle a `use` does, so
     * the robot need not guess the verb (4.4).
     */
    public function testTakeRefrigeratorGivesTheColdWaterBottle(): void
    {
        $world = $this->makeHeatWorld(robotARoom: 'kitchen');

        $take = new Action('robot_a', Operation::take->value, targetId: 'refrigerator');
        $outcome = $this->runAction($take, $world);

        $this->assertTrue($outcome->success, 'taking from the refrigerator succeeds in the kitchen');
        $this->assertSame('barley_tea', $world->robotStates['robot_a']->heldObjectId);
        $this->assertSame('robot_a', $world->objects['barley_tea']->room, 'the bottle is parked on the robot');
    }

    /**
     * A `hand_over` of the cold water to the resident raises his water by
     * waterDeltaHandover, clamped at 100 (4.9, 3.3). The resident controller's
     * end-of-turn clamp is applied explicitly here, as the Engine does.
     */
    public function testHandOverColdWaterRaisesWaterAndClampsAt100(): void
    {
        $world = $this->makeHeatWorld(robotARoom: 'living_room', manActivity: 'sitting_at_table');
        // The resident sits in the living room; robot_a is there too.
        $this->assertSame('living_room', $world->residentState->room);

        // Draw the bottle: move robot_a to the kitchen, use the fridge, carry back.
        $world->robotStates['robot_a']->room = 'kitchen';
        $this->assertTrue($this->runAction(
            new Action('robot_a', Operation::use->value, targetId: 'refrigerator'),
            $world
        )->success);
        $world->robotStates['robot_a']->room = 'living_room';

        // Water below the top so the delta is visible.
        $world->residentState->water = 70;
        $expected = min(100, 70 + $this->config->waterDeltaHandover);

        $handOver = new Action('robot_a', Operation::hand_over->value, targetId: 'resident');
        $this->assertTrue($this->runAction($handOver, $world)->success, 'the hand-over succeeds');

        $this->assertSame(
            $expected,
            $world->residentState->water,
            'the cold water raises the resident water by waterDeltaHandover'
        );

        // Now near the top: hand-over clamps at 100.
        $world->robotStates['robot_a']->room = 'kitchen';
        $this->assertTrue($this->runAction(
            new Action('robot_a', Operation::use->value, targetId: 'refrigerator'),
            $world
        )->success);
        $world->robotStates['robot_a']->room = 'living_room';

        $world->residentState->water = 95;
        $this->assertTrue($this->runAction($handOver, $world)->success);

        // ActionExecutor adds the raw delta; the end-of-turn ResidentController
        // clamps to [0, 100], exactly as the live loop does.
        (new ResidentController())->updateState(
            $world,
            $this->config,
            new \PoltergeistSwitch\Engine\Values\TurnActions([], [], false)
        );

        $this->assertSame(100, $world->residentState->water, 'water clamps at 100');
    }

    // ------------------------------------------------------------------
    // Window toggle (4.7)
    // ------------------------------------------------------------------

    /**
     * A `use` with targetId 'window' toggles the robot's current room's
     * open-window state, and the window is never listed among the room's
     * objects (4.7).
     */
    public function testWindowUseTogglesOpenClosedAndIsNeverListed(): void
    {
        $world = $this->makeHeatWorld(robotARoom: 'living_room');

        $this->assertFalse($world->windowOpen('living_room'), 'the window starts closed');

        $use = new Action('robot_a', Operation::use->value, targetId: 'window');

        $this->assertTrue($this->runAction($use, $world)->success, 'opening the window succeeds');
        $this->assertTrue($world->windowOpen('living_room'), 'the window is open after the first use');

        $this->assertTrue($this->runAction($use, $world)->success, 'closing the window succeeds');
        $this->assertFalse($world->windowOpen('living_room'), 'the window is closed after the second use');

        // The window is a fixture: it never appears among the room's objects.
        foreach (array_keys($world->rooms) as $roomKey) {
            $this->assertNotContains(
                'window',
                $world->visibleObjectPhrases($roomKey),
                "the window is never listed among the {$roomKey} objects"
            );
        }
    }
}
