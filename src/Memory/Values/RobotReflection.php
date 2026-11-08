<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Memory\Values;

/**
 * One robot's reflection outcome for an episode (design, ReflectService;
 * Requirement 2.6).
 *
 * When `answered` is true a non-empty memory was stored (new or reused): the
 * verbatim body is carried in `memory`, the robot's active count in the
 * episode's generation after the call is `activeCount`, and `reason` is null.
 * When `answered` is false no memory was stored: `memory` is null and `reason`
 * is a short machine code (`empty_answer`, `no_reply`, `timeout`) — never a
 * device id, key, credential, or AI company/model name (Requirement 2.12).
 */
readonly class RobotReflection
{
    public function __construct(
        public bool $answered,   // true when a non-empty memory was stored (new or reused)
        public ?string $memory,  // the stored body when answered; null on failure
        public int $activeCount, // this robot's active count in the episode's generation after the call
        public ?string $reason,  // short failure reason when !answered; null otherwise
    ) {}
}
