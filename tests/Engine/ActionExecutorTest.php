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
 * Unit tests for ActionExecutor — move, take, use, speak, cover, and the
 * snapshot/restore rollback pattern (task 12).
 *
 * Requirements: Req 5.1–5.4, Req 5.7–5.10
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
        // The spoken words are carried in the outcome text.
        $this->assertStringContainsString('Stay calm.', (string) $outcome->whatHappenedText);
        $this->assertStringContainsString('I am here.', (string) $outcome->whatHappenedText);

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
