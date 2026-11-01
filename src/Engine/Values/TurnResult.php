<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine\Values;

readonly class TurnResult
{
    public function __construct(
        public int $turnNumber,
        public array $judgements,         // Judgement[] keyed by robotId
        public array $outcomes,           // Outcome[] keyed by robotId
        public ?string $residentLine,
        public mixed $worldSnapshot,      // WorldSnapshot (typed after WorldSnapshot is available)
        public mixed $residentState,      // ResidentState
        public bool $episodeEnded,
        public bool $isError = false,
        public ?string $errorMessage = null,
    ) {}
}
