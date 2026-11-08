<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Memory\Values;

/**
 * The memory status reported to the Observe screen (design, MemoryService;
 * Requirements 5.2, 5.3).
 *
 * Carries the current generation and, per robot reported by its screen name
 * Amber (robot_a) and Jade (robot_b), the count of active memories of the
 * current generation and the character length of each active memory oldest
 * first, excluding retired rows and rows of other generations. It returns no
 * device id, key, credential, or AI company/model name (Requirement 5.4).
 */
readonly class MemoryStatus
{
    /**
     * @param int[] $amberLengths length of each active memory, oldest first
     * @param int[] $jadeLengths  length of each active memory, oldest first
     */
    public function __construct(
        public int $generation,
        public int $amberActiveCount,
        public array $amberLengths,
        public int $jadeActiveCount,
        public array $jadeLengths,
    ) {}
}
