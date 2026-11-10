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
 * Feature: public-lab, Property 2: Snapshot order and cap.
 *
 * For any Device with any sequence of stored memories across robots, disasters
 * and its current generation, each robot's Memory_Snapshot is the newest at
 * most 18 of that Device's active memories for that robot in the current
 * generation, ordered oldest first across all disasters; surplus memories
 * beyond 18 stay stored and unchanged; and a Device with no active memories
 * gets an empty snapshot (0 memories) for both robots.
 *
 * The expected snapshot is computed by a small independent model (newest 6
 * active per Device, robot, disaster and generation; then the newest 18 of the
 * current generation, oldest first), not by reading the repository back. The
 * plan mixes in a second Device, Legacy_Memory rows (no owner) and Device
 * resets, which must never leak into or alter the snapshot.
 *
 * Validates: Requirements 2.3, 2.4, 2.7, 2.13
 */
final class DeviceSnapshotPropertyTest extends TestCase
{
    use TestTrait;

    private const DEVICE = 'dev_snapshot';
    private const OTHER = 'dev_other';
    private const CAP = 18;
    private const PER_GROUP = 6;
    private const ROBOTS = ['robot_a', 'robot_b'];
    private const DISASTERS = ['earthquake', 'heavy_rain', 'heat_wave', 'other'];

    /**
     * Feature: public-lab, Property 2: Snapshot order and cap
     *
     * Validates: Requirements 2.3, 2.4, 2.7, 2.13
     */
    public function testSnapshotIsNewestEighteenActiveOldestFirst(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generators::seq(
                    Generators::tuple(
                        Generators::choose(0, 1),  // robot
                        Generators::choose(0, 3),  // disaster
                        Generators::choose(0, 2),  // 0 = this Device, 1 = other Device, 2 = Legacy
                        Generators::choose(0, 11), // 0 on this Device's step = a Device reset first
                    )
                ),
                Generators::choose(0, 60) // extra memories so the 18 cap is reached often
            )
            ->then(function (array $plan, int $extra): void {
                for ($i = 0; $i < $extra; $i++) {
                    $plan[] = [$i % 2, intdiv($i, 6) % 4, 0, 5];
                }

                $repo = new InMemoryEpisodeRepository();

                // Independent model: per robot, the active rows of the current
                // generation as [seq, body, group], and the group buckets.
                $generation = 1;
                $model = []; // list of ['robot','disaster','generation','body','active']
                $n = 0;

                foreach ($plan as [$robotIdx, $disasterIdx, $owner, $marker]) {
                    if ($owner === 0 && $marker === 0) {
                        $repo->recordDeviceReset(self::DEVICE, '2026-11-09 12:00:00');
                        $generation++;
                    }

                    $n++;
                    $robot = self::ROBOTS[$robotIdx];
                    $disaster = self::DISASTERS[$disasterIdx];
                    $deviceId = match ($owner) {
                        0 => self::DEVICE,
                        1 => self::OTHER,
                        default => null,
                    };
                    $rowGeneration = $owner === 0 ? $generation : 1;
                    $body = "m{$n}";

                    $repo->appendMemory(new MemoryRow(
                        robotId: $robot,
                        generation: $rowGeneration,
                        disaster: $disaster,
                        sourceEpisodeId: "src_{$n}",
                        body: $body,
                        status: 'active',
                        createdAt: '2026-11-09 12:00:00',
                        deviceId: $deviceId,
                    ));

                    if ($owner === 0) {
                        $model[] = [
                            'robot' => $robot,
                            'disaster' => $disaster,
                            'generation' => $rowGeneration,
                            'body' => $body,
                        ];
                    }
                }

                $this->assertSame($generation, $repo->deviceGeneration(self::DEVICE));

                $before = $this->storedRows($repo);

                (new MemoryService($repo))->snapshotForNewEpisode('ep_new', self::DEVICE);

                foreach (self::ROBOTS as $robot) {
                    $expected = $this->expectedSnapshot($model, $robot, $generation);
                    $actual = $repo->episodeSnapshot('ep_new', $robot)->bodies;

                    $this->assertSame(
                        $expected,
                        $actual,
                        "{$robot} snapshot must be the newest at most 18 active, current generation, oldest first"
                    );
                    $this->assertLessThanOrEqual(self::CAP, count($actual));
                    $this->assertSame(
                        $expected,
                        $repo->activeMemories(self::DEVICE, $robot, $generation, self::CAP)->bodies
                    );
                }

                // Taking the snapshot stores and changes nothing else: every
                // row, including those beyond the 18, is unchanged.
                $this->assertEquals($before, $this->storedRows($repo));
            });
    }

    /** A Device with no active memories gets an empty snapshot for both robots (Req 2.7). */
    public function testDeviceWithNoMemoriesGetsEmptySnapshots(): void
    {
        $this->limitTo(100)
            ->forAll(Generators::seq(Generators::tuple(
                Generators::choose(0, 1),
                Generators::choose(0, 3),
                Generators::choose(0, 1), // 0 = other Device, 1 = Legacy
            )))
            ->then(function (array $plan): void {
                $repo = new InMemoryEpisodeRepository();

                foreach ($plan as $i => [$robotIdx, $disasterIdx, $owner]) {
                    $repo->appendMemory(new MemoryRow(
                        robotId: self::ROBOTS[$robotIdx],
                        generation: 1,
                        disaster: self::DISASTERS[$disasterIdx],
                        sourceEpisodeId: "src_{$i}",
                        body: "m{$i}",
                        status: 'active',
                        createdAt: '2026-11-09 12:00:00',
                        deviceId: $owner === 0 ? self::OTHER : null,
                    ));
                }

                (new MemoryService($repo))->snapshotForNewEpisode('ep_new', self::DEVICE);

                foreach (self::ROBOTS as $robot) {
                    $this->assertSame([], $repo->episodeSnapshot('ep_new', $robot)->bodies);
                }
            });
    }

    /**
     * The model: keep the newest 6 per (robot, disaster, generation) as active,
     * then take the current generation's newest 18, oldest first.
     *
     * @param list<array{robot: string, disaster: string, generation: int, body: string}> $rows in store order
     * @return list<string>
     */
    private function expectedSnapshot(array $rows, string $robot, int $generation): array
    {
        $groups = [];
        foreach ($rows as $index => $row) {
            if ($row['robot'] === $robot && $row['generation'] === $generation) {
                $groups[$row['disaster']][$index] = $row['body'];
            }
        }

        $active = [];
        foreach ($groups as $members) {
            foreach (array_slice($members, -self::PER_GROUP, null, true) as $index => $body) {
                $active[$index] = $body;
            }
        }

        ksort($active);

        return array_values(array_slice($active, -self::CAP));
    }

    /** @return list<MemoryRow> every stored row, active and retired */
    private function storedRows(InMemoryEpisodeRepository $repo): array
    {
        $property = new \ReflectionProperty(InMemoryEpisodeRepository::class, 'memories');

        return array_values($property->getValue($repo));
    }
}
