<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\ActionExecutor;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\Enums\GasState;
use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\SituationSpec;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\Values\Judgement;
use PoltergeistSwitch\Engine\WorldModel;

/**
 * Unit tests for ActionExecutor — move, take, use, speak, cover, support,
 * hand_over, and the snapshot/restore rollback pattern (tasks 12 and 13).
 *
 * Requirements: Req 5.1–5.6, Req 5.7–5.11
 */
class ActionExecutorTest extends TestCase
{
    private ActionExecutor $executor;
    private EpisodeConfig $config;

    protected function setUp(): void
    {
        $this->executor = new ActionExecutor();
        $this->config = new EpisodeConfig();
    }

    /**
     * World with robot_a in the kitchen and robot_b in the living room, the
     * resident cooking (kitchen, pot boiling on a lit stove). Tests mutate
     * fields directly to set up the case under test.
     */
    private function makeWorld(): WorldModel
    {
        $world = new WorldModel($this->config);
        $world->reset(
            Seed::fromInt(1),
            new SituationSpec(
                time: 'afternoon',
                robotARoom: 'kitchen',
                robotBRoom: 'living_room',
                manActivity: 'cooking',
                potBoiling: true,
            )
        );

        return $world;
    }

    private function possible(string $robotId, ?int $duration = null): Judgement
    {
        return new Judgement(robotId: $robotId, possible: true, duration: $duration);
    }

    // ------------------------------------------------------------------
    // move (Req 5.2)
    // ------------------------------------------------------------------

    public function testMoveUpdatesRobotRoom(): void
    {
        $world = $this->makeWorld();

        // kitchen ↔ living_room are adjacent.
        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::move->value,
            targetId: 'living_room',
        );

        $outcome = $this->executor->execute($action, $this->possible('robot_a'), $world, $this->config);

        $this->assertTrue($outcome->success);
        $this->assertSame('living_room', $world->robotStates['robot_a']->room);
    }

    public function testSameRoomMoveChangesOnlySpotLeavingRoomUnchanged(): void
    {
        $world = $this->makeWorld();

        // robot_a is in the kitchen; a move whose target is its own current
        // room updates only the spot and leaves the room unchanged (Req 30.1).
        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::move->value,
            targetId: 'kitchen',
            targetSpot: 'near_resident',
        );

        $outcome = $this->executor->execute($action, $this->possible('robot_a'), $world, $this->config);

        $this->assertTrue($outcome->success);
        $this->assertSame('kitchen', $world->robotStates['robot_a']->room);
        $this->assertSame('near_resident', $world->robotStates['robot_a']->spot);
    }

    // ------------------------------------------------------------------
    // take (Req 5.1, design §f)
    // ------------------------------------------------------------------

    public function testTakeMovesObjectIntoHeldSlotAndOutOfRoom(): void
    {
        $world = $this->makeWorld();

        // The flashlight starts in the kitchen with robot_a.
        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::take->value,
            targetId: 'flashlight',
        );

        $outcome = $this->executor->execute($action, $this->possible('robot_a'), $world, $this->config);

        $this->assertTrue($outcome->success);
        $this->assertSame('flashlight', $world->robotStates['robot_a']->heldObjectId);

        // The kitchen's visible object list no longer shows the flashlight.
        $kitchenPhrases = $world->visibleObjectPhrases('kitchen');
        $this->assertNotContains('a flashlight', $kitchenPhrases);
    }

    // ------------------------------------------------------------------
    // use (Req 5.3, Req 5.4)
    // ------------------------------------------------------------------

    public function testUseGasStoveTurnsFlameOffAndLeavesGasMainUntouched(): void
    {
        $world = $this->makeWorld();

        $this->assertTrue($world->objects['gas_stove']->on);
        $this->assertSame(GasState::open, $world->houseState->gasMain);

        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::use->value,
            targetId: 'gas_stove',
        );

        $outcome = $this->executor->execute($action, $this->possible('robot_a'), $world, $this->config);

        $this->assertTrue($outcome->success);
        $this->assertFalse($world->objects['gas_stove']->on);
        // The gas main is unchanged by turning off the stove flame (Req 5.3).
        $this->assertSame(GasState::open, $world->houseState->gasMain);
    }

    public function testUseGasMainValveClosesGasMain(): void
    {
        $world = $this->makeWorld();

        $this->assertSame(GasState::open, $world->houseState->gasMain);

        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::use->value,
            targetId: 'gas_main_valve',
        );

        $outcome = $this->executor->execute($action, $this->possible('robot_a'), $world, $this->config);

        $this->assertTrue($outcome->success);
        $this->assertSame(GasState::closed, $world->houseState->gasMain);
    }

    // ------------------------------------------------------------------
    // speak (Req 5.7)
    // ------------------------------------------------------------------

    public function testSpeakAppendsWordsAndChangesNoWorldField(): void
    {
        $world = $this->makeWorld();
        $before = $world->snapshot();

        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::speak->value,
            words: ['Stay calm.', 'I am here.'],
        );

        $outcome = $this->executor->execute($action, $this->possible('robot_a'), $world, $this->config);

        $this->assertTrue($outcome->success);
        // A spoken line is carried by Action.words and delivered separately, so
        // the outcome event text is empty for speak (design §f, Req 9.16).
        $this->assertSame('', $outcome->whatHappenedText);
        $this->assertSame('', $outcome->seenByOtherText);

        // No world field changed.
        $this->assertSnapshotsEqual($before, $world->snapshot());
    }

    // ------------------------------------------------------------------
    // cover (Req 5.8)
    // ------------------------------------------------------------------

    public function testCoverResidentSetsResidentCoverFlag(): void
    {
        $world = $this->makeWorld();
        $this->assertFalse($world->residentState->coverFlag);

        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::cover->value,
            targetId: 'resident',
        );

        $outcome = $this->executor->execute($action, $this->possible('robot_a'), $world, $this->config);

        $this->assertTrue($outcome->success);
        $this->assertTrue($world->residentState->coverFlag);
        // The robot's own cover flag is not set when covering the resident.
        $this->assertFalse($world->robotStates['robot_a']->coverFlag);
    }

    public function testCoverSelfSetsRobotCoverFlag(): void
    {
        $world = $this->makeWorld();

        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::cover->value,
            targetId: null,
        );

        $outcome = $this->executor->execute($action, $this->possible('robot_a'), $world, $this->config);

        $this->assertTrue($outcome->success);
        $this->assertTrue($world->robotStates['robot_a']->coverFlag);
        $this->assertFalse($world->residentState->coverFlag);
    }

    // ------------------------------------------------------------------
    // support (Req 5.5)
    // ------------------------------------------------------------------

    public function testSupportSetsCountdownAndLeavesResidentInPlace(): void
    {
        $world = $this->makeWorld();

        // The resident is cooking, so he is in the kitchen with robot_a.
        $residentRoomBefore = $world->residentState->room;
        $this->assertSame('kitchen', $residentRoomBefore);
        $this->assertSame(0, $world->robotStates['robot_a']->supportTurnsRemaining);

        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::support->value,
            targetId: 'resident',
        );

        $outcome = $this->executor->execute(
            $action,
            new Judgement(robotId: 'robot_a', possible: true, duration: 2),
            $world,
            $this->config,
        );

        $this->assertTrue($outcome->success);
        // A two-turn countdown is started on the robot (Req 5.5).
        $this->assertSame(2, $world->robotStates['robot_a']->supportTurnsRemaining);
        // The resident is not moved on this first turn.
        $this->assertSame($residentRoomBefore, $world->residentState->room);
    }

    // ------------------------------------------------------------------
    // hand_over (Req 5.6, Req 5.11)
    // ------------------------------------------------------------------

    /**
     * Put a held object on robot_a, mirroring the post-`take` world state:
     * the robot's slot records the id and the object's room is the robot id.
     */
    private function giveRobotHeldObject(WorldModel $world, string $robotId, string $objectId): void
    {
        $world->robotStates[$robotId]->heldObjectId = $objectId;
        $world->objects[$objectId]->room = $robotId;
        $world->objects[$objectId]->spot = null;
    }

    public function testHandOverNonWaterObjectAssignsToResidentAndLeavesWaterUnchanged(): void
    {
        $world = $this->makeWorld();
        // robot_a and the cooking resident share the kitchen.
        $this->giveRobotHeldObject($world, 'robot_a', 'flashlight');
        $this->assertFalse($world->objects['flashlight']->isWaterContainer);
        $waterBefore = $world->residentState->water;

        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::hand_over->value,
            targetId: 'resident',
        );

        $outcome = $this->executor->execute($action, $this->possible('robot_a'), $world, $this->config);

        $this->assertTrue($outcome->success);
        // The held slot is cleared and the object is assigned to the resident.
        $this->assertNull($world->robotStates['robot_a']->heldObjectId);
        $this->assertSame('resident', $world->objects['flashlight']->room);
        // A non-water object does not change the resident's water.
        $this->assertSame($waterBefore, $world->residentState->water);
    }

    public function testHandOverWaterContainerIncreasesResidentWater(): void
    {
        $world = $this->makeWorld();
        // The boiling pot is a water container; hand it to the resident.
        $this->giveRobotHeldObject($world, 'robot_a', 'pot');
        $this->assertTrue($world->objects['pot']->isWaterContainer);
        $waterBefore = $world->residentState->water;

        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::hand_over->value,
            targetId: 'resident',
        );

        $outcome = $this->executor->execute($action, $this->possible('robot_a'), $world, $this->config);

        $this->assertTrue($outcome->success);
        $this->assertNull($world->robotStates['robot_a']->heldObjectId);
        $this->assertSame('resident', $world->objects['pot']->room);
        // Water rises by the handover delta (clamp happens later, end of turn).
        $this->assertSame($waterBefore + $this->config->waterDeltaHandover, $world->residentState->water);
    }

    public function testHandOverWhenResidentNotInSameRoomLeavesWorldUnchanged(): void
    {
        $world = $this->makeWorld();
        $this->giveRobotHeldObject($world, 'robot_a', 'flashlight');
        // Move the resident out of robot_a's room.
        $world->residentState->room = 'living_room';
        $before = $world->snapshot();

        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::hand_over->value,
            targetId: 'resident',
        );

        // The evaluator would normally block this; the executor enforces the
        // guard independently (Req 5.11). A possible judgement still rolls back.
        $outcome = $this->executor->execute($action, $this->possible('robot_a'), $world, $this->config);

        $this->assertFalse($outcome->success);
        $this->assertNotNull($outcome->errorMessage);
        $this->assertSnapshotsEqual($before, $world->snapshot());
    }

    public function testHandOverWhenRobotHoldsNothingLeavesWorldUnchanged(): void
    {
        $world = $this->makeWorld();
        // robot_a holds nothing; the resident is in the same (kitchen) room.
        $this->assertNull($world->robotStates['robot_a']->heldObjectId);
        $before = $world->snapshot();

        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::hand_over->value,
            targetId: 'resident',
        );

        $outcome = $this->executor->execute($action, $this->possible('robot_a'), $world, $this->config);

        $this->assertFalse($outcome->success);
        $this->assertNotNull($outcome->errorMessage);
        $this->assertSnapshotsEqual($before, $world->snapshot());
    }

    // ------------------------------------------------------------------
    // Held light sources follow their holder (Req 2.4–2.5, Req 4.6)
    // ------------------------------------------------------------------

    /**
     * A night world with the power out and every non-afternoon room dark.
     * robot_a is in the kitchen, robot_b and the resident in the living room.
     */
    private function makeDarkWorld(): WorldModel
    {
        $world = new WorldModel($this->config);
        $world->reset(
            Seed::fromInt(1),
            new SituationSpec(
                time: 'night',
                robotARoom: 'kitchen',
                robotBRoom: 'living_room',
                manActivity: 'sitting_at_table', // resident in the living room
                potBoiling: false,
            )
        );

        // Fail the power and darken the house through the single light rule.
        $world->houseState->power = \PoltergeistSwitch\Engine\Enums\PowerState::outage;
        foreach ($world->rooms as $room) {
            \PoltergeistSwitch\Engine\EarthquakePhase::recomputeRoomLightLevel($world, $room->key);
        }

        return $world;
    }

    /**
     * Lighting a held flashlight via `use` lights the holder's current room,
     * not the agent id its `room` is parked on (fixes the $object->room bug;
     * Req 2.5, Req 4.6).
     */
    public function testUseLightsHeldFlashlightInHoldersRoom(): void
    {
        $world = $this->makeDarkWorld();
        $this->giveRobotHeldObject($world, 'robot_a', 'flashlight'); // parked on robot_a (kitchen)

        $this->assertSame(
            \PoltergeistSwitch\Engine\Enums\LightLevel::dark,
            $world->getRoom('kitchen')->lightLevel
        );

        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::use->value,
            targetId: 'flashlight',
        );

        $outcome = $this->executor->execute($action, $this->possible('robot_a'), $world, $this->config);

        $this->assertTrue($outcome->success);
        $this->assertTrue($world->objects['flashlight']->on);
        $this->assertSame(
            \PoltergeistSwitch\Engine\Enums\LightLevel::normal,
            $world->getRoom('kitchen')->lightLevel,
            'Lighting a held flashlight lights the holder\'s room'
        );
    }

    /**
     * Picking up a lit flashlight standing in a dark room keeps the room lit:
     * the robot stays in the room, so the carried light still lights it. The
     * `take` recompute must resolve to the real room, not throw on the robot id.
     */
    public function testTakeLitFlashlightKeepsHoldersRoomLit(): void
    {
        $world = $this->makeDarkWorld();
        // A lit flashlight stands in the kitchen; robot_a is in the kitchen.
        $world->objects['flashlight']->on = true;
        \PoltergeistSwitch\Engine\EarthquakePhase::recomputeRoomLightLevel($world, 'kitchen');
        $this->assertSame(
            \PoltergeistSwitch\Engine\Enums\LightLevel::normal,
            $world->getRoom('kitchen')->lightLevel
        );

        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::take->value,
            targetId: 'flashlight',
        );

        $outcome = $this->executor->execute($action, $this->possible('robot_a'), $world, $this->config);

        $this->assertTrue($outcome->success);
        $this->assertSame('robot_a', $world->objects['flashlight']->room);
        $this->assertSame(
            \PoltergeistSwitch\Engine\Enums\LightLevel::normal,
            $world->getRoom('kitchen')->lightLevel,
            'The room stays lit by the flashlight the robot now holds'
        );
    }

    /**
     * When a robot carrying a lit flashlight moves to an adjacent dark room,
     * the room left behind goes dark and the room entered becomes lit (Req 2.5).
     */
    public function testMoveCarriesLitLightBetweenRooms(): void
    {
        $world = $this->makeDarkWorld();
        // robot_a holds a lit flashlight in the kitchen.
        $world->objects['flashlight']->on = true;
        $this->giveRobotHeldObject($world, 'robot_a', 'flashlight');
        \PoltergeistSwitch\Engine\EarthquakePhase::recomputeRoomLightLevel($world, 'kitchen');

        $this->assertSame(
            \PoltergeistSwitch\Engine\Enums\LightLevel::normal,
            $world->getRoom('kitchen')->lightLevel
        );
        $this->assertSame(
            \PoltergeistSwitch\Engine\Enums\LightLevel::dark,
            $world->getRoom('living_room')->lightLevel
        );

        // kitchen ↔ living_room are adjacent.
        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::move->value,
            targetId: 'living_room',
        );

        $outcome = $this->executor->execute($action, $this->possible('robot_a'), $world, $this->config);

        $this->assertTrue($outcome->success);
        $this->assertSame(
            \PoltergeistSwitch\Engine\Enums\LightLevel::dark,
            $world->getRoom('kitchen')->lightLevel,
            'The room left behind goes dark once the carried light leaves'
        );
        $this->assertSame(
            \PoltergeistSwitch\Engine\Enums\LightLevel::normal,
            $world->getRoom('living_room')->lightLevel,
            'The entered room becomes lit as the carried light arrives'
        );
    }

    /**
     * Handing a lit flashlight to the resident lights the resident's room
     * (Req 2.5, Req 4.6).
     */
    public function testHandOverLitLightLightsResidentsRoom(): void
    {
        $world = $this->makeDarkWorld();
        // robot_b and the resident share the living room; give robot_b a lit light.
        $world->objects['flashlight']->on = true;
        $this->giveRobotHeldObject($world, 'robot_b', 'flashlight');
        // robot_b carried a lit light into its own (living) room; recompute it.
        \PoltergeistSwitch\Engine\EarthquakePhase::recomputeRoomLightLevel($world, 'living_room');
        $this->assertSame('living_room', $world->residentState->room);

        $action = new Action(
            robotId: 'robot_b',
            operation: Operation::hand_over->value,
            targetId: 'resident',
        );

        $outcome = $this->executor->execute($action, $this->possible('robot_b'), $world, $this->config);

        $this->assertTrue($outcome->success);
        $this->assertSame('resident', $world->objects['flashlight']->room);
        $this->assertSame(
            \PoltergeistSwitch\Engine\Enums\LightLevel::normal,
            $world->getRoom('living_room')->lightLevel,
            'A lit light handed to the resident lights the resident\'s room'
        );
    }

    /**
     * An unlit held light leaves the holder's room dark: moving it between dark
     * rooms lights neither (Req 2.4–2.5).
     */
    public function testUnlitHeldLightDoesNotLightOnMove(): void
    {
        $world = $this->makeDarkWorld();
        // robot_a holds an UNLIT flashlight in the kitchen.
        $this->giveRobotHeldObject($world, 'robot_a', 'flashlight');
        $world->objects['flashlight']->on = false;

        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::move->value,
            targetId: 'living_room',
        );

        $outcome = $this->executor->execute($action, $this->possible('robot_a'), $world, $this->config);

        $this->assertTrue($outcome->success);
        $this->assertSame(
            \PoltergeistSwitch\Engine\Enums\LightLevel::dark,
            $world->getRoom('kitchen')->lightLevel,
            'An unlit carried light leaves the room behind dark'
        );
        $this->assertSame(
            \PoltergeistSwitch\Engine\Enums\LightLevel::dark,
            $world->getRoom('living_room')->lightLevel,
            'An unlit carried light leaves the entered room dark'
        );
    }

    // ------------------------------------------------------------------
    // rollback (Req 5.10)
    // ------------------------------------------------------------------

    public function testExecutionFailureRollsBackToPreActionSnapshot(): void
    {
        $world = $this->makeWorld();
        $before = $world->snapshot();

        // `use` targeting an object that does not exist throws inside the
        // executor; the snapshot/restore pattern must leave the world
        // byte-identical and return a failure Outcome (Req 5.10).
        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::use->value,
            targetId: 'no_such_object',
        );

        $outcome = $this->executor->execute($action, $this->possible('robot_a'), $world, $this->config);

        $this->assertFalse($outcome->success);
        $this->assertNotNull($outcome->errorMessage);
        $this->assertSnapshotsEqual($before, $world->snapshot());
    }

    // ------------------------------------------------------------------
    // impossible judgement (Req 5.9)
    // ------------------------------------------------------------------

    public function testImpossibleJudgementLeavesWorldUnchangedAndReturnsFailure(): void
    {
        $world = $this->makeWorld();
        $before = $world->snapshot();

        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::move->value,
            targetId: 'bedroom',
        );
        $judgement = new Judgement(
            robotId: 'robot_a',
            possible: false,
            reason: 'not_adjacent',
        );

        $outcome = $this->executor->execute($action, $judgement, $world, $this->config);

        $this->assertFalse($outcome->success);
        $this->assertSame('not_adjacent', $outcome->errorMessage);
        $this->assertSnapshotsEqual($before, $world->snapshot());
    }

    /**
     * Assert two world snapshots are field-for-field equal. WorldSnapshot holds
     * cloned value objects, so a loose equality comparison (==) compares by
     * value rather than identity.
     */
    private function assertSnapshotsEqual(object $expected, object $actual): void
    {
        $this->assertEquals($expected->objects, $actual->objects);
        $this->assertEquals($expected->robotStates, $actual->robotStates);
        $this->assertEquals($expected->residentState, $actual->residentState);
        $this->assertEquals($expected->houseState, $actual->houseState);
    }
}
