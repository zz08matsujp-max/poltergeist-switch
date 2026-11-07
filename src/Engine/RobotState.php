<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine;

/**
 * Mutable per-robot engine state. `coverFlag` is turn-scoped: it is set
 * when the robot covers itself this turn and cleared at the end of the turn.
 */
class RobotState
{
    public function __construct(
        public string $id,
        public string $room,
        public ?string $spot = null,
        public ?string $heldObjectId = null,
        public bool $coverFlag = false,
    ) {}
}
