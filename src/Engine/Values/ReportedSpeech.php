<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine\Values;

readonly class ReportedSpeech
{
    public function __construct(
        public string $quote,    // the reply's own words
        public string $heardAs,  // for the listener
        public string $ownForm,  // for the speaker
    ) {}
}
