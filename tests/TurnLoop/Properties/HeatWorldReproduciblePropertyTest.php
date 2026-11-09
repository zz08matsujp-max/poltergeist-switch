<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop\Properties;

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
use PoltergeistSwitch\TurnLoop\WorldStateSerializer;

/**
 * Property-based test for the heat-wave spec (Eris).
 *
 * Feature: heat-wave, Property 2: Heat-wave world is reproducible.
 *
 * For any seed and any sequence of robot actions, building and running a
 * heat-wave episode twice — with two independent engine builds — yields the
 * identical serialized world each turn: identical room temperatures, resident
 * state, and air-conditioner / fan / window state. The seeded PRNG (our own
 * code) and the pure, I/O-free engine make the whole run a deterministic
 * function of (seed, actions), so the same seed and the same actions produce
 * the same world on every execution — and, because nothing in the engine or the
 * serializer depends on a PHP build-specific value, on any PHP 8.2 build.
 *
 * The property compares the WorldStateSerializer JSON (not a raw snapshot): that
 * is the exact byte stream `GET /api/episodes/{id}` stores and replays, and for
 * a heat-wave world it carries the heat keys (`disaster`, `roomTemps`,
 * `outside`, `ac`, `fan`, `windows`) alongside the resident `man` block, so an
 * identical JSON each turn pins the room temperatures, the resident state, and
 * the AC / fan / window state all at once. Two Seed instances are derived from
 * the same int because a Seed's PRNG is consumed by reset(), so each world needs
 * its own Seed to draw the identical starting layout.
 *
 * No real provider API is touched: this drives the pure engine directly, like
 * the other engine property tests (no network, no fakes).
 *
 * This Eris release (giorgiosironi/eris ^1.3) sets the iteration count with
 * `limitTo()`; `limitTo(100)` makes the "at least 100 cases" explicit.
 *
 * Validates: Requirements 2.8, 11.2
 */
class HeatWorldReproduciblePropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property 2: a heat-wave world is reproducible across two independent
     * engine builds driven by the same seed and the same action sequence.
     *
     * Validates: Requirements 2.8, 11.2
     */
    public function testHeatWaveWorldIsReproducible(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generators::int(),            // world seed
                Generators::choose(1, 20),    // number of turns to run
                Generators::bool(),           // air conditioner on at the start
            )
            ->then(function (int $seedValue, int $turns, bool $acOn): void {
                $serializer = new WorldStateSerializer();

                // Two independent engine builds from the same int seed.
                $engineA = $this->makeHeatEngine($seedValue, $worldA);
                $engineB = $this->makeHeatEngine($seedValue, $worldB);

                // Both are heat-wave worlds, so the heat branch under test runs.
                $this->assertTrue(
                    $worldA->disasterIs(Disaster::heat_wave),
                    "run A must be a heat wave (seed {$seedValue})"
                );
                $this->assertTrue(
                    $worldB->disasterIs(Disaster::heat_wave),
                    "run B must be a heat wave (seed {$seedValue})"
                );

                // Identical start state for both runs (same deterministic flip).
                $worldA->objects['window_air_conditioner']->on = $acOn;
                $worldB->objects['window_air_conditioner']->on = $acOn;

                // The two independent runs start from byte-identical worlds.
                $this->assertSame(
                    $serializer->toJson($worldA),
                    $serializer->toJson($worldB),
                    "the two heat-wave runs must start from an identical world "
                        . "(seed {$seedValue})"
                );

                for ($turn = 0; $turn < $turns; $turn++) {
                    $actions = $this->turnActions($seedValue, $turn);

                    $resultA = $engineA->applyTurn($turn, $actions);
                    $resultB = $engineB->applyTurn($turn, $actions);

                    // The two runs agree on whether the turn errored and on
                    // whether the episode ended, so they stay in lock-step.
                    $this->assertSame(
                        $resultA->isError,
                        $resultB->isError,
                        "the two runs must agree on the turn-{$turn} error flag "
                            . "(seed {$seedValue})"
                    );
                    $this->assertSame(
                        $resultA->episodeEnded,
                        $resultB->episodeEnded,
                        "the two runs must agree on the turn-{$turn} end flag "
                            . "(seed {$seedValue})"
                    );

                    if ($resultA->isError) {
                        break;
                    }

                    // The load-bearing check: the serialized world is identical
                    // each turn. For a heat-wave world this JSON carries the room
                    // temperatures, the resident state, and the AC / fan / window
                    // state, so an identical string pins all of them at once.
                    $this->assertSame(
                        $serializer->toJson($worldA),
                        $serializer->toJson($worldB),
                        "the two heat-wave runs must produce an identical world "
                            . "after turn {$turn} (seed {$seedValue}, acOn "
                            . ($acOn ? 'true' : 'false') . ")"
                    );

                    if ($resultA->episodeEnded) {
                        break;
                    }
                }
            });
    }

    /**
     * Build an Engine over a world freshly reset for $seed as a heat-wave
     * episode, mirroring how the production layers assemble the engine. Each
     * call derives its own Seed from the int, because a Seed's PRNG is consumed
     * by reset().
     */
    private function makeHeatEngine(int $seedValue, ?WorldModel &$world = null): Engine
    {
        $config = new EpisodeConfig();

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
     * regardless of whether they are judged possible, so the engine's whole heat
     * path is exercised the same way in both runs.
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
