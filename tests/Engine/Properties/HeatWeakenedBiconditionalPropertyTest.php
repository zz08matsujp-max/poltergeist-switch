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
 * Property 4 — Weakened tracks body temperature.
 *
 * For any heat-wave episode and any turn, after the resident update
 * `weakened` is true if and only if `body_temp >= weakenedThreshold`
 * (heat-wave Requirements 3.5, 3.6). This is the biconditional that the heat
 * branch refreshes last, so it must hold after every applied heat-wave turn:
 * the flag is set when body temperature reaches the threshold and cleared when
 * it drops below, letting a robot bring the resident back after he weakens.
 *
 * The property drives the full Engine over a heat-wave episode so the wired
 * heat branch (HeatWavePhase then ResidentController::applyHeatToResident, which
 * refreshes the weakened flag last) runs exactly as the live loop does — no
 * network, no fakes. To exercise BOTH sides of the biconditional within a
 * single run, the resident's body temperature is seeded across and around the
 * threshold and the house is driven hot or cool at random, so some cases drive
 * him over the threshold (weakened true) and some keep or bring him below it
 * (weakened false). Each case asserts the biconditional after every turn.
 *
 * This Eris release (giorgiosironi/eris ^1.3) sets the iteration count with
 * `limitTo()`; `limitTo(100)` makes the "at least 100 cases" explicit.
 *
 * Validates: Requirements 3.5, 3.6
 */
class HeatWeakenedBiconditionalPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property 4: weakened is true iff body_temp >= weakenedThreshold after the
     * resident update.
     *
     * Validates: Requirements 3.5, 3.6
     */
    public function testWeakenedTracksBodyTemperature(): void
    {
        $config = new EpisodeConfig();

        // Seed body temperature across both sides of the threshold (whole
        // tenths of a degree), so cases land above, at, and below it.
        $threshold = $config->weakenedThreshold;
        $low  = (int) round(($threshold - 2.0) * 10);
        $high = (int) round(($threshold + 2.0) * 10);

        $this->limitTo(100)
            ->forAll(
                Generators::int(),              // world seed
                Generators::choose(1, 20),      // number of turns to run
                Generators::choose($low, $high), // starting body_temp * 10
                Generators::bool(),             // drive the house hot or cool
            )
            ->then(function (
                int $seedValue,
                int $turns,
                int $bodyTempTenths,
                bool $driveHot
            ) use ($config): void {
                $engine = $this->makeHeatEngine($seedValue, $config, $world);

                $this->assertTrue(
                    $world->disasterIs(Disaster::heat_wave),
                    "The world under test must be a heat wave (seed {$seedValue})"
                );

                $world->residentState->bodyTemp = $bodyTempTenths / 10.0;

                for ($turn = 0; $turn < $turns; $turn++) {
                    // Drive the whole house hot (above the body-heat threshold)
                    // or cool (below it) so body_temp climbs or recovers,
                    // reaching both sides of the weakened threshold over a run.
                    $roomTemp = $driveHot
                        ? $config->bodyHeatThreshold + 8.0
                        : $config->bodyHeatThreshold - 10.0;
                    foreach (array_keys($world->rooms) as $roomKey) {
                        $world->getRoom($roomKey)->temperature = $roomTemp;
                    }

                    $result = $engine->applyTurn($turn, $this->turnActions($seedValue, $turn));
                    if ($result->isError) {
                        break;
                    }

                    $resident = $world->residentState;
                    $expectedWeak = $resident->bodyTemp >= $config->weakenedThreshold;

                    $this->assertSame(
                        $expectedWeak,
                        $resident->weakened,
                        sprintf(
                            'weakened must be (body_temp >= %.2f): body_temp=%.4f weakened=%s '
                                . '(seed %d, turn %d)',
                            $config->weakenedThreshold,
                            $resident->bodyTemp,
                            $resident->weakened ? 'true' : 'false',
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
     * Build an Engine over a world freshly reset for $seed as a heat-wave
     * episode, with the heat phase injected explicitly.
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
     * turn. The specific actions do not matter for the biconditional; they only
     * keep the turn loop busy while the heat rule drives body temperature.
     *
     * @return array<string,Action>
     */
    private function turnActions(int $seedValue, int $turn): array
    {
        $words = ['It is hot in here.'];

        return [
            'robot_a' => new Action('robot_a', Operation::speak->value, words: $words),
            'robot_b' => new Action('robot_b', Operation::speak->value, words: $words),
        ];
    }
}
