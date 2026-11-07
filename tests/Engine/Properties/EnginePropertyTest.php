<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\ActionExecutor;
use PoltergeistSwitch\Engine\EarthquakePhase;
use PoltergeistSwitch\Engine\Engine;
use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\ResidentController;
use PoltergeistSwitch\Engine\RulesEvaluator;
use PoltergeistSwitch\Engine\Seed;
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
    private function buildEngine(Seed $seed): Engine
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
