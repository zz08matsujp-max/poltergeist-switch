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
 * Feature: public-lab, Property 7: No operation deletes a row
 *
 * For any sequence of create, reflect, reset, list and read operations, the
 * number of episode rows and memory rows never decreases, and no memory row
 * changes except by retirement (`status` to `retired`, `retired_at` set) of an
 * old surplus row.
 *
 * Driven against the in-memory EpisodeRepository fake (the PDO path's logic
 * mirror). The fake has no row-count accessors, so the private `episodes` and
 * `memories` lists are read through reflection.
 *
 * NOTE: this Eris release (giorgiosironi/eris ^1.3) controls the iteration
 * count with `limitTo()`; `limitTo(100)` is used, matching the other property
 * tests.
 *
 * Validates: Requirements 2.8
 */
class NoDeletePropertyTest extends TestCase
{
    use TestTrait;

    private const DEVICES = ['device_p7_a', 'device_p7_b'];

    private const ROBOTS = ['robot_a', 'robot_b'];

    private const DISASTERS = ['earthquake', 'heavy_rain', 'heat_wave'];

    /**
     * Validates: Requirements 2.8
     */
    public function testNoOperationDeletesARow(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generators::seq(
                    Generators::tuple(
                        Generators::choose(0, 4),  // operation kind
                        Generators::choose(0, 1),  // device index
                        Generators::choose(0, 1),  // robot index
                        Generators::choose(0, 2),  // disaster index
                    )
                )
            )
            ->then(function (array $steps): void {
                $repo = new InMemoryEpisodeRepository();
                $service = new MemoryService($repo);
                $counter = 0;

                $episodes = $this->privateList($repo, 'episodes');
                $memories = $this->privateList($repo, 'memories');

                foreach ($steps as $n => [$kind, $deviceIndex, $robotIndex, $disasterIndex]) {
                    $device = self::DEVICES[$deviceIndex];
                    $robot = self::ROBOTS[$robotIndex];
                    $disaster = self::DISASTERS[$disasterIndex];
                    $counter++;

                    switch ($kind) {
                        case 0: // create an episode
                            $now = date('Y-m-d H:i:s');
                            $repo->createEpisode(new EpisodeRow(
                                id: "p7_ep_{$counter}",
                                deviceId: $device,
                                disaster: $disaster,
                                seed: $counter,
                                currentExpectedTurn: 2,
                                earthquakeStartTurn: 1,
                                ended: true,
                                createdAt: $now,
                                lastActivityAt: $now,
                            ));
                            break;
                        case 1: // reflect: commit a memory (cap pressure via few triples)
                            $repo->appendMemory(new MemoryRow(
                                robotId: $robot,
                                generation: $repo->deviceGeneration($device),
                                disaster: $disaster,
                                sourceEpisodeId: "p7_src_{$counter}",
                                body: "Memory {$counter}.",
                                status: 'active',
                                createdAt: date('Y-m-d H:i:s'),
                                deviceId: $device,
                            ));
                            break;
                        case 2: // reset
                            $service->reset($device);
                            break;
                        case 3: // list
                            $repo->listEndedEpisodes(1000);
                            break;
                        default: // read
                            $service->status($device);
                            $repo->activeMemories($device, $robot, $repo->deviceGeneration($device), 18);
                            break;
                    }

                    $label = "(after step {$n}, kind {$kind})";
                    $episodesAfter = $this->privateList($repo, 'episodes');
                    $memoriesAfter = $this->privateList($repo, 'memories');

                    // Counts never decrease; episodes are never altered.
                    $this->assertGreaterThanOrEqual(count($episodes), count($episodesAfter), "episode rows {$label}");
                    $this->assertGreaterThanOrEqual(count($memories), count($memoriesAfter), "memory rows {$label}");
                    foreach ($episodes as $id => $row) {
                        $this->assertArrayHasKey($id, $episodesAfter, "episode kept {$label}");
                        $this->assertEquals($row, $episodesAfter[$id], "episode unchanged {$label}");
                    }

                    // Memory rows keep their position and content; the only
                    // allowed change is active -> retired with retiredAt set.
                    foreach ($memories as $index => $before) {
                        $this->assertArrayHasKey($index, $memoriesAfter, "memory kept {$label}");
                        $after = $memoriesAfter[$index];
                        if ($before->status === 'retired') {
                            $this->assertEquals($before, $after, "retired row frozen {$label}");
                            continue;
                        }
                        if ($after->status === 'retired') {
                            $this->assertNotNull($after->retiredAt, "retired_at set {$label}");
                            $this->assertEquals(
                                $before,
                                new MemoryRow(
                                    robotId: $after->robotId,
                                    generation: $after->generation,
                                    disaster: $after->disaster,
                                    sourceEpisodeId: $after->sourceEpisodeId,
                                    body: $after->body,
                                    status: 'active',
                                    createdAt: $after->createdAt,
                                    seq: $after->seq,
                                    retiredAt: null,
                                    deviceId: $after->deviceId,
                                ),
                                "only status/retired_at changed {$label}"
                            );
                        } else {
                            $this->assertEquals($before, $after, "active row unchanged {$label}");
                        }
                    }

                    $episodes = $episodesAfter;
                    $memories = $memoriesAfter;
                }
            });
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
}
