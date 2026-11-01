<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine\Values;

readonly class Action
{
    public function __construct(
        public string $robotId,
        public string $operation,    // Operation enum value
        public ?string $targetId = null,
        public ?string $targetSpot = null,
        public ?string $words = null,
    ) {}
}
