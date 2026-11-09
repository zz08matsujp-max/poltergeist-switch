<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\Enums\Disaster;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\ResidentController;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\WorldModel;

/**
 * Property-based test for heat-wave spec Requirements 5.6 and 11.4 (Eris).
 *
 * Property 6 — The air-conditioner objection line obeys the cooldown.
 *
 * Requirement 5.6 says that WHILE the air conditioner is on after the resident
 * objected AND the acceptance conditions are still not met, the resident
 * repeats "I hate the air conditioner. Turn it off." subject to the 5-turn
 * line cooldown and the one-line-per-turn rule. This repeat is the ONLY
 * air-conditioner line governed by the cooldown; the immediate replies of
 * Requirements 5.1–5.3 are uncooled (Req 5.4). Requirement 11.4 asks that this
 * be pinned by a property test.
 *
 * `ResidentController::airConditionerRepeat` is the method that owns the
 * cooldown-governed repeat: it fires the line only when the air conditioner is
 * on, the resident has not accepted, and the line is not within its cooldown
 * window, recording each delivery turn under its own dedicated line key so the
 * window is measured only against earlier repeats. This property drives that
 * method turn by turn over a heat-wave world with the air conditioner on and
 * the acceptance conditions deliberately left unmet (the resident is not
 * weakened and has not accepted), so every turn is a turn on which the repeat
 * is eligible to fire, and asserts:
 *
 *   1. at most one repeat line fires per turn (the method returns at most one
 *      line per call, so a turn delivers the repeat at most once); and
 *   2. the gap between consecutive repeat deliveries is at least
 *      `lineCooldownTurns` turns — never more often than once every cooldown
 *      window.
 *
 * The cooldown number comes from EpisodeConfig (`lineCooldownTurns`), never a
 * literal, so a config change moves the asserted gap with it. Seeds and the
 * starting turn number are varied so the property holds across many worlds and
 * many turn offsets, not just a run that starts at turn 1.
 *
 * The controller is exercised directly (no Engine, no network, no fakes), the
 * way the other engine property tests drive a single pure component. This Eris
 * release (giorgiosironi/eris ^1.3) sets the iteration count with `limitTo()`;
 * `limitTo(100)` makes the "at least 100 cases" explicit.
 *
 * Validates: Requirements 5.6, 11.4
 */
class AcObjectionLineCooldownPropertyTest extends TestCase
{
    use TestTrait;

    /** The one air-conditioner line the cooldown governs (Req 5.6). */
    private const REPEAT_LINE = 'I hate the air conditioner. Turn it off.';

    /**
     * Property 6: the repeated air-conditioner objection line fires at most
     * once per turn and never more often than once every `lineCooldownTurns`
     * turns, while the air conditioner is on and the acceptance conditions are
     * unmet.
     *
     * Validates: Requirements 5.6, 11.4
     */
    public function testAcObjectionLineObeysTheCooldown(): void
    {
        $config = new EpisodeConfig();
        $cooldown = $config->lineCooldownTurns;

        $this->limitTo(100)
            ->forAll(
                Generators::int(),            // world seed
                // Enough turns that several cooldown windows pass, so repeated
                // firings are observed and their gaps can be checked.
                Generators::choose($cooldown * 2 + 2, $cooldown * 6 + 10),
                // A varied starting turn number so the property is not special
                // to a run beginning at turn 1.
                Generators::choose(1, 40),
            )
            ->then(function (
                int $seedValue,
                int $turnCount,
                int $startTurn
            ) use ($config, $cooldown): void {
                $world = new WorldModel($config);
                $world->reset(Seed::fromInt($seedValue), null, Disaster::heat_wave);

                // Sanity: this is genuinely a heat-wave world, so the heat
                // branch under test is the one that runs.
                $this->assertTrue(
                    $world->disasterIs(Disaster::heat_wave),
                    "The world under test must be a heat wave (seed {$seedValue})"
                );

                // The air conditioner is on after the resident objected, and the
                // acceptance conditions are deliberately unmet: he is not
                // weakened and has not accepted. Every turn is therefore a turn
                // on which the repeat is eligible, so only the cooldown gates it.
                $world->objects['window_air_conditioner']->on = true;
                $resident = $world->residentState;
                $resident->acAccepted = false;
                $resident->weakened = false;

                $controller = new ResidentController();

                $deliveredTurns = [];

                for ($i = 0; $i < $turnCount; $i++) {
                    $turnNumber = $startTurn + $i;

                    $line = $controller->airConditionerRepeat(
                        $world,
                        $config,
                        $turnNumber,
                    );

                    if ($line === null) {
                        continue;
                    }

                    // One-line-per-turn: when the repeat fires it is exactly the
                    // cooldown-governed line and nothing else (Req 5.6).
                    $this->assertSame(
                        self::REPEAT_LINE,
                        $line,
                        sprintf(
                            'The only cooldown-governed air-conditioner line is the '
                                . 'turn-it-off repeat (seed %d, turn %d)',
                            $seedValue,
                            $turnNumber
                        )
                    );

                    // The gap to the previous delivery must be at least the
                    // configured cooldown — the line never fires more often than
                    // once every `lineCooldownTurns` turns (Req 5.6).
                    if ($deliveredTurns !== []) {
                        $previous = $deliveredTurns[count($deliveredTurns) - 1];
                        $gap = $turnNumber - $previous;

                        $this->assertGreaterThanOrEqual(
                            $cooldown,
                            $gap,
                            sprintf(
                                'Consecutive air-conditioner objection lines must be at '
                                    . 'least %d turns apart, got %d (turns %d and %d, seed %d)',
                                $cooldown,
                                $gap,
                                $previous,
                                $turnNumber,
                                $seedValue
                            )
                        );
                    }

                    $deliveredTurns[] = $turnNumber;
                }

                // Over a run of this length the repeat must have fired at least
                // twice, so the gap assertion above actually exercised a
                // consecutive pair rather than vacuously passing.
                $this->assertGreaterThanOrEqual(
                    2,
                    count($deliveredTurns),
                    sprintf(
                        'The repeat should fire repeatedly over %d turns so its cooldown '
                            . 'gaps are observed (seed %d, start turn %d)',
                        $turnCount,
                        $seedValue,
                        $startTurn
                    )
                );
            });
    }
}
