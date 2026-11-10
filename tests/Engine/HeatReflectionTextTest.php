<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\ActionExecutor;
use PoltergeistSwitch\Engine\EarthquakePhase;
use PoltergeistSwitch\Engine\Engine;
use PoltergeistSwitch\Engine\Enums\Disaster;
use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\ReflectionTextBuilder;
use PoltergeistSwitch\Engine\ResidentController;
use PoltergeistSwitch\Engine\RulesEvaluator;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\SituationSpec;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\Values\TurnRecord;
use PoltergeistSwitch\Engine\WorldModel;
use PoltergeistSwitch\Memory\Values\MemorySnapshot;

/**
 * Deterministic EXAMPLE tests for the heat-wave Reflection_Text (task 10.2).
 *
 * The structure and banned-word guarantees are covered universally by the
 * heat text-leaks-nothing property (Property 9, task 10.3) and the earthquake
 * reflection structure property. This file pins the exact heat wording those
 * properties do not assert by literal:
 *   - the heat lead-in and the minute count-up headings (Requirement 7.1);
 *   - the exact unchanged reflection question (Requirement 7.2);
 *   - the heat version constant differing from the earthquake version
 *     (Requirement 7.4).
 *
 * Every test drives a REAL heat-wave episode from a fixed seed through the live
 * Engine (prepareTurn / applyTurn), captures genuine TurnRecords exactly as the
 * live loop stores them, and feeds them to the pure builder — so the literals
 * asserted are the literals a real heat reflection renders.
 *
 * Requirements: 7.1, 7.2, 7.4
 */
class HeatReflectionTextTest extends TestCase
{
    private const LEAD_IN_HEAT = 'Here is what happened as the heat went on:';

    private const QUESTION = 'Is there anything from what happened this time that you want to remember?';

    private const NEUTRAL_LINE = 'Nothing happened that you noticed.';

    /**
     * The heat lead-in is its own section once, immediately before the first
     * event block, and the exact question — byte-for-byte the earthquake's —
     * is the final section after a blank line (Requirement 7.1, 7.2).
     */
    public function testHeatLeadInAndUnchangedQuestion(): void
    {
        [$records, $finalWorld] = $this->runHeatWave(2468, 4, 'robot_a');
        $this->assertNotEmpty($records, 'the fixture episode must play at least one turn');

        $builder = new ReflectionTextBuilder(new SituationTextGenerator());
        $reflection = $builder->build('robot_a', $records, 0, $finalWorld, new MemorySnapshot([]));

        // The heat lead-in appears exactly once, as its own section.
        $this->assertStringContainsString("\n\n" . self::LEAD_IN_HEAT . "\n\n", $reflection);
        $this->assertSame(
            1,
            substr_count($reflection, self::LEAD_IN_HEAT),
            'the heat lead-in must appear exactly once'
        );
        // The earthquake lead-in never appears in a heat reflection.
        $this->assertStringNotContainsString('after the shaking began:', $reflection);

        // The reflection ends with a blank line then the EXACT unchanged
        // question (Requirement 7.2).
        $this->assertStringEndsWith("\n\n" . self::QUESTION, $reflection);
        $this->assertSame(
            1,
            substr_count($reflection, self::QUESTION),
            'the question must appear exactly once, as the final section'
        );
    }

    /**
     * The event blocks are headed by the minute count-up literals: turn 1 is
     * "0 to 5 minutes after the heat set in:", turn 2 "5 to 10 …", turn 3
     * "10 to 15 …", each in 5-minute steps counting up from the first played
     * turn, with no second heading anywhere (Requirement 7.1).
     */
    public function testEventBlockHeadingsCountUpInFiveMinuteSteps(): void
    {
        // Play turns 0..3; turns 1,2,3 are recorded (turn 0 is the quiet turn).
        [$records, $finalWorld] = $this->runHeatWave(2468, 4, 'robot_a');
        $this->assertCount(3, $records, 'turns 1, 2 and 3 must be recorded');

        $builder = new ReflectionTextBuilder(new SituationTextGenerator());
        $reflection = $builder->build('robot_a', $records, 0, $finalWorld, new MemorySnapshot([]));

        $expected = [
            '0 to 5 minutes after the heat set in:',
            '5 to 10 minutes after the heat set in:',
            '10 to 15 minutes after the heat set in:',
        ];

        $prev = -1;
        foreach ($expected as $k => $heading) {
            $pos = strpos($reflection, "\n\n" . $heading . "\n");
            $this->assertNotFalse(
                $pos,
                "event block {$k} must be headed by the minute literal '{$heading}'"
            );
            $this->assertGreaterThan(
                $prev,
                $pos,
                'the minute count-up headings must appear oldest first'
            );
            $prev = $pos;
        }

        // No earthquake second heading leaks into a heat reflection.
        $this->assertStringNotContainsString('seconds after the shaking began:', $reflection);
        $this->assertStringNotContainsString('seconds ago:', $reflection);
    }

    /**
     * A real heat event block carries the man's line heard this turn and the
     * robots' spoken lines, with no shaking paragraph (a heat turn has none),
     * and never repeats the static object list (Requirement 7.1).
     */
    public function testHeatEventBlockHoldsSpeechAndNoShakingParagraph(): void
    {
        [$records, $finalWorld] = $this->runHeatWave(2468, 4, 'robot_a');
        $this->assertCount(3, $records);

        $builder = new ReflectionTextBuilder(new SituationTextGenerator());
        $reflection = $builder->build('robot_a', $records, 0, $finalWorld, new MemorySnapshot([]));

        $blocks = $this->eventBlocks($reflection);
        $this->assertCount(3, $blocks, 'one event block per recorded turn');

        // Robot_A speaks a fixed line every turn, so each block carries it.
        $this->assertStringContainsString('You said, "It is warm in here."', $blocks[0]);

        // No shaking wording ever appears in a heat reflection.
        foreach ($blocks as $k => $block) {
            $this->assertStringNotContainsString(
                'shake',
                $block,
                "heat event block {$k} must carry no shaking wording"
            );
            $this->assertStringNotContainsString(
                'there are:',
                $block,
                "heat event block {$k} must not repeat the static object list"
            );
        }
    }

    /**
     * A played heat turn on which the robot does nothing and hears nothing
     * collapses to the single neutral line under its minute heading, so the
     * count-up stays unbroken (Requirement 7.1).
     */
    public function testEmptyHeatTurnRendersTheNeutralLine(): void
    {
        // A real heat snapshot to hang the record on; its facts are unused
        // because the constructed turn has no perceived or acted lines.
        [$records, $finalWorld] = $this->runHeatWave(2468, 2, 'robot_a');
        $this->assertNotEmpty($records);
        $snapshot = $records[0]->snapshot;

        $quietTurn = new TurnRecord(
            turnNumber: 3,
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

        $builder = new ReflectionTextBuilder(new SituationTextGenerator());
        $reflection = $builder->build('robot_a', [$quietTurn], 0, $finalWorld, new MemorySnapshot([]));

        $blocks = $this->eventBlocks($reflection);
        $this->assertCount(1, $blocks);

        $lines = explode("\n", $blocks[0]);
        $this->assertSame(
            '10 to 15 minutes after the heat set in:',
            $lines[0],
            'turn 3 heads at k = 2: 10 to 15 minutes'
        );
        $this->assertSame(
            self::NEUTRAL_LINE,
            $lines[1] ?? null,
            'an otherwise-empty heat block shows exactly the neutral line'
        );
        $this->assertCount(2, $lines, 'the neutral block is the heading and one neutral line only');
    }

    /**
     * The heat-wave reflection-text version constant differs from the
     * earthquake version, and versionFor() returns the right one per disaster
     * (Requirement 7.4).
     */
    public function testHeatVersionDiffersFromEarthquakeVersion(): void
    {
        $this->assertNotSame(
            ReflectionTextBuilder::REFLECTION_TEXT_VERSION,
            ReflectionTextBuilder::REFLECTION_TEXT_VERSION_HEAT,
            'the heat-wave reflection version must differ from the earthquake version'
        );
        $this->assertSame('reflection-text-2026-11-09d', ReflectionTextBuilder::REFLECTION_TEXT_VERSION);
        $this->assertSame(
            'reflection-text-heat-2026-11-10c',
            ReflectionTextBuilder::REFLECTION_TEXT_VERSION_HEAT
        );

        $builder = new ReflectionTextBuilder(new SituationTextGenerator());

        // versionFor() picks the heat version for a heat-wave world and the
        // earthquake version otherwise.
        $config = new EpisodeConfig();

        $quakeWorld = new WorldModel($config);
        $quakeWorld->reset(Seed::fromInt(1));
        $this->assertSame(
            ReflectionTextBuilder::REFLECTION_TEXT_VERSION,
            $builder->versionFor($quakeWorld)
        );

        $heatWorld = new WorldModel($config);
        $heatWorld->reset(
            Seed::fromInt(1),
            new SituationSpec(
                time: 'afternoon',
                robotARoom: 'living_room',
                robotBRoom: 'bedroom',
                manActivity: 'sitting_at_table',
                potBoiling: false,
            ),
            Disaster::heat_wave
        );
        $this->assertSame(
            ReflectionTextBuilder::REFLECTION_TEXT_VERSION_HEAT,
            $builder->versionFor($heatWorld)
        );

        // The two accessor methods surface each constant.
        $this->assertSame(ReflectionTextBuilder::REFLECTION_TEXT_VERSION, $builder->version());
        $this->assertSame(ReflectionTextBuilder::REFLECTION_TEXT_VERSION_HEAT, $builder->heatVersion());
    }

    /**
     * The fixed body and fixed house sections are byte-for-byte the same in a
     * heat reflection as in an earthquake reflection (they are shared fixed
     * text), so a heat reflection opens with the same two sections
     * (Requirement 7.1).
     */
    public function testFixedBodyAndHouseAreSharedWithEarthquake(): void
    {
        [$records, $finalWorld] = $this->runHeatWave(2468, 3, 'robot_a');

        $builder = new ReflectionTextBuilder(new SituationTextGenerator());
        $reflection = $builder->build('robot_a', $records, 0, $finalWorld, new MemorySnapshot([]));

        $this->assertStringStartsWith('You are a robot in this house.', $reflection);
        $this->assertStringContainsString(
            'This is a single-story house in the Japanese countryside',
            $reflection
        );
    }

    /**
     * Extract the event blocks: the "\n\n"-separated sections between the heat
     * lead-in and the trailing "Right now:" paragraph. With an empty snapshot
     * there is no memories block, so everything between the lead-in and
     * "Right now:" is event blocks, oldest first.
     *
     * @return string[]
     */
    private function eventBlocks(string $reflection): array
    {
        $start = strpos($reflection, "\n\n" . self::LEAD_IN_HEAT . "\n\n");
        $this->assertNotFalse($start, 'the heat lead-in must be present');
        $start += strlen("\n\n" . self::LEAD_IN_HEAT . "\n\n");

        $end = strpos($reflection, "\n\nRight now:", $start);
        $this->assertNotFalse($end, 'the trailing "Right now:" paragraph must be present');

        return explode("\n\n", substr($reflection, $start, $end - $start));
    }

    /**
     * Drive a real heat-wave episode from the seed for $turnCount played turns
     * (turns 0 .. $turnCount-1), capturing genuine TurnRecords from turn 1 on
     * (turn 0 is the quiet turn, not part of the recap). Both robots speak a
     * short fixed line each turn so every event block carries an acted line.
     * Returns the records and the post-episode heat world.
     *
     * @return array{0: TurnRecord[], 1: WorldModel}
     */
    private function runHeatWave(int $seedValue, int $turnCount, string $robotId): array
    {
        $config = new EpisodeConfig();
        $world = new WorldModel($config);
        $world->reset(
            Seed::fromInt($seedValue),
            new SituationSpec(
                time: 'afternoon',
                robotARoom: 'living_room',
                robotBRoom: 'bedroom',
                manActivity: 'sitting_at_table',
                potBoiling: false,
            ),
            Disaster::heat_wave
        );

        $engine = new Engine(
            $world,
            $config,
            new RulesEvaluator(),
            new ActionExecutor(),
            new EarthquakePhase(),
            new ResidentController(),
            new SituationTextGenerator(),
        );

        /** @var TurnRecord[] $records */
        $records = [];

        for ($turn = 0; $turn < $turnCount; $turn++) {
            $engine->prepareTurn($turn);

            $roomsAtStart = [
                'robot_a' => $world->robotStates['robot_a']->room,
                'robot_b' => $world->robotStates['robot_b']->room,
            ];

            // Turn 0 is quiet (no robot actions), mirroring the live loop; from
            // turn 1 both robots speak a short fixed line.
            $actions = $turn === 0 ? [] : [
                'robot_a' => new Action(
                    robotId: 'robot_a',
                    operation: Operation::speak->value,
                    words: ['It is warm in here.'],
                    actionQuote: 'It is warm in here.',
                ),
                'robot_b' => new Action(
                    robotId: 'robot_b',
                    operation: Operation::speak->value,
                    words: ['It is warm in here.'],
                    actionQuote: 'It is warm in here.',
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
