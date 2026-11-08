<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\ActionExecutor;
use PoltergeistSwitch\Engine\EarthquakePhase;
use PoltergeistSwitch\Engine\Engine;
use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\ResidentController;
use PoltergeistSwitch\Engine\RulesEvaluator;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\WorldModel;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;
use PoltergeistSwitch\TurnLoop\ReplayedAction;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\Values\AppliedRobotRow;
use PoltergeistSwitch\TurnLoop\Values\AppliedTurnRows;
use PoltergeistSwitch\TurnLoop\Values\EpisodeRow;
use PoltergeistSwitch\TurnLoop\Values\TurnStateValue;

/**
 * Feature: turn-loop, Property 16: Rehydration by replay.
 *
 * For any sequence of applied turns, an {@see Engine} rebuilt by
 * {@see ReplayEngineFactory} from the stored seed, earthquake start turn, and
 * per-turn `action_json` holds the SAME world as the Engine that played those
 * turns live: the same world snapshot AND the same {@see Engine::situationTextFor}
 * output for both robots (Requirements 13.1, 13.2, 13.4, 14.9).
 *
 * The live engine the replay is compared against walks the live path itself —
 * for each turn n it calls {@see Engine::prepareTurn}($n) and then
 * {@see Engine::applyTurn}($n, …), exactly as TurnService::ask/apply do — and the
 * earthquake switch is flipped at a RANDOM start turn, covering both an
 * immediate flip (first played turn is offset 1) and a later flip. So the
 * property proves the replay reproduces the live resident line and the
 * late-flip timing, not merely a bare applyTurn sequence.
 *
 * The flip is mirrored exactly as the live loop and ReplayEngineFactory mirror
 * it (Requirement 4.4): the observer flips when the current expected turn is
 * earthquake_start_turn + 1, so on the live engine the world's
 * earthquakeStartTurn is set to (E − 1) right before turn E is played, where E
 * is the randomly chosen flip turn; the factory stores earthquake_start_turn =
 * E − 1 and sets it only when it is about to replay turn E. A turn before E is
 * quiet on both sides.
 *
 * The comparison prepares the next expected turn on both engines before reading
 * their text, because situationTextFor renders the prepared turn's earthquake
 * step and resident line; preparing both to the same turn is what makes the
 * text comparison meaningful (and the first post-flip text open the shaking
 * paragraph on both).
 *
 * No real provider API is touched: the live engine is the pure engine and the
 * replay reads an in-memory repository (Requirement 14.1). Eris drives the
 * seed, run length, and flip turn with limitTo(100).
 *
 * Requirements: 14.9, 12.1, 13.1, 13.2, 13.4
 */
final class RehydrationPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property 16: a replayed engine holds the same world and the same per-robot
     * situation text as the live-played engine.
     *
     * Validates: Requirements 14.9, 13.1, 13.2, 13.4
     */
    public function testReplayReproducesTheLiveWorldAndText(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generators::int(),
                // The number of turns played AFTER the quiet turn 0 (1..12),
                // so the total applied turns are 0..$playedTurns.
                Generators::choose(1, 12),
                // The expected turn E at which the earthquake is flipped, drawn
                // relative to the run so it always falls within it. E = 1 is the
                // immediate flip (first played turn is offset 1); a larger E is a
                // later flip. Clamped to the run length inside the body.
                Generators::choose(1, 12),
            )
            ->then(function (int $seedValue, int $playedTurns, int $flipAt): void {
                // Keep the flip turn within the played run so the late-flip case
                // actually starts the earthquake during the replayed turns.
                $flipTurn = min($flipAt, $playedTurns);

                // --- Play the episode live on a directly-built engine --------
                // The live engine walks the live path: prepareTurn($n) then
                // applyTurn($n, …) for every turn, including the quiet turn 0.
                $liveEngine = $this->buildEngine(Seed::fromInt($seedValue), $liveWorld);

                // Build the in-memory repository the factory will replay from.
                $repo = new InMemoryEpisodeRepository();
                $now = date('Y-m-d H:i:s');
                $episodeId = $repo->createEpisode(new EpisodeRow(
                    id: 'ep_replay',
                    deviceId: 'device_replay',
                    disaster: 'earthquake',
                    seed: $seedValue,
                    // The current expected turn after the whole live run is
                    // $playedTurns + 1 (the next, not-yet-applied turn).
                    currentExpectedTurn: $playedTurns + 1,
                    // Mirror the live mapping: earthquake_start_turn = E − 1.
                    earthquakeStartTurn: $flipTurn - 1,
                    ended: false,
                    createdAt: $now,
                    lastActivityAt: $now,
                ));

                $ended = false;
                $lastApplied = -1;

                for ($turn = 0; $turn <= $playedTurns; $turn++) {
                    // Flip the earthquake at the live moment: the observer flips
                    // when the current expected turn is E, setting
                    // earthquakeStartTurn = E − 1, so the world starts shaking
                    // from the turn-E prompt. We set it right before playing
                    // turn E (i.e. after turn E − 1 has been applied).
                    if ($turn === $flipTurn && $liveWorld->earthquakeStartTurn === null) {
                        $liveWorld->earthquakeStartTurn = $flipTurn - 1;
                    }

                    $actions = $this->actionsForTurn($turn, $seedValue);

                    $liveEngine->prepareTurn($turn);
                    $result = $liveEngine->applyTurn($turn, $actions);
                    $this->assertFalse(
                        $result->isError,
                        "live applyTurn({$turn}) must succeed (seed {$seedValue}, "
                            . "playedTurns {$playedTurns}, flipTurn {$flipTurn}): "
                            . ($result->errorMessage ?? '')
                    );

                    // Store the applied turn so the factory can replay it. The
                    // quiet turn 0 is stored with no robot rows (empty actions),
                    // exactly as createEpisode does live.
                    $repo->createTurn($episodeId, $turn);
                    $repo->storeAppliedTurn(new AppliedTurnRows(
                        episodeId: $episodeId,
                        turnNumber: $turn,
                        robots: $this->robotRowsFor($turn, $actions),
                        residentLine: $result->residentLine,
                    ));
                    $repo->setTurnState($episodeId, $turn, TurnStateValue::applied->value);

                    $lastApplied = $turn;

                    if ($result->episodeEnded) {
                        $ended = true;
                        break;
                    }
                }

                // The next expected turn after the live run — the turn whose
                // text both engines will render for the comparison. When the
                // episode ended early there is no further turn to prepare, so
                // the comparison stops at the applied snapshot.
                $nextTurn = $lastApplied + 1;

                if ($ended) {
                    // Record the early end so the factory's replay ends too.
                    $repo->markEpisodeEnded($episodeId);
                }

                // --- Rebuild the engine by replay ---------------------------
                $replayEngine = (new ReplayEngineFactory($repo, new EpisodeConfig()))
                    ->forEpisode($episodeId);
                $replayWorld = (new ReplayEngineFactory($repo, new EpisodeConfig()))
                    ->worldForEpisode($episodeId);

                // 1. Same world snapshot after replaying every applied turn
                //    (Requirement 13.4). Compare the replay-built world against
                //    the live engine's world via a plain, comparable projection.
                $this->assertEquals(
                    $this->describeWorld($liveWorld),
                    $this->describeWorld($replayWorld),
                    "replayed world must match the live world after turns 0..{$lastApplied} "
                        . "(seed {$seedValue}, playedTurns {$playedTurns}, flipTurn {$flipTurn})"
                );

                // 2. Same per-robot situation text for the next expected turn
                //    (Requirement 14.9). Prepare the same turn on both engines so
                //    each renders that turn's earthquake step and resident line,
                //    then compare the text robot by robot. Skip when the episode
                //    ended, since there is no further turn to prepare.
                if (!$ended) {
                    $liveEngine->prepareTurn($nextTurn);
                    $replayEngine->prepareTurn($nextTurn);

                    foreach (['robot_a', 'robot_b'] as $robotId) {
                        $this->assertSame(
                            $liveEngine->situationTextFor($robotId),
                            $replayEngine->situationTextFor($robotId),
                            "replayed situation text for {$robotId} must match the live text at "
                                . "turn {$nextTurn} (seed {$seedValue}, playedTurns {$playedTurns}, "
                                . "flipTurn {$flipTurn})"
                        );
                    }

                    // The situation-text version is the same on both engines, so
                    // a stored prompt replays with the same version.
                    $this->assertSame(
                        $liveEngine->situationTextVersion(),
                        $replayEngine->situationTextVersion(),
                        "replayed situation-text version must match the live one "
                            . "(seed {$seedValue})"
                    );
                }
            });
    }

    /**
     * Deterministic, always-valid actions for a turn, so each case is
     * reproducible from the seed and the live/replay paths pass the identical
     * actions. The quiet turn 0 carries no actions (the engine's createEpisode
     * turn). Later turns alternate between a lone wait and a wait + a spoken
     * line; both operations are valid for any layout and never block, so the
     * turn is processed the same way on both the live and replay paths.
     *
     * @return array<string, Action>
     */
    private function actionsForTurn(int $turn, int $seedValue): array
    {
        if ($turn === 0) {
            return [];
        }

        if ((($seedValue + $turn) % 2) === 0) {
            return [
                'robot_a' => new Action(
                    robotId: 'robot_a',
                    operation: Operation::wait->value,
                    actionQuote: 'I wait.',
                ),
            ];
        }

        return [
            'robot_a' => new Action(
                robotId: 'robot_a',
                operation: Operation::wait->value,
                actionQuote: 'I wait.',
            ),
            'robot_b' => new Action(
                robotId: 'robot_b',
                operation: Operation::speak->value,
                words: ['Hello.'],
                actionQuote: 'I say hello.',
            ),
        ];
    }

    /**
     * Build the per-robot AppliedRobotRow map for a turn, carrying each action's
     * action_json so the factory decodes the exact Action back on replay
     * (Requirements 13.2, 13.3). Only the action_json matters for replay; the
     * other layer fields are filled with harmless placeholders. A turn with no
     * actions (turn 0) yields an empty map.
     *
     * @param array<string, Action> $actions
     * @return array<string, AppliedRobotRow>
     */
    private function robotRowsFor(int $turn, array $actions): array
    {
        $rows = [];
        foreach ($actions as $robotId => $action) {
            $rows[$robotId] = new AppliedRobotRow(
                robotId: $robotId,
                situationText: '',
                situationTextVersion: 'v0',
                recorderJson: '{}',
                recorderUnknown: false,
                recorderPromptVersion: 'v0',
                judgementJson: '{}',
                outcomeJson: '{}',
                actionJson: ReplayedAction::toJson($action),
            );
        }

        return $rows;
    }

    /**
     * Construct a fully wired Engine with its WorldModel reset to $seed,
     * mirroring how ReplayEngineFactory assembles the engine. All collaborators
     * are no-argument plain classes.
     */
    private function buildEngine(Seed $seed, ?WorldModel &$world = null): Engine
    {
        $config = new EpisodeConfig();

        $world = new WorldModel($config);
        $world->reset($seed);

        return new Engine(
            $world,
            $config,
            new RulesEvaluator(),
            new ActionExecutor(),
            new EarthquakePhase(),
            new ResidentController(),
            new SituationTextGenerator(),
        );
    }

    /**
     * Reduce a WorldModel's observable state to a plain, comparable array via
     * its snapshot, plus the earthquake start turn the world carries. Turning
     * the cloned objects into scalars lets assertEquals compare two worlds
     * field by field without depending on object identity. Mirrors the
     * describeSnapshot helper used in the engine property tests.
     *
     * @return array<string, mixed>
     */
    private function describeWorld(WorldModel $world): array
    {
        $snapshot = $world->snapshot();

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
            'earthquakeStartTurn' => $world->earthquakeStartTurn,
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
