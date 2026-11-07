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
 * Unit tests for RulesEvaluator — move and take operations (task 9).
 *
 * Requirements: Req 4.2–4.3, Req 4.7, Req 4.12
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

        // kitchen is adjacent to living_room.
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
}
