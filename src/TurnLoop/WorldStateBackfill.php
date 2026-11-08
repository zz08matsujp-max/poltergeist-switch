<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop;

use PoltergeistSwitch\TurnLoop\Http\ApiError;
use PoltergeistSwitch\TurnLoop\Repository\EpisodeRepository;
use Throwable;

/**
 * The one-time per-turn World_State backfill for pre-existing episodes (screens
 * spec Requirements 10.7, 10.8).
 *
 * `world_json` was added to the `turns` table nullable, so turns stored before
 * this spec hold no World_State. When such an episode is next read — by the
 * episode-view read path (`TurnService::getEpisode`) or by the ended-episode
 * list (`EpisodeListService`, a later task) — the missing worlds must be filled
 * once by replaying the engine, never by calling AI. This class holds that
 * single behaviour so both read paths share it; it is a plain class with no I/O
 * of its own beyond the repository and the (pure) replay.
 *
 * How it guarantees the contract:
 *
 *   - **Exactly one replay.** When at least one turn is missing a world, it
 *     calls {@see EngineFactory::worldsPerTurnForEpisode} exactly ONCE. That
 *     single call replays the whole episode and serialises the world after each
 *     turn. It never replays once per missing turn. When no turn is missing it
 *     replays not at all.
 *
 *   - **Only missing turns filled, present ones untouched.** The repository's
 *     `fillMissingWorldJson` writes only turns whose stored `world_json` is
 *     still null; a turn that already holds a value is left byte-for-byte
 *     unchanged (Requirement 10.7).
 *
 *   - **All-or-nothing.** The full set of per-turn worlds is computed from one
 *     complete replay BEFORE anything is written. If the replay throws, no
 *     fill is attempted and nothing is persisted; the error surfaces as a
 *     `world_backfill_failed` {@see ApiError} (Requirement 10.8). The write
 *     itself is a single atomic unit in the repository.
 *
 * No AI is ever called: the replay rebuilds the engine from the stored seed and
 * stored actions only.
 */
final class WorldStateBackfill
{
    public function __construct(
        private readonly EpisodeRepository $repo,
        private readonly EngineFactory $engineFactory,
        private readonly WorldStateSerializer $serializer = new WorldStateSerializer(),
    ) {}

    /**
     * Fill the missing per-turn World_State for one episode, replaying the
     * engine at most once (Requirements 10.7, 10.8). A no-op when the episode
     * has no turn missing a world, so a read of an already-backfilled (or
     * freshly-run) episode never replays.
     *
     * @throws ApiError 'world_backfill_failed' when the replay cannot complete;
     *                   the stored World_State values are then left unchanged
     *                   and nothing partial is persisted (Requirement 10.8).
     */
    public function backfill(string $episodeId): void
    {
        // Read the raw stored world_json per turn WITHOUT decoding, so a present
        // value is compared byte-for-byte and never rewritten (Requirement
        // 10.7).
        $stored = $this->repo->loadTurnWorldJson($episodeId);

        // Nothing stored (unknown or turn-less episode) or every turn already
        // has a world: no replay, no write (the exactly-once guarantee is "at
        // most once"; here it is zero).
        if (!$this->hasMissingTurn($stored)) {
            return;
        }

        // One complete replay, serialising the world after each turn. This is
        // the single replay the backfill performs (Requirement 10.7). A replay
        // that cannot complete throws here, BEFORE any write, so nothing partial
        // is persisted (Requirement 10.8).
        try {
            $worldsByTurn = $this->engineFactory->worldsPerTurnForEpisode(
                $episodeId,
                fn ($world): string => $this->serializer->toJson($world),
            );
        } catch (Throwable $e) {
            throw ApiError::backfillFailed($e);
        }

        // Fill only the turns still missing a world, in one atomic unit; a turn
        // that already holds a value is left byte-for-byte unchanged
        // (Requirements 10.7, 10.8). A write failure leaves nothing partial and
        // surfaces as the same backfill-failed error.
        try {
            $this->repo->fillMissingWorldJson($episodeId, $worldsByTurn);
        } catch (Throwable $e) {
            throw ApiError::backfillFailed($e);
        }
    }

    /**
     * True when at least one turn's stored world_json is absent or null, which
     * is what makes a backfill necessary (Requirement 10.7).
     *
     * @param array<int, string|null> $stored turnNumber => world_json or null
     */
    private function hasMissingTurn(array $stored): bool
    {
        foreach ($stored as $worldJson) {
            if ($worldJson === null) {
                return true;
            }
        }

        return false;
    }
}
