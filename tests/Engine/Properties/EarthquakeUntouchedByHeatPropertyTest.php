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
use PoltergeistSwitch\Engine\ReflectionTextBuilder;
use PoltergeistSwitch\Engine\ResidentController;
use PoltergeistSwitch\Engine\RulesEvaluator;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\WorldModel;
use PoltergeistSwitch\Memory\Values\MemorySnapshot;

/**
 * Property-based test for heat-wave spec Requirement 1 (Eris).
 *
 * Property 12 — An earthquake episode is untouched by heat.
 *
 * This is the first heat-wave change after the earthquake golden master
 * (task 1), and it guards the maintainer's first condition (Requirement 1):
 * nothing this spec adds may change how an earthquake behaves, reads, or
 * replays. For any seed, driving an earthquake episode — the default
 * `reset($seed)` path AND an explicit `Disaster::earthquake` reset — through
 * an action sequence that `use`s and `take`s the window, the window air
 * conditioner, the electric fan, and the refrigerator, and hands objects over,
 * the test asserts that:
 *
 *   - no room temperature ever moves off the earthquake initial temperature of
 *     22 °C, because no heat room-drift rule runs in an earthquake (Req 1.6);
 *   - no heat branch was entered — no room's window reads open, since the
 *     window-open mechanism is heat-gated and never fires in an earthquake, and
 *     (together with the unchanged 22 °C temperatures) no heat object EFFECT was
 *     applied. The air-conditioner and electric-fan `on` flags are deliberately
 *     NOT asserted off, because a `use` on either appliance already flips its
 *     `on` flag in an earthquake today (pinned by the golden master); that
 *     existing earthquake behaviour must stay unchanged, and the flags drive no
 *     heat effect in an earthquake (Req 1.6);
 *   - none of the heat-wave resident lines ("I hate the air conditioner.",
 *     "I hate the air conditioner. Turn it off.", "All right. Just for a
 *     while.") is ever emitted (Req 1.6);
 *   - the episode keeps the earthquake `maxTurns` of 40 and the
 *     `house-rules-engine` Requirement 10.6 end rule (the turn cap and the
 *     disaster-subsided stable run), unchanged (Req 1.6, 8.6);
 *   - the stored earthquake situation-text and reflection-text versions are
 *     the committed earthquake strings, byte-for-byte (Req 1.7); and
 *   - no earthquake situation text and no earthquake reflection text contains
 *     the word "thermometer" or "temperature" (Req 1.7).
 *
 * The disaster predicate also confirms the world still reports earthquake and
 * never heat wave on this path, so no heat branch is reachable (Req 1.9's
 * screen gate reads the same `disaster` these assertions pin).
 *
 * The engine is driven directly over the pure Engine (no network, no fakes
 * needed), exactly as the golden master and the other engine property tests
 * do. This Eris release (giorgiosironi/eris ^1.3) sets the iteration count with
 * `limitTo()`; `limitTo(100)` makes the "at least 100 cases" explicit.
 *
 * Validates: Requirements 1.6, 1.7, 1.9, 8.6
 */
class EarthquakeUntouchedByHeatPropertyTest extends TestCase
{
    use TestTrait;

    /** The earthquake initial room temperature (WorldModel::INITIAL_ROOM_TEMPERATURE). */
    private const EARTHQUAKE_ROOM_TEMPERATURE = 22.0;

    /**
     * The exact heat-wave resident lines. None of these may ever be emitted in
     * an earthquake episode (Req 1.6).
     *
     * @var string[]
     */
    private const HEAT_RESIDENT_LINES = [
        'I hate the air conditioner.',
        'I hate the air conditioner. Turn it off.',
        'All right. Just for a while.',
    ];

    /** How many turns each episode is driven (well under maxTurns = 40). */
    private const TURNS = 14;

    /**
     * Property 12: an earthquake episode is untouched by heat.
     *
     * Validates: Requirements 1.6, 1.7, 1.9, 8.6
     */
    public function testEarthquakeEpisodeIsUntouchedByHeat(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generators::int(),
                // Drive both the default reset path (null -> earthquake) and an
                // explicit Disaster::earthquake reset, so the threading added by
                // this task is proven inert on both earthquake entry points.
                Generators::bool(),
            )
            ->then(function (int $seedValue, bool $explicitDisaster): void {
                $config = new EpisodeConfig();

                // maxTurns must still be the earthquake 40 (Req 1.6, 8.6).
                $this->assertSame(
                    40,
                    $config->maxTurns,
                    "The earthquake maxTurns must stay 40 (seed {$seedValue})"
                );

                $textGen = new SituationTextGenerator();
                $engine = $this->makeEngine($seedValue, $config, $textGen, $explicitDisaster, $world);

                // The world runs the earthquake and never heat wave, so no heat
                // branch is reachable (Req 1.6, 1.9).
                $this->assertTrue(
                    $world->disasterIs(Disaster::earthquake),
                    "An earthquake world must report Disaster::earthquake (seed {$seedValue})"
                );
                $this->assertFalse(
                    $world->disasterIs(Disaster::heat_wave),
                    "An earthquake world must never report Disaster::heat_wave (seed {$seedValue})"
                );

                // The stored earthquake text versions are the committed strings
                // (Req 1.7). They are read as the generators expose them and
                // must match the earthquake constants exactly.
                $this->assertSame(
                    'situation-text-2026-11-09b',
                    SituationTextGenerator::VERSION,
                    "The earthquake situation-text version must be unchanged (seed {$seedValue})"
                );
                $this->assertSame(
                    'situation-text-2026-11-09b',
                    $engine->situationTextVersion(),
                    "The engine must report the earthquake situation-text version (seed {$seedValue})"
                );
                $this->assertSame(
                    'reflection-text-2026-11-09d',
                    ReflectionTextBuilder::REFLECTION_TEXT_VERSION,
                    "The earthquake reflection-text version must be unchanged (seed {$seedValue})"
                );

                // Run the earthquake from turn 0 so shaking, power loss, and the
                // scripted resident lines all get their chance to fire.
                $world->earthquakeStartTurn = 0;

                // Preview engine advanced in lockstep so each turn's situation
                // text is captured exactly as a robot reads it.
                $preview = $this->makeEngine($seedValue, $config, $textGen, $explicitDisaster, $previewWorld);
                $previewWorld->earthquakeStartTurn = 0;

                $situationTexts = [];

                for ($turn = 0; $turn < self::TURNS; $turn++) {
                    $actions = $this->heatTargetActions($seedValue, $turn);

                    // Capture this turn's situation text from the preview engine.
                    $preview->prepareTurn($turn);
                    $situationTexts[] = $preview->situationTextFor('robot_a');
                    $situationTexts[] = $preview->situationTextFor('robot_b');
                    $preview->applyTurn($turn, $actions);

                    $result = $engine->applyTurn($turn, $actions);
                    if ($result->isError) {
                        break;
                    }

                    // No room temperature moves off the earthquake value: no
                    // heat drift rule runs in an earthquake (Req 1.6).
                    foreach (array_keys($world->rooms) as $roomKey) {
                        $this->assertSame(
                            self::EARTHQUAKE_ROOM_TEMPERATURE,
                            $world->roomTemperature($roomKey),
                            "Room '{$roomKey}' temperature must stay {$this->describeTemp()} "
                                . "in an earthquake (seed {$seedValue}, turn {$turn})"
                        );
                    }

                    // No heat branch was entered: the heat-only window-open
                    // mechanism (added later, heat-gated) never fires, so no
                    // room's window reads open in an earthquake (Req 1.6). The
                    // air-conditioner and electric-fan `on` flags are NOT checked
                    // here: a `use` on either appliance already flips its `on`
                    // flag in an earthquake today (the golden master pins
                    // "You used a window air conditioner." and "You used an
                    // electric fan."), and that existing earthquake behaviour
                    // must not change. What Requirement 1.6 forbids is a heat
                    // EFFECT — room cooling, body heating — none of which depends
                    // on those flags in an earthquake; the unchanged 22 °C room
                    // temperatures above are the evidence that no heat effect ran.
                    foreach (array_keys($world->rooms) as $roomKey) {
                        $this->assertFalse(
                            $world->windowOpen($roomKey),
                            "No room window may read open in an earthquake "
                                . "(room '{$roomKey}', seed {$seedValue}, turn {$turn})"
                        );
                    }

                    // No heat-wave resident line ever fires (Req 1.6).
                    if ($result->residentLine !== null) {
                        $this->assertNotContains(
                            $result->residentLine,
                            self::HEAT_RESIDENT_LINES,
                            "A heat-wave resident line fired in an earthquake: "
                                . "'{$result->residentLine}' (seed {$seedValue}, turn {$turn})"
                        );
                    }

                    if ($result->episodeEnded) {
                        break;
                    }
                }

                // The episode never ran past the earthquake turn cap (Req 8.6);
                // 14 turns is well under 40, so currentTurn stays below maxTurns.
                $this->assertLessThan(
                    $config->maxTurns,
                    $world->currentTurn,
                    "An earthquake episode must obey the 40-turn cap (seed {$seedValue})"
                );

                // The reflection texts, built the way the reflection flow builds
                // them after a replay, join the situation texts for the
                // no-thermometer / no-temperature scan (Req 1.7).
                $records = $engine->turnRecords();
                $builder = new ReflectionTextBuilder($textGen);
                $empty = new MemorySnapshot([]);
                $reflectionA = $builder->build('robot_a', $records, 0, $world, $empty);
                $reflectionB = $builder->build('robot_b', $records, 0, $world, $empty);

                foreach (array_merge($situationTexts, [$reflectionA, $reflectionB]) as $text) {
                    $this->assertStringNotContainsStringIgnoringCase(
                        'thermometer',
                        $text,
                        "No earthquake text may mention a thermometer (seed {$seedValue})"
                    );
                    $this->assertStringNotContainsStringIgnoringCase(
                        'temperature',
                        $text,
                        "No earthquake text may mention temperature (seed {$seedValue})"
                    );
                }
            });
    }

    /**
     * A fixed per-turn action sequence for both robots that hits every exact
     * target the heat code will later touch — the window, the window air
     * conditioner, the electric fan, and the refrigerator — plus a hand-over.
     * It is a pure function of seed and turn, so the sequence is identical on
     * every execution on any PHP 8.2 build. The actions are issued regardless
     * of whether they are judged possible; in an earthquake they must produce
     * the same judgement and outcome as today and must touch no heat state.
     *
     * @return array<string,Action>
     */
    private function heatTargetActions(int $seedValue, int $turn): array
    {
        return [
            'robot_a' => $this->heatTargetAction('robot_a', $seedValue, $turn),
            'robot_b' => $this->heatTargetAction('robot_b', $seedValue, $turn),
        ];
    }

    private function heatTargetAction(string $robotId, int $seedValue, int $turn): Action
    {
        // A per-robot offset so the two robots never march in lockstep, mixed
        // with the seed so the sequence varies by seed yet stays fixed.
        $offset = $robotId === 'robot_a' ? 0 : 5;
        $i = ($turn + $offset + (($seedValue % 9 + 9) % 9)) % 9;

        switch ($i) {
            case 0:
                // use the window (recognised specially, like the gas-main valve).
                return new Action($robotId, Operation::use->value, targetId: 'window');
            case 1:
                // take the window air conditioner.
                return new Action($robotId, Operation::take->value, targetId: 'window_air_conditioner');
            case 2:
                // use the window air conditioner.
                return new Action($robotId, Operation::use->value, targetId: 'window_air_conditioner');
            case 3:
                // take the electric fan.
                return new Action($robotId, Operation::take->value, targetId: 'electric_fan');
            case 4:
                // use the electric fan.
                return new Action($robotId, Operation::use->value, targetId: 'electric_fan');
            case 5:
                // take the refrigerator.
                return new Action($robotId, Operation::take->value, targetId: 'refrigerator');
            case 6:
                // use the refrigerator.
                return new Action($robotId, Operation::use->value, targetId: 'refrigerator');
            case 7:
                // hand something over to the resident.
                return new Action($robotId, Operation::hand_over->value, targetId: 'resident');
            default:
                // speak to the resident (never a heat-wave line).
                return new Action($robotId, Operation::speak->value, words: ["It's all right, I'm here."]);
        }
    }

    /**
     * Build an Engine over a world freshly reset for $seed as an earthquake
     * episode. When $explicitDisaster is true the reset passes
     * Disaster::earthquake explicitly; otherwise it uses the default null path,
     * so both earthquake entry points are exercised.
     */
    private function makeEngine(
        int $seedValue,
        EpisodeConfig $config,
        SituationTextGenerator $textGen,
        bool $explicitDisaster,
        ?WorldModel &$world = null
    ): Engine {
        $world = new WorldModel($config);

        if ($explicitDisaster) {
            $world->reset(Seed::fromInt($seedValue), null, Disaster::earthquake);
        } else {
            $world->reset(Seed::fromInt($seedValue));
        }

        return new Engine(
            $world,
            $config,
            new RulesEvaluator(),
            new ActionExecutor(),
            new EarthquakePhase(),
            new ResidentController(),
            $textGen,
        );
    }

    private function describeTemp(): string
    {
        return number_format(self::EARTHQUAKE_ROOM_TEMPERATURE, 1) . ' °C';
    }
}
