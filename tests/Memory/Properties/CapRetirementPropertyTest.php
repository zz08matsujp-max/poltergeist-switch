<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Memory\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Memory\Values\MemoryRow;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;

/**
 * Feature: robot-memory, Property 1: Cap, retirement, and read-ordering; and
 * Feature: robot-memory, Property 2: Memories round-trip verbatim with their
 * tags in finish order.
 *
 * These drive the fully-implemented in-memory EpisodeRepository fake (the PDO
 * path's logic mirror; its MySQL locking/atomicity tests SKIP when no test
 * database is available), so the behaviour asserted is the behaviour the store
 * guarantees:
 *
 * Property 1 — for any sequence of appends across robots, disasters, and
 * generations (including two interleaved commits for the same
 * `(robot, disaster, generation)` from two different episodes):
 *   - the active count per triple is at most 6 at every committed state;
 *   - adding a 7th retires the oldest active of that triple while its row
 *     remains (never deleted) and no other-generation memory is retired;
 *   - a retired memory never appears in any read;
 *   - `activeMemories` returns oldest first across all disasters, excluding
 *     retired and other-generation rows.
 *
 * Property 2 — storing a non-empty answer appends exactly one memory whose body
 * equals the answer byte-for-byte with matching robot, generation, disaster,
 * source episode, and created time, read back in finish order.
 *
 * NOTE: this Eris release (giorgiosironi/eris ^1.3) controls the iteration
 * count with `limitTo()`; `limitTo(100)` is required here, matching the other
 * property tests.
 *
 * Validates: Requirements 9.2, 3.3, 3.4, 3.5, 3.6, 3.8, 8.4, 2.6, 3.7
 */
class CapRetirementPropertyTest extends TestCase
{
    use TestTrait;

    /** The per-(robot, disaster, generation) active cap (design, Requirement 3.3). */
    private const TRIPLE_CAP = 6;

    /** The cross-disaster snapshot cap used by activeMemories (Requirement 3.8). */
    private const SNAPSHOT_CAP = 18;

    private const ROBOTS = ['robot_a', 'robot_b'];

    private const DISASTERS = ['earthquake', 'heavy_rain', 'heat_wave'];

    /**
     * Property 1: Cap, retirement, and read-ordering.
     *
     * Generate an arbitrary sequence of appends, each tagged with a random
     * robot, disaster, and generation, plus an occasional second interleaved
     * commit for the same triple from a different source episode. After every
     * committed append the invariants above must hold.
     *
     * Validates: Requirements 9.2, 3.3, 3.4, 3.5, 3.6, 3.8, 8.4, 3.7
     */
    public function testCapRetirementAndReadOrdering(): void
    {
        $this->limitTo(100)
            ->forAll(
                // 1..30 append steps; each step draws (robot, disaster,
                // generation) indices plus a flag that, when set, fires a
                // second interleaved commit for the SAME triple from another
                // episode (modelling two episodes finishing their reflections
                // for the same robot/disaster/generation).
                Generators::seq(
                    Generators::tuple(
                        Generators::choose(0, 1),  // robot index
                        Generators::choose(0, 2),  // disaster index
                        Generators::choose(1, 3),  // generation
                        Generators::choose(0, 1),  // interleave flag
                    )
                )
            )
            ->then(function (array $steps): void {
                $repo = new InMemoryEpisodeRepository();

                // The exact set of (robot, generation) pairs touched, so the
                // cross-disaster snapshot read can be checked for each.
                $touched = [];
                // The finish-order body log per (robot, generation), the
                // oldest-first order appends happened in — used to check the
                // snapshot keeps the newest SNAPSHOT_CAP in order.
                $appendedBodies = [];
                $episodeSeq = 0;

                foreach ($steps as $step) {
                    [$robotIndex, $disasterIndex, $generation, $interleave] = $step;
                    $robotId = self::ROBOTS[$robotIndex];
                    $disaster = self::DISASTERS[$disasterIndex];

                    $pairKey = $robotId . '#' . $generation;
                    $touched[$pairKey] = [$robotId, $generation];

                    // One append.
                    $episodeSeq++;
                    $body = "body ep{$episodeSeq} {$robotId} {$disaster} g{$generation}";
                    $repo->appendMemory($this->row(
                        $robotId,
                        $generation,
                        $disaster,
                        "ep_{$episodeSeq}",
                        $body,
                    ));
                    $appendedBodies[$pairKey][] = $body;

                    $this->assertInvariantsHold($repo, $touched, $appendedBodies);

                    // An interleaved second commit for the SAME triple from a
                    // different source episode. appendMemory retires down to 6
                    // on every call, so however the two appends order, the
                    // committed active count never exceeds 6 (Req 8.4).
                    if ($interleave === 1) {
                        $episodeSeq++;
                        $body2 = "body ep{$episodeSeq} {$robotId} {$disaster} g{$generation}";
                        $repo->appendMemory($this->row(
                            $robotId,
                            $generation,
                            $disaster,
                            "ep_{$episodeSeq}",
                            $body2,
                        ));
                        $appendedBodies[$pairKey][] = $body2;

                        $this->assertInvariantsHold($repo, $touched, $appendedBodies);
                    }
                }
            });
    }

    /**
     * Property 2: Memories round-trip verbatim with their tags in finish order.
     *
     * For a batch of answers committed through `commitReflectionAnswer` (the
     * reflect commit path), each non-empty answer appends exactly one memory
     * whose body equals the answer byte-for-byte with its robot, generation,
     * disaster, source episode, and created time intact; reading the active
     * memories back returns the bodies in finish order, oldest first.
     *
     * The batch is kept at or below the cap so no answer is retired, letting us
     * assert the full round-trip and exact finish order of every stored body.
     *
     * Validates: Requirements 2.6, 3.6, 3.7, 3.8
     */
    public function testMemoriesRoundTripVerbatimInFinishOrder(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generators::choose(0, 1),            // robot index
                Generators::choose(0, 2),            // disaster index
                Generators::choose(1, 3),            // generation
                // Arbitrary answer bodies; trimmed/filtered to non-empty and
                // capped inside the body so none is retired (full finish order
                // checkable).
                Generators::seq(Generators::string())
            )
            ->then(function (
                int $robotIndex,
                int $disasterIndex,
                int $generation,
                array $rawBodies,
            ): void {
                $robotId = self::ROBOTS[$robotIndex];
                $disaster = self::DISASTERS[$disasterIndex];

                // Non-empty answers only (an empty answer stores no memory in
                // the service; Requirement 2.7), capped at TRIPLE_CAP so none
                // is retired and the full finish order stays checkable.
                $bodies = array_values(array_filter(
                    $rawBodies,
                    static fn (string $b): bool => $b !== '',
                ));
                $bodies = array_slice($bodies, 0, self::TRIPLE_CAP);
                if ($bodies === []) {
                    $bodies = ['a remembered thing'];
                }

                $repo = new InMemoryEpisodeRepository();

                // Commit each answer in order through the reflect commit path.
                // Each one is tagged with a distinct source episode, created
                // time, robot, generation, and disaster so the round-trip can
                // check every tag survives verbatim.
                $stored = [];
                foreach ($bodies as $i => $body) {
                    $episodeId = "ep_{$i}";
                    $createdAt = sprintf('2026-11-09 12:%02d:00', $i);
                    $memory = $this->row(
                        $robotId,
                        $generation,
                        $disaster,
                        $episodeId,
                        $body,
                        $createdAt,
                    );
                    $before = $repo->activeCount($robotId, $generation, $disaster);
                    $repo->commitReflectionAnswer($episodeId, $robotId, $memory);
                    $after = $repo->activeCount($robotId, $generation, $disaster);

                    // Exactly one memory appended per committed answer.
                    $this->assertSame(
                        $before + 1,
                        $after,
                        'each committed answer must append exactly one active memory'
                    );

                    $stored[] = $body;
                }

                // Read back: the active bodies of this (robot, generation)
                // across all disasters, oldest first, must equal the bodies in
                // the exact finish order they were committed, byte-for-byte.
                $snapshot = $repo->activeMemories($robotId, $generation, self::SNAPSHOT_CAP);
                $this->assertSame(
                    $stored,
                    $snapshot->bodies,
                    'stored answers must round-trip verbatim in finish order'
                );

                // The reflection slot reads back the stored row with its tags
                // intact (robot, generation, disaster, source episode, created
                // time), proving the tags survived the commit verbatim.
                foreach ($bodies as $i => $body) {
                    $episodeId = "ep_{$i}";
                    $row = $repo->reflectionAnswer($episodeId, $robotId);
                    $this->assertNotNull(
                        $row,
                        'an answered slot must read back its stored memory'
                    );
                    $this->assertSame($body, $row->body, 'body must be verbatim');
                    $this->assertSame($robotId, $row->robotId, 'robot tag must match');
                    $this->assertSame($generation, $row->generation, 'generation tag must match');
                    $this->assertSame($disaster, $row->disaster, 'disaster tag must match');
                    $this->assertSame(
                        $episodeId,
                        $row->sourceEpisodeId,
                        'source episode tag must match'
                    );
                    $this->assertSame(
                        sprintf('2026-11-09 12:%02d:00', $i),
                        $row->createdAt,
                        'created time must match'
                    );
                }
            });
    }

    /**
     * Assert Property 1's invariants hold at the current committed state:
     *   - every touched (robot, disaster, generation) triple has at most
     *     TRIPLE_CAP active memories (Req 3.3, 8.4);
     *   - a retired memory never appears in any read: the active snapshot's
     *     count matches the per-triple active counts, and the snapshot never
     *     shows a retired/other-generation body (Req 3.5);
     *   - activeMemories returns oldest first across all disasters, and keeps
     *     the newest SNAPSHOT_CAP in that order (Req 3.8).
     *
     * @param array<string, array{0:string,1:int}> $touched (robot,generation) pairs
     * @param array<string, string[]> $appendedBodies finish-order bodies per pair
     */
    private function assertInvariantsHold(
        InMemoryEpisodeRepository $repo,
        array $touched,
        array $appendedBodies,
    ): void {
        foreach ($touched as $pairKey => [$robotId, $generation]) {
            // At most 6 active per triple, for every disaster.
            foreach (self::DISASTERS as $disaster) {
                $count = $repo->activeCount($robotId, $generation, $disaster);
                $this->assertLessThanOrEqual(
                    self::TRIPLE_CAP,
                    $count,
                    "the active count for ({$robotId}, {$disaster}, g{$generation}) "
                        . 'must never exceed the cap of ' . self::TRIPLE_CAP
                );
            }

            // The cross-disaster snapshot, oldest first, excluding retired and
            // other-generation rows.
            $snapshot = $repo->activeMemories($robotId, $generation, self::SNAPSHOT_CAP);
            $bodies = $snapshot->bodies;

            // The expected active bodies: the appended bodies of this pair that
            // survived retirement. A body is retired only when its triple
            // exceeded the cap, so we recompute the surviving set per triple
            // (the newest TRIPLE_CAP per disaster), then keep them in overall
            // finish order (the order they were appended in).
            $expected = $this->survivingBodiesInFinishOrder(
                $appendedBodies[$pairKey] ?? []
            );

            // The snapshot keeps the newest SNAPSHOT_CAP of the survivors, in
            // finish order.
            if (count($expected) > self::SNAPSHOT_CAP) {
                $expected = array_slice($expected, count($expected) - self::SNAPSHOT_CAP);
            }

            $this->assertSame(
                $expected,
                $bodies,
                "activeMemories for ({$robotId}, g{$generation}) must return the "
                    . 'surviving active bodies oldest first across all disasters, '
                    . 'excluding retired and other-generation rows'
            );
        }
    }

    /**
     * Recompute which of a (robot, generation) pair's appended bodies are still
     * active, in overall finish order. A body belongs to a triple encoded in
     * its text (`... {disaster} g{gen}`); within each triple only the newest
     * TRIPLE_CAP survive, oldest retired first. The surviving bodies are then
     * returned in the original append (finish) order.
     *
     * @param string[] $appended finish-order bodies for one (robot, generation)
     * @return string[] surviving bodies, oldest first
     */
    private function survivingBodiesInFinishOrder(array $appended): array
    {
        // Group indexes by disaster triple (generation is fixed per pair).
        $byDisaster = [];
        foreach ($appended as $index => $body) {
            $disaster = $this->disasterOf($body);
            $byDisaster[$disaster][] = $index;
        }

        // Within each triple keep only the newest TRIPLE_CAP indexes.
        $survivingIndexes = [];
        foreach ($byDisaster as $indexes) {
            if (count($indexes) > self::TRIPLE_CAP) {
                $indexes = array_slice($indexes, count($indexes) - self::TRIPLE_CAP);
            }
            foreach ($indexes as $index) {
                $survivingIndexes[$index] = true;
            }
        }

        // Emit the survivors in overall finish (append) order.
        $out = [];
        foreach ($appended as $index => $body) {
            if (isset($survivingIndexes[$index])) {
                $out[] = $body;
            }
        }

        return $out;
    }

    /** Read the disaster tag back out of a generated body string. */
    private function disasterOf(string $body): string
    {
        foreach (self::DISASTERS as $disaster) {
            if (str_contains($body, ' ' . $disaster . ' ')) {
                return $disaster;
            }
        }

        return 'earthquake';
    }

    /** Build a draft MemoryRow for a store (seq/status are set by the store). */
    private function row(
        string $robotId,
        int $generation,
        string $disaster,
        string $sourceEpisodeId,
        string $body,
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
