<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop\Repository;

use PDO;
use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\Values\Judgement;
use PoltergeistSwitch\Engine\Values\Outcome;
use PoltergeistSwitch\Engine\Values\ReportedSpeech;
use PoltergeistSwitch\Memory\Values\MemoryRow;
use PoltergeistSwitch\Memory\Values\MemorySnapshot;
use PoltergeistSwitch\Memory\Values\ReflectionClaim;
use PoltergeistSwitch\TurnLoop\Config;
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

        // heat_wave_start_turn is the only column the heat migration adds; it
        // is NULL for an earthquake episode and set only when a heat-wave
        // episode's heat is fixed (heat-wave Requirement 8.2). Written with the
        // insert so a heat-wave episode created with the heat already fixed
        // carries it from the start.
        $sql = 'INSERT INTO episodes '
            . '(id, device_id, disaster, seed, current_expected_turn, '
            . 'earthquake_start_turn, ended, created_at, last_activity_at, '
            . 'heat_wave_start_turn) '
            . 'VALUES '
            . '(:id, :device_id, :disaster, :seed, :current_expected_turn, '
            . ':earthquake_start_turn, :ended, :created_at, :last_activity_at, '
            . ':heat_wave_start_turn)';

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
        if ($episode->heatWaveStartTurn === null) {
            $stmt->bindValue(':heat_wave_start_turn', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':heat_wave_start_turn', $episode->heatWaveStartTurn, PDO::PARAM_INT);
        }
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

    public function setHeatWaveStartTurn(string $id, int $turn): void
    {
        // The heat-wave counterpart of setEarthquakeStartTurn: record the turn
        // the heat was fixed (heat-wave Requirement 8.2). Bound as a parameter,
        // never interpolated, like every write in this file.
        $stmt = $this->pdo->prepare(
            'UPDATE episodes SET heat_wave_start_turn = :turn WHERE id = :id'
        );
        $stmt->bindValue(':turn', $turn, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    }

    public function setDisaster(string $id, string $disaster): void
    {
        // Convert a waiting default episode to a heat-wave episode in place
        // (heat-wave Requirement 8.2). The disaster value is bound as a
        // parameter, never interpolated.
        $stmt = $this->pdo->prepare(
            'UPDATE episodes SET disaster = :disaster WHERE id = :id'
        );
        $stmt->bindValue(':disaster', $disaster);
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

    public function touchActivity(string $id, string $now): void
    {
        // Record activity so an actively-stepping episode keeps its running
        // slot (Requirement 9.10). $now is bound as a parameter, never
        // interpolated, matching the rest of this file.
        $stmt = $this->pdo->prepare(
            'UPDATE episodes SET last_activity_at = :now WHERE id = :id'
        );
        $stmt->bindValue(':now', $now);
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

    public function createTurn(string $episodeId, int $turnNumber, ?string $worldJson = null): void
    {
        // Idempotent create: INSERT IGNORE leaves an existing turn untouched so
        // its state and attempt counts are never reset by a repeated create.
        // $worldJson stores the per-turn World_State on the new row (turn 0's
        // initial calm world, Req 10.2); nullable so a turn created without a
        // world leaves world_json NULL. The INSERT IGNORE keeps it idempotent:
        // a repeat never overwrites an already-stored world.
        $sql = 'INSERT IGNORE INTO turns '
            . '(episode_id, turn_number, state, attempts_a, attempts_b, '
            . 'no_reply_a, no_reply_b, resident_line, world_json, created_at) '
            . 'VALUES '
            . '(:episode_id, :turn_number, :state, 0, 0, 0, 0, NULL, :world_json, :created_at)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':episode_id', $episodeId);
        $stmt->bindValue(':turn_number', $turnNumber, PDO::PARAM_INT);
        $stmt->bindValue(':state', TurnStateValue::waiting->value);
        if ($worldJson === null) {
            $stmt->bindValue(':world_json', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':world_json', $worldJson);
        }
        $stmt->bindValue(':created_at', $this->now());
        $stmt->execute();
    }

    public function setTurnWorldJson(string $episodeId, int $turnNumber, string $worldJson): void
    {
        // Unconditional overwrite (heat-wave Requirement 8.2): unlike the
        // fill-only backfill, this replaces whatever the turn already holds so
        // converting a waiting turn-0 episode to heat wave re-stores turn 0's
        // world as the heat-wave starting situation. A turn that does not exist
        // matches no row and is left untouched.
        $stmt = $this->pdo->prepare(
            'UPDATE turns SET world_json = :world_json '
            . 'WHERE episode_id = :episode_id AND turn_number = :turn_number'
        );
        $stmt->bindValue(':world_json', $worldJson);
        $stmt->bindValue(':episode_id', $episodeId);
        $stmt->bindValue(':turn_number', $turnNumber, PDO::PARAM_INT);
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

    public function findSuccessfulReply(string $episodeId, int $turnNumber, string $robotId): ?ReplyRecord
    {
        // The highest-numbered answered attempt wins (Requirements 5.2, 5.3).
        // The prompt text lives in the prompts table, one row per robot per
        // turn, joined here so the returned record carries the full prompt.
        $stmt = $this->pdo->prepare(
            'SELECT r.reply_text, r.input_tokens, r.output_tokens, r.seconds, '
            . 'r.cost, r.truncated, p.prompt_text '
            . 'FROM responses r '
            . "LEFT JOIN prompts p ON p.episode_id = r.episode_id "
            . "AND p.turn_number = r.turn_number AND p.robot_id = r.robot_id AND p.kind = 'turn' "
            . 'WHERE r.episode_id = :episode_id AND r.turn_number = :turn_number '
            . "AND r.robot_id = :robot_id AND r.unanswered = 0 AND r.kind = 'turn' "
            . 'ORDER BY r.attempt DESC LIMIT 1'
        );
        $stmt->bindValue(':episode_id', $episodeId);
        $stmt->bindValue(':turn_number', $turnNumber, PDO::PARAM_INT);
        $stmt->bindValue(':robot_id', $robotId);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return new ReplyRecord(
            prompt: (string) ($row['prompt_text'] ?? ''),
            reply: (string) ($row['reply_text'] ?? ''),
            inputTokens: (int) ($row['input_tokens'] ?? 0),
            outputTokens: (int) ($row['output_tokens'] ?? 0),
            seconds: (float) ($row['seconds'] ?? 0.0),
            cost: (float) ($row['cost'] ?? 0.0),
            unanswered: false,
            truncated: (bool) (int) ($row['truncated'] ?? 0),
        );
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

            // Carry the post-apply World_State onto the turn row (Req 10.1), in
            // the same transaction as the four-layer write so a stored applied
            // turn and its world are committed together. Only written when a
            // world is supplied; a null leaves world_json as it was.
            if ($rows->worldJson !== null) {
                $stmt = $this->pdo->prepare(
                    'UPDATE turns SET world_json = :world_json '
                    . 'WHERE episode_id = :episode_id AND turn_number = :turn_number'
                );
                $stmt->bindValue(':world_json', $rows->worldJson);
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

    // --- Replay -----------------------------------------------------------

    public function loadAppliedActionsForReplay(string $episodeId): array
    {
        // Driven by the turns table (state = applied), not the actions table,
        // so a turn that no robot acted in — the quiet turn 0 that
        // createEpisode applies (Requirement 4.3) — is still replayed, with an
        // empty action map. A LEFT JOIN brings in each applied turn's action
        // rows when it has them (Requirement 13.2).
        $stmt = $this->pdo->prepare(
            'SELECT t.turn_number, a.robot_id, a.action_json '
            . 'FROM turns t '
            . 'LEFT JOIN actions a ON a.episode_id = t.episode_id '
            . 'AND a.turn_number = t.turn_number '
            . 'WHERE t.episode_id = :episode_id AND t.state = :applied '
            . 'ORDER BY t.turn_number ASC, a.robot_id ASC'
        );
        $stmt->bindValue(':episode_id', $episodeId);
        $stmt->bindValue(':applied', TurnStateValue::applied->value);
        $stmt->execute();

        $byTurn = [];
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $turnNumber = (int) ($row['turn_number'] ?? 0);
            // Ensure every applied turn appears as a key, even one with no
            // action rows (the LEFT JOIN yields a null robot_id for it).
            if (!isset($byTurn[$turnNumber])) {
                $byTurn[$turnNumber] = [];
            }
            $robotId = $row['robot_id'] ?? null;
            if (!is_string($robotId) || $robotId === '') {
                continue;
            }
            $byTurn[$turnNumber][$robotId] = (string) ($row['action_json'] ?? '');
        }

        // Keyed by turn number in ascending order (Requirement 13.2).
        ksort($byTurn);

        return $byTurn;
    }

    // --- Per-turn World_State backfill (screens spec) --------------------

    public function loadTurnWorldJson(string $episodeId): array
    {
        // Read each turn's raw stored world_json without decoding, so a present
        // value can be left byte-for-byte unchanged by the backfill
        // (Requirement 10.7). A NULL column reads back as a null entry.
        $stmt = $this->pdo->prepare(
            'SELECT turn_number, world_json FROM turns '
            . 'WHERE episode_id = :episode_id ORDER BY turn_number ASC'
        );
        $stmt->bindValue(':episode_id', $episodeId);
        $stmt->execute();

        $byTurn = [];
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $turnNumber = (int) ($row['turn_number'] ?? 0);
            $worldJson = $row['world_json'] ?? null;
            $byTurn[$turnNumber] = is_string($worldJson) ? $worldJson : null;
        }

        ksort($byTurn);

        return $byTurn;
    }

    public function fillMissingWorldJson(string $episodeId, array $worldByTurn): void
    {
        // All-or-nothing (Requirement 10.8): write every missing turn inside one
        // transaction, commit only after all succeed, roll back on any error so
        // no partial World_State persists. The WHERE guard `world_json IS NULL`
        // makes each write fill-only: a turn that already holds a value is left
        // byte-for-byte unchanged (Requirement 10.7), and a repeated backfill is
        // idempotent.
        if ($worldByTurn === []) {
            return;
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE turns SET world_json = :world_json '
                . 'WHERE episode_id = :episode_id AND turn_number = :turn_number '
                . 'AND world_json IS NULL'
            );
            foreach ($worldByTurn as $turnNumber => $worldJson) {
                $stmt->bindValue(':world_json', $worldJson);
                $stmt->bindValue(':episode_id', $episodeId);
                $stmt->bindValue(':turn_number', (int) $turnNumber, PDO::PARAM_INT);
                $stmt->execute();
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw new RuntimeException('fillMissingWorldJson failed; rolled back', 0, $e);
        }
    }

    // --- Ended-episode list (screens spec) -------------------------------

    public function listEndedEpisodes(int $limit): array
    {
        // Only ended episodes, newest-created first with id descending for
        // ties (screens spec Requirements 11.1, 11.2). The limit is bound as a
        // parameter, never interpolated; the caller has already validated it to
        // 1–100 (Requirement 11.6), and it is cast to int here defensively.
        $sql = 'SELECT * FROM episodes '
            . 'WHERE ended = 1 '
            . 'ORDER BY created_at DESC, id DESC '
            . 'LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $episodes = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $episodes[] = $this->episodeRowFrom($row);
        }

        return $episodes;
    }

    public function listEndedEpisodesByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('strval', $ids)));
        if ($ids === []) {
            return [];
        }

        $holders = [];
        foreach ($ids as $i => $_) {
            $holders[] = ':id' . $i;
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM episodes WHERE ended = 1 AND id IN (' . implode(', ', $holders) . ')'
        );
        foreach ($ids as $i => $id) {
            $stmt->bindValue(':id' . $i, $id);
        }
        $stmt->execute();

        $byId = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $episode = $this->episodeRowFrom($row);
                $byId[$episode->id] = $episode;
            }
        }

        // Keep the caller's order.
        $episodes = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $episodes[] = $byId[$id];
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
        $sql = 'SELECT * FROM episodes WHERE ended = 1 AND device_id = :device';
        if ($sinceCreatedAt !== null) {
            $sql .= ' AND created_at > :since';
        }
        $excludeIds = array_values(array_unique(array_map('strval', $excludeIds)));
        $holders = [];
        foreach ($excludeIds as $i => $_) {
            $holders[] = ':ex' . $i;
        }
        if ($holders !== []) {
            $sql .= ' AND id NOT IN (' . implode(', ', $holders) . ')';
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':device', $deviceId);
        if ($sinceCreatedAt !== null) {
            $stmt->bindValue(':since', $sinceCreatedAt);
        }
        foreach ($excludeIds as $i => $id) {
            $stmt->bindValue(':ex' . $i, $id);
        }
        $stmt->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
        $stmt->execute();

        $episodes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $episodes[] = $this->episodeRowFrom($row);
            }
        }

        return $episodes;
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
        // Turn prompts are tagged kind = 'turn'; a reflection prompt is tagged
        // kind = 'reflection' with the sentinel turn_number -1, so the two
        // never collide and no turn read picks up a reflection row (robot-memory
        // Requirement 2.5). The kind comes from the PromptReplyRow DTO, whose
        // only values are the fixed literals 'turn'/'reflection'.
        $sql = 'INSERT INTO prompts '
            . "(episode_id, turn_number, robot_id, prompt_text, situation_text_version, kind) "
            . 'VALUES (:episode_id, :turn_number, :robot_id, :prompt_text, :version, :kind) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'prompt_text = VALUES(prompt_text), '
            . 'situation_text_version = VALUES(situation_text_version)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':episode_id', $row->episodeId);
        $stmt->bindValue(':turn_number', $row->turnNumber, PDO::PARAM_INT);
        $stmt->bindValue(':robot_id', $row->robotId);
        $stmt->bindValue(':prompt_text', $row->reply->prompt);
        $stmt->bindValue(':version', $row->situationTextVersion);
        $stmt->bindValue(':kind', $row->kind);
        $stmt->execute();
    }

    private function upsertResponse(PromptReplyRow $row): void
    {
        // Turn responses are tagged kind = 'turn'; a reflection response is
        // tagged kind = 'reflection' with the sentinel turn_number -1, so the
        // two never collide and no turn read picks up a reflection row
        // (robot-memory Requirement 2.5). The kind comes from the
        // PromptReplyRow DTO's fixed 'turn'/'reflection' literals.
        $sql = 'INSERT INTO responses '
            . "(episode_id, turn_number, robot_id, attempt, reply_text, "
            . 'input_tokens, output_tokens, seconds, cost, unanswered, truncated, failure_reason, kind) '
            . 'VALUES '
            . "(:episode_id, :turn_number, :robot_id, :attempt, :reply_text, "
            . ':input_tokens, :output_tokens, :seconds, :cost, :unanswered, :truncated, :failure_reason, :kind) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'reply_text = VALUES(reply_text), '
            . 'input_tokens = VALUES(input_tokens), '
            . 'output_tokens = VALUES(output_tokens), '
            . 'seconds = VALUES(seconds), '
            . 'cost = VALUES(cost), '
            . 'unanswered = VALUES(unanswered), '
            . 'truncated = VALUES(truncated), '
            . 'failure_reason = VALUES(failure_reason)';

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
        if ($row->reply->failureReason === null) {
            $stmt->bindValue(':failure_reason', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':failure_reason', $row->reply->failureReason);
        }
        $stmt->bindValue(':kind', $row->kind);
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
        // Tagged kind = 'turn' so a reflection prompt never collides with a
        // turn read (robot-memory Requirement 2.5). INSERT ... ON DUPLICATE KEY
        // keeps the guard idempotent should the prompt row already exist from
        // the ask phase.
        $sql = 'INSERT INTO prompts '
            . "(episode_id, turn_number, robot_id, prompt_text, situation_text_version, kind) "
            . "VALUES (:episode_id, :turn_number, :robot_id, :prompt_text, :version, 'turn') "
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
            . '(episode_id, turn_number, robot_id, recorder_json, unknown, recorder_prompt_version, failure_reason) '
            . 'VALUES (:episode_id, :turn_number, :robot_id, :recorder_json, :unknown, :version, :failure_reason)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':episode_id', $episodeId);
        $stmt->bindValue(':turn_number', $turnNumber, PDO::PARAM_INT);
        $stmt->bindValue(':robot_id', $robotId);
        $stmt->bindValue(':recorder_json', $row->recorderJson);
        $stmt->bindValue(':unknown', $row->recorderUnknown ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':version', $row->recorderPromptVersion);
        if ($row->recorderFailureReason === null) {
            $stmt->bindValue(':failure_reason', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':failure_reason', $row->recorderFailureReason);
        }
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
            . 'r.recorder_json, r.unknown, r.recorder_prompt_version, r.failure_reason '
            . 'FROM actions a '
            . "LEFT JOIN prompts p ON p.episode_id = a.episode_id "
            . "AND p.turn_number = a.turn_number AND p.robot_id = a.robot_id AND p.kind = 'turn' "
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
            $recorderFailureReason = $this->nullableString($actionRow, 'failure_reason');
            $reply = $this->bestReplyFor($episodeId, $turnNumber, $robotId);
            $noReply = $recorderUnknown && $reply === '';
            // When the robot produced no successful reply, surface the reason
            // its last attempt failed (a short machine token, never a provider
            // message); an answered robot has none.
            $replyFailureReason = $reply === ''
                ? $this->replyFailureReasonFor($episodeId, $turnNumber, $robotId)
                : null;

            $reading = new Reading(
                action: $this->decodeAction($robotId, (string) ($actionRow['action_json'] ?? '')),
                unknown: $recorderUnknown,
                rawJson: $recorderJson,
                promptVersion: $recorderPromptVersion,
                failureReason: $recorderFailureReason,
            );

            $robots[$robotId] = new RobotFourLayers(
                situationText: (string) ($actionRow['prompt_text'] ?? ''),
                reply: $reply,
                noReply: $noReply,
                reading: $reading,
                judgement: $this->decodeJudgement($robotId, (string) ($actionRow['judgement_json'] ?? '')),
                outcome: $this->decodeOutcome($robotId, (string) ($actionRow['outcome_json'] ?? '')),
                recorderFailureReason: $recorderFailureReason,
                replyFailureReason: $replyFailureReason,
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
            . "AND robot_id = :robot_id AND unanswered = 0 AND kind = 'turn' "
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

    /**
     * The failure reason of the robot's highest-numbered attempt on that turn
     * when it never produced a successful reply, or null when it has none. This
     * surfaces why an unanswered/no-reply robot has no reply (a short machine
     * token, never a provider message).
     */
    private function replyFailureReasonFor(string $episodeId, int $turnNumber, string $robotId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT failure_reason FROM responses '
            . 'WHERE episode_id = :episode_id AND turn_number = :turn_number '
            . "AND robot_id = :robot_id AND kind = 'turn' "
            . 'ORDER BY attempt DESC LIMIT 1'
        );
        $stmt->bindValue(':episode_id', $episodeId);
        $stmt->bindValue(':turn_number', $turnNumber, PDO::PARAM_INT);
        $stmt->bindValue(':robot_id', $robotId);
        $stmt->execute();

        $value = $stmt->fetchColumn();
        if ($value === false || $value === null) {
            return null;
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
        // heat_wave_start_turn may be absent when read against a schema that
        // predates the heat migration; a missing or NULL value reads back as
        // null so an earthquake row is unchanged (heat-wave Requirement 8.2).
        $heatWaveStartTurn = $row['heat_wave_start_turn'] ?? null;

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
            heatWaveStartTurn: $heatWaveStartTurn === null ? null : (int) $heatWaveStartTurn,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function turnRowFrom(array $row): TurnRow
    {
        $residentLine = $row['resident_line'];
        // world_json is nullable and may be absent on an older schema read;
        // a missing or null value reads back as null (Req 10.1, 10.2).
        $worldJson = $row['world_json'] ?? null;

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
            worldJson: $worldJson === null ? null : (string) $worldJson,
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

    // =====================================================================
    // Robot-memory additions (robot-memory spec, Requirement 8.1)
    //
    // The PDO/MySQL implementation of the memory methods. It mirrors the
    // in-memory fake's semantics so ReflectService and MemoryService behave
    // identically against either store, and matches the schema in
    // sql/2026-11-09_add_memory.sql exactly (Requirement 8.1).
    //
    // The server is a MySQL 5.7-class engine: the idempotency and cap/retire
    // designs use InnoDB row locks (SELECT ... FOR UPDATE) and NEVER
    // SKIP LOCKED (design, "Reflection idempotency and the MySQL 5.7 locking
    // design"). Every query uses prepared statements with bound parameters; no
    // value is ever interpolated into SQL. Snapshots are stored as JSON text
    // and decoded with json_decode(..., flags: JSON_THROW_ON_ERROR); a
    // malformed snapshot degrades to an empty MemorySnapshot (PHP 8.2 has no
    // json_validate()).
    //
    // Reflection prompts/responses are written with kind = 'reflection' and the
    // sentinel turn number -1 (by ReflectService via savePromptAndReply); every
    // turn read above filters on kind = 'turn' and never queries turn_number =
    // -1, so a reflection row never collides with a turn read (Requirement 2.5).
    // =====================================================================

    // The per-(robot, disaster, generation) active cap (Requirements 3.3, 3.4).
    private const MEMORY_ACTIVE_CAP = 6;

    // A claim older than this many seconds is stale and re-claimable; the
    // per-request limit is well under it, so a fresh claim cannot still be
    // running (Requirement 2.10).
    private const REFLECTION_STALE_SECONDS = 120;

    // --- Generations -----------------------------------------------------

    public function currentGeneration(): int
    {
        // The single memory_generation row (id = 1) holds the current
        // generation (Requirement 4.1). A fresh install is seeded with
        // generation 1 by the migration; a missing row degrades to 1.
        $stmt = $this->pdo->prepare(
            'SELECT generation FROM memory_generation WHERE id = 1'
        );
        $stmt->execute();

        $value = $stmt->fetchColumn();
        if ($value === false) {
            return 1;
        }

        return (int) $value;
    }

    public function advanceGeneration(): int
    {
        // Move the current generation forward by one in a short transaction
        // (Requirement 4.2). No memory row is ever deleted; a reset only
        // advances the generation so later episodes see none of the earlier
        // generation's memories. The locking read serialises two concurrent
        // resets so the generation advances by exactly one each.
        $this->pdo->beginTransaction();
        try {
            $read = $this->pdo->prepare(
                'SELECT generation FROM memory_generation WHERE id = 1 FOR UPDATE'
            );
            $read->execute();
            $current = $read->fetchColumn();
            $next = ($current === false ? 1 : (int) $current) + 1;

            // Upsert so a missing seed row is created rather than silently
            // left behind; the migration seeds it, this is defensive.
            $write = $this->pdo->prepare(
                'INSERT INTO memory_generation (id, generation, updated_at) '
                . 'VALUES (1, :generation, :now) '
                . 'ON DUPLICATE KEY UPDATE generation = VALUES(generation), '
                . 'updated_at = VALUES(updated_at)'
            );
            $write->bindValue(':generation', $next, PDO::PARAM_INT);
            $write->bindValue(':now', $this->now());
            $write->execute();

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw new RuntimeException('advanceGeneration failed; rolled back', 0, $e);
        }

        return $next;
    }

    public function deviceGeneration(string $deviceId): int
    {
        // 1 + the number of Reset_Points recorded for the Device (public-lab
        // Req 2.2). A Device that never reset is in generation 1.
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM device_resets WHERE device_id = :device'
        );
        $stmt->bindValue(':device', $deviceId);
        $stmt->execute();

        return 1 + (int) $stmt->fetchColumn();
    }

    public function recordDeviceReset(string $deviceId, string $now): int
    {
        // Append exactly one row; nothing is updated or deleted (Req 2.10).
        // The new generation is read back in the same transaction.
        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO device_resets (device_id, reset_at) VALUES (:device, :reset_at)'
            );
            $insert->bindValue(':device', $deviceId);
            $insert->bindValue(':reset_at', $now);
            $insert->execute();

            $generation = $this->deviceGeneration($deviceId);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw new RuntimeException('recordDeviceReset failed; rolled back', 0, $e);
        }

        return $generation;
    }

    public function latestDeviceResetAt(string $deviceId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT MAX(reset_at) FROM device_resets WHERE device_id = :device'
        );
        $stmt->bindValue(':device', $deviceId);
        $stmt->execute();

        $value = $stmt->fetchColumn();
        if ($value === false || $value === null) {
            return null;
        }

        return (string) $value;
    }

    // --- Internal: memory owner predicate --------------------------------

    private function deviceClause(?string $deviceId): string
    {
        // A legacy row (null owner) is only ever written by a null-device
        // MemoryRow; keep its cap group separate with IS NULL.
        return $deviceId === null ? 'device_id IS NULL' : 'device_id = :device';
    }

    private function bindDevice(\PDOStatement $stmt, ?string $deviceId): void
    {
        if ($deviceId !== null) {
            $stmt->bindValue(':device', $deviceId);
        }
    }

    private function bindDeviceValue(\PDOStatement $stmt, ?string $deviceId): void
    {
        if ($deviceId === null) {
            $stmt->bindValue(':device', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':device', $deviceId);
        }
    }

    // --- Per-episode snapshot + generation -------------------------------

    public function storeEpisodeMemory(
        string $episodeId,
        int $generation,
        MemorySnapshot $robotA,
        MemorySnapshot $robotB
    ): void {
        // Freeze the generation and each robot's verbatim snapshot onto the
        // episode row so a rebuild reads the exact bodies without re-querying
        // the live `memories` table (Requirements 6.1, 6.6). The snapshot is a
        // JSON array of strings, oldest first; an empty snapshot is stored as
        // '[]' (never NULL) so a post-feature episode is never mistaken for a
        // pre-feature one.
        $stmt = $this->pdo->prepare(
            'UPDATE episodes SET memory_generation = :generation, '
            . 'robot_a_memory_snapshot = :snapshot_a, '
            . 'robot_b_memory_snapshot = :snapshot_b '
            . 'WHERE id = :id'
        );
        $stmt->bindValue(':generation', $generation, PDO::PARAM_INT);
        $stmt->bindValue(':snapshot_a', $this->encodeSnapshot($robotA));
        $stmt->bindValue(':snapshot_b', $this->encodeSnapshot($robotB));
        $stmt->bindValue(':id', $episodeId);
        $stmt->execute();
    }

    public function episodeGeneration(string $episodeId): int
    {
        // A running episode keeps its own stored generation even when a reset
        // advances the current generation (Requirement 6.1). A missing episode
        // or a column left at its default degrades to 1.
        $stmt = $this->pdo->prepare(
            'SELECT memory_generation FROM episodes WHERE id = :id'
        );
        $stmt->bindValue(':id', $episodeId);
        $stmt->execute();

        $value = $stmt->fetchColumn();
        if ($value === false) {
            return 1;
        }

        return (int) $value;
    }

    public function episodeSnapshot(string $episodeId, string $robotId): MemorySnapshot
    {
        // Read a robot's frozen verbatim snapshot off the episode row
        // (Requirement 6.6). A pre-feature episode whose column is NULL, or a
        // malformed stored snapshot, yields an empty MemorySnapshot.
        $column = $this->isRobotA($robotId)
            ? 'robot_a_memory_snapshot'
            : 'robot_b_memory_snapshot';

        // The column name comes from a fixed internal whitelist above, never
        // from caller input, so interpolating it here is safe; the id is bound.
        $stmt = $this->pdo->prepare(
            'SELECT ' . $column . ' FROM episodes WHERE id = :id'
        );
        $stmt->bindValue(':id', $episodeId);
        $stmt->execute();

        $value = $stmt->fetchColumn();
        if ($value === false || $value === null) {
            return new MemorySnapshot([]);
        }

        return $this->decodeSnapshot((string) $value);
    }

    public function episodeHasSnapshot(string $episodeId): bool
    {
        // A post-feature episode writes both snapshot columns at creation (an
        // empty snapshot as '[]', never NULL), so a non-NULL robot_a snapshot
        // marks it reflectable; a pre-feature episode keeps the column NULL and
        // is not reflectable (Requirement 2.2). An unknown episode (no row)
        // yields false — findEpisode already answers 404 ahead of this check.
        $stmt = $this->pdo->prepare(
            'SELECT robot_a_memory_snapshot FROM episodes WHERE id = :id'
        );
        $stmt->bindValue(':id', $episodeId);
        $stmt->execute();

        $value = $stmt->fetchColumn();

        return $value !== false && $value !== null;
    }

    // --- Memories: read --------------------------------------------------

    public function activeMemories(string $deviceId, string $robotId, int $generation, int $cap): MemorySnapshot
    {
        // Active memories of (robot, generation) across ALL disasters, oldest
        // first by finish-order sequence (id ASC), capped at $cap (Requirements
        // 3.8, 6.1). Retired rows and other-generation rows are excluded by the
        // status/generation predicates, served by idx_snapshot. $cap is bound,
        // never interpolated, and floored at 0 defensively.
        $cap = $cap < 0 ? 0 : $cap;
        if ($cap === 0) {
            return new MemorySnapshot([]);
        }

        $stmt = $this->pdo->prepare(
            'SELECT body FROM memories '
            . 'WHERE device_id = :device AND robot_id = :robot_id AND generation = :generation '
            . "AND status = 'active' "
            . 'ORDER BY id ASC '
            . 'LIMIT :cap'
        );
        $stmt->bindValue(':device', $deviceId);
        $stmt->bindValue(':robot_id', $robotId);
        $stmt->bindValue(':generation', $generation, PDO::PARAM_INT);
        $stmt->bindValue(':cap', $cap, PDO::PARAM_INT);
        $stmt->execute();

        $bodies = [];
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $bodies[] = (string) ($row['body'] ?? '');
        }

        return new MemorySnapshot($bodies);
    }

    public function activeCount(string $deviceId, string $robotId, int $generation, string $disaster): int
    {
        // How many active memories (robot, generation) has for one disaster
        // (Requirement 3.3), excluding retired and other-generation rows,
        // served by idx_active_pick.
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM memories '
            . 'WHERE device_id = :device AND robot_id = :robot_id AND disaster = :disaster '
            . "AND generation = :generation AND status = 'active'"
        );
        $stmt->bindValue(':device', $deviceId);
        $stmt->bindValue(':robot_id', $robotId);
        $stmt->bindValue(':disaster', $disaster);
        $stmt->bindValue(':generation', $generation, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    // --- Memories: write with cap + retirement, atomic -------------------

    public function appendMemory(MemoryRow $memory): void
    {
        // Store one memory and retire oldest-active rows for its
        // (device, robot, disaster, generation) triple down to at most 6 active
        // (Requirements 3.3, 3.4, 8.4). The whole operation is ONE transaction
        // that first takes a locking read over that triple's active rows, so
        // two interleaved commits for the same triple — even from two different
        // episodes — serialise and never leave more than 6 active. Idempotent
        // per (sourceEpisodeId, robotId) via uq_source: a repeat leaves the
        // stored records unchanged and creates no duplicate (Requirement 8.5).
        $this->pdo->beginTransaction();
        try {
            // Idempotency guard: a store already exists for this source episode
            // + robot, so do nothing (Requirement 8.5). Checked inside the
            // transaction so a concurrent duplicate serialises behind the first.
            if ($this->memoryExistsForSource($memory->sourceEpisodeId, $memory->robotId)) {
                $this->pdo->commit();

                return;
            }

            // 1. SERIALISE: lock this triple's active rows so concurrent commits
            //    for the same (robot, disaster, generation) cannot both count
            //    from a stale snapshot (design step 1).
            $lock = $this->pdo->prepare(
                'SELECT id FROM memories '
                . 'WHERE ' . $this->deviceClause($memory->deviceId) . ' AND robot_id = :robot_id AND disaster = :disaster '
                . "AND generation = :generation AND status = 'active' "
                . 'FOR UPDATE'
            );
            $this->bindDevice($lock, $memory->deviceId);
            $lock->bindValue(':robot_id', $memory->robotId);
            $lock->bindValue(':disaster', $memory->disaster);
            $lock->bindValue(':generation', $memory->generation, PDO::PARAM_INT);
            $lock->execute();
            $lock->fetchAll();

            // 2. Append the new memory. The auto-increment id doubles as the
            //    finish-order seq (Requirement 3.6), so a later commit gets a
            //    higher id and oldest-first is id ASC.
            $insert = $this->pdo->prepare(
                'INSERT INTO memories '
                . '(device_id, robot_id, generation, disaster, source_episode_id, body, status, created_at) '
                . "VALUES (:device, :robot_id, :generation, :disaster, :source_episode_id, :body, 'active', :created_at)"
            );
            $this->bindDeviceValue($insert, $memory->deviceId);
            $insert->bindValue(':robot_id', $memory->robotId);
            $insert->bindValue(':generation', $memory->generation, PDO::PARAM_INT);
            $insert->bindValue(':disaster', $memory->disaster);
            $insert->bindValue(':source_episode_id', $memory->sourceEpisodeId);
            $insert->bindValue(':body', $memory->body);
            $insert->bindValue(':created_at', $memory->createdAt);
            $insert->execute();

            // 3. Retire every active row older than the 6 newest (id ASC,
            //    OFFSET 6) by flipping status to 'retired'; the row is kept, not
            //    deleted (Requirements 3.4, 3.5). In the one-at-a-time case the
            //    surplus is exactly the single oldest active row. The
            //    derived-table wrapping is the MySQL 5.7-compatible idiom for
            //    updating a table also selected from. LIMIT/OFFSET are fixed
            //    internal constants, never caller input.
            $retire = $this->pdo->prepare(
                "UPDATE memories SET status = 'retired', retired_at = :retired_at "
                . 'WHERE id IN ( '
                . 'SELECT id FROM ( '
                . 'SELECT id FROM memories '
                . 'WHERE ' . $this->deviceClause($memory->deviceId) . ' AND robot_id = :robot_id AND disaster = :disaster '
                . "AND generation = :generation AND status = 'active' "
                . 'ORDER BY id DESC '
                . 'LIMIT 1000 OFFSET ' . self::MEMORY_ACTIVE_CAP . ' '
                . ') AS surplus '
                . ')'
            );
            $retire->bindValue(':retired_at', $this->now());
            $this->bindDevice($retire, $memory->deviceId);
            $retire->bindValue(':robot_id', $memory->robotId);
            $retire->bindValue(':disaster', $memory->disaster);
            $retire->bindValue(':generation', $memory->generation, PDO::PARAM_INT);
            $retire->execute();

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw new RuntimeException('appendMemory failed; rolled back', 0, $e);
        }
    }

    // --- Reflection slot claim/commit ------------------------------------

    public function claimReflectionSlot(string $episodeId, string $robotId): ReflectionClaim
    {
        // Claim the per-episode-per-robot reflection slot in one short
        // transaction, releasing the row lock at commit long before any AI call
        // (Requirements 2.8, 2.9, 2.10; design "Reflection idempotency"). The
        // INSERT ... ON DUPLICATE KEY ensures the row exists so the FOR UPDATE
        // has something to lock; the locked status then decides the outcome.
        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO reflections (episode_id, robot_id, status, claimed_at) '
                . "VALUES (:episode_id, :robot_id, 'in_progress', :now) "
                . 'ON DUPLICATE KEY UPDATE episode_id = episode_id'
            );
            $insert->bindValue(':episode_id', $episodeId);
            $insert->bindValue(':robot_id', $robotId);
            $insert->bindValue(':now', $this->now());
            $insert->execute();
            // rowCount() is 1 for a fresh insert, 0 for the no-op on an existing
            // row (the ON DUPLICATE KEY assigns the same value), so this tells
            // us whether THIS transaction created the row.
            $created = $insert->rowCount() === 1;

            $read = $this->pdo->prepare(
                'SELECT status, answered_memory_id, '
                . '(claimed_at < (NOW() - INTERVAL :stale SECOND)) AS stale '
                . 'FROM reflections '
                . 'WHERE episode_id = :episode_id AND robot_id = :robot_id '
                . 'FOR UPDATE'
            );
            $read->bindValue(':stale', self::REFLECTION_STALE_SECONDS, PDO::PARAM_INT);
            $read->bindValue(':episode_id', $episodeId);
            $read->bindValue(':robot_id', $robotId);
            $read->execute();
            $row = $read->fetch(PDO::FETCH_ASSOC);

            $status = is_array($row) ? (string) ($row['status'] ?? '') : '';
            $stale = is_array($row) && (int) ($row['stale'] ?? 0) === 1;

            if ($status === 'answered') {
                // The robot already answered; the caller reuses the stored body
                // and makes no AI call (Requirement 2.9). The body is read from
                // the linked memory inside this same transaction.
                $answeredId = isset($row['answered_memory_id']) && $row['answered_memory_id'] !== null
                    ? (int) $row['answered_memory_id']
                    : null;
                $body = $answeredId !== null ? $this->memoryBodyById($answeredId) : null;
                $this->pdo->commit();

                return ReflectionClaim::reuse($body ?? '');
            }

            if ($created) {
                // This transaction created the row: we own a fresh claim
                // (Requirement 2.8). Proceed to the AI call after commit.
                $this->pdo->commit();

                return ReflectionClaim::claimed();
            }

            if ($status === 'failed' || ($status === 'in_progress' && $stale)) {
                // A failed slot, or a stale in-progress whose owner died, is
                // re-claimable: refresh it to a fresh in-progress and proceed
                // (Requirement 2.10).
                $reclaim = $this->pdo->prepare(
                    "UPDATE reflections SET status = 'in_progress', claimed_at = :now, reason = NULL "
                    . 'WHERE episode_id = :episode_id AND robot_id = :robot_id'
                );
                $reclaim->bindValue(':now', $this->now());
                $reclaim->bindValue(':episode_id', $episodeId);
                $reclaim->bindValue(':robot_id', $robotId);
                $reclaim->execute();
                $this->pdo->commit();

                return ReflectionClaim::claimed();
            }

            // A fresh in-progress claim exists and we did not create it: a
            // concurrent caller owns it, so skip this robot (Requirement 2.9).
            $this->pdo->commit();

            return ReflectionClaim::skip();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw new RuntimeException('claimReflectionSlot failed; rolled back', 0, $e);
        }
    }

    public function commitReflectionAnswer(string $episodeId, string $robotId, MemoryRow $memory): void
    {
        // Commit a non-empty reflection answer in one short transaction
        // (Requirements 2.6, 8.4): append the memory with the cap/retirement
        // applied in the SAME transaction (serialised per
        // robot/disaster/generation by the locking read), then mark the slot
        // 'answered' and link the stored memory. Idempotent per source episode
        // + robot, so a repeated commit of an already-stored answer creates no
        // duplicate memory.
        $this->pdo->beginTransaction();
        try {
            $answeredMemoryId = null;

            if ($this->memoryExistsForSource($memory->sourceEpisodeId, $memory->robotId)) {
                // Already stored (a repeat commit): reuse the existing memory's
                // id for the slot link and append nothing (Requirement 8.5).
                $answeredMemoryId = $this->memoryIdForSource($memory->sourceEpisodeId, $memory->robotId);
            } else {
                // 1. SERIALISE this triple's active rows (design step 1).
                $lock = $this->pdo->prepare(
                    'SELECT id FROM memories '
                    . 'WHERE ' . $this->deviceClause($memory->deviceId) . ' AND robot_id = :robot_id AND disaster = :disaster '
                    . "AND generation = :generation AND status = 'active' "
                    . 'FOR UPDATE'
                );
                $this->bindDevice($lock, $memory->deviceId);
                $lock->bindValue(':robot_id', $memory->robotId);
                $lock->bindValue(':disaster', $memory->disaster);
                $lock->bindValue(':generation', $memory->generation, PDO::PARAM_INT);
                $lock->execute();
                $lock->fetchAll();

                // 2. Append the new memory (finish-order seq = auto-increment id).
                $insert = $this->pdo->prepare(
                    'INSERT INTO memories '
                    . '(device_id, robot_id, generation, disaster, source_episode_id, body, status, created_at) '
                    . "VALUES (:device, :robot_id, :generation, :disaster, :source_episode_id, :body, 'active', :created_at)"
                );
                $this->bindDeviceValue($insert, $memory->deviceId);
                $insert->bindValue(':robot_id', $memory->robotId);
                $insert->bindValue(':generation', $memory->generation, PDO::PARAM_INT);
                $insert->bindValue(':disaster', $memory->disaster);
                $insert->bindValue(':source_episode_id', $memory->sourceEpisodeId);
                $insert->bindValue(':body', $memory->body);
                $insert->bindValue(':created_at', $memory->createdAt);
                $insert->execute();
                $answeredMemoryId = (int) $this->pdo->lastInsertId();

                // 3. Retire oldest-active rows past the 6 newest (id ASC,
                //    OFFSET 6); kept, not deleted (Requirements 3.4, 3.5).
                $retire = $this->pdo->prepare(
                    "UPDATE memories SET status = 'retired', retired_at = :retired_at "
                    . 'WHERE id IN ( '
                    . 'SELECT id FROM ( '
                    . 'SELECT id FROM memories '
                    . 'WHERE ' . $this->deviceClause($memory->deviceId) . ' AND robot_id = :robot_id AND disaster = :disaster '
                    . "AND generation = :generation AND status = 'active' "
                    . 'ORDER BY id DESC '
                    . 'LIMIT 1000 OFFSET ' . self::MEMORY_ACTIVE_CAP . ' '
                    . ') AS surplus '
                    . ')'
                );
                $retire->bindValue(':retired_at', $this->now());
                $this->bindDevice($retire, $memory->deviceId);
            $this->bindDevice($retire, $memory->deviceId);
                $retire->bindValue(':robot_id', $memory->robotId);
                $retire->bindValue(':disaster', $memory->disaster);
                $retire->bindValue(':generation', $memory->generation, PDO::PARAM_INT);
                $retire->execute();
            }

            // 4. Mark the slot answered so repeat/concurrent calls reuse it.
            $slot = $this->pdo->prepare(
                "UPDATE reflections SET status = 'answered', answered_memory_id = :memory_id, reason = NULL "
                . 'WHERE episode_id = :episode_id AND robot_id = :robot_id'
            );
            if ($answeredMemoryId === null) {
                $slot->bindValue(':memory_id', null, PDO::PARAM_NULL);
            } else {
                $slot->bindValue(':memory_id', $answeredMemoryId, PDO::PARAM_INT);
            }
            $slot->bindValue(':episode_id', $episodeId);
            $slot->bindValue(':robot_id', $robotId);
            $slot->execute();

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw new RuntimeException('commitReflectionAnswer failed; rolled back', 0, $e);
        }
    }

    public function commitReflectionFailure(string $episodeId, string $robotId, string $reason): void
    {
        // Commit a reflection failure in one short transaction (Requirement
        // 2.10): add no memory, record the short machine reason, and release
        // the slot to 'failed' so a later reflect call may re-claim and ask
        // that robot again. The slot row already exists (the claim created it).
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE reflections SET status = 'failed', reason = :reason "
                . 'WHERE episode_id = :episode_id AND robot_id = :robot_id'
            );
            $stmt->bindValue(':reason', $reason);
            $stmt->bindValue(':episode_id', $episodeId);
            $stmt->bindValue(':robot_id', $robotId);
            $stmt->execute();

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw new RuntimeException('commitReflectionFailure failed; rolled back', 0, $e);
        }
    }

    public function reflectionAnswer(string $episodeId, string $robotId): ?MemoryRow
    {
        // The memory stored for this episode+robot when the slot is 'answered',
        // so the caller reuses it verbatim without a new AI call (Requirements
        // 2.8, 2.9); null when there is no stored answer. Joined from the slot's
        // answered_memory_id to the memories row.
        $stmt = $this->pdo->prepare(
            'SELECT m.robot_id, m.generation, m.disaster, m.source_episode_id, '
            . 'm.body, m.status, m.created_at, m.id AS seq, m.retired_at '
            . 'FROM reflections r '
            . 'INNER JOIN memories m ON m.id = r.answered_memory_id '
            . "WHERE r.episode_id = :episode_id AND r.robot_id = :robot_id "
            . "AND r.status = 'answered'"
        );
        $stmt->bindValue(':episode_id', $episodeId);
        $stmt->bindValue(':robot_id', $robotId);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return new MemoryRow(
            robotId: (string) ($row['robot_id'] ?? ''),
            generation: (int) ($row['generation'] ?? 0),
            disaster: (string) ($row['disaster'] ?? ''),
            sourceEpisodeId: (string) ($row['source_episode_id'] ?? ''),
            body: (string) ($row['body'] ?? ''),
            status: (string) ($row['status'] ?? ''),
            createdAt: (string) ($row['created_at'] ?? ''),
            seq: isset($row['seq']) && $row['seq'] !== null ? (int) $row['seq'] : null,
            retiredAt: isset($row['retired_at']) && $row['retired_at'] !== null
                ? (string) $row['retired_at']
                : null,
        );
    }

    // --- Internal: memory helpers ----------------------------------------

    /**
     * True when a memory already exists for this source episode + robot, which
     * marks the store as already done (idempotency guard, Requirement 8.5).
     */
    private function memoryExistsForSource(string $sourceEpisodeId, string $robotId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM memories '
            . 'WHERE source_episode_id = :source_episode_id AND robot_id = :robot_id'
        );
        $stmt->bindValue(':source_episode_id', $sourceEpisodeId);
        $stmt->bindValue(':robot_id', $robotId);
        $stmt->execute();

        return ((int) $stmt->fetchColumn()) > 0;
    }

    /**
     * The id of the memory already stored for this source episode + robot, or
     * null when there is none (used to relink the slot on a repeat commit).
     */
    private function memoryIdForSource(string $sourceEpisodeId, string $robotId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM memories '
            . 'WHERE source_episode_id = :source_episode_id AND robot_id = :robot_id '
            . 'ORDER BY id ASC LIMIT 1'
        );
        $stmt->bindValue(':source_episode_id', $sourceEpisodeId);
        $stmt->bindValue(':robot_id', $robotId);
        $stmt->execute();

        $value = $stmt->fetchColumn();
        if ($value === false) {
            return null;
        }

        return (int) $value;
    }

    /**
     * The verbatim body of one memory by its id, or null when no such row
     * exists (used to carry the reused body on an 'answered' claim).
     */
    private function memoryBodyById(int $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT body FROM memories WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $value = $stmt->fetchColumn();
        if ($value === false) {
            return null;
        }

        return (string) $value;
    }

    /**
     * Encode a MemorySnapshot's verbatim bodies as a JSON array string, oldest
     * first, for storage in a snapshot column. An empty snapshot encodes as
     * '[]' (never NULL) so a post-feature episode is distinguishable from a
     * pre-feature one whose column is NULL.
     */
    private function encodeSnapshot(MemorySnapshot $snapshot): string
    {
        $json = json_encode($snapshot->bodies, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '[]' : $json;
    }

    /**
     * Decode a stored snapshot JSON-array string back into a MemorySnapshot.
     * Decoded with JSON_THROW_ON_ERROR (PHP 8.2 has no json_validate()); a
     * malformed snapshot is treated as empty rather than throwing (Requirement
     * 6.6). Only string entries are kept, preserving order (oldest first).
     */
    private function decodeSnapshot(string $json): MemorySnapshot
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return new MemorySnapshot([]);
        }

        if (!is_array($decoded)) {
            return new MemorySnapshot([]);
        }

        $bodies = [];
        foreach ($decoded as $item) {
            if (is_string($item)) {
                $bodies[] = $item;
            }
        }

        return new MemorySnapshot($bodies);
    }
}
