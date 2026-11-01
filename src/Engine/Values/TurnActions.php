<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine\Values;

readonly class TurnActions
{
    public function __construct(
        public array $speakingRobots,  // string[] of robotIds that spoke this turn
        public array $speakRooms,      // string[] keyed by robotId => roomKey where the robot spoke
        public bool $residentCovered,  // true if any robot covered the resident this turn
    ) {}
}
