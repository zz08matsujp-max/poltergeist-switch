<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop\Repository;

use PDO;
use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\Values\Judgement;
use PoltergeistSwitch\Engine\Values\Outcome;
use PoltergeistSwitch\Engine\Values\ReportedSpeech;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\Values\AppliedRobotRow;
use PoltergeistSwitch\TurnLoop\Values\AppliedTurnRows;
use PoltergeistSwitch\TurnLoop\Values\EpisodeRow;
use PoltergeistSwitch\TurnLoop\Values\EpisodeView;
use PoltergeistSwitch\TurnLoop\Values\PromptReplyRow;
use PoltergeistSwitch\TurnLoop\Values\Reading;
use PoltergeistSwitch\TurnLoop\Values\RobotFourLayers;
use PoltergeistSwitch\TurnLoop\Values\TurnRow;
use PoltergeistSwitch\TurnLoop\Values\TurnStateValue;
use PoltergeistSwitch\TurnLoop\Values\TurnView;
use RuntimeException;
use Throwable;

/**
 * The PDO/MySQL implementation of {@see EpisodeRepository} (Requirement 8.1).
 *
 * It mirrors the storage semantics of {@see \PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository}
 * so TurnService behaves identically against either, and matches the schema in
 * sql/turn_loop.sql exactly. Every query uses prepared statements with bound
 * parameters; no value is ever interpolated into SQL.
 *
 * The two load-bearing behaviours:
 *   - the lazy idle-slot release (Requirements 9.7–9.10): an episode occupies a
 *     running slot only when ended = 0 AND last_activity_at is within
 *     Config::$idleSlotReleaseSeconds of now. The threshold is computed in PHP
 *     and bound as a parameter, never interpolated;
 *   - the single-transaction, idempotent four-layer write (Requirements 8.8,
 *     8.9, 8.10): storeAppliedTurn writes every record inside one transaction,
 *     commits only after all of them succeed, rolls back on any exception, and
 *     is guarded by the (episode_id, turn_number, robot_id) keys so a repeat
 *     creates no duplicates.
 *
 * The PDO handle is expected to be constructed elsewhere from credentials read
 * from outside the web root; this class never touches credentials. It assumes
 * PDO::ERRMODE_EXCEPTION so a failed statement throws and the transaction can
 * roll back; it sets that mode defensively in the constructor.
 *
 * Datetimes are kept as 'Y-m-d H:i:s' strings, matching the row DTOs and the
 * DATETIME columns, so storage stays build-independent.
 */
final class PdoEpisodeRepository implements EpisodeRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config = new Config(),
    ) {
        // Make failures throw so storeAppliedTurn's try/rollBack works and a
        // silent partial write can never happen.
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    // --- Episode CRUD and slot queries -----------------------------------

    public function createEpisode(EpisodeRow $episode): string
    {
        $id = $episode->id !== '' ? $episode->id : $this->mintEpisodeId();

        $sql = 'INSERT INTO episodes '
            . '(id, device_id, disaster, seed, current_expected_turn, '
            . 'earthquake_start_turn, ended, created_at, last_activity_at) '
            . 'VALUES '
            . '(:id, :device_id, :disaster, :seed, :current_expected_turn, '
            . ':earthquake_start_turn, :ended, :created_at, :last_activity_at)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $id);
        $stmt->bindValue(':device_id', $episode->deviceId);
        $stmt->bindValue(':disaster', $episode->disaster);
        $stmt->bindValue(':seed', $episode->seed, PDO::PARAM_INT);
        $stmt->bindValue(':current_expected_turn', $episode->currentExpectedTurn, PDO::PARAM_INT);
        if ($episode->earthquakeStartTurn === null) {
            $stmt->bindValue(':earthquake_start_turn', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':earthquake_start_turn', $episode->earthquakeStartTurn, PDO::PARAM_INT);
        }
        $stmt->bindValue(':ended', $episode->ended ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':created_at', $episode->createdAt);
        $stmt->bindValue(':last_activity_at', $episode->lastActivityAt);
        $stmt->execute();

        return $id;
    }

    public function findEpisode(string $id): ?EpisodeRow
    {
        $stmt = $this->pdo->prepare('SELECT * FROM episodes WHERE id = :id');
        $stmt->bindValue(':id', $id);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return $this->episodeRowFrom($row);
    }

    public function countRunningEpisodes(): int
    {
        $sql = 'SELECT COUNT(*) FROM episodes '
            . 'WHERE ended = 0 AND last_activity_at >= :threshold';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':threshold', $this->idleThreshold());
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function findRunningEpisodeForDevice(string $deviceId): ?EpisodeRow
    {
        $sql = 'SELECT * FROM episodes '
            . 'WHERE device_id = :device_id AND ended = 0 '
            . 'AND last_activity_at >= :threshold '
            . 'ORDER BY last_activity_at DESC '
            . 'LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':device_id', $deviceId);
        $stmt->bindValue(':threshold', $this->idleThreshold());
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return $this->episodeRowFrom($row);
    }

    public function setEarthquakeStartTurn(string $id, int $turn): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE episodes SET earthquake_start_turn = :turn WHERE id = :id'
        );
        $stmt->bindValue(':turn', $turn, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    }

    public function markEpisodeEnded(string $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE episodes SET ended = 1 WHERE id = :id');
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    }

    public function setCurrentExpectedTurn(string $id, int $turn): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE episodes SET current_expected_turn = :turn WHERE id = :id'
        );
        $stmt->bindValue(':turn', $turn, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    }

    // --- Turn lifecycle ---------------------------------------------------

    public function findTurn(string $episodeId, int $turnNumber): ?TurnRow
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM turns WHERE episode_id = :episode_id AND turn_number = :turn_number'
        );
        $stmt->bindValue(':episode_id', $episodeId);
        $stmt->bindValue(':turn_number', $turnNumber, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return $this->turnRowFrom($row);
    }

    public function createTurn(string $episodeId, int $turnNumber): void
    {
        // Idempotent create: INSERT IGNORE leaves an existing turn untouched so
        // its state and attempt counts are never reset by a repeated create.
        $sql = 'INSERT IGNORE INTO turns '
            . '(episode_id, turn_number, state, attempts_a, attempts_b, '
            . 'no_reply_a, no_reply_b, resident_line, created_at) '
            . 'VALUES '
            . '(:episode_id, :turn_number, :state, 0, 0, 0, 0, NULL, :created_at)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':episode_id', $episodeId);
        $stmt->bindValue(':turn_number', $turnNumber, PDO::PARAM_INT);
        $stmt->bindValue(':state', TurnStateValue::waiting->value);
        $stmt->bindValue(':created_at', $this->now());
        $stmt->execute();
    }

    public function setTurnState(string $episodeId, int $turnNumber, string $state): void
    {
        $turn = $this->findTurn($episodeId, $turnNumber);
        if ($turn === null) {
            return;
        }

        $next = TurnStateValue::from($state);
        // Forward-only: never return a turn to an earlier state (Req 7.2).
        if (!$turn->state->canAdvanceTo($next)) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE turns SET state = :state '
            . 'WHERE episode_id = :episode_id AND turn_number = :turn_number'
        );
        $stmt->bindValue(':state', $next->value);
        $stmt->bindValue(':episode_id', $episodeId);
        $stmt->bindValue(':turn_number', $turnNumber, PDO::PARAM_INT);
        $stmt->execute();
    }

    // --- Reply persistence ------------------------------------------------

    public function savePromptAndReply(PromptReplyRow $row): void
    {
        // The prompt (prompt_text + situation_text_version) is one row per
        // robot per turn; repeated attempts overwrite the same row. The reply
        // (usage + flags) is one row per attempt.
        $this->upsertPrompt($row);
        $this->upsertResponse($row);
    }

    public function incrementAttempt(string $episodeId, int $turnNumber, string $robotId): int
    {
        $column = $this->isRobotA($robotId) ? 'attempts_a' : 'attempts_b';

        // The column name comes from a fixed internal whitelist above, never
        // from caller input, so interpolating it here is safe; every value is
        // still bound.
        $update = $this->pdo->prepare(
            'UPDATE turns SET ' . $column . ' = ' . $column . ' + 1 '
            . 'WHERE episode_id = :episode_id AND turn_number = :turn_number'
        );
        $update->bindValue(':episode_id', $episodeId);
        $update->bindValue(':turn_number', $turnNumber, PDO::PARAM_INT);
        $update->execute();

        $select = $this->pdo->prepare(
            'SELECT ' . $column . ' FROM turns '
            . 'WHERE episode_id = :episode_id AND turn_number = :turn_number'
        );
        $select->bindValue(':episode_id', $episodeId);
        $select->bindValue(':turn_number', $turnNumber, PDO::PARAM_INT);
        $select->execute();

        $value = $select->fetchColumn();
        if ($value === false) {
            return 0;
        }

        return (int) $value;
    }

    public function markNoReply(string $episodeId, int $turnNumber, string $robotId): void
    {
        $column = $this->isRobotA($robotId) ? 'no_reply_a' : 'no_reply_b';

        $stmt = $this->pdo->prepare(
            'UPDATE turns SET ' . $column . ' = 1 '
            . 'WHERE episode_id = :episode_id AND turn_number = :turn_number'
        );
        $stmt->bindValue(':episode_id', $episodeId);
        $stmt->bindValue(':turn_number', $turnNumber, PDO::PARAM_INT);
        $stmt->execute();
    }

    // --- Single-transaction four-layer write ------------------------------

    public function storeAppliedTurn(AppliedTurnRows $rows): void
    {
        // Idempotent guard (Requirement 8.10): if this applied turn's records
        // already exist (keyed by episode_id + turn_number + robot_id in the
        // actions table), leave them unchanged and create no duplicates.
        if ($this->appliedTurnExists($rows->episodeId, $rows->turnNumber)) {
            return;
        }

        $this->pdo->beginTransaction();
        try {
            // Carry the resident line onto the turn row so loadEpisodeView can
            // report it. Advancing the turn's state to 'applied' is
            // TurnService's job via setTurnState, not this write's.
            if ($rows->residentLine !== null) {
                $stmt = $this->pdo->prepare(
                    'UPDATE turns SET resident_line = :resident_line '
                    . 'WHERE episode_id = :episode_id AND turn_number = :turn_number'
                );
                $stmt->bindValue(':resident_line', $rows->residentLine);
                $stmt->bindValue(':episode_id', $rows->episodeId);
                $stmt->bindValue(':turn_number', $rows->turnNumber, PDO::PARAM_INT);
                $stmt->execute();
            }

            foreach ($rows->robots as $robotId => $robotRow) {
                $this->insertPromptLayer($rows->episodeId, $rows->turnNumber, (string) $robotId, $robotRow);
                $this->insertRecordingLayer($rows->episodeId, $rows->turnNumber, (string) $robotId, $robotRow);
                $this->insertActionLayer($rows->episodeId, $rows->turnNumber, (string) $robotId, $robotRow);
            }

            // Commit only after every record is written (Requirement 8.8).
            $this->pdo->commit();
        } catch (Throwable $e) {
            // On any error, roll back so no partial records persist, then
            // report the failure to the caller (Requirement 8.9).
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw new RuntimeException('storeAppliedTurn failed; rolled back', 0, $e);
        }
    }

    // --- View -------------------------------------------------------------

    public function loadEpisodeView(string $id): ?EpisodeView
    {
        $episode = $this->findEpisode($id);
        if ($episode === null) {
            return null;
        }

        $turnStmt = $this->pdo->prepare(
            'SELECT * FROM turns WHERE episode_id = :episode_id ORDER BY turn_number ASC'
        );
        $turnStmt->bindValue(':episode_id', $id);
        $turnStmt->execute();

        $turnViews = [];
        $turnRows = $turnStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($turnRows as $turnRow) {
            if (!is_array($turnRow)) {
                continue;
            }
            $turn = $this->turnRowFrom($turnRow);
            $turnViews[] = $this->turnView($turn);
        }

        return new EpisodeView(
            episodeId: $episode->id,
            disaster: $episode->disaster,
            currentExpectedTurn: $episode->currentExpectedTurn,
            earthquakeStartTurn: $episode->earthquakeStartTurn,
            ended: $episode->ended,
            turns: $turnViews,
        );
    }

    // --- Internal: reply persistence -------------------------------------

    private function upsertPrompt(PromptReplyRow $row): void
    {
        $sql = 'INSERT INTO prompts '
            . '(episode_id, turn_number, robot_id, prompt_text, situation_text_version) '
            . 'VALUES (:episode_id, :turn_number, :robot_id, :prompt_text, :version) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'prompt_text = VALUES(prompt_text), '
            . 'situation_text_version = VALUES(situation_text_version)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':episode_id', $row->episodeId);
        $stmt->bindValue(':turn_number', $row->turnNumber, PDO::PARAM_INT);
        $stmt->bindValue(':robot_id', $row->robotId);
        $stmt->bindValue(':prompt_text', $row->reply->prompt);
        $stmt->bindValue(':version', $row->situationTextVersion);
        $stmt->execute();
    }

    private function upsertResponse(PromptReplyRow $row): void
    {
        $sql = 'INSERT INTO responses '
            . '(episode_id, turn_number, robot_id, attempt, reply_text, '
            . 'input_tokens, output_tokens, seconds, cost, unanswered, truncated) '
            . 'VALUES '
            . '(:episode_id, :turn_number, :robot_id, :attempt, :reply_text, '
            . ':input_tokens, :output_tokens, :seconds, :cost, :unanswered, :truncated) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'reply_text = VALUES(reply_text), '
            . 'input_tokens = VALUES(input_tokens), '
            . 'output_tokens = VALUES(output_tokens), '
            . 'seconds = VALUES(seconds), '
            . 'cost = VALUES(cost), '
            . 'unanswered = VALUES(unanswered), '
            . 'truncated = VALUES(truncated)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':episode_id', $row->episodeId);
        $stmt->bindValue(':turn_number', $row->turnNumber, PDO::PARAM_INT);
        $stmt->bindValue(':robot_id', $row->robotId);
        $stmt->bindValue(':attempt', $row->attempt, PDO::PARAM_INT);
        $stmt->bindValue(':reply_text', $row->reply->reply);
        $stmt->bindValue(':input_tokens', $row->reply->inputTokens, PDO::PARAM_INT);
        $stmt->bindValue(':output_tokens', $row->reply->outputTokens, PDO::PARAM_INT);
        $stmt->bindValue(':seconds', (string) $row->reply->seconds);
        $stmt->bindValue(':cost', (string) $row->reply->cost);
        $stmt->bindValue(':unanswered', $row->reply->unanswered ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':truncated', $row->reply->truncated ? 1 : 0, PDO::PARAM_INT);
        $stmt->execute();
    }

    // --- Internal: four-layer write --------------------------------------

    /**
     * True when the actions table already holds a row for this turn (either
     * robot), which marks the four-layer write as already done (Req 8.10).
     */
    private function appliedTurnExists(string $episodeId, int $turnNumber): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM actions '
            . 'WHERE episode_id = :episode_id AND turn_number = :turn_number'
        );
        $stmt->bindValue(':episode_id', $episodeId);
        $stmt->bindValue(':turn_number', $turnNumber, PDO::PARAM_INT);
        $stmt->execute();

        return ((int) $stmt->fetchColumn()) > 0;
    }

    private function insertPromptLayer(string $episodeId, int $turnNumber, string $robotId, AppliedRobotRow $row): void
    {
        // The situation text sent and its version (prompts table, Req 8.3).
        // INSERT IGNORE keeps the guard idempotent should the prompt row
        // already exist from the ask phase.
        $sql = 'INSERT INTO prompts '
            . '(episode_id, turn_number, robot_id, prompt_text, situation_text_version) '
            . 'VALUES (:episode_id, :turn_number, :robot_id, :prompt_text, :version) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'prompt_text = VALUES(prompt_text), '
            . 'situation_text_version = VALUES(situation_text_version)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':episode_id', $episodeId);
        $stmt->bindValue(':turn_number', $turnNumber, PDO::PARAM_INT);
        $stmt->bindValue(':robot_id', $robotId);
        $stmt->bindValue(':prompt_text', $row->situationText);
        $stmt->bindValue(':version', $row->situationTextVersion);
        $stmt->execute();
    }

    private function insertRecordingLayer(string $episodeId, int $turnNumber, string $robotId, AppliedRobotRow $row): void
    {
        // The recorder reading (recordings table, Req 8.5).
        $sql = 'INSERT INTO recordings '
            . '(episode_id, turn_number, robot_id, recorder_json, unknown, recorder_prompt_version) '
            . 'VALUES (:episode_id, :turn_number, :robot_id, :recorder_json, :unknown, :version)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':episode_id', $episodeId);
        $stmt->bindValue(':turn_number', $turnNumber, PDO::PARAM_INT);
        $stmt->bindValue(':robot_id', $robotId);
        $stmt->bindValue(':recorder_json', $row->recorderJson);
        $stmt->bindValue(':unknown', $row->recorderUnknown ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':version', $row->recorderPromptVersion);
        $stmt->execute();
    }

    private function insertActionLayer(string $episodeId, int $turnNumber, string $robotId, AppliedRobotRow $row): void
    {
        // The rules judgement, the outcome, and the verified action for replay
        // (actions table, Req 8.6, 13.3).
        $sql = 'INSERT INTO actions '
            . '(episode_id, turn_number, robot_id, judgement_json, outcome_json, action_json) '
            . 'VALUES (:episode_id, :turn_number, :robot_id, :judgement_json, :outcome_json, :action_json)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':episode_id', $episodeId);
        $stmt->bindValue(':turn_number', $turnNumber, PDO::PARAM_INT);
        $stmt->bindValue(':robot_id', $robotId);
        $stmt->bindValue(':judgement_json', $row->judgementJson);
        $stmt->bindValue(':outcome_json', $row->outcomeJson);
        $stmt->bindValue(':action_json', $row->actionJson);
        $stmt->execute();
    }

    // --- Internal: view rebuild ------------------------------------------

    private function turnView(TurnRow $turn): TurnView
    {
        $robots = $this->fourLayersForTurn($turn->episodeId, $turn->turnNumber);

        return new TurnView(
            turnNumber: $turn->turnNumber,
            state: $turn->state,
            robots: $robots,
            residentLine: $turn->residentLine,
        );
    }

    /**
     * Rebuild the per-robot Four_Layers for one turn by joining the stored
     * prompt, recording, action, and best successful response rows. A turn
     * that has not been applied has no actions rows and returns an empty map.
     *
     * @return array<string, RobotFourLayers>
     */
    private function fourLayersForTurn(string $episodeId, int $turnNumber): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.robot_id, a.judgement_json, a.outcome_json, a.action_json, '
            . 'p.prompt_text, '
            . 'r.recorder_json, r.unknown, r.recorder_prompt_version '
            . 'FROM actions a '
            . 'LEFT JOIN prompts p ON p.episode_id = a.episode_id '
            . 'AND p.turn_number = a.turn_number AND p.robot_id = a.robot_id '
            . 'LEFT JOIN recordings r ON r.episode_id = a.episode_id '
            . 'AND r.turn_number = a.turn_number AND r.robot_id = a.robot_id '
            . 'WHERE a.episode_id = :episode_id AND a.turn_number = :turn_number'
        );
        $stmt->bindValue(':episode_id', $episodeId);
        $stmt->bindValue(':turn_number', $turnNumber, PDO::PARAM_INT);
        $stmt->execute();

        $robots = [];
        $actionRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($actionRows as $actionRow) {
            if (!is_array($actionRow)) {
                continue;
            }
            $robotId = (string) ($actionRow['robot_id'] ?? '');
            if ($robotId === '') {
                continue;
            }

            $recorderUnknown = (bool) ($actionRow['unknown'] ?? false);
            $recorderJson = (string) ($actionRow['recorder_json'] ?? '');
            $recorderPromptVersion = (string) ($actionRow['recorder_prompt_version'] ?? '');
            $reply = $this->bestReplyFor($episodeId, $turnNumber, $robotId);
            $noReply = $recorderUnknown && $reply === '';

            $reading = new Reading(
                action: $this->decodeAction($robotId, (string) ($actionRow['action_json'] ?? '')),
                unknown: $recorderUnknown,
                rawJson: $recorderJson,
                promptVersion: $recorderPromptVersion,
            );

            $robots[$robotId] = new RobotFourLayers(
                situationText: (string) ($actionRow['prompt_text'] ?? ''),
                reply: $reply,
                noReply: $noReply,
                reading: $reading,
                judgement: $this->decodeJudgement($robotId, (string) ($actionRow['judgement_json'] ?? '')),
                outcome: $this->decodeOutcome($robotId, (string) ($actionRow['outcome_json'] ?? '')),
            );
        }

        return $robots;
    }

    /**
     * The robot's reply text comes from its highest-numbered successful
     * attempt on that turn; a robot that never produced a reply has an empty
     * reply.
     */
    private function bestReplyFor(string $episodeId, int $turnNumber, string $robotId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT reply_text FROM responses '
            . 'WHERE episode_id = :episode_id AND turn_number = :turn_number '
            . 'AND robot_id = :robot_id AND unanswered = 0 '
            . 'ORDER BY attempt DESC LIMIT 1'
        );
        $stmt->bindValue(':episode_id', $episodeId);
        $stmt->bindValue(':turn_number', $turnNumber, PDO::PARAM_INT);
        $stmt->bindValue(':robot_id', $robotId);
        $stmt->execute();

        $value = $stmt->fetchColumn();
        if ($value === false) {
            return '';
        }

        return (string) $value;
    }

    // --- Internal: row hydration -----------------------------------------

    /**
     * @param array<string, mixed> $row
     */
    private function episodeRowFrom(array $row): EpisodeRow
    {
        $earthquakeStartTurn = $row['earthquake_start_turn'];

        return new EpisodeRow(
            id: (string) $row['id'],
            deviceId: (string) $row['device_id'],
            disaster: (string) $row['disaster'],
            seed: (int) $row['seed'],
            currentExpectedTurn: (int) $row['current_expected_turn'],
            earthquakeStartTurn: $earthquakeStartTurn === null ? null : (int) $earthquakeStartTurn,
            ended: (bool) (int) $row['ended'],
            createdAt: (string) $row['created_at'],
            lastActivityAt: (string) $row['last_activity_at'],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function turnRowFrom(array $row): TurnRow
    {
        $residentLine = $row['resident_line'];

        return new TurnRow(
            episodeId: (string) $row['episode_id'],
            turnNumber: (int) $row['turn_number'],
            state: TurnStateValue::from((string) $row['state']),
            attemptsA: (int) $row['attempts_a'],
            attemptsB: (int) $row['attempts_b'],
            noReplyA: (bool) (int) $row['no_reply_a'],
            noReplyB: (bool) (int) $row['no_reply_b'],
            residentLine: $residentLine === null ? null : (string) $residentLine,
            createdAt: (string) $row['created_at'],
        );
    }

    // --- Internal: JSON decode (mirrors the in-memory fake) --------------

    /**
     * Decode a stored action_json back into an engine Action. A missing or
     * malformed field decodes to operation 'unknown' (Requirement 11.3).
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

    // --- Internal: small helpers -----------------------------------------

    private function isRobotA(string $robotId): bool
    {
        return $robotId === 'robot_a';
    }

    /**
     * The datetime below which an episode's last activity is considered idle,
     * so its slot is released (Requirements 9.7–9.10). Computed in PHP and
     * bound as a parameter; never interpolated into SQL.
     */
    private function idleThreshold(): string
    {
        return date('Y-m-d H:i:s', time() - $this->config->idleSlotReleaseSeconds);
    }

    private function mintEpisodeId(): string
    {
        // A random 36-char id matching the CHAR(36) column; good enough for a
        // single-maintainer lab tool and never derived from user input.
        $hex = bin2hex(random_bytes(16));

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
