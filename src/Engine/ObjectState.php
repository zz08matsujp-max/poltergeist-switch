<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine;

/**
 * A movable or fixed object in the world. The first group of fields is
 * mutable turn-to-turn state; the second group (breakable … isPot) is a
 * fixed set of properties assigned once at initialisation.
 */
class ObjectState
{
    /**
     * @param string[] $contents contained object ids
     */
    public function __construct(
        public string $id,
        public string $displayName,
        public string $room,
        public ?string $spot = null,
        public bool $on = false,
        public bool $open = false,
        public array $contents = [],
        public bool $broken = false,
        public bool $fallen = false,
        // --- fixed properties, set at init ---
        public bool $breakable = false,
        public bool $isFireSource = false,
        public bool $isWaterContainer = false,
        public bool $isHeavy = false,
        public bool $isElectric = false,
        public bool $isLightSource = false,
        public bool $isProtective = false,
        public bool $isPot = false,
    ) {}
}
