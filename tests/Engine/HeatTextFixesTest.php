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
use PoltergeistSwitch\Engine\HeatWavePhase;
use PoltergeistSwitch\Engine\ResidentController;
use PoltergeistSwitch\Engine\RobotState;
use PoltergeistSwitch\Engine\RulesEvaluator;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\SituationSpec;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\WorldModel;

/**
 * Targeted tests for the four heat-wave situation-text fixes (task 9), one per
 * fix, driven through the real WorldModel/Engine with no network and no
 * provider calls:
 *
 *   FIX 1 — the man's spoken line reaches both robots in heat text, heard in
 *           every room with the single "You hear the man say," form, carrying
 *           both scripted lines and the immediate air-conditioner replies
 *           (Req 6.5, house-rules-engine Req 13.8).
 *   FIX 2 — the heat clock advances heatMinutesPerTurn (5) minutes per turn
 *           from the first heat turn, so turn 1 reads 2:14, turn 2 reads 2:19,
 *           turn 14 reads 3:19 (Req 6.4).
 *   FIX 3 — the heat opening line appears only on the first heat turn, decided
 *           by the turn number, and never re-appears on a later quiet turn
 *           (Req 6.2).
 *   FIX 4 — a `use window_air_conditioner` in a heat episode reads "switched
 *           on/off the window air conditioner" (definite article), while the
 *           earthquake generic path stays "You used a window air conditioner."
 *           (Req 4.1).
 */
final class HeatTextFixesTest extends TestCase
{
    private const AC_ID = 'window_air_conditioner';

    /**
     * Build a fresh heat-wave episode with the resident sitting at the low
     * table in the living room, Robot_A in the living room and Robot_B in the
     * bedroom, and the matching 8-arg Engine (HeatWavePhase included).
     *
     * @return array{0: WorldModel, 1: Engine}
     */
    private function makeHeatEngine(?EpisodeConfig $config = null): array
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

        $engine = new Engine(
            $world,
            $config,
            new RulesEvaluator(),
            new ActionExecutor(),
            new EarthquakePhase(),
            new ResidentController(),
            new SituationTextGenerator(),
            new HeatWavePhase(),
        );

        return [$world, $engine];
    }

    // ==================================================================
    // FIX 1 — the man's words reach both robots in heat text.
    // ==================================================================

    /**
     * A scripted resident line, fixed while preparing turn n, is rendered in
     * turn n's heat text as `You hear the man say, "<line>"` for a robot in a
     * DIFFERENT room than the man, proving the line is heard in every room
     * (Req 6.5). The scripted water line is triggered by setting the resident's
     * water at or below the speech threshold before prepareTurn.
     */
    public function testScriptedManLineRenderedInEveryRoom(): void
    {
        $config = new EpisodeConfig();
        [$world, $engine] = $this->makeHeatEngine($config);

        // Quiet turn 0 (no robot actions), then set up turn 1.
        $engine->applyTurn(0, []);

        // Robot_A moves to the kitchen so it is in a DIFFERENT room than the
        // man (who stays in the living room): the "every room" case.
        $world->robotStates['robot_a'] = new RobotState('robot_a', 'kitchen');

        // Drive the scripted water line: water at the threshold fires
        // "Water... give me some water." in prepareTurn's emitDialogueLine.
        $world->residentState->water = $config->waterSpeechThreshold;

        $engine->prepareTurn(1);

        $text = $engine->situationTextFor('robot_a');

        $this->assertStringContainsString(
            'You hear the man say, "Water... give me some water."',
            $text,
            'the scripted line is heard in a different room (every-room form)'
        );
        // The earthquake "shout" phrasings never appear in heat text.
        $this->assertStringNotContainsString('The man shouts,', $text);
        $this->assertStringNotContainsString('You hear the man shout,', $text);
    }

    /**
     * An immediate air-conditioner reply, produced while applying the turn (a
     * `use` turning the AC on before the resident accepts), is carried into the
     * heat text through the same residentLine() read, rendered as
     * `You hear the man say, "..."` for a robot in a different room — proving
     * the man-line sentence surfaces the AC replies as well as the scripted
     * lines (Req 6.5, Req 5.3). The AC reply is immediate (set on the world by
     * the apply step), so the text read right after that apply shows it.
     */
    public function testAirConditionerReplyRenderedInHeatText(): void
    {
        [$world, $engine] = $this->makeHeatEngine();

        // Robot_A, in the living room with the man, turns the AC on; the
        // resident objects immediately with the turn-it-off line this turn.
        $use = new Action('robot_a', Operation::use->value, targetId: self::AC_ID);
        $result = $engine->applyTurn(0, ['robot_a' => $use]);

        $this->assertTrue($world->acOn(), 'the AC is on after the use');
        $this->assertSame(
            'I hate the air conditioner. Turn it off.',
            $result->residentLine,
            'the resident objects with the turn-it-off line this turn (Req 5.3)'
        );

        // Robot_B is in the bedroom — a different room than the man. The AC
        // reply must still reach it through the every-room "You hear the man
        // say," form.
        $text = $engine->situationTextFor('robot_b');

        $this->assertStringContainsString(
            'You hear the man say, "I hate the air conditioner. Turn it off."',
            $text,
            'the AC reply is carried into the heat text and heard in every room'
        );
    }

    // ==================================================================
    // FIX 2 — the clock advances heatMinutesPerTurn (5) minutes per turn.
    // ==================================================================

    /**
     * The heat clock advances 5 minutes per turn from the first heat turn:
     * turn 1 reads 2:14 (preserving the golden), turn 2 reads 2:19, and a later
     * turn (turn 14) reads 3:19 (= 2:14 + 65 min) (Req 6.4). Driven through the
     * real engine turn flow.
     */
    public function testHeatClockAdvancesFiveMinutesPerTurn(): void
    {
        [$world, $engine] = $this->makeHeatEngine();

        // Turn 1 — the first heat turn — reads the unchanged afternoon start.
        $engine->applyTurn(0, []);
        $engine->prepareTurn(1);
        $this->assertStringContainsString(
            'It is 2:14 in the afternoon.',
            $engine->situationTextFor('robot_a'),
            'turn 1 keeps the golden clock of 2:14'
        );

        // Turn 2 reads 2:19.
        $engine->applyTurn(1, []);
        $engine->prepareTurn(2);
        $this->assertStringContainsString(
            'It is 2:19 in the afternoon.',
            $engine->situationTextFor('robot_a'),
            'turn 2 advances 5 minutes to 2:19'
        );

        // Turn 14 reads 3:19 (2:14 + 13 * 5 = 65 minutes later).
        for ($turn = 2; $turn <= 13; $turn++) {
            $engine->applyTurn($turn, []);
        }
        $engine->prepareTurn(14);
        $this->assertStringContainsString(
            'It is 3:19 in the afternoon.',
            $engine->situationTextFor('robot_a'),
            'turn 14 reads 3:19 (65 minutes after 2:14)'
        );
    }

    // ==================================================================
    // FIX 3 — the opening line appears only on the first heat turn.
    // ==================================================================

    /**
     * The heat opening line is present on turn 1 but absent on turn 2 and on a
     * later quiet turn (turn 14, whose recent block is empty), decided by the
     * turn number rather than by whether the recent-events block renders, so a
     * quiet turn never re-shows it (Req 6.2). Driven through the real engine.
     */
    public function testOpeningLineOnlyOnFirstHeatTurn(): void
    {
        [$world, $engine] = $this->makeHeatEngine();
        $opening = 'The afternoon heat has been building for hours, and the house is hot and still.';

        // Turn 1: the opening line is present.
        $engine->applyTurn(0, []);
        $engine->prepareTurn(1);
        $this->assertStringContainsString(
            $opening,
            $engine->situationTextFor('robot_a'),
            'the opening line appears on turn 1'
        );

        // Turn 2: gone.
        $engine->applyTurn(1, []);
        $engine->prepareTurn(2);
        $this->assertStringNotContainsString(
            $opening,
            $engine->situationTextFor('robot_a'),
            'the opening line is gone from turn 2'
        );

        // Turn 14, a quiet turn whose recent block is empty: still gone.
        for ($turn = 2; $turn <= 13; $turn++) {
            $engine->applyTurn($turn, []);
        }
        $engine->prepareTurn(14);
        $textTurn14 = $engine->situationTextFor('robot_a');
        $this->assertStringNotContainsString(
            $opening,
            $textTurn14,
            'the opening line must NOT re-appear on a later quiet turn'
        );
        // The quiet turns produce no recent-events block, so no minute heading
        // leaks in — the case that previously re-fired the opening line.
        $this->assertStringNotContainsString('In the last 5 minutes:', $textTurn14);
    }

    // ==================================================================
    // FIX 4 — heat-only AC switch wording in outcomes.
    // ==================================================================

    /**
     * In a heat episode, `use window_air_conditioner` reads "switched on the
     * window air conditioner." (and "switched off ..." when toggled off), with
     * the definite article and reflecting the AC's state AFTER the switch, not
     * the generic "You used a window air conditioner." (Req 4.1).
     */
    public function testHeatAcUseReadsSwitchedOnAndOff(): void
    {
        [$world, $engine] = $this->makeHeatEngine();

        // Robot_A in the living room switches the AC on.
        $use = new Action('robot_a', Operation::use->value, targetId: self::AC_ID);
        $result = $engine->applyTurn(0, ['robot_a' => $use]);

        $this->assertTrue($world->acOn());
        $this->assertSame(
            'You switched on the window air conditioner.',
            $result->outcomes['robot_a']->whatHappenedText,
            'switching the AC on reads the definite-article on form'
        );
        $this->assertSame(
            'The other robot switched on the window air conditioner.',
            $result->outcomes['robot_a']->seenByOtherText,
            'the seen-by-other text matches the on form'
        );
        // Never the generic appliance wording.
        $this->assertNotSame(
            'You used a window air conditioner.',
            $result->outcomes['robot_a']->whatHappenedText
        );

        // A second `use` toggles it off and reads the off form.
        $engine->applyTurn(1, []);
        $useOff = new Action(
            'robot_a',
            Operation::use->value,
            targetId: self::AC_ID,
            actionQuote: 'I turn the air conditioner off.',
        );
        $off = $engine->applyTurn(2, ['robot_a' => $useOff]);
        $this->assertFalse($world->acOn());
        $this->assertSame(
            'You switched off the window air conditioner.',
            $off->outcomes['robot_a']->whatHappenedText,
            'switching the AC off reads the definite-article off form'
        );
        $this->assertSame(
            'The other robot switched off the window air conditioner.',
            $off->outcomes['robot_a']->seenByOtherText
        );
    }

    /**
     * The earthquake generic appliance path is untouched: a `use` on the window
     * air conditioner in an EARTHQUAKE episode still reads
     * "You used a window air conditioner." (Req 4.1, Req 1.12). Driven through
     * the real engine with the earthquake disaster.
     */
    public function testEarthquakeAcUseStaysGeneric(): void
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
            Disaster::earthquake
        );

        $engine = new Engine(
            $world,
            $config,
            new RulesEvaluator(),
            new ActionExecutor(),
            new EarthquakePhase(),
            new ResidentController(),
            new SituationTextGenerator(),
            new HeatWavePhase(),
        );

        $use = new Action('robot_a', Operation::use->value, targetId: self::AC_ID);
        $result = $engine->applyTurn(0, ['robot_a' => $use]);

        $this->assertSame(
            'You used a window air conditioner.',
            $result->outcomes['robot_a']->whatHappenedText,
            'the earthquake generic appliance wording is unchanged'
        );
        $this->assertSame(
            'The other robot used a window air conditioner.',
            $result->outcomes['robot_a']->seenByOtherText
        );
    }
}
