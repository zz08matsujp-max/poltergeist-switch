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
 * Before the byte-for-byte check runs, {@see QuoteRepair::repair} rewrites each
 * recorder quote that is not an exact substring of the reply to the reply's own
 * exact text when they differ only by quote-glyph variants (a curly apostrophe
 * copied back as a straight one, or straight double quotes copied back as curly
 * ones). This removes a systematic one-company typographic-quote bias without
 * loosening EvidenceCheck, which stays byte-for-byte: the stored quote is always
 * the reply's exact substring (turn-loop Requirement 3).
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
        // Repair typographic-quote variants to the reply's exact text first, so
        // a quote that differs from the reply only by quote glyph still survives
        // the byte-for-byte EvidenceCheck that follows.
        $repaired = QuoteRepair::repair($reply, $reading->action);
        $verified = EvidenceCheck::verify($reply, $repaired);

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
