<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop\Repository;

use PoltergeistSwitch\Memory\Values\MemoryRow;
use PoltergeistSwitch\Memory\Values\MemorySnapshot;
use PoltergeistSwitch\Memory\Values\ReflectionClaim;
use PoltergeistSwitch\TurnLoop\Values\AppliedTurnRows;
use PoltergeistSwitch\TurnLoop\Values\EpisodeRow;
use PoltergeistSwitch\TurnLoop\Values\EpisodeView;
use PoltergeistSwitch\TurnLoop\Values\PromptReplyRow;
use PoltergeistSwitch\TurnLoop\Values\ReplyRecord;
use PoltergeistSwitch\TurnLoop\Values\TurnRow;

/**
 * All turn-loop storage goes through this one interface, so TurnService can be
 * driven against an in-memory fake without a database server (Requirement 8.7)
 * and against PdoEpisodeRepository in production. The interface carries no I/O
 * of its own; implementations own the database or the in-memory store.
 *
 * Turn numbers are zero-based throughout, following the engine's own numbering
 * (Requirements 4.1, 12.3). Robot ids are the literals 'robot_a' and
 * 'robot_b'. Datetimes are kept as 'Y-m-d H:i:s' strings in the row DTOs so
 * storage stays build-independent.
 */
interface EpisodeRepository
{
    // --- Episode CRUD and slot queries -----------------------------------

    /**
     * Persist a new episode and return its id (Requirement 9.6).
     */
    public function createEpisode(EpisodeRow $episode): string;

    /**
     * Return the episode row, or null when no episode has that id so the
     * caller can answer 404 (Requirement 9.5).
     */
    public function findEpisode(string $id): ?EpisodeRow;

    /**
     * Count the episodes that currently occupy a running slot, applying the
     * lazy idle-slot release, for the across-all-devices cap (Requirements
     * 9.9, 9.10).
     */
    public function countRunningEpisodes(): int;

    /**
     * Return this device's running episode when it has one, applying the lazy
     * idle-slot release, so a device is held to one running episode
     * (Requirements 9.7, 9.8, 9.10); null when it has none.
     */
    public function findRunningEpisodeForDevice(string $deviceId): ?EpisodeRow;

    /**
     * Record the earthquake start turn for an episode (Requirement 4.4).
     */
    public function setEarthquakeStartTurn(string $id, int $turn): void;

    /**
     * Record the heat-wave start turn for an episode, the turn its heat was
     * fixed (heat-wave Requirement 8.2). Written once when the heat wave is
     * fixed before turn 1; a repeated Heat wave flip leaves it unchanged, which
     * is what makes the flip idempotent. NULL for every earthquake episode, so
     * this is never called for one.
     */
    public function setHeatWaveStartTurn(string $id, int $turn): void;

    /**
     * Set an episode's disaster (heat-wave Requirement 8.2). Used only to
     * convert a waiting (turn-0, not-yet-asked) default episode to a heat-wave
     * episode in place, so the Heat wave lever never creates a second episode.
     * The earthquake flip never calls this.
     */
    public function setDisaster(string $id, string $disaster): void;

    /**
     * Mark an episode ended (Requirement 7.5).
     */
    public function markEpisodeEnded(string $id): void;

    /**
     * Set an episode's current expected turn (Requirement 7.4).
     */
    public function setCurrentExpectedTurn(string $id, int $turn): void;

    /**
     * Set the episode's last_activity_at to $now, so an actively-stepping
     * episode keeps its running slot (Requirement 9.10). The lazy idle-slot
     * release frees a running slot only after no ask or apply activity for
     * idleSlotReleaseSeconds; one earthquake run steps for well over that
     * window, so every accepted ask and apply records activity here to keep the
     * slot the episode is actively using. $now is a 'Y-m-d H:i:s' string,
     * consistent with the rest of the repository.
     */
    public function touchActivity(string $id, string $now): void;

    // --- Turn lifecycle ---------------------------------------------------

    /**
     * Return the turn row for one turn of an episode, or null when the turn
     * has not been created yet.
     */
    public function findTurn(string $episodeId, int $turnNumber): ?TurnRow;

    /**
     * Create a turn in state 'waiting' (Requirement 7.1). An optional
     * $worldJson stores the per-turn recorded World_State on the new turns row,
     * used when turn 0 is created with the initial calm world (screens spec
     * Requirement 10.2); null leaves the turn's world_json unset. Like the rest
     * of createTurn, this is idempotent: a turn that already exists is left
     * unchanged, so a repeated create never overwrites a stored world.
     */
    public function createTurn(string $episodeId, int $turnNumber, ?string $worldJson = null): void;

    /**
     * Overwrite a turn's stored `world_json` unconditionally (heat-wave
     * Requirement 8.2). Unlike {@see fillMissingWorldJson}, which fills only a
     * missing world, this replaces whatever a turn already holds, so converting
     * a waiting turn-0 episode to heat wave in place can re-store turn 0's
     * world as the heat-wave starting situation. A turn that does not exist is
     * left untouched.
     */
    public function setTurnWorldJson(string $episodeId, int $turnNumber, string $worldJson): void;

    /**
     * Set a turn's state. The state only ever advances forward
     * waiting -> replies_complete -> applied (Requirement 7.2).
     */
    public function setTurnState(string $episodeId, int $turnNumber, string $state): void;

    // --- Reply persistence ------------------------------------------------

    /**
     * Store the prompt and the reply for one robot on one attempt: the prompt
     * text with its situation-text version in `prompts`, and the reply with
     * its usage and flags in `responses`, one row per attempt (Requirements
     * 8.3, 8.4, 11.6).
     */
    public function savePromptAndReply(PromptReplyRow $row): void;

    /**
     * Return the robot's stored successful reply for a turn, or null when it
     * has none. A robot with a stored successful reply is reused and never
     * called again across repeated ask calls (Requirements 5.2, 5.3). The
     * highest-numbered answered attempt wins when more than one is stored.
     */
    public function findSuccessfulReply(string $episodeId, int $turnNumber, string $robotId): ?ReplyRecord;

    /**
     * Increment a robot's attempt count for a turn and return the new count;
     * the count is persisted across repeated ask calls and never exceeds 2
     * (Requirement 5.4).
     */
    public function incrementAttempt(string $episodeId, int $turnNumber, string $robotId): int;

    /**
     * Mark a robot as having produced no reply for a turn after its attempts
     * are spent, so its recorded action becomes 'unknown' (Requirement 5.6).
     */
    public function markNoReply(string $episodeId, int $turnNumber, string $robotId): void;

    // --- Single-transaction four-layer write ------------------------------

    /**
     * Store all Four_Layers for one applied turn (both robots) in a single
     * transaction: commit only after every record is written (Requirement
     * 8.8). On any error, roll back so no partial records persist and report
     * the failure to the caller (Requirement 8.9). Storing an already-stored
     * applied turn again leaves the stored records unchanged and creates no
     * duplicates (Requirement 8.10).
     */
    public function storeAppliedTurn(AppliedTurnRows $rows): void;

    // --- Replay -----------------------------------------------------------

    /**
     * Return the EvidenceCheck-verified actions stored for every applied turn,
     * for deterministic replay (Requirements 13.2, 13.3). The outer array is
     * ordered by turn number ascending; each entry maps robot id
     * ('robot_a'/'robot_b') to that robot's stored action_json string.
     *
     * @return array<int, array<string, string>> turnNumber => (robotId => action_json)
     */
    public function loadAppliedActionsForReplay(string $episodeId): array;

    // --- Per-turn World_State backfill (screens spec) --------------------

    /**
     * Return the raw stored `world_json` of every turn of an episode, keyed by
     * turn number in ascending order, with null for a turn whose world_json is
     * absent or null (screens spec Requirement 10.7). The one-time World_State
     * backfill reads this to detect which turns are missing a world WITHOUT
     * decoding (so a present value can be left byte-for-byte unchanged); an
     * episode with no turns yields an empty array.
     *
     * @return array<int, string|null> turnNumber => world_json or null, ascending
     */
    public function loadTurnWorldJson(string $episodeId): array;

    /**
     * Fill the per-turn `world_json` for an episode, writing ONLY turns whose
     * stored world_json is currently absent or null and leaving every turn that
     * already holds a value byte-for-byte unchanged (screens spec Requirement
     * 10.7). The whole fill is one atomic unit: either every missing turn in
     * $worldByTurn is written or, on any error, nothing is (Requirement 10.8).
     *
     * The caller passes the world for EVERY turn from one complete replay; this
     * method writes only the ones still missing, so a concurrent write or an
     * already-present value is never clobbered. An entry for a turn that does
     * not exist is ignored.
     *
     * @param array<int, string> $worldByTurn turnNumber => world_json from the replay
     */
    public function fillMissingWorldJson(string $episodeId, array $worldByTurn): void;

    // --- Ended-episode list (screens spec) -------------------------------

    /**
     * Return the ended episodes for the Replay_Screen's list, ordered by
     * created date descending and, for ties, by id descending, capped at
     * $limit (screens spec Requirements 11.1, 11.2). Only episodes whose
     * `ended` is true are returned; every not-ended episode is excluded. The
     * query is `WHERE ended = 1 ORDER BY created_at DESC, id DESC LIMIT :limit`
     * with the limit bound as a parameter, never interpolated.
     *
     * The caller (EpisodeListService) validates $limit (1–100) before calling,
     * so an out-of-range limit never reaches the query (Requirement 11.6). The
     * rows carry no device id, key, credential, or provider name beyond the
     * EpisodeRow DTO; the service reads only id and created date from them
     * (Requirement 11.7).
     *
     * @return EpisodeRow[] the ended episodes, newest first, at most $limit
     */
    public function listEndedEpisodes(int $limit): array;

    // --- View -------------------------------------------------------------

    /**
     * Load the episode's state and its stored turn records as an EpisodeView,
     * or null when no episode has that id (Requirements 9.4, 9.5).
     */
    public function loadEpisodeView(string $id): ?EpisodeView;

    // =====================================================================
    // Robot-memory additions (robot-memory spec, Requirement 8.1)
    //
    // All memory storage goes through this same interface, so ReflectService
    // and MemoryService can be driven against the in-memory fake without a
    // database (robot-memory Requirement 8.1). PdoEpisodeRepository implements
    // these over PDO for MySQL; InMemoryEpisodeRepository mirrors the same
    // logic in memory. Robot ids are the same 'robot_a' / 'robot_b' literals;
    // generations are positive integers; the only disaster today is
    // 'earthquake' but the column and these methods carry the disaster so all
    // three are supported.
    // =====================================================================

    // --- Generations -----------------------------------------------------

    /**
     * Return the current memory generation (robot-memory Requirement 4.1). The
     * generation partitions every robot's memories: a reset advances it so new
     * episodes start with none, while older memories are kept but excluded.
     */
    public function currentGeneration(): int;

    /**
     * Advance the current memory generation to a new, higher integer and return
     * it (robot-memory Requirement 4.2). No memory row is ever deleted; a reset
     * only moves the current generation forward so later episodes see none of
     * the earlier generation's memories.
     */
    public function advanceGeneration(): int;

    // --- Per-episode snapshot + generation (stored with the episode) -----

    /**
     * Store, with the episode, the generation it belongs to and each robot's
     * frozen verbatim memory snapshot taken at creation (robot-memory
     * Requirement 6.1). Called inside createEpisode, reusing the same insert
     * transaction, so every new episode carries its generation and the exact
     * bodies both robots will be shown for the whole run. A rebuild reads these
     * back and never re-queries the live `memories` table, so replay matches
     * the live run byte-for-byte.
     */
    public function storeEpisodeMemory(
        string $episodeId,
        int $generation,
        MemorySnapshot $robotA,
        MemorySnapshot $robotB
    ): void;

    /**
     * Return the generation stored with an episode (robot-memory Requirement
     * 6.1). A running episode keeps its own stored generation even when a reset
     * advances the current generation, so its later reflections store under its
     * own generation.
     */
    public function episodeGeneration(string $episodeId): int;

    /**
     * Return a robot's frozen memory snapshot stored with an episode
     * (robot-memory Requirement 6.6), 'robot_a' or 'robot_b'. A pre-feature
     * episode whose snapshot column is NULL, or a malformed stored snapshot,
     * yields an empty MemorySnapshot. The bodies are returned verbatim, oldest
     * first, exactly as they were frozen at creation.
     */
    public function episodeSnapshot(string $episodeId, string $robotId): MemorySnapshot;

    /**
     * Return whether an episode carries a stored Memory_Snapshot, i.e. whether
     * it was created after this feature shipped (robot-memory Requirement 2.2).
     *
     * A post-feature episode always writes both snapshot columns at creation
     * (even an empty snapshot as the JSON array `[]`), so this is true for it;
     * a pre-feature episode — created before the migration ran — has NULL
     * snapshot columns and is NOT reflectable, so this is false. The reflect
     * guard uses it to answer 409 `not_reflectable` without touching state or
     * calling a Robot_Client. A missing/unknown episode is treated as having no
     * snapshot (false), because {@see findEpisode} already answers 404 first.
     *
     * {@see episodeSnapshot} alone cannot distinguish a NULL column from a
     * stored empty snapshot (both decode to an empty MemorySnapshot), which is
     * why this separate signal exists.
     */
    public function episodeHasSnapshot(string $episodeId): bool;

    // --- Memories: read --------------------------------------------------

    /**
     * Return the active memories of ($robotId, $generation) across all
     * disasters, oldest first by finish-order sequence, capped at $cap
     * (robot-memory Requirements 3.8, 6.1). Retired rows and rows of any other
     * generation are excluded. This is the list frozen into an episode's
     * snapshot at creation (draft cap 18).
     */
    public function activeMemories(string $robotId, int $generation, int $cap): MemorySnapshot;

    /**
     * Return how many active memories ($robotId, $generation) currently has for
     * one $disaster (robot-memory Requirement 3.3), excluding retired and
     * other-generation rows. The per-triple active cap is 6.
     */
    public function activeCount(string $robotId, int $generation, string $disaster): int;

    // --- Memories: write with cap + retirement, atomic -------------------

    /**
     * Store one memory and retire oldest-active rows for its
     * ($robotId, $disaster, $generation) triple until at most 6 remain active
     * (robot-memory Requirements 3.3, 3.4, 8.4). The whole operation runs in a
     * single transaction that FIRST takes a locking read (SELECT ... FOR
     * UPDATE) over that triple's active rows, so two interleaved commits for
     * the same triple — even from two different episodes — serialize and never
     * leave more than 6 active. Retirement flips status to 'retired' and never
     * deletes a row, so a retired memory is kept but excluded from every read.
     * Idempotent per ($sourceEpisodeId, $robotId): a second store for the same
     * source episode + robot leaves the stored records unchanged and creates no
     * duplicate (robot-memory Requirement 8.5).
     */
    public function appendMemory(MemoryRow $memory): void;

    // --- Reflection slot claim/commit (idempotency) ----------------------

    /**
     * Claim this episode+robot's reflection slot in a short transaction and
     * return a ReflectionClaim telling the caller what to do (robot-memory
     * Requirements 2.8, 2.9, 2.10):
     *   - claimed: call this robot — a new, previously failed, or stale
     *     re-claimed slot (a fresh in-progress claim is one made within the
     *     last 120 s; older than that is stale and re-claimable);
     *   - reuse: the robot already answered; reuse the carried stored body and
     *     make no call;
     *   - skip: a fresh in-progress claim exists, so do nothing (the robot is
     *     not double-asked).
     * The claim holds no lock across the AI call: it is taken and released in
     * one short transaction, with the call made afterwards outside any lock.
     */
    public function claimReflectionSlot(string $episodeId, string $robotId): ReflectionClaim;

    /**
     * Commit a non-empty reflection answer in a short transaction (robot-memory
     * Requirements 2.6, 8.4): append the memory with the cap/retirement applied
     * in the same transaction (serialized per robot/disaster/generation) and
     * mark the slot 'answered'. Idempotent with appendMemory per source episode
     * + robot, so a repeated commit of an already-stored answer creates no
     * duplicate memory.
     */
    public function commitReflectionAnswer(string $episodeId, string $robotId, MemoryRow $memory): void;

    /**
     * Commit a reflection failure in a short transaction (robot-memory
     * Requirement 2.10): add no memory, record the short $reason
     * ('empty_answer', 'no_reply', 'timeout'), and release the slot to 'failed'
     * so a later reflect call may ask that robot again.
     */
    public function commitReflectionFailure(string $episodeId, string $robotId, string $reason): void;

    /**
     * Return the memory already stored for this episode+robot when the slot is
     * 'answered', so the caller reuses it verbatim without a new AI call
     * (robot-memory Requirements 2.8, 2.9); null when there is no stored answer.
     */
    public function reflectionAnswer(string $episodeId, string $robotId): ?MemoryRow;
}
