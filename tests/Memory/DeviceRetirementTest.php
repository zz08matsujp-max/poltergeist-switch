<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Memory;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Memory\MemoryService;
use PoltergeistSwitch\Memory\Values\MemoryRow;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;

/**
 * Per-Device retirement on the in-memory fake (public-lab task 5.2): the
 * 6-per-(Device, robot, disaster, generation) cap retires only the storing
 * Device's own oldest surplus, never another Device's memories and never a
 * Legacy_Memory (a row with no owner).
 *
 * Requirements: 2.10, 2.11, 2.12, 2.13, 14.14
 */
class DeviceRetirementTest extends TestCase
{
    private const DEVICE = 'dev_a';
    private const OTHER = 'dev_b';

    /** Only the storing Device's oldest surplus is retired; the newest 6 stay (Req 2.10, 14.14). */
    public function testOnlyTheStoringDevicesOldestSurplusIsRetired(): void
    {
        $repo = new InMemoryEpisodeRepository();

        for ($i = 1; $i <= 9; $i++) {
            $repo->appendMemory($this->row("ep_a_{$i}", "a{$i}"));
        }

        $this->assertSame(6, $repo->activeCount(self::DEVICE, 'robot_a', 1, 'earthquake'));
        $this->assertSame(
            ['a4', 'a5', 'a6', 'a7', 'a8', 'a9'],
            $repo->activeMemories(self::DEVICE, 'robot_a', 1, 18)->bodies
        );
        $this->assertSame(
            ['a1' => 'retired', 'a2' => 'retired', 'a3' => 'retired', 'a4' => 'active', 'a9' => 'active'],
            array_intersect_key(
                $this->statusByBody($repo),
                array_flip(['a1', 'a2', 'a3', 'a4', 'a9'])
            ),
            'retired rows are kept, flagged retired, never deleted'
        );
    }

    /** The other Device's memories stay active and unchanged (Req 2.11). */
    public function testTheOtherDevicesMemoriesStayActiveAndUnchanged(): void
    {
        $repo = new InMemoryEpisodeRepository();

        for ($i = 1; $i <= 6; $i++) {
            $repo->appendMemory($this->row("ep_b_{$i}", "b{$i}", deviceId: self::OTHER));
        }
        $before = $this->rowsOwnedBy($repo, self::OTHER);

        for ($i = 1; $i <= 10; $i++) {
            $repo->appendMemory($this->row("ep_a_{$i}", "a{$i}"));
        }

        $this->assertSame($before, $this->rowsOwnedBy($repo, self::OTHER));
        $this->assertSame(6, $repo->activeCount(self::OTHER, 'robot_a', 1, 'earthquake'));
        $this->assertSame(
            ['b1', 'b2', 'b3', 'b4', 'b5', 'b6'],
            $repo->activeMemories(self::OTHER, 'robot_a', 1, 18)->bodies
        );
    }

    /** Both Devices over the cap retire independently, each its own oldest (Req 2.10, 2.11). */
    public function testEachDeviceRetiresItsOwnOldestSurplus(): void
    {
        $repo = new InMemoryEpisodeRepository();

        for ($i = 1; $i <= 8; $i++) {
            $repo->appendMemory($this->row("ep_a_{$i}", "a{$i}"));
            $repo->appendMemory($this->row("ep_b_{$i}", "b{$i}", deviceId: self::OTHER));
        }

        $this->assertSame(
            ['a3', 'a4', 'a5', 'a6', 'a7', 'a8'],
            $repo->activeMemories(self::DEVICE, 'robot_a', 1, 18)->bodies
        );
        $this->assertSame(
            ['b3', 'b4', 'b5', 'b6', 'b7', 'b8'],
            $repo->activeMemories(self::OTHER, 'robot_a', 1, 18)->bodies
        );
    }

    /** Every Legacy_Memory is unchanged, whatever any Device stores (Req 2.12). */
    public function testEveryLegacyMemoryIsUnchanged(): void
    {
        $repo = new InMemoryEpisodeRepository();

        for ($i = 1; $i <= 8; $i++) {
            $repo->appendMemory($this->row("ep_legacy_{$i}", "legacy{$i}", deviceId: null));
        }
        $before = $this->rowsOwnedBy($repo, null);
        $this->assertCount(8, $before);

        for ($i = 1; $i <= 9; $i++) {
            $repo->appendMemory($this->row("ep_a_{$i}", "a{$i}"));
            $repo->appendMemory($this->row("ep_b_{$i}", "b{$i}", deviceId: self::OTHER));
        }

        $after = $this->rowsOwnedBy($repo, null);
        $this->assertSame($before, $after);
        foreach ($after as $row) {
            $this->assertSame('active', $row['status']);
            $this->assertNull($row['retiredAt']);
        }
    }

    /** The reflection activeCount is per Device (Req 2.13). */
    public function testActiveCountIsPerDevice(): void
    {
        $repo = new InMemoryEpisodeRepository();

        for ($i = 1; $i <= 9; $i++) {
            $repo->appendMemory($this->row("ep_a_{$i}", "a{$i}"));
        }
        for ($i = 1; $i <= 2; $i++) {
            $repo->appendMemory($this->row("ep_b_{$i}", "b{$i}", deviceId: self::OTHER));
        }
        $repo->appendMemory($this->row('ep_legacy_1', 'legacy1', deviceId: null));

        $this->assertSame(6, $repo->activeCount(self::DEVICE, 'robot_a', 1, 'earthquake'));
        $this->assertSame(2, $repo->activeCount(self::OTHER, 'robot_a', 1, 'earthquake'));
        $this->assertSame(0, $repo->activeCount('dev_new', 'robot_a', 1, 'earthquake'));
        $this->assertSame(0, $repo->activeCount(self::DEVICE, 'robot_b', 1, 'earthquake'), 'per robot');
        $this->assertSame(0, $repo->activeCount(self::DEVICE, 'robot_a', 1, 'heat_wave'), 'per disaster');
    }

    /** The Memory_Snapshot cap stays at 18 across disasters (Req 2.13). */
    public function testSnapshotCapStaysAtEighteen(): void
    {
        $repo = new InMemoryEpisodeRepository();

        // 3 disasters x 6 active = 18 for this Device; one more disaster is
        // only reachable by exceeding the per-disaster cap, so use 4 names
        // with 6 each (24 active) and check the snapshot keeps the newest 18.
        $disasters = ['earthquake', 'heavy_rain', 'heat_wave', 'other'];
        $n = 0;
        foreach ($disasters as $disaster) {
            for ($i = 1; $i <= 6; $i++) {
                $n++;
                $repo->appendMemory($this->row("ep_a_{$n}", "a{$n}", disaster: $disaster));
            }
        }
        $repo->appendMemory($this->row('ep_b_1', 'b1', deviceId: self::OTHER));

        $service = new MemoryService($repo);
        $service->snapshotForNewEpisode('ep_new', self::DEVICE);

        $snapshot = $repo->episodeSnapshot('ep_new', 'robot_a');
        $this->assertCount(18, $snapshot->bodies);
        $this->assertSame('a7', $snapshot->bodies[0], 'the oldest 6 are dropped, oldest first');
        $this->assertSame('a24', $snapshot->bodies[17]);

        $this->assertCount(18, $repo->activeMemories(self::DEVICE, 'robot_a', 1, 18)->bodies);
        $this->assertSame(['b1'], $repo->activeMemories(self::OTHER, 'robot_a', 1, 18)->bodies);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function row(
        string $sourceEpisodeId,
        string $body,
        string $robotId = 'robot_a',
        int $generation = 1,
        string $disaster = 'earthquake',
        ?string $deviceId = self::DEVICE,
    ): MemoryRow {
        return new MemoryRow(
            robotId: $robotId,
            generation: $generation,
            disaster: $disaster,
            sourceEpisodeId: $sourceEpisodeId,
            body: $body,
            status: 'active',
            createdAt: '2026-11-09 12:00:00',
            deviceId: $deviceId,
        );
    }

    /**
     * Every stored row (active and retired) as a plain array, read from the
     * fake's private store so retired and Legacy rows can be checked.
     *
     * @return list<MemoryRow>
     */
    private function storedRows(InMemoryEpisodeRepository $repo): array
    {
        $property = new \ReflectionProperty(InMemoryEpisodeRepository::class, 'memories');

        return array_values($property->getValue($repo));
    }

    /**
     * The stored rows of one owner (null = Legacy_Memory) as comparable arrays.
     *
     * @return list<array<string, mixed>>
     */
    private function rowsOwnedBy(InMemoryEpisodeRepository $repo, ?string $deviceId): array
    {
        $rows = [];
        foreach ($this->storedRows($repo) as $row) {
            if ($row->deviceId === $deviceId) {
                $rows[] = [
                    'body' => $row->body,
                    'status' => $row->status,
                    'seq' => $row->seq,
                    'retiredAt' => $row->retiredAt,
                    'generation' => $row->generation,
                    'disaster' => $row->disaster,
                ];
            }
        }

        return $rows;
    }

    /** @return array<string, string> status keyed by body */
    private function statusByBody(InMemoryEpisodeRepository $repo): array
    {
        $out = [];
        foreach ($this->storedRows($repo) as $row) {
            $out[$row->body] = $row->status;
        }

        return $out;
    }
}
