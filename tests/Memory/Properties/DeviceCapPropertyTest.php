<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Memory\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Memory\Values\MemoryRow;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;

/**
 * Feature: public-lab, Property 3: Per-Device cap and retirement
 *
 * For any sequence of memory stores from any number of Devices, after every
 * store:
 *   - the active count of each (device, robot, disaster, generation) is at
 *     most 6;
 *   - the memories retired by that store are only the storing Device's oldest
 *     surplus for the same key (the newest 6 stay active);
 *   - every other Device's memories and every Legacy_Memory are unchanged
 *     (status and retired_at included).
 *
 * Drives the in-memory EpisodeRepository fake (the PDO path's logic mirror)
 * through both store paths, appendMemory and commitReflectionAnswer.
 *
 * NOTE: this Eris release controls the iteration count with `limitTo()`;
 * `limitTo(100)` is required here, matching the other property tests.
 *
 * Validates: Requirements 2.10, 2.11
 */
class DeviceCapPropertyTest extends TestCase
{
    use TestTrait;

    private const CAP = 6;

    private const DEVICES = ['dev_a', 'dev_b', 'dev_c'];

    private const ROBOTS = ['robot_a', 'robot_b'];

    private const DISASTERS = ['earthquake', 'heavy_rain', 'heat_wave'];

    /**
     * Feature: public-lab, Property 3: Per-Device cap and retirement
     *
     * Validates: Requirements 2.10, 2.11
     */
    public function testPerDeviceCapAndRetirement(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generators::choose(0, 5), // number of seeded Legacy_Memory rows
                Generators::seq(
                    Generators::tuple(
                        Generators::choose(0, 2), // device index
                        Generators::choose(0, 1), // robot index
                        Generators::choose(0, 2), // disaster index
                        Generators::choose(1, 2), // generation
                        Generators::choose(0, 1), // 0 = appendMemory, 1 = commitReflectionAnswer
                    )
                )
            )
            ->then(function (int $legacyCount, array $steps): void {
                $repo = new InMemoryEpisodeRepository();

                for ($i = 1; $i <= $legacyCount; $i++) {
                    $repo->appendMemory($this->row(null, 'robot_a', 1, 'earthquake', "ep_legacy_{$i}", "legacy{$i}"));
                }

                // Model: bodies stored per key, in store order.
                $stored = [];
                $seq = 0;

                foreach ($steps as [$deviceIndex, $robotIndex, $disasterIndex, $generation, $viaReflection]) {
                    $device = self::DEVICES[$deviceIndex];
                    $robot = self::ROBOTS[$robotIndex];
                    $disaster = self::DISASTERS[$disasterIndex];
                    $key = "{$device}|{$robot}|{$disaster}|{$generation}";

                    $before = $this->rowsByBody($repo);

                    $seq++;
                    $body = "m{$seq}";
                    $memory = $this->row($device, $robot, $generation, $disaster, "ep_{$seq}", $body);
                    if ($viaReflection === 1) {
                        $repo->commitReflectionAnswer("ep_{$seq}", $robot, $memory);
                    } else {
                        $repo->appendMemory($memory);
                    }
                    $stored[$key][] = $body;

                    $after = $this->rowsByBody($repo);

                    // Nothing is ever deleted; exactly one new row.
                    $this->assertCount(count($before) + 1, $after);

                    // Every key stays within the cap, and holds exactly its newest 6.
                    foreach ($stored as $k => $bodies) {
                        [$d, $r, $di, $g] = explode('|', $k);
                        $this->assertLessThanOrEqual(
                            self::CAP,
                            $repo->activeCount($d, $r, (int) $g, $di),
                            "active count for {$k} must never exceed " . self::CAP
                        );
                        $expectedActive = array_slice($bodies, -self::CAP);
                        $actualActive = [];
                        foreach ($after as $b => $row) {
                            if (
                                $row['deviceId'] === $d && $row['robotId'] === $r
                                && $row['disaster'] === $di && $row['generation'] === (int) $g
                                && $row['status'] === 'active'
                            ) {
                                $actualActive[] = $b;
                            }
                        }
                        $this->assertSame(
                            $expectedActive,
                            $actualActive,
                            "only the oldest surplus of {$k} may be retired"
                        );
                    }

                    // Rows whose state changed in this store: all retirements of
                    // the storing Device's same key; everything else is unchanged.
                    foreach ($before as $b => $oldRow) {
                        $newRow = $after[$b];
                        if ($oldRow === $newRow) {
                            continue;
                        }
                        $this->assertSame('active', $oldRow['status']);
                        $this->assertSame('retired', $newRow['status']);
                        $this->assertSame($device, $oldRow['deviceId'], 'only the storing Device loses memories');
                        $this->assertSame($robot, $oldRow['robotId']);
                        $this->assertSame($disaster, $oldRow['disaster']);
                        $this->assertSame($generation, $oldRow['generation']);
                    }
                }

                // Legacy_Memory rows are untouched at the end.
                $legacy = array_filter($this->rowsByBody($repo), static fn (array $r): bool => $r['deviceId'] === null);
                $this->assertCount($legacyCount, $legacy);
                foreach ($legacy as $row) {
                    $this->assertSame('active', $row['status']);
                    $this->assertNull($row['retiredAt']);
                }
            });
    }

    private function row(
        ?string $deviceId,
        string $robotId,
        int $generation,
        string $disaster,
        string $sourceEpisodeId,
        string $body,
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
     * Every stored row (active and retired) keyed by its unique body, in store
     * order, read from the fake's private store.
     *
     * @return array<string, array<string, mixed>>
     */
    private function rowsByBody(InMemoryEpisodeRepository $repo): array
    {
        $property = new \ReflectionProperty(InMemoryEpisodeRepository::class, 'memories');

        $out = [];
        foreach ($property->getValue($repo) as $row) {
            $out[$row->body] = [
                'deviceId' => $row->deviceId,
                'robotId' => $row->robotId,
                'disaster' => $row->disaster,
                'generation' => $row->generation,
                'status' => $row->status,
                'retiredAt' => $row->retiredAt,
            ];
        }

        return $out;
    }
}
