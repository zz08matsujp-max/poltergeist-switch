<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Engine\WorldModel;

/**
 * Property-based tests for SituationTextGenerator (Eris).
 *
 * Property 6 — Banned words absent (Req 9.15, Req 11.7):
 *   For any seed, the engine-authored sections of the situation text sent to
 *   either robot (the fixed body, the fixed house, the "Right now:" paragraph
 *   and the shaking paragraph) must never contain any of the 13 banned words.
 *   The robots must only ever see facts: no company names, no value words. The
 *   text is generated for a freshly reset world with the shaking flag raised so
 *   the shaking paragraph is always present and gets checked too.
 *
 * Property 7 — Fixed sections identical (Req 9.2, Req 11.8):
 *   For any seed, the fixed body + fixed house that open the text are
 *   byte-for-byte identical for robot_a and robot_b. Both robots get the same
 *   structure and the same fixed text; only their own viewpoint ("Right now:"
 *   onward) differs.
 *
 * NOTE: this Eris release (giorgiosironi/eris ^1.3) controls the number of
 * iterations with `limitTo()`; limitTo(100) makes the intended "at least 100
 * cases" explicit, matching the other property tests.
 */
class SituationTextPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * The 13 words that must never reach a robot (Req 9.15). Five company /
     * product names plus eight value words. The list mirrors BANNED_WORDS in
     * SituationTextGenerator; the match is case-insensitive, as there, so
     * "danger" inside "dangerous" is caught by the shorter entry too.
     *
     * @var string[]
     */
    private const BANNED_WORDS = [
        'Claude', 'ChatGPT', 'GPT', 'Anthropic', 'OpenAI',
        'dangerous', 'danger', 'safe', 'careful', 'should', 'must', 'warning',
    ];

    /**
     * Property 6: banned words absent from the engine-authored sections.
     *
     * Validates: Requirements 9.15, 11.7
     */
    public function testBannedWordsNeverAppearInEngineAuthoredSections(): void
    {
        $this->limitTo(100)
            ->forAll(Generators::int())
            ->then(function (int $seedValue): void {
                $generator = new SituationTextGenerator();

                foreach (['robot_a', 'robot_b'] as $robotId) {
                    // A fresh, shaking world so the text carries the fixed body,
                    // the fixed house, the "Right now:" paragraph and the
                    // shaking paragraph — the four engine-authored sections.
                    $world = $this->shakingWorld($seedValue);

                    $text = $generator->generate($robotId, $world, []);
                    $sections = $this->engineAuthoredSections($text);

                    foreach (self::BANNED_WORDS as $word) {
                        $this->assertFalse(
                            stripos($sections, $word) !== false,
                            "Banned word '{$word}' appeared in the {$robotId} situation "
                                . "text (seed {$seedValue})"
                        );
                    }
                }
            });
    }

    /**
     * Property 7: the fixed body + fixed house are identical for both robots.
     *
     * Validates: Requirements 9.2, 11.8
     */
    public function testFixedSectionsAreIdenticalForBothRobots(): void
    {
        $this->limitTo(100)
            ->forAll(Generators::int())
            ->then(function (int $seedValue): void {
                $generator = new SituationTextGenerator();

                $textA = $generator->generate('robot_a', $this->shakingWorld($seedValue), []);
                $textB = $generator->generate('robot_b', $this->shakingWorld($seedValue), []);

                $this->assertSame(
                    $this->fixedPrefix($textA),
                    $this->fixedPrefix($textB),
                    "fixed body + house must be byte-for-byte equal for both robots (seed {$seedValue})"
                );
            });
    }

    /**
     * A freshly reset world for the given seed with the shaking flag raised, so
     * the generated text always includes the shaking paragraph. No robot
     * actions and no earthquake offsets are applied: only the single boolean is
     * flipped, which is enough for SituationTextGenerator to emit the
     * "Suddenly the floor begins to shake hard." paragraph.
     */
    private function shakingWorld(int $seedValue): WorldModel
    {
        $world = new WorldModel();
        $world->reset(Seed::fromInt($seedValue));
        $world->houseState->shaking = true;

        return $world;
    }

    /**
     * Return the engine-authored sections of a full situation text: everything
     * from the start through the shaking paragraph, i.e. the whole text except
     * the trailing final-line section. With no memories and no recent events,
     * the generated text is exactly
     *   body \n\n house \n\n "Right now: …" \n\n shaking \n\n final line
     * so dropping the last "\n\n"-separated section leaves precisely the four
     * engine-authored sections the banned-word rule covers.
     */
    private function engineAuthoredSections(string $text): string
    {
        $cut = strrpos($text, "\n\n");
        if ($cut === false) {
            return $text;
        }

        return substr($text, 0, $cut);
    }

    /**
     * Return the fixed prefix of a full situation text: the fixed body and
     * fixed house, i.e. everything before the "Right now:" paragraph. With no
     * memories and no recent events, "Right now:" is the first viewpoint-
     * dependent section, so the substring before it is exactly the two fixed
     * sections (plus their trailing blank-line separator), which is identical
     * for both robots.
     */
    private function fixedPrefix(string $text): string
    {
        $pos = strpos($text, 'Right now:');
        if ($pos === false) {
            return $text;
        }

        return substr($text, 0, $pos);
    }
}
