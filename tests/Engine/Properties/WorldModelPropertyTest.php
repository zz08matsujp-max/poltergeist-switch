<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\EarthquakePhase;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\ResidentController;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\Values\TurnActions;
use PoltergeistSwitch\Engine\WorldModel;

/**
 * Property-based tests for WorldModel (Eris).
 *
 * Property 2 — One-room invariant (Req 2.1–2.2, Req 11.1):
 *   For any seed, after reset() every ObjectState lives in exactly one room.
 *   Cross-check: summing the per-room object counts equals the total object
 *   count, which can only hold when no object is double-counted or missing.
 *
 * Property 3 — Resident state range (Req 6.8, Req 11.2, Req 11.3):
 *   For any seed, after any sequence of turn applications (up to 13 turns with
 *   no robot actions) every ResidentState numeric field stays within its
 *   configured clamped range. The earthquake phase piles on injury, fear and
 *   darkness deltas while no speech ever pulls fear back down, so this
 *   exercises the controller's clamping under sustained upward pressure.
 *
 * NOTE: this Eris release (giorgiosironi/eris ^1.3) controls the number of
 * iterations with `limitTo()`; the default is already 100, and limitTo(100)
 * makes the intended "at least 100 cases" explicit. (`withMinSuccesses()` is
 * from a different Eris API and is not available here.)
 */
class WorldModelPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property 1: Reset reproducibility.
     *
     * For any seed, calling reset() twice on independent WorldModel instances
     * must leave them in byte-for-byte identical observable state: the same
     * situation layout, the same objects in the same rooms with the same flags,
     * the same robots, the same resident, the same house flags, and the same
     * per-room light/temperature/hazard state. This is the determinism
     * guarantee the whole replay/compare feature rests on (Req 1.11, Req 8.5).
     *
     * Validates: Requirements 1.11, 8.5
     */
    public function testResetWithTheSameSeedIsReproducible(): void
    {
        $this->limitTo(100)
            ->forAll(Generators::int())
            ->then(function (int $seedValue): void {
                $worldA = new WorldModel();
                $worldA->reset(Seed::fromInt($seedValue));

                $worldB = new WorldModel();
                $worldB->reset(Seed::fromInt($seedValue));

                $this->assertSame(
                    $this->describeWorld($worldA),
                    $this->describeWorld($worldB),
                    "reset() with seed {$seedValue} must be reproducible"
                );
            });
    }

    /**
     * Property 10: floor_water_level never negative.
     *
     * For any seed, driving the earthquake phase across offsets 1–13 with no
     * robot actions must never leave the house's floor water level below zero
     * after any turn. A water level is a physical quantity that can only ever
     * be zero or positive (Req 11.2).
     *
     * Validates: Requirements 11.2
     */
    public function testFloorWaterLevelStaysNonNegative(): void
    {
        $this->limitTo(100)
            ->forAll(Generators::int())
            ->then(function (int $seedValue): void {
                $seed = Seed::fromInt($seedValue);

                $world = new WorldModel();
                $world->reset($seed);

                $phase = new EarthquakePhase();

                for ($offset = 1; $offset <= 13; $offset++) {
                    $phase->advanceTurn($world, $seed, $offset);

                    $this->assertGreaterThanOrEqual(
                        0.0,
                        $world->houseState->floorWaterLevel,
                        "floorWaterLevel must stay >= 0 at offset {$offset} (seed {$seedValue})"
                    );
                }
            });
    }

    /**
     * Property 2: One-room invariant.
     *
     * Validates: Requirements 2.1, 2.2
     */
    public function testEveryObjectAppearsInExactlyOneRoom(): void
    {
        $this->limitTo(100)
            ->forAll(Generators::int())
            ->then(function (int $seedValue): void {
                $world = new WorldModel();
                $world->reset(Seed::fromInt($seedValue));

                $totalObjects = count($world->objects);

                // Tally how many objects report each room key.
                $perRoom = [];
                foreach ($world->objects as $object) {
                    $perRoom[$object->room] = ($perRoom[$object->room] ?? 0) + 1;
                }

                // Every room an object claims must be a real room in the world.
                foreach (array_keys($perRoom) as $roomKey) {
                    $this->assertArrayHasKey(
                        $roomKey,
                        $world->rooms,
                        "Object placed in unknown room '{$roomKey}' (seed {$seedValue})"
                    );
                }

                // The per-room counts must sum back to the total object count.
                // If any object were in two rooms or no room, this would differ.
                $this->assertSame(
                    $totalObjects,
                    array_sum($perRoom),
                    "Per-room object counts must sum to the total (seed {$seedValue})"
                );
            });
    }

    /**
     * Property 3: Resident state range.
     *
     * For any seed, driving the earthquake phase and the resident controller
     * across offsets 1–13 with no robot actions must never push any
     * ResidentState field outside its configured range, after every turn.
     *
     * Validates: Requirements 6.8, 11.2, 11.3
     */
    public function testResidentStateStaysWithinClampedRanges(): void
    {
        $this->limitTo(100)
            ->forAll(Generators::int())
            ->then(function (int $seedValue): void {
                $seed = Seed::fromInt($seedValue);

                $world = new WorldModel();
                $world->reset($seed);

                $config = new EpisodeConfig();
                $controller = new ResidentController();
                $phase = new EarthquakePhase();

                // No robot actions this run: no robot speaks and no robot
                // covers the resident, so fear only ever climbs.
                $noActions = new TurnActions([], [], false);

                for ($offset = 1; $offset <= 13; $offset++) {
                    $phase->advanceTurn($world, $seed, $offset);
                    $controller->updateState($world, $config, $noActions);

                    $resident = $world->residentState;

                    $this->assertGreaterThanOrEqual(
                        $config->bodyTempMin,
                        $resident->bodyTemp,
                        "bodyTemp below min at offset {$offset} (seed {$seedValue})"
                    );
                    $this->assertLessThanOrEqual(
                        $config->bodyTempMax,
                        $resident->bodyTemp,
                        "bodyTemp above max at offset {$offset} (seed {$seedValue})"
                    );

                    $this->assertGreaterThanOrEqual(
                        $config->waterMin,
                        $resident->water,
                        "water below min at offset {$offset} (seed {$seedValue})"
                    );
                    $this->assertLessThanOrEqual(
                        $config->waterMax,
                        $resident->water,
                        "water above max at offset {$offset} (seed {$seedValue})"
                    );

                    $this->assertGreaterThanOrEqual(
                        $config->injuryMin,
                        $resident->injury,
                        "injury below min at offset {$offset} (seed {$seedValue})"
                    );
                    $this->assertLessThanOrEqual(
                        $config->injuryMax,
                        $resident->injury,
                        "injury above max at offset {$offset} (seed {$seedValue})"
                    );

                    $this->assertGreaterThanOrEqual(
                        $config->fearMin,
                        $resident->fear,
                        "fear below min at offset {$offset} (seed {$seedValue})"
                    );
                    $this->assertLessThanOrEqual(
                        $config->fearMax,
                        $resident->fear,
                        "fear above max at offset {$offset} (seed {$seedValue})"
                    );
                }
            });
    }

    /**
     * Property 5: Earthquake determinism.
     *
     * For any seed, running the earthquake phase (offsets 1–13) with no robot
     * actions produces the identical set of fallen objects at each offset every
     * time. Two independent WorldModel instances reset with the same seed are
     * driven through the phase, and the fallen-id sets per offset must match.
     *
     * Validates: Requirements 3.4, 3.8
     */
    public function testEarthquakeFallSequenceIsDeterministic(): void
    {
        $this->limitTo(100)
            ->forAll(Generators::int())
            ->then(function (int $seedValue): void {
                $fallenA = $this->runEarthquakeFalls($seedValue);
                $fallenB = $this->runEarthquakeFalls($seedValue);

                $this->assertSame(
                    $fallenA,
                    $fallenB,
                    "Earthquake fall sequence must be identical for seed {$seedValue}"
                );
            });
    }

    /**
     * Drive a fresh world through the whole earthquake phase and return, per
     * offset 1–13, the sorted list of object ids that fell on that turn. The
     * fall selection is the only randomised step; no robot actions are applied.
     *
     * @return array<int,string[]> offset => sorted fallen object ids
     */
    private function runEarthquakeFalls(int $seedValue): array
    {
        $seed = Seed::fromInt($seedValue);
        $world = new WorldModel();
        $world->reset($seed);

        $phase = new EarthquakePhase();

        $perOffset = [];
        for ($offset = 1; $offset <= 13; $offset++) {
            $before = $this->fallenIds($world);
            $phase->advanceTurn($world, $seed, $offset);
            $after = $this->fallenIds($world);

            // Objects newly marked fallen on this offset.
            $new = array_values(array_diff($after, $before));
            sort($new);
            $perOffset[$offset] = $new;
        }

        return $perOffset;
    }

    /**
     * Reduce a whole WorldModel to a plain, order-stable array of every
     * observable field so two resets can be compared with assertSame. This
     * covers more than snapshot() does: it also includes the situation layout
     * and the per-room light/temperature/hazard state, which reset() sets but
     * which snapshot() does not carry. Object and robot keys are sorted so the
     * comparison does not depend on iteration order.
     *
     * @return array<string,mixed>
     */
    private function describeWorld(WorldModel $world): array
    {
        $objects = [];
        foreach ($world->objects as $id => $object) {
            $objects[$id] = [
                'displayName' => $object->displayName,
                'room' => $object->room,
                'spot' => $object->spot,
                'on' => $object->on,
                'open' => $object->open,
                'contents' => $object->contents,
                'broken' => $object->broken,
                'fallen' => $object->fallen,
            ];
        }
        ksort($objects);

        $robots = [];
        foreach ($world->robotStates as $id => $robot) {
            $robots[$id] = [
                'room' => $robot->room,
                'spot' => $robot->spot,
                'heldObjectId' => $robot->heldObjectId,
                'coverFlag' => $robot->coverFlag,
                'supportTurnsRemaining' => $robot->supportTurnsRemaining,
            ];
        }
        ksort($robots);

        $rooms = [];
        foreach ($world->rooms as $key => $room) {
            $rooms[$key] = [
                'lightLevel' => $room->lightLevel->value,
                'temperature' => $room->temperature,
                'hotWaterInjuryHazard' => $room->hotWaterInjuryHazard,
            ];
        }
        ksort($rooms);

        $resident = $world->residentState;
        $house = $world->houseState;
        $spec = $world->situationSpec;

        return [
            'situation' => [
                'time' => $spec->time,
                'robotARoom' => $spec->robotARoom,
                'robotBRoom' => $spec->robotBRoom,
                'manActivity' => $spec->manActivity,
                'potBoiling' => $spec->potBoiling,
            ],
            'objects' => $objects,
            'robots' => $robots,
            'rooms' => $rooms,
            'resident' => [
                'bodyTemp' => $resident->bodyTemp,
                'water' => $resident->water,
                'injury' => $resident->injury,
                'fear' => $resident->fear,
                'weakened' => $resident->weakened,
                'room' => $resident->room,
                'spot' => $resident->spot,
                'activity' => $resident->activity,
                'coverFlag' => $resident->coverFlag,
                'lastLineDeliveredOn' => $resident->lastLineDeliveredOn,
            ],
            'house' => [
                'power' => $house->power->value,
                'gasMain' => $house->gasMain->value,
                'floorWaterLevel' => $house->floorWaterLevel,
                'shaking' => $house->shaking,
                'fallingObjects' => $house->fallingObjects,
                'timeOfDay' => $house->timeOfDay,
            ],
            'counters' => [
                'currentTurn' => $world->currentTurn,
                'earthquakeStartTurn' => $world->earthquakeStartTurn,
                'episodeEnded' => $world->episodeEnded,
                'disasterSubsided' => $world->disasterSubsided,
            ],
        ];
    }

    /**
     * Sorted ids of every object currently flagged fallen.
     *
     * @return string[]
     */
    private function fallenIds(WorldModel $world): array
    {
        $ids = [];
        foreach ($world->objects as $object) {
            if ($object->fallen) {
                $ids[] = $object->id;
            }
        }
        sort($ids);

        return $ids;
    }
}
