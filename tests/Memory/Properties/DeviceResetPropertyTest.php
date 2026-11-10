<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Memory\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Memory\MemoryService;
use PoltergeistSwitch\Memory\Values\MemoryRow;
use PoltergeistSwitch\TurnLoop\Values\EpisodeRow;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;

/**
 * Feature: public-lab, Property 4: Reset hides the caller's own memory and changes nothing else
 *
 * For any history of operations by any Devices, after Device D's accepted reset:
 * D's next Memory_Snapshot and status are empty and D's generation is one higher;
 * every other Device's snapshot, status and episode lists are identical to
 * before; exactly one `device_resets` row was added; and no other table gained,
 * lost or modified a row. A second reset by D with no new memory in between
 * returns success and leaves D with zero memories.
 *
 * Driven against the in-memory EpisodeRepository fake (the PDO path's logic
 * mirror). The fake has no row-count accessors, so the private `memories`,
 * `episodes` and `deviceResets` lists are read through reflection to prove
 * that no row was gained, lost or modified.
 *
 * NOTE: this Eris release (giorgiosironi/eris ^1.3) controls the iteration
 * count with `limitTo()`; `limitTo(100)` is used, matching the other property
 * tests.
 *
 * Validates: Requirements 4.1, 4.2, 4.3, 4.7
 */
class DeviceResetPropertyTest extends TestCase
{
    use TestTrait;

    private const DEVICES = ['device_p4_a', 'device_p4_b', 'device_p4_c'];

    private const ROBOTS = ['robot_a', 'robot_b'];

    private const DISASTERS = ['earthquake', 'heavy_rain', 'heat_wave'];

    private const SNAPSHOT_CAP = 18;

    /**
     * Reset by one Device hides only its own memory, adds exactly one
     * device_resets row, and leaves every other Device and table unchanged.
     *
     * Validates: Requirements 4.1, 4.2, 4.3, 4.7
     */
    public function testResetHidesOwnMemoryAndChangesNothingElse(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generators::choose(0, 30),      // memories per Device
                Generators::choose(0, 4),       // ended episodes per Device
                Generators::int(),              // spread seed
                Generators::choose(0, 2),       // which Device resets
                Generators::choose(0, 2),       // earlier resets by every Device
            )
            ->then(function (int $memoryCount, int $episodeCount, int $spread, int $pick, int $earlier): void {
                $repo = new InMemoryEpisodeRepository();
                $service = new MemoryService($repo);
                $label = "(memories {$memoryCount}, episodes {$episodeCount}, spread {$spread}, pick {$pick}, earlier {$earlier})";

                $resetDevice = self::DEVICES[$pick];

                // Build an arbitrary history: earlier resets, then memories and
                // ended episodes for every Device (legacy rows included).
                foreach (self::DEVICES as $index => $device) {
                    for ($r = 0; $r < $earlier; $r++) {
                        $service->reset($device);
                    }
                    $this->seedMemories($repo, $device, $repo->deviceGeneration($device), $spread + $index, $memoryCount);
                    $this->seedEpisodes($repo, $device, $episodeCount);
                }
                $repo->appendMemory(new MemoryRow(
                    robotId: 'robot_a',
                    generation: 1,
                    disaster: 'earthquake',
                    sourceEpisodeId: 'legacy_src',
                    body: 'Legacy memory.',
                    status: 'active',
                    createdAt: date('Y-m-d H:i:s'),
                    deviceId: null,
                ));

                // --- State before ------------------------------------------
                $genBefore = $repo->deviceGeneration($resetDevice);
                $memoriesBefore = $this->privateList($repo, 'memories');
                $episodesBefore = $this->privateList($repo, 'episodes');
                $resetsBefore = $this->privateList($repo, 'deviceResets');
                $endedBefore = $repo->listEndedEpisodes(1000);
                $othersBefore = [];
                foreach (self::DEVICES as $device) {
                    if ($device !== $resetDevice) {
                        $othersBefore[$device] = $this->deviceView($repo, $service, $device);
                    }
                }

                // --- Reset -------------------------------------------------
                $status = $service->reset($resetDevice);

                // D: empty status, empty snapshots, generation one higher.
                $this->assertSame($genBefore + 1, $status->generation, "generation +1 {$label}");
                $this->assertSame($genBefore + 1, $repo->deviceGeneration($resetDevice));
                $this->assertSame(0, $status->amberActiveCount, $label);
                $this->assertSame([], $status->amberLengths, $label);
                $this->assertSame(0, $status->jadeActiveCount, $label);
                $this->assertSame([], $status->jadeLengths, $label);
                foreach (self::ROBOTS as $robot) {
                    $this->assertSame(
                        [],
                        $repo->activeMemories($resetDevice, $robot, $genBefore + 1, self::SNAPSHOT_CAP)->bodies,
                        "{$robot} snapshot of the reset Device is empty {$label}"
                    );
                }

                // Exactly one device_resets row added, nothing else touched.
                $resetsAfter = $this->privateList($repo, 'deviceResets');
                $this->assertCount(count($resetsBefore) + 1, $resetsAfter, "one reset row added {$label}");
                $this->assertSame($resetsBefore, array_slice($resetsAfter, 0, count($resetsBefore)));
                $this->assertSame($resetDevice, $resetsAfter[count($resetsAfter) - 1]['deviceId']);

                // Zero rows deleted or modified in memories and episodes.
                $this->assertEquals($memoriesBefore, $this->privateList($repo, 'memories'), "memories untouched {$label}");
                $this->assertEquals($episodesBefore, $this->privateList($repo, 'episodes'), "episodes untouched {$label}");
                $this->assertEquals($endedBefore, $repo->listEndedEpisodes(1000), "episode list unchanged {$label}");

                // Every other Device: status, snapshots and episode view identical.
                foreach ($othersBefore as $device => $before) {
                    $this->assertEquals($before, $this->deviceView($repo, $service, $device), "{$device} unchanged {$label}");
                }

                // D's next episode starts with empty snapshots (Req 4.3).
                $service->snapshotForNewEpisode('ep_p4_after', $resetDevice);
                $this->assertTrue($repo->episodeSnapshot('ep_p4_after', 'robot_a')->isEmpty(), $label);
                $this->assertTrue($repo->episodeSnapshot('ep_p4_after', 'robot_b')->isEmpty(), $label);

                // --- Second reset with no new memory (Req 4.7) -------------
                $second = $service->reset($resetDevice);
                $this->assertSame($genBefore + 2, $second->generation, $label);
                $this->assertSame(0, $second->amberActiveCount);
                $this->assertSame(0, $second->jadeActiveCount);
                $this->assertCount(count($resetsBefore) + 2, $this->privateList($repo, 'deviceResets'));
                $this->assertEquals($memoriesBefore, $this->privateList($repo, 'memories'), "still no memory row changed {$label}");
            });
    }

    /**
     * Everything one Device can observe: status plus both robots' snapshots in
     * its current generation, and its own episodes.
     *
     * @return array<string, mixed>
     */
    private function deviceView(InMemoryEpisodeRepository $repo, MemoryService $service, string $device): array
    {
        $generation = $repo->deviceGeneration($device);
        $episodes = [];
        foreach ($this->privateList($repo, 'episodes') as $episode) {
            if ($episode->deviceId === $device) {
                $episodes[] = $episode;
            }
        }

        return [
            'status' => $service->status($device),
            'robot_a' => $repo->activeMemories($device, 'robot_a', $generation, self::SNAPSHOT_CAP)->bodies,
            'robot_b' => $repo->activeMemories($device, 'robot_b', $generation, self::SNAPSHOT_CAP)->bodies,
            'episodes' => $episodes,
        ];
    }

    /**
     * Read a private list of the in-memory fake (it has no row-count accessor).
     *
     * @return array<int|string, mixed>
     */
    private function privateList(InMemoryEpisodeRepository $repo, string $property): array
    {
        $reflection = new \ReflectionProperty(InMemoryEpisodeRepository::class, $property);

        return $reflection->getValue($repo);
    }

    private function seedMemories(
        InMemoryEpisodeRepository $repo,
        string $deviceId,
        int $generation,
        int $spread,
        int $memoryCount
    ): void {
        for ($i = 0; $i < $memoryCount; $i++) {
            $robot = self::ROBOTS[abs($spread + $i) % 2];
            $disaster = self::DISASTERS[abs($spread + $i * 7) % count(self::DISASTERS)];

            $repo->appendMemory(new MemoryRow(
                robotId: $robot,
                generation: $generation,
                disaster: $disaster,
                sourceEpisodeId: $deviceId . '_src_' . $i,
                body: "Memory {$i} for {$robot} ({$disaster}, gen {$generation}).",
                status: 'active',
                createdAt: date('Y-m-d H:i:s'),
                deviceId: $deviceId,
            ));
        }
    }

    private function seedEpisodes(InMemoryEpisodeRepository $repo, string $deviceId, int $count): void
    {
        $now = date('Y-m-d H:i:s');
        for ($i = 0; $i < $count; $i++) {
            $repo->createEpisode(new EpisodeRow(
                id: $deviceId . '_ep_' . $i,
                deviceId: $deviceId,
                disaster: 'earthquake',
                seed: $i + 1,
                currentExpectedTurn: 2,
                earthquakeStartTurn: 1,
                ended: true,
                createdAt: $now,
                lastActivityAt: $now,
            ));
        }
    }
}
