<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop;

use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\Values\Judgement;
use PoltergeistSwitch\Engine\Values\Outcome;
use PoltergeistSwitch\Engine\Values\ReportedSpeech;
use PoltergeistSwitch\Memory\Values\MemoryRow;
use PoltergeistSwitch\Memory\Values\MemorySnapshot;
use PoltergeistSwitch\Memory\Values\ReflectionClaim;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\Repository\EpisodeRepository;
use PoltergeistSwitch\TurnLoop\Values\AppliedRobotRow;
use PoltergeistSwitch\TurnLoop\Values\AppliedTurnRows;
use PoltergeistSwitch\TurnLoop\Values\EpisodeRow;
use PoltergeistSwitch\TurnLoop\Values\EpisodeView;
use PoltergeistSwitch\TurnLoop\Values\PromptReplyRow;
use PoltergeistSwitch\TurnLoop\Values\Reading;
use PoltergeistSwitch\TurnLoop\Values\ReplyRecord;
use PoltergeistSwitch\TurnLoop\Values\RobotFourLayers;
use PoltergeistSwitch\TurnLoop\Values\TurnRow;
use PoltergeistSwitch\TurnLoop\Values\TurnStateValue;
use PoltergeistSwitch\TurnLoop\Values\TurnView;

/**
 * An in-memory implementation of {@see EpisodeRepository} for driving
 * TurnService without a database server (Requirement 8.7). It is a shared test
 * double, kept under tests/ so it is never deployed; it opens no connection and
 * holds all state in plain PHP arrays.
 *
 * It reproduces the two storage behaviours the properties depend on:
 *   - the lazy idle-slot release (Requirements 9.7, 9.8, 9.9, 9.10): an episode
 *     counts as running only when it has not ended AND its last activity is
 *     within Config::$idleSlotReleaseSeconds of now;
 *   - the single-transaction, idempotent four-layer write (Requirements 8.8,
 *     8.10): storeAppliedTurn either stores every record for the applied turn
 *     or, when that turn's records already exist, leaves them unchanged and
 *     creates no duplicates.
 *
 * Datetimes are kept as 'Y-m-d H:i:s' strings exactly as the row DTOs carry
 * them (build-independent). Turn numbers are zero-based. Robot ids are the
 * literals 'robot_a' and 'robot_b'.
 */
final class InMemoryEpisodeRepository implements EpisodeRepository
{
    /** @var array<string, EpisodeRow> keyed by episode id */
    private array $episodes = [];

    /** @var array<string, TurnRow> keyed by "{episodeId}#{turnNumber}" */
    private array $turns = [];

    /**
     * Stored prompt/reply attempts, one entry per (episode, turn, robot,
     * attempt). The key makes repeated saves of the same attempt overwrite
     * rather than duplicate, mirroring the per-attempt primary key.
     *
     * @var array<string, PromptReplyRow>
     */
    private array $promptReplies = [];

    /**
     * The stored applied-turn records, keyed by "{episodeId}#{turnNumber}".
     * Presence of a key marks the four-layer write as already done for that
     * turn, which is what makes storeAppliedTurn idempotent (Requirement 8.10).
     *
     * @var array<string, AppliedTurnRows>
     */
    private array $appliedTurns = [];

    private int $nextEpisodeSeq = 1;

    /**
     * Returns the current Unix timestamp used by the lazy idle-slot release
     * (isRunning) and by createTurn's stored datetime. Injectable so a test can
     * advance "now" deterministically and prove that an episode which keeps
     * recording activity stays running, while an idle one is released. Defaults
     * to the real clock via time().
     *
     * @var callable():int
     */
    private $clock;

    /**
     * @param callable():int|null $clock returns the current Unix timestamp;
     *                                    defaults to time()
     */
    public function __construct(
        private readonly Config $config = new Config(),
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    // --- Episode CRUD and slot queries -----------------------------------

    public function createEpisode(EpisodeRow $episode): string
    {
        // Honour a caller-supplied id when given; otherwise mint a stable one.
        $id = $episode->id !== '' ? $episode->id : $this->mintEpisodeId();

        $this->episodes[$id] = new EpisodeRow(
            id: $id,
            deviceId: $episode->deviceId,
            disaster: $episode->disaster,
            seed: $episode->seed,
            currentExpectedTurn: $episode->currentExpectedTurn,
            earthquakeStartTurn: $episode->earthquakeStartTurn,
            ended: $episode->ended,
            createdAt: $episode->createdAt,
            lastActivityAt: $episode->lastActivityAt,
            heatWaveStartTurn: $episode->heatWaveStartTurn,
        );

        return $id;
    }

    /** @var array<string,true> turn locks held by this repository */
    private array $heldTurnLocks = [];

    /** @var array<string,true> turn locks held by "someone else" (test hook) */
    private array $foreignTurnLocks = [];

    private int $turnLockAcquireCount = 0;

    private int $turnLockReleaseCount = 0;

    /** Test hook: make a lock busy for this repository's callers. */
    public function holdTurnLock(string $kind, string $episodeId, int $turnNumber): void
    {
        $this->foreignTurnLocks[$kind . ':' . $episodeId . ':' . $turnNumber] = true;
    }

    /** Test hook: make a lock held through {@see holdTurnLock()} free again. */
    public function freeHeldTurnLock(string $kind, string $episodeId, int $turnNumber): void
    {
        unset($this->foreignTurnLocks[$kind . ':' . $episodeId . ':' . $turnNumber]);
    }

    /** Number of successful acquireTurnLock calls. */
    public function turnLockAcquireCount(): int
    {
        return $this->turnLockAcquireCount;
    }

    /** Number of releaseTurnLock calls that freed a held lock. */
    public function turnLockReleaseCount(): int
    {
        return $this->turnLockReleaseCount;
    }

    public function acquireTurnLock(string $kind, string $episodeId, int $turnNumber): bool
    {
        $key = $kind . ':' . $episodeId . ':' . $turnNumber;
        if (isset($this->foreignTurnLocks[$key]) || isset($this->heldTurnLocks[$key])) {
            return false;
        }
        $this->heldTurnLocks[$key] = true;
        $this->turnLockAcquireCount++;

        return true;
    }

    public function releaseTurnLock(string $kind, string $episodeId, int $turnNumber): void
    {
        $key = $kind . ':' . $episodeId . ':' . $turnNumber;
        if (isset($this->heldTurnLocks[$key])) {
            unset($this->heldTurnLocks[$key]);
            $this->turnLockReleaseCount++;
        }
    }

    public function findEpisode(string $id): ?EpisodeRow
    {
        return $this->episodes[$id] ?? null;
    }

    public function countRunningEpisodes(): int
    {
        $now = ($this->clock)();
        $count = 0;
        foreach ($this->episodes as $episode) {
            if ($this->isRunning($episode, $now)) {
                $count++;
            }
        }

        return $count;
    }

    public function findRunningEpisodeForDevice(string $deviceId): ?EpisodeRow
    {
        $now = ($this->clock)();
        foreach ($this->episodes as $episode) {
            if ($episode->deviceId === $deviceId && $this->isRunning($episode, $now)) {
                return $episode;
            }
        }

        return null;
    }

    public function setEarthquakeStartTurn(string $id, int $turn): void
    {
        $episode = $this->episodes[$id] ?? null;
        if ($episode === null) {
            return;
        }

        $this->episodes[$id] = $this->withEpisodeChanges($episode, earthquakeStartTurn: $turn);
    }

    public function setHeatWaveStartTurn(string $id, int $turn): void
    {
        $episode = $this->episodes[$id] ?? null;
        if ($episode === null) {
            return;
        }

        $this->episodes[$id] = $this->withEpisodeChanges($episode, heatWaveStartTurn: $turn);
    }

    public function setDisaster(string $id, string $disaster): void
    {
        $episode = $this->episodes[$id] ?? null;
        if ($episode === null) {
            return;
        }

        $this->episodes[$id] = $this->withEpisodeChanges($episode, disaster: $disaster);
    }

    public function markEpisodeEnded(string $id): void
    {
        $episode = $this->episodes[$id] ?? null;
        if ($episode === null) {
            return;
        }

        $this->episodes[$id] = $this->withEpisodeChanges($episode, ended: true);
    }

    public function setCurrentExpectedTurn(string $id, int $turn): void
    {
        $episode = $this->episodes[$id] ?? null;
        if ($episode === null) {
            return;
        }

        $this->episodes[$id] = $this->withEpisodeChanges($episode, currentExpectedTurn: $turn);
    }

    public function touchActivity(string $id, string $now): void
    {
        $episode = $this->episodes[$id] ?? null;
        if ($episode === null) {
            return;
        }

        // Record activity so an actively-stepping episode keeps its running
        // slot (Requirement 9.10). The copy helper already supports
        // lastActivityAt.
        $this->episodes[$id] = $this->withEpisodeChanges($episode, lastActivityAt: $now);
    }

    // --- Turn lifecycle ---------------------------------------------------

    public function findTurn(string $episodeId, int $turnNumber): ?TurnRow
    {
        return $this->turns[$this->turnKey($episodeId, $turnNumber)] ?? null;
    }

    public function createTurn(string $episodeId, int $turnNumber, ?string $worldJson = null): void
    {
        $key = $this->turnKey($episodeId, $turnNumber);
        // Idempotent: a turn that already exists is left as it is, so its
        // state, attempt counts, and any stored world are never reset by a
        // repeated createTurn.
        if (isset($this->turns[$key])) {
            return;
        }

        // $worldJson stores the per-turn World_State on the new row (turn 0's
        // initial calm world, Req 10.2); null leaves it unset.
        $this->turns[$key] = new TurnRow(
            episodeId: $episodeId,
            turnNumber: $turnNumber,
            state: TurnStateValue::waiting,
            attemptsA: 0,
            attemptsB: 0,
            noReplyA: false,
            noReplyB: false,
            residentLine: null,
            createdAt: $this->now(),
            worldJson: $worldJson,
        );
    }

    public function setTurnWorldJson(string $episodeId, int $turnNumber, string $worldJson): void
    {
        // Unconditional overwrite (heat-wave Requirement 8.2), mirroring the
        // PDO store: a turn that exists has its world replaced; a missing turn
        // is left untouched.
        $key = $this->turnKey($episodeId, $turnNumber);
        $turn = $this->turns[$key] ?? null;
        if ($turn === null) {
            return;
        }

        $this->turns[$key] = $this->withTurnChanges($turn, worldJson: $worldJson);
    }

    public function setTurnState(string $episodeId, int $turnNumber, string $state): void
    {
        $turn = $this->turns[$this->turnKey($episodeId, $turnNumber)] ?? null;
        if ($turn === null) {
            return;
        }

        $next = TurnStateValue::from($state);
        // Forward-only: never return a turn to an earlier state (Req 7.2).
        if (!$turn->state->canAdvanceTo($next)) {
            return;
        }

        $this->turns[$this->turnKey($episodeId, $turnNumber)] = $this->withTurnChanges($turn, state: $next);
    }

    // --- Reply persistence ------------------------------------------------

    public function savePromptAndReply(PromptReplyRow $row): void
    {
        $key = $this->attemptKey($row->episodeId, $row->turnNumber, $row->robotId, $row->attempt);
        $this->promptReplies[$key] = $row;
    }

    /**
     * Test-only introspection: every stored prompt/reply attempt for one robot
     * on one turn, in attempt order. Lets a unit test assert that the stored
     * prompt carries the situation-text version (Requirement 8.3).
     *
     * @return PromptReplyRow[]
     */
    public function storedAttempts(string $episodeId, int $turnNumber, string $robotId): array
    {
        $rows = [];
        foreach ($this->promptReplies as $pr) {
            if ($pr->episodeId === $episodeId
                && $pr->turnNumber === $turnNumber
                && $pr->robotId === $robotId
            ) {
                $rows[] = $pr;
            }
        }
        usort($rows, static fn (PromptReplyRow $a, PromptReplyRow $b): int => $a->attempt <=> $b->attempt);

        return $rows;
    }

    public function findSuccessfulReply(string $episodeId, int $turnNumber, string $robotId): ?ReplyRecord
    {
        $best = null;
        $bestAttempt = -1;
        foreach ($this->promptReplies as $pr) {
            if ($pr->episodeId !== $episodeId
                || $pr->turnNumber !== $turnNumber
                || $pr->robotId !== $robotId
            ) {
                continue;
            }
            if (!$pr->reply->unanswered && $pr->attempt > $bestAttempt) {
                $best = $pr->reply;
                $bestAttempt = $pr->attempt;
            }
        }

        return $best;
    }

    public function incrementAttempt(string $episodeId, int $turnNumber, string $robotId): int
    {
        $turn = $this->turns[$this->turnKey($episodeId, $turnNumber)] ?? null;
        if ($turn === null) {
            return 0;
        }

        if ($this->isRobotA($robotId)) {
            $next = $turn->attemptsA + 1;
            $this->turns[$this->turnKey($episodeId, $turnNumber)] = $this->withTurnChanges($turn, attemptsA: $next);

            return $next;
        }

        $next = $turn->attemptsB + 1;
        $this->turns[$this->turnKey($episodeId, $turnNumber)] = $this->withTurnChanges($turn, attemptsB: $next);

        return $next;
    }

    public function markNoReply(string $episodeId, int $turnNumber, string $robotId): void
    {
        $turn = $this->turns[$this->turnKey($episodeId, $turnNumber)] ?? null;
        if ($turn === null) {
            return;
        }

        if ($this->isRobotA($robotId)) {
            $this->turns[$this->turnKey($episodeId, $turnNumber)] = $this->withTurnChanges($turn, noReplyA: true);

            return;
        }

        $this->turns[$this->turnKey($episodeId, $turnNumber)] = $this->withTurnChanges($turn, noReplyB: true);
    }

    // --- Single-transaction four-layer write ------------------------------

    public function storeAppliedTurn(AppliedTurnRows $rows): void
    {
        $key = $this->turnKey($rows->episodeId, $rows->turnNumber);

        // Idempotent four-layer write (Requirement 8.10): if this applied
        // turn's records already exist, leave them unchanged and create no
        // duplicates. In the in-memory store there is no partial state to
        // worry about — the single assignment below is the whole "commit"
        // (Requirement 8.8), so there is nothing to roll back.
        if (isset($this->appliedTurns[$key])) {
            return;
        }

        $this->appliedTurns[$key] = $rows;

        // Carry the resident line and the post-apply World_State (Req 10.1)
        // onto the turn row so loadEpisodeView can report them; advancing the
        // turn to `applied` is TurnService's job via setTurnState, not this
        // write's. A null world leaves the turn's stored world as it was.
        $turn = $this->turns[$key] ?? null;
        if ($turn !== null) {
            if ($rows->residentLine !== null) {
                $turn = $this->withTurnChanges($turn, residentLine: $rows->residentLine);
            }
            if ($rows->worldJson !== null) {
                $turn = $this->withTurnChanges($turn, worldJson: $rows->worldJson);
            }
            $this->turns[$key] = $turn;
        }
    }

    // --- Replay -----------------------------------------------------------

    public function loadAppliedActionsForReplay(string $episodeId): array
    {
        // Driven by the turns in the `applied` state, not by the stored action
        // rows, so the quiet turn 0 that createEpisode applies (Requirement 4.3)
        // with no robot actions is still replayed, with an empty action map.
        $byTurn = [];
        foreach ($this->turns as $turn) {
            if ($turn->episodeId !== $episodeId) {
                continue;
            }
            if ($turn->state !== TurnStateValue::applied) {
                continue;
            }

            // Every applied turn is a key; a turn with stored actions fills in
            // its per-robot action_json, a quiet turn stays an empty map.
            $perRobot = [];
            $applied = $this->appliedTurns[$this->turnKey($episodeId, $turn->turnNumber)] ?? null;
            if ($applied !== null) {
                foreach ($applied->robots as $robotId => $robotRow) {
                    $perRobot[(string) $robotId] = $robotRow->actionJson;
                }
            }
            $byTurn[$turn->turnNumber] = $perRobot;
        }

        // Ordered by turn number ascending (Requirement 13.2).
        ksort($byTurn);

        return $byTurn;
    }

    // --- Per-turn World_State backfill (screens spec) --------------------

    public function loadTurnWorldJson(string $episodeId): array
    {
        // The raw stored world_json per turn, ascending, null when a turn has
        // none (Requirement 10.7). Returned without decoding so a present value
        // can be compared byte-for-byte by the backfill.
        $byTurn = [];
        foreach ($this->turns as $turn) {
            if ($turn->episodeId !== $episodeId) {
                continue;
            }
            $byTurn[$turn->turnNumber] = $turn->worldJson;
        }

        ksort($byTurn);

        return $byTurn;
    }

    public function fillMissingWorldJson(string $episodeId, array $worldByTurn): void
    {
        // Fill-only, all-or-nothing (Requirements 10.7, 10.8). The in-memory
        // store has no partial-write window — a turn already holding a world is
        // skipped, a missing one is filled, and a turn that does not exist is
        // ignored — so the loop below is the whole atomic "commit".
        foreach ($worldByTurn as $turnNumber => $worldJson) {
            $key = $this->turnKey($episodeId, (int) $turnNumber);
            $turn = $this->turns[$key] ?? null;
            if ($turn === null) {
                continue; // no such turn: ignore
            }
            if ($turn->worldJson !== null) {
                continue; // already present: leave byte-for-byte unchanged
            }
            $this->turns[$key] = $this->withTurnChanges($turn, worldJson: $worldJson);
        }
    }

    // --- Ended-episode list (screens spec) -------------------------------

    public function listEndedEpisodes(int $limit): array
    {
        // Only ended episodes (Requirement 11.1), ordered by created date
        // descending then id descending for ties (Requirement 11.2), capped at
        // $limit. Mirrors the PDO query 'WHERE ended = 1 ORDER BY created_at
        // DESC, id DESC LIMIT :limit' so TurnService/EpisodeListService behaves
        // identically against either store.
        $ended = [];
        foreach ($this->episodes as $episode) {
            if ($episode->ended) {
                $ended[] = $episode;
            }
        }

        usort($ended, static function (EpisodeRow $a, EpisodeRow $b): int {
            // Created date descending; id descending for a tie.
            $byDate = strcmp($b->createdAt, $a->createdAt);
            if ($byDate !== 0) {
                return $byDate;
            }

            return strcmp($b->id, $a->id);
        });

        if ($limit >= 0 && count($ended) > $limit) {
            $ended = array_slice($ended, 0, $limit);
        }

        return $ended;
    }

    public function listEndedEpisodesByIds(array $ids): array
    {
        $episodes = [];
        $seen = [];
        foreach ($ids as $id) {
            $id = (string) $id;
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if (isset($this->episodes[$id]) && $this->episodes[$id]->ended) {
                $episodes[] = $this->episodes[$id];
            }
        }

        return $episodes;
    }

    public function listEndedEpisodesForDevice(
        string $deviceId,
        ?string $sinceCreatedAt,
        array $excludeIds,
        int $limit
    ): array {
        $matches = [];
        foreach ($this->episodes as $episode) {
            if (!$episode->ended || $episode->deviceId !== $deviceId) {
                continue;
            }
            if ($sinceCreatedAt !== null && strcmp($episode->createdAt, $sinceCreatedAt) <= 0) {
                continue;
            }
            if (in_array($episode->id, $excludeIds, true)) {
                continue;
            }
            $matches[] = $episode;
        }

        usort($matches, static function (EpisodeRow $a, EpisodeRow $b): int {
            $byDate = strcmp($b->createdAt, $a->createdAt);

            return $byDate !== 0 ? $byDate : strcmp($b->id, $a->id);
        });

        return array_slice($matches, 0, max(0, $limit));
    }

    // --- View -------------------------------------------------------------

    public function loadEpisodeView(string $id): ?EpisodeView
    {
        $episode = $this->episodes[$id] ?? null;
        if ($episode === null) {
            return null;
        }

        $turnViews = [];
        foreach ($this->turns as $turn) {
            if ($turn->episodeId !== $id) {
                continue;
            }
            $turnViews[] = $this->turnView($turn);
        }

        // Stored turn records in turn order (Requirement 9.4).
        usort($turnViews, static fn (TurnView $a, TurnView $b): int => $a->turnNumber <=> $b->turnNumber);

        return new EpisodeView(
            episodeId: $episode->id,
            disaster: $episode->disaster,
            currentExpectedTurn: $episode->currentExpectedTurn,
            earthquakeStartTurn: $episode->earthquakeStartTurn,
            ended: $episode->ended,
            turns: $turnViews,
        );
    }

    // --- Internal helpers -------------------------------------------------

    /**
     * An episode occupies a running slot only when it has not ended AND its
     * last activity is within the idle-release window (Requirements 9.7–9.10).
     */
    private function isRunning(EpisodeRow $episode, int $now): bool
    {
        if ($episode->ended) {
            return false;
        }

        $lastActivity = $this->timestampOf($episode->lastActivityAt);
        if ($lastActivity === null) {
            // An unparseable datetime is treated as idle (slot free) rather
            // than holding a slot forever.
            return false;
        }

        if (($now - $lastActivity) > $this->config->idleSlotReleaseSeconds) {
            return false;
        }

        // An episode with no turn row of 1 or more (never asked) is released
        // sooner (public-lab Requirement 19).
        if (($now - $lastActivity) <= $this->config->unaskedReleaseSeconds) {
            return true;
        }
        foreach ($this->turns as $turn) {
            if ($turn->episodeId === $episode->id && $turn->turnNumber >= 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse a 'Y-m-d H:i:s' datetime string to a Unix timestamp, or null when
     * it cannot be parsed.
     */
    private function timestampOf(string $datetime): ?int
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $datetime);
        if ($parsed === false) {
            return null;
        }

        return $parsed->getTimestamp();
    }

    private function turnView(TurnRow $turn): TurnView
    {
        $applied = $this->appliedTurns[$this->turnKey($turn->episodeId, $turn->turnNumber)] ?? null;

        $robots = [];
        if ($applied !== null) {
            foreach ($applied->robots as $robotId => $robotRow) {
                $robots[$robotId] = $this->fourLayersOf($robotRow);
            }
        }

        return new TurnView(
            turnNumber: $turn->turnNumber,
            state: $turn->state,
            robots: $robots,
            residentLine: $turn->residentLine,
            world: $this->decodeWorld($turn->worldJson),
        );
    }

    /**
     * Decode a turn's stored `world_json` to the parsed World_State array for
     * the TurnView (screens spec Requirement 10.4), or null when no world has
     * been recorded. The stored string round-trips verbatim, so the decoded
     * array equals what WorldStateSerializer produced (Requirement 10.6); a
     * malformed string degrades to null rather than throwing.
     *
     * @return array<string, mixed>|null
     */
    private function decodeWorld(?string $worldJson): ?array
    {
        if ($worldJson === null) {
            return null;
        }

        $decoded = json_decode($worldJson, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Reconstruct a robot's Four_Layers from its stored row. The judgement,
     * outcome, and reading were stored as JSON strings by TurnService; they are
     * decoded defensively here so a missing or malformed field degrades to a
     * safe default rather than throwing.
     */
    private function fourLayersOf(AppliedRobotRow $row): RobotFourLayers
    {
        $reply = $this->replyTextFor($row);
        $noReply = $row->recorderUnknown && $reply === '';
        // When the robot produced no successful reply, surface the reason its
        // last attempt failed (a short machine token); an answered robot has
        // none.
        $replyFailureReason = $reply === ''
            ? $this->replyFailureReasonFor($row)
            : null;

        $reading = new Reading(
            action: $this->decodeAction($row->robotId, $row->actionJson),
            unknown: $row->recorderUnknown,
            rawJson: $row->recorderJson,
            promptVersion: $row->recorderPromptVersion,
            failureReason: $row->recorderFailureReason,
        );

        return new RobotFourLayers(
            situationText: $row->situationText,
            reply: $reply,
            noReply: $noReply,
            reading: $reading,
            judgement: $this->decodeJudgement($row->robotId, $row->judgementJson),
            outcome: $this->decodeOutcome($row->robotId, $row->outcomeJson),
            recorderFailureReason: $row->recorderFailureReason,
            replyFailureReason: $replyFailureReason,
        );
    }

    /**
     * The robot's reply text comes from its stored successful attempt, if any
     * (the highest-numbered attempt for that robot on that turn). A robot that
     * never produced a reply has an empty reply.
     */
    private function replyTextFor(AppliedRobotRow $row): string
    {
        $applied = null;
        foreach ($this->appliedTurns as $turnRows) {
            if (isset($turnRows->robots[$row->robotId]) && $turnRows->robots[$row->robotId] === $row) {
                $applied = $turnRows;
                break;
            }
        }
        if ($applied === null) {
            return '';
        }

        $best = '';
        $bestAttempt = -1;
        foreach ($this->promptReplies as $pr) {
            if ($pr->episodeId !== $applied->episodeId
                || $pr->turnNumber !== $applied->turnNumber
                || $pr->robotId !== $row->robotId
            ) {
                continue;
            }
            if (!$pr->reply->unanswered && $pr->attempt > $bestAttempt) {
                $best = $pr->reply->reply;
                $bestAttempt = $pr->attempt;
            }
        }

        return $best;
    }

    /**
     * The failure reason of the robot's highest-numbered stored attempt for the
     * applied turn this row belongs to, or null when it has none. Surfaces why
     * an unanswered/no-reply robot has no reply (a short machine token).
     */
    private function replyFailureReasonFor(AppliedRobotRow $row): ?string
    {
        $applied = null;
        foreach ($this->appliedTurns as $turnRows) {
            if (isset($turnRows->robots[$row->robotId]) && $turnRows->robots[$row->robotId] === $row) {
                $applied = $turnRows;
                break;
            }
        }
        if ($applied === null) {
            return null;
        }

        $reason = null;
        $bestAttempt = -1;
        foreach ($this->promptReplies as $pr) {
            if ($pr->episodeId !== $applied->episodeId
                || $pr->turnNumber !== $applied->turnNumber
                || $pr->robotId !== $row->robotId
            ) {
                continue;
            }
            if ($pr->attempt > $bestAttempt) {
                $reason = $pr->reply->failureReason;
                $bestAttempt = $pr->attempt;
            }
        }

        return $reason;
    }

    /**
     * Decode a stored action_json back into an engine Action. A missing or
     * malformed field decodes to operation `unknown` (Requirement 11.3), the
     * same contract the ReplayedAction decode helper will follow.
     */
    private function decodeAction(string $robotId, string $actionJson): Action
    {
        $decoded = json_decode($actionJson, true);
        if (!is_array($decoded)) {
            return $this->unknownAction($robotId);
        }

        $operation = $decoded['operation'] ?? null;
        if (!is_string($operation) || Operation::tryFrom($operation) === null) {
            return $this->unknownAction($robotId);
        }

        return new Action(
            robotId: $robotId,
            operation: $operation,
            targetId: $this->nullableString($decoded, 'targetId'),
            targetSpot: $this->nullableString($decoded, 'targetSpot'),
            words: $this->stringList($decoded['words'] ?? null),
            actionQuote: $this->nullableString($decoded, 'actionQuote'),
            reported: $this->reportedList($decoded['reported'] ?? null),
        );
    }

    private function unknownAction(string $robotId): Action
    {
        return new Action(
            robotId: $robotId,
            operation: Operation::unknown->value,
            targetId: null,
            targetSpot: null,
            words: [],
            actionQuote: null,
            reported: [],
        );
    }

    private function decodeJudgement(string $robotId, string $judgementJson): Judgement
    {
        $decoded = json_decode($judgementJson, true);
        if (!is_array($decoded)) {
            return new Judgement($robotId, false);
        }

        return new Judgement(
            robotId: $robotId,
            possible: (bool) ($decoded['possible'] ?? false),
            reason: $this->nullableString($decoded, 'reason'),
            dangerous: (bool) ($decoded['dangerous'] ?? false),
            dangerCode: $this->nullableString($decoded, 'dangerCode'),
            duration: isset($decoded['duration']) && is_numeric($decoded['duration'])
                ? (int) $decoded['duration']
                : null,
        );
    }

    private function decodeOutcome(string $robotId, string $outcomeJson): Outcome
    {
        $decoded = json_decode($outcomeJson, true);
        if (!is_array($decoded)) {
            return Outcome::failure($robotId, 'unreadable_outcome');
        }

        $worldChanges = $decoded['worldChanges'] ?? [];

        return new Outcome(
            robotId: $robotId,
            success: (bool) ($decoded['success'] ?? false),
            whatHappenedText: $this->nullableString($decoded, 'whatHappenedText'),
            seenByOtherText: $this->nullableString($decoded, 'seenByOtherText'),
            errorMessage: $this->nullableString($decoded, 'errorMessage'),
            worldChanges: is_array($worldChanges) ? $worldChanges : [],
        );
    }

    /**
     * @param mixed $raw
     * @return string[]
     */
    private function stringList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (is_string($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param mixed $raw
     * @return ReportedSpeech[]
     */
    private function reportedList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (
                is_array($item)
                && isset($item['quote'], $item['heardAs'], $item['ownForm'])
                && is_string($item['quote'])
                && is_string($item['heardAs'])
                && is_string($item['ownForm'])
            ) {
                $out[] = new ReportedSpeech($item['quote'], $item['heardAs'], $item['ownForm']);
            }
        }

        return $out;
    }

    /**
     * @param array<mixed> $decoded
     */
    private function nullableString(array $decoded, string $key): ?string
    {
        $value = $decoded[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    private function isRobotA(string $robotId): bool
    {
        return $robotId === 'robot_a';
    }

    private function turnKey(string $episodeId, int $turnNumber): string
    {
        return $episodeId . '#' . $turnNumber;
    }

    private function attemptKey(string $episodeId, int $turnNumber, string $robotId, int $attempt): string
    {
        return $episodeId . '#' . $turnNumber . '#' . $robotId . '#' . $attempt;
    }

    private function mintEpisodeId(): string
    {
        return 'ep_' . $this->nextEpisodeSeq++;
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s', ($this->clock)());
    }

    /**
     * Rebuild an EpisodeRow with selected fields changed (the DTO is readonly).
     */
    private function withEpisodeChanges(
        EpisodeRow $episode,
        ?int $currentExpectedTurn = null,
        ?int $earthquakeStartTurn = null,
        ?bool $ended = null,
        ?string $lastActivityAt = null,
        ?string $disaster = null,
        ?int $heatWaveStartTurn = null,
    ): EpisodeRow {
        return new EpisodeRow(
            id: $episode->id,
            deviceId: $episode->deviceId,
            disaster: $disaster ?? $episode->disaster,
            seed: $episode->seed,
            currentExpectedTurn: $currentExpectedTurn ?? $episode->currentExpectedTurn,
            earthquakeStartTurn: $earthquakeStartTurn ?? $episode->earthquakeStartTurn,
            ended: $ended ?? $episode->ended,
            createdAt: $episode->createdAt,
            lastActivityAt: $lastActivityAt ?? $episode->lastActivityAt,
            heatWaveStartTurn: $heatWaveStartTurn ?? $episode->heatWaveStartTurn,
        );
    }

    /**
     * Rebuild a TurnRow with selected fields changed (the DTO is readonly).
     */
    private function withTurnChanges(
        TurnRow $turn,
        ?TurnStateValue $state = null,
        ?int $attemptsA = null,
        ?int $attemptsB = null,
        ?bool $noReplyA = null,
        ?bool $noReplyB = null,
        ?string $residentLine = null,
        ?string $worldJson = null,
    ): TurnRow {
        return new TurnRow(
            episodeId: $turn->episodeId,
            turnNumber: $turn->turnNumber,
            state: $state ?? $turn->state,
            attemptsA: $attemptsA ?? $turn->attemptsA,
            attemptsB: $attemptsB ?? $turn->attemptsB,
            noReplyA: $noReplyA ?? $turn->noReplyA,
            noReplyB: $noReplyB ?? $turn->noReplyB,
            residentLine: $residentLine ?? $turn->residentLine,
            createdAt: $turn->createdAt,
            worldJson: $worldJson ?? $turn->worldJson,
        );
    }

    // =====================================================================
    // Robot-memory additions (robot-memory spec, Requirement 8.1)
    //
    // The in-memory mirror of PdoEpisodeRepository's memory storage. It carries
    // the logic the properties depend on (the PDO MySQL locking/atomicity tests
    // SKIP when no test database is available), so these behaviours must match
    // the "Reflection idempotency and the MySQL 5.7 locking design" exactly:
    //   - one memory store per robot accumulated across every episode of every
    //     run kind, keyed only by (robot, disaster, generation) and never by
    //     run kind (Requirements 3.2, 6.1);
    //   - appendMemory enforces the active cap of 6 per (robot, disaster,
    //     generation) by retiring every active row past the 6 newest, oldest
    //     first by finish-order seq, never deleting a retired row (Req 3.3,
    //     3.4, 3.5, 8.4), and is idempotent per (sourceEpisodeId, robotId)
    //     (Req 8.5);
    //   - activeMemories returns the active rows of (robot, generation) across
    //     all disasters oldest first, capped, excluding retired and
    //     other-generation rows (Req 3.8, 6.1);
    //   - the reflection claim mirrors SELECT ... FOR UPDATE: first claim wins
    //     in_progress, a fresh in_progress (within 120 s) skips, an answered
    //     slot reuses the stored body, a failed or stale in_progress is
    //     re-claimable (Req 2.8, 2.9, 2.10).
    // A single PHP process runs the fake, so "serialize per triple" needs no
    // real lock: appendMemory retires down to 6 on every call, so however two
    // commits for the same triple interleave the committed active count never
    // exceeds 6 (Req 8.4).
    // =====================================================================

    /**
     * The single memory store, one list of memory rows accumulated across every
     * episode of every run kind (Req 3.2). Each entry is kept verbatim with its
     * finish-order seq and status; a retired row stays in the list (never
     * deleted) but is excluded from every read (Req 3.5).
     *
     * @var array<int, MemoryRow>
     */
    private array $memories = [];

    /**
     * The current memory generation (Req 4.1). Defaults to generation 1, matching
     * the migration's seeded memory_generation row; advanceGeneration moves it
     * forward (Req 4.2) and never deletes a memory.
     */
    private int $currentGeneration = 1;

    /**
     * Append-only Device reset log (the `device_resets` table): one entry per
     * accepted reset, in insertion order. Never updated or deleted.
     *
     * @var list<array{deviceId:string, resetAt:string}>
     */
    private array $deviceResets = [];

    /** The finish-order sequence shared by appended memories (the auto-increment id). */
    private int $nextMemorySeq = 1;

    /**
     * Per-episode stored generation and both robots' frozen snapshots
     * (Req 6.1). Keyed by episode id; a snapshot is the verbatim bodies, oldest
     * first, frozen at creation so a rebuild matches the live run.
     *
     * @var array<string, array{generation:int, robot_a:MemorySnapshot, robot_b:MemorySnapshot}>
     */
    private array $episodeMemory = [];

    /**
     * Per-(episode, robot) reflection slot, mirroring the `reflections` row
     * claimed with SELECT ... FOR UPDATE (Req 2.8–2.10). Keyed by
     * "{episodeId}#{robotId}". `claimedAt` is a Unix timestamp from the
     * injected clock so a test can advance "now" and prove the 120 s stale
     * recovery; `answer` holds the stored MemoryRow once answered.
     *
     * @var array<string, array{status:string, claimedAt:int, reason:?string, answer:?MemoryRow}>
     */
    private array $reflections = [];

    // --- Generations -----------------------------------------------------

    public function currentGeneration(): int
    {
        return $this->currentGeneration;
    }

    public function deviceGeneration(string $deviceId): int
    {
        // 1 + the number of reset rows recorded for this Device (Req 2.2).
        $count = 0;
        foreach ($this->deviceResets as $reset) {
            if ($reset['deviceId'] === $deviceId) {
                $count++;
            }
        }

        return 1 + $count;
    }

    public function recordDeviceReset(string $deviceId, string $now): int
    {
        // Append-only: one new row, nothing updated or deleted (Req 2.10).
        $this->deviceResets[] = ['deviceId' => $deviceId, 'resetAt' => $now];

        return $this->deviceGeneration($deviceId);
    }

    public function latestDeviceResetAt(string $deviceId): ?string
    {
        // The most recently appended reset of this Device (highest id).
        $latest = null;
        foreach ($this->deviceResets as $reset) {
            if ($reset['deviceId'] === $deviceId) {
                $latest = $reset['resetAt'];
            }
        }

        return $latest;
    }

    public function advanceGeneration(): int
    {
        // Move the current generation forward and delete nothing (Req 4.2): new
        // episodes start with no memories while every earlier-generation row is
        // kept but excluded from reads.
        $this->currentGeneration++;

        return $this->currentGeneration;
    }

    // --- Per-episode snapshot + generation -------------------------------

    public function storeEpisodeMemory(
        string $episodeId,
        int $generation,
        MemorySnapshot $robotA,
        MemorySnapshot $robotB
    ): void {
        // Freeze the two ordered body lists and the generation with the episode
        // (Req 6.1). Rebuilt turns read these back verbatim rather than
        // re-querying the live store, so replay matches the live run.
        $this->episodeMemory[$episodeId] = [
            'generation' => $generation,
            'robot_a' => $robotA,
            'robot_b' => $robotB,
        ];
    }

    public function episodeGeneration(string $episodeId): int
    {
        // A running episode keeps its own stored generation even after a reset
        // advances the current one (Req 4.4); a pre-feature / unknown episode
        // falls back to the current generation.
        return $this->episodeMemory[$episodeId]['generation'] ?? $this->currentGeneration;
    }

    public function episodeSnapshot(string $episodeId, string $robotId): MemorySnapshot
    {
        // A pre-feature episode (no stored snapshot) yields an empty snapshot,
        // matching the PDO path that treats a NULL / malformed snapshot column
        // as empty (Req 6.6).
        $stored = $this->episodeMemory[$episodeId] ?? null;
        if ($stored === null) {
            return new MemorySnapshot([]);
        }

        return $stored[$robotId] ?? new MemorySnapshot([]);
    }

    public function episodeHasSnapshot(string $episodeId): bool
    {
        // A post-feature episode has had storeEpisodeMemory called at creation,
        // so it carries a stored snapshot (even an empty one) and is
        // reflectable; a pre-feature / unknown episode has none and is not
        // (Requirement 2.2), mirroring the PDO NULL-column check.
        return isset($this->episodeMemory[$episodeId]);
    }

    // --- Memories: read --------------------------------------------------

    public function activeMemories(string $deviceId, string $robotId, int $generation, int $cap): MemorySnapshot
    {
        // Active rows of (robot, generation) across ALL disasters, oldest first
        // by finish-order seq, excluding retired and other-generation rows
        // (Req 3.8, 6.1). The cap keeps only the newest $cap when more exist,
        // so the oldest beyond the cap are dropped from the snapshot (not the
        // store) — the snapshot still reads oldest first.
        $rows = [];
        foreach ($this->memories as $memory) {
            // device_id = :device never matches a NULL (legacy) row.
            if ($memory->deviceId !== null
                && $memory->deviceId === $deviceId
                && $memory->robotId === $robotId
                && $memory->generation === $generation
                && $memory->status === 'active'
            ) {
                $rows[] = $memory;
            }
        }

        usort($rows, static fn (MemoryRow $a, MemoryRow $b): int => ($a->seq ?? 0) <=> ($b->seq ?? 0));

        if ($cap >= 0 && count($rows) > $cap) {
            // Keep the newest $cap, still presented oldest first.
            $rows = array_slice($rows, count($rows) - $cap);
        }

        $bodies = [];
        foreach ($rows as $memory) {
            $bodies[] = $memory->body;
        }

        return new MemorySnapshot($bodies);
    }

    public function activeCount(string $deviceId, string $robotId, int $generation, string $disaster): int
    {
        // Active rows of the (device, robot, disaster, generation) group,
        // excluding retired, other-generation and legacy (no owner) rows. The
        // per-group cap is 6.
        $count = 0;
        foreach ($this->memories as $memory) {
            if ($memory->deviceId !== null
                && $memory->deviceId === $deviceId
                && $memory->robotId === $robotId
                && $memory->generation === $generation
                && $memory->disaster === $disaster
                && $memory->status === 'active'
            ) {
                $count++;
            }
        }

        return $count;
    }

    // --- Memories: write with cap + retirement, atomic -------------------

    public function appendMemory(MemoryRow $memory): void
    {
        // Idempotent per (sourceEpisodeId, robotId): a second store for the same
        // source episode + robot leaves the store unchanged and creates no
        // duplicate (Req 8.5), mirroring the PDO uq_source guard.
        foreach ($this->memories as $existing) {
            if ($existing->sourceEpisodeId === $memory->sourceEpisodeId
                && $existing->robotId === $memory->robotId
            ) {
                return;
            }
        }

        // Append in finish order: the next seq is the auto-increment id, so a
        // later-committed memory has a higher seq (Req 3.6). The created time
        // and tags are stored verbatim; status starts active.
        $this->memories[] = new MemoryRow(
            robotId: $memory->robotId,
            generation: $memory->generation,
            disaster: $memory->disaster,
            sourceEpisodeId: $memory->sourceEpisodeId,
            body: $memory->body,
            status: 'active',
            createdAt: $memory->createdAt,
            seq: $this->nextMemorySeq++,
            retiredAt: null,
            deviceId: $memory->deviceId,
        );

        // Retire oldest-active rows of this triple down to at most 6 (Req 3.3,
        // 3.4, 8.4). Retiring every active row past the 6 newest (oldest first
        // by seq) means that however two interleaved commits for the same
        // triple order their appends, the committed active count never exceeds
        // 6: the second append still retires its own surplus. A retired row is
        // kept in the store (status flipped, retired_at set), never deleted
        // (Req 3.5), and only this triple is touched so no other-generation or
        // other-disaster memory is retired.
        // A row with no owner belongs to no group (device_id = :device never
        // matches NULL), so it retires nothing.
        if ($memory->deviceId !== null) {
            $this->retireSurplus($memory->deviceId, $memory->robotId, $memory->disaster, $memory->generation);
        }
    }

    /**
     * Retire the oldest active rows of ($robotId, $disaster, $generation) until
     * at most 6 remain active, oldest first by finish-order seq (Req 3.4, 8.4).
     * Flips status to 'retired' and sets retired_at in place; never deletes.
     */
    private function retireSurplus(string $deviceId, string $robotId, string $disaster, int $generation): void
    {
        $cap = 6;

        // Indexes of this group's active rows, oldest first by seq. The oldest
        // surplus is retired and the newest 6 stay active (the SQL's
        // ORDER BY id DESC OFFSET 6).
        $activeIndexes = [];
        foreach ($this->memories as $index => $existing) {
            if ($existing->deviceId === $deviceId
                && $existing->robotId === $robotId
                && $existing->disaster === $disaster
                && $existing->generation === $generation
                && $existing->status === 'active'
            ) {
                $activeIndexes[] = $index;
            }
        }
        usort(
            $activeIndexes,
            fn (int $a, int $b): int =>
                ($this->memories[$a]->seq ?? 0) <=> ($this->memories[$b]->seq ?? 0)
        );

        $surplus = count($activeIndexes) - $cap;
        if ($surplus <= 0) {
            return;
        }

        $retiredAt = $this->now();
        for ($i = 0; $i < $surplus; $i++) {
            $index = $activeIndexes[$i];
            $row = $this->memories[$index];
            $this->memories[$index] = new MemoryRow(
                robotId: $row->robotId,
                generation: $row->generation,
                disaster: $row->disaster,
                sourceEpisodeId: $row->sourceEpisodeId,
                body: $row->body,
                status: 'retired',
                createdAt: $row->createdAt,
                seq: $row->seq,
                retiredAt: $retiredAt,
                deviceId: $row->deviceId,
            );
        }
    }

    // --- Reflection slot claim/commit (idempotency) ----------------------

    public function claimReflectionSlot(string $episodeId, string $robotId): ReflectionClaim
    {
        $key = $this->reflectionKey($episodeId, $robotId);
        $now = ($this->clock)();
        $slot = $this->reflections[$key] ?? null;

        // No row yet: this caller owns a fresh claim (Req 2.8). Mirrors the
        // INSERT ... ON DUPLICATE KEY creating the row, then owning it.
        if ($slot === null) {
            $this->reflections[$key] = [
                'status' => 'in_progress',
                'claimedAt' => $now,
                'reason' => null,
                'answer' => null,
            ];

            return ReflectionClaim::claimed();
        }

        // Already answered: reuse the stored body, make no call (Req 2.9).
        if ($slot['status'] === 'answered' && $slot['answer'] !== null) {
            return ReflectionClaim::reuse($slot['answer']->body);
        }

        // A previously failed slot is re-claimable (Req 2.10).
        if ($slot['status'] === 'failed') {
            $this->reflections[$key] = [
                'status' => 'in_progress',
                'claimedAt' => $now,
                'reason' => null,
                'answer' => null,
            ];

            return ReflectionClaim::claimed();
        }

        // An in-progress slot: fresh (within 120 s) → a concurrent caller owns
        // it, so skip and do not double-ask (Req 2.9); stale (older than 120 s)
        // → the owner died, so re-claim it (Req 2.10).
        if ($slot['status'] === 'in_progress') {
            $stale = ($now - $slot['claimedAt']) >= 120;
            if ($stale) {
                $this->reflections[$key] = [
                    'status' => 'in_progress',
                    'claimedAt' => $now,
                    'reason' => null,
                    'answer' => null,
                ];

                return ReflectionClaim::claimed();
            }

            return ReflectionClaim::skip();
        }

        // Any unexpected state is treated as skippable rather than claimed, so a
        // bad slot can never cause a double-ask.
        return ReflectionClaim::skip();
    }

    public function commitReflectionAnswer(string $episodeId, string $robotId, MemoryRow $memory): void
    {
        // Append the memory with the cap/retirement applied (Req 2.6, 8.4);
        // appendMemory is idempotent per (sourceEpisodeId, robotId), so a
        // repeated commit of an already-stored answer creates no duplicate
        // (Req 8.5). Then mark the slot answered so repeat/concurrent calls
        // reuse it.
        $this->appendMemory($memory);

        // The stored answer carried on the slot is the persisted row (with its
        // seq/status), so reflectionAnswer and a later reuse read it back
        // verbatim.
        $stored = $this->findStoredMemory($memory->sourceEpisodeId, $memory->robotId) ?? $memory;

        $key = $this->reflectionKey($episodeId, $robotId);
        $existing = $this->reflections[$key] ?? null;
        $this->reflections[$key] = [
            'status' => 'answered',
            'claimedAt' => $existing['claimedAt'] ?? ($this->clock)(),
            'reason' => null,
            'answer' => $stored,
        ];
    }

    public function commitReflectionFailure(string $episodeId, string $robotId, string $reason): void
    {
        // Add no memory, record the short reason, and release the slot to
        // 'failed' so a later reflect may ask that robot again (Req 2.7, 2.10).
        $key = $this->reflectionKey($episodeId, $robotId);
        $existing = $this->reflections[$key] ?? null;
        $this->reflections[$key] = [
            'status' => 'failed',
            'claimedAt' => $existing['claimedAt'] ?? ($this->clock)(),
            'reason' => $reason,
            'answer' => null,
        ];
    }

    public function reflectionAnswer(string $episodeId, string $robotId): ?MemoryRow
    {
        // The stored memory when the slot is answered, so the caller reuses it
        // without a new AI call (Req 2.8, 2.9); null otherwise.
        $slot = $this->reflections[$this->reflectionKey($episodeId, $robotId)] ?? null;
        if ($slot === null || $slot['status'] !== 'answered') {
            return null;
        }

        return $slot['answer'];
    }

    /**
     * The persisted memory row for one (sourceEpisodeId, robotId), or null when
     * none exists. Used so a committed answer carries the stored row (with its
     * finish-order seq) rather than the caller's pre-store draft.
     */
    private function findStoredMemory(string $sourceEpisodeId, string $robotId): ?MemoryRow
    {
        foreach ($this->memories as $memory) {
            if ($memory->sourceEpisodeId === $sourceEpisodeId && $memory->robotId === $robotId) {
                return $memory;
            }
        }

        return null;
    }

    private function reflectionKey(string $episodeId, string $robotId): string
    {
        return $episodeId . '#' . $robotId;
    }
}
