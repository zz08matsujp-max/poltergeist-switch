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
 * Feature: robot-memory (Device-scoped by public-lab), Property 3: A Device reset
 * starts a fresh generation with zero memories and deletes nothing.
 *
 * For any state of the memory store, calling {@see MemoryService::reset}
 * for one Device advances that Device's generation and removes no memory row, and an episode
 * created immediately after the reset receives an empty Memory_Snapshot for
 * both robots.
 *
 * The property is driven against the in-memory EpisodeRepository fake (the PDO
 * path's logic mirror), so the behaviour asserted is the behaviour the store
 * guarantees. An arbitrary memory store is seeded across both robots, several
 * disasters, and the current generation; then:
 *   - reset() returns a status whose generation is strictly greater than the
 *     generation before the reset (a new, higher integer; Req 4.2);
 *   - every memory row that was active before the reset is still present after
 *     it — reset deletes nothing. The old generation's active bodies read back
 *     byte-for-byte unchanged, proving no row was removed (Req 4.2);
 *   - an episode created immediately after the reset (via
 *     snapshotForNewEpisode, the exact create-time path) carries an empty
 *     snapshot for both robots, because the fresh generation holds no active
 *     memory yet (Req 4.3).
 *
 * NOTE: this Eris release (giorgiosironi/eris ^1.3) controls the iteration
 * count with `limitTo()`; `limitTo(100)` is required here, matching the other
 * property tests.
 *
 * Validates: Requirements 9.3, 4.2, 4.3
 */
class ResetZeroPropertyTest extends TestCase
{
    use TestTrait;

    /** The single Device under test, and a second one that must stay untouched. */
    private const DEVICE = 'device_reset_prop';

    private const OTHER_DEVICE = 'device_other_prop';

    private const ROBOTS = ['robot_a', 'robot_b'];

    private const DISASTERS = ['earthquake', 'heavy_rain', 'heat_wave'];

    /**
     * Property 3: reset advances the generation, deletes no row, and a new
     * episode created right after the reset snapshots zero memories for both
     * robots.
     *
     * Validates: Requirements 9.3, 4.2, 4.3
     */
    public function testResetAdvancesGenerationDeletesNothingAndNewEpisodeIsEmpty(): void
    {
        $this->limitTo(100)
            ->forAll(
                // 0..24 memories to seed before the reset: zero exercises an
                // already-empty store, a large count pushes past the
                // per-triple cap so retired rows are present too.
                Generators::choose(0, 24),
                // A seed to spread the memories deterministically across robots
                // and disasters so Eris can shrink and replay the same plan.
                Generators::int(),
            )
            ->then(function (int $memoryCount, int $spread): void {
                $repo = new InMemoryEpisodeRepository();
                $service = new MemoryService($repo);

                $generationBefore = $repo->deviceGeneration(self::DEVICE);

                // --- Seed an arbitrary memory store in the current generation.
                $this->seedMemories($repo, self::DEVICE, $generationBefore, $spread, $memoryCount);
                // Another Device holds memories of its own; reset must not touch them.
                $this->seedMemories($repo, self::OTHER_DEVICE, 1, $spread, 3);
                $otherBefore = $repo->activeMemories(self::OTHER_DEVICE, 'robot_a', 1, PHP_INT_MAX)->bodies;

                // The active bodies of the current generation, per robot, as
                // they stand before the reset. After the reset these exact rows
                // must still be readable from the old generation — proving
                // reset removed no row.
                $beforeA = $repo->activeMemories(self::DEVICE, 'robot_a', $generationBefore, PHP_INT_MAX)->bodies;
                $beforeB = $repo->activeMemories(self::DEVICE, 'robot_b', $generationBefore, PHP_INT_MAX)->bodies;

                // --- Reset ----------------------------------------------------
                $status = $service->reset(self::DEVICE);

                // The current generation advanced to a new, higher integer
                // (Req 4.2).
                $generationAfter = $repo->deviceGeneration(self::DEVICE);
                $this->assertGreaterThan(
                    $generationBefore,
                    $generationAfter,
                    "reset must advance the current generation to a higher integer "
                        . "(count {$memoryCount}, spread {$spread})"
                );
                $this->assertSame(
                    $generationAfter,
                    $status->generation,
                    'the returned status must report the new current generation'
                );

                // reset deletes nothing: every row active in the old generation
                // before the reset is still readable from that old generation
                // after it, byte-for-byte unchanged (Req 4.2).
                $this->assertSame(
                    $beforeA,
                    $repo->activeMemories(self::DEVICE, 'robot_a', $generationBefore, PHP_INT_MAX)->bodies,
                    "reset must delete no robot_a memory of the old generation "
                        . "(count {$memoryCount}, spread {$spread})"
                );
                $this->assertSame(
                    $beforeB,
                    $repo->activeMemories(self::DEVICE, 'robot_b', $generationBefore, PHP_INT_MAX)->bodies,
                    "reset must delete no robot_b memory of the old generation "
                        . "(count {$memoryCount}, spread {$spread})"
                );

                // Another Device's generation and memories are unchanged.
                $this->assertSame(1, $repo->deviceGeneration(self::OTHER_DEVICE));
                $this->assertSame(
                    $otherBefore,
                    $repo->activeMemories(self::OTHER_DEVICE, 'robot_a', 1, PHP_INT_MAX)->bodies,
                    'a reset must not touch another Device'
                );

                // --- An episode created immediately after the reset ----------
                // snapshotForNewEpisode is the exact create-time path: it reads
                // the current generation (now the fresh one) and freezes each
                // robot's active bodies onto the episode. The fresh generation
                // holds no active memory, so both snapshots are empty (Req 4.3).
                $service->snapshotForNewEpisode('ep_after_reset', self::DEVICE);

                $snapshotA = $repo->episodeSnapshot('ep_after_reset', 'robot_a');
                $snapshotB = $repo->episodeSnapshot('ep_after_reset', 'robot_b');

                $this->assertTrue(
                    $snapshotA->isEmpty(),
                    "robot_a must start the post-reset episode with zero memories "
                        . "(count {$memoryCount}, spread {$spread})"
                );
                $this->assertTrue(
                    $snapshotB->isEmpty(),
                    "robot_b must start the post-reset episode with zero memories "
                        . "(count {$memoryCount}, spread {$spread})"
                );
                $this->assertSame([], $snapshotA->bodies);
                $this->assertSame([], $snapshotB->bodies);

                // And the status right after the reset reports zero active
                // memories for both robots in the fresh generation (Req 4.3).
                $afterStatus = $service->status(self::DEVICE);
                $this->assertSame(0, $afterStatus->amberActiveCount);
                $this->assertSame([], $afterStatus->amberLengths);
                $this->assertSame(0, $afterStatus->jadeActiveCount);
                $this->assertSame([], $afterStatus->jadeLengths);
            });
    }

    /**
     * Seed up to $memoryCount memories in the given generation, spread
     * deterministically across both robots and several disasters so the reset
     * is exercised with retired rows present too. Appends go through
     * appendMemory so the store's own cap/retirement runs — the state reset
     * must leave untouched.
     */
    private function seedMemories(
        InMemoryEpisodeRepository $repo,
        string $deviceId,
        int $generation,
        int $spread,
        int $memoryCount
    ): void {
        for ($i = 0; $i < $memoryCount; $i++) {
            $robot = self::ROBOTS[($spread + $i) % 2 === 0 ? 0 : 1];
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
}
