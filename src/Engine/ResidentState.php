<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine;

use PoltergeistSwitch\Engine\Enums\ManActivity;

/**
 * Mutable state of the scripted elderly resident. `coverFlag` is
 * turn-scoped: it is set when a robot covers the resident this turn and
 * cleared at the end of the turn. `activity` holds a ManActivity value.
 */
class ResidentState
{
    /**
     * @param array<string,int> $lastLineDeliveredOn line_key => turn number of last delivery
     */
    public function __construct(
        public float $bodyTemp,
        public int $water,
        public int $injury,
        public int $fear,
        public bool $weakened,
        public string $room,
        public ?string $spot = null,
        public string $activity = ManActivity::sitting_at_table->value,
        public bool $coverFlag = false,
        public array $lastLineDeliveredOn = [],
    ) {}
}
