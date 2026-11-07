<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\WorldModel;

/**
 * Property-based tests for WorldModel (Eris).
 *
 * Property 2 — One-room invariant (Req 2.1–2.2, Req 11.1):
 *   For any seed, after reset() every ObjectState lives in exactly one room.
 *   Cross-check: summing the per-room object counts equals the total object
 *   count, which can only hold when no object is double-counted or missing.
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
}
