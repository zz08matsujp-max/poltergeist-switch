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

    // ------------------------------------------------------------------
    // Episode-end conditions (task 19.2, Req 10.6)
    // ------------------------------------------------------------------

    /**
     * Build an Engine and hand back both it and the live WorldModel so the
     * episode-end tests can nudge the resident state and the disasterSubsided
     * flag directly. No earthquake is started, so the quiet world leaves the
     * resident untouched turn to turn and the flag stays at whatever the test
     * sets. The default spec keeps robot_a in the kitchen and the resident
     * sitting at the table in the living room.
     *
     * @return array{Engine,WorldModel}
     */
    private function makeEngineWithWorld(): array
    {
        $spec = new SituationSpec(
            time: 'afternoon',
            robotARoom: 'kitchen',
            robotBRoom: 'bedroom',
            manActivity: 'sitting_at_table',
            potBoiling: false,
        );

        $world = new WorldModel($this->config);
        $world->reset(Seed::fromInt(1), $spec);

        $engine = new Engine(
            $world,
            $this->config,
            new RulesEvaluator(),
            new ActionExecutor(),
            new EarthquakePhase(),
            new ResidentController(),
            new SituationTextGenerator(),
        );

        return [$engine, $world];
    }

    /**
     * The episode runs up to the turn cap when stability is never reached. The
     * resident's water is forced below water_stable_threshold and left there,
     * so no turn is ever stable and only the maxTurns cap can end the episode.
     * The episode must still be running right up to the final turn, then end
     * exactly on the turn whose completion makes the count reach maxTurns.
     */
    public function testEpisodeEndsAtTurnCapWhenStabilityNeverReached(): void
    {
        [$engine, $world] = $this->makeEngineWithWorld();

        // Force a permanently unstable resident: water at the floor keeps the
        // "water > water_stable_threshold" condition false every turn.
        $world->residentState->water = 0;

        $lastTurnIndex = $this->config->maxTurns - 1;

        for ($turn = 0; $turn < $lastTurnIndex; $turn++) {
            $result = $engine->applyTurn($turn, []);
            $this->assertFalse(
                $result->episodeEnded,
                "Episode should still be running on turn {$turn}.",
            );
        }

        // Completing the final turn (index maxTurns - 1) reaches the cap.
        $final = $engine->applyTurn($lastTurnIndex, []);
        $this->assertTrue(
            $final->episodeEnded,
            'Episode should end once the turn count reaches maxTurns.',
        );
    }

    /**
     * After the disaster has subsided, a resident who is stable for
     * stableRunLength consecutive turns ends the episode early (well before the
     * turn cap). The default resident state is already within every stability
     * band, so with disasterSubsided=true the run accrues one turn at a time
     * and the episode ends on the stableRunLength-th stable turn.
     */
    public function testEpisodeEndsEarlyOnStableRunAfterDisasterSubsided(): void
    {
        [$engine, $world] = $this->makeEngineWithWorld();

        // The disaster is over; the default resident is stable from turn 0.
        $world->disasterSubsided = true;

        $runLength = $this->config->stableRunLength;

        // The first (runLength - 1) stable turns are not yet enough to end it.
        for ($turn = 0; $turn < $runLength - 1; $turn++) {
            $result = $engine->applyTurn($turn, []);
            $this->assertFalse(
                $result->episodeEnded,
                "Episode should not end before {$runLength} stable turns (turn {$turn}).",
            );
        }

        // The stableRunLength-th consecutive stable turn ends the episode.
        $result = $engine->applyTurn($runLength - 1, []);
        $this->assertTrue(
            $result->episodeEnded,
            "Episode should end after {$runLength} consecutive stable turns.",
        );

        // This is well before the turn cap, confirming an early end.
        $this->assertLessThan($this->config->maxTurns, $runLength);
    }

    /**
     * Stability alone is not enough: while disasterSubsided is false, a stable
     * run of stableRunLength turns (and more) must NOT end the episode. The
     * episode keeps running until the turn cap. Here the resident is stable
     * every turn but the disaster flag stays false, so no early end fires.
     */
    public function testEpisodeDoesNotEndEarlyWhileDisasterNotSubsided(): void
    {
        [$engine, $world] = $this->makeEngineWithWorld();

        // disasterSubsided deliberately left false (reset() clears it).
        $this->assertFalse($world->disasterSubsided);

        // Run comfortably past stableRunLength while still short of the cap.
        $turnsToRun = $this->config->stableRunLength + 2;
        $this->assertLessThan($this->config->maxTurns, $turnsToRun);

        for ($turn = 0; $turn < $turnsToRun; $turn++) {
            $result = $engine->applyTurn($turn, []);
            $this->assertFalse(
                $result->episodeEnded,
                "Episode must not end early while the disaster has not subsided (turn {$turn}).",
            );
        }
    }
}
