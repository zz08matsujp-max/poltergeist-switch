<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Memory\Values;

/**
 * One stored memory with its tags (design Data Models; Requirements 2.6, 3.7,
 * 6.1).
 *
 * Mirrors a row of the `memories` table: the robot that wrote it, the
 * generation and disaster it belongs to, the episode it was reflected from, the
 * verbatim answer body, the active/retired status, and the created time. The
 * finish-order `seq` (the auto-increment id doubling as the sequence) and the
 * `retiredAt` time are set by the store, so they are null until a row is
 * persisted/retired (Requirement 3.7). Datetimes are kept as strings so storage
 * stays build-independent.
 */
readonly class MemoryRow
{
    public function __construct(
        public string $robotId,         // 'robot_a' | 'robot_b'
        public int $generation,
        public string $disaster,        // 'earthquake' (column supports all three)
        public string $sourceEpisodeId, // the episode this memory was reflected from
        public string $body,            // the verbatim answer
        public string $status,          // 'active' | 'retired'
        public string $createdAt,       // ISO-8601
        public ?int $seq = null,        // finish-order sequence; set by the store (Req 3.7)
        public ?string $retiredAt = null, // set when retired; null while active
    ) {}
}
