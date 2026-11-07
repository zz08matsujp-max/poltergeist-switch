<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine;

use PoltergeistSwitch\Engine\Enums\GasState;
use PoltergeistSwitch\Engine\Enums\PowerState;

/**
 * Mutable house-wide state. `timeOfDay` holds a TimeOfDay value and is
 * fixed after reset; `fallingObjects` is true on shaking turns when at
 * least one object fell and is cleared at the start of the next turn.
 */
class HouseState
{
    public function __construct(
        public PowerState $power = PowerState::on,
        public GasState $gasMain = GasState::open,
        public float $floorWaterLevel = 0.0,
        public bool $shaking = false,
        public bool $fallingObjects = false,
        public string $timeOfDay = '',
    ) {}
}
