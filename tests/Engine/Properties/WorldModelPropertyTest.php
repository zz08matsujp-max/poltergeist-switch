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
