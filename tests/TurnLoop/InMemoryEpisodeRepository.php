<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop;

use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\Values\Judgement;
use PoltergeistSwitch\Engine\Values\Outcome;
use PoltergeistSwitch\Engine\Values\ReportedSpeech;
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
        );

        return $id;
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

    public function createTurn(string $episodeId, int $turnNumber): void
    {
        $key = $this->turnKey($episodeId, $turnNumber);
        // Idempotent: a turn that already exists is left as it is, so its
        // state and attempt counts are never reset by a repeated createTurn.
        if (isset($this->turns[$key])) {
            return;
        }

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
        );
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

        // Carry the resident line onto the turn row so loadEpisodeView can
        // report it; advancing the turn to `applied` is TurnService's job via
        // setTurnState, not this write's.
        $turn = $this->turns[$key] ?? null;
        if ($turn !== null && $rows->residentLine !== null) {
            $this->turns[$key] = $this->withTurnChanges($turn, residentLine: $rows->residentLine);
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

        return ($now - $lastActivity) <= $this->config->idleSlotReleaseSeconds;
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
        );
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
    ): EpisodeRow {
        return new EpisodeRow(
            id: $episode->id,
            deviceId: $episode->deviceId,
            disaster: $episode->disaster,
            seed: $episode->seed,
            currentExpectedTurn: $currentExpectedTurn ?? $episode->currentExpectedTurn,
            earthquakeStartTurn: $earthquakeStartTurn ?? $episode->earthquakeStartTurn,
            ended: $ended ?? $episode->ended,
            createdAt: $episode->createdAt,
            lastActivityAt: $lastActivityAt ?? $episode->lastActivityAt,
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
        );
    }
}
