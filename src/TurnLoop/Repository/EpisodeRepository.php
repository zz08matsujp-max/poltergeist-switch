<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop\Repository;

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

    // --- View -------------------------------------------------------------

    /**
     * Load the episode's state and its stored turn records as an EpisodeView,
     * or null when no episode has that id (Requirements 9.4, 9.5).
     */
    public function loadEpisodeView(string $id): ?EpisodeView;
}
