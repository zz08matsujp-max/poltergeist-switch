<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop\Values;

use PoltergeistSwitch\Engine\Values\Action;

/**
 * The recorder's reading of one reply (Requirement 3; house-rules-engine
 * Requirement 12.1).
 *
 * The reading is mapped to an engine Action (operation 'unknown' when the reply
 * yields no readable action, when the recorder output is not valid JSON of the
 * Reading shape, or when the call fails/times out — Requirements 3.3, 3.6, 3.7).
 * The raw recorder output and the recorder prompt version are stored verbatim
 * (Requirement 8.5).
 */
readonly class Reading
{
    public function __construct(
        public Action $action,        // mapped engine Action (operation 'unknown' when unreadable)
        public bool $unknown,         // true when the reading could not be read (Req 3.6, 3.7)
        public string $rawJson,       // the recorder's raw output, stored verbatim (Req 8.5)
        public string $promptVersion, // recorder prompt version (Req 3.5, 8.5)
    ) {}
}
