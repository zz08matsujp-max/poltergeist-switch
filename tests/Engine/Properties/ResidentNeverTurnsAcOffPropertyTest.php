<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine\Properties;

use Eris\Generators;
use Eris\TestTrait;
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
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\WorldModel;

/**
 * Property-based test for heat-wave spec Requirements 5.5, 11.3 (Eris).
 *
 * Tag: Feature: heat-wave, Property 5: The resident never turns the air
 * conditioner off.
 *
 * Property 5 — The resident never turns the air conditioner off.
 *
 * In a heat-wave episode the resident's whole air-conditioner will — the
 * immediate accept/object reply (Req 5.1–5.3), the cooldown-governed repeat
 * (Req 5.6), the body/water heat rule, and the weakened refresh — only ever
 * reads the world and speaks a line; it never mutates the air conditioner's
 * on/off state (Req 5.5). The ONLY thing that toggles `window_air_conditioner`
 * is a robot's `use` action in the living room, resolved in the action layer
 * (Req 4.1). So across a heat-wave episode with varied seeds and action
 * sequences (turning the AC on, speaking about it, giving a danger reason,
 * handing water, running the fan, opening the window, waiting, unknown
 * readings), the AC's on-state must change on a turn ONLY WHEN a robot issued a
 * `use` targeting the air conditioner that turn. Equivalently: once the AC is
 * on, it stays on until a robot `use` toggles it — the resident never turns it
 * off, even when he objects "I hate the air conditioner. Turn it off." or when
 * he has accepted it.
 *
 * The property drives the full Engine over a heat-wave episode so the wired
 * resident AC will (resolveAirConditioner → airConditionerReply /
 * airConditionerRepeat) runs exactly as the live loop does — no network, no
 * fakes. Each case records the AC's on-state immediately before each
 * applyTurn, notes whether that turn's issued actions include a robot `use` on
 * the air conditioner, then — after the turn — asserts the on-state is
 * unchanged whenever no such `use` was issued. A separate assertion pins the
 * concrete "stays on" direction: the AC is switched on first, then driven
 * through objection and acceptance turns with no AC `use`, and it must remain
 * on throughout.
 *
 * This Eris release (giorgiosironi/eris ^1.3) sets the iteration count with
 * `limitTo()`; `limitTo(100)` makes the "at least 100 cases" explicit.
 *
 * Validates: Requirements 5.5, 11.3
 */
class ResidentNeverTurnsAcOffPropertyTest extends TestCase
{
    use TestTrait;

    private const AC_ID = 'window_air_conditioner';

    /**
     * Property 5: on any turn where no robot issued a `use` on the air
     * conditioner, the AC's on-state is unchanged by the turn — the resident
     * (and every engine rule other than a robot `use`) never flips it. Over the
     * run this means the resident never turns the AC off.
     *
     * Validates: Requirements 5.5, 11.3
     */
    public function testResidentNeverTurnsTheAirConditionerOff(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generators::int(),            // world seed
                Generators::choose(1, 20),    // number of turns to run
                Generators::bool(),           // air conditioner on at the start
            )
            ->then(function (int $seedValue, int $turns, bool $acOn): void {
                $config = new EpisodeConfig();
                $engine = $this->makeHeatEngine($seedValue, $config, $world);

                $this->assertTrue(
                    $world->disasterIs(Disaster::heat_wave),
                    "The world under test must be a heat wave (seed {$seedValue})"
                );

                // Start with the AC either on or off; either way the resident
                // must never be the one to flip it.
                $world->objects[self::AC_ID]->on = $acOn;

                for ($turn = 0; $turn < $turns; $turn++) {
                    $actions = $this->turnActions($seedValue, $turn);

                    // The AC's on-state going into the turn and whether a robot
                    // asked to `use` the air conditioner this turn — the ONLY
                    // operation allowed to toggle it (Req 4.1, 5.5).
                    $acOnBefore = $world->acOn();
                    $robotUsedAc = $this->anyUseTargetsAc($actions);

                    $result = $engine->applyTurn($turn, $actions);
                    if ($result->isError) {
                        break;
                    }

                    $acOnAfter = $world->acOn();

                    if (!$robotUsedAc) {
                        // No robot `use` on the AC this turn → nothing may have
                        // changed its on-state. If it changed here, something
                        // other than a robot `use` (the resident's will, the
                        // repeat, the body rule) flipped it — forbidden by
                        // Req 5.5.
                        $this->assertSame(
                            $acOnBefore,
                            $acOnAfter,
                            sprintf(
                                'AC on-state changed (%s → %s) on a turn with no robot '
                                    . 'use of the air conditioner (seed %d, turn %d): '
                                    . 'only a robot use may toggle it, the resident never does',
                                $acOnBefore ? 'on' : 'off',
                                $acOnAfter ? 'on' : 'off',
                                $seedValue,
                                $turn
                            )
                        );
                    }

                    if ($result->episodeEnded) {
                        break;
                    }
                }
            });
    }

    /**
     * The concrete "stays on" direction of Property 5 (Req 5.5): once the air
     * conditioner is on, it remains on across objection AND acceptance turns as
     * long as no robot issues a `use` on it. The resident objects first
     * ("I hate the air conditioner. Turn it off."), then — given a danger
     * reason plus a prepared alternative — accepts; through all of it he never
     * switches the AC off himself.
     *
     * Validates: Requirements 5.5, 11.3
     */
    public function testAcStaysOnThroughObjectionAndAcceptanceWithoutRobotUse(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generators::int(),            // world seed
                Generators::choose(2, 20),    // number of turns to run
            )
            ->then(function (int $seedValue, int $turns): void {
                $config = new EpisodeConfig();
                $engine = $this->makeHeatEngine($seedValue, $config, $world);

                $this->assertTrue(
                    $world->disasterIs(Disaster::heat_wave),
                    "The world under test must be a heat wave (seed {$seedValue})"
                );

                // Put the air conditioner on and the alternative-prepared latch
                // up front (a fan ran / water was handed on an earlier turn),
                // so a later danger reason triggers acceptance — exercising the
                // accept branch too. The AC is on and no robot will `use` it
                // again, so it must simply stay on the whole run.
                $world->objects[self::AC_ID]->on = true;
                $world->residentState->acAlternativePrepared = true;

                for ($turn = 0; $turn < $turns; $turn++) {
                    // A spoken line that both mentions the air conditioner and
                    // is an AC_Danger_Reason (heat word + harm word), so the
                    // resident's will runs its object/accept branches — none of
                    // which may turn the AC off. No action here is a `use` on
                    // the air conditioner.
                    $actions = [
                        'robot_a' => new Action(
                            'robot_a',
                            Operation::speak->value,
                            words: ['The heat is dangerous; please use the air conditioner.']
                        ),
                        'robot_b' => new Action('robot_b', Operation::wait->value),
                    ];

                    $this->assertFalse(
                        $this->anyUseTargetsAc($actions),
                        'the fixture must never `use` the air conditioner'
                    );

                    $result = $engine->applyTurn($turn, $actions);
                    if ($result->isError) {
                        break;
                    }

                    $this->assertTrue(
                        $world->acOn(),
                        sprintf(
                            'AC was switched off with no robot use of it (seed %d, turn %d): '
                                . 'the resident must never turn the air conditioner off',
                            $seedValue,
                            $turn
                        )
                    );

                    if ($result->episodeEnded) {
                        break;
                    }
                }
            });
    }

    /**
     * Whether any issued action this turn is a robot `use` targeting the air
     * conditioner — the only operation that may toggle it (Req 4.1).
     *
     * @param array<string,Action> $actions
     */
    private function anyUseTargetsAc(array $actions): bool
    {
        foreach ($actions as $action) {
            if ($action->operation === Operation::use->value
                && $action->targetId === self::AC_ID
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build an Engine over a world freshly reset for $seed as a heat-wave
     * episode, with the heat phase injected explicitly so the heat branch of
     * applyTurn runs.
     */
    private function makeHeatEngine(
        int $seedValue,
        EpisodeConfig $config,
        ?WorldModel &$world = null
    ): Engine {
        $world = new WorldModel($config);
        $world->reset(Seed::fromInt($seedValue), null, Disaster::heat_wave);

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
     * A fixed per-turn action pair for both robots, a pure function of seed and
     * turn, covering the heat targets and speech WITHOUT ever being a `use` on
     * the air conditioner on more than the one dedicated slot, so the property
     * can distinguish AC-use turns from the rest. The sequence includes:
     * speaking about the air conditioner, giving a danger reason, handing
     * water, running the fan, opening the window, taking the refrigerator,
     * waiting, and an unknown reading — none of which (bar the explicit AC
     * `use` slot) may ever turn the AC off.
     *
     * @return array<string,Action>
     */
    private function turnActions(int $seedValue, int $turn): array
    {
        return [
            'robot_a' => $this->turnAction('robot_a', $seedValue, $turn),
            'robot_b' => $this->turnAction('robot_b', $seedValue, $turn),
        ];
    }

    private function turnAction(string $robotId, int $seedValue, int $turn): Action
    {
        $offset = $robotId === 'robot_a' ? 0 : 5;
        $i = ($turn + $offset + (($seedValue % 10 + 10) % 10)) % 10;

        switch ($i) {
            case 0:
                // The one slot that may legitimately toggle the AC.
                return new Action($robotId, Operation::use->value, targetId: self::AC_ID);
            case 1:
                return new Action(
                    $robotId,
                    Operation::speak->value,
                    words: ['Shall we turn on the air conditioner?']
                );
            case 2:
                return new Action(
                    $robotId,
                    Operation::speak->value,
                    words: ['The heat is dangerous for your body.']
                );
            case 3:
                return new Action($robotId, Operation::take->value, targetId: 'electric_fan');
            case 4:
                return new Action($robotId, Operation::use->value, targetId: 'electric_fan');
            case 5:
                return new Action($robotId, Operation::take->value, targetId: 'refrigerator');
            case 6:
                return new Action($robotId, Operation::hand_over->value, targetId: 'resident');
            case 7:
                return new Action($robotId, Operation::use->value, targetId: 'window');
            case 8:
                return new Action($robotId, Operation::wait->value);
            default:
                return new Action($robotId, Operation::unknown->value);
        }
    }
}
