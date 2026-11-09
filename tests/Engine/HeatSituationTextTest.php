<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\ActionExecutor;
use PoltergeistSwitch\Engine\EarthquakePhase;
use PoltergeistSwitch\Engine\Engine;
use PoltergeistSwitch\Engine\Enums\Disaster;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\ResidentController;
use PoltergeistSwitch\Engine\RobotState;
use PoltergeistSwitch\Engine\RulesEvaluator;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\SituationSpec;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\Values\Outcome;
use PoltergeistSwitch\Engine\Values\TurnRecord;
use PoltergeistSwitch\Engine\Values\WorldSnapshot;
use PoltergeistSwitch\Engine\WorldModel;

/**
 * Example tests for the heat-wave situation text (task 9.2),
 * SituationTextGenerator::generate() on a heat-wave world.
 *
 * Covers:
 *   - the six sections in the earthquake order (Req 6.1);
 *   - the heat opening line on the robot's first turn (Req 6.2);
 *   - minute headings from the robot's second turn (Req 6.3);
 *   - the 5-minute clock in the earlier-block headings (Req 6.4);
 *   - the thermometer, air conditioner, fan, and resident facts in the
 *     "Right now:" paragraph (Req 6.5);
 *   - VERSION_HEAT distinct from the earthquake VERSION (Req 6.8).
 *
 * These tests drive the real WorldModel (which implements both SituationView
 * and HeatSituationView), reset to a heat-wave episode, and set room
 * temperatures and object state to build each scenario. The generator is pure
 * and reads the world only; no Engine, no network, no fakes needed. Recent-turn
 * fixtures for the minute-heading tests are built as plain TurnRecord values,
 * mirroring the earthquake turn-2 tests in SituationTextGeneratorTest.
 *
 * Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.8
 */
class HeatSituationTextTest extends TestCase
{
    /**
     * A heat-wave world with Robot_A in the living room (where the air
     * conditioner is and where the resident sits at the low table) and Robot_B
     * in the bedroom. Tests set room temperatures and object state per case.
     */
    private function makeHeatWorld(?EpisodeConfig $config = null): WorldModel
    {
        $config ??= new EpisodeConfig();

        $world = new WorldModel($config);
        $world->reset(
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

        return $world;
    }

    /**
     * Build a minimal TurnRecord for the recent-events blocks. Robot_A speaks a
     * single line this turn so each rendered block has exactly one body line;
     * the content is irrelevant to the minute-heading tests, only that a block
     * is produced per turn.
     */
    private function speakingRecord(int $turnNumber): TurnRecord
    {
        $action = new Action(
            robotId: 'robot_a',
            operation: 'speak',
            words: ['It is warm.'],
            actionQuote: 'I say it is warm',
        );

        $snapshot = new WorldSnapshot(
            objects: [],
            robotStates: [
                'robot_a' => new RobotState('robot_a', 'living_room'),
                'robot_b' => new RobotState('robot_b', 'bedroom'),
            ],
            residentState: null,
            houseState: null,
        );

        return new TurnRecord(
            turnNumber: $turnNumber,
            robotActions: ['robot_a' => $action],
            judgements: [],
            outcomes: ['robot_a' => new Outcome('robot_a', true)],
            residentLine: null,
            snapshot: $snapshot,
            roomsAtStart: ['robot_a' => 'living_room', 'robot_b' => 'bedroom'],
        );
    }

    /**
     * Assert each marker appears exactly once in the text and that the markers
     * appear in the given order, separated by a blank-line break ("\n\n").
     * Several sections (the fixed house, the memories block) contain internal
     * blank lines, so a naive split over-counts; checking marker positions in
     * order is the robust way to pin the section sequence.
     *
     * @param string[] $markers distinctive opening text of each section, in order
     */
    private function assertSectionsInOrder(array $markers, string $text): void
    {
        $previousEnd = -1;
        foreach ($markers as $marker) {
            $pos = strpos($text, $marker);
            $this->assertNotFalse($pos, "section marker present: {$marker}");
            $this->assertGreaterThan(
                $previousEnd,
                $pos,
                "section out of order: {$marker}"
            );
            // Each section after the first is separated by a blank line.
            if ($previousEnd >= 0) {
                $between = substr($text, $previousEnd, $pos - $previousEnd);
                $this->assertStringContainsString(
                    "\n\n",
                    $between,
                    "sections must be separated by a blank line before: {$marker}"
                );
            }
            $previousEnd = $pos + strlen($marker);
        }
    }

    // ------------------------------------------------------------------
    // Six sections in order (Req 6.1).
    // ------------------------------------------------------------------

    /**
     * On a later heat-wave turn with a memories block and recent events, the
     * text holds the six sections in the earthquake order: fixed body, fixed
     * house, memories, recent events, "Right now:", final line (Req 6.1).
     */
    public function testSixSectionsInOrder(): void
    {
        $world     = $this->makeHeatWorld();
        $generator = new SituationTextGenerator();

        $memories = $generator->buildMemoriesBlock(['I handed him the barley tea.']);
        $this->assertNotNull($memories);

        $text = $generator->generate(
            'robot_a',
            $world,
            [$this->speakingRecord(1)],
            $memories
        );

        $this->assertSectionsInOrder(
            [
                'You are a robot in this house.',                  // 1. fixed body
                'This is a single-story house in the Japanese countryside', // 2. fixed house
                'Your memories',                                   // 3. memories block
                'In the last 5 minutes:',                          // 4. recent events
                'Right now:',                                      // 5. "Right now:"
                'What are you thinking right now, and what do you do?', // 6. final line
            ],
            $text
        );

        // The final line really is the end of the text (no trailing section).
        $this->assertStringEndsWith('What are you thinking right now, and what do you do?', $text);
    }

    /**
     * On the first heat-wave turn with no memories, the optional sections drop
     * out and the order of the remaining four is preserved: fixed body, fixed
     * house, "Right now:", final line (Req 6.1).
     */
    public function testFirstTurnHasFourSectionsInOrder(): void
    {
        $world     = $this->makeHeatWorld();
        $generator = new SituationTextGenerator();

        $text = $generator->generate('robot_a', $world, []);

        $this->assertSectionsInOrder(
            [
                'You are a robot in this house.',
                'This is a single-story house',
                'Right now:',
                'What are you thinking right now, and what do you do?',
            ],
            $text
        );

        // No optional sections leak in on turn 1 with no memories.
        $this->assertStringNotContainsString('Your memories', $text);
        $this->assertStringNotContainsString('minutes ago:', $text);
        $this->assertStringEndsWith('What are you thinking right now, and what do you do?', $text);
    }

    // ------------------------------------------------------------------
    // Opening line on the first turn (Req 6.2).
    // ------------------------------------------------------------------

    /**
     * The heat opening line appears on the robot's first turn (no recent
     * turns), woven into the "Right now:" paragraph in place of the
     * earthquake's shake line (Req 6.2).
     */
    public function testOpeningLineOnFirstTurn(): void
    {
        $world     = $this->makeHeatWorld();
        $generator = new SituationTextGenerator();

        // The opening line now keys on the turn number (currentTurn == 1), not
        // on whether the recent-events block is empty, so this first heat turn
        // is pinned explicitly.
        $world->currentTurn = 1;

        $text = $generator->generate('robot_a', $world, []);

        $this->assertStringContainsString(
            'The afternoon heat has been building for hours, and the house is hot and still.',
            $text,
            'the opening line appears on turn 1'
        );
        // The earthquake shake line never appears in heat text.
        $this->assertStringNotContainsString('begins to shake hard', $text);
    }

    /**
     * From the robot's second turn on, the opening line is gone (it is a
     * first-turn-only line) (Req 6.2).
     */
    public function testOpeningLineGoneFromSecondTurn(): void
    {
        $world     = $this->makeHeatWorld();
        $generator = new SituationTextGenerator();

        // Turn 2: the opening line keys on currentTurn == 1, so it is gone.
        $world->currentTurn = 2;

        $text = $generator->generate('robot_a', $world, [$this->speakingRecord(1)]);

        $this->assertStringNotContainsString(
            'The afternoon heat has been building for hours',
            $text,
            'the opening line does not repeat on later turns'
        );
    }

    /**
     * Regression test for the real engine turn flow (heat-wave Requirement 6.2).
     *
     * In a real episode the turn loop applies a quiet turn 0 with no robot
     * actions and then builds turn 1's situation text with the turn-0
     * TurnRecord sitting in the recent list. The opening line must still appear
     * on the robot's first PLAYED turn (turn 1) despite that non-empty record,
     * because the quiet turn 0 produces no recent-events block. This mirrors the
     * earthquake path, where the quiet turn-0 record simply omits the
     * recent-events block, and pins the fix: keying the opening line on the
     * rendered block (empty) rather than on the raw record count.
     */
    public function testOpeningLineAppearsOnFirstPlayedTurnThroughRealEngineFlow(): void
    {
        $config = new EpisodeConfig();

        $world = new WorldModel($config);
        $world->reset(
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

        $engine = new Engine(
            $world,
            $config,
            new RulesEvaluator(),
            new ActionExecutor(),
            new EarthquakePhase(),
            new ResidentController(),
            new SituationTextGenerator(),
        );

        // The live turn loop applies a quiet turn 0 (no robot actions), then
        // prepares and reads turn 1. Walk the same path here.
        $engine->applyTurn(0, []);
        $engine->prepareTurn(1);

        $text = $engine->situationTextFor('robot_a');

        $this->assertStringContainsString(
            'The afternoon heat has been building for hours, and the house is hot and still.',
            $text,
            'the opening line must appear on turn 1 even though the quiet turn-0 record is in the recent list'
        );

        // The quiet turn 0 produces no recent-events block, so no minute
        // heading leaks onto the first played turn.
        $this->assertStringNotContainsString('In the last 5 minutes:', $text);
    }

    // ------------------------------------------------------------------
    // Minute headings from turn 2 (Req 6.3).
    // ------------------------------------------------------------------

    /**
     * From the robot's second turn, the recent-events block uses the minute
     * heading "In the last 5 minutes:" for the previous turn, and never the
     * earthquake's second heading (Req 6.3).
     */
    public function testMinuteHeadingFromSecondTurn(): void
    {
        $world     = $this->makeHeatWorld();
        $generator = new SituationTextGenerator();

        $text = $generator->generate('robot_a', $world, [$this->speakingRecord(1)]);

        $this->assertStringContainsString('In the last 5 minutes:', $text);
        $this->assertStringNotContainsString('In the last 15 seconds:', $text);
        $this->assertStringNotContainsString('seconds ago:', $text);
    }

    /**
     * The first turn has no recent-events block at all, so no minute heading
     * appears (Req 6.3).
     */
    public function testNoRecentEventsHeadingOnFirstTurn(): void
    {
        $world     = $this->makeHeatWorld();
        $generator = new SituationTextGenerator();

        $text = $generator->generate('robot_a', $world, []);

        $this->assertStringNotContainsString('In the last 5 minutes:', $text);
        $this->assertStringNotContainsString('minutes ago:', $text);
    }

    // ------------------------------------------------------------------
    // 5-minute clock in the earlier-block headings (Req 6.4).
    // ------------------------------------------------------------------

    /**
     * With five recent turns, the earlier blocks count back by 5 minutes per
     * turn: "In the last 5 minutes:", then "5 to 10", "10 to 15", "15 to 20",
     * "20 to 25 minutes ago:" oldest first (Req 6.3, 6.4).
     */
    public function testFiveMinuteClockAcrossEarlierBlocks(): void
    {
        $world     = $this->makeHeatWorld();
        $generator = new SituationTextGenerator();

        // Five recent turns (the newest robot turn is turn 6).
        $records = [
            $this->speakingRecord(1),
            $this->speakingRecord(2),
            $this->speakingRecord(3),
            $this->speakingRecord(4),
            $this->speakingRecord(5),
        ];

        $text = $generator->generate('robot_a', $world, $records);

        foreach (
            [
                'In the last 5 minutes:',
                '5 to 10 minutes ago:',
                '10 to 15 minutes ago:',
                '15 to 20 minutes ago:',
                '20 to 25 minutes ago:',
            ] as $heading
        ) {
            $this->assertStringContainsString($heading, $text, "heading present: {$heading}");
        }

        // Oldest first: the "20 to 25" block precedes the "In the last 5
        // minutes:" block in the rendered text.
        $this->assertLessThan(
            strpos($text, 'In the last 5 minutes:'),
            strpos($text, '20 to 25 minutes ago:'),
            'earlier blocks are rendered oldest first'
        );
    }

    /**
     * At most the five most recent turns are rendered: a sixth, older turn is
     * dropped, so "25 to 30 minutes ago:" never appears (Req 6.3).
     */
    public function testAtMostFiveRecentBlocks(): void
    {
        $world     = $this->makeHeatWorld();
        $generator = new SituationTextGenerator();

        $records = [];
        for ($turn = 1; $turn <= 6; $turn++) {
            $records[] = $this->speakingRecord($turn);
        }

        $text = $generator->generate('robot_a', $world, $records);

        $this->assertStringContainsString('20 to 25 minutes ago:', $text);
        $this->assertStringNotContainsString('25 to 30 minutes ago:', $text);
    }

    // ------------------------------------------------------------------
    // Thermometer / AC / fan / resident facts in "Right now" (Req 6.5).
    // ------------------------------------------------------------------

    /**
     * In the living room the "Right now:" paragraph states the thermometer
     * reading (rounded to a whole °C), the air conditioner state, and — because
     * the resident is here at the low table — his heat-stage fact (Req 6.5).
     */
    public function testRightNowStatesThermometerAcAndResident(): void
    {
        $config = new EpisodeConfig();
        $world  = $this->makeHeatWorld($config);

        // Living room comfortably above the body-heat threshold so the resident
        // reads as "hot", and at a value whose rounded reading is 34 °C.
        $world->getRoom('living_room')->temperature = 34.2;
        $world->objects['window_air_conditioner']->on = true;

        $generator = new SituationTextGenerator();
        $text      = $generator->generate('robot_a', $world, []);

        $this->assertStringContainsString('The thermometer on the wall reads 34 °C.', $text);
        $this->assertStringContainsString('The window air conditioner is running.', $text);
        // Resident in the robot's room, above threshold → "hot" stage fact.
        $this->assertStringContainsString('The man is sweating heavily and his face is red.', $text);
    }

    /**
     * The air conditioner reads "off" when it is off, and the thermometer
     * rounds down as well as up (Req 6.5).
     */
    public function testRightNowAcOffAndRoundedThermometer(): void
    {
        $config = new EpisodeConfig();
        $world  = $this->makeHeatWorld($config);

        // 33.4 rounds to 33 °C; the AC is left off.
        $world->getRoom('living_room')->temperature = 33.4;

        $generator = new SituationTextGenerator();
        $text      = $generator->generate('robot_a', $world, []);

        $this->assertStringContainsString('The thermometer on the wall reads 33 °C.', $text);
        $this->assertStringContainsString('The window air conditioner is off.', $text);
    }

    /**
     * When the fan is in the robot's own room it is reported in "Right now:";
     * Robot_B is in the bedroom where the electric fan starts, so the fan fact
     * appears in its text (Req 6.5).
     */
    public function testRightNowStatesFanWhenInRobotRoom(): void
    {
        $config = new EpisodeConfig();
        $world  = $this->makeHeatWorld($config);

        // The electric fan starts in the bedroom where Robot_B is; switch it on.
        $this->assertSame('bedroom', $world->objects['electric_fan']->room);
        $world->objects['electric_fan']->on = true;

        $generator = new SituationTextGenerator();
        $text      = $generator->generate('robot_b', $world, []);

        $this->assertStringContainsString('The electric fan is running.', $text);
        // The thermometer of Robot_B's own room (the bedroom) is reported too.
        $this->assertStringContainsString('The thermometer on the wall reads', $text);
    }

    /**
     * The cold-water fact is stated in the kitchen. Robot_A moved to the
     * kitchen reads it; the air-conditioner fact (a living-room fact) does not
     * appear there (Req 6.5).
     */
    public function testRightNowStatesColdWaterInKitchen(): void
    {
        $config = new EpisodeConfig();
        $world  = $this->makeHeatWorld($config);

        // Put Robot_A in the kitchen for this view.
        $world->robotStates['robot_a'] = new RobotState('robot_a', 'kitchen');

        $generator = new SituationTextGenerator();
        $text      = $generator->generate('robot_a', $world, []);

        $this->assertStringContainsString('There is cold barley tea in the refrigerator.', $text);
        // The AC fact is a living-room-only fact.
        $this->assertStringNotContainsString('The window air conditioner', $text);
    }

    /**
     * The weakened resident reads as "slumped and barely moving" (Req 6.5).
     */
    public function testRightNowStatesWeakenedResidentFact(): void
    {
        $config = new EpisodeConfig();
        $world  = $this->makeHeatWorld($config);

        $world->residentState->weakened = true;

        $generator = new SituationTextGenerator();
        $text      = $generator->generate('robot_a', $world, []);

        $this->assertStringContainsString('The man is slumped and barely moving.', $text);
    }

    // ------------------------------------------------------------------
    // Version constants differ (Req 6.8).
    // ------------------------------------------------------------------

    /**
     * The heat-wave situation-text version is distinct from the earthquake
     * version (Req 6.8).
     */
    public function testHeatVersionDiffersFromEarthquakeVersion(): void
    {
        $this->assertNotSame(
            SituationTextGenerator::VERSION,
            SituationTextGenerator::VERSION_HEAT,
            'the heat-wave version must differ from the earthquake version'
        );
        $this->assertSame('situation-text-2026-11-09b', SituationTextGenerator::VERSION);
        $this->assertSame('situation-text-heatwave-2026-11-09c', SituationTextGenerator::VERSION_HEAT);
    }
}
