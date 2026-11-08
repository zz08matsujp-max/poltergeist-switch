<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop\Repository;

use PoltergeistSwitch\TurnLoop\Values\AppliedTurnRows;
use PoltergeistSwitch\TurnLoop\Values\EpisodeRow;
use PoltergeistSwitch\TurnLoop\Values\EpisodeView;
use PoltergeistSwitch\TurnLoop\Values\PromptReplyRow;
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

    // --- Turn lifecycle ---------------------------------------------------

    /**
     * Return the turn row for one turn of an episode, or null when the turn
     * has not been created yet.
     */
    public function findTurn(string $episodeId, int $turnNumber): ?TurnRow;

    /**
     * Create a turn in state 'waiting' (Requirement 7.1).
     */
    public function createTurn(string $episodeId, int $turnNumber): void;

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

    // --- View -------------------------------------------------------------

    /**
     * Load the episode's state and its stored turn records as an EpisodeView,
     * or null when no episode has that id (Requirements 9.4, 9.5).
     */
    public function loadEpisodeView(string $id): ?EpisodeView;
}
