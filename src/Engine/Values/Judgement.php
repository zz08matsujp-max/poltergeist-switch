<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine\Values;

readonly class Judgement
{
    public function __construct(
        public string $robotId,
        public bool $possible,
        public ?string $reason = null,
        public bool $dangerous = false,
        public ?string $dangerCode = null,
        public ?int $duration = null,
    ) {}
}
