<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Memory\Values;

/**
 * One robot's ordered, verbatim memory bodies for an episode (design Data
 * Models; Requirements 6.1, 6.6).
 *
 * The snapshot is the verbatim bodies only, oldest first, so a rebuild needs no
 * join back to the `memories` table and matches the live run byte-for-byte. An
 * empty snapshot (no memories this generation) renders no memories block.
 */
readonly class MemorySnapshot
{
    /** @param string[] $bodies verbatim memory bodies, oldest first */
    public function __construct(public array $bodies) {}

    public function isEmpty(): bool
    {
        return $this->bodies === [];
    }
}
