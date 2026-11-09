<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine;

use PoltergeistSwitch\Engine\Enums\GasState;
use PoltergeistSwitch\Engine\Enums\PowerState;

/**
 * Mutable house-wide state. `timeOfDay` holds a TimeOfDay value and is
 * fixed after reset; `fallingObjects` is true on shaking turns when at
 * least one object fell and is cleared at the start of the next turn.
 *
 * `outsideTemperature` is a heat-wave-only field (heat-wave Requirement 2.1):
 * the fixed outside temperature a heat-wave episode's rooms drift toward. It
 * defaults to 0.0, meaning "not a heat wave"; `WorldModel::reset()` writes it
 * only when the disaster is heat wave, so an earthquake `HouseState` is
 * unchanged in meaning and bytes.
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
        public float $outsideTemperature = 0.0,
    ) {}
}
