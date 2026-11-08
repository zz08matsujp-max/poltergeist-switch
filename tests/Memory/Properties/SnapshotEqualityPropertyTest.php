<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Memory\Properties;

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
use PoltergeistSwitch\Memory\Values\MemoryRow;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;
use PoltergeistSwitch\TurnLoop\ReplayedAction;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\Values\AppliedRobotRow;
use PoltergeistSwitch\TurnLoop\Values\AppliedTurnRows;
use PoltergeistSwitch\TurnLoop\Values\EpisodeRow;
use PoltergeistSwitch\TurnLoop\Values\TurnStateValue;

/**
 * Feature: robot-memory, Property 4: The snapshot is the generation's active
 * memories, and every turn — live and rebuilt — uses the same block.
 *
 * For any memory store and a new episode, each robot's stored Memory_Snapshot
 * equals that robot's Active_Memory bodies of the current generation across all
 * disasters, oldest first, capped at 18 (Requirement 6.1); every turn of the
 * episode renders the same Memories_Block from that snapshot (Requirement 6.2);
 * and an {@see Engine} rebuilt by {@see ReplayEngineFactory} from the stored
 * snapshot produces turn text byte-identical to the live run for both robots
 * (Requirements 9.4, 6.6).
 *
 * The store is seeded with arbitrary memories spread across both robots, more
 * than one disaster, and more than one generation, so the "current generation
 * across all disasters, excluding retired and other-generation rows" filter and
 * the cap of 18 are both exercised. The snapshot is taken from the store the
 * way `MemoryService::snapshotForNewEpisode` takes it — the repository's
 * `activeMemories($robot, $currentGeneration, 18)` — and frozen on the episode
 * row via `storeEpisodeMemory`, which is exactly what the live loop and the
 * replay factory read back.
 *
 * The live engine walks the live path itself — for each applied turn n it calls
 * {@see Engine::prepareTurn}($n) then {@see Engine::applyTurn}($n, …), exactly
 * as TurnService::ask/apply do — with the earthquake switch flipped at a random
 * start turn, mirrored onto the episode row the same way the turn-loop
 * rehydration property mirrors it (earthquake_start_turn = E − 1, set only when
 * turn E is about to play). The replay engine is then rebuilt by the factory
 * from the stored episode (seed, earthquake start turn, applied turns, and the
 * frozen snapshot) and the two engines' per-robot situation text is compared
 * turn by turn, so the property proves the same Memories_Block rides every turn
 * and that a rebuild reproduces the live text verbatim.
 *
 * No real provider API is touched: the live engine is the pure engine and the
 * replay reads an in-memory repository. Eris drives the seed, run length, flip
 * turn, and the memory contents with limitTo(100).
 *
 * Requirements: 9.4, 6.1, 6.2, 6.6
 */
final class SnapshotEqualityPropertyTest extends TestCase
{
    use TestTrait;

    /** The snapshot cap MemoryService applies when taking a new episode's snapshot (Req 6.1). */
    private const SNAPSHOT_CAP = 18;

    /**
     * The fixed Appendix A Memories_Block heading (Req 6.3). Kept as a literal
     * here — SituationTextGenerator's constant is private — so the empty-
     * snapshot case can assert the block is absent.
     */
    private const MEMORIES_HEADING =
        'Your memories (what you wrote down after an earlier disaster in this house):';

    /**
     * Property 4: the stored snapshot is the current generation's active bodies
     * oldest first capped at 18, every turn renders the same block, and a
     * replayed engine reproduces the live per-robot text byte-for-byte.
     *
     * Validates: Requirements 9.4, 6.1, 6.2, 6.6
     */
    public function testSnapshotEqualsActiveMemoriesAndLiveAndReplayRenderTheSameBlock(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generators::int(),
                // The number of turns played AFTER the quiet turn 0 (1..12).
                Generators::choose(1, 12),
                // The expected turn E at which the earthquake is flipped.
                Generators::choose(1, 12),
                // How many memories to seed, large enough to push past the
                // per-triple cap of 6 and (across disasters) the snapshot cap
                // of 18 in some cases.
                Generators::choose(0, 26),
            )
            ->then(function (int $seedValue, int $playedTurns, int $flipAt, int $memoryCount): void {
                $flipTurn = min($flipAt, $playedTurns);

                // --- Seed an arbitrary memory store -------------------------
                // Memories span both robots, two disasters, and two
                // generations; only the current generation's active rows, over
                // all disasters, belong in a new episode's snapshot.
                $repo = new InMemoryEpisodeRepository();
                $currentGeneration = $repo->currentGeneration();
                $this->seedMemories($repo, $currentGeneration, $seedValue, $memoryCount);

                // --- The snapshot MemoryService would freeze on the episode --
                // Exactly activeMemories($robot, currentGeneration, 18): the
                // current generation's active bodies across all disasters,
                // oldest first, capped at 18 (Req 6.1).
                $snapshotA = $repo->activeMemories('robot_a', $currentGeneration, self::SNAPSHOT_CAP);
                $snapshotB = $repo->activeMemories('robot_b', $currentGeneration, self::SNAPSHOT_CAP);

                // The snapshot equals the current generation's active bodies,
                // oldest first, capped at 18 — assert the shape the property
                // claims rather than trusting the reader blindly (Req 6.1).
                $this->assertSame(
                    $this->expectedActiveBodies($repo, 'robot_a', $currentGeneration),
                    $snapshotA->bodies,
                    "robot_a snapshot must be its current-generation active bodies oldest first capped at 18 "
                        . "(seed {$seedValue}, memoryCount {$memoryCount})"
                );
                $this->assertSame(
                    $this->expectedActiveBodies($repo, 'robot_b', $currentGeneration),
                    $snapshotB->bodies,
                    "robot_b snapshot must be its current-generation active bodies oldest first capped at 18 "
                        . "(seed {$seedValue}, memoryCount {$memoryCount})"
                );
                $this->assertLessThanOrEqual(self::SNAPSHOT_CAP, count($snapshotA->bodies));
                $this->assertLessThanOrEqual(self::SNAPSHOT_CAP, count($snapshotB->bodies));

                // --- Create the episode and freeze the snapshot on it -------
                $now = date('Y-m-d H:i:s');
                $episodeId = $repo->createEpisode(new EpisodeRow(
                    id: 'ep_snapshot',
                    deviceId: 'device_snapshot',
                    disaster: 'earthquake',
                    seed: $seedValue,
                    currentExpectedTurn: $playedTurns + 1,
                    earthquakeStartTurn: $flipTurn - 1,
                    ended: false,
                    createdAt: $now,
                    lastActivityAt: $now,
                ));
                // MemoryService::snapshotForNewEpisode freezes the two ordered
                // body lists and the generation on the episode (Req 6.1); the
                // live loop and the replay factory read them straight back.
                $repo->storeEpisodeMemory($episodeId, $currentGeneration, $snapshotA, $snapshotB);

                // The stored snapshot read back equals what we froze (Req 6.6).
                $this->assertSame($snapshotA->bodies, $repo->episodeSnapshot($episodeId, 'robot_a')->bodies);
                $this->assertSame($snapshotB->bodies, $repo->episodeSnapshot($episodeId, 'robot_b')->bodies);

                // --- Play the episode live with the frozen memories ---------
                $liveEngine = $this->buildEngine(Seed::fromInt($seedValue), $liveWorld);
                $liveEngine->setMemories([
                    'robot_a' => $snapshotA->bodies,
                    'robot_b' => $snapshotB->bodies,
                ]);

                // The expected Memories_Block per robot — the exact Appendix A
                // block the generator builds from the snapshot (Req 6.2). Empty
                // snapshot → null (no block).
                $textGen = new SituationTextGenerator();
                $expectedBlockA = $textGen->buildMemoriesBlock($snapshotA->bodies);
                $expectedBlockB = $textGen->buildMemoriesBlock($snapshotB->bodies);

                $ended = false;
                $lastApplied = -1;

                for ($turn = 0; $turn <= $playedTurns; $turn++) {
                    if ($turn === $flipTurn && $liveWorld->earthquakeStartTurn === null) {
                        $liveWorld->earthquakeStartTurn = $flipTurn - 1;
                    }

                    $actions = $this->actionsForTurn($turn, $seedValue);

                    $liveEngine->prepareTurn($turn);

                    // Every turn of the episode renders the same Memories_Block
                    // for each robot (Req 6.2): assert it is present in the
                    // turn text (or absent for an empty snapshot) before the
                    // turn is applied.
                    $this->assertBlockInText($expectedBlockA, $liveEngine->situationTextFor('robot_a'), 'robot_a', $turn);
                    $this->assertBlockInText($expectedBlockB, $liveEngine->situationTextFor('robot_b'), 'robot_b', $turn);

                    $result = $liveEngine->applyTurn($turn, $actions);
                    $this->assertFalse(
                        $result->isError,
                        "live applyTurn({$turn}) must succeed (seed {$seedValue}): " . ($result->errorMessage ?? '')
                    );

                    $repo->createTurn($episodeId, $turn);
                    $repo->storeAppliedTurn(new AppliedTurnRows(
                        episodeId: $episodeId,
                        turnNumber: $turn,
                        robots: $this->robotRowsFor($actions),
                        residentLine: $result->residentLine,
                    ));
                    $repo->setTurnState($episodeId, $turn, TurnStateValue::applied->value);

                    $lastApplied = $turn;

                    if ($result->episodeEnded) {
                        $ended = true;
                        break;
                    }
                }

                $nextTurn = $lastApplied + 1;

                if ($ended) {
                    $repo->markEpisodeEnded($episodeId);
                }

                // --- Rebuild the engine by replay from the stored episode ---
                // ReplayEngineFactory::freshReplayState reads the frozen
                // snapshot and calls Engine::setMemories before any turn text,
                // so the rebuild uses the exact verbatim bodies (Req 6.6).
                $replayEngine = (new ReplayEngineFactory($repo, new EpisodeConfig()))
                    ->forEpisode($episodeId);

                // Same per-robot situation text for the next expected turn:
                // the frozen Memories_Block rides the rebuilt text exactly as
                // the live one (Req 9.4, 6.6). Skip when the episode ended.
                if (!$ended) {
                    $liveEngine->prepareTurn($nextTurn);
                    $replayEngine->prepareTurn($nextTurn);

                    foreach (['robot_a', 'robot_b'] as $robotId) {
                        $this->assertSame(
                            $liveEngine->situationTextFor($robotId),
                            $replayEngine->situationTextFor($robotId),
                            "replayed situation text for {$robotId} must match the live text at "
                                . "turn {$nextTurn} with the frozen memories (seed {$seedValue}, "
                                . "memoryCount {$memoryCount})"
                        );
                    }

                    // The replayed block is the same frozen block.
                    $this->assertBlockInText($expectedBlockA, $replayEngine->situationTextFor('robot_a'), 'robot_a', $nextTurn);
                    $this->assertBlockInText($expectedBlockB, $replayEngine->situationTextFor('robot_b'), 'robot_b', $nextTurn);
                }
            });
    }

    /**
     * The bodies a snapshot should contain for one robot: the current
     * generation's active rows across all disasters, oldest first by finish
     * order, capped at 18 (the newest 18 kept, still oldest first). Computed
     * directly from the seeding plan so the assertion does not merely echo the
     * reader under test.
     *
     * @return string[]
     */
    private function expectedActiveBodies(InMemoryEpisodeRepository $repo, string $robotId, int $generation): array
    {
        // Rebuild the expected set from the same reader the service uses but
        // with an uncapped read, then apply the cap here, so a bug in the cap
        // on the service path (which uses 18) is still catchable: the two reads
        // use different caps and must agree after this slice.
        $uncapped = $repo->activeMemories($robotId, $generation, PHP_INT_MAX)->bodies;
        if (count($uncapped) > self::SNAPSHOT_CAP) {
            $uncapped = array_slice($uncapped, count($uncapped) - self::SNAPSHOT_CAP);
        }

        return $uncapped;
    }

    /**
     * Assert the robot's turn text carries exactly the expected Memories_Block:
     * present (and verbatim) when the robot has memories, absent when it has
     * none (the empty-snapshot case renders no block, Req 6.5).
     */
    private function assertBlockInText(?string $expectedBlock, string $text, string $robotId, int $turn): void
    {
        if ($expectedBlock === null) {
            $this->assertStringNotContainsString(
                self::MEMORIES_HEADING,
                $text,
                "an empty snapshot must render no Memories_Block for {$robotId} on turn {$turn}"
            );

            return;
        }

        $this->assertStringContainsString(
            $expectedBlock,
            $text,
            "the Memories_Block must appear verbatim in {$robotId}'s text on turn {$turn}"
        );
    }

    /**
     * Seed the store with up to $memoryCount memories spread across both
     * robots, two disasters, and two generations, so the snapshot's filter
     * (current generation, all disasters, exclude retired and other
     * generations) and the cap of 18 are exercised. Appends go through
     * appendMemory so the store's own cap/retirement runs, which is the state
     * the snapshot reads. Bodies carry a unique index so order and verbatim
     * content are both checkable.
     */
    private function seedMemories(
        InMemoryEpisodeRepository $repo,
        int $currentGeneration,
        int $seedValue,
        int $memoryCount
    ): void {
        $robots = ['robot_a', 'robot_b'];
        $disasters = ['earthquake', 'heavy_rain'];

        for ($i = 0; $i < $memoryCount; $i++) {
            // Spread deterministically from the drawn values so Eris can shrink
            // and replay the same plan.
            $robot = $robots[($seedValue + $i) % 2 === 0 ? 0 : 1];
            $disaster = $disasters[($seedValue + $i * 3) % 2 === 0 ? 0 : 1];
            // Most memories go to the current generation; some to an older one
            // (currentGeneration - 1) so the other-generation exclusion bites.
            $generation = (($seedValue + $i) % 4 === 0)
                ? $currentGeneration - 1
                : $currentGeneration;

            $repo->appendMemory(new MemoryRow(
                robotId: $robot,
                generation: $generation,
                disaster: $disaster,
                sourceEpisodeId: 'src_' . $i,
                body: "Memory {$i} for {$robot} ({$disaster}, gen {$generation}).",
                status: 'active',
                createdAt: date('Y-m-d H:i:s'),
            ));
        }
    }

    /**
     * Deterministic, house-changing actions for a turn (turn 0 is quiet). The
     * same ($turn, $seedValue) always yield the same actions so the live
     * applyTurn and the stored action_json carry the identical Action and the
     * replay reproduces the live world and text.
     *
     * @return array<string, Action>
     */
    private function actionsForTurn(int $turn, int $seedValue): array
    {
        if ($turn === 0) {
            return [];
        }

        $menu = $this->actionMenu();
        $size = count($menu);
        $base = abs($seedValue % $size);
        $indexA = ($base + $turn) % $size;
        $indexB = ($base + $turn + 3) % $size;

        return [
            'robot_a' => $this->bindAction($menu[$indexA], 'robot_a'),
            'robot_b' => $this->bindAction($menu[$indexB], 'robot_b'),
        ];
    }

    /**
     * A menu of candidate actions, mirroring the rehydration property's menu so
     * the replay is exercised with turns that actually move robots, take and
     * use objects, cover and support the resident. Odd/impossible combinations
     * are intentional: the replay must reproduce the live world regardless.
     *
     * @return list<Action>
     */
    private function actionMenu(): array
    {
        return [
            new Action(robotId: 'robot', operation: Operation::move->value, targetId: 'kitchen', actionQuote: 'I walk to the kitchen.'),
            new Action(robotId: 'robot', operation: Operation::move->value, targetId: 'living_room', actionQuote: 'I go to the living room.'),
            new Action(robotId: 'robot', operation: Operation::move->value, targetSpot: 'near_resident', actionQuote: 'I step near the old man.'),
            new Action(robotId: 'robot', operation: Operation::move->value, targetSpot: 'window', actionQuote: 'I move to the window.'),
            new Action(robotId: 'robot', operation: Operation::take->value, targetId: 'flashlight', actionQuote: 'I pick up the flashlight.'),
            new Action(robotId: 'robot', operation: Operation::use->value, targetId: 'flashlight', actionQuote: 'I switch on the flashlight.'),
            new Action(robotId: 'robot', operation: Operation::use->value, targetId: 'gas_stove', actionQuote: 'I turn off the gas stove.'),
            new Action(robotId: 'robot', operation: Operation::cover->value, targetId: 'resident', actionQuote: 'I shield the old man.'),
            new Action(robotId: 'robot', operation: Operation::cover->value, actionQuote: 'I crouch and cover myself.'),
            new Action(
                robotId: 'robot',
                operation: Operation::support->value,
                targetId: 'resident',
                targetSpot: 'under_table',
                words: ['Let us get you under the table.'],
                actionQuote: 'I help the old man under the table and say, "Let us get you under the table."',
            ),
            new Action(
                robotId: 'robot',
                operation: Operation::support->value,
                targetId: 'resident',
                targetSpot: 'living_room',
                actionQuote: 'I help the old man toward the living room.',
            ),
            new Action(robotId: 'robot', operation: Operation::wait->value, actionQuote: 'I wait.'),
            new Action(
                robotId: 'robot',
                operation: Operation::speak->value,
                words: ['Are you all right?'],
                actionQuote: 'I ask, "Are you all right?"',
            ),
        ];
    }

    /**
     * Rebind a template menu Action (keyed to 'robot') to a concrete robot id,
     * preserving every other field so the live and replay paths see the same
     * Action.
     */
    private function bindAction(Action $template, string $robotId): Action
    {
        return new Action(
            robotId: $robotId,
            operation: $template->operation,
            targetId: $template->targetId,
            targetSpot: $template->targetSpot,
            words: $template->words,
            actionQuote: $template->actionQuote,
            reported: $template->reported,
        );
    }

    /**
     * Build the per-robot AppliedRobotRow map for a turn, carrying each action's
     * action_json so the factory decodes the exact Action back on replay. Only
     * the action_json matters for replay; the other layer fields are harmless
     * placeholders. A turn with no actions (turn 0) yields an empty map.
     *
     * @param array<string, Action> $actions
     * @return array<string, AppliedRobotRow>
     */
    private function robotRowsFor(array $actions): array
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
}
