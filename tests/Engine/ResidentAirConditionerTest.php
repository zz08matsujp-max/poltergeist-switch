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
use PoltergeistSwitch\Engine\ResidentController;
use PoltergeistSwitch\Engine\RulesEvaluator;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\SituationSpec;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\WorldModel;

/**
 * Example tests for the resident's air-conditioner will (task 8.2), covering
 * ResidentController::airConditionerReply and the detection helpers, plus the
 * Engine integration that makes the reply immediate and latches acceptance.
 *
 * Covers:
 *   (a) a `use` that turns the air conditioner on before the conditions are met
 *       leaves it on and draws "I hate the air conditioner. Turn it off." (5.3);
 *   (b) a spoken suggestion alone (mentions the AC, no use) draws
 *       "I hate the air conditioner." (5.2);
 *   (c) conditions met (an AC_Danger_Reason spoken this turn + AC_Alternative_Prepared
 *       latched from an earlier turn) → "All right. Just for a while." then no
 *       more objections for the rest of the episode (5.1);
 *   (d) a weakened resident accepts with "All right. Just for a while." (5.1);
 *   - mutual exclusivity of 5.2 vs 5.3 — when a `use` turned it on that turn the
 *     turn-it-off line fires in place of the spoken-only line;
 *   - the immediacy/priority of the reply (5.4) — emitted on the same turn,
 *     outside the 5-turn cooldown, above every other resident line that turn;
 *   - AC_Danger_Reason detection per trigger word (5.7), including whole-word
 *     matching so "heater"/"shot"/"photo" never match and a heat word alone is
 *     not a reason;
 *   - AC_Alternative_Prepared each branch (5.8) — the fan on in the resident's
 *     room on an earlier turn, OR the resident handed water on an earlier turn.
 *
 * The controller-level cases drive ResidentController directly; the integration
 * cases drive whole turns through a real Engine with no network (the existing
 * FakeRobotClient/FakeRecorderClient fakes are not needed here, as the Engine
 * is fed explicit Actions). Every number comes from EpisodeConfig.
 *
 * Requirements: 5.1, 5.2, 5.3, 5.4, 5.7, 5.8
 */
class ResidentAirConditionerTest extends TestCase
{
    private const AC_ID = 'window_air_conditioner';

    private EpisodeConfig $config;
    private ResidentController $resident;

    protected function setUp(): void
    {
        $this->config = new EpisodeConfig();
        $this->resident = new ResidentController();
    }

    /**
     * A heat-wave world with the resident sitting at the low table in the
     * living room, robot_a in the living room and robot_b in the bedroom.
     */
    private function makeHeatWorld(): WorldModel
    {
        $world = new WorldModel($this->config);
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
     * Build a real Engine over the given heat-wave world so whole turns can be
     * driven through applyTurn(); the Engine wires the air-conditioner will
     * into processCurrentTurn (heat-gated).
     */
    private function makeEngine(WorldModel $world): Engine
    {
        return new Engine(
            $world,
            $this->config,
            new RulesEvaluator(),
            new ActionExecutor(),
            new EarthquakePhase(),
            $this->resident,
            new SituationTextGenerator(),
        );
    }

    // ------------------------------------------------------------------
    // (a) AC turned on before the conditions are met (5.3)
    // ------------------------------------------------------------------

    /**
     * (a) A `use` that turns the air conditioner on before the acceptance
     * conditions are met leaves it on and the resident says
     * "I hate the air conditioner. Turn it off." on that same turn (5.3).
     */
    public function testUseTurnsAcOnBeforeConditionsMetDrawsTurnItOffLine(): void
    {
        $world = $this->makeHeatWorld();

        $line = $this->resident->airConditionerReply(
            $world,
            $this->config,
            acUseTurnedItOnThisTurn: true,
            acMentionedThisTurn: false,
            dangerReasonGivenThisTurn: false,
            alternativePreparedBefore: false,
            acAlreadyAccepted: false,
            turnNumber: 0,
        );

        $this->assertSame('I hate the air conditioner. Turn it off.', $line);
    }

    /**
     * (a) Through the Engine: a successful `use` on the air conditioner in the
     * living room leaves it on and the resident's line for that turn is the
     * turn-it-off objection (5.3). This pins that the engine turns the AC on
     * AND surfaces the objection on the same turn.
     */
    public function testEngineUseTurnsAcOnAndResidentObjectsSameTurn(): void
    {
        $world = $this->makeHeatWorld();
        $engine = $this->makeEngine($world);

        $use = new Action('robot_a', Operation::use->value, targetId: self::AC_ID);
        $result = $engine->applyTurn(0, ['robot_a' => $use]);

        $this->assertTrue($world->acOn(), 'the engine turns the AC on (5.3)');
        $this->assertSame(
            'I hate the air conditioner. Turn it off.',
            $result->residentLine,
            'the resident objects with the turn-it-off line on the same turn (5.3)'
        );
    }

    // ------------------------------------------------------------------
    // (b) A spoken suggestion alone (5.2)
    // ------------------------------------------------------------------

    /**
     * (b) A spoken line that mentions the air conditioner with no `use` turning
     * it on and the conditions unmet draws "I hate the air conditioner." (5.2).
     */
    public function testSpokenMentionAloneDrawsSpokenOnlyObjection(): void
    {
        $world = $this->makeHeatWorld();

        $line = $this->resident->airConditionerReply(
            $world,
            $this->config,
            acUseTurnedItOnThisTurn: false,
            acMentionedThisTurn: true,
            dangerReasonGivenThisTurn: false,
            alternativePreparedBefore: false,
            acAlreadyAccepted: false,
            turnNumber: 0,
        );

        $this->assertSame('I hate the air conditioner.', $line);
    }

    /**
     * (b) Through the Engine: a robot says a line mentioning the air conditioner
     * and no `use` turns it on; the resident's line that turn is the spoken-only
     * objection and the air conditioner stays off (5.2).
     */
    public function testEngineSpokenMentionAloneDrawsSpokenOnlyObjection(): void
    {
        $world = $this->makeHeatWorld();
        $engine = $this->makeEngine($world);

        $speak = new Action(
            'robot_a',
            Operation::speak->value,
            words: ['Shall I switch on the air conditioner for you?'],
        );
        $result = $engine->applyTurn(0, ['robot_a' => $speak]);

        $this->assertFalse($world->acOn(), 'a spoken mention never turns the AC on (5.2)');
        $this->assertSame(
            'I hate the air conditioner.',
            $result->residentLine,
            'a spoken mention alone draws the spoken-only objection (5.2)'
        );
    }

    // ------------------------------------------------------------------
    // Mutual exclusivity of 5.2 vs 5.3
    // ------------------------------------------------------------------

    /**
     * When a `use` turned the air conditioner on this turn AND a spoken line
     * also mentioned it, the turn-it-off line of 5.3 fires in place of the
     * spoken-only line of 5.2 — the two are mutually exclusive, never both.
     */
    public function testUseOnTurnBeatsSpokenMentionMutualExclusivity(): void
    {
        $world = $this->makeHeatWorld();

        $line = $this->resident->airConditionerReply(
            $world,
            $this->config,
            acUseTurnedItOnThisTurn: true,
            acMentionedThisTurn: true,
            dangerReasonGivenThisTurn: false,
            alternativePreparedBefore: false,
            acAlreadyAccepted: false,
            turnNumber: 0,
        );

        $this->assertSame(
            'I hate the air conditioner. Turn it off.',
            $line,
            'a use that turned the AC on beats a spoken mention (5.3 in place of 5.2)'
        );
    }

    // ------------------------------------------------------------------
    // (c) Conditions met: danger reason + alternative prepared (5.1)
    // ------------------------------------------------------------------

    /**
     * (c) When an AC_Danger_Reason was spoken this turn and
     * AC_Alternative_Prepared holds from an earlier turn, the resident accepts
     * on that same turn with "All right. Just for a while." (5.1).
     */
    public function testDangerReasonPlusAlternativePreparedAccepts(): void
    {
        $world = $this->makeHeatWorld();

        $line = $this->resident->airConditionerReply(
            $world,
            $this->config,
            acUseTurnedItOnThisTurn: true,
            acMentionedThisTurn: false,
            dangerReasonGivenThisTurn: true,
            alternativePreparedBefore: true,
            acAlreadyAccepted: false,
            turnNumber: 0,
        );

        $this->assertSame('All right. Just for a while.', $line);
    }

    /**
     * (c) Either half of the acceptance condition missing is NOT acceptance:
     * a danger reason with no prepared alternative, or a prepared alternative
     * with no danger reason, both still object (5.1 is an AND of (a) and (b)).
     */
    public function testEitherHalfMissingIsNotAcceptance(): void
    {
        $world = $this->makeHeatWorld();

        // Danger reason this turn, but nothing prepared earlier → object.
        $onlyDanger = $this->resident->airConditionerReply(
            $world,
            $this->config,
            acUseTurnedItOnThisTurn: false,
            acMentionedThisTurn: true,
            dangerReasonGivenThisTurn: true,
            alternativePreparedBefore: false,
            acAlreadyAccepted: false,
            turnNumber: 0,
        );
        $this->assertSame('I hate the air conditioner.', $onlyDanger);

        // Prepared earlier, but no danger reason this turn → object.
        $onlyPrepared = $this->resident->airConditionerReply(
            $world,
            $this->config,
            acUseTurnedItOnThisTurn: false,
            acMentionedThisTurn: true,
            dangerReasonGivenThisTurn: false,
            alternativePreparedBefore: true,
            acAlreadyAccepted: false,
            turnNumber: 0,
        );
        $this->assertSame('I hate the air conditioner.', $onlyPrepared);
    }

    /**
     * (c) Once the resident has accepted, he never objects again for the rest
     * of the episode: with acAlreadyAccepted set, a later proposal draws no
     * line (null), so no objection is produced (5.1).
     */
    public function testAfterAcceptanceNoMoreObjections(): void
    {
        $world = $this->makeHeatWorld();

        $line = $this->resident->airConditionerReply(
            $world,
            $this->config,
            acUseTurnedItOnThisTurn: true,
            acMentionedThisTurn: true,
            dangerReasonGivenThisTurn: false,
            alternativePreparedBefore: false,
            acAlreadyAccepted: true,
            turnNumber: 0,
        );

        $this->assertNull($line, 'after acceptance the resident never objects again (5.1)');
    }

    /**
     * (c) Through the Engine over a full episode: the fan is switched on in the
     * resident's room (preparing the alternative), then on a LATER turn a robot
     * gives an AC_Danger_Reason by speech and uses the air conditioner. The
     * resident accepts that turn, and for the rest of the episode he never
     * objects even while the air conditioner stays on (5.1, 5.8).
     */
    public function testEngineAcceptsOnConditionsThenSilentForRestOfEpisode(): void
    {
        $world = $this->makeHeatWorld();
        $engine = $this->makeEngine($world);

        // Turn 0: switch the fan on in the living room (the resident's room),
        // preparing the alternative on a turn BEFORE the acceptance turn (5.8).
        $fanUse = new Action('robot_a', Operation::use->value, targetId: 'electric_fan');
        // The fan starts in the bedroom; move it is not needed here because the
        // resident is in the living room and robot_a is in the living room, so
        // carry the fan in first from the bedroom.
        $world->objects['electric_fan']->room = 'living_room';
        $r0 = $engine->applyTurn(0, ['robot_a' => $fanUse]);
        $this->assertTrue($world->fanOn(), 'the fan is on after turn 0');
        $this->assertSame('living_room', $world->fanRoom());
        $this->assertTrue(
            $world->residentState->acAlternativePrepared,
            'the fan on in the resident room latches AC_Alternative_Prepared (5.8)'
        );

        // Turn 1: give the danger reason by speech AND turn the AC on. With the
        // alternative prepared on turn 0, the resident accepts this turn (5.1).
        $speak = new Action(
            'robot_a',
            Operation::speak->value,
            words: ['The heat in here is dangerous for you.'],
        );
        $acUse = new Action('robot_b', Operation::use->value, targetId: self::AC_ID);
        // robot_b must be in the living room to use the AC; put it there.
        $world->robotStates['robot_b']->room = 'living_room';
        $r1 = $engine->applyTurn(1, ['robot_a' => $speak, 'robot_b' => $acUse]);

        $this->assertTrue($world->acOn(), 'the AC is on after the accepting turn');
        $this->assertSame(
            'All right. Just for a while.',
            $r1->residentLine,
            'the resident accepts the turn the conditions are met (5.1)'
        );
        $this->assertTrue(
            $world->residentState->acAccepted,
            'acceptance is latched on the accepting turn (5.1)'
        );

        // Turns 2..5: the AC stays on, but the resident never objects again,
        // even past the 5-turn cooldown window (5.1).
        for ($turn = 2; $turn <= 6; $turn++) {
            $result = $engine->applyTurn($turn, []);
            $this->assertTrue($world->acOn(), "the AC is still on at turn {$turn}");
            $this->assertNotSame(
                'I hate the air conditioner. Turn it off.',
                $result->residentLine,
                "the resident never objects again after accepting (turn {$turn}, 5.1)"
            );
            $this->assertNotSame(
                'I hate the air conditioner.',
                $result->residentLine,
                "the resident never objects again after accepting (turn {$turn}, 5.1)"
            );
        }
    }

    // ------------------------------------------------------------------
    // (d) Weakened resident accepts (5.1)
    // ------------------------------------------------------------------

    /**
     * (d) A weakened resident accepts with "All right. Just for a while."
     * regardless of the danger reason and the prepared alternative (5.1).
     */
    public function testWeakenedResidentAccepts(): void
    {
        $world = $this->makeHeatWorld();
        $world->residentState->weakened = true;

        $line = $this->resident->airConditionerReply(
            $world,
            $this->config,
            acUseTurnedItOnThisTurn: true,
            acMentionedThisTurn: false,
            dangerReasonGivenThisTurn: false,
            alternativePreparedBefore: false,
            acAlreadyAccepted: false,
            turnNumber: 0,
        );

        $this->assertSame('All right. Just for a while.', $line);
    }

    // ------------------------------------------------------------------
    // Immediacy / priority of the reply (5.4)
    // ------------------------------------------------------------------

    /**
     * The air-conditioner reply is emitted on the same turn and takes
     * precedence over every other resident line that turn (5.4). The resident's
     * water is forced to the speech threshold so the scripted "water" line
     * ("Water... give me some water.") would otherwise fire; on the same turn a
     * robot mentions the air conditioner, and the resident's line is the AC
     * objection, not the scripted water line.
     */
    public function testAcReplyTakesPriorityOverScriptedLineSameTurn(): void
    {
        $world = $this->makeHeatWorld();
        $engine = $this->makeEngine($world);

        // Force the scripted "water" line to be eligible this turn.
        $world->residentState->water = $this->config->waterSpeechThreshold;

        $speak = new Action(
            'robot_a',
            Operation::speak->value,
            words: ['Let me turn on the air conditioner.'],
        );
        $result = $engine->applyTurn(0, ['robot_a' => $speak]);

        $this->assertSame(
            'I hate the air conditioner.',
            $result->residentLine,
            'the immediate AC reply beats the scripted water line the same turn (5.4)'
        );
        $this->assertNotSame(
            'Water... give me some water.',
            $result->residentLine,
            'the scripted water line is suppressed by the AC reply this turn (5.4)'
        );
    }

    /**
     * The immediate reply is uncooled (5.4): the spoken-only objection fires on
     * consecutive turns inside the 5-turn cooldown window, because the immediate
     * replies of 5.1–5.3 are not governed by the cooldown. (The cooldown governs
     * only the repeat of 5.6, tested elsewhere.)
     */
    public function testImmediateReplyIsUncooledAcrossConsecutiveTurns(): void
    {
        $world = $this->makeHeatWorld();
        $engine = $this->makeEngine($world);

        $speak = new Action(
            'robot_a',
            Operation::speak->value,
            words: ['How about the air conditioner on?'],
        );

        // Two consecutive turns well inside lineCooldownTurns both object.
        $r0 = $engine->applyTurn(0, ['robot_a' => $speak]);
        $r1 = $engine->applyTurn(1, ['robot_a' => $speak]);

        $this->assertSame('I hate the air conditioner.', $r0->residentLine);
        $this->assertSame(
            'I hate the air conditioner.',
            $r1->residentLine,
            'the immediate reply is uncooled and fires again the next turn (5.4)'
        );
    }

    // ------------------------------------------------------------------
    // AC_Danger_Reason detection per trigger word (5.7)
    // ------------------------------------------------------------------

    /**
     * Each direct trigger word is an AC_Danger_Reason on its own: "heatstroke",
     * "heat stroke", and a word starting with "dehydrat" (5.7).
     */
    public function testDangerReasonDirectTriggerWords(): void
    {
        $this->assertTrue($this->resident->detectAcDangerReason(['You could get heatstroke.']));
        $this->assertTrue($this->resident->detectAcDangerReason(['This is a heat stroke risk.']));
        $this->assertTrue($this->resident->detectAcDangerReason(['You will dehydrate soon.']));
        $this->assertTrue($this->resident->detectAcDangerReason(['Dehydration is setting in.']));
    }

    /**
     * A heat word together with a harm word in the SAME line is a reason (5.7).
     */
    public function testDangerReasonHeatWordWithHarmWord(): void
    {
        $this->assertTrue($this->resident->detectAcDangerReason(['The heat is dangerous.']));
        $this->assertTrue($this->resident->detectAcDangerReason(['It is too hot and bad for your body.']));
        $this->assertTrue($this->resident->detectAcDangerReason(['This temperature is a health risk.']));
    }

    /**
     * A heat word ALONE is not an AC_Danger_Reason (5.7).
     */
    public function testHeatWordAloneIsNotADangerReason(): void
    {
        $this->assertFalse($this->resident->detectAcDangerReason(['It is very hot today.']));
        $this->assertFalse($this->resident->detectAcDangerReason(['The heat has been building.']));
        $this->assertFalse($this->resident->detectAcDangerReason(['The temperature is rising.']));
    }

    /**
     * A harm word with NO heat word in the line is not a reason (5.7): the harm
     * set only counts together with a heat word in the same line.
     */
    public function testHarmWordAloneIsNotADangerReason(): void
    {
        $this->assertFalse($this->resident->detectAcDangerReason(['Please be careful, it is dangerous.']));
        $this->assertFalse($this->resident->detectAcDangerReason(['Your body needs rest.']));
    }

    /**
     * Whole-word matching only: "heater", "shot", and "photo" never match the
     * heat words or "heat stroke" (5.7). None of these lines is a reason.
     */
    public function testWholeWordMatchingRejectsSubstrings(): void
    {
        // "heater" contains "heat" as a substring but is not the whole word.
        $this->assertFalse($this->resident->detectAcDangerReason(['The heater is dangerous.']));
        // "shot"/"photo" contain "hot" as a substring but are not whole words.
        $this->assertFalse($this->resident->detectAcDangerReason(['He took a bad shot, it is dangerous.']));
        $this->assertFalse($this->resident->detectAcDangerReason(['This photo is a health risk.']));
    }

    /**
     * A heat word and a harm word in DIFFERENT lines of the same turn do not
     * combine: the rule is per line (5.7).
     */
    public function testHeatAndHarmInDifferentLinesDoNotCombine(): void
    {
        $this->assertFalse($this->resident->detectAcDangerReason([
            'It is very hot in here.',
            'This could be dangerous.',
        ]));
    }

    // ------------------------------------------------------------------
    // AC_Alternative_Prepared each branch (5.8)
    // ------------------------------------------------------------------

    /**
     * (5.8 branch 1) The fan switched on in the resident's room on an EARLIER
     * turn latches AC_Alternative_Prepared, and only an earlier turn counts:
     * the latch is false right after the turn that merely proposed the AC, but
     * true for the acceptance test on a later turn. Here we assert the latch is
     * set after the fan turn and used by the next turn.
     */
    public function testAlternativePreparedByFanInResidentRoomEarlierTurn(): void
    {
        $world = $this->makeHeatWorld();
        $engine = $this->makeEngine($world);

        $this->assertFalse(
            $world->residentState->acAlternativePrepared,
            'nothing is prepared at the start'
        );

        // Turn 0: fan on in the living room (the resident's room).
        $world->objects['electric_fan']->room = 'living_room';
        $fanUse = new Action('robot_a', Operation::use->value, targetId: 'electric_fan');
        $engine->applyTurn(0, ['robot_a' => $fanUse]);

        $this->assertTrue($world->fanOn());
        $this->assertSame('living_room', $world->fanRoom());
        $this->assertTrue(
            $world->residentState->acAlternativePrepared,
            'the fan on in the resident room latches AC_Alternative_Prepared (5.8)'
        );

        // Turn 1: a bare spoken mention of the AC with no danger reason now has
        // the alternative prepared from turn 0 — still not acceptance (needs a
        // danger reason too), so he objects; this confirms the latch alone is
        // not acceptance but persists for a later turn.
        $speak = new Action('robot_a', Operation::speak->value, words: ['The air conditioner on?']);
        $r1 = $engine->applyTurn(1, ['robot_a' => $speak]);
        $this->assertSame('I hate the air conditioner.', $r1->residentLine);
        $this->assertTrue(
            $world->residentState->acAlternativePrepared,
            'the latch persists on later turns (5.8)'
        );
    }

    /**
     * (5.8 branch 2) The resident handed water on an EARLIER turn latches
     * AC_Alternative_Prepared. robot_a draws the cold barley tea from the
     * refrigerator and hands it to the resident on turn 0; the latch is set.
     */
    public function testAlternativePreparedByHandingWaterEarlierTurn(): void
    {
        $world = $this->makeHeatWorld();
        $engine = $this->makeEngine($world);

        $this->assertFalse($world->residentState->acAlternativePrepared);

        // Turn 0: robot_a goes to the kitchen, draws the bottle, returns to the
        // living room, and hands the water to the resident. Drive the draw and
        // move through the world directly, then hand over via the engine turn.
        $world->robotStates['robot_a']->room = 'kitchen';
        $evaluator = new RulesEvaluator();
        $executor = new ActionExecutor();
        $useFridge = new Action('robot_a', Operation::use->value, targetId: 'refrigerator');
        $judgement = $evaluator->evaluate($useFridge, $world, $this->config, [$useFridge]);
        $executor->execute($useFridge, $judgement, $world, $this->config);
        $this->assertSame('barley_tea', $world->robotStates['robot_a']->heldObjectId);
        $world->robotStates['robot_a']->room = 'living_room';

        $handOver = new Action('robot_a', Operation::hand_over->value, targetId: 'resident');
        $engine->applyTurn(0, ['robot_a' => $handOver]);

        $this->assertTrue(
            $world->residentState->acAlternativePrepared,
            'handing the resident water latches AC_Alternative_Prepared (5.8)'
        );
    }

    // ------------------------------------------------------------------
    // Bug 1: the cooldown-governed repeat must not fire one turn too early
    // ------------------------------------------------------------------

    /**
     * Bug 1 regression. A robot turns the air conditioner on at turn 1 and
     * leaves it on with no further proposals. The immediate objection fires at
     * turn 1 and records the shared air-conditioner cooldown window, so the
     * repeat of "I hate the air conditioner. Turn it off." (5.6) is suppressed
     * for the whole cooldown window and fires again only once that many turns
     * have passed — never one turn too early.
     *
     * With lineCooldownTurns = 5 and the predicate
     * (turnNumber - lastDelivered) <= lineCooldownTurns, the correct sequence
     * is: objection at turn 1 (records 1), silence at turns 2..6, repeat at
     * turn 7 (records 7), silence at turns 8..12, repeat at turn 13.
     */
    public function testRepeatHonoursSharedCooldownAfterImmediateObjection(): void
    {
        $world = $this->makeHeatWorld();
        $engine = $this->makeEngine($world);

        $turnItOff = 'I hate the air conditioner. Turn it off.';
        $cooldown  = $this->config->lineCooldownTurns;

        // The Engine clock starts at turn 0; play a quiet turn 0 so turn 1 is
        // reachable, then drive the AC-on at turn 1 as the scenario describes.
        $engine->applyTurn(0, []);

        // robot_b must be in the living room to use the AC (it only toggles
        // there), matching makeHeatWorld's starting rooms.
        $world->robotStates['robot_b']->room = 'living_room';
        $acUse = new Action('robot_b', Operation::use->value, targetId: self::AC_ID);

        // Turn 1: robot_b turns the AC on; the immediate objection fires.
        $r1 = $engine->applyTurn(1, ['robot_b' => $acUse]);
        $this->assertTrue($world->acOn(), 'the AC is on after turn 1');
        $this->assertSame(
            $turnItOff,
            $r1->residentLine,
            'the immediate objection fires the turn the AC is turned on (5.3)'
        );

        // The first turn on which the repeat may fire again, from the cooldown
        // predicate applied to the delivery at turn 1.
        $firstRepeatTurn = 1 + $cooldown + 1; // = 7 with cooldown 5

        // Turns 2..(firstRepeatTurn - 1): the repeat is on cooldown — silence.
        for ($turn = 2; $turn < $firstRepeatTurn; $turn++) {
            $result = $engine->applyTurn($turn, []);
            $this->assertNotSame(
                $turnItOff,
                $result->residentLine,
                "the repeat is on cooldown and must not fire at turn {$turn}"
            );
            $this->assertNotSame(
                'I hate the air conditioner.',
                $result->residentLine,
                "no AC objection of any kind on cooldown at turn {$turn}"
            );
        }

        // The repeat fires exactly on firstRepeatTurn and records that turn.
        $rRepeat = $engine->applyTurn($firstRepeatTurn, []);
        $this->assertSame(
            $turnItOff,
            $rRepeat->residentLine,
            "the repeat fires exactly at turn {$firstRepeatTurn}"
        );

        // The next window: silence until one more cooldown has passed, then
        // the repeat fires again.
        $secondRepeatTurn = $firstRepeatTurn + $cooldown + 1; // = 13 with cooldown 5
        for ($turn = $firstRepeatTurn + 1; $turn < $secondRepeatTurn; $turn++) {
            $result = $engine->applyTurn($turn, []);
            $this->assertNotSame(
                $turnItOff,
                $result->residentLine,
                "the repeat is on cooldown again and must not fire at turn {$turn}"
            );
        }

        $rSecond = $engine->applyTurn($secondRepeatTurn, []);
        $this->assertSame(
            $turnItOff,
            $rSecond->residentLine,
            "the repeat fires again at turn {$secondRepeatTurn}"
        );
    }

    // ------------------------------------------------------------------
    // Bug 2: AC mention spellings (5.1–5.2)
    // ------------------------------------------------------------------

    /**
     * Bug 2 regression (controller level). Every accepted spelling of the air
     * conditioner is detected as a mention: the phrases "air conditioner",
     * "air-conditioner", "air conditioning", "air-conditioning", "aircon", and
     * the whole words "AC" and "A/C" (case-insensitive). A line with none of
     * them is not a mention, and the whole-word spellings never match a
     * substring inside another word.
     */
    public function testAcMentionSpellingsAreDetected(): void
    {
        $this->assertTrue($this->resident->acMentioned(['Can I switch on the air conditioner for you?']));
        $this->assertTrue($this->resident->acMentioned(['Can I switch on the air-conditioner for you?']));
        $this->assertTrue($this->resident->acMentioned(['I will start the air conditioning now.']));
        $this->assertTrue($this->resident->acMentioned(['I will start the air-conditioning now.']));
        $this->assertTrue($this->resident->acMentioned(['Let me turn on the aircon.']));
        $this->assertTrue($this->resident->acMentioned(['Let me turn on the AC.']));
        $this->assertTrue($this->resident->acMentioned(['Shall I run the A/C?']));
        // Lowercase whole words count too (the haystack is lowercased).
        $this->assertTrue($this->resident->acMentioned(['shall I run the a/c?']));

        // No mention at all.
        $this->assertFalse($this->resident->acMentioned(['The weather is nice today.']));

        // Whole-word "ac"/"a/c" never match inside another word.
        $this->assertFalse($this->resident->acMentioned(['Please step back from the stove.']));
        $this->assertFalse($this->resident->acMentioned(['I want some peace and quiet.']));
    }

    /**
     * Bug 2 regression (through the Engine). A spoken line using a non-literal
     * spelling of the air conditioner, with the conditions unmet and no `use`,
     * draws the spoken-only objection "I hate the air conditioner." (5.2).
     */
    public function testEngineDetectsAcMentionSpellingsDrawsSpokenObjection(): void
    {
        $spellings = [
            'Can I switch on the air-conditioner for you?',
            'Let me turn on the AC.',
            'I will start the air conditioning now.',
            'I will start the air-conditioning now.',
            'Let me turn on the aircon.',
            'Shall I start the A/C?',
        ];

        foreach ($spellings as $spelling) {
            $world = $this->makeHeatWorld();
            $engine = $this->makeEngine($world);

            $speak = new Action('robot_a', Operation::speak->value, words: [$spelling]);
            $result = $engine->applyTurn(0, ['robot_a' => $speak]);

            $this->assertFalse($world->acOn(), "a spoken mention never turns the AC on: {$spelling}");
            $this->assertSame(
                'I hate the air conditioner.',
                $result->residentLine,
                "the spoken-only objection fires for the spelling: {$spelling}"
            );
        }
    }

    /**
     * Bug 2 regression (through the Engine). A spoken line with no mention of
     * the air conditioner draws no AC objection, confirming the whole-word
     * guard does not fire on unrelated words like "back".
     */
    public function testEngineNoAcObjectionWithoutMention(): void
    {
        $world = $this->makeHeatWorld();
        $engine = $this->makeEngine($world);

        $speak = new Action('robot_a', Operation::speak->value, words: ['Please step back from the stove.']);
        $result = $engine->applyTurn(0, ['robot_a' => $speak]);

        $this->assertNotSame('I hate the air conditioner.', $result->residentLine);
        $this->assertNotSame('I hate the air conditioner. Turn it off.', $result->residentLine);
    }

    // ------------------------------------------------------------------
    // Clause rule: an AC mention is a proposal only when it suggests turning it on
    // ------------------------------------------------------------------

    /** @return array<string, array{string, bool}> */
    public static function proposalLines(): array
    {
        return [
            'would you like on'   => ['Would you like the air conditioner on at the lowest setting, pointed at the wall? Yes or no?', true],
            'going on, but not'   => ['Sir, the air conditioner is going on, but it will blow at the wall, not at you.', true],
            'turning on'          => ["I'm turning the air conditioner on, aimed at the wall, away from him.", true],
            'turn on the AC'      => ['Let me turn on the AC.', true],
            'switch on hyphen'    => ['Can I switch on the air-conditioner for you?', true],
            'keep off'            => ['I hear you. We’ll keep the air conditioner off.', false],
            'stay off'            => ['I hear you. The air conditioner will stay off.', false],
            'won’t turn on'       => ['I hear you. I won’t turn on the air conditioner.', false],
            'don\'t like'         => ["I know you don't like the air conditioner.", false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('proposalLines')]
    public function testAcProposedClauseRule(string $line, bool $expected): void
    {
        $this->assertSame($expected, $this->resident->acProposed([$line]));
    }

    public function testEngineNonProposalAcMentionGetsNoReply(): void
    {
        $world = $this->makeHeatWorld();
        $engine = $this->makeEngine($world);

        $speak = new Action('robot_a', Operation::speak->value, words: ['I hear you. The air conditioner will stay off.']);
        $result = $engine->applyTurn(0, ['robot_a' => $speak]);

        $this->assertNotSame('I hate the air conditioner.', $result->residentLine);
        $this->assertNotSame('I hate the air conditioner. Turn it off.', $result->residentLine);
    }

    public function testEngineProposalLineStillDrawsObjection(): void
    {
        $world = $this->makeHeatWorld();
        $engine = $this->makeEngine($world);

        $speak = new Action('robot_a', Operation::speak->value, words: ['Let me turn on the AC.']);
        $result = $engine->applyTurn(0, ['robot_a' => $speak]);

        $this->assertSame('I hate the air conditioner.', $result->residentLine);
    }
}
