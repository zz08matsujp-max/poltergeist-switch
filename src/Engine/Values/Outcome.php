<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine\Values;

readonly class Outcome
{
    public function __construct(
        public string $robotId,
        public bool $success,
        public ?string $whatHappenedText = null,
        public ?string $seenByOtherText = null, // third person; shown to the other robot only when they shared a room (Req 9.16)
        public ?string $errorMessage = null,
        public array $worldChanges = [],
    ) {}

    public static function failure(string $robotId, string $errorMessage): self
    {
        return new self($robotId, false, null, null, $errorMessage);
    }

    public static function success(
        string $robotId,
        string $whatHappenedText,
        ?string $seenByOtherText = null,
        array $worldChanges = [],
    ): self {
        return new self($robotId, true, $whatHappenedText, $seenByOtherText, null, $worldChanges);
    }
}
