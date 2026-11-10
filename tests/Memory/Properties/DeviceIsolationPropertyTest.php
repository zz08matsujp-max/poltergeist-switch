<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Memory\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Memory\MemoryService;
use PoltergeistSwitch\Memory\Values\MemoryRow;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;

/**
 * Feature: public-lab, Property 1: Memory ownership and isolation
 *
 * For any set of Devices and any interleaving of create (snapshot), finish-and-
 * reflect (store a memory) and reset operations, each Device's Memory_Snapshot,
 * status (counts and lengths) and reflection activeCount are computed only from
 * memories owned by that Device, are identical to what the same Device would
 * see if it were the only Device, and never include a memory with no owner.
 *
 * The property runs against the in-memory EpisodeRepository fake. A random
 * operation list is applied to one shared store (with Devices and Legacy rows
 * mixed in), then each Device's operations alone are replayed on a fresh store.
 * Every body is tagged with its owner, so a leaked or ownerless memory is
 * visible directly.
 *
 * Validates: Requirements 2.1, 2.2, 2.5, 2.6, 2.12, 3.1, 3.5, 5.2
 */
class DeviceIsolationPropertyTest extends TestCase
{
    use TestTrait;

    private const DEVICES = ['dev_a', 'dev_b', 'dev_c'];

    private const ROBOTS = ['robot_a', 'robot_b'];

    private const DISASTERS = ['earthquake', 'heavy_rain', 'heat_wave'];

    /** Operation kinds: 0-2 store a memory, 3 reset, 4 snapshot, 5 Legacy_Memory. */
    private const KIND_MAX = 5;

    /**
     * Feature: public-lab, Property 1: Memory ownership and isolation
     *
     * Validates: Requirements 2.1, 2.2, 2.5, 2.6, 2.12, 3.1, 3.5, 5.2
     */
    public function testEachDeviceSeesOnlyItsOwnMemoriesAsIfAlone(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generators::seq(
                    Generators::tuple(
                        Generators::choose(0, self::KIND_MAX),
                        Generators::choose(0, count(self::DEVICES) - 1),
                        Generators::choose(0, 1),
                        Generators::choose(0, count(self::DISASTERS) - 1),
                    )
                )
            )
            ->then(function (array $ops): void {
                $shared = new InMemoryEpisodeRepository();
                $this->apply($shared, $ops, null);

                foreach (self::DEVICES as $device) {
                    $alone = new InMemoryEpisodeRepository();
                    $this->apply($alone, $ops, $device);

                    $this->assertSame(
                        $this->view($alone, $ops, $device),
                        $this->view($shared, $ops, $device),
                        "{$device} must see what it would see alone"
                    );

                    // Only this Device's own bodies, never another Device's or a Legacy row.
                    $generation = $shared->deviceGeneration($device);
                    foreach (self::ROBOTS as $robot) {
                        foreach ($shared->activeMemories($device, $robot, $generation, PHP_INT_MAX)->bodies as $body) {
                            $this->assertStringStartsWith("{$device}:", $body);
                        }
                    }
                }

                // A Device that did nothing sees nothing, whatever the others stored.
                $this->assertSame(0, $shared->activeCount('dev_none', 'robot_a', 1, 'earthquake'));
                $this->assertSame([], $shared->activeMemories('dev_none', 'robot_a', 1, 18)->bodies);
                $status = (new MemoryService($shared))->status('dev_none');
                $this->assertSame(0, $status->amberActiveCount + $status->jadeActiveCount);
            });
    }

    /**
     * Apply the operations in order. With $only set, operations of other
     * Devices and Legacy rows are skipped (the "only Device" run).
     *
     * @param list<array{int,int,int,int}> $ops
     */
    private function apply(InMemoryEpisodeRepository $repo, array $ops, ?string $only): void
    {
        $service = new MemoryService($repo);

        foreach ($ops as $i => [$kind, $d, $r, $z]) {
            $device = self::DEVICES[$d];
            $isLegacy = $kind === 5;
            if ($only !== null && ($isLegacy || $device !== $only)) {
                continue;
            }

            if ($kind === 3) {
                $service->reset($device);
            } elseif ($kind === 4) {
                $service->snapshotForNewEpisode("snap_{$i}", $device);
            } else {
                $owner = $isLegacy ? null : $device;
                $repo->appendMemory(new MemoryRow(
                    robotId: self::ROBOTS[$r],
                    generation: $isLegacy ? 1 : $repo->deviceGeneration($device),
                    disaster: self::DISASTERS[$z],
                    sourceEpisodeId: "src_{$i}",
                    body: ($owner ?? 'legacy') . ":m{$i}",
                    status: 'active',
                    createdAt: '2026-11-09 12:00:00',
                    deviceId: $owner,
                ));
            }
        }
    }

    /**
     * Everything one Device can observe: status, per-group activeCount, the
     * live active bodies and every snapshot it took.
     *
     * @param list<array{int,int,int,int}> $ops
     * @return array<string, mixed>
     */
    private function view(InMemoryEpisodeRepository $repo, array $ops, string $device): array
    {
        $status = (new MemoryService($repo))->status($device);
        $generation = $repo->deviceGeneration($device);

        $counts = [];
        $bodies = [];
        foreach (self::ROBOTS as $robot) {
            $bodies[$robot] = $repo->activeMemories($device, $robot, $generation, 18)->bodies;
            foreach (self::DISASTERS as $disaster) {
                $counts["{$robot}/{$disaster}"] = $repo->activeCount($device, $robot, $generation, $disaster);
            }
        }

        $snapshots = [];
        foreach ($ops as $i => [$kind, $d]) {
            if ($kind === 4 && self::DEVICES[$d] === $device) {
                foreach (self::ROBOTS as $robot) {
                    $snapshots["snap_{$i}/{$robot}"] = $repo->episodeSnapshot("snap_{$i}", $robot)->bodies;
                }
            }
        }

        return [
            'generation' => $status->generation,
            'amberCount' => $status->amberActiveCount,
            'amberLengths' => $status->amberLengths,
            'jadeCount' => $status->jadeActiveCount,
            'jadeLengths' => $status->jadeLengths,
            'counts' => $counts,
            'bodies' => $bodies,
            'snapshots' => $snapshots,
        ];
    }
}
