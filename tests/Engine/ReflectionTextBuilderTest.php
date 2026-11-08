<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\ActionExecutor;
use PoltergeistSwitch\Engine\EarthquakePhase;
use PoltergeistSwitch\Engine\Engine;
use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\ReflectionTextBuilder;
use PoltergeistSwitch\Engine\ResidentController;
use PoltergeistSwitch\Engine\RulesEvaluator;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\Values\TurnRecord;
use PoltergeistSwitch\Engine\WorldModel;
use PoltergeistSwitch\Memory\Values\MemorySnapshot;

/**
 * Deterministic EXAMPLE tests for ReflectionTextBuilder (Requirement 1).
 *
 * The structure and banned-word guarantees are covered universally by the two
 * property tests in tests/Engine/Properties (Properties 6 and 7). This file
 * pins the exact wording those properties do not assert by literal: the
 * forward count-up heading literals (Req 1.4), the exact lead-in and question
 * literals (Req 1.2, 1.10), a real event block carrying the man's line heard
 * and the house events perceived (shaking start/continue/stop, lights out,
 * objects falling/breaking where visible — Req 1.3, reusing house-rules-engine
 * Req 9.7's break outcomes), and the neutral line (Req 1.3).
 *
 * Every test drives a REAL earthquake episode from a fixed seed through the
 * live Engine (prepareTurn / applyTurn with earthquakeStartTurn = 0), captures
 * genuine TurnRecords exactly as the live loop stores them, and feeds them to
 * the pure builder — so the literals asserted are the literals a real
 * reflection renders, not hand-built fixtures.
 *
 * Validates: Requirements 1.2, 1.3, 1.4, 1.10, 9.7
 */
class ReflectionTextBuilderTest extends TestCase
{
    private const LEAD_IN = 'Here is what happened after the shaking began:';

    private const QUESTION = 'Is there anything from what happened this time that you want to remember?';

    private const NEUTRAL_LINE = 'Nothing happened that you noticed.';

    /**
     * The lead-in and the question appear as their own fixed sections: the
     * lead-in once, immediately before the first event block, and the question
     * as the final section after a blank line (Req 1.2, Req 1.10).
     */
    public function testLeadInAndQuestionAreTheExactLiterals(): void
    {
        [$records, $finalWorld] = $this->runEarthquake(12345, 4, 'robot_a');
        $this->assertNotEmpty($records, 'the fixture episode must play at least one turn');

        $builder = new ReflectionTextBuilder(new SituationTextGenerator());
        $reflection = $builder->build('robot_a', $records, 0, $finalWorld, new MemorySnapshot([]));

        // The lead-in is its own section, appearing exactly once.
        $this->assertStringContainsString("\n\n" . self::LEAD_IN . "\n\n", $reflection);
        $this->assertSame(
            1,
            substr_count($reflection, self::LEAD_IN),
            'the lead-in must appear exactly once'
        );

        // The text ends with a blank line then the exact question.
        $this->assertStringEndsWith("\n\n" . self::QUESTION, $reflection);
        $this->assertSame(
            1,
            substr_count($reflection, self::QUESTION),
            'the question must appear exactly once, as the final section'
        );
    }

    /**
     * The event blocks are headed by the forward count-up literals: turn 1 is
     * "0 to 15 seconds after the shaking began:", turn 2 "15 to 30 …", turn 3
     * "30 to 45 …", each in 15-second steps counting up from the first played
     * turn (Req 1.4).
     */
    public function testEventBlockHeadingsCountUpFromZeroInFifteenSecondSteps(): void
    {
        // Play turns 0..3; turns 1,2,3 are recorded (turn 0 is the quiet turn).
        [$records, $finalWorld] = $this->runEarthquake(12345, 4, 'robot_a');
        $this->assertCount(3, $records, 'turns 1, 2 and 3 must be recorded');

        $builder = new ReflectionTextBuilder(new SituationTextGenerator());
        $reflection = $builder->build('robot_a', $records, 0, $finalWorld, new MemorySnapshot([]));

        // The three exact count-up headings, in strictly increasing position.
        $expected = [
            '0 to 15 seconds after the shaking began:',
            '15 to 30 seconds after the shaking began:',
            '30 to 45 seconds after the shaking began:',
        ];

        $prev = -1;
        foreach ($expected as $k => $heading) {
            $pos = strpos($reflection, "\n\n" . $heading . "\n");
            $this->assertNotFalse(
                $pos,
                "event block {$k} must be headed by the literal '{$heading}'"
            );
            $this->assertGreaterThan(
                $prev,
                $pos,
                'the count-up headings must appear oldest first'
            );
            $prev = $pos;
        }
    }

    /**
     * A real event block carries the house events the robot perceived, in the
     * turn texts' exact wording (Req 1.3, reusing Req 9.14): the first shaking
     * turn opens its block with "Suddenly the floor begins to shake hard." and
     * the room shake text for the robot's room; a later shaking turn reads
     * "The floor is still shaking hard."; the lights-out turn carries "The
     * lights go out."; and the per-turn block never repeats the static
     * room-object list ("there are:").
     */
    public function testEventBlockRendersPerceivedHouseEventsWithoutRepeatingTheObjectList(): void
    {
        [$records, $finalWorld] = $this->runEarthquake(12345, 4, 'robot_a');
        $this->assertCount(3, $records);

        $builder = new ReflectionTextBuilder(new SituationTextGenerator());
        $reflection = $builder->build('robot_a', $records, 0, $finalWorld, new MemorySnapshot([]));

        $blocks = $this->eventBlocks($reflection);
        $this->assertCount(3, $blocks, 'one event block per recorded turn');

        // Turn 1 (offset 1): the man shouts and robot_a shares his room, so the
        // man's line is rendered in the same-room form (Req 9.10–9.11), right
        // after the house events.
        $this->assertStringContainsString(
            'The man shouts, "What\'s happening?!"',
            $blocks[0],
            'the man\'s line heard must render in the same-room shout form'
        );

        // Turn 1 (offset 1) is the first shaking turn: its block opens the
        // house line with the "begins to shake" sentence.
        $this->assertStringContainsString(
            'Suddenly the floor begins to shake hard.',
            $blocks[0],
            'the first shaking turn must render the shaking-start sentence'
        );

        // The perceived house events precede the man's line in the block, as
        // in the turn texts where the shaking paragraph ends with his shout
        // (Req 1.3; order fixed by the maintainer's review on 2026-11-09).
        $this->assertLessThan(
            strpos($blocks[0], 'The man shouts, "What\'s happening?!"'),
            strpos($blocks[0], 'Suddenly the floor begins to shake hard.'),
            'the house events must precede the man\'s line in the event block'
        );

        // Turn 2 (offset 2) is still shaking: the "still shaking" sentence, and
        // it is the power-outage turn, so the lights-out sentence appears.
        $this->assertStringContainsString(
            'The floor is still shaking hard.',
            $blocks[1],
            'a continuing shaking turn must render the shaking-continue sentence'
        );
        $this->assertStringContainsString(
            'The lights go out.',
            $blocks[1],
            'the lights-out turn must render the lights-out sentence'
        );

        // The static per-room object list is never repeated inside an event
        // block (Req 1.3): that list only ever appears in the "Right now:"
        // paragraph, which uses "there are:".
        foreach ($blocks as $k => $block) {
            $this->assertStringNotContainsString(
                'there are:',
                $block,
                "event block {$k} must not repeat the static object list"
            );
        }
    }

    /**
     * The shaking stops after the earthquake's shaking turns. The event block
     * for the turn on which it stops renders the exact "The shaking has
     * stopped." sentence (Req 1.3, reusing the turn texts' stop wording). The
     * shaking offsets are 1,2,3,4 then a gap, so turn 5 (offset 5) is the stop
     * turn: its block carries the stop sentence.
     */
    public function testStopTurnRendersTheShakingStoppedSentence(): void
    {
        // Play through turn 5 so the stop turn (offset 5) is recorded.
        [$records, $finalWorld] = $this->runEarthquake(12345, 6, 'robot_a');
        $this->assertCount(5, $records, 'turns 1..5 must be recorded');

        $builder = new ReflectionTextBuilder(new SituationTextGenerator());
        $reflection = $builder->build('robot_a', $records, 0, $finalWorld, new MemorySnapshot([]));

        $blocks = $this->eventBlocks($reflection);
        // Block index 4 is turn 5 (offset 5), the first non-shaking turn after
        // the shaking offsets 1..4: the shaking stops here.
        $this->assertStringContainsString(
            'The shaking has stopped.',
            $blocks[4],
            'the stop turn must render the shaking-stopped sentence'
        );
    }

    /**
     * When a robot kept out of the earthquake's reach and heard nothing new on
     * a turn whose event block would otherwise be empty, the block shows the
     * single neutral line so the count-up stays unbroken (Req 1.3).
     *
     * The builder renders the neutral line for any played turn whose perceived
     * and acted lines are all empty. We construct one such turn directly on top
     * of a real snapshot captured from a live run: a `wait` with an `unknown`
     * action (no result line), no spoken words, no resident line, and a turn
     * offset with no shaking event, so the block collapses to the neutral line.
     */
    public function testEmptyTurnRendersTheNeutralLine(): void
    {
        // A real snapshot to hang the record on; its facts are not used because
        // the constructed turn has no perceived or acted lines.
        [$records] = $this->runEarthquake(12345, 2, 'robot_a');
        $this->assertNotEmpty($records);
        $snapshot = $records[0]->snapshot;

        // A quiet turn well after the shaking (offset 7: not in 1,2,3,4,12 and
        // offset-1 = 6 is also quiet), with an unknown action and no words, no
        // resident line: the event block has no perceived and no acted lines.
        $quietTurn = new TurnRecord(
            turnNumber: 7,
            robotActions: [
                'robot_a' => new Action(robotId: 'robot_a', operation: Operation::unknown->value),
                'robot_b' => new Action(robotId: 'robot_b', operation: Operation::unknown->value),
            ],
            judgements: [],
            outcomes: [],
            residentLine: null,
            snapshot: $snapshot,
            roomsAtStart: [
                'robot_a' => 'bedroom',
                'robot_b' => 'storeroom',
            ],
        );

        $config = new EpisodeConfig();
        $finalWorld = new WorldModel($config);
        $finalWorld->reset(Seed::fromInt(12345));

        $builder = new ReflectionTextBuilder(new SituationTextGenerator());
        $reflection = $builder->build('robot_a', [$quietTurn], 0, $finalWorld, new MemorySnapshot([]));

        $blocks = $this->eventBlocks($reflection);
        $this->assertCount(1, $blocks);

        // The single block is its heading line plus exactly the neutral line.
        $lines = explode("\n", $blocks[0]);
        $this->assertSame(
            '90 to 105 seconds after the shaking began:',
            $lines[0],
            'offset 7 heads at k = 6: 90 to 105 seconds'
        );
        $this->assertSame(
            self::NEUTRAL_LINE,
            $lines[1] ?? null,
            'an otherwise-empty block shows exactly the neutral line'
        );
        $this->assertCount(2, $lines, 'the neutral block is the heading and one neutral line only');
    }

    /**
     * Extract the event blocks: the "\n\n"-separated sections between the
     * lead-in and the trailing "Right now:" paragraph. With an empty snapshot
     * there is no memories block, so everything between the lead-in and
     * "Right now:" is event blocks, oldest first.
     *
     * @return string[]
     */
    private function eventBlocks(string $reflection): array
    {
        $start = strpos($reflection, "\n\n" . self::LEAD_IN . "\n\n");
        $this->assertNotFalse($start, 'the lead-in must be present');
        $start += strlen("\n\n" . self::LEAD_IN . "\n\n");

        $end = strpos($reflection, "\n\nRight now:", $start);
        $this->assertNotFalse($end, 'the trailing "Right now:" paragraph must be present');

        return explode("\n\n", substr($reflection, $start, $end - $start));
    }

    /**
     * Drive a real earthquake episode from the seed for $turnCount played turns
     * (turns 0 .. $turnCount-1), capturing genuine TurnRecords from turn 1 on
     * (turn 0 is the quiet turn, not part of the recap). Both robots speak a
     * short fixed line each turn. Returns the records and the post-episode
     * world.
     *
     * @return array{0: TurnRecord[], 1: WorldModel}
     */
    private function runEarthquake(int $seedValue, int $turnCount, string $robotId): array
    {
        $config = new EpisodeConfig();
        $world = new WorldModel($config);
        $world->reset(Seed::fromInt($seedValue));

        $engine = new Engine(
            $world,
            $config,
            new RulesEvaluator(),
            new ActionExecutor(),
            new EarthquakePhase(),
            new ResidentController(),
            new SituationTextGenerator(),
        );

        // Flip the earthquake on from turn 0 so turn 1 (offset 1) is the first
        // shaking turn and the first played turn of the recap.
        $world->earthquakeStartTurn = 0;

        /** @var TurnRecord[] $records */
        $records = [];

        for ($turn = 0; $turn < $turnCount; $turn++) {
            $engine->prepareTurn($turn);

            $roomsAtStart = [
                'robot_a' => $world->robotStates['robot_a']->room,
                'robot_b' => $world->robotStates['robot_b']->room,
            ];

            $actions = [
                'robot_a' => new Action(
                    robotId: 'robot_a',
                    operation: Operation::speak->value,
                    words: ['Hello there.'],
                    actionQuote: 'Hello there.',
                ),
                'robot_b' => new Action(
                    robotId: 'robot_b',
                    operation: Operation::speak->value,
                    words: ['Hello there.'],
                    actionQuote: 'Hello there.',
                ),
            ];

            $result = $engine->applyTurn($turn, $actions);
            if ($result->isError) {
                break;
            }

            if ($turn >= 1) {
                $records[] = new TurnRecord(
                    turnNumber: $turn,
                    robotActions: $actions,
                    judgements: $result->judgements,
                    outcomes: $result->outcomes,
                    residentLine: $result->residentLine,
                    snapshot: $result->worldSnapshot,
                    roomsAtStart: $roomsAtStart,
                );
            }

            if ($result->episodeEnded) {
                break;
            }
        }

        return [$records, $world];
    }
}
