<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Memory;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Memory\MemoryService;
use PoltergeistSwitch\Memory\Values\MemoryRow;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;

/**
 * Legacy_Memory (a row with no owner) is never visible to any Device, and a
 * new Device starts with nothing (public-lab task 5.3).
 *
 * Requirements: 2.6, 2.7, 5.2, 14.4
 */
class LegacyMemoryTest extends TestCase
{
    /** A Legacy_Memory never appears in a snapshot, status or count (Req 2.6, 2.7, 5.2). */
    public function testLegacyMemoryNeverAppearsForAnyDevice(): void
    {
        $repo = new InMemoryEpisodeRepository();
        for ($i = 1; $i <= 4; $i++) {
            $repo->appendMemory($this->row("ep_la_{$i}", "legacyA{$i}", 'robot_a', null));
            $repo->appendMemory($this->row("ep_lb_{$i}", "legacyB{$i}", 'robot_b', null));
        }
        $repo->appendMemory($this->row('ep_own', 'ownA', 'robot_a', 'dev_a'));

        $service = new MemoryService($repo);

        foreach (['dev_a', 'dev_b', 'dev_new'] as $device) {
            $service->snapshotForNewEpisode("ep_{$device}", $device);
            $snapA = $repo->episodeSnapshot("ep_{$device}", 'robot_a')->bodies;
            $snapB = $repo->episodeSnapshot("ep_{$device}", 'robot_b')->bodies;

            foreach (array_merge($snapA, $snapB) as $body) {
                $this->assertStringNotContainsString('legacy', $body, "snapshot of {$device}");
            }

            $status = $service->status($device);
            $this->assertSame($device === 'dev_a' ? 1 : 0, $status->amberActiveCount);
            $this->assertSame(0, $status->jadeActiveCount);
            $this->assertSame(
                $device === 'dev_a' ? 1 : 0,
                $repo->activeCount($device, 'robot_a', 1, 'earthquake')
            );
            $this->assertSame(0, $repo->activeCount($device, 'robot_b', 1, 'earthquake'));
        }

        $this->assertSame(['ownA'], $repo->episodeSnapshot('ep_dev_a', 'robot_a')->bodies);
    }

    /** A Device with no owner (null) sees generation 1 and zeros (Req 2.7, 5.2). */
    public function testNoDeviceStatusIsEmpty(): void
    {
        $repo = new InMemoryEpisodeRepository();
        $repo->appendMemory($this->row('ep_l', 'legacy', 'robot_a', null));

        $status = (new MemoryService($repo))->status(null);

        $this->assertSame(1, $status->generation);
        $this->assertSame(0, $status->amberActiveCount);
        $this->assertSame(0, $status->jadeActiveCount);
        $this->assertSame([], $status->amberLengths);
        $this->assertSame([], $status->jadeLengths);
    }

    /** A new Device gets an empty snapshot for both robots (Req 2.6, 14.4). */
    public function testNewDeviceGetsEmptySnapshotForBothRobots(): void
    {
        $repo = new InMemoryEpisodeRepository();
        $repo->appendMemory($this->row('ep_l', 'legacy', 'robot_a', null));
        $repo->appendMemory($this->row('ep_o', 'other', 'robot_b', 'dev_other'));

        (new MemoryService($repo))->snapshotForNewEpisode('ep_fresh', 'dev_fresh');

        $this->assertCount(0, $repo->episodeSnapshot('ep_fresh', 'robot_a')->bodies);
        $this->assertCount(0, $repo->episodeSnapshot('ep_fresh', 'robot_b')->bodies);
    }

    private function row(string $sourceEpisodeId, string $body, string $robotId, ?string $deviceId): MemoryRow
    {
        return new MemoryRow(
            robotId: $robotId,
            generation: 1,
            disaster: 'earthquake',
            sourceEpisodeId: $sourceEpisodeId,
            body: $body,
            status: 'active',
            createdAt: '2026-11-09 12:00:00',
            deviceId: $deviceId,
        );
    }
}
