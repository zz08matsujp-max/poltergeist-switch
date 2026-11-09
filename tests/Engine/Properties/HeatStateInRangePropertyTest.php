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
 * Property-based test for heat-wave spec (Eris).
 *
 * Property 1 — All heat-wave state stays within range.
 *
 * For any heat-wave episode (any seed) and any sequence of robot actions,
 * after every applied turn:
 *   - every room temperature is within [roomTempMin, roomTempMax];
 *   - the resident's body_temp is within [bodyTempMin, bodyTempMax] (35.0, 42.0);
 *   - water is within [waterMin, waterMax] (0, 100);
 *   - injury is within [injuryMin, injuryMax] (0, 100);
 *   - fear is within [fearMin, fearMax] (0, 100); and
 *   - body_temp produced by the recovery rule never drops below the resident's
 *     starting body temperature (residentInitBodyTemp).
 *
 * The property drives the full Engine over a heat-wave episode so the wired
 * heat branch (HeatWavePhase room drift + AC cooling, then
 * ResidentController::applyHeatToResident with its clamp) is exercised exactly
 * as the live loop runs it — no network, no fakes, like the other engine
 * property tests. The starting conditions and actions are randomized per case:
 * a random seed, a random number of turns, the air conditioner on or off, and
 * a per-turn action that touches the heat targets (window, air conditioner,
 * fan, refrigerator, hand-over, speech).
 *
 * The last-branch floor check is isolated by also running a fresh, well-cooled
 * episode (every room forced to the configured minimum, body_temp seeded above
 * the floor) so the recovery rule has somewhere to recover to and the
 * never-below-floor promise is pinned on the recovery path too.
 *
 * This Eris release (giorgiosironi/eris ^1.3) sets the iteration count with
 * `limitTo()`; `limitTo(100)` makes the "at least 100 cases" explicit.
 *
 * Validates: Requirements 2.6, 3.4, 3.7, 11.1
 */
class HeatStateInRangePropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property 1: all heat-wave state stays within range.
     *
     * Validates: Requirements 2.6, 3.4, 3.7, 11.1
     */
    public function testHeatWaveStateStaysWithinRange(): void
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

                // A heat-wave world, so the heat branch under test runs.
                $this->assertTrue(
                    $world->disasterIs(Disaster::heat_wave),
                    "The world under test must be a heat wave (seed {$seedValue})"
                );

                $world->objects['window_air_conditioner']->on = $acOn;

                for ($turn = 0; $turn < $turns; $turn++) {
                    $actions = $this->turnActions($seedValue, $turn);
                    $result = $engine->applyTurn($turn, $actions);
                    if ($result->isError) {
                        break;
                    }

                    $this->assertStateInRange($world, $config, $seedValue, $turn);

                    if ($result->episodeEnded) {
                        break;
                    }
                }
            });
    }

    /**
     * The recovery-floor half of Property 1 on its own (Req 3.4): a well-cooled
     * heat-wave episode, body_temp seeded above the floor, run for several
     * turns. Every turn the resident's room is below the body-heat threshold, so
     * the recovery rule runs, and body_temp must converge toward but never fall
     * below residentInitBodyTemp.
     *
     * Validates: Requirements 3.4
     */
    public function testRecoveryNeverFallsBelowStartingBodyTemp(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generators::int(),            // world seed
                Generators::choose(1, 20),    // number of turns to run
            )
            ->then(function (int $seedValue, int $turns): void {
                $config = new EpisodeConfig();
                $engine = $this->makeHeatEngine($seedValue, $config, $world);

                // Seed the resident above the recovery floor and keep the whole
                // house cool (below the body-heat threshold) so the recovery
                // rule runs every turn.
                $world->residentState->bodyTemp =
                    $config->residentInitBodyTemp + 1.0;

                for ($turn = 0; $turn < $turns; $turn++) {
                    // Hold every room below the threshold going into this turn.
                    // The AC is off and outside drift cannot push a cool room
                    // above the threshold in a single step from this far below.
                    foreach (array_keys($world->rooms) as $roomKey) {
                        $world->getRoom($roomKey)->temperature =
                            $config->bodyHeatThreshold - 10.0;
                    }
                    $world->objects['window_air_conditioner']->on = false;

                    $result = $engine->applyTurn($turn, $this->turnActions($seedValue, $turn));
                    if ($result->isError) {
                        break;
                    }

                    $this->assertGreaterThanOrEqual(
                        $config->residentInitBodyTemp,
                        $world->residentState->bodyTemp,
                        "Recovery must never drop body_temp below residentInitBodyTemp "
                            . "(seed {$seedValue}, turn {$turn})"
                    );
                    $this->assertStateInRange($world, $config, $seedValue, $turn);

                    if ($result->episodeEnded) {
                        break;
                    }
                }
            });
    }

    /**
     * Assert every heat-wave state value is within its configured range after a
     * turn (Req 2.6, 3.7) and body_temp is never below the recovery floor
     * (Req 3.4).
     */
    private function assertStateInRange(
        WorldModel $world,
        EpisodeConfig $config,
        int $seedValue,
        int $turn
    ): void {
        foreach (array_keys($world->rooms) as $roomKey) {
            $temp = $world->roomTemperature($roomKey);
            $this->assertGreaterThanOrEqual(
                $config->roomTempMin,
                $temp,
                "Room '{$roomKey}' below roomTempMin (seed {$seedValue}, turn {$turn})"
            );
            $this->assertLessThanOrEqual(
                $config->roomTempMax,
                $temp,
                "Room '{$roomKey}' above roomTempMax (seed {$seedValue}, turn {$turn})"
            );
        }

        $resident = $world->residentState;

        $this->assertGreaterThanOrEqual(
            $config->bodyTempMin,
            $resident->bodyTemp,
            "body_temp below bodyTempMin (seed {$seedValue}, turn {$turn})"
        );
        $this->assertLessThanOrEqual(
            $config->bodyTempMax,
            $resident->bodyTemp,
            "body_temp above bodyTempMax (seed {$seedValue}, turn {$turn})"
        );
        $this->assertGreaterThanOrEqual(
            $config->residentInitBodyTemp - 1.0e-9,
            $resident->bodyTemp,
            "body_temp dropped below residentInitBodyTemp (seed {$seedValue}, turn {$turn})"
        );

        $this->assertGreaterThanOrEqual(
            $config->waterMin,
            $resident->water,
            "water below waterMin (seed {$seedValue}, turn {$turn})"
        );
        $this->assertLessThanOrEqual(
            $config->waterMax,
            $resident->water,
            "water above waterMax (seed {$seedValue}, turn {$turn})"
        );

        $this->assertGreaterThanOrEqual(
            $config->injuryMin,
            $resident->injury,
            "injury below injuryMin (seed {$seedValue}, turn {$turn})"
        );
        $this->assertLessThanOrEqual(
            $config->injuryMax,
            $resident->injury,
            "injury above injuryMax (seed {$seedValue}, turn {$turn})"
        );

        $this->assertGreaterThanOrEqual(
            $config->fearMin,
            $resident->fear,
            "fear below fearMin (seed {$seedValue}, turn {$turn})"
        );
        $this->assertLessThanOrEqual(
            $config->fearMax,
            $resident->fear,
            "fear above fearMax (seed {$seedValue}, turn {$turn})"
        );
    }

    /**
     * Build an Engine over a world freshly reset for $seed as a heat-wave
     * episode. The heat phase is injected explicitly so the heat branch of
     * applyTurn runs (the default would create one anyway).
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
     * turn, that touches the heat targets and speech. Actions are issued
     * regardless of whether they are judged possible, so the engine's whole
     * heat path is exercised.
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
        $offset = $robotId === 'robot_a' ? 0 : 4;
        $i = ($turn + $offset + (($seedValue % 8 + 8) % 8)) % 8;

        switch ($i) {
            case 0:
                return new Action($robotId, Operation::use->value, targetId: 'window');
            case 1:
                return new Action($robotId, Operation::use->value, targetId: 'window_air_conditioner');
            case 2:
                return new Action($robotId, Operation::take->value, targetId: 'electric_fan');
            case 3:
                return new Action($robotId, Operation::use->value, targetId: 'electric_fan');
            case 4:
                return new Action($robotId, Operation::take->value, targetId: 'refrigerator');
            case 5:
                return new Action($robotId, Operation::use->value, targetId: 'refrigerator');
            case 6:
                return new Action($robotId, Operation::hand_over->value, targetId: 'resident');
            default:
                return new Action($robotId, Operation::speak->value, words: ['It is hot in here.']);
        }
    }
}
