<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine\Values;

readonly class TurnRecord
{
    public function __construct(
        public int $turnNumber,
        public array $robotActions,  // Action[] keyed by robotId
        public array $judgements,    // Judgement[] keyed by robotId
        public array $outcomes,      // Outcome[] keyed by robotId
        public ?string $residentLine,
        public mixed $snapshot,      // WorldSnapshot
        public array $roomsAtStart = [], // robotId => room key at the start of this turn (rule A, Req 9.16)
    ) {}
}
