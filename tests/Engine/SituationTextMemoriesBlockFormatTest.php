<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\RobotState;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Engine\SituationView;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\Values\Outcome;
use PoltergeistSwitch\Engine\Values\TurnRecord;
use PoltergeistSwitch\Engine\Values\WorldSnapshot;

/**
 * Example test for the Appendix A Memories_Block format and its position in a
 * full turn text (robot-memory Requirements 6.3, 6.4, 9.9; task 6.4).
 *
 * Two things are pinned here, deterministically (no fixtures, no randomness):
 *
 *   1. The exact bytes of the block built by
 *      SituationTextGenerator::buildMemoriesBlock from a known ordered body
 *      list — the heading, a blank line, then each body wrapped in its own
 *      `"""` block, oldest first, verbatim, with a blank line between
 *      successive blocks (the Appendix A form from design.md).
 *
 *   2. Its position inside a full generated turn text: section 3, immediately
 *      after the fixed house section and immediately before the recent-events
 *      section. To make the ordering observable the turn text is built with a
 *      non-empty recent-events history, so the "In the last 15 seconds:" block
 *      is present and the block must sit strictly between the house section and
 *      it.
 */
class SituationTextMemoriesBlockFormatTest extends TestCase
{
    /** The verbatim Appendix A heading (robot-memory Requirement 6.4). */
    private const HEADING =
        'Your memories (what you wrote down after an earlier disaster in this house):';

    /**
     * The fixed house section — the section the memories block must follow.
     * Kept in sync with SituationTextGenerator::FIXED_HOUSE (a bump there would
     * move the golden situation-text tests too).
     */
    private const FIXED_HOUSE =
        "This is a single-story house in the Japanese countryside, around 1986. "
        . "It has electricity, running water, and gas, but no internet and no mobile phones. "
        . "You cannot go outside.\n\n"
        . "A human lives here with you: a man, 80 years old. His legs are bad and he moves very slowly.\n\n"
        . 'Another robot also lives in this house.';

    /**
     * Requirements 6.3, 6.4: buildMemoriesBlock renders the Appendix A form
     * byte-for-byte. Three ordered bodies (oldest first) produce the heading, a
     * blank line, then three `"""` blocks separated by blank lines, each body
     * verbatim between its fences.
     */
    public function testBlockBytesAreTheAppendixAForm(): void
    {
        $bodies = [
            'The dishes fell and broke. I stayed by the old man.',
            'When the lights went out I found the flashlight in the kitchen.',
            'The shaking stopped. The old man was shaken but unhurt.',
        ];

        $expected =
            self::HEADING . "\n\n"
            . '"""' . "\n" . $bodies[0] . "\n" . '"""' . "\n\n"
            . '"""' . "\n" . $bodies[1] . "\n" . '"""' . "\n\n"
            . '"""' . "\n" . $bodies[2] . "\n" . '"""';

        $generator = new SituationTextGenerator();

        $this->assertSame(
            $expected,
            $generator->buildMemoriesBlock($bodies),
            'the memories block must be the exact Appendix A form, oldest first, each body in its own """ block'
        );
    }

    /**
     * Requirement 6.4: a single memory still gets its own `"""` block with the
     * heading and the one blank-line separator, and nothing more.
     */
    public function testSingleBodyBlockBytes(): void
    {
        $body = 'I remember the floor shaking hard.';

        $expected = self::HEADING . "\n\n" . '"""' . "\n" . $body . "\n" . '"""';

        $generator = new SituationTextGenerator();

        $this->assertSame(
            $expected,
            $generator->buildMemoriesBlock([$body]),
            'a one-memory block is the heading, a blank line, then a single """ block'
        );
    }

    /**
     * Requirement 6.7 (verbatim): a body containing blank lines and quote marks
     * is passed through unchanged between its fences — the generator never
     * reflows or escapes the robot's own words.
     */
    public function testBodyIsVerbatimBetweenFences(): void
    {
        $body = "I said, \"Stay low!\"\n\nThen the radio fell.";

        $expected = self::HEADING . "\n\n" . '"""' . "\n" . $body . "\n" . '"""';

        $generator = new SituationTextGenerator();

        $this->assertSame(
            $expected,
            $generator->buildMemoriesBlock([$body]),
            'a memory body is placed verbatim between its """ fences'
        );
    }

    /**
     * Requirements 6.3, 9.9: in a full turn text with recent events, the
     * memories block sits strictly between the fixed house section and the
     * recent-events ("In the last 15 seconds:") section.
     *
     * The fixed house section itself contains blank lines, so the ordering is
     * pinned by byte position: the whole block (its exact Appendix A bytes)
     * appears after the house section and before the recent-events block, i.e.
     *   house section  <  memories block  <  recent-events block.
     */
    public function testBlockSitsBetweenHouseAndRecentEventsInAFullTurnText(): void
    {
        $bodies = [
            'The dishes fell and broke. I stayed by the old man.',
            'The shaking stopped. The old man was shaken but unhurt.',
        ];

        $generator = new SituationTextGenerator();
        $block     = $generator->buildMemoriesBlock($bodies);
        $this->assertNotNull($block, 'a non-empty body list must produce a block');

        // A turn-2 text: one previous turn in which the other robot acted while
        // sharing the robot's room, so a non-empty recent-events block renders.
        $world  = $this->buildWorld('afternoon', 'living_room', 'living_room');
        $actual = $generator->generate(
            'robot_a',
            $world,
            [$this->sharedRoomTurn(1, 'living_room')],
            $block,
        );

        // All three markers are present in the full text.
        $housePos  = strpos($actual, self::FIXED_HOUSE);
        $blockPos  = strpos($actual, $block);
        $recentPos = strpos($actual, 'In the last 15 seconds:');
        $this->assertNotFalse($housePos, 'the full turn text must contain the fixed house section');
        $this->assertNotFalse($blockPos, 'the full turn text must contain the exact memories block bytes');
        $this->assertNotFalse($recentPos, 'the full turn text must contain a recent-events block');

        // The block is inserted whole and verbatim, strictly between the house
        // section and the recent-events block:
        //   house section  <  memories block  <  recent-events block.
        $this->assertGreaterThan($housePos, $blockPos, 'the block must follow the fixed house section');
        $this->assertLessThan($recentPos, $blockPos, 'the block must precede the recent-events section');

        // Nothing is inserted between the house section and the block, nor
        // between the block and the recent-events block, other than the "\n\n"
        // section separators: the block is section 3 exactly.
        $afterHouse = $housePos + strlen(self::FIXED_HOUSE);
        $this->assertSame(
            "\n\n",
            substr($actual, $afterHouse, 2),
            'the house section is immediately followed by a blank-line separator then the block',
        );
        $this->assertSame(
            $afterHouse + 2,
            $blockPos,
            'the memories block begins immediately after the house section and its separator',
        );
        $afterBlock = $blockPos + strlen($block);
        $this->assertSame(
            "\n\n",
            substr($actual, $afterBlock, 2),
            'the block is immediately followed by a blank-line separator then the recent-events section',
        );
        $this->assertSame(
            $afterBlock + 2,
            $recentPos,
            'the recent-events block begins immediately after the memories block and its separator',
        );
    }

    /**
     * A one-turn TurnRecord in which only the other robot acts and speaks,
     * sharing the viewpoint robot's room at start and end so rule A shows its
     * action. Used to force a non-empty recent-events block.
     */
    private function sharedRoomTurn(int $turnNumber, string $room): TurnRecord
    {
        $otherAction = new Action(
            robotId: 'robot_b',
            operation: 'cover',
            words: ['Stay low!'],
            actionQuote: 'I cover my head',
            reported: [],
        );
        $otherOutcome = new Outcome(
            robotId: 'robot_b',
            success: true,
            whatHappenedText: 'The other robot covered its head.',
            seenByOtherText: 'The other robot covered its head.',
        );
        $snapshot = new WorldSnapshot(
            objects: [],
            robotStates: [
                'robot_a' => new RobotState(id: 'robot_a', room: $room),
                'robot_b' => new RobotState(id: 'robot_b', room: $room),
            ],
            residentState: null,
            houseState: null,
        );

        return new TurnRecord(
            turnNumber: $turnNumber,
            robotActions: ['robot_b' => $otherAction],
            judgements: [],
            outcomes: ['robot_b' => $otherOutcome],
            residentLine: null,
            snapshot: $snapshot,
            roomsAtStart: ['robot_a' => $room, 'robot_b' => $room],
        );
    }

    /**
     * A minimal SituationView double: viewpoint robot_a, the other robot in the
     * same room, the man sitting at the low table in the living room, not
     * shaking this turn (keeps the turn text simple and the ordering clear).
     */
    private function buildWorld(string $time, string $robotARoom, string $robotBRoom): SituationView
    {
        return new class($time, $robotARoom, $robotBRoom) implements SituationView {
            public function __construct(
                private string $time,
                private string $robotARoom,
                private string $robotBRoom,
            ) {
            }

            public function timeOfDay(): string
            {
                return $this->time;
            }

            public function robotRoom(string $robotId): string
            {
                return $robotId === 'robot_a' ? $this->robotARoom : $this->robotBRoom;
            }

            public function residentRoom(): string
            {
                return 'living_room';
            }

            public function residentActivity(): string
            {
                return 'sitting_at_table';
            }

            public function residentSpot(): ?string
            {
                return null;
            }

            public function isRoomDark(string $roomKey): bool
            {
                return false;
            }

            /** @return string[] */
            public function visibleObjectPhrases(string $roomKey): array
            {
                return [];
            }

            public function isShaking(): bool
            {
                return false;
            }

            public function isPotBoiling(): bool
            {
                return false;
            }

            public function wasShakingPreviousTurn(): bool
            {
                return false;
            }

            public function didLightsGoOutThisTurn(): bool
            {
                return false;
            }

            public function residentSpeaksThisTurn(): bool
            {
                return false;
            }

            public function residentLine(): ?string
            {
                return null;
            }
        };
    }
}
