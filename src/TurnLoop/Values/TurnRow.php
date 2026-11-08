<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop\Values;

/**
 * One row of the `turns` table (Requirement 8.2; design Data Models).
 *
 * Mirrors the turns table columns: episode_id, turn_number (zero-based), state,
 * the per-robot attempt counts (0..2 — Requirement 5.4) and no-reply flags
 * (Requirement 5.6) for robot_a and robot_b, and the resident line (null until
 * one is recorded). The state is held as a TurnStateValue so forward-only
 * ordering is enforced by the value object (Requirement 7.2).
 *
 * $worldJson carries the per-turn recorded World_State (screens spec
 * Requirement 10.1, 10.2): the serialised state of the house after this turn
 * was applied, and the initial calm state for turn 0. It is null until one is
 * recorded (the column is nullable, so a turn written without a world reads
 * back as null; the one-time backfill is a later task).
 */
readonly class TurnRow
{
    public function __construct(
        public string $episodeId,
        public int $turnNumber,              // zero-based
        public TurnStateValue $state,        // waiting | replies_complete | applied (Req 7.1)
        public int $attemptsA,               // 0..2 (Req 5.4)
        public int $attemptsB,               // 0..2
        public bool $noReplyA,               // Req 5.6
        public bool $noReplyB,
        public ?string $residentLine,        // null or the resident's line
        public string $createdAt,            // 'Y-m-d H:i:s'
        public ?string $worldJson = null,    // null or the per-turn World_State (Req 10.1, 10.2)
    ) {}
}
