<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop\Values;

/**
 * One row of the `episodes` table (Requirement 8.2; design Data Models).
 *
 * Mirrors the episodes table columns: id, device_id, disaster, seed,
 * current_expected_turn (zero-based), earthquake_start_turn (null until
 * flipped), ended, created_at, last_activity_at (for the lazy idle slot
 * release — Requirement 9.10). Datetimes are kept as strings in the DB's
 * 'Y-m-d H:i:s' form so storage stays build-independent.
 */
readonly class EpisodeRow
{
    public function __construct(
        public string $id,
        public string $deviceId,
        public string $disaster,             // 'earthquake' in this spec
        public int $seed,
        public int $currentExpectedTurn,     // zero-based (Req 4.1, 7.4)
        public ?int $earthquakeStartTurn,    // null until flipped (Req 4.4)
        public bool $ended,                  // Req 7.5
        public string $createdAt,            // 'Y-m-d H:i:s'
        public string $lastActivityAt,       // 'Y-m-d H:i:s'; idle slot release (Req 9.10)
    ) {}
}
