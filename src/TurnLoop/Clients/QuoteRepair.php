<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop\Clients;

use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\Values\ReportedSpeech;

/**
 * Repair a recorder's quotes against the reply before the byte-for-byte
 * EvidenceCheck runs (turn-loop Requirement 3; house-rules-engine Requirement
 * 12, as a note).
 *
 * The recorder sometimes copies a reply's typographic quote glyph as a
 * different glyph of the same kind — a curly apostrophe `’` written back as a
 * straight `'`, or straight double quotes `"` written back as curly `“ ”`.
 * That makes the recorder's quote no longer a byte-for-byte substring of the
 * reply, so EvidenceCheck drops it. One company's robot writes curly glyphs
 * while the other writes straight ones, so the loss is a systematic
 * one-company bias.
 *
 * This pure step rewrites each recorder quote to the reply's OWN exact
 * substring when they differ only by quote-glyph variants, so the quote that
 * EvidenceCheck then checks — and the quote that is stored — is always the
 * reply's exact text. EvidenceCheck itself is unchanged and stays
 * byte-for-byte.
 *
 * The equivalence used for matching is narrow:
 *   - the apostrophe class {U+2019 ’, U+2018 ‘, U+02BC ʼ, U+0027 '} are all
 *     equal to each other;
 *   - the double-quote class {U+201C “, U+201D ”, U+0022 "} are all equal to
 *     each other;
 *   - every other character must match by its Unicode code point (so a 3-byte
 *     `’` lines up with a 1-byte `'`); no trimming, no case folding, no
 *     whitespace normalisation.
 *
 * A quote is repaired only when EXACTLY ONE distinct exact reply substring
 * matches it under that equivalence. Zero matches, or two-or-more distinct
 * matching substrings (ambiguous), leave the quote unchanged — EvidenceCheck
 * then drops it exactly as before. Several match positions that all yield the
 * same exact substring count as one distinct substring and do repair.
 *
 * The class is pure: it opens no I/O and never mutates the input Action.
 */
final class QuoteRepair
{
    /** Apostrophe / single-quote class, as Unicode code points. */
    private const APOSTROPHE_CODEPOINTS = [0x2019, 0x2018, 0x02BC, 0x0027];

    /** Double-quote class, as Unicode code points. */
    private const DOUBLE_QUOTE_CODEPOINTS = [0x201C, 0x201D, 0x0022];

    /**
     * Return a new Action whose actionQuote, spoken lines, and reported-speech
     * quotes are each repaired independently against $reply. All other fields
     * (operation, targetId, targetSpot, robotId, and each reported item's
     * heardAs/ownForm) are copied through unchanged. The input Action is never
     * mutated.
     */
    public static function repair(string $reply, Action $action): Action
    {
        $actionQuote = $action->actionQuote === null
            ? null
            : self::repairQuote($reply, $action->actionQuote);

        $words = [];
        foreach ($action->words as $line) {
            $words[] = self::repairQuote($reply, $line);
        }

        $reported = [];
        foreach ($action->reported as $item) {
            if ($item instanceof ReportedSpeech) {
                $reported[] = new ReportedSpeech(
                    self::repairQuote($reply, $item->quote),
                    $item->heardAs,
                    $item->ownForm,
                );
            } else {
                $reported[] = $item;
            }
        }

        return new Action(
            robotId: $action->robotId,
            operation: $action->operation,
            targetId: $action->targetId,
            targetSpot: $action->targetSpot,
            words: $words,
            actionQuote: $actionQuote,
            reported: $reported,
        );
    }

    /**
     * Repair a single quote against the reply.
     *
     * If the quote is already an exact byte-for-byte substring of the reply it
     * is returned unchanged. Otherwise the reply is scanned for substrings of
     * the same character length that equal the quote under the quote-glyph
     * equivalence; if exactly one DISTINCT exact reply substring matches, it is
     * returned; zero or two-or-more distinct matches return the quote
     * unchanged.
     */
    private static function repairQuote(string $reply, string $quote): string
    {
        // Already exact: EvidenceCheck would keep it, so leave it alone.
        if (str_contains($reply, $quote)) {
            return $quote;
        }

        $quoteChars = self::chars($quote);
        $len = count($quoteChars);

        // The empty quote is trivially an exact substring (handled above); a
        // non-empty quote that is not exact needs a same-length window search.
        if ($len === 0) {
            return $quote;
        }

        $replyChars = self::chars($reply);
        $replyLen = count($replyChars);
        if ($replyLen < $len) {
            return $quote;
        }

        $distinct = [];
        $last = $replyLen - $len;
        for ($start = 0; $start <= $last; $start++) {
            if (self::windowMatches($replyChars, $start, $quoteChars)) {
                // The reply's OWN exact text at this window.
                $candidate = implode('', array_slice($replyChars, $start, $len));
                $distinct[$candidate] = true;
                // More than one distinct exact substring is ambiguous; stop.
                if (count($distinct) > 1) {
                    return $quote;
                }
            }
        }

        if (count($distinct) === 1) {
            return (string) array_key_first($distinct);
        }

        return $quote;
    }

    /**
     * Does the $len-character window of $replyChars starting at $start equal
     * $quoteChars under the quote-glyph equivalence? Each position matches when
     * both characters fall in the same quote class, or they are the identical
     * code point.
     *
     * @param string[] $replyChars
     * @param string[] $quoteChars
     */
    private static function windowMatches(array $replyChars, int $start, array $quoteChars): bool
    {
        foreach ($quoteChars as $i => $quoteChar) {
            if (!self::charsEquivalent($replyChars[$start + $i], $quoteChar)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Two characters are equivalent when they are the same character, or both
     * belong to the apostrophe class, or both belong to the double-quote class.
     */
    private static function charsEquivalent(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        $ca = self::codepoint($a);
        $cb = self::codepoint($b);

        if (in_array($ca, self::APOSTROPHE_CODEPOINTS, true)
            && in_array($cb, self::APOSTROPHE_CODEPOINTS, true)
        ) {
            return true;
        }

        if (in_array($ca, self::DOUBLE_QUOTE_CODEPOINTS, true)
            && in_array($cb, self::DOUBLE_QUOTE_CODEPOINTS, true)
        ) {
            return true;
        }

        return false;
    }

    /**
     * Split a UTF-8 string into an array of single characters (one entry per
     * Unicode code point). Falls back to raw bytes for an invalid-UTF-8 input
     * so the scan still terminates without throwing.
     *
     * @return string[]
     */
    private static function chars(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            // Invalid UTF-8: fall back to bytes so matching degrades to a plain
            // byte comparison rather than throwing.
            return str_split($text);
        }

        return $chars;
    }

    /**
     * Unicode code point of a single UTF-8 character, or -1 when it cannot be
     * decoded. Used only to classify quote glyphs.
     */
    private static function codepoint(string $char): int
    {
        $values = unpack('N', mb_convert_encoding($char, 'UTF-32BE', 'UTF-8'));
        if ($values === false || !isset($values[1])) {
            return -1;
        }

        return (int) $values[1];
    }
}
