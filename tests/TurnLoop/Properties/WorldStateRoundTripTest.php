<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\Enums\LightLevel;
use PoltergeistSwitch\Engine\Enums\ManActivity;
use PoltergeistSwitch\Engine\Enums\PowerState;
use PoltergeistSwitch\Engine\Enums\TimeOfDay;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\WorldModel;
use PoltergeistSwitch\TurnLoop\WorldStateSerializer;

/**
 * Property-based tests for WorldStateSerializer (Eris).
 *
 * Feature: screens, Property 1: World_State round-trip
 *
 * This Eris release (giorgiosironi/eris ^1.3) controls the number of
 * iterations with `limitTo()`, not `withMinSuccesses()`.
 */
class WorldStateRoundTripTest extends TestCase
{
    use TestTrait;

    /**
     * Feature: screens, Property 1: World_State round-trip.
     *
     * For any valid WorldModel, serialising to the World_State array and to
     * JSON, then reading the JSON back, must yield the exact same array (no
     * field added, dropped, or altered), and every Requirement-10.3 field in
     * the array must equal the value actually set on the world. This proves
     * the mapper is a faithful, lossless round-trip.
     *
     * The world is built with reset(Seed) for a valid baseline, then
     * deterministically mutated from a second seed so random choices cover the
     * Requirement-10.3 fields that reset alone may not vary (robot positions,
     * man state, power, shaking, time of day, per-room light, broken/fallen
     * sets, stove). Every mutation uses valid enum values.
     *
     * Validates: Requirements 10.6, 10.3
     */
    public function testWorldStateRoundTrips(): void
    {
        $this->limitTo(100)
            ->forAll(Generators::int(), Generators::int())
            ->then(function (int $baseSeed, int $mutSeed): void {
                $world = new WorldModel();
                $world->reset(Seed::fromInt($baseSeed));

                // Deterministic mutation driven by its own seed so the same
                // (baseSeed, mutSeed) pair always produces the same world.
                $this->mutateWorld($world, Seed::fromInt($mutSeed));

                $serializer = new WorldStateSerializer();
                $array = $serializer->toArray($world);
                $json = $serializer->toJson($world);
                $readBack = json_decode($json, true);

                // The JSON round-trips verbatim: no field added, dropped, or
                // altered.
                $this->assertSame(
                    $array,
                    $readBack,
                    "World_State JSON must round-trip to the same array "
                    . "(base {$baseSeed}, mut {$mutSeed})"
                );

                // Every Requirement-10.3 field equals the value set on the
                // world, so the mapper neither adds, drops, nor alters a field.
                $this->assertRequirement103Fields($array, $world, $baseSeed, $mutSeed);
            });
    }

    /**
     * Deterministically mutate a valid world so random choices exercise every
     * Requirement-10.3 field. Only valid enum values and plausible ranges are
     * used; all draws come from the given Seed so the mutation is reproducible.
     */
    private function mutateWorld(WorldModel $world, Seed $seed): void
    {
        $roomKeys = array_keys($world->rooms);
        $roomCount = count($roomKeys);
        $objectIds = array_keys($world->objects);
        $spotChoices = [null, 'window', 'gas_stove', 'under_table', 'beside_futon'];
        $timeChoices = [
            TimeOfDay::morning->value,
            TimeOfDay::afternoon->value,
            TimeOfDay::night->value,
        ];
        $activityChoices = [
            ManActivity::sitting_at_table->value,
            ManActivity::asleep_in_futon->value,
            ManActivity::bathing->value,
            ManActivity::cooking->value,
            ManActivity::standing->value,
        ];

        // House: power, shaking, time of day.
        $world->houseState->power = $seed->next(0, 1) === 1
            ? PowerState::outage
            : PowerState::on;
        $world->houseState->shaking = $seed->next(0, 1) === 1;
        $world->houseState->timeOfDay = $timeChoices[$seed->next(0, count($timeChoices) - 1)];

        // Robots: room, spot, held object.
        foreach (['robot_a', 'robot_b'] as $robotId) {
            $robot = $world->robotStates[$robotId];
            $robot->room = $roomKeys[$seed->next(0, $roomCount - 1)];
            $robot->spot = $spotChoices[$seed->next(0, count($spotChoices) - 1)];
            // Either empty-handed or holding one of the world's objects.
            $robot->heldObjectId = $seed->next(0, 1) === 1
                ? $objectIds[$seed->next(0, count($objectIds) - 1)]
                : null;
        }

        // Man: room, spot, activity, and numeric/bool state.
        $man = $world->residentState;
        $man->room = $roomKeys[$seed->next(0, $roomCount - 1)];
        $man->spot = $spotChoices[$seed->next(0, count($spotChoices) - 1)];
        $man->activity = $activityChoices[$seed->next(0, count($activityChoices) - 1)];
        // bodyTemp in tenths of a degree so a float value is exercised.
        $man->bodyTemp = $seed->next(340, 420) / 10.0;
        $man->water = $seed->next(0, 100);
        $man->injury = $seed->next(0, 100);
        $man->fear = $seed->next(0, 100);
        $man->weakened = $seed->next(0, 1) === 1;
        $man->coverFlag = $seed->next(0, 1) === 1;

        // Per-room light level.
        foreach ($world->rooms as $room) {
            $room->lightLevel = $seed->next(0, 1) === 1
                ? LightLevel::dark
                : LightLevel::normal;
        }

        // A random subset of objects' broken / fallen flags.
        foreach ($world->objects as $object) {
            $object->broken = $seed->next(0, 1) === 1;
            $object->fallen = $seed->next(0, 1) === 1;
        }

        // Stove on flag.
        $world->objects['gas_stove']->on = $seed->next(0, 1) === 1;
    }

    /**
     * Assert that every Requirement-10.3 field in the serialised array equals
     * the value actually set on the world.
     *
     * @param array<string,mixed> $array
     */
    private function assertRequirement103Fields(
        array $array,
        WorldModel $world,
        int $baseSeed,
        int $mutSeed
    ): void {
        $ctx = "(base {$baseSeed}, mut {$mutSeed})";

        // House-wide fields.
        $this->assertSame($world->houseState->timeOfDay, $array['timeOfDay'], "timeOfDay {$ctx}");
        $this->assertSame($world->houseState->power->value, $array['power'], "power {$ctx}");
        $this->assertSame($world->houseState->shaking, $array['shaking'], "shaking {$ctx}");

        // Robots.
        foreach (['robot_a', 'robot_b'] as $robotId) {
            $robot = $world->robotStates[$robotId];
            $this->assertSame($robot->room, $array['robots'][$robotId]['room'], "{$robotId} room {$ctx}");
            $this->assertSame($robot->spot, $array['robots'][$robotId]['spot'], "{$robotId} spot {$ctx}");
            $this->assertSame($robot->heldObjectId, $array['robots'][$robotId]['held'], "{$robotId} held {$ctx}");
        }

        // Man.
        $man = $world->residentState;
        $this->assertSame($man->room, $array['man']['room'], "man room {$ctx}");
        $this->assertSame($man->spot, $array['man']['spot'], "man spot {$ctx}");
        $this->assertSame($man->activity, $array['man']['activity'], "man activity {$ctx}");
        $this->assertSame($man->bodyTemp, $array['man']['bodyTemp'], "man bodyTemp {$ctx}");
        $this->assertSame($man->water, $array['man']['water'], "man water {$ctx}");
        $this->assertSame($man->injury, $array['man']['injury'], "man injury {$ctx}");
        $this->assertSame($man->fear, $array['man']['fear'], "man fear {$ctx}");
        $this->assertSame($man->weakened, $array['man']['weakened'], "man weakened {$ctx}");
        $this->assertSame($man->coverFlag, $array['man']['covered'], "man covered {$ctx}");

        // Per-room light.
        foreach ($world->rooms as $key => $room) {
            $this->assertSame(
                $room->lightLevel->value,
                $array['rooms'][$key]['light'],
                "room {$key} light {$ctx}"
            );
        }

        // Broken / fallen id sets, in the fixed room (registration) order.
        $expectedBroken = [];
        $expectedFallen = [];
        foreach ($world->objects as $object) {
            if ($object->broken) {
                $expectedBroken[] = $object->id;
            }
            if ($object->fallen) {
                $expectedFallen[] = $object->id;
            }
        }
        $this->assertSame($expectedBroken, $array['objects']['broken'], "broken ids {$ctx}");
        $this->assertSame($expectedFallen, $array['objects']['fallen'], "fallen ids {$ctx}");

        // Stove.
        $this->assertSame($world->objects['gas_stove']->on, $array['stove']['on'], "stove on {$ctx}");
    }
}
