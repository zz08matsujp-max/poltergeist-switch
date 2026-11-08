<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\ObjectState;
use PoltergeistSwitch\Engine\RulesEvaluator;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\SituationSpec;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\WorldModel;

/**
 * Unit tests for RulesEvaluator — move and take operations (task 9), the
 * remaining operations use, hand_over, support, cover, wait, speak (task 10),
 * and the danger codes R1/R2a/R2b/R3 plus the priority-conflict override
 * (task 11).
 *
 * Requirements: Req 4.2–4.13
 */
class RulesEvaluatorTest extends TestCase
{
    private RulesEvaluator $evaluator;
    private EpisodeConfig $config;

    protected function setUp(): void
    {
        $this->evaluator = new RulesEvaluator();
        $this->config = new EpisodeConfig();
    }

    /**
     * Build a world with robot_a in the living room and robot_b in the kitchen,
     * the resident sitting at the low table, and no boiling pot. Fields are
     * mutated directly in each test to set up the case under test.
     */
    private function makeWorld(): WorldModel
    {
        $world = new WorldModel($this->config);
        $world->reset(
            Seed::fromInt(1),
            new SituationSpec(
                time: 'afternoon',
                robotARoom: 'living_room',
                robotBRoom: 'kitchen',
                manActivity: 'sitting_at_table',
                potBoiling: false,
            )
        );

        return $world;
    }

    private function evaluate(WorldModel $world, Action $action): \PoltergeistSwitch\Engine\Values\Judgement
    {
        return $this->evaluator->evaluate($action, $world, $this->config, [$action]);
    }

    /**
     * Evaluate with an explicit set of concurrent actions and/or an override
     * config, for the danger and priority-conflict cases.
     *
     * @param Action[] $allActions
     */
    private function evaluateWith(
        WorldModel $world,
        Action $action,
        array $allActions,
        ?EpisodeConfig $config = null,
    ): \PoltergeistSwitch\Engine\Values\Judgement {
        return $this->evaluator->evaluate($action, $world, $config ?? $this->config, $allActions);
    }

    // ------------------------------------------------------------------
    // move
    // ------------------------------------------------------------------

    public function testMoveToNonAdjacentRoomIsImpossible(): void
    {
        $world = $this->makeWorld();

        // robot_a is in living_room; bathroom is not adjacent to living_room.
        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::move->value,
            targetId: 'bathroom',
        );

        $judgement = $this->evaluate($world, $action);

        $this->assertFalse($judgement->possible);
        $this->assertSame('not_adjacent', $judgement->reason);
    }

    public function testMoveToAdjacentRoomIsPossible(): void
    {
        $world = $this->makeWorld();

        // robot_a is in the living_room with the resident; kitchen is adjacent
        // and not shaking. A plain move to another room targets a room, not the
        // resident, so it is never R3 — the move is plainly possible with no
        // danger.
        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::move->value,
            targetId: 'kitchen',
        );

        $judgement = $this->evaluate($world, $action);

        $this->assertTrue($judgement->possible);
        $this->assertNull($judgement->reason);
        $this->assertFalse($judgement->dangerous);
        $this->assertNull($judgement->dangerCode);
    }

    // ------------------------------------------------------------------
    // move inside the robot's own room (task 30, Req 30.1–30.6)
    // ------------------------------------------------------------------

    public function testSameRoomMoveIsPossibleAsSpotChange(): void
    {
        $world = $this->makeWorld();

        // robot_a is in the living_room; a move whose target is its own current
        // room is a spot change and is possible with no danger (Req 30.1).
        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::move->value,
            targetId: 'living_room',
            targetSpot: 'doorway',
        );

        $judgement = $this->evaluate($world, $action);

        $this->assertTrue($judgement->possible);
        $this->assertNull($judgement->reason);
        $this->assertFalse($judgement->dangerous);
        $this->assertNull($judgement->dangerCode);
    }

    public function testMoveToNearResidentWithResidentPresentIsPossible(): void
    {
        $world = $this->makeWorld();

        // The resident sits at the low table in the living_room, where robot_a
        // also stands. A move to `near_resident` in that room is possible
        // (Req 30.4).
        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::move->value,
            targetId: 'living_room',
            targetSpot: 'near_resident',
        );

        $judgement = $this->evaluate($world, $action);

        $this->assertTrue($judgement->possible);
        $this->assertNull($judgement->reason);
    }

    public function testMoveToNearResidentWithResidentAbsentIsImpossible(): void
    {
        $world = $this->makeWorld();

        // robot_a in the living_room moves to the adjacent kitchen aiming for
        // `near_resident`, but the man is in the living_room — impossible with
        // reason `resident_not_here` (Req 30.5).
        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::move->value,
            targetId: 'kitchen',
            targetSpot: 'near_resident',
        );

        $judgement = $this->evaluate($world, $action);

        $this->assertFalse($judgement->possible);
        $this->assertSame('resident_not_here', $judgement->reason);
        $this->assertFalse($judgement->dangerous);
        $this->assertNull($judgement->dangerCode);
    }

    public function testSameRoomMoveToWindowSpotWhileShakingIsDangerousR1(): void
    {
        $world = $this->makeWorld();
        $world->houseState->shaking = true;

        // A same-room move onto the window spot while shaking is R1 exactly as
        // a move between rooms would be (Req 30.2).
        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::move->value,
            targetId: 'living_room',
            targetSpot: 'window',
        );

        $judgement = $this->evaluate($world, $action);

        $this->assertTrue($judgement->possible);
        $this->assertTrue($judgement->dangerous);
        $this->assertSame('R1', $judgement->dangerCode);
    }

    public function testSameRoomMoveToGasStoveSpotWhileShakingRecordsR2a(): void
    {
        $world = $this->makeWorld();
        $world->houseState->shaking = true;

        // robot_b is in the kitchen; a same-room move onto the gas stove spot
        // while shaking records R2a exactly as a move between rooms (Req 30.3).
        $action = new Action(
            robotId: 'robot_b',
            operation: Operation::move->value,
            targetId: 'kitchen',
            targetSpot: 'gas_stove',
        );

        // Default config: r2aIsDangerous=false → code recorded, not dangerous.
        $notDangerous = $this->evaluateWith($world, $action, [$action], new EpisodeConfig(r2aIsDangerous: false));
        $this->assertTrue($notDangerous->possible);
        $this->assertSame('R2a', $notDangerous->dangerCode);
        $this->assertFalse($notDangerous->dangerous);

        // Override config: r2aIsDangerous=true → dangerous mirrors the flag.
        $dangerous = $this->evaluateWith($world, $action, [$action], new EpisodeConfig(r2aIsDangerous: true));
        $this->assertSame('R2a', $dangerous->dangerCode);
        $this->assertTrue($dangerous->dangerous);
    }

    public function testNonAdjacentRoomStillImpossibleNotAdjacent(): void
    {
        $world = $this->makeWorld();

        // bathroom is neither adjacent to nor equal to living_room → the
        // genuinely non-adjacent case is unchanged (Req 30.6).
        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::move->value,
            targetId: 'bathroom',
            targetSpot: 'doorway',
        );

        $judgement = $this->evaluate($world, $action);

        $this->assertFalse($judgement->possible);
        $this->assertSame('not_adjacent', $judgement->reason);
        $this->assertFalse($judgement->dangerous);
        $this->assertNull($judgement->dangerCode);
    }

    // ------------------------------------------------------------------
    // take
    // ------------------------------------------------------------------

    public function testTakeWhenHandsFullIsImpossible(): void
    {
        $world = $this->makeWorld();
        $world->robotStates['robot_a']->heldObjectId = 'tv';

        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::take->value,
            targetId: 'black_telephone',
        );

        $judgement = $this->evaluate($world, $action);

        $this->assertFalse($judgement->possible);
        $this->assertSame('hands_full', $judgement->reason);
    }

    public function testTakeWhenObjectNotInRoomIsImpossible(): void
    {
        $world = $this->makeWorld();

        // robot_a is in living_room; the flashlight lives in the kitchen.
        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::take->value,
            targetId: 'flashlight',
        );

        $judgement = $this->evaluate($world, $action);

        $this->assertFalse($judgement->possible);
        $this->assertSame('object_not_in_room', $judgement->reason);
    }

    public function testTakeBrokenObjectWithoutProtectiveItemIsDangerousR4(): void
    {
        $world = $this->makeWorld();
        // The TV is in the living room with robot_a; break it.
        $world->objects['tv']->broken = true;

        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::take->value,
            targetId: 'tv',
        );

        $judgement = $this->evaluate($world, $action);

        $this->assertTrue($judgement->possible);
        $this->assertTrue($judgement->dangerous);
        $this->assertSame('R4', $judgement->dangerCode);
    }

    public function testTakeBrokenObjectWhileHoldingProtectiveItemIsNotDangerous(): void
    {
        $world = $this->makeWorld();
        // The robot holds a protective item (the low table, isProtective=true),
        // so the R4 bare-hand hazard does not apply to a broken target. Holding
        // an item also means hands_full blocks the take, so the take is
        // impossible — but the point under test is the absence of the R4 danger
        // flag: dangerous=false, dangerCode=null (Req 4.12).
        $world->robotStates['robot_a']->heldObjectId = 'low_table'; // isProtective=true
        $world->objects['tv']->broken = true;

        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::take->value,
            targetId: 'tv',
        );

        $judgement = $this->evaluate($world, $action);

        $this->assertFalse($judgement->dangerous);
        $this->assertNull($judgement->dangerCode);
    }

    // ------------------------------------------------------------------
    // use
    // ------------------------------------------------------------------

    public function testUseWhenObjectNotInRoomIsImpossible(): void
    {
        $world = $this->makeWorld();

        // robot_a is in living_room; the flashlight lives in the kitchen.
        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::use->value,
            targetId: 'flashlight',
        );

        $judgement = $this->evaluate($world, $action);

        $this->assertFalse($judgement->possible);
        $this->assertSame('object_not_in_room', $judgement->reason);
    }

    public function testUseOnObjectInRoomIsPossible(): void
    {
        $world = $this->makeWorld();

        // robot_b is in the kitchen where the flashlight lives; using it there
        // is plainly possible (Req 4.6).
        $action = new Action(
            robotId: 'robot_b',
            operation: Operation::use->value,
            targetId: 'flashlight',
        );

        $judgement = $this->evaluate($world, $action);

        $this->assertTrue($judgement->possible);
        $this->assertNull($judgement->reason);
    }

    public function testUseOnHeldObjectIsPossible(): void
    {
        $world = $this->makeWorld();

        // robot_a holds the flashlight: `take` parks a held object by setting
        // its room to the robot's id, so it no longer stands in any room. A use
        // targeting the held light source must still be possible (Req 4.6).
        $world->objects['flashlight']->room = 'robot_a';
        $world->objects['flashlight']->spot = null;
        $world->robotStates['robot_a']->heldObjectId = 'flashlight';

        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::use->value,
            targetId: 'flashlight',
        );

        $judgement = $this->evaluate($world, $action);

        $this->assertTrue($judgement->possible);
        $this->assertNull($judgement->reason);
    }

    public function testUseOnObjectNotInRoomAndHeldByOtherRobotIsImpossible(): void
    {
        $world = $this->makeWorld();

        // robot_b holds the flashlight (parked on robot_b); robot_a, in the
        // living room, neither has it in its room nor holds it, so a use is
        // object_not_in_room (Req 4.6).
        $world->objects['flashlight']->room = 'robot_b';
        $world->objects['flashlight']->spot = null;
        $world->robotStates['robot_b']->heldObjectId = 'flashlight';

        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::use->value,
            targetId: 'flashlight',
        );

        $judgement = $this->evaluate($world, $action);

        $this->assertFalse($judgement->possible);
        $this->assertSame('object_not_in_room', $judgement->reason);
    }

    // ------------------------------------------------------------------
    // hand_over
    // ------------------------------------------------------------------

    public function testHandOverWhenRobotHoldsNothingIsImpossible(): void
    {
        $world = $this->makeWorld();
        // robot_a shares the living room with the resident but holds nothing.

        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::hand_over->value,
        );

        $judgement = $this->evaluate($world, $action);

        $this->assertFalse($judgement->possible);
        $this->assertSame('no_object_held', $judgement->reason);
    }

    public function testHandOverWhenResidentNotInSameRoomIsImpossible(): void
    {
        $world = $this->makeWorld();
        // robot_b is in the kitchen; the resident sits in the living room.
        $world->robotStates['robot_b']->heldObjectId = 'flashlight';

        $action = new Action(
            robotId: 'robot_b',
            operation: Operation::hand_over->value,
        );

        $judgement = $this->evaluate($world, $action);

        $this->assertFalse($judgement->possible);
        $this->assertSame('not_in_same_room', $judgement->reason);
    }

    // ------------------------------------------------------------------
    // support
    // ------------------------------------------------------------------

    public function testSupportWhenResidentInSameRoomIsPossibleWithDurationTwo(): void
    {
        $world = $this->makeWorld();
        // robot_a and the resident are both in the living room; the low table
        // is here, so `under_table` is a valid destination (Req 7.13).

        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::support->value,
            targetSpot: 'under_table',
        );

        $judgement = $this->evaluate($world, $action);

        $this->assertTrue($judgement->possible);
        $this->assertSame(2, $judgement->duration);
    }

    public function testSupportWithAdjacentRoomDestinationIsPossible(): void
    {
        $world = $this->makeWorld();
        // The resident is in the living room; the kitchen is adjacent, so a
        // room-key destination is valid (Req 7.13).

        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::support->value,
            targetSpot: 'kitchen',
        );

        $judgement = $this->evaluate($world, $action);

        $this->assertTrue($judgement->possible);
        $this->assertSame(2, $judgement->duration);
    }

    public function testSupportWithNoDestinationIsImpossible(): void
    {
        $world = $this->makeWorld();
        // robot_a shares the resident's room but names no destination.

        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::support->value,
        );

        $judgement = $this->evaluate($world, $action);

        $this->assertFalse($judgement->possible);
        $this->assertSame('no_destination', $judgement->reason);
    }

    public function testSupportWithNonAdjacentRoomDestinationIsImpossible(): void
    {
        $world = $this->makeWorld();
        // The resident is in the living room; the bathroom is not adjacent to
        // it, so a bathroom destination is rejected (Req 7.13).

        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::support->value,
            targetSpot: 'bathroom',
        );

        $judgement = $this->evaluate($world, $action);

        $this->assertFalse($judgement->possible);
        $this->assertSame('no_destination', $judgement->reason);
    }

    public function testSupportWhenResidentNotInSameRoomIsImpossible(): void
    {
        $world = $this->makeWorld();
        // robot_b is in the kitchen; the resident sits in the living room. The
        // same-room check runs before the destination check, so even a valid
        // destination still yields not_in_same_room.

        $action = new Action(
            robotId: 'robot_b',
            operation: Operation::support->value,
            targetSpot: 'under_table',
        );

        $judgement = $this->evaluate($world, $action);

        $this->assertFalse($judgement->possible);
        $this->assertSame('not_in_same_room', $judgement->reason);
    }

    // ------------------------------------------------------------------
    // cover, wait, speak — no blocking pre-conditions
    // ------------------------------------------------------------------

    public function testWaitIsAlwaysPossible(): void
    {
        $world = $this->makeWorld();

        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::wait->value,
        );

        $judgement = $this->evaluate($world, $action);

        $this->assertTrue($judgement->possible);
        $this->assertNull($judgement->reason);
    }

    public function testSpeakIsAlwaysPossible(): void
    {
        $world = $this->makeWorld();

        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::speak->value,
            words: ['Hello.'],
        );

        $judgement = $this->evaluate($world, $action);

        $this->assertTrue($judgement->possible);
        $this->assertNull($judgement->reason);
    }

    // ------------------------------------------------------------------
    // R1 — window spot while shaking (task 11)
    // ------------------------------------------------------------------

    public function testMoveToWindowSpotWhileShakingIsDangerousR1(): void
    {
        $world = $this->makeWorld();
        $world->houseState->shaking = true;

        // robot_a in living_room moves to the adjacent kitchen, landing on the
        // window spot. Adjacent → possible, but the window spot is dangerous.
        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::move->value,
            targetId: 'kitchen',
            targetSpot: 'window',
        );

        $judgement = $this->evaluate($world, $action);

        $this->assertTrue($judgement->possible);
        $this->assertTrue($judgement->dangerous);
        $this->assertSame('R1', $judgement->dangerCode);
    }

    public function testMoveToWindowSpotWhileShakingIsBothImpossibleAndDangerousR1(): void
    {
        $world = $this->makeWorld();
        $world->houseState->shaking = true;

        // bathroom is not adjacent to living_room → impossible; the window spot
        // while shaking still records the R1 danger (Req 4.8).
        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::move->value,
            targetId: 'bathroom',
            targetSpot: 'window',
        );

        $judgement = $this->evaluate($world, $action);

        $this->assertFalse($judgement->possible);
        $this->assertSame('not_adjacent', $judgement->reason);
        $this->assertTrue($judgement->dangerous);
        $this->assertSame('R1', $judgement->dangerCode);
    }

    // ------------------------------------------------------------------
    // R2a — move onto gas stove spot while shaking (task 11)
    // ------------------------------------------------------------------

    public function testMoveToGasStoveSpotWhileShakingRecordsR2a(): void
    {
        $world = $this->makeWorld();
        $world->houseState->shaking = true;

        // robot_a in living_room moves to the adjacent kitchen, landing on the
        // gas stove spot.
        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::move->value,
            targetId: 'kitchen',
            targetSpot: 'gas_stove',
        );

        // Default config: r2aIsDangerous=false → code recorded, not dangerous.
        $notDangerous = $this->evaluateWith($world, $action, [$action], new EpisodeConfig(r2aIsDangerous: false));
        $this->assertSame('R2a', $notDangerous->dangerCode);
        $this->assertFalse($notDangerous->dangerous);

        // Override config: r2aIsDangerous=true → dangerous mirrors the flag.
        $dangerous = $this->evaluateWith($world, $action, [$action], new EpisodeConfig(r2aIsDangerous: true));
        $this->assertSame('R2a', $dangerous->dangerCode);
        $this->assertTrue($dangerous->dangerous);
    }

    // ------------------------------------------------------------------
    // R2b — use gas stove while shaking (task 11)
    // ------------------------------------------------------------------

    public function testUseGasStoveWhileShakingRecordsR2b(): void
    {
        $world = $this->makeWorld();
        $world->houseState->shaking = true;
        // robot_b is in the kitchen with the gas stove.

        $action = new Action(
            robotId: 'robot_b',
            operation: Operation::use->value,
            targetId: 'gas_stove',
        );

        $notDangerous = $this->evaluateWith($world, $action, [$action], new EpisodeConfig(r2bIsDangerous: false));
        $this->assertSame('R2b', $notDangerous->dangerCode);
        $this->assertFalse($notDangerous->dangerous);
        $this->assertTrue($notDangerous->possible);

        $dangerous = $this->evaluateWith($world, $action, [$action], new EpisodeConfig(r2bIsDangerous: true));
        $this->assertSame('R2b', $dangerous->dangerCode);
        $this->assertTrue($dangerous->dangerous);
    }

    // ------------------------------------------------------------------
    // R3 — moving the resident himself without concurrent support
    // ------------------------------------------------------------------

    public function testMoveLeavingResidentsRoomIsNotR3(): void
    {
        $world = $this->makeWorld();
        // robot_a and the resident are both in the living_room; robot_a leaves
        // for the adjacent kitchen. The move targets a room, not the resident,
        // so the resident stays put (Req 7.8) and there is no R3.
        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::move->value,
            targetId: 'kitchen',
        );

        $judgement = $this->evaluateWith($world, $action, [$action]);

        $this->assertFalse($judgement->dangerous);
        $this->assertNull($judgement->dangerCode);
    }

    public function testMoveTargetingResidentWithoutSupportIsDangerousR3(): void
    {
        $world = $this->makeWorld();
        // robot_a and the resident are both in the living_room; robot_a tries to
        // move the resident himself (targetId "resident") with no concurrent
        // support. Moving the resident suddenly without support is R3.
        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::move->value,
            targetId: 'resident',
        );

        $judgement = $this->evaluateWith($world, $action, [$action]);

        $this->assertTrue($judgement->dangerous);
        $this->assertSame('R3', $judgement->dangerCode);
    }

    public function testMoveTargetingResidentWithConcurrentSupportIsNotR3(): void
    {
        $world = $this->makeWorld();
        // robot_b joins the resident's room and supports the resident while
        // robot_a moves the resident; the concurrent support makes the move
        // safe (no R3).
        $world->robotStates['robot_b']->room = 'living_room';

        $moveAction = new Action(
            robotId: 'robot_a',
            operation: Operation::move->value,
            targetId: 'resident',
        );
        $supportAction = new Action(
            robotId: 'robot_b',
            operation: Operation::support->value,
            targetId: 'resident',
        );

        $judgement = $this->evaluateWith($world, $moveAction, [$moveAction, $supportAction]);

        $this->assertFalse($judgement->dangerous);
        $this->assertNull($judgement->dangerCode);
    }

    // ------------------------------------------------------------------
    // Priority conflict — same object targeted by both robots (task 11)
    // ------------------------------------------------------------------

    public function testPriorityConflictBlocksLosingRobot(): void
    {
        $world = $this->makeWorld();
        // Both robots stand in the kitchen and both target the flashlight.
        $world->robotStates['robot_a']->room = 'kitchen';
        // robot_b already starts in the kitchen.

        $actionA = new Action(
            robotId: 'robot_a',
            operation: Operation::take->value,
            targetId: 'flashlight',
        );
        $actionB = new Action(
            robotId: 'robot_b',
            operation: Operation::take->value,
            targetId: 'flashlight',
        );
        $allActions = [$actionA, $actionB];

        // currentTurn=0 → 0 % 2 == 0 → slot 0 (robot_a) wins, robot_b loses.
        $world->currentTurn = 0;
        $winnerA = $this->evaluateWith($world, $actionA, $allActions);
        $loserB = $this->evaluateWith($world, $actionB, $allActions);

        $this->assertTrue($winnerA->possible);
        $this->assertNull($winnerA->reason);
        $this->assertFalse($loserB->possible);
        $this->assertSame('priority_conflict', $loserB->reason);

        // currentTurn=1 → 1 % 2 == 1 → slot 1 (robot_b) wins, robot_a loses.
        $world->currentTurn = 1;
        $loserA = $this->evaluateWith($world, $actionA, $allActions);
        $winnerB = $this->evaluateWith($world, $actionB, $allActions);

        $this->assertFalse($loserA->possible);
        $this->assertSame('priority_conflict', $loserA->reason);
        $this->assertTrue($winnerB->possible);
        $this->assertNull($winnerB->reason);
    }
}
