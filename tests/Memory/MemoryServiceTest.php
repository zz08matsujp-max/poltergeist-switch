<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Memory;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Memory\MemoryService;
use PoltergeistSwitch\Memory\Values\MemoryRow;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;

/**
 * Example test for MemoryService's reset-during-a-running-episode behaviour
 * (task 7.3; Requirements 4.4, 9.9).
 *
 * The property tests in tests/Memory/Properties cover reset-to-zero
 * (Property 3) and the status counts (Property 8) universally. This example
 * nails the one scenario those properties do not: a reset that happens while
 * an episode is still running. Because MemoryService freezes each robot's
 * generation and snapshot onto the episode row at creation (inside
 * snapshotForNewEpisode), a later reset must not disturb the running episode —
 * it keeps its own generation and its frozen snapshot, and any reflection it
 * commits after the reset is stored under its own (older) generation, never the
 * new one. A brand-new episode created after the reset, by contrast, snapshots
 * the new generation and so starts empty.
 *
 * The test runs entirely against the in-memory EpisodeRepository fake, so no
 * database and no network is involved; it is fully deterministic.
 *
 * Requirements: 4.4, 9.9
 */
final class MemoryServiceTest extends TestCase
{
    public function testResetDuringRunningEpisodeKeepsItsGenerationSnapshotAndReflectionGeneration(): void
    {
        $repo = new InMemoryEpisodeRepository();
        $service = new MemoryService($repo);

        // --- 1. Seed some generation-1 memories, then create the running
        // episode: snapshotForNewEpisode freezes generation G = 1 and each
        // robot's current-generation active bodies onto the episode row.
        $repo->appendMemory($this->row('robot_a', 1, 'seed_a1', 'Amber remembers the kettle'));
        $repo->appendMemory($this->row('robot_a', 1, 'seed_a2', 'Amber remembers the shaking'));
        $repo->appendMemory($this->row('robot_b', 1, 'seed_b1', 'Jade remembers the dishes'));

        $runningEpisodeId = 'ep_running';
        $service->snapshotForNewEpisode($runningEpisodeId);

        $frozenGeneration = $repo->episodeGeneration($runningEpisodeId);
        $this->assertSame(1, $frozenGeneration, 'the running episode freezes generation 1 at creation');

        $frozenA = $repo->episodeSnapshot($runningEpisodeId, 'robot_a')->bodies;
        $frozenB = $repo->episodeSnapshot($runningEpisodeId, 'robot_b')->bodies;
        $this->assertSame(
            ['Amber remembers the kettle', 'Amber remembers the shaking'],
            $frozenA,
            'robot_a freezes its two generation-1 bodies, oldest first'
        );
        $this->assertSame(
            ['Jade remembers the dishes'],
            $frozenB,
            'robot_b freezes its one generation-1 body'
        );

        // --- 2. Reset while that episode is still running: the current
        // generation advances to G + 1 = 2 and no row is deleted.
        $status = $service->reset();
        $this->assertSame(2, $status->generation, 'reset advances the current generation to 2');
        $this->assertSame(2, $repo->currentGeneration(), 'the repository now reports generation 2');

        // --- 3. The running episode is untouched by the reset: it still
        // reports its own frozen generation and its frozen snapshot unchanged.
        $this->assertSame(
            1,
            $repo->episodeGeneration($runningEpisodeId),
            'the running episode keeps its own generation after a reset (Req 4.4)'
        );
        $this->assertSame(
            ['Amber remembers the kettle', 'Amber remembers the shaking'],
            $repo->episodeSnapshot($runningEpisodeId, 'robot_a')->bodies,
            "robot_a's frozen snapshot is unchanged by the reset"
        );
        $this->assertSame(
            ['Jade remembers the dishes'],
            $repo->episodeSnapshot($runningEpisodeId, 'robot_b')->bodies,
            "robot_b's frozen snapshot is unchanged by the reset"
        );

        // --- 4. A reflection committed for the running episode after the reset
        // is stored under that episode's own generation (1), not the new one.
        $reflection = $this->row(
            'robot_a',
            $repo->episodeGeneration($runningEpisodeId),
            $runningEpisodeId,
            'Amber writes down what happened this time',
        );
        $repo->commitReflectionAnswer($runningEpisodeId, 'robot_a', $reflection);

        // It joins the generation-1 active memories (the frozen generation),
        // never generation 2.
        $gen1BodiesA = $repo->activeMemories('robot_a', 1, 18)->bodies;
        $this->assertContains(
            'Amber writes down what happened this time',
            $gen1BodiesA,
            'the later reflection is stored under the running episode\'s own generation 1 (Req 4.4)'
        );
        $this->assertSame(
            [],
            $repo->activeMemories('robot_a', 2, 18)->bodies,
            'generation 2 gains no memory from the running episode\'s reflection'
        );

        // --- 5. A brand-new episode created after the reset snapshots the new
        // generation (2), whose active memories are empty, so it starts with no
        // memories for either robot.
        $freshEpisodeId = 'ep_after_reset';
        $service->snapshotForNewEpisode($freshEpisodeId);

        $this->assertSame(
            2,
            $repo->episodeGeneration($freshEpisodeId),
            'an episode created after the reset gets the new generation 2'
        );
        $this->assertSame(
            [],
            $repo->episodeSnapshot($freshEpisodeId, 'robot_a')->bodies,
            'the fresh episode starts with an empty snapshot for robot_a'
        );
        $this->assertSame(
            [],
            $repo->episodeSnapshot($freshEpisodeId, 'robot_b')->bodies,
            'the fresh episode starts with an empty snapshot for robot_b'
        );
    }

    /** Build a draft MemoryRow; the store assigns the finish-order seq and status. */
    private function row(
        string $robotId,
        int $generation,
        string $sourceEpisodeId,
        string $body,
        string $disaster = 'earthquake',
        string $createdAt = '2026-11-09 12:00:00',
    ): MemoryRow {
        return new MemoryRow(
            robotId: $robotId,
            generation: $generation,
            disaster: $disaster,
            sourceEpisodeId: $sourceEpisodeId,
            body: $body,
            status: 'active',
            createdAt: $createdAt,
        );
    }
}
