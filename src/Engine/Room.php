<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine;

use PoltergeistSwitch\Engine\Enums\LightLevel;

/**
 * A single room in the house. Mutable engine state: light level,
 * temperature, and the delayed hot-water injury hazard change during
 * a turn. Adjacency, display name, and fixture list are fixed at init.
 */
class Room
{
    /**
     * @param string[] $adjacentRooms room keys reachable in one step
     * @param string[] $fixtureKeys    fixtures present but never listed in text (window, counter, wall, doorway)
     */
    public function __construct(
        public string $key,
        public string $displayName,
        public array $adjacentRooms = [],
        public LightLevel $lightLevel = LightLevel::normal,
        public float $temperature = 22.0,
        public bool $hotWaterInjuryHazard = false,
        public array $fixtureKeys = [],
    ) {}
}
