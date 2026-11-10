<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine;

use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\EvidenceCheck;
use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\Values\ReportedSpeech;

/**
 * Tests for EvidenceCheck (Requirements: Req 11.14, Req 12.2-12.4).
 *
 * Req 12.2: quotes are verified byte-for-byte against the reply.
 * Req 12.3: a missing or non-matching action quote turns the action `unknown`.
 * Req 12.4: non-matching spoken lines and reported-speech items are dropped.
 */
class EvidenceCheckTest extends TestCase
{
    use TestTrait;

    private const FIXTURE = '/pilot/pretest_turn2_2026-11-02_n3.json';

    /**
     * @return array<string, mixed>
     */
    private function loadFixture(): array
    {
        $path = dirname(__DIR__, 2) . self::FIXTURE;
        $json = file_get_contents($path);
        $this->assertNotFalse($json, "could not read fixture {$path}");

        $data = json_decode($json, true);
        $this->assertIsArray($data, 'fixture did not decode to an array');
        $this->assertArrayHasKey('runs', $data);
        $this->assertCount(18, $data['runs'], 'fixture should hold 18 runs');

        return $data;
    }

    // ------------------------------------------------------------------
    // Part A.1 - every quote in all 18 readings is a real substring
    // ------------------------------------------------------------------

    public function testAll18ReadingQuotesAreSubstringsOfTheReply(): void
    {
        $data = $this->loadFixture();

        foreach ($data['runs'] as $i => $run) {
            $reply   = $run['turn1']['reply'];
            $reading = $run['reading'];

            $this->assertTrue(
                str_contains($reply, $reading['quote']),
                "run {$i}: reading.quote is not an exact substring of the reply"
            );

            foreach ($reading['words'] as $k => $word) {
                $this->assertTrue(
                    str_contains($reply, $word),
                    "run {$i}: reading.words[{$k}] is not an exact substring of the reply"
                );
            }

            foreach ($reading['indirect'] as $k => $ind) {
                $this->assertTrue(
                    str_contains($reply, $ind['quote']),
                    "run {$i}: reading.indirect[{$k}].quote is not an exact substring of the reply"
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // Part A.2 - a real reading verifies cleanly through EvidenceCheck
    // ------------------------------------------------------------------

    public function testRealReadingSurvivesVerification(): void
    {
        $data = $this->loadFixture();

        // Pick a run whose reading has a non-empty action quote and at least
        // one reported-speech item so the assertions below are meaningful.
        $run = null;
        foreach ($data['runs'] as $candidate) {
            $rd = $candidate['reading'];
            if ($rd['quote'] !== '' && count($rd['indirect']) > 0) {
                $run = $candidate;
                break;
            }
        }
        $this->assertNotNull($run, 'expected at least one run with a quote and reported speech');

        $reply   = $run['turn1']['reply'];
        $reading = $run['reading'];

        $reported = [];
        foreach ($reading['indirect'] as $ind) {
            $reported[] = new ReportedSpeech($ind['quote'], $ind['heard_as'], $ind['own_form']);
        }

        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::use->value,
            targetId: null,
            targetSpot: null,
            words: $reading['words'],
            actionQuote: $reading['quote'],
            reported: $reported,
        );

        $verified = EvidenceCheck::verify($reply, $action);

        $this->assertSame(Operation::use->value, $verified->operation, 'operation should survive');
        $this->assertSame($reading['quote'], $verified->actionQuote, 'action quote should survive');
        $this->assertSame($reading['words'], $verified->words, 'all spoken lines should survive');
        $this->assertCount(count($reported), $verified->reported, 'all reported items should survive');
    }

    // ------------------------------------------------------------------
    // Part A.3 - hand-made failing cases
    // ------------------------------------------------------------------

    public function testBogusActionQuoteBecomesUnknown(): void
    {
        $reply = 'I walk toward the kitchen and listen for the old man.';

        $action = new Action(
            robotId: 'robot_b',
            operation: Operation::move->value,
            targetId: 'kitchen',
            targetSpot: 'doorway',
            words: [],
            actionQuote: 'I sprint across the rooftop', // not in the reply
            reported: [],
        );

        $verified = EvidenceCheck::verify($reply, $action);

        $this->assertSame(Operation::unknown->value, $verified->operation);
        $this->assertNull($verified->targetId);
        $this->assertNull($verified->targetSpot);
        $this->assertNull($verified->actionQuote);
        $this->assertSame('robot_b', $verified->robotId, 'robotId is kept');
    }

    public function testNullActionQuoteBecomesUnknown(): void
    {
        $reply = 'I stand still and think.';

        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::take->value,
            targetId: 'flashlight',
            targetSpot: null,
            words: [],
            actionQuote: null,
            reported: [],
        );

        $verified = EvidenceCheck::verify($reply, $action);

        $this->assertSame(Operation::unknown->value, $verified->operation);
        $this->assertNull($verified->targetId);
        $this->assertNull($verified->actionQuote);
    }

    public function testBogusSpokenLineIsDroppedValidOneKept(): void
    {
        $valid = 'Earthquake! Get under the table!';
        $reply = "I shout to the old man. {$valid} Then I brace myself.";

        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::speak->value,
            targetId: null,
            targetSpot: null,
            words: [$valid, 'I never said this line at all'],
            actionQuote: 'I shout to the old man.',
            reported: [],
        );

        $verified = EvidenceCheck::verify($reply, $action);

        $this->assertSame([$valid], $verified->words, 'only the quoted spoken line survives');
        $this->assertSame(Operation::speak->value, $verified->operation);
    }

    public function testBogusReportedSpeechIsDroppedValidOneKept(): void
    {
        $validQuote = 'I call out to the other robot to take cover';
        $reply      = "Right now {$validQuote} and I move to the doorway.";

        $good = new ReportedSpeech($validQuote, 'call out to you to take cover', 'called out to the other robot to take cover');
        $bad  = new ReportedSpeech('I whisper a secret plan', 'whisper a secret plan', 'whispered a secret plan');

        $action = new Action(
            robotId: 'robot_b',
            operation: Operation::move->value,
            targetId: 'living_room',
            targetSpot: 'doorway',
            words: [],
            actionQuote: 'I move to the doorway',
            reported: [$bad, $good], // bogus first, to confirm ordering keeps the valid one
        );

        $verified = EvidenceCheck::verify($reply, $action);

        $this->assertCount(1, $verified->reported, 'only the quoted reported item survives');
        $this->assertSame($validQuote, $verified->reported[0]->quote);
        $this->assertSame(Operation::move->value, $verified->operation, 'valid action quote keeps the operation');
    }

    // ------------------------------------------------------------------
    // Part B - Property 12 (Req 11.14)
    // ------------------------------------------------------------------

    /**
     * Property 12: any exact substring of the reply passes EvidenceCheck
     * (the action keeps its operation, the spoken line survives, the reported
     * item survives), while a string that is not a substring turns the action
     * `unknown` and is dropped from words / reported.
     *
     * **Validates: Requirements 11.14**
     */
    public function testProperty12SubstringsPassNonSubstringsFail(): void
    {
        // Require at least 100 successful evaluations of the property.
        $this->limitTo(100);

        // Keep the alphabet ASCII so byte-for-byte substring math is simple
        // and the derived substring cannot collide with the sentinel below.
        $this->forAll(
            Generator\suchThat(
                static fn (string $s): bool => $s !== '',
                Generator\string()
            ),
            Generator\nat(),
            Generator\nat()
        )->then(
            function (string $reply, int $a, int $b): void {
                $len = strlen($reply);

                // Derive a genuine, non-empty substring of the reply.
                $start  = $a % $len;
                $maxLen = $len - $start;
                $subLen = ($b % $maxLen) + 1; // 1..$maxLen
                $sub    = substr($reply, $start, $subLen);

                $this->assertTrue(str_contains($reply, $sub), 'derived piece must be a substring');

                // --- positive: a real substring passes every check ---
                $good = new ReportedSpeech($sub, 'heard', 'own');
                $pass = EvidenceCheck::verify($reply, new Action(
                    robotId: 'robot_a',
                    operation: Operation::use->value,
                    targetId: 'stove',
                    targetSpot: null,
                    words: [$sub],
                    actionQuote: $sub,
                    reported: [$good],
                ));

                $this->assertSame(Operation::use->value, $pass->operation, 'substring action quote keeps operation');
                $this->assertSame('stove', $pass->targetId, 'target kept when quote verifies');
                $this->assertSame([$sub], $pass->words, 'substring spoken line survives');
                $this->assertCount(1, $pass->reported, 'substring reported item survives');

                // --- negative: a sentinel that cannot be a substring fails ---
                // The reply is drawn from printable ASCII, so a run of NUL
                // bytes can never appear inside it.
                $sentinel = "\x00\x00SENTINEL\x00\x00";
                $this->assertFalse(str_contains($reply, $sentinel), 'sentinel must not be a substring');

                $bad  = new ReportedSpeech($sentinel, 'heard', 'own');
                $fail = EvidenceCheck::verify($reply, new Action(
                    robotId: 'robot_a',
                    operation: Operation::use->value,
                    targetId: 'stove',
                    targetSpot: null,
                    words: [$sentinel],
                    actionQuote: $sentinel,
                    reported: [$bad],
                ));

                $this->assertSame(Operation::unknown->value, $fail->operation, 'non-substring quote -> unknown');
                $this->assertNull($fail->targetId, 'target cleared on unknown');
                $this->assertNull($fail->actionQuote, 'action quote cleared on unknown');
                $this->assertSame([], $fail->words, 'non-substring spoken line dropped');
                $this->assertSame([], $fail->reported, 'non-substring reported item dropped');
            }
        );
    }
}
