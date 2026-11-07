<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\ActionExecutor;
use PoltergeistSwitch\Engine\EarthquakePhase;
use PoltergeistSwitch\Engine\Engine;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\Enums\LightLevel;
use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\ResidentController;
use PoltergeistSwitch\Engine\ResidentState;
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

    // ------------------------------------------------------------------
    // Model solution integration test (task 21.1, Req 11.6)
    // ------------------------------------------------------------------

    /**
     * Three explicit starting situations are driven, turn by turn, through the
     * hand-written "model solution" action plans documented in design.md
     * §Model Solution Test. Each plan uses exactly one physical action per
     * robot per turn and only objects in the robot's current room. The plans
     * are built to keep the resident safe, so at episode end every threshold
     * from EpisodeConfig must hold:
     *
     *   - injury <= modelSolutionInjuryThreshold
     *   - fear   <= modelSolutionFearThreshold
     *   - water  >= modelSolutionWaterThreshold
     *
     * The SituationSpecs are hard-coded (never seed-derived), so the test
     * stays valid regardless of how SituationSpec::fromSeed evolves.
     *
     * Turn/offset mapping: the earthquake begins on turn 0
     * (earthquakeStartTurn = 0), so the earthquake offset equals the turn
     * number. The design's plan turns T1–T13 (offsets 1–13) therefore map to
     * turn numbers 1–13; turn 0 is the quiet pre-shake offset-0 turn, driven
     * with no actions.
     */
    public function testModelSolutionKeepsResidentSafe(): void
    {
        foreach ($this->modelSolutionScenarios() as $name => [$spec, $plan]) {
            $run = $this->runModelSolution($spec, $plan);

            /** @var ResidentState $state */
            $state = $run['residentState'];
            /** @var array<int,TurnResult> $results */
            $results = $run['results'];
            /** @var WorldModel $world */
            $world = $run['world'];

            $this->assertLessThanOrEqual(
                $this->config->modelSolutionInjuryThreshold,
                $state->injury,
                "Scenario {$name}: resident injury must stay within the model-solution threshold.",
            );
            $this->assertLessThanOrEqual(
                $this->config->modelSolutionFearThreshold,
                $state->fear,
                "Scenario {$name}: resident fear must stay within the model-solution threshold.",
            );
            $this->assertGreaterThanOrEqual(
                $this->config->modelSolutionWaterThreshold,
                $state->water,
                "Scenario {$name}: resident water must stay at or above the model-solution threshold.",
            );

            // Task 27.3: every planned physical action must be judged possible.
            $this->assertEveryPlannedActionPossible($name, $plan, $results);

            // Task 27.3: in S2 the carried lit flashlight keeps the bedroom lit
            // from the time it arrives (T4) onward. Assert it is not dark on
            // turns T5 through T11 inclusive. The room light lives on the live
            // WorldModel (the snapshot carries no rooms), so runModelSolution
            // samples the bedroom light level right after each turn completes.
            if ($name === 'S2') {
                /** @var array<int,LightLevel> $bedroomLight */
                $bedroomLight = $run['bedroomLight'];
                $this->assertS2BedroomLitThroughQuietTurns($bedroomLight);
            }
        }
    }

    /**
     * Physical operations that actually change the world and therefore must be
     * judged possible when they appear in a plan. `wait` and `speak` carry no
     * physical action, so a turn with only those has nothing to check.
     */
    private const PHYSICAL_OPERATIONS = ['move', 'take', 'use', 'cover', 'hand_over', 'support'];

    /**
     * Assert that for every turn in the plan, each robot's planned physical
     * action was judged possible (possible=true, reason=null). Fails with a
     * message naming the scenario, turn, robot, and the engine's reason.
     *
     * @param array<int,array<string,Action>> $plan
     * @param array<int,TurnResult>           $results turn number => result
     */
    private function assertEveryPlannedActionPossible(
        string $scenario,
        array $plan,
        array $results,
    ): void {
        foreach ($plan as $turn => $actions) {
            foreach ($actions as $robotId => $action) {
                if (!in_array($action->operation, self::PHYSICAL_OPERATIONS, true)) {
                    continue;
                }

                $result = $results[$turn];
                $this->assertArrayHasKey(
                    $robotId,
                    $result->judgements,
                    "Scenario {$scenario}, turn {$turn}: no judgement recorded for {$robotId}'s "
                        . "{$action->operation} action.",
                );

                $judgement = $result->judgements[$robotId];
                $this->assertTrue(
                    $judgement->possible,
                    "Scenario {$scenario}, turn {$turn}: {$robotId}'s {$action->operation} action "
                        . 'was judged impossible'
                        . ($judgement->reason !== null ? " (reason: {$judgement->reason})." : '.'),
                );
                $this->assertNull(
                    $judgement->reason,
                    "Scenario {$scenario}, turn {$turn}: {$robotId}'s {$action->operation} action "
                        . "carried an impossibility reason: {$judgement->reason}.",
                );
            }
        }
    }

    /**
     * Assert the bedroom light level is normal (not dark) on turns T5 through
     * T11 inclusive — the carried lit flashlight keeps the dark bedroom lit
     * across the quiet turns.
     *
     * @param array<int,LightLevel> $bedroomLight turn number => bedroom light
     */
    private function assertS2BedroomLitThroughQuietTurns(array $bedroomLight): void
    {
        for ($turn = 5; $turn <= 11; $turn++) {
            $this->assertArrayHasKey(
                $turn,
                $bedroomLight,
                "Scenario S2: no bedroom light level recorded for turn {$turn}.",
            );
            $this->assertSame(
                LightLevel::normal,
                $bedroomLight[$turn],
                "Scenario S2: the carried lit flashlight must keep the bedroom lit on turn {$turn} "
                    . "(it was {$bedroomLight[$turn]->value}).",
            );
        }
    }

    /**
     * Reset a world with the given explicit SituationSpec, start the
     * earthquake on turn 0, and drive turns 0..13 through applyTurn using the
     * supplied plan. Returns the final resident state, every turn's result
     * keyed by turn number, and the live WorldModel so callers can inspect
     * per-turn judgements and current room light levels.
     *
     * @param array<int,array<string,Action>> $plan turn number => actions keyed
     *                                               by robot id (turns 1..13;
     *                                               turn 0 is always quiet)
     *
     * @return array{
     *     residentState:ResidentState,
     *     results:array<int,TurnResult>,
     *     world:WorldModel,
     *     bedroomLight:array<int,LightLevel>
     * }
     */
    private function runModelSolution(SituationSpec $spec, array $plan): array
    {
        $world = new WorldModel($this->config);
        $world->reset(Seed::fromInt(1), $spec);

        // The observer layer normally records the earthquake start on the
        // world; here the test stands in for it. Offset == turn number.
        $world->earthquakeStartTurn = 0;

        $engine = new Engine(
            $world,
            $this->config,
            new RulesEvaluator(),
            new ActionExecutor(),
            new EarthquakePhase(),
            new ResidentController(),
            new SituationTextGenerator(),
        );

        $results = [];
        $bedroomLight = [];

        $results[0] = $engine->applyTurn(0, []);
        $bedroomLight[0] = $world->getRoom('bedroom')->lightLevel;
        for ($turn = 1; $turn <= 13; $turn++) {
            $actions = $plan[$turn] ?? [];
            $results[$turn] = $engine->applyTurn($turn, $actions);
            // Sample the live bedroom light right after the turn completes; the
            // snapshot carries no rooms, so this is the only place to read it.
            $bedroomLight[$turn] = $world->getRoom('bedroom')->lightLevel;
        }

        return [
            'residentState' => $results[13]->residentState,
            'results' => $results,
            'world' => $world,
            'bedroomLight' => $bedroomLight,
        ];
    }

    /**
     * The three model-solution scenarios (S0, S2, S4) with their explicit
     * SituationSpecs and turn-by-turn action plans, exactly as documented in
     * design.md §Model Solution Test.
     *
     * @return array<string,array{0:SituationSpec,1:array<int,array<string,Action>>}>
     */
    private function modelSolutionScenarios(): array
    {
        return [
            'S0' => [
                new SituationSpec(
                    time: 'afternoon',
                    robotARoom: 'kitchen',
                    robotBRoom: 'bedroom',
                    manActivity: 'sitting_at_table',
                    potBoiling: true,
                ),
                $this->planS0(),
            ],
            'S2' => [
                new SituationSpec(
                    time: 'night',
                    robotARoom: 'living_room',
                    robotBRoom: 'kitchen',
                    manActivity: 'asleep_in_futon',
                    potBoiling: false,
                ),
                $this->planS2(),
            ],
            'S4' => [
                new SituationSpec(
                    time: 'morning',
                    robotARoom: 'living_room',
                    robotBRoom: 'bedroom',
                    manActivity: 'cooking',
                    potBoiling: true,
                ),
                $this->planS4(),
            ],
        ];
    }

    /**
     * S0 plan (afternoon; Robot_A in kitchen with the pot boiling; man at the
     * table in the living room; Robot_B in bedroom). The resident stays in the
     * living room throughout, so Robot_B joins him there and shields him while
     * Robot_A turns off the stove and then keeps the man company by speaking.
     *
     * @return array<int,array<string,Action>>
     */
    private function planS0(): array
    {
        $plan = [];

        // T1: A turns off the stove in the kitchen; B walks to the living room.
        $plan[1] = [
            'robot_a' => $this->use('robot_a', 'gas_stove'),
            'robot_b' => $this->move('robot_b', 'living_room'),
        ];
        // T2: A follows to the living room; B shields the resident.
        $plan[2] = [
            'robot_a' => $this->move('robot_a', 'living_room'),
            'robot_b' => $this->cover('robot_b'),
        ];
        // T3, T4: A speaks to the resident; B keeps shielding him.
        $plan[3] = [
            'robot_a' => $this->speak('robot_a'),
            'robot_b' => $this->cover('robot_b'),
        ];
        $plan[4] = [
            'robot_a' => $this->speak('robot_a'),
            'robot_b' => $this->cover('robot_b'),
        ];
        // T5-T11: A speaks; B waits.
        for ($turn = 5; $turn <= 11; $turn++) {
            $plan[$turn] = [
                'robot_a' => $this->speak('robot_a'),
                'robot_b' => $this->wait('robot_b'),
            ];
        }
        // T12 (aftershock): A speaks; B shields the resident.
        $plan[12] = [
            'robot_a' => $this->speak('robot_a'),
            'robot_b' => $this->cover('robot_b'),
        ];
        // T13: A speaks; B waits.
        $plan[13] = [
            'robot_a' => $this->speak('robot_a'),
            'robot_b' => $this->wait('robot_b'),
        ];

        return $plan;
    }

    /**
     * S2 plan (night; Robot_A in the living room; man asleep in the bedroom;
     * Robot_B in the kitchen; no pot). Robot_A reaches the sleeping man and
     * shields him; Robot_B fetches and lights the flashlight, then carries it
     * into the bedroom so the dark room is lit from T4 on.
     *
     * @return array<int,array<string,Action>>
     */
    private function planS2(): array
    {
        $plan = [];

        // T1: A walks to the bedroom; B picks up the flashlight (in kitchen).
        $plan[1] = [
            'robot_a' => $this->move('robot_a', 'bedroom'),
            'robot_b' => $this->take('robot_b', 'flashlight'),
        ];
        // T2 (outage; night, rooms go dark): A shields the man; B lights the
        // flashlight.
        $plan[2] = [
            'robot_a' => $this->cover('robot_a'),
            'robot_b' => $this->use('robot_b', 'flashlight'),
        ];
        // T3: A keeps shielding; B walks kitchen -> living_room.
        $plan[3] = [
            'robot_a' => $this->cover('robot_a'),
            'robot_b' => $this->move('robot_b', 'living_room'),
        ];
        // T4: A keeps shielding; B walks living_room -> bedroom (flashlight
        // now lights the bedroom).
        $plan[4] = [
            'robot_a' => $this->cover('robot_a'),
            'robot_b' => $this->move('robot_b', 'bedroom'),
        ];
        // T5-T11: A speaks; B waits.
        for ($turn = 5; $turn <= 11; $turn++) {
            $plan[$turn] = [
                'robot_a' => $this->speak('robot_a'),
                'robot_b' => $this->wait('robot_b'),
            ];
        }
        // T12 (aftershock): A shields the man; B waits.
        $plan[12] = [
            'robot_a' => $this->cover('robot_a'),
            'robot_b' => $this->wait('robot_b'),
        ];
        // T13: A speaks; B waits.
        $plan[13] = [
            'robot_a' => $this->speak('robot_a'),
            'robot_b' => $this->wait('robot_b'),
        ];

        return $plan;
    }

    /**
     * S4 plan (morning; Robot_A in the living room; man cooking in the kitchen
     * with the pot boiling; Robot_B in the bedroom). Robot_A reaches the
     * kitchen, turns off the stove and shields the man; Robot_B fetches and
     * lights the flashlight so the kitchen is lit after the outage.
     *
     * @return array<int,array<string,Action>>
     */
    private function planS4(): array
    {
        $plan = [];

        // T1: A walks living_room -> kitchen; B walks bedroom -> living_room.
        $plan[1] = [
            'robot_a' => $this->move('robot_a', 'kitchen'),
            'robot_b' => $this->move('robot_b', 'living_room'),
        ];
        // T2 (outage; morning, rooms go dark): A turns off the stove; B walks
        // living_room -> kitchen.
        $plan[2] = [
            'robot_a' => $this->use('robot_a', 'gas_stove'),
            'robot_b' => $this->move('robot_b', 'kitchen'),
        ];
        // T3: A shields the man; B picks up the flashlight (in kitchen).
        $plan[3] = [
            'robot_a' => $this->cover('robot_a'),
            'robot_b' => $this->take('robot_b', 'flashlight'),
        ];
        // T4: A keeps shielding; B lights the flashlight.
        $plan[4] = [
            'robot_a' => $this->cover('robot_a'),
            'robot_b' => $this->use('robot_b', 'flashlight'),
        ];
        // T5-T11: A speaks; B waits.
        for ($turn = 5; $turn <= 11; $turn++) {
            $plan[$turn] = [
                'robot_a' => $this->speak('robot_a'),
                'robot_b' => $this->wait('robot_b'),
            ];
        }
        // T12 (aftershock): A shields the man; B waits.
        $plan[12] = [
            'robot_a' => $this->cover('robot_a'),
            'robot_b' => $this->wait('robot_b'),
        ];
        // T13: A speaks; B waits.
        $plan[13] = [
            'robot_a' => $this->speak('robot_a'),
            'robot_b' => $this->wait('robot_b'),
        ];

        return $plan;
    }

    // ------------------------------------------------------------------
    // Small action builders for the model-solution plans.
    // ------------------------------------------------------------------

    private function move(string $robotId, string $roomKey): Action
    {
        return new Action(
            robotId: $robotId,
            operation: Operation::move->value,
            targetId: $roomKey,
        );
    }

    private function take(string $robotId, string $objectId): Action
    {
        return new Action(
            robotId: $robotId,
            operation: Operation::take->value,
            targetId: $objectId,
        );
    }

    private function use(string $robotId, string $objectId): Action
    {
        return new Action(
            robotId: $robotId,
            operation: Operation::use->value,
            targetId: $objectId,
        );
    }

    /** Shield the resident (cover action targeting the resident). */
    private function cover(string $robotId): Action
    {
        return new Action(
            robotId: $robotId,
            operation: Operation::cover->value,
            targetId: 'resident',
        );
    }

    /** Speak to the resident: a word line is required to reassure him. */
    private function speak(string $robotId): Action
    {
        return new Action(
            robotId: $robotId,
            operation: Operation::speak->value,
            words: ["It's all right, I'm here."],
        );
    }

    private function wait(string $robotId): Action
    {
        return new Action(
            robotId: $robotId,
            operation: Operation::wait->value,
        );
    }
}
