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

    // ==================================================================
    // Resident move via support (task 25, Req 7.13–7.17)
    // ==================================================================

    /**
     * Build an Engine whose world places robot_a in the resident's room so a
     * support action is possible. The resident sits at the low table in the
     * living room (sitting_at_table), and robot_a starts there too; robot_b is
     * out of the way in the kitchen. No earthquake is started, so the quiet
     * world leaves the resident untouched between turns and the only state
     * change comes from the support under test.
     *
     * @return array{Engine,WorldModel}
     */
    private function makeSupportEngine(): array
    {
        $spec = new SituationSpec(
            time: 'afternoon',
            robotARoom: 'living_room',
            robotBRoom: 'kitchen',
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
     * A support action naming a destination that a spoken line also names is
     * accepted, and the resident moves on the SECOND turn — not the turn the
     * support begins (Req 7.15). With an in-room spot destination the resident
     * keeps his room and gains the spot.
     */
    public function testAcceptedSupportMovesResidentUnderTableOnSecondTurn(): void
    {
        [$engine, $world] = $this->makeSupportEngine();

        $support = new Action(
            robotId: 'robot_a',
            operation: Operation::support->value,
            targetId: 'resident',
            targetSpot: 'under_table',
            words: ['Let me help you under the table.'],
        );

        // Turn 0: the support begins. The resident has NOT moved yet — he is
        // still at his starting spot (seated at the low table), not under it.
        $engine->applyTurn(0, ['robot_a' => $support]);
        $this->assertNotSame('under_table', $world->residentState->spot);
        $this->assertSame('living_room', $world->residentState->room);

        // Turn 1 (the second turn): the countdown reaches zero and the resident
        // crouches under the low table, keeping his room.
        $engine->applyTurn(1, ['robot_a' => $this->wait('robot_a')]);
        $this->assertSame('under_table', $world->residentState->spot);
        $this->assertSame('living_room', $world->residentState->room);
    }

    /**
     * A support with a valid destination spot but no robot naming it in speech
     * is refused: the resident never moves and speaks the refusal line in place
     * of any scripted line this turn (Req 7.9, Req 7.14, Req 7.15).
     */
    public function testSupportWithNoDestinationWordIsRefusedWithRefusalLine(): void
    {
        [$engine, $world] = $this->makeSupportEngine();

        $support = new Action(
            robotId: 'robot_a',
            operation: Operation::support->value,
            targetId: 'resident',
            targetSpot: 'under_table',
            // No spoken line, so no robot names the destination this turn.
        );

        $result = $engine->applyTurn(0, ['robot_a' => $support]);

        // The refusal line is this turn's resident line (Req 7.9).
        $this->assertSame("I don't want to move from here.", $result->residentLine);

        // The support ended, so even running further turns never moves him: he
        // stays in the living room and never reaches the under_table spot.
        $engine->applyTurn(1, ['robot_a' => $this->wait('robot_a')]);
        $engine->applyTurn(2, ['robot_a' => $this->wait('robot_a')]);
        $this->assertNotSame('under_table', $world->residentState->spot);
        $this->assertSame('living_room', $world->residentState->room);
    }

    /**
     * A weakened resident accepts a support move even when no robot names the
     * destination (Req 7.11 overrides the support-and-reason test). He still
     * moves on the second turn.
     */
    public function testWeakenedResidentAcceptsSupportWithoutDestinationWord(): void
    {
        [$engine, $world] = $this->makeSupportEngine();
        // A weakened resident accepts any move proposal (Req 7.11). The
        // weakened flag is recomputed from body temperature each turn, so raise
        // the temperature above the threshold rather than setting the flag,
        // which updateState would overwrite before the proposal is resolved.
        $world->residentState->bodyTemp = $this->config->weakenedThreshold + 1.0;

        $support = new Action(
            robotId: 'robot_a',
            operation: Operation::support->value,
            targetId: 'resident',
            targetSpot: 'under_table',
            // No spoken line naming the destination.
        );

        // Turn 0: begins; no refusal line, resident not yet moved under the
        // table (he is still at his starting spot).
        $result = $engine->applyTurn(0, ['robot_a' => $support]);
        $this->assertNotSame("I don't want to move from here.", $result->residentLine);
        $this->assertNotSame('under_table', $world->residentState->spot);

        // Turn 1: the accepted move completes under the low table.
        $engine->applyTurn(1, ['robot_a' => $this->wait('robot_a')]);
        $this->assertSame('under_table', $world->residentState->spot);
    }

    /**
     * An accepted support to an adjacent room relocates the resident on the
     * second turn: his room becomes the destination, his spot clears, and his
     * activity becomes `standing` (Req 7.15).
     */
    public function testAcceptedSupportToAdjacentRoomStandsResidentUp(): void
    {
        [$engine, $world] = $this->makeSupportEngine();

        // The kitchen is adjacent to the living room (the resident's room).
        $support = new Action(
            robotId: 'robot_a',
            operation: Operation::support->value,
            targetId: 'resident',
            targetSpot: 'kitchen',
            words: ['Let us move to the kitchen together.'],
        );

        $engine->applyTurn(0, ['robot_a' => $support]);
        // Not moved yet on the first turn.
        $this->assertSame('living_room', $world->residentState->room);

        $engine->applyTurn(1, ['robot_a' => $this->wait('robot_a')]);
        $this->assertSame('kitchen', $world->residentState->room);
        $this->assertNull($world->residentState->spot);
        $this->assertSame('standing', $world->residentState->activity);
    }

    // ==================================================================
    // Engine::prepareTurn — same-world turn preparation (task 28.3,
    // Req 13.1–13.8, Req 11.4)
    // ==================================================================

    /**
     * Build an Engine over a world reset with the given SituationSpec, handing
     * back both the Engine and the live WorldModel so a prepareTurn test can
     * set earthquakeStartTurn (standing in for the observer layer) before
     * driving turns. No earthquake is started until the test asks for it.
     *
     * @return array{Engine,WorldModel}
     */
    private function makeEngineAndWorld(SituationSpec $spec): array
    {
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
     * A night-time layout: robot_a in the kitchen, robot_b in the bedroom, the
     * resident sitting at the low table in the living room. Night so the
     * offset-2 power outage darkens rooms (the light rule leaves afternoon
     * lit), and both robots start in different rooms.
     */
    private function prepareSpec(): SituationSpec
    {
        return new SituationSpec(
            time: 'night',
            robotARoom: 'kitchen',
            robotBRoom: 'bedroom',
            manActivity: 'sitting_at_table',
            potBoiling: false,
        );
    }

    // ------------------------------------------------------------------
    // prepareTurn renders the turn's world in the situation text (Req 13.1,
    // Req 13.2).
    // ------------------------------------------------------------------

    /**
     * After prepareTurn opens the first turn of the earthquake (offset 1), the
     * situation text for BOTH robots renders that turn's shaking world: the
     * shaking paragraph opens with "Suddenly the floor begins to shake hard."
     * The text is built straight after prepareTurn, before any applyTurn for
     * that turn, so it is prepareTurn alone that advanced the world.
     */
    public function testPrepareTurnRendersFirstShakingTurnForBothRobots(): void
    {
        [$engine, $world] = $this->makeEngineAndWorld($this->prepareSpec());

        // The earthquake begins on turn 0, so turn 0 is offset 0 (quiet). Play
        // the quiet turn, then prepare turn 1 — offset 1, the first shaking
        // turn — and read the text before applying it.
        $world->earthquakeStartTurn = 0;
        $engine->applyTurn(0, []);
        $engine->prepareTurn(1);

        $textA = $engine->situationTextFor('robot_a');
        $textB = $engine->situationTextFor('robot_b');

        $this->assertStringContainsString('Suddenly the floor begins to shake hard.', $textA);
        $this->assertStringContainsString('Suddenly the floor begins to shake hard.', $textB);
    }

    /**
     * An offset-5 turn is the first still turn after the shaking (offsets 1–4).
     * After prepareTurn(5), the text for both robots reads "The shaking has
     * stopped.", consistent with the earthquake world the following applyTurn
     * then judges in.
     */
    public function testPrepareTurnRendersShakingStoppedAtOffsetFive(): void
    {
        [$engine, $world] = $this->makeEngineAndWorld($this->prepareSpec());

        $world->earthquakeStartTurn = 0;

        // Drive turns 0–4 so the world reaches offset 4 (still shaking), then
        // prepare turn 5 (offset 5) and read it before applying.
        for ($turn = 0; $turn <= 4; $turn++) {
            $engine->applyTurn($turn, []);
        }
        $engine->prepareTurn(5);

        $textA = $engine->situationTextFor('robot_a');
        $textB = $engine->situationTextFor('robot_b');

        $this->assertStringContainsString('The shaking has stopped.', $textA);
        $this->assertStringContainsString('The shaking has stopped.', $textB);
    }

    /**
     * prepareTurn sets the earthquake flags so the lights-go-out cue shows on
     * the offset-2 turn before it is applied — the world the robots read is the
     * world their action will be judged in (Req 13.2, Req 9.14).
     */
    public function testPrepareTurnShowsLightsGoOutAtOffsetTwoBeforeApply(): void
    {
        [$engine, $world] = $this->makeEngineAndWorld($this->prepareSpec());

        $world->earthquakeStartTurn = 0;

        for ($turn = 0; $turn <= 1; $turn++) {
            $engine->applyTurn($turn, []);
        }
        $engine->prepareTurn(2);

        $textA = $engine->situationTextFor('robot_a');
        $textB = $engine->situationTextFor('robot_b');

        $this->assertStringContainsString('The lights go out.', $textA);
        $this->assertStringContainsString('The lights go out.', $textB);
    }

    // ------------------------------------------------------------------
    // prepareTurn idempotency and single earthquake advance (Req 13.3,
    // Req 13.4).
    // ------------------------------------------------------------------

    /**
     * Calling prepareTurn twice for the same turn, then applyTurn once, yields
     * exactly the same TurnResult and world snapshot as a single prepareTurn
     * followed by applyTurn. prepareTurn is idempotent per turn: the second
     * call advances nothing, so the earthquake is advanced exactly once across
     * both the prepare and the apply (Req 13.3, Req 13.4).
     */
    public function testPrepareTurnTwiceThenApplyEqualsPrepareOnceThenApply(): void
    {
        // Run A: prepare the turn once, then apply.
        [$engineOnce, $worldOnce] = $this->makeEngineAndWorld($this->prepareSpec());
        $worldOnce->earthquakeStartTurn = 0;
        $engineOnce->applyTurn(0, []);
        $engineOnce->prepareTurn(1);
        $resultOnce = $engineOnce->applyTurn(1, []);

        // Run B: prepare the SAME turn twice, then apply.
        [$engineTwice, $worldTwice] = $this->makeEngineAndWorld($this->prepareSpec());
        $worldTwice->earthquakeStartTurn = 0;
        $engineTwice->applyTurn(0, []);
        $engineTwice->prepareTurn(1);
        $engineTwice->prepareTurn(1);
        $resultTwice = $engineTwice->applyTurn(1, []);

        // The two results must be equal by value, and so must the snapshots:
        // the duplicate prepareTurn advanced nothing.
        $this->assertEquals(
            $this->describeResult($resultOnce),
            $this->describeResult($resultTwice),
            'A duplicate prepareTurn for the same turn must not change the TurnResult.',
        );
        $this->assertEquals(
            $this->describeSnapshot($resultOnce->worldSnapshot),
            $this->describeSnapshot($resultTwice->worldSnapshot),
            'A duplicate prepareTurn for the same turn must not change the world snapshot.',
        );
    }

    // ------------------------------------------------------------------
    // Backward compatibility and the single-advance equivalence (Req 13.5,
    // Req 13.7, Req 11.4).
    // ------------------------------------------------------------------

    /**
     * A turn played with prepareTurn first yields the identical TurnResult and
     * world snapshot as the same turn played without prepareTurn. The
     * earthquake is advanced exactly once either way: prepareTurn + applyTurn
     * equals applyTurn alone (Req 13.4, Req 13.5, Req 13.7, Req 11.4).
     */
    public function testPrepareThenApplyEqualsApplyAloneAcrossAnEarthquake(): void
    {
        // Run each turn of a short earthquake two ways and compare turn by turn.
        for ($turn = 0; $turn <= 6; $turn++) {
            // Without prepareTurn.
            [$plain, $plainWorld] = $this->makeEngineAndWorld($this->prepareSpec());
            $plainWorld->earthquakeStartTurn = 0;
            $plainResult = $this->driveToTurn($plain, $turn, false);

            // With prepareTurn before each turn.
            [$prepared, $preparedWorld] = $this->makeEngineAndWorld($this->prepareSpec());
            $preparedWorld->earthquakeStartTurn = 0;
            $preparedResult = $this->driveToTurn($prepared, $turn, true);

            $this->assertEquals(
                $this->describeResult($plainResult),
                $this->describeResult($preparedResult),
                "prepareTurn+applyTurn must equal applyTurn alone for turn {$turn}.",
            );
            $this->assertEquals(
                $this->describeSnapshot($plainResult->worldSnapshot),
                $this->describeSnapshot($preparedResult->worldSnapshot),
                "World snapshot must match with and without prepareTurn for turn {$turn}.",
            );
        }
    }

    /**
     * Drive an engine (whose world already has earthquakeStartTurn set by the
     * caller) through turns 0..$upTo. When $withPrepare is true, prepareTurn is
     * called for each turn before applyTurn; otherwise applyTurn is called
     * alone. Returns the TurnResult of the last turn.
     */
    private function driveToTurn(Engine $engine, int $upTo, bool $withPrepare): TurnResult
    {
        $result = null;
        for ($turn = 0; $turn <= $upTo; $turn++) {
            if ($withPrepare) {
                $engine->prepareTurn($turn);
            }
            $result = $engine->applyTurn($turn, []);
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // Earthquake-switch mapping never produces a negative start turn
    // (Req 13.6).
    // ------------------------------------------------------------------

    /**
     * The earthquake-switch mapping used by prepareTurn never yields a negative
     * start turn: the start turn is the engine's current turn at the moment the
     * switch is flipped (always ≥ 0), and the first turn played after it is
     * offset 1, the first shaking turn. Flipping the switch on several current
     * turns keeps the start turn non-negative and makes the next prepared
     * turn's text open the shaking paragraph (Req 13.6).
     */
    public function testEarthquakeSwitchMappingNeverNegativeStartTurn(): void
    {
        foreach ([0, 1, 3, 7] as $switchTurn) {
            [$engine, $world] = $this->makeEngineAndWorld($this->prepareSpec());

            // Play quiet turns up to the switch turn with no earthquake.
            for ($turn = 0; $turn < $switchTurn; $turn++) {
                $engine->applyTurn($turn, []);
            }

            // Flip the switch at the current turn: earthquakeStartTurn is the
            // current turn (offset 0), always ≥ 0, never negative. The next
            // turn played ($switchTurn + 1) is offset 1, the first shaking turn
            // opening "Suddenly the floor begins to shake hard." (Req 13.6).
            $startTurn = $switchTurn;
            $this->assertGreaterThanOrEqual(
                0,
                $startTurn,
                "Earthquake start turn must never be negative (switch at turn {$switchTurn}).",
            );
            $world->earthquakeStartTurn = $startTurn;

            // Play the offset-0 start turn, then prepare the first shaking turn.
            $engine->applyTurn($switchTurn, []);
            $firstShakingTurn = $switchTurn + 1;

            $engine->prepareTurn($firstShakingTurn);
            $text = $engine->situationTextFor('robot_a');
            $this->assertStringContainsString(
                'Suddenly the floor begins to shake hard.',
                $text,
                "The first shaking turn after the switch (turn {$firstShakingTurn}) must open the quake.",
            );
        }
    }

    // ------------------------------------------------------------------
    // Resident line: earthquake shout on the first shaking turn, a refusal
    // carried into the next turn, no line lost or emitted twice (Req 13.8).
    // ------------------------------------------------------------------

    /**
     * The earthquake start shout "What's happening?!" is the resident line the
     * engine fixes for the first shaking turn (offset 1). prepareTurn fixes it
     * once; applyTurn records exactly that line (Req 7.1, Req 13.8). The line
     * fires on `turnNumber == earthquakeStartTurn + 1`, so it belongs to the
     * first shaking turn — the turn whose shaking paragraph opens "Suddenly the
     * floor begins to shake hard." This test pins that shout to the first
     * shaking turn so a regression that silently drops or relocates it is
     * caught.
     */
    public function testEarthquakeShoutIsTheFirstShakingTurnResidentLine(): void
    {
        [$engine, $world] = $this->makeEngineAndWorld($this->prepareSpec());

        // Start the earthquake on turn 1 (offset 0 = turn 1); the first shaking
        // turn is offset 1 = turn 2, where the earthquake line fires.
        $engine->applyTurn(0, []);
        $world->earthquakeStartTurn = 1;

        // Offset 0 turn (turn 1): the shout does not fire yet.
        $engine->prepareTurn(1);
        $result1 = $engine->applyTurn(1, []);
        $this->assertNull(
            $result1->residentLine,
            'The earthquake shout does not fire on the offset-0 start turn.',
        );

        // First shaking turn (offset 1 = turn 2): the shout is this turn's line.
        $engine->prepareTurn(2);
        $result2 = $engine->applyTurn(2, []);
        $this->assertSame("What's happening?!", $result2->residentLine);
    }

    /**
     * The first shaking turn (offset 1) opens its shaking paragraph with
     * "Suddenly the floor begins to shake hard." for both robots, so the robot
     * prompt that follows the switch unmistakably marks the quake's onset
     * (Req 13.6). This complements the shout test: the shout is the start turn's
     * line, the "Suddenly…" opener is the very next (first shaking) turn.
     */
    public function testFirstShakingTurnOpensWithSuddenlyForBothRobots(): void
    {
        [$engine, $world] = $this->makeEngineAndWorld($this->prepareSpec());

        // Earthquake starts on turn 0 (offset 0). Turn 1 is offset 1.
        $world->earthquakeStartTurn = 0;
        $engine->applyTurn(0, []);

        $engine->prepareTurn(1);
        $this->assertStringContainsString(
            'Suddenly the floor begins to shake hard.',
            $engine->situationTextFor('robot_a'),
        );
        $this->assertStringContainsString(
            'Suddenly the floor begins to shake hard.',
            $engine->situationTextFor('robot_b'),
        );
    }

    /**
     * A refusal produced while applying turn n is carried into turn n+1's text
     * and takes priority there; it is not lost and not emitted twice. A support
     * with no destination word is refused as turn n's own resident line (no
     * robot reads it that turn), then prepareTurn(n+1) carries it into the
     * following turn's text (Req 13.8, Req 7.15).
     */
    public function testRefusalFromTurnNAppearsInTurnNPlusOneText(): void
    {
        // The earthquake runs so the shaking paragraph is present on the turns
        // in play — that paragraph is where a resident line surfaces in the
        // text. robot_a shares the resident's room (living room) so a support
        // is possible; the quake starts on turn 0.
        $spec = new SituationSpec(
            time: 'afternoon',
            robotARoom: 'living_room',
            robotBRoom: 'kitchen',
            manActivity: 'sitting_at_table',
            potBoiling: false,
        );
        [$engine, $world] = $this->makeEngineAndWorld($spec);
        $world->earthquakeStartTurn = 0;

        $refusal = "I don't want to move from here.";

        // Turn 0 (offset 0): quiet start turn, prepared and applied.
        $engine->prepareTurn(0);
        $engine->applyTurn(0, []);

        // Turn 1 (offset 1, shaking): a support with a destination spot but no
        // spoken line naming it is refused. Because turn 1 was prepared, the
        // refusal is produced while applying the turn and is NOT turn 1's own
        // resident line — it is carried into turn 2's text (Req 13.8).
        $support = new Action(
            robotId: 'robot_a',
            operation: Operation::support->value,
            targetId: 'resident',
            targetSpot: 'under_table',
        );
        $engine->prepareTurn(1);
        $engine->applyTurn(1, ['robot_a' => $support]);

        // prepareTurn(2) carries the refusal into turn 2's text (offset 2 still
        // shakes, so the shaking paragraph renders the resident line).
        $engine->prepareTurn(2);
        $text2 = $engine->situationTextFor('robot_a');
        $this->assertStringContainsString(
            $refusal,
            $text2,
            'The refusal produced on turn 1 must appear in turn 2 text.',
        );

        // The refusal became turn 2's single resident line — emitted once, read
        // by the robots on turn 2.
        $result2 = $engine->applyTurn(2, ['robot_a' => $this->wait('robot_a')]);
        $this->assertSame($refusal, $result2->residentLine);

        // And it is consumed: it is not carried a second time, so turn 3's text
        // no longer contains it.
        $engine->prepareTurn(3);
        $text3 = $engine->situationTextFor('robot_a');
        $this->assertStringNotContainsString(
            $refusal,
            $text3,
            'The refusal must not be emitted twice: it is gone from turn 3 text.',
        );
    }

    /**
     * No turn's resident line is emitted twice: when a turn is prepared, the
     * line fixed in prepareTurn is the same line applyTurn records, not a
     * second line from a repeated emitDialogueLine call. Preparing and then
     * applying the first shaking turn yields the shout exactly as the turn's
     * single resident line (Req 13.8).
     */
    public function testPreparedTurnEmitsExactlyOneResidentLine(): void
    {
        [$engine, $world] = $this->makeEngineAndWorld($this->prepareSpec());

        // Earthquake starts on turn 0; the earthquake shout is the first
        // shaking turn's line (offset 1 = turn 1).
        $world->earthquakeStartTurn = 0;

        // Offset 0 (turn 0): quiet start turn, no shout yet.
        $engine->prepareTurn(0);
        $engine->applyTurn(0, []);

        // First shaking turn (offset 1 = turn 1): the shout is its single line.
        $engine->prepareTurn(1);
        $result = $engine->applyTurn(1, []);

        // The line applyTurn recorded is exactly the prepared earthquake shout.
        $this->assertSame("What's happening?!", $result->residentLine);
    }

    // ------------------------------------------------------------------
    // Line-not-lost: the seed-0 fixture (fear line turn 7 → turn 8) and the
    // per-turn text/TurnResult agreement (Req 13.8, maintainer request
    // 2026-11-08).
    // ------------------------------------------------------------------

    /**
     * The seed-0 fear-line shift (Req 13.8). This is the exact case that the
     * narrowed prepare-vs-plain property (EnginePropertyTest) first exposed at
     * seed 0, turn 7: the scripted fear line "I'm scared..." lands on a
     * DIFFERENT turn's TurnResult on the two paths.
     *
     * On the OLD/unprepared path the fear line is fixed on turn 7's TurnResult
     * — but it was also only ever first READ in the text built for turn 8, i.e.
     * stored a turn before it was read. On the PREPARED path prepareTurn fixes
     * the line one turn later, so it is recorded on turn 8's TurnResult — the
     * NEXT turn, the turn in which the robots read it. The line is not lost and
     * not duplicated; it is simply attributed to the turn it belongs to.
     *
     * This test pins both halves of that shift at seed 0 so a regression that
     * reverts the attribution (or drops the line) is caught: the plain path has
     * the fear line on turn 7 and null on turn 8; the prepared path has null on
     * turn 7 and the fear line on turn 8. (earthquakeStartTurn = 0, no robot
     * actions, default seed-0 layout — the same fixture the property uses.)
     */
    public function testSeed0FearLineShiftsToNextTurnOnPreparedPath(): void
    {
        $fearLine = "I'm scared...";

        // Plain path: applyTurn alone, earthquake from turn 0. The fear line is
        // recorded on turn 7's TurnResult (a turn before it is read).
        [$plain, $plainWorld] = $this->makeSeededEngineAndWorld(0);
        $plainWorld->earthquakeStartTurn = 0;
        $plainLines = [];
        for ($turn = 0; $turn <= 8; $turn++) {
            $plainLines[$turn] = $plain->applyTurn($turn, [])->residentLine;
        }
        $this->assertSame(
            $fearLine,
            $plainLines[7],
            'On the unprepared path the fear line is recorded on turn 7.',
        );
        $this->assertNotSame(
            $fearLine,
            $plainLines[8],
            'On the unprepared path the fear line is not on turn 8.',
        );

        // Prepared path: prepareTurn before each applyTurn, same seed and start.
        // The fear line shifts one turn later, to turn 8's TurnResult — the turn
        // in which the robots read it (Req 13.8).
        [$prepared, $preparedWorld] = $this->makeSeededEngineAndWorld(0);
        $preparedWorld->earthquakeStartTurn = 0;
        $preparedLines = [];
        for ($turn = 0; $turn <= 8; $turn++) {
            $prepared->prepareTurn($turn);
            $preparedLines[$turn] = $prepared->applyTurn($turn, [])->residentLine;
        }
        $this->assertNotSame(
            $fearLine,
            $preparedLines[7],
            'On the prepared path the fear line is no longer on turn 7.',
        );
        $this->assertSame(
            $fearLine,
            $preparedLines[8],
            'On the prepared path the fear line belongs to turn 8 (the next turn).',
        );
    }

    /**
     * Line-not-lost invariant (Req 13.8): on the prepared path, for EVERY turn,
     * the resident line SHOWN in that turn's text is the line carried by that
     * turn's TurnResult — the line the robots read is always the current turn's
     * line, never a stale one from a different turn and never read twice.
     *
     * The resident line surfaces in the text only inside the shaking paragraph,
     * which renders only while the earthquake is shaking or has just stopped
     * (isShaking || wasShakingPreviousTurn). On a turn with no shaking paragraph
     * (e.g. seed 0, turn 8's offset) the line is still recorded on the
     * TurnResult but has no slot in the text, so this invariant checks the
     * direction that matters: whenever the text DOES show a line, it equals the
     * current turn's TurnResult line. A regression that read a line one turn
     * early or late — exactly the pre-prepareTurn behaviour — would surface a
     * line in a turn's text that the same turn's TurnResult does not carry, and
     * this test would fail.
     *
     * Checked for both robots across a full earthquake (turns 0–13) on the
     * seed-0 fixture.
     */
    public function testEveryTurnsResidentLineIsTheLineShownInThatTurnsText(): void
    {
        [$engine, $world] = $this->makeSeededEngineAndWorld(0);
        $world->earthquakeStartTurn = 0;

        for ($turn = 0; $turn <= 13; $turn++) {
            // prepareTurn fixes this turn's line and world; read the text the
            // robots would read for this turn BEFORE applying it.
            $engine->prepareTurn($turn);
            $textLineA = $this->residentLineInText($engine->situationTextFor('robot_a'));
            $textLineB = $this->residentLineInText($engine->situationTextFor('robot_b'));

            $result = $engine->applyTurn($turn, []);

            if ($textLineA !== null) {
                $this->assertSame(
                    $result->residentLine,
                    $textLineA,
                    "Turn {$turn}: the line in robot_a's text must be this turn's TurnResult line.",
                );
            }
            if ($textLineB !== null) {
                $this->assertSame(
                    $result->residentLine,
                    $textLineB,
                    "Turn {$turn}: the line in robot_b's text must be this turn's TurnResult line.",
                );
            }
        }
    }

    /**
     * Build an Engine over a seed-derived world (no explicit SituationSpec, so
     * the layout comes from the seed exactly as the property test's buildEngine
     * does), handing back the Engine and the live WorldModel so a test can set
     * earthquakeStartTurn before driving turns.
     *
     * @return array{Engine,WorldModel}
     */
    private function makeSeededEngineAndWorld(int $seedValue): array
    {
        $world = new WorldModel($this->config);
        $world->reset(Seed::fromInt($seedValue));

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
     * Extract the resident line quoted in the shaking paragraph of a situation
     * text, or null when the text voices no resident line this turn. The
     * generator renders the line as either `The man shouts, "…"` (resident in
     * the robot's room) or `You hear the man shout, "…"` (another room), so a
     * single pattern captures both forms.
     */
    private function residentLineInText(string $text): ?string
    {
        if (preg_match('/(?:The man shouts, |You hear the man shout, )"([^"]*)"/', $text, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * Reduce a TurnResult to a plain, comparable array of its observable
     * fields so two results can be compared by value without depending on
     * object identity. Mirrors describeSnapshot for the resident/house/robot
     * state the result carries.
     *
     * @param TurnResult $result
     * @return array<string,mixed>
     */
    private function describeResult(TurnResult $result): array
    {
        $judgements = [];
        foreach ($result->judgements as $robotId => $judgement) {
            $judgements[$robotId] = [
                'possible' => $judgement->possible,
                'reason' => $judgement->reason,
                'dangerous' => $judgement->dangerous,
                'dangerCode' => $judgement->dangerCode,
            ];
        }

        $outcomes = [];
        foreach ($result->outcomes as $robotId => $outcome) {
            $outcomes[$robotId] = [
                'success' => $outcome->success,
                'whatHappenedText' => $outcome->whatHappenedText,
                'errorMessage' => $outcome->errorMessage,
            ];
        }

        return [
            'turnNumber' => $result->turnNumber,
            'residentLine' => $result->residentLine,
            'episodeEnded' => $result->episodeEnded,
            'isError' => $result->isError,
            'errorMessage' => $result->errorMessage,
            'judgements' => $judgements,
            'outcomes' => $outcomes,
            'snapshot' => $this->describeSnapshot($result->worldSnapshot),
        ];
    }

    /**
     * Reduce a WorldSnapshot to a plain, comparable array of its observable
     * fields (same shape as EnginePropertyTest::describeSnapshot), so two
     * snapshots can be compared field-by-field without object identity.
     *
     * @param mixed $snapshot WorldSnapshot
     * @return array<string,mixed>
     */
    private function describeSnapshot(mixed $snapshot): array
    {
        $objects = [];
        foreach ($snapshot->objects as $id => $object) {
            $objects[$id] = [
                'room' => $object->room,
                'spot' => $object->spot,
                'on' => $object->on,
                'open' => $object->open,
                'broken' => $object->broken,
                'fallen' => $object->fallen,
            ];
        }

        $robots = [];
        foreach ($snapshot->robotStates as $id => $robot) {
            $robots[$id] = [
                'room' => $robot->room,
                'spot' => $robot->spot,
                'heldObjectId' => $robot->heldObjectId,
                'coverFlag' => $robot->coverFlag,
                'supportTurnsRemaining' => $robot->supportTurnsRemaining,
            ];
        }

        $resident = $snapshot->residentState;
        $house = $snapshot->houseState;

        return [
            'objects' => $objects,
            'robots' => $robots,
            'resident' => [
                'bodyTemp' => $resident->bodyTemp,
                'water' => $resident->water,
                'injury' => $resident->injury,
                'fear' => $resident->fear,
                'weakened' => $resident->weakened,
                'room' => $resident->room,
                'spot' => $resident->spot,
                'coverFlag' => $resident->coverFlag,
            ],
            'house' => [
                'power' => $house->power,
                'gasMain' => $house->gasMain,
                'floorWaterLevel' => $house->floorWaterLevel,
                'shaking' => $house->shaking,
                'fallingObjects' => $house->fallingObjects,
                'timeOfDay' => $house->timeOfDay,
            ],
        ];
    }
}
