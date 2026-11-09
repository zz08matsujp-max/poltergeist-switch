<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine;

use PoltergeistSwitch\Engine\Enums\LightLevel;

/**
 * A single room in the house. Mutable engine state: light level,
 * temperature, the delayed hot-water injury hazard, and (heat-wave only)
 * whether the window is open change during a turn. Adjacency, display name,
 * and fixture list are fixed at init.
 *
 * `windowOpen` is a heat-wave-only flag (heat-wave Requirement 4.7). The
 * window stays a fixture — it is never added to `fixtureKeys` as an object
 * nor listed in the fixed object order — so an earthquake room reads exactly
 * as before; the flag simply records whether the window is open so the heat
 * rules can apply the open-window drift bonus. It defaults closed.
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
        public bool $windowOpen = false,
    ) {}
}
