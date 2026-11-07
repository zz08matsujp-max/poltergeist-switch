<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine;

use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\Values\ReportedSpeech;

/**
 * Evidence check for a robot's turn (Req 12.2-12.4).
 *
 * Every action, spoken line, and reported-speech item must be backed by an
 * exact, byte-for-byte quote of the robot's reply. Anything that is not an
 * exact substring of the reply is discarded as unverified:
 *   - a missing or non-matching action quote turns the action into `unknown`
 *     (no target, no action quote);
 *   - a non-matching spoken line is dropped;
 *   - a non-matching reported-speech item is dropped.
 *
 * The check is purely textual: no trimming, no normalisation, no multibyte
 * handling. `str_contains` decides membership. The recorder reads; it never
 * decides what is possible (that is the rules engine's job).
 */
final class EvidenceCheck
{
    /**
     * Return a new Action carrying only the parts of $action that are backed
     * by an exact quote of $reply. The input Action is never mutated.
     */
    public static function verify(string $reply, Action $action): Action
    {
        // Spoken lines: keep only those that are exact substrings of the reply.
        $words = [];
        foreach ($action->words as $line) {
            if (self::quoted($reply, $line)) {
                $words[] = $line;
            }
        }

        // Reported speech: keep only items whose quote is an exact substring.
        // heardAs / ownForm are NOT checked; only the quote is the evidence.
        $reported = [];
        foreach ($action->reported as $item) {
            if ($item instanceof ReportedSpeech && self::quoted($reply, $item->quote)) {
                $reported[] = $item;
            }
        }

        // Action quote: a missing or non-matching quote turns the action into
        // `unknown` with no target. The spoken words and reported items are
        // still carried on the returned Action.
        $quote = $action->actionQuote;
        if ($quote === null || !self::quoted($reply, $quote)) {
            return new Action(
                robotId: $action->robotId,
                operation: Operation::unknown->value,
                targetId: null,
                targetSpot: null,
                words: $words,
                actionQuote: null,
                reported: $reported,
            );
        }

        return new Action(
            robotId: $action->robotId,
            operation: $action->operation,
            targetId: $action->targetId,
            targetSpot: $action->targetSpot,
            words: $words,
            actionQuote: $quote,
            reported: $reported,
        );
    }

    /**
     * A quote is verified when it is an exact byte-for-byte substring of the
     * reply. The empty string is trivially contained in any reply.
     */
    private static function quoted(string $reply, string $quote): bool
    {
        return str_contains($reply, $quote);
    }
}
