<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine\Properties;

use Eris\Generators;
use Eris\TestTrait;
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
 * Feature: robot-memory, Property 7: Reflection_Text structure, count-up
 * headings, and memories-block presence.
 *
 * For any ended episode driven from a random seed, and for a memory snapshot
 * that is randomly either empty or non-empty, the Reflection_Text the builder
 * produces has the fixed structure of Requirement 1.2–1.7, 1.10:
 *
 *   1. the fixed body section,
 *   2. the fixed house section,
 *   3. the memories block — present IFF the robot had memories this episode
 *      (Req 1.7); when present it uses the Appendix A heading and renders each
 *      body in its own `"""` block, oldest first (Req 1.7),
 *   4. the exact lead-in line (Req 1.2),
 *   5. one event block per played turn, oldest first, each headed by the
 *      forward count-up heading `{15·k} to {15·(k+1)} seconds after the
 *      shaking began:` with k counting up from the first played turn (Req 1.4),
 *   6. the `Right now:` paragraph for the world after the last turn (Req 1.2),
 *   7. a blank line, then the exact question (Req 1.10),
 *
 * in that order, each section separated by a blank line. Within every event
 * block the perceived items (the man's line and the house events) precede the
 * acted items (own action/speech, then the other robot's) — Req 1.3.
 *
 * The builder is pure and is fed real TurnRecords captured from a live engine
 * run, so the structure asserted is the structure a real reflection renders.
 *
 * NOTE: this Eris release (giorgiosironi/eris ^1.3) controls the iteration
 * count with `limitTo()`; `limitTo(100)` is required here, matching the other
 * property tests.
 *
 * Validates: Requirements 9.6, 1.2, 1.3, 1.4, 1.7, 1.10, 1.11
 */
class ReflectionTextStructurePropertyTest extends TestCase
{
    use TestTrait;

    private const LEAD_IN = 'Here is what happened after the shaking began:';

    private const QUESTION = 'Is there anything from what happened this time that you want to remember?';

    private const MEMORIES_HEADING =
        'Your memories (what you wrote down after an earlier disaster in this house):';

    /**
     * Property 7.
     *
     * Validates: Requirements 9.6, 1.2, 1.3, 1.4, 1.7, 1.10, 1.11
     */
    public function testReflectionTextHasTheFixedStructureCountUpHeadingsAndMemoriesBlock(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generators::int(),
                Generators::choose(1, 8),
                // Draw 0..3 memory bodies so the snapshot is sometimes empty
                // (no memories block) and sometimes non-empty (block present).
                Generators::choose(0, 3),
            )
            ->then(function (int $seedValue, int $turnCount, int $memoryCount): void {
                $builder = new ReflectionTextBuilder(new SituationTextGenerator());

                $bodies = [];
                for ($i = 0; $i < $memoryCount; $i++) {
                    // Distinct, ordered bodies so "oldest first" is checkable.
                    $bodies[] = "memory body number {$i}";
                }
                $snapshot = new MemorySnapshot($bodies);

                foreach (['robot_a', 'robot_b'] as $robotId) {
                    [$records, $finalWorld] = $this->runEpisode($seedValue, $turnCount, $robotId);
                    if ($records === []) {
                        return;
                    }

                    $reflection = $builder->build($robotId, $records, 0, $finalWorld, $snapshot);

                    // The sections appear in order. Each `"\n\n"`-separated
                    // memory body block would itself split on "\n\n", so we do
                    // NOT index sections 1:1; instead we check the fixed markers
                    // appear and are strictly ordered, and pin the memories
                    // block wording with an exact substring. The lead-in,
                    // "Right now:" and the question are unique markers.
                    $leadInPos = strpos($reflection, "\n\n" . self::LEAD_IN . "\n\n");
                    $this->assertNotFalse(
                        $leadInPos,
                        "the lead-in must appear as its own section (seed {$seedValue}, {$robotId})"
                    );

                    // Section 3: the memories block, present IFF non-empty (Req 1.7).
                    if ($snapshot->isEmpty()) {
                        $this->assertStringNotContainsString(
                            self::MEMORIES_HEADING,
                            $reflection,
                            "no memories block may appear for an empty snapshot "
                                . "(seed {$seedValue}, {$robotId})"
                        );
                    } else {
                        // Appendix A form: heading, then each body in its own
                        // triple-quote block joined by a blank line, oldest
                        // first, verbatim. Assert the exact block substring and
                        // that it sits between the house section and the lead-in.
                        $expectedBlock = self::MEMORIES_HEADING;
                        foreach ($bodies as $body) {
                            $expectedBlock .= "\n\n" . '"""' . "\n" . $body . "\n" . '"""';
                        }
                        $blockPos = strpos($reflection, $expectedBlock);
                        $this->assertNotFalse(
                            $blockPos,
                            "the memories block must render the Appendix A heading and each "
                                . "body in its own triple-quote block, oldest first "
                                . "(seed {$seedValue}, {$robotId})"
                        );
                        // The block precedes the lead-in (so it sits after the
                        // house section and before the recap).
                        $this->assertLessThan(
                            $leadInPos,
                            $blockPos,
                            "the memories block must come before the lead-in "
                                . "(seed {$seedValue}, {$robotId})"
                        );
                    }

                    // One event block per played turn, oldest first, each with
                    // the forward count-up heading (Req 1.3, Req 1.4). The
                    // headings must appear in strictly increasing position,
                    // after the lead-in.
                    $eventBlocks = $this->eventBlocks($reflection);
                    $this->assertCount(
                        count($records),
                        $eventBlocks,
                        "there must be exactly one event block per played turn "
                            . "(seed {$seedValue}, {$robotId})"
                    );

                    $prevPos = $leadInPos;
                    foreach ($eventBlocks as $k => $block) {
                        $from = 15 * $k;
                        $to = 15 * ($k + 1);
                        $expectedHeading = "{$from} to {$to} seconds after the shaking began:";
                        $this->assertStringStartsWith(
                            $expectedHeading,
                            $block,
                            "event block {$k} must open with the count-up heading "
                                . "'{$expectedHeading}' (seed {$seedValue}, {$robotId})"
                        );

                        $headingPos = strpos($reflection, "\n\n" . $expectedHeading . "\n");
                        $this->assertNotFalse($headingPos);
                        $this->assertGreaterThan(
                            $prevPos,
                            $headingPos,
                            "event blocks must appear oldest first (seed {$seedValue}, {$robotId})"
                        );
                        $prevPos = $headingPos;

                        // Within a block, perceived items precede acted items (Req 1.3).
                        $this->assertPerceivedBeforeActed($block, $seedValue, $robotId, $k);
                    }

                    // The "Right now:" paragraph comes after the last event
                    // block, then a blank line, then the exact question, which
                    // ends the text (Req 1.2, Req 1.10).
                    $rightNowPos = strpos($reflection, "\n\nRight now:");
                    $this->assertNotFalse(
                        $rightNowPos,
                        "the 'Right now:' paragraph must appear (seed {$seedValue}, {$robotId})"
                    );
                    $this->assertGreaterThan(
                        $prevPos,
                        $rightNowPos,
                        "the 'Right now:' paragraph must follow the last event block "
                            . "(seed {$seedValue}, {$robotId})"
                    );

                    $this->assertStringEndsWith(
                        "\n\n" . self::QUESTION,
                        $reflection,
                        "the reflection must end with a blank line and the exact question "
                            . "(seed {$seedValue}, {$robotId})"
                    );
                    $this->assertGreaterThan(
                        $rightNowPos,
                        strrpos($reflection, self::QUESTION),
                        "the question must come after the 'Right now:' paragraph "
                            . "(seed {$seedValue}, {$robotId})"
                    );
                }
            });
    }

    /**
     * Extract the event blocks from a reflection: the sections between the
     * lead-in and the trailing "Right now:" paragraph, split on the blank-line
     * separators. Each returned block opens with a count-up heading. The
     * memories block (which contains its own "\n\n" separators) sits BEFORE the
     * lead-in, so slicing from after the lead-in to before "Right now:" leaves
     * only the event blocks.
     *
     * @return string[] event blocks, oldest first
     */
    private function eventBlocks(string $reflection): array
    {
        $start = strpos($reflection, "\n\n" . self::LEAD_IN . "\n\n");
        if ($start === false) {
            return [];
        }
        $start += strlen("\n\n" . self::LEAD_IN . "\n\n");

        $end = strpos($reflection, "\n\nRight now:", $start);
        if ($end === false) {
            return [];
        }

        $middle = substr($reflection, $start, $end - $start);

        return explode("\n\n", $middle);
    }

    /**
     * Assert that in one event block every perceived line precedes every acted
     * line. Perceived lines are the man's line (`The man shouts,`/`You hear the
     * man shout,`) and the house events (the shaking paragraph). Acted lines
     * start with `You said,`, `You ` (own reported speech / action result when
     * phrased in second person), `The other robot`, or `You heard the other
     * robot`. We find the first acted line and the last perceived line and
     * assert the perceived one is not after the acted one.
     */
    private function assertPerceivedBeforeActed(
        string $block,
        int $seedValue,
        string $robotId,
        int $k,
    ): void {
        $lines = explode("\n", $block);
        // Drop the heading line.
        array_shift($lines);

        $firstActed = null;
        $lastPerceived = null;

        foreach ($lines as $i => $line) {
            if ($this->isActedLine($line)) {
                if ($firstActed === null) {
                    $firstActed = $i;
                }
            } elseif ($this->isPerceivedLine($line)) {
                $lastPerceived = $i;
            }
        }

        if ($firstActed !== null && $lastPerceived !== null) {
            $this->assertLessThan(
                $firstActed,
                $lastPerceived,
                "perceived items must precede acted items in event block {$k} "
                    . "(seed {$seedValue}, {$robotId})"
            );
        }
    }

    private function isActedLine(string $line): bool
    {
        return str_starts_with($line, 'You said, "')
            || str_starts_with($line, 'You heard the other robot')
            || str_starts_with($line, 'The other robot said, "');
    }

    private function isPerceivedLine(string $line): bool
    {
        return str_starts_with($line, 'The man shouts, "')
            || str_starts_with($line, 'You hear the man shout, "')
            || str_contains($line, 'shake')
            || str_contains($line, 'shaking');
    }

    /**
     * Drive a real earthquake episode from the seed for up to $turnCount played
     * turns, capturing genuine TurnRecords so the reflection renders real
     * engine-authored facts. Both robots speak a short fixed line each turn so
     * every event block carries an acted line to order against the perceived
     * ones.
     *
     * @return array{0: TurnRecord[], 1: WorldModel}
     */
    private function runEpisode(int $seedValue, int $turnCount, string $robotId): array
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
