<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Memory\Values;

/**
 * Both robots' reflection outcomes for one episode (design, ReflectService;
 * Requirement 2.6).
 *
 * `robots` is keyed by robot id, 'robot_a' and 'robot_b', each a
 * RobotReflection. The result carries no device id, key, credential, or AI
 * company/model name (Requirement 2.12).
 */
readonly class ReflectionResult
{
    /** @param array<string,RobotReflection> $robots keyed 'robot_a','robot_b' */
    public function __construct(public array $robots) {}
}
