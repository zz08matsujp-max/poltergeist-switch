<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\Engine;
use PoltergeistSwitch\Engine\WorldModel;
use PoltergeistSwitch\TurnLoop\Clients\FakeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\EngineFactory;
use PoltergeistSwitch\TurnLoop\Http\ApiError;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\Repository\EpisodeRepository;
use PoltergeistSwitch\TurnLoop\TurnService;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;
use PoltergeistSwitch\TurnLoop\Values\AppliedTurnRows;
use PoltergeistSwitch\TurnLoop\Values\EpisodeRow;
use PoltergeistSwitch\TurnLoop\Values\EpisodeView;
use PoltergeistSwitch\TurnLoop\Values\PromptReplyRow;
use PoltergeistSwitch\TurnLoop\Values\ReplyRecord;
use PoltergeistSwitch\TurnLoop\Values\TurnRow;
use PoltergeistSwitch\TurnLoop\Values\TurnView;
use RuntimeException;
use Throwable;

/**
 * Property-based tests for the one-time per-turn World_State backfill (Eris).
 *
 * The backfill is {@see \PoltergeistSwitch\TurnLoop\WorldStateBackfill},
 * invoked from {@see TurnService::getEpisode} (the episode-view read path). It
 * fills a pre-existing episode's missing per-turn `world_json` once, by
 * replaying the engine through {@see EngineFactory::worldsPerTurnForEpisode}
 * (never AI), and persists through the repository's
 * {@see EpisodeRepository::fillMissingWorldJson} /
 * {@see EpisodeRepository::loadTurnWorldJson}.
 *
 * This Eris release (giorgiosironi/eris ^1.3) controls the number of
 * iterations with `limitTo()`, not `withMinSuccesses()`.
 *
 * Feature: screens, Property 2: Backfill fills only missing turns, exactly once
 * Feature: screens, Property 3: Backfill failure persists nothing
 */
class WorldStateBackfillPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Feature: screens, Property 2: Backfill fills only missing turns, exactly
     * once.
     *
     * Build an ended episode whose every turn already holds the correct
     * `world_json` (a faithful oracle: the worlds a fresh run / replay stores).
     * Then mark a random subset of turns as missing (their stored world nulled,
     * as a pre-spec episode's turns would be). Perform ONE episode-view read
     * (TurnService::getEpisode, which triggers the backfill) and assert:
     *
     *   - every turn that was missing is now filled (non-null) and equals what
     *     a fresh replay would have stored (the captured oracle), byte-for-byte
     *     (Requirement 10.7);
     *   - every turn that already had a world is left byte-for-byte unchanged
     *     (Requirement 10.7);
     *   - the replay ran EXACTLY ONCE when at least one turn was missing, and
     *     NOT AT ALL when nothing was missing — spied on the engine factory's
     *     backfill entry point `worldsPerTurnForEpisode` (the one-time replay
     *     the backfill relies on).
     *
     * The random subset and the turn count both come from the Eris seed, so the
     * case is reproducible.
     *
     * Validates: Requirements 10.7
     */
    public function testBackfillFillsOnlyMissingTurnsExactlyOnce(): void
    {
        $this->limitTo(100)
            ->forAll(Generators::int(), Generators::choose(1, 5), Generators::int())
            ->then(function (int $seed, int $turnCount, int $subsetSeed): void {
                $inner = new InMemoryEpisodeRepository();

                // Build an ended episode with turnCount played turns, so every
                // turn already holds the correct world (the oracle).
                $episodeId = $this->buildEndedEpisode($inner, $seed, $turnCount);

                // The oracle: the correct stored world_json per turn, exactly
                // as a fresh run / replay stored it.
                $oracle = $inner->loadTurnWorldJson($episodeId);
                $this->assertNotEmpty($oracle, "the episode must have stored turns (seed {$seed})");

                // Guard: every turn starts non-null (nothing is missing yet).
                foreach ($oracle as $turnNumber => $worldJson) {
                    $this->assertNotNull(
                        $worldJson,
                        "turn {$turnNumber} should start with a stored world (seed {$seed})"
                    );
                }

                // Choose a random subset of turns to treat as missing, driven
                // by the Eris seed so the case is reproducible.
                $missing = $this->chooseMissingTurns(array_keys($oracle), $subsetSeed);

                // Layer the "missing on disk" view over the real inner store so
                // the inner store stays the oracle for every turn, while the
                // decorator reports (and later fills) only the chosen turns.
                $repo = new WorldJsonOverrideRepository($inner);
                foreach ($missing as $turnNumber) {
                    $repo->markMissing($turnNumber);
                }

                // Spy on the backfill replay: count how many times the engine
                // factory's one-time backfill entry point is called.
                $factory = new BackfillSpyEngineFactory(new ReplayEngineFactory($inner));

                $service = new TurnService(
                    new FakeRobotClient(),
                    new FakeRobotClient(),
                    new FakeRecorderClient(),
                    $factory,
                    $repo,
                    new Config(),
                );

                // ONE read of the episode view: this is the read path that
                // triggers the backfill.
                $view = $service->getEpisode($episodeId);

                // The replay ran exactly once when something was missing, and
                // not at all when nothing was missing.
                $expectedReplays = $missing === [] ? 0 : 1;
                $this->assertSame(
                    $expectedReplays,
                    $factory->worldsPerTurnCount,
                    "the backfill replay must run {$expectedReplays} time(s) "
                    . 'for a single read (seed ' . $seed . ', missing '
                    . implode(',', $missing) . ')'
                );

                $afterBackfill = $repo->loadTurnWorldJson($episodeId);

                foreach ($oracle as $turnNumber => $originalWorld) {
                    if (in_array($turnNumber, $missing, true)) {
                        // A missing turn is now filled and equals the oracle.
                        $this->assertNotNull(
                            $afterBackfill[$turnNumber],
                            "missing turn {$turnNumber} must be filled (seed {$seed})"
                        );
                        $this->assertSame(
                            $originalWorld,
                            $afterBackfill[$turnNumber],
                            "filled turn {$turnNumber} must equal a fresh replay's "
                            . "world (seed {$seed})"
                        );
                    } else {
                        // A present turn is left byte-for-byte unchanged.
                        $this->assertSame(
                            $originalWorld,
                            $afterBackfill[$turnNumber],
                            "present turn {$turnNumber} must be unchanged (seed {$seed})"
                        );
                    }
                }

                // The view the read returned exposes a world for every turn now
                // (the backfill is complete).
                foreach ($view->turns as $turnView) {
                    $this->assertNotNull(
                        $turnView->world,
                        "turn {$turnView->turnNumber} view must carry a world after "
                        . "backfill (seed {$seed})"
                    );
                }
            });
    }

    /**
     * Feature: screens, Property 3: Backfill failure persists nothing.
     *
     * Build the same ended episode and mark a random, non-empty subset of turns
     * missing, then inject a replay that fails (the engine factory's backfill
     * entry point throws after walking a random number of turns). Perform ONE
     * episode-view read and assert:
     *
     *   - a backfill-failed error is returned — the {@see ApiError} with the
     *     'world_backfill_failed' / HTTP 500 contract (Requirement 10.8);
     *   - no stored `world_json` value changed: the still-missing turns stay
     *     null and the present turns stay byte-for-byte unchanged, so nothing
     *     partial was persisted (Requirement 10.8).
     *
     * Validates: Requirements 10.8
     */
    public function testBackfillFailurePersistsNothing(): void
    {
        $this->limitTo(100)
            ->forAll(Generators::int(), Generators::choose(1, 5), Generators::int(), Generators::int())
            ->then(function (int $seed, int $turnCount, int $subsetSeed, int $failSeed): void {
                $inner = new InMemoryEpisodeRepository();
                $episodeId = $this->buildEndedEpisode($inner, $seed, $turnCount);

                $oracle = $inner->loadTurnWorldJson($episodeId);
                $turnNumbers = array_keys($oracle);

                // A non-empty subset so the backfill actually tries to replay;
                // forcing at least one missing turn makes the failure reachable.
                $missing = $this->chooseMissingTurns($turnNumbers, $subsetSeed);
                if ($missing === []) {
                    $missing = [$turnNumbers[0]];
                }

                $repo = new WorldJsonOverrideRepository($inner);
                foreach ($missing as $turnNumber) {
                    $repo->markMissing($turnNumber);
                }

                // Snapshot of the stored worlds BEFORE the read, as the
                // decorator reports them (missing turns null, others present).
                $before = $repo->loadTurnWorldJson($episodeId);

                // Inject a replay that fails at a random turn. failAtTurn is a
                // 0-based count of successful serialisations before the throw,
                // bounded to 0..turnCount-1 so the replay ALWAYS throws before
                // completing — the failure can land before the first turn,
                // partway through, or at the last turn.
                $failAtTurn = abs($failSeed) % max(1, count($turnNumbers));
                $factory = new ThrowingReplayEngineFactory(
                    new ReplayEngineFactory($inner),
                    $failAtTurn
                );

                $service = new TurnService(
                    new FakeRobotClient(),
                    new FakeRobotClient(),
                    new FakeRecorderClient(),
                    $factory,
                    $repo,
                    new Config(),
                );

                $threw = false;
                try {
                    $service->getEpisode($episodeId);
                } catch (ApiError $e) {
                    $threw = true;
                    // The backfill-failed contract (Requirement 10.8).
                    $this->assertSame(500, $e->status, "status must be 500 (seed {$seed})");
                    $this->assertSame(
                        'world_backfill_failed',
                        $e->errorCode,
                        "error code must be world_backfill_failed (seed {$seed})"
                    );
                }

                $this->assertTrue(
                    $threw,
                    "a failing replay must surface a backfill-failed ApiError (seed {$seed})"
                );

                // Nothing partial was persisted: every stored value is exactly
                // what it was before the read (missing turns still null).
                $after = $repo->loadTurnWorldJson($episodeId);
                $this->assertSame(
                    $before,
                    $after,
                    "no stored world_json may change on a failed backfill (seed {$seed})"
                );

                // And the decorator recorded no fill at all.
                $this->assertSame(
                    0,
                    $repo->fillCallCount,
                    "a failed replay must persist no fill (seed {$seed})"
                );
            });
    }

    // --- helpers ----------------------------------------------------------

    /**
     * Build an ended episode with a quiet turn 0 plus $turnCount played turns,
     * all stored with their correct per-turn world_json, then mark it ended.
     * Returns the episode id. Uses real TurnService over the in-memory repo and
     * fake clients (no network, no AI), so the stored worlds are the faithful
     * oracle a fresh replay reproduces.
     */
    private function buildEndedEpisode(InMemoryEpisodeRepository $inner, int $seed, int $turnCount): string
    {
        $service = new TurnService(
            new FakeRobotClient(new ReplyRecord('', 'I wait.', 1, 1, 0.0, 0.0, false, false)),
            new FakeRobotClient(new ReplyRecord('', 'I wait.', 1, 1, 0.0, 0.0, false, false)),
            new FakeRecorderClient(),
            new ReplayEngineFactory($inner),
            $inner,
            new Config(),
        );

        // A fixed, non-negative seed keeps the world reproducible; createEpisode
        // plays the quiet turn 0 and leaves the expected turn at 1.
        $created = $service->createEpisode('dev_' . $seed, seed: abs($seed));
        $episodeId = $created->episodeId;
        $service->flipEarthquake($episodeId);

        // Play $turnCount turns (turns 1..turnCount), each ask then apply, until
        // the episode ends or the count is reached.
        for ($turn = 1; $turn <= $turnCount; $turn++) {
            $episode = $inner->findEpisode($episodeId);
            if ($episode === null || $episode->ended) {
                break;
            }
            $service->ask($episodeId, $turn);
            $service->apply($episodeId, $turn);
        }

        // Ensure the episode is ended (the backfill contract is about reading an
        // ended, pre-existing episode). markEpisodeEnded is idempotent.
        $inner->markEpisodeEnded($episodeId);

        return $episodeId;
    }

    /**
     * Deterministically pick a subset of the given turn numbers to treat as
     * missing a world, driven by $subsetSeed. Each turn is independently
     * included with a 50% chance, so the subset ranges from empty to all turns.
     *
     * @param int[] $turnNumbers
     * @return int[] the chosen turn numbers, ascending
     */
    private function chooseMissingTurns(array $turnNumbers, int $subsetSeed): array
    {
        sort($turnNumbers);
        $bits = abs($subsetSeed);
        $missing = [];
        $i = 0;
        foreach ($turnNumbers as $turnNumber) {
            if ((($bits >> $i) & 1) === 1) {
                $missing[] = $turnNumber;
            }
            $i++;
        }

        return $missing;
    }
}

/**
 * An {@see EngineFactory} that forwards to the real {@see ReplayEngineFactory}
 * but counts how many times the one-time backfill entry point
 * `worldsPerTurnForEpisode` is called, so Property 2 can assert the backfill
 * replays EXACTLY ONCE for a read that needs it and NOT AT ALL otherwise.
 */
final class BackfillSpyEngineFactory implements EngineFactory
{
    public int $worldsPerTurnCount = 0;

    public function __construct(private readonly ReplayEngineFactory $inner) {}

    public function forEpisode(string $episodeId): Engine
    {
        return $this->inner->forEpisode($episodeId);
    }

    public function forEpisodeWithWorld(string $episodeId): array
    {
        return $this->inner->forEpisodeWithWorld($episodeId);
    }

    public function worldForEpisode(string $episodeId): WorldModel
    {
        return $this->inner->worldForEpisode($episodeId);
    }

    public function worldsPerTurnForEpisode(string $episodeId, callable $serialize): array
    {
        $this->worldsPerTurnCount++;

        return $this->inner->worldsPerTurnForEpisode($episodeId, $serialize);
    }
}

/**
 * An {@see EngineFactory} whose backfill entry point `worldsPerTurnForEpisode`
 * fails partway through the replay, standing in for a replay that cannot
 * complete (Requirement 10.8). It serialises the first $failAtTurn turns, then
 * throws, so the failure can land before, during, or at the very end of the
 * replay. Because the backfill computes the whole per-turn world set BEFORE
 * writing anything, a throw here means nothing is persisted.
 */
final class ThrowingReplayEngineFactory implements EngineFactory
{
    public function __construct(
        private readonly ReplayEngineFactory $inner,
        private readonly int $failAtTurn,
    ) {}

    public function forEpisode(string $episodeId): Engine
    {
        return $this->inner->forEpisode($episodeId);
    }

    public function forEpisodeWithWorld(string $episodeId): array
    {
        return $this->inner->forEpisodeWithWorld($episodeId);
    }

    public function worldForEpisode(string $episodeId): WorldModel
    {
        return $this->inner->worldForEpisode($episodeId);
    }

    public function worldsPerTurnForEpisode(string $episodeId, callable $serialize): array
    {
        $seen = 0;

        // Wrap the serialiser so the replay throws after $failAtTurn turns have
        // been serialised, simulating a replay that cannot complete.
        return $this->inner->worldsPerTurnForEpisode(
            $episodeId,
            function (WorldModel $world) use ($serialize, &$seen): string {
                if ($seen >= $this->failAtTurn) {
                    throw new RuntimeException('injected replay failure');
                }
                $seen++;

                return $serialize($world);
            }
        );
    }
}

/**
 * A repository decorator that layers a "missing on disk" view of each turn's
 * `world_json` over a real {@see InMemoryEpisodeRepository}, WITHOUT changing
 * the inner store. The inner store keeps the correct world for every turn (the
 * Property-2 oracle a fresh replay reproduces); the decorator reports the
 * turns marked missing as null and fills them — and only them — when the
 * backfill writes.
 *
 * This lets a test simulate a pre-spec episode (some turns with no stored
 * world) while still comparing a filled turn against the faithful oracle. It
 * records how many fills were persisted so Property 3 can assert a failed
 * backfill persisted nothing.
 *
 * InMemoryEpisodeRepository is final, so this is a decorator, not a subclass.
 */
final class WorldJsonOverrideRepository implements EpisodeRepository
{
    /**
     * Per-turn override of the stored world_json. A key present with null means
     * "missing on disk"; a key present with a string means "filled by the
     * backfill". A turn with no key uses the inner store's value.
     *
     * @var array<int, string|null>
     */
    private array $overrides = [];

    public int $fillCallCount = 0;

    public function __construct(private readonly InMemoryEpisodeRepository $inner) {}

    /** Mark a turn as missing its stored world (as a pre-spec turn would be). */
    public function markMissing(int $turnNumber): void
    {
        $this->overrides[$turnNumber] = null;
    }

    public function loadTurnWorldJson(string $episodeId): array
    {
        $byTurn = $this->inner->loadTurnWorldJson($episodeId);
        foreach ($this->overrides as $turnNumber => $worldJson) {
            if (array_key_exists($turnNumber, $byTurn)) {
                $byTurn[$turnNumber] = $worldJson;
            }
        }

        return $byTurn;
    }

    public function fillMissingWorldJson(string $episodeId, array $worldByTurn): void
    {
        // Fill-only: a turn already holding a value (not overridden to null) is
        // left unchanged; a missing one is filled once. Records the fill so a
        // test can assert a failed backfill never reaches here.
        $filledAny = false;
        foreach ($worldByTurn as $turnNumber => $worldJson) {
            $turnNumber = (int) $turnNumber;
            if (!array_key_exists($turnNumber, $this->overrides)) {
                continue; // not one of ours: inner already has it, leave it
            }
            if ($this->overrides[$turnNumber] !== null) {
                continue; // already filled: leave byte-for-byte unchanged
            }
            $this->overrides[$turnNumber] = $worldJson;
            $filledAny = true;
        }
        if ($filledAny) {
            $this->fillCallCount++;
        }
    }

    public function loadEpisodeView(string $id): ?EpisodeView
    {
        $view = $this->inner->loadEpisodeView($id);
        if ($view === null) {
            return null;
        }

        // Rebuild each TurnView's world from the override layer so the read
        // reflects what is "stored" through this decorator.
        $turnViews = [];
        foreach ($view->turns as $turnView) {
            $world = $turnView->world;
            if (array_key_exists($turnView->turnNumber, $this->overrides)) {
                $override = $this->overrides[$turnView->turnNumber];
                $world = $override === null ? null : $this->decodeWorld($override);
            }
            $turnViews[] = new TurnView(
                turnNumber: $turnView->turnNumber,
                state: $turnView->state,
                robots: $turnView->robots,
                residentLine: $turnView->residentLine,
                world: $world,
            );
        }

        return new EpisodeView(
            episodeId: $view->episodeId,
            disaster: $view->disaster,
            currentExpectedTurn: $view->currentExpectedTurn,
            earthquakeStartTurn: $view->earthquakeStartTurn,
            ended: $view->ended,
            turns: $turnViews,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeWorld(string $worldJson): ?array
    {
        $decoded = json_decode($worldJson, true);

        return is_array($decoded) ? $decoded : null;
    }

    // --- everything else forwards unchanged to the inner repo ------------

    public function createEpisode(EpisodeRow $episode): string
    {
        return $this->inner->createEpisode($episode);
    }

    public function findEpisode(string $id): ?EpisodeRow
    {
        return $this->inner->findEpisode($id);
    }

    public function countRunningEpisodes(): int
    {
        return $this->inner->countRunningEpisodes();
    }

    public function findRunningEpisodeForDevice(string $deviceId): ?EpisodeRow
    {
        return $this->inner->findRunningEpisodeForDevice($deviceId);
    }

    public function setEarthquakeStartTurn(string $id, int $turn): void
    {
        $this->inner->setEarthquakeStartTurn($id, $turn);
    }

    public function markEpisodeEnded(string $id): void
    {
        $this->inner->markEpisodeEnded($id);
    }

    public function setCurrentExpectedTurn(string $id, int $turn): void
    {
        $this->inner->setCurrentExpectedTurn($id, $turn);
    }

    public function touchActivity(string $id, string $now): void
    {
        $this->inner->touchActivity($id, $now);
    }

    public function findTurn(string $episodeId, int $turnNumber): ?TurnRow
    {
        return $this->inner->findTurn($episodeId, $turnNumber);
    }

    public function createTurn(string $episodeId, int $turnNumber, ?string $worldJson = null): void
    {
        $this->inner->createTurn($episodeId, $turnNumber, $worldJson);
    }

    public function setTurnState(string $episodeId, int $turnNumber, string $state): void
    {
        $this->inner->setTurnState($episodeId, $turnNumber, $state);
    }

    public function savePromptAndReply(PromptReplyRow $row): void
    {
        $this->inner->savePromptAndReply($row);
    }

    public function findSuccessfulReply(string $episodeId, int $turnNumber, string $robotId): ?ReplyRecord
    {
        return $this->inner->findSuccessfulReply($episodeId, $turnNumber, $robotId);
    }

    public function incrementAttempt(string $episodeId, int $turnNumber, string $robotId): int
    {
        return $this->inner->incrementAttempt($episodeId, $turnNumber, $robotId);
    }

    public function markNoReply(string $episodeId, int $turnNumber, string $robotId): void
    {
        $this->inner->markNoReply($episodeId, $turnNumber, $robotId);
    }

    public function loadAppliedActionsForReplay(string $episodeId): array
    {
        return $this->inner->loadAppliedActionsForReplay($episodeId);
    }

    public function listEndedEpisodes(int $limit): array
    {
        return $this->inner->listEndedEpisodes($limit);
    }

    public function storeAppliedTurn(AppliedTurnRows $rows): void
    {
        $this->inner->storeAppliedTurn($rows);
    }
}
