<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop\Clients;

use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\EvidenceCheck;
use PoltergeistSwitch\TurnLoop\Values\Reading;

/**
 * The small call site that runs {@see EvidenceCheck::verify} on a Reading
 * before it is used (Requirement 3.8). TurnService's apply (task 12) calls this
 * for every reading before passing its action to `Engine::applyTurn`.
 *
 * Every action, spoken line, and reported-speech item must be backed by an
 * exact substring quote of the reply (house-rules-engine Requirement 12). The
 * engine's pure {@see EvidenceCheck::verify} keeps only the quote-backed parts:
 * an action whose `actionQuote` is missing or not an exact substring of the
 * reply becomes operation `unknown`, and spoken lines / reported quotes that
 * are not exact substrings are dropped.
 *
 * This helper returns a new Reading carrying the verified action, with
 * `unknown` set to true when the verified action's operation is `unknown`
 * (Requirement 3.8). The raw JSON and prompt version are preserved so the
 * reading can still be stored verbatim.
 */
final class VerifiedReading
{
    /**
     * Pass a reading's action through EvidenceCheck against the reply and
     * return a new Reading carrying the verified action.
     */
    public static function verify(string $reply, Reading $reading): Reading
    {
        $verified = EvidenceCheck::verify($reply, $reading->action);

        $unknown = $reading->unknown || $verified->operation === Operation::unknown->value;

        return new Reading(
            $verified,
            $unknown,
            $reading->rawJson,
            $reading->promptVersion,
            // Preserve any machine failure reason the recorder set (transport,
            // HTTP, unreadable). EvidenceCheck dropping an unbacked quote is not
            // a provider failure, so it adds no reason of its own.
            $reading->failureReason,
        );
    }
}
