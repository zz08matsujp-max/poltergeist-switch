<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine\Values;

readonly class Outcome
{
    public function __construct(
        public string $robotId,
        public bool $success,
        public ?string $whatHappenedText = null,
        public ?string $errorMessage = null,
        public array $worldChanges = [],
    ) {}

    public static function failure(string $robotId, string $errorMessage): self
    {
        return new self($robotId, false, null, $errorMessage);
    }

    public static function success(string $robotId, string $whatHappenedText, array $worldChanges = []): self
    {
        return new self($robotId, true, $whatHappenedText, null, $worldChanges);
    }
}
