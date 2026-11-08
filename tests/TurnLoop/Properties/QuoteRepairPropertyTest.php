<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\EvidenceCheck;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\Values\ReportedSpeech;
use PoltergeistSwitch\TurnLoop\Clients\QuoteRepair;

/**
 * Feature: turn-loop, Property 17: Repaired quotes are exact substrings.
 *
 * For an arbitrary reply and an Action whose quotes are the reply's own
 * substrings with some quote glyphs swapped to a variant in the same class
 * (apostrophe class {’ ‘ ʼ '}, double-quote class {“ ” "}), after
 * {@see QuoteRepair::repair} followed by the byte-for-byte
 * {@see EvidenceCheck::verify}, EVERY kept quote is an exact byte-for-byte
 * substring of the reply: the verified actionQuote (when the operation is not
 * `unknown`), every verified spoken line, and every verified reported-speech
 * quote (Requirement 3.8).
 *
 * The invariant is that repair never leaves a kept quote unbacked by the reply;
 * it holds for arbitrary generated inputs, not just a crafted happy path. No
 * real provider API is touched; the property is pure text (Requirement 14.1).
 * Eris drives the reply text, the quote slices, and the glyph swaps with
 * limitTo(100).
 *
 * Requirements: 3.8
 */
final class QuoteRepairPropertyTest extends TestCase
{
    use TestTrait;

    /** Apostrophe class, as UTF-8 characters. */
    private const APOSTROPHES = ["\u{2019}", "\u{2018}", "\u{02BC}", "'"];

    /** Double-quote class, as UTF-8 characters. */
    private const DOUBLE_QUOTES = ["\u{201C}", "\u{201D}", '"'];

    /**
     * Property 17: after QuoteRepair + EvidenceCheck, every kept quote is an
     * exact substring of the reply.
     *
     * Validates: Requirements 3.8
     */
    public function testRepairedKeptQuotesAreExactSubstringsOfTheReply(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generators::seq(Generators::elements($this->fragments())),
                Generators::int(),
            )
            ->then(function (array $fragments, int $salt): void {
                // Build a reply from fragments, sprinkling quote glyphs in.
                $reply = $this->buildReply($fragments, $salt);

                // Derive quotes that ARE substrings of the reply, then swap some
                // of their quote glyphs to a same-class variant so they are no
                // longer byte-for-byte substrings (the real failure mode).
                $actionQuote = $this->maybeSwappedSlice($reply, $salt, 1);
                $wordA = $this->maybeSwappedSlice($reply, $salt, 2);
                $wordB = $this->maybeSwappedSlice($reply, $salt, 3);
                $reportedQuote = $this->maybeSwappedSlice($reply, $salt, 4);

                $action = new Action(
                    robotId: 'robot_a',
                    operation: Operation::move->value,
                    targetId: 'door',
                    targetSpot: null,
                    words: array_values(array_filter([$wordA, $wordB], static fn (string $w): bool => $w !== '')),
                    actionQuote: $actionQuote === '' ? null : $actionQuote,
                    reported: $reportedQuote === ''
                        ? []
                        : [new ReportedSpeech($reportedQuote, 'heard', 'own')],
                );

                $repaired = QuoteRepair::repair($reply, $action);
                $verified = EvidenceCheck::verify($reply, $repaired);

                // The verified actionQuote, when the operation survived, is an
                // exact substring of the reply.
                if ($verified->operation !== Operation::unknown->value && $verified->actionQuote !== null) {
                    $this->assertTrue(
                        str_contains($reply, $verified->actionQuote),
                        'a kept actionQuote must be an exact substring of the reply'
                    );
                }

                // Every kept spoken line is an exact substring.
                foreach ($verified->words as $line) {
                    $this->assertTrue(
                        str_contains($reply, $line),
                        'a kept spoken line must be an exact substring of the reply'
                    );
                }

                // Every kept reported-speech quote is an exact substring.
                foreach ($verified->reported as $item) {
                    $this->assertTrue(
                        str_contains($reply, $item->quote),
                        'a kept reported quote must be an exact substring of the reply'
                    );
                }
            });
    }

    /**
     * The pool of reply fragments. Includes plain words and quote glyphs from
     * both classes so generated replies mix straight and curly quotes.
     *
     * @return string[]
     */
    private function fragments(): array
    {
        return [
            'I', 'move', 'to', 'the', 'door', 'and', 'say', 'wait', 'here',
            'dont', 'its', 'Okay', 'now', 'him', ' ', '.', ',',
            "\u{2019}", "'", "\u{201C}", "\u{201D}", '"', "\u{2018}", "\u{02BC}",
        ];
    }

    /**
     * Join fragments into a reply string.
     *
     * @param string[] $fragments
     */
    private function buildReply(array $fragments, int $salt): string
    {
        if ($fragments === []) {
            // Guarantee a non-trivial reply that carries a quote glyph.
            return 'I dont' . self::APOSTROPHES[0] . 't move.';
        }

        return implode('', $fragments);
    }

    /**
     * Take a substring of the reply (so it starts life as an exact substring),
     * then swap each quote glyph in it to another variant of the same class,
     * chosen deterministically from $salt and $which. The result is a quote
     * that is equivalent to a reply substring under the repair rule but may no
     * longer be a byte-for-byte substring — exactly what repair must fix.
     */
    private function maybeSwappedSlice(string $reply, int $salt, int $which): string
    {
        $chars = preg_split('//u', $reply, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false || $chars === []) {
            return '';
        }

        $n = count($chars);
        $seed = abs($salt) + $which * 7919;
        $start = $seed % $n;
        $maxLen = $n - $start;
        $len = $maxLen <= 1 ? $maxLen : 1 + (($seed >> 3) % $maxLen);

        $slice = array_slice($chars, $start, $len);

        foreach ($slice as $i => $ch) {
            if (in_array($ch, self::APOSTROPHES, true)) {
                $slice[$i] = self::APOSTROPHES[($seed + $i) % count(self::APOSTROPHES)];
            } elseif (in_array($ch, self::DOUBLE_QUOTES, true)) {
                $slice[$i] = self::DOUBLE_QUOTES[($seed + $i) % count(self::DOUBLE_QUOTES)];
            }
        }

        return implode('', $slice);
    }
}
