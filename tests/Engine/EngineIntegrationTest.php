<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\ActionExecutor;
use PoltergeistSwitch\Engine\EarthquakePhase;
use PoltergeistSwitch\Engine\Engine;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\ResidentController;
use PoltergeistSwitch\Engine\RulesEvaluator;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\SituationSpec;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\Values\TurnResult;
use PoltergeistSwitch\Engine\WorldModel;

/**
 * Integration tests for the Engine's public entry point (tasks 18.2, 19.2,
 * 21.1). Each test builds a real Engine over a freshly reset WorldModel with an
 * explicit SituationSpec, then drives whole turns through applyTurn().
 *
 * Task 18.2 (this file): basic flow — turn-clock advance, idempotency of a
 * repeated call, future-turn rejection, and a move reflected in the snapshot.
 * Tasks 19.2 and 21.1 add their own test methods to this class.
 *
 * Requirements: Req 10.1–10.5
 */
class EngineIntegrationTest extends TestCase
{
    private EpisodeConfig $config;

    protected function setUp(): void
    {
        $this->config = new EpisodeConfig();
    }

    /**
     * Build an Engine over a world reset with the given SituationSpec. No
     * earthquake is started, so applyTurn drives the quiet world one turn at a
     * time. The spec defaults place robot_a in the kitchen, robot_b in the
     * bedroom and the resident sitting at the table in the living room — a
     * layout with a clear adjacent move (kitchen ↔ living_room).
     */
    private function makeEngine(?SituationSpec $spec = null): Engine
    {
        $spec ??= new SituationSpec(
            time: 'afternoon',
            robotARoom: 'kitchen',
            robotBRoom: 'bedroom',
            manActivity: 'sitting_at_table',
            potBoiling: false,
        );

        $world = new WorldModel($this->config);
        $world->reset(Seed::fromInt(1), $spec);

        return new Engine(
            $world,
            $this->config,
            new RulesEvaluator(),
            new ActionExecutor(),
            new EarthquakePhase(),
            new ResidentController(),
            new SituationTextGenerator(),
        );
    }

    // ------------------------------------------------------------------
    // Turn-clock advance (Req 10.1)
    // ------------------------------------------------------------------

    public function testApplyTurnAdvancesClockAndReturnsResultForTurnZero(): void
    {
        $engine = $this->makeEngine();

        $result = $engine->applyTurn(0, []);

        $this->assertInstanceOf(TurnResult::class, $result);
        $this->assertSame(0, $result->turnNumber);
        $this->assertFalse($result->isError);

        // The clock has advanced to 1, which is what makes the next call to
        // applyTurn(0, ...) a past-turn lookup rather than a re-application.
        $next = $engine->applyTurn(1, []);
        $this->assertSame(1, $next->turnNumber);
        $this->assertFalse($next->isError);
    }

    // ------------------------------------------------------------------
    // Idempotency of a repeated current/past turn (Req 10.2, Req 10.3)
    // ------------------------------------------------------------------

    public function testRepeatedApplyTurnReturnsSameStoredResultWithoutReapplying(): void
    {
        $engine = $this->makeEngine();

        $first = $engine->applyTurn(0, []);
        $again = $engine->applyTurn(0, []);

        // The exact same stored object is handed back, not a recomputed one.
        $this->assertSame($first, $again);
    }

    // ------------------------------------------------------------------
    // Future-turn rejection (Req 10.4)
    // ------------------------------------------------------------------

    public function testFutureTurnReturnsErrorAndLeavesClockUnchanged(): void
    {
        $engine = $this->makeEngine();

        // Current turn is 0; asking for turn 2 is a future turn.
        $result = $engine->applyTurn(2, []);

        $this->assertTrue($result->isError);
        $this->assertSame(2, $result->turnNumber);

        // The clock is still at 0: the very next turn that can be processed is
        // turn 0, and it processes normally and advances to 1.
        $turnZero = $engine->applyTurn(0, []);
        $this->assertFalse($turnZero->isError);
        $this->assertSame(0, $turnZero->turnNumber);
    }

    // ------------------------------------------------------------------
    // A move is reflected in the returned snapshot (Req 10.5)
    // ------------------------------------------------------------------

    public function testMoveToAdjacentRoomUpdatesRobotRoomInSnapshot(): void
    {
        $engine = $this->makeEngine();

        // robot_a starts in the kitchen; kitchen ↔ living_room are adjacent.
        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::move->value,
            targetId: 'living_room',
        );

        $result = $engine->applyTurn(0, ['robot_a' => $action]);

        $this->assertFalse($result->isError);
        $this->assertSame(
            'living_room',
            $result->worldSnapshot->robotStates['robot_a']->room,
        );
    }
}
