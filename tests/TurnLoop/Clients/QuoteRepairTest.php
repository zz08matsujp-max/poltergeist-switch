<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop\Clients;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\Values\ReportedSpeech;
use PoltergeistSwitch\TurnLoop\Clients\QuoteRepair;

/**
 * Unit tests for {@see QuoteRepair} (task 22.1).
 *
 * The repair rewrites a recorder quote that is not an exact substring of the
 * reply to the reply's OWN exact text when they differ only by quote-glyph
 * variants: the apostrophe class {’ ‘ ʼ '} are equal, the double-quote class
 * {“ ” "} are equal, every other character matches by code point. A quote is
 * repaired only when exactly one distinct reply substring matches; zero or an
 * ambiguous two-or-more leaves it unchanged. EvidenceCheck stays byte-for-byte
 * and is not exercised here.
 *
 * Requirements: 3.8
 */
class QuoteRepairTest extends TestCase
{
    // U+2019 right single quote, U+201C/U+201D curly double quotes.
    private const CURLY_APOS = "\u{2019}";
    private const LDQUO = "\u{201C}";
    private const RDQUO = "\u{201D}";

    private function action(
        ?string $actionQuote = null,
        array $words = [],
        array $reported = [],
        string $operation = 'move',
    ): Action {
        return new Action(
            robotId: 'robot_b',
            operation: $operation,
            targetId: 'door',
            targetSpot: null,
            words: $words,
            actionQuote: $actionQuote,
            reported: $reported,
        );
    }

    public function testActionQuoteStraightApostropheRepairedToReplyCurly(): void
    {
        $reply = 'I say I don' . self::CURLY_APOS . 't move from here.';
        $action = $this->action(actionQuote: "I say I don't move from here.");

        $repaired = QuoteRepair::repair($reply, $action);

        $this->assertSame(
            'I say I don' . self::CURLY_APOS . 't move from here.',
            $repaired->actionQuote,
        );
        $this->assertTrue(str_contains($reply, (string) $repaired->actionQuote), 'repaired quote is an exact substring');
    }

    public function testSpokenLineCurlyDoubleRepairedToReplyStraight(): void
    {
        // Reply uses straight double quotes; recorder wrote curly ones.
        $reply = 'I turn and say, "Okay" to him.';
        $action = $this->action(words: [self::LDQUO . 'Okay' . self::RDQUO]);

        $repaired = QuoteRepair::repair($reply, $action);

        $this->assertSame(['"Okay"'], $repaired->words);
        $this->assertTrue(str_contains($reply, $repaired->words[0]));
    }

    public function testSpokenLineStraightDoubleRepairedToReplyCurly(): void
    {
        // The symmetric case: reply uses curly doubles, recorder wrote straight.
        $reply = 'I turn and say, ' . self::LDQUO . 'Okay' . self::RDQUO . ' to him.';
        $action = $this->action(words: ['"Okay"']);

        $repaired = QuoteRepair::repair($reply, $action);

        $this->assertSame([self::LDQUO . 'Okay' . self::RDQUO], $repaired->words);
        $this->assertTrue(str_contains($reply, $repaired->words[0]));
    }

    public function testReportedSpeechQuoteRepaired(): void
    {
        $reply = 'I call out, don' . self::CURLY_APOS . 't panic, to the other robot.';
        $reported = [new ReportedSpeech("don't panic", 'stay calm', 'I told it not to panic')];
        $action = $this->action(reported: $reported);

        $repaired = QuoteRepair::repair($reply, $action);

        $this->assertCount(1, $repaired->reported);
        $this->assertSame('don' . self::CURLY_APOS . 't panic', $repaired->reported[0]->quote);
        // heardAs / ownForm are copied through unchanged; only the quote repairs.
        $this->assertSame('stay calm', $repaired->reported[0]->heardAs);
        $this->assertSame('I told it not to panic', $repaired->reported[0]->ownForm);
        $this->assertTrue(str_contains($reply, $repaired->reported[0]->quote));
    }

    public function testAlreadyExactQuoteLeftUnchanged(): void
    {
        $reply = 'I crawl under the table to shelter.';
        $action = $this->action(actionQuote: 'I crawl under the table');

        $repaired = QuoteRepair::repair($reply, $action);

        $this->assertSame('I crawl under the table', $repaired->actionQuote);
    }

    public function testQuoteWithNoMatchLeftUnchanged(): void
    {
        $reply = 'I wait by the window.';
        $action = $this->action(actionQuote: "I don't move from here");

        $repaired = QuoteRepair::repair($reply, $action);

        $this->assertSame("I don't move from here", $repaired->actionQuote, 'no match: unchanged');
    }

    public function testAmbiguousTwoDistinctMatchesLeftUnchanged(): void
    {
        // The reply contains BOTH Don't (straight) and Don’t (curly). The
        // recorder quote uses a third apostrophe glyph (U+2018), so it matches
        // two DISTINCT exact reply substrings — ambiguous — and is left
        // unchanged rather than repaired to either one.
        $reply = 'First: Don' . self::CURLY_APOS . 't. Then: Don\'t.';
        $leftApos = "\u{2018}";
        $action = $this->action(actionQuote: 'Don' . $leftApos . 't');

        $repaired = QuoteRepair::repair($reply, $action);

        $this->assertSame('Don' . $leftApos . 't', $repaired->actionQuote, 'ambiguous: unchanged');
    }

    public function testMultiplePositionsOfSameExactSubstringRepaired(): void
    {
        // The same exact reply substring "it's" appears twice; a straight-apos
        // recorder quote... here the reply uses the curly form in both places,
        // so both positions yield the SAME exact substring -> one distinct ->
        // repaired to it.
        $its = 'it' . self::CURLY_APOS . 's';
        $reply = $its . ' here and ' . $its . ' there.';
        $action = $this->action(actionQuote: "it's");

        $repaired = QuoteRepair::repair($reply, $action);

        $this->assertSame($its, $repaired->actionQuote);
        $this->assertTrue(str_contains($reply, (string) $repaired->actionQuote));
    }

    public function testNonQuoteCharacterMismatchNotRepaired(): void
    {
        // A real difference in a non-quote character must not be repaired, even
        // though a quote glyph also differs.
        $reply = 'I open the door' . self::CURLY_APOS . 's latch.';
        $action = $this->action(actionQuote: "I open the window's latch");

        $repaired = QuoteRepair::repair($reply, $action);

        $this->assertSame("I open the window's latch", $repaired->actionQuote, 'non-quote mismatch: unchanged');
    }

    public function testApostropheDiffersInsideLongerLine(): void
    {
        // A mixed case: only the apostrophe differs inside a longer sentence.
        $reply = 'I kneel beside him and whisper that it' . self::CURLY_APOS . 's going to be all right now.';
        $action = $this->action(actionQuote: "I kneel beside him and whisper that it's going to be all right now.");

        $repaired = QuoteRepair::repair($reply, $action);

        $this->assertSame(
            'I kneel beside him and whisper that it' . self::CURLY_APOS . 's going to be all right now.',
            $repaired->actionQuote,
        );
        $this->assertTrue(str_contains($reply, (string) $repaired->actionQuote));
    }

    public function testNullActionQuoteStaysNull(): void
    {
        $reply = 'I wait.';
        $action = $this->action(actionQuote: null, operation: Operation::wait->value);

        $repaired = QuoteRepair::repair($reply, $action);

        $this->assertNull($repaired->actionQuote);
    }

    public function testOtherFieldsCopiedThroughUnchanged(): void
    {
        $reply = 'I move to the door' . self::CURLY_APOS . 's edge.';
        $action = new Action(
            robotId: 'robot_a',
            operation: 'move',
            targetId: 'door',
            targetSpot: 'window',
            words: [],
            actionQuote: "I move to the door's edge.",
            reported: [],
        );

        $repaired = QuoteRepair::repair($reply, $action);

        $this->assertSame('robot_a', $repaired->robotId);
        $this->assertSame('move', $repaired->operation);
        $this->assertSame('door', $repaired->targetId);
        $this->assertSame('window', $repaired->targetSpot);
    }

    public function testInputActionIsNotMutated(): void
    {
        $reply = 'I say it' . self::CURLY_APOS . 's fine.';
        $action = $this->action(actionQuote: "I say it's fine.");

        QuoteRepair::repair($reply, $action);

        // The original Action still carries the straight-apostrophe quote.
        $this->assertSame("I say it's fine.", $action->actionQuote);
    }
}
