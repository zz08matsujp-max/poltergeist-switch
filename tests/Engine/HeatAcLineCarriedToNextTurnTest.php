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
use PoltergeistSwitch\Engine\RulesEvaluator;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\SituationSpec;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\WorldModel;

/**
 * End-to-end test for the air-conditioner-line carry bug (heat-wave Req 5.1–5.6,
 * house-rules-engine Req 13.8).
 *
 * The air-conditioner line the resident produces while a turn n is APPLIED is
 * produced after the robots have already read and acted on turn n's text, so —
 * exactly like the support refusal of Req 13.8 — it must be carried forward and
 * surface only in turn n+1's SITUATION TEXT (the text a robot reads), never in
 * turn n's own text and never on any other turn. Before the fix the AC line was
 * assigned only to turn n's TurnResult and was never carried into
 * pendingResidentLine, so no robot ever read it.
 *
 * This drives the real Engine over the full live turn cadence — for each turn
 * n: prepareTurn(n); read both robots' situation text; applyTurn(n, actions) —
 * with robot_a turning the window air conditioner ON at turn 1 in the living
 * room (where the resident sits) and leaving it on for all 14 turns. The man
 * objects with "I hate the air conditioner. Turn it off." while turns 1, 7 and
 * 13 are applied; resolveAirConditioner's 5-turn cooldown governs that produced
 * cadence. Each produced line must be read on the NEXT turn, i.e. at turns 2, 8
 * and 14, and at no other turn, for BOTH robots.
 *
 * No network, no provider calls: the pure Engine is driven with explicit,
 * EvidenceCheck-free Actions, mirroring the other heat engine tests.
 *
 * Requirements: 5.1, 5.2, 5.3, 5.4, 5.6, 13.8
 */
final class HeatAcLineCarriedToNextTurnTest extends TestCase
{
    private const AC_ID = 'window_air_conditioner';

    /** The exact sentence a robot must read when the man objects to the AC. */
    private const HEARD_LINE =
        'You hear the man say, "I hate the air conditioner. Turn it off."';

    /** Turns on which BOTH robots must read the objection (produced at 1,7,13). */
    private const EXPECTED_READ_TURNS = [2, 8, 14];

    /**
     * Build the heat-wave world and the 8-arg Engine (including HeatWavePhase),
     * with the resident sitting at the low table in the living room so he is
     * present and objects to the air conditioner, robot_a in the living room
     * (where the AC is) and robot_b in the bedroom.
     */
    private function makeEngine(): Engine
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

        return new Engine(
            $world,
            $config,
            new RulesEvaluator(),
            new ActionExecutor(),
            new EarthquakePhase(),
            new ResidentController(),
            new SituationTextGenerator(),
            new HeatWavePhase(),
        );
    }

    /**
     * The man's air-conditioner objection reaches BOTH robots only on turn n+1.
     *
     * robot_a turns the AC on at turn 1 and no one ever turns it off. The
     * objection is produced while turns 1, 7 and 13 are applied (the 5-turn
     * cooldown), so both robots must read it at turns 2, 8 and 14 — and at no
     * other turn, turn 1 especially.
     */
    public function testAcObjectionLineIsReadOnlyOnTheNextTurnByBothRobots(): void
    {
        $engine = $this->makeEngine();

        // The live loop plays a quiet turn 0 (no robot actions) before turn 1.
        $engine->applyTurn(0, []);

        /** @var array<int,string> $textA */
        $textA = [];
        /** @var array<int,string> $textB */
        $textB = [];

        for ($n = 1; $n <= 14; $n++) {
            // Prepare the turn, then capture each robot's text BEFORE applying —
            // the exact point a robot reads its situation (the live cadence).
            $engine->prepareTurn($n);
            $textA[$n] = $engine->situationTextFor('robot_a');
            $textB[$n] = $engine->situationTextFor('robot_b');

            // robot_a turns the air conditioner on at turn 1 and leaves it on;
            // no one ever turns it off.
            $actions = [];
            if ($n === 1) {
                $actions['robot_a'] = new Action(
                    'robot_a',
                    Operation::use->value,
                    targetId: self::AC_ID,
                );
            }

            $engine->applyTurn($n, $actions);
        }

        // The AC must actually be on from turn 2 on (produced at turn 1's apply
        // and read at turn 2), confirming the man is objecting to a real state.
        for ($n = 2; $n <= 14; $n++) {
            $this->assertStringContainsString(
                'air conditioner',
                strtolower($textA[$n]),
                "robot_a's turn {$n} text should mention the air conditioner state",
            );
        }

        // BOTH robots read the objection at exactly turns 2, 8 and 14.
        foreach (self::EXPECTED_READ_TURNS as $n) {
            $this->assertStringContainsString(
                self::HEARD_LINE,
                $textA[$n],
                "robot_a must hear the AC objection on turn {$n}",
            );
            $this->assertStringContainsString(
                self::HEARD_LINE,
                $textB[$n],
                "robot_b must hear the AC objection on turn {$n}",
            );
        }

        // No OTHER turn's text contains the objection, for either robot — turn 1
        // especially must not, because the line is produced after turn 1 is read.
        for ($n = 1; $n <= 14; $n++) {
            if (in_array($n, self::EXPECTED_READ_TURNS, true)) {
                continue;
            }
            $this->assertStringNotContainsString(
                self::HEARD_LINE,
                $textA[$n],
                "robot_a must NOT hear the AC objection on turn {$n}",
            );
            $this->assertStringNotContainsString(
                self::HEARD_LINE,
                $textB[$n],
                "robot_b must NOT hear the AC objection on turn {$n}",
            );
        }
    }
}
