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
 * Property-based test for the heat-wave spec (Eris).
 *
 * Feature: heat-wave, Property 7: A heat-wave episode never runs past 20 turns.
 *
 * For any seed and any sequence of robot actions, a heat-wave episode ends no
 * later than the turn whose completion makes the count reach the heat turn cap
 * read from EpisodeConfig (heatMaxTurns, 20) — so it never runs past 20 turns,
 * with the heat never subsiding on its own. The cap is the only numeric source;
 * the test reads it from a fresh EpisodeConfig rather than hard-coding 20, so a
 * config change moves the test with it (no literal in the property).
 *
 * The property drives the pure engine directly, like the other engine property
 * tests (no network, no fakes). Driving the engine turn by turn exactly as the
 * live loop does (prepareTurn then applyTurn), it asserts:
 *   - every turn before the cap turn leaves episodeEnded false (the episode is
 *     still running), unless the engine's own end rule fired earlier — which is
 *     fine, an earlier end still never runs past the cap;
 *   - the turn whose completion reaches the cap sets episodeEnded true, so the
 *     episode is over by then and no turn past the cap is ever applied.
 *
 * This Eris release sets the iteration count with `limitTo()`; `limitTo(100)`
 * makes the "at least 100 cases" explicit.
 *
 * Validates: Requirements 8.4, 11.6
 */
class HeatNeverPast20TurnsPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property 7: a heat-wave episode never runs past 20 turns.
     *
     * Validates: Requirements 8.4, 11.6
     */
    public function testHeatWaveEpisodeNeverRunsPastTwentyTurns(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generators::int(),            // world seed
                Generators::bool(),           // air conditioner on at the start
            )
            ->then(function (int $seedValue, bool $acOn): void {
                $config = new EpisodeConfig();
                // The cap turn index: the turn whose completion makes the count
                // reach heatMaxTurns is index heatMaxTurns - 1 (currentTurn + 1
                // >= heatMaxTurns). Read only from config, no literal here.
                $capTurnIndex = $config->heatMaxTurns - 1;

                $world = new WorldModel($config);
                $world->reset(Seed::fromInt($seedValue), null, Disaster::heat_wave);
                $world->objects['window_air_conditioner']->on = $acOn;

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

                // Drive turn by turn up to and including the cap turn, exactly
                // as the live loop does (prepareTurn then applyTurn). The loop
                // stops the moment the episode ends.
                $ended = false;
                for ($turn = 0; $turn <= $capTurnIndex; $turn++) {
                    $engine->prepareTurn($turn);
                    $result = $engine->applyTurn($turn, $this->turnActions($seedValue, $turn));

                    if ($result->isError) {
                        $this->fail("turn {$turn} errored unexpectedly (seed {$seedValue})");
                    }

                    if ($result->episodeEnded) {
                        $ended = true;
                        // An end at or before the cap turn is exactly the
                        // guarantee: the episode never runs past the cap.
                        $this->assertLessThanOrEqual(
                            $capTurnIndex,
                            $turn,
                            "a heat-wave episode must not end past turn {$capTurnIndex} "
                                . "(seed {$seedValue})"
                        );
                        break;
                    }
                }

                // By the cap turn the episode is over (Requirement 8.4, 11.6):
                // the heat does not subside, so the turn cap is what ends it at
                // the latest.
                $this->assertTrue(
                    $ended,
                    "a heat-wave episode must have ended by turn {$capTurnIndex} "
                        . "(seed {$seedValue}, acOn " . ($acOn ? 'true' : 'false') . ")"
                );
            });
    }

    /**
     * A fixed per-turn action pair for both robots, a pure function of seed and
     * turn, that touches the heat targets and speech so the heat path is
     * exercised while the turn cap is proven.
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
        $offset = $robotId === 'robot_a' ? 0 : 3;
        $i = ($turn + $offset + (($seedValue % 6 + 6) % 6)) % 6;

        switch ($i) {
            case 0:
                return new Action($robotId, Operation::use->value, targetId: 'window_air_conditioner');
            case 1:
                return new Action($robotId, Operation::take->value, targetId: 'electric_fan');
            case 2:
                return new Action($robotId, Operation::use->value, targetId: 'electric_fan');
            case 3:
                return new Action($robotId, Operation::take->value, targetId: 'refrigerator');
            case 4:
                return new Action($robotId, Operation::hand_over->value, targetId: 'resident');
            default:
                return new Action($robotId, Operation::speak->value, words: ['It is hot in here.']);
        }
    }
}
