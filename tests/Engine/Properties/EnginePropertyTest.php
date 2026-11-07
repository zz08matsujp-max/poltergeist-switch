<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\ActionExecutor;
use PoltergeistSwitch\Engine\EarthquakePhase;
use PoltergeistSwitch\Engine\Engine;
use PoltergeistSwitch\Engine\Enums\ManActivity;
use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\ResidentController;
use PoltergeistSwitch\Engine\RulesEvaluator;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\SituationSpec;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\Values\TurnResult;
use PoltergeistSwitch\Engine\WorldModel;

/**
 * Property-based tests for the Engine (Eris).
 *
 * Property 4 — applyTurn idempotency (Req 10.2–10.3, Req 11.4):
 *   The current turn is processed exactly once. Calling applyTurn(n, actions)
 *   a second time with the same arguments must return the identical stored
 *   TurnResult and must not mutate the world again. We check this by applying
 *   the current turn, snapshotting the whole world, re-applying the very same
 *   call, and asserting that both TurnResult values are equal by value and that
 *   the world snapshot is unchanged between the two calls.
 *
 * NOTE: this Eris release (giorgiosironi/eris ^1.3) controls the number of
 * iterations with `limitTo()`; `limitTo(100)` makes the intended "at least 100
 * cases" explicit, matching WorldModelPropertyTest.
 */
class EnginePropertyTest extends TestCase
{
    use TestTrait;

    /** The resident's earthquake-start shout, exempt from the cooldown (Req 7.1). */
    private const EARTHQUAKE_LINE = "What's happening?!";

    /**
     * Property 8: cooking always means the pot is boiling.
     *
     * For any starting layout whose manActivity is `cooking`, the resolved
     * SituationSpec and the reset world must both report a boiling pot. The
     * SituationSpec constructor forces potBoiling=true when cooking regardless
     * of the flag passed in, and reset() lights the stove and places the pot
     * accordingly (Req 8.2).
     *
     * The three free fields (time and the two robot rooms) are drawn by Eris;
     * the bathroom is allowed because the activity is cooking, not bathing.
     *
     * Validates: Requirements 8.2
     */
    public function testCookingAlwaysForcesPotBoiling(): void
    {
        $rooms = ['living_room', 'kitchen', 'bathroom', 'bedroom', 'entrance', 'storeroom'];
        $times = ['morning', 'afternoon', 'night'];

        $this->limitTo(100)
            ->forAll(
                Generators::elements($times),
                Generators::elements($rooms),
                Generators::elements($rooms),
                // The incoming potBoiling flag must be ignored for cooking, so
                // draw it freely to prove it is overridden either way.
                Generators::bool(),
            )
            ->then(function (string $time, string $roomA, string $roomB, bool $flag): void {
                $spec = new SituationSpec($time, $roomA, $roomB, ManActivity::cooking->value, $flag);

                $this->assertTrue(
                    $spec->potBoiling,
                    "cooking must force SituationSpec.potBoiling=true (incoming flag "
                        . var_export($flag, true) . ")"
                );

                $world = new WorldModel();
                $world->reset(Seed::fromInt(0), $spec);

                $this->assertTrue(
                    $world->situationSpec->potBoiling,
                    'reset world must keep potBoiling=true for cooking'
                );
                $this->assertTrue(
                    $world->isPotBoiling(),
                    'reset world must report a boiling pot for cooking'
                );
            });
    }

    /**
     * Property 9: scripted line cooldown.
     *
     * For any seed and any run length of 1–40 turns driven by an active
     * earthquake, no scripted resident line other than the earthquake-start
     * shout may appear more than once within any window of `lineCooldownTurns`
     * consecutive turns (Req 7.6, Req 7.7). The earthquake line is exempt
     * because it fires exactly once, on the start turn, ignoring the cooldown
     * (Req 7.1).
     *
     * The earthquake starts on turn 0 (earthquakeStartTurn set before the first
     * applyTurn), so the fear/water/darkness lines actually get a chance to
     * fire as shaking, darkness and thirst build. Each turn's residentLine is
     * collected, then every window of `lineCooldownTurns` consecutive turns is
     * checked for a repeated non-earthquake line.
     *
     * Validates: Requirements 7.6, 7.7
     */
    public function testScriptedLinesObeyCooldownWithinAnyWindow(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generators::int(),
                Generators::choose(1, 40),
            )
            ->then(function (int $seedValue, int $turnCount): void {
                $seed = Seed::fromInt($seedValue);

                $engine = $this->buildEngine($seed, $world);

                // Trigger the earthquake from turn 0 so scripted lines can fire.
                $world->earthquakeStartTurn = 0;

                $config = new EpisodeConfig();
                $cooldown = $config->lineCooldownTurns;

                // Collect each turn's line (or null) in turn order.
                $lines = [];
                for ($turn = 0; $turn < $turnCount; $turn++) {
                    $result = $engine->applyTurn($turn, []);

                    // Stop collecting once the episode ends: no further turns
                    // are processed, so trailing turns carry no new lines.
                    if ($result->isError) {
                        break;
                    }

                    $lines[$turn] = $result->residentLine;

                    if ($result->episodeEnded) {
                        break;
                    }
                }

                // For every window of `cooldown` consecutive turns, each
                // non-earthquake line may appear at most once.
                $maxTurn = $turnCount - 1;
                for ($start = 0; $start <= $maxTurn; $start++) {
                    $seen = [];
                    for ($t = $start; $t < $start + $cooldown && $t <= $maxTurn; $t++) {
                        $line = $lines[$t] ?? null;
                        if ($line === null || $line === self::EARTHQUAKE_LINE) {
                            continue;
                        }

                        $this->assertArrayNotHasKey(
                            $line,
                            $seen,
                            "Line '{$line}' repeated within a {$cooldown}-turn window "
                                . "starting at turn {$start} (seed {$seedValue}, turnCount {$turnCount})"
                        );
                        $seen[$line] = true;
                    }
                }
            });
    }

    /**
     * Property 4: applyTurn idempotency.
     *
     * Validates: Requirements 10.2, 10.3, 11.4
     */
    public function testApplyTurnIsIdempotentForTheCurrentTurn(): void
    {
        $this->limitTo(100)
            ->forAll(Generators::int())
            ->then(function (int $seedValue): void {
                $seed = Seed::fromInt($seedValue);

                $engine = $this->buildEngine($seed);

                // The world starts at turn 0; that is the only "current" turn
                // the engine will process. Keep the action set deterministic so
                // the whole case is reproducible from the seed alone.
                $turnNumber = 0;
                $actions = $this->actionsFromSeed($seedValue);

                // First application: process the current turn and record the
                // result plus the full world state right after.
                $firstResult = $engine->applyTurn($turnNumber, $actions);

                // The first call must have actually processed the turn (not an
                // error result), otherwise the idempotency check is vacuous.
                $this->assertFalse(
                    $firstResult->isError,
                    "First applyTurn($turnNumber) must succeed (seed {$seedValue}): "
                        . ($firstResult->errorMessage ?? '')
                );

                $snapshotAfterFirst = $this->describeSnapshot($firstResult->worldSnapshot);

                // Second application with the same arguments: must return the
                // stored result and must not touch the world again.
                $secondResult = $engine->applyTurn($turnNumber, $actions);

                $snapshotAfterSecond = $this->describeSnapshot($secondResult->worldSnapshot);

                // The two TurnResult values must be equal by value. They hold
                // readonly value objects and cloned mutable state, so a deep
                // value comparison is the right equality here.
                $this->assertEquals(
                    $firstResult,
                    $secondResult,
                    "Re-applying the current turn must return an equal TurnResult (seed {$seedValue})"
                );

                // The world snapshot must be unchanged between the two calls:
                // the repeat call returns the stored result without re-running
                // any mutation.
                $this->assertEquals(
                    $snapshotAfterFirst,
                    $snapshotAfterSecond,
                    "Re-applying the current turn must not mutate the world (seed {$seedValue})"
                );
            });
    }

    /**
     * Construct a fully wired Engine with its WorldModel reset to $seed, mirroring
     * how the production layers assemble the engine (design §d). All of the
     * collaborators are no-argument plain classes.
     */
    private function buildEngine(Seed $seed, ?WorldModel &$world = null): Engine
    {
        $config = new EpisodeConfig();

        $world = new WorldModel($config);
        $world->reset($seed);

        return new Engine(
            $world,
            $config,
            new RulesEvaluator(),
            new ActionExecutor(),
            new EarthquakePhase(),
            new ResidentController(),
            new SituationTextGenerator(),
        );
    }

    /**
     * Derive a deterministic, always-valid set of actions from the seed so each
     * case is reproducible. The choice alternates between no actions at all and
     * a pair of simple operations that carry no blocking pre-conditions
     * (`wait` and `speak`), so the turn is processed the same way on both calls
     * regardless of the drawn start-up layout.
     *
     * @return array<string,Action>
     */
    private function actionsFromSeed(int $seedValue): array
    {
        // Three reproducible shapes keyed off the seed: empty, a lone wait, and
        // a wait paired with a spoken line. All are valid for any layout.
        switch (($seedValue % 3 + 3) % 3) {
            case 0:
                return [];

            case 1:
                return [
                    'robot_a' => new Action(
                        robotId: 'robot_a',
                        operation: Operation::wait->value,
                        actionQuote: 'I wait.',
                    ),
                ];

            default:
                return [
                    'robot_a' => new Action(
                        robotId: 'robot_a',
                        operation: Operation::wait->value,
                        actionQuote: 'I wait.',
                    ),
                    'robot_b' => new Action(
                        robotId: 'robot_b',
                        operation: Operation::speak->value,
                        words: ['Hello.'],
                        actionQuote: 'I say hello.',
                    ),
                ];
        }
    }

    /**
     * Reduce a WorldSnapshot to a plain, comparable array of its observable
     * fields. The snapshot holds cloned objects; turning them into scalars lets
     * assertEquals compare the two snapshots field-by-field without depending on
     * object identity.
     *
     * @param mixed $snapshot WorldSnapshot
     * @return array<string,mixed>
     */
    private function describeSnapshot(mixed $snapshot): array
    {
        $objects = [];
        foreach ($snapshot->objects as $id => $object) {
            $objects[$id] = [
                'room' => $object->room,
                'spot' => $object->spot,
                'on' => $object->on,
                'open' => $object->open,
                'broken' => $object->broken,
                'fallen' => $object->fallen,
            ];
        }

        $robots = [];
        foreach ($snapshot->robotStates as $id => $robot) {
            $robots[$id] = [
                'room' => $robot->room,
                'spot' => $robot->spot,
                'heldObjectId' => $robot->heldObjectId,
                'coverFlag' => $robot->coverFlag,
                'supportTurnsRemaining' => $robot->supportTurnsRemaining,
            ];
        }

        $resident = $snapshot->residentState;
        $house = $snapshot->houseState;

        return [
            'objects' => $objects,
            'robots' => $robots,
            'resident' => [
                'bodyTemp' => $resident->bodyTemp,
                'water' => $resident->water,
                'injury' => $resident->injury,
                'fear' => $resident->fear,
                'weakened' => $resident->weakened,
                'room' => $resident->room,
                'spot' => $resident->spot,
                'coverFlag' => $resident->coverFlag,
            ],
            'house' => [
                'power' => $house->power,
                'gasMain' => $house->gasMain,
                'floorWaterLevel' => $house->floorWaterLevel,
                'shaking' => $house->shaking,
                'fallingObjects' => $house->fallingObjects,
                'timeOfDay' => $house->timeOfDay,
            ],
        ];
    }
}
