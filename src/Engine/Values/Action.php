<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine\Values;

readonly class Action
{
    public function __construct(
        public string $robotId,
        public string $operation,       // Operation enum value; 'unknown' when no quote backs the action (Req 12.3)
        public ?string $targetId = null,
        public ?string $targetSpot = null,
        public array $words = [],        // string[]: spoken lines, exactly as quoted in the reply (Req 5.12)
        public ?string $actionQuote = null, // exact reply words the action was read from (Req 12.1)
        public array $reported = [],     // ReportedSpeech[] (Req 12.1)
    ) {}
}
