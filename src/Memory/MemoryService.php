<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Memory;

use PoltergeistSwitch\Memory\Values\MemorySnapshot;
use PoltergeistSwitch\Memory\Values\MemoryStatus;
use PoltergeistSwitch\TurnLoop\Http\ApiError;
use PoltergeistSwitch\TurnLoop\Repository\EpisodeRepository;

/**
 * Snapshot-at-creation, generation reset, and status for robot memory
 * (robot-memory spec, Requirements 4, 5, 6).
 *
 * A plain service with no I/O of its own: it reads and writes only through the
 * extended {@see EpisodeRepository}, so it is driven against the in-memory fake
 * without a database (Requirement 8.1). It holds no rules logic.
 *
 * `snapshotForNewEpisode` freezes each robot's current-generation active
 * memories onto the episode at creation; `reset` advances the current
 * generation and deletes nothing; `status` reports the current generation and
 * each robot's active-memory count and lengths. No device id, API key,
 * database credential, secrets path, or AI company/model name ever appears in a
 * result (Requirement 5.4).
 *
 * PHP 8.2 only.
 */
final class MemoryService
{
    /**
     * The per-robot snapshot cap taken at episode creation: the newest 18
     * active memories of the current generation across all disasters, oldest
     * first (robot-memory Requirements 6.1, 3.8). A robot never carries more
     * than this many memories into one episode.
     */
    private const SNAPSHOT_CAP = 18;

    /** The two robot ids, used to read each robot's memories (Requirement 6.1). */
    private const ROBOT_A = 'robot_a';
    private const ROBOT_B = 'robot_b';

    /** @var callable():string returns 'Y-m-d H:i:s' (injectable for tests) */
    private $clock;

    /**
     * @param callable():string|null $clock returns 'Y-m-d H:i:s'; defaults to date()
     */
    public function __construct(private readonly EpisodeRepository $repo, ?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): string => date('Y-m-d H:i:s');
    }

    /**
     * Take and store the per-robot memory snapshot and the episode's
     * generation (Requirement 6.1).
     *
     * Reads the Current_Generation, then for each robot reads its active-memory
     * bodies of that generation across all disasters, oldest first, capped at
     * 18, and stores the two ordered lists and the generation with the episode.
     * The snapshot is the verbatim bodies only, so a rebuild needs no join back
     * to the `memories` table and matches the live run byte-for-byte. Called
     * inside createEpisode, reusing the same insert transaction, so every new
     * episode carries a snapshot and a generation.
     *
     * An episode created after a reset snapshots the new generation's active
     * memories, which is empty until a reflection happens in that generation —
     * so a freshly reset generation's robots start with zero memories
     * (Requirements 4.2, 4.3).
     */
    public function snapshotForNewEpisode(string $episodeId, string $deviceId): void
    {
        $generation = $this->repo->deviceGeneration($deviceId);

        $robotA = $this->repo->activeMemories($deviceId, self::ROBOT_A, $generation, self::SNAPSHOT_CAP);
        $robotB = $this->repo->activeMemories($deviceId, self::ROBOT_B, $generation, self::SNAPSHOT_CAP);

        $this->repo->storeEpisodeMemory($episodeId, $generation, $robotA, $robotB);
    }

    /**
     * Start a new generation and report the resulting status
     * (Requirements 4.2, 4.3).
     *
     * Advances the Current_Generation to a new, higher integer and deletes no
     * memory row: every earlier-generation memory is kept but excluded from
     * reads. Episodes created after the reset snapshot the new generation's
     * active memories, which is empty, so a freshly reset generation's robots
     * start with zero memories. A running episode keeps its own stored
     * generation and snapshot, so a reset mid-run carries no old memory into
     * the new generation and its later reflections store under its own
     * generation (Requirement 4.4).
     */
    public function reset(?string $deviceId): MemoryStatus
    {
        // No Device: nothing to reset (public-lab Req 2.13).
        if ($deviceId === null) {
            return $this->status(null);
        }

        // A running episode of this Device blocks the reset (Req 2.6, 2.7).
        if ($this->repo->findRunningEpisodeForDevice($deviceId) !== null) {
            throw ApiError::conflict('episode_running');
        }

        // Every accepted reset is recorded; nothing is deleted (Req 2.2, 2.10).
        $this->repo->recordDeviceReset($deviceId, ($this->clock)());

        return $this->status($deviceId);
    }

    /**
     * Report the Current_Generation and, per robot reported by its screen name
     * Amber (robot_a) and Jade (robot_b), the count of active memories of the
     * current generation and the character length of each, oldest first
     * (Requirements 5.2, 5.3).
     *
     * Only active memories of the current generation are counted and measured;
     * every retired memory and every memory of an earlier generation is
     * excluded (Requirement 5.3). The result carries no device id, API key,
     * database credential, or AI company/model name (Requirement 5.4).
     */
    public function status(?string $deviceId): MemoryStatus
    {
        // No Device: generation 1 and zeros (public-lab Req 2.3, 2.4).
        if ($deviceId === null) {
            return new MemoryStatus(
                generation: 1,
                amberActiveCount: 0,
                amberLengths: [],
                jadeActiveCount: 0,
                jadeLengths: [],
            );
        }

        $generation = $this->repo->deviceGeneration($deviceId);

        $amberBodies = $this->repo->activeMemories($deviceId, self::ROBOT_A, $generation, self::SNAPSHOT_CAP)->bodies;
        $jadeBodies = $this->repo->activeMemories($deviceId, self::ROBOT_B, $generation, self::SNAPSHOT_CAP)->bodies;

        return new MemoryStatus(
            generation: $generation,
            amberActiveCount: count($amberBodies),
            amberLengths: $this->lengthsOf($amberBodies),
            jadeActiveCount: count($jadeBodies),
            jadeLengths: $this->lengthsOf($jadeBodies),
        );
    }

    /**
     * The character length of each memory body, oldest first, preserving order.
     * Uses mb_strlen so a multibyte body (Japanese or English) is measured in
     * characters rather than bytes (Requirement 5.2).
     *
     * @param string[] $bodies verbatim memory bodies, oldest first
     * @return int[] the character length of each body, oldest first
     */
    private function lengthsOf(array $bodies): array
    {
        $lengths = [];
        foreach ($bodies as $body) {
            $lengths[] = mb_strlen($body);
        }

        return $lengths;
    }
}
