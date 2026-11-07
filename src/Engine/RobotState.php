<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine;

/**
 * Mutable per-robot engine state. `coverFlag` is turn-scoped: it is set
 * when the robot covers itself this turn and cleared at the end of the turn.
 *
 * `supportTurnsRemaining` is a short countdown started by a `support` action
 * (set to 2); the resident is moved with the robot on the turn it decrements
 * to zero. It is 0 while no support is in progress (Req 5.5).
 */
class RobotState
{
    public function __construct(
        public string $id,
        public string $room,
        public ?string $spot = null,
        public ?string $heldObjectId = null,
        public bool $coverFlag = false,
        public int $supportTurnsRemaining = 0,
    ) {}
}
