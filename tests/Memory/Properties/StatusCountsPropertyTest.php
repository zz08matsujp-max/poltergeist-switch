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
 * Feature: robot-memory, Property 8: Status reports exactly the current
 * generation's active counts and lengths.
 *
 * For any memory store, {@see MemoryService::status} reports the current
 * generation and, per robot reported by its screen name Amber (robot_a) and
 * Jade (robot_b), the count and the character lengths of that robot's
 * Active_Memory of the current generation, oldest first, excluding every
 * retired and older-generation memory (Requirements 5.2, 5.3).
 *
 * The property is driven against the in-memory EpisodeRepository fake (the PDO
 * path's logic mirror). An arbitrary store is seeded across both robots,
 * several disasters, the current generation, and an older generation, with
 * multibyte (Japanese) and ASCII bodies mixed in and enough per-triple volume
 * to force retirement. The status is then compared against an independently
 * computed expectation:
 *   - the reported generation equals the store's current generation;
 *   - each robot's count equals the number of its current-generation active
 *     memories across all disasters, oldest first (never counting a retired or
 *     older-generation row);
 *   - each robot's lengths list equals the character length (mb_strlen, so a
 *     Japanese body is measured in characters not bytes, matching the service)
 *     of each of those active bodies, in the same oldest-first order.
 *
 * The expectation is built from `activeMemories($robot, $currentGeneration,
 * PHP_INT_MAX)` — an uncapped read — so a cap bug on the status path (which
 * reads with the 18 snapshot cap) is still catchable: the two reads use
 * different caps and must agree when the active set is at or below 18, which it
 * always is here (at most 6 active per triple × 3 disasters is 18).
 *
 * NOTE: this Eris release (giorgiosironi/eris ^1.3) controls the iteration
 * count with `limitTo()`; `limitTo(100)` is required here, matching the other
 * property tests.
 *
 * Validates: Requirements 9.3, 5.2, 5.3
 */
class StatusCountsPropertyTest extends TestCase
{
    use TestTrait;

    private const ROBOTS = ['robot_a', 'robot_b'];

    private const DISASTERS = ['earthquake', 'heavy_rain', 'heat_wave'];

    /**
     * A pool of candidate bodies mixing ASCII and multibyte (Japanese) text so
     * the length measurement proves it counts characters (mb_strlen), not
     * bytes. Each has a distinct character length.
     */
    private const BODY_POOL = [
        'ok',                      // 2
        'remember',                // 8
        'the stove was off',       // 17
        'ゆれた',                    // 3 characters, 9 bytes in UTF-8
        '机の下に入った',              // 7 characters, multibyte
        'I helped the old man.',   // 21
    ];

    /**
     * Property 8: status reports the current generation and, per robot, the
     * current-generation active count and each active body's character length,
     * oldest first, excluding retired and older-generation rows.
     *
     * Validates: Requirements 9.3, 5.2, 5.3
     */
    public function testStatusReportsCurrentGenerationActiveCountsAndLengths(): void
    {
        $this->limitTo(100)
            ->forAll(
                // 0..30 memories to seed: zero exercises the empty-status case,
                // a large count forces retirement within triples.
                Generators::choose(0, 30),
                // A seed to spread memories deterministically so Eris shrinks
                // and replays the same plan.
                Generators::int(),
            )
            ->then(function (int $memoryCount, int $spread): void {
                $repo = new InMemoryEpisodeRepository();
                $service = new MemoryService($repo);

                $currentGeneration = $repo->currentGeneration();

                // Seed the store, routing some rows to an OLDER generation so
                // the older-generation exclusion is exercised (Req 5.3).
                $this->seedMemories($repo, $currentGeneration, $spread, $memoryCount);

                $status = $service->status();

                // The reported generation is the store's current generation.
                $this->assertSame(
                    $currentGeneration,
                    $status->generation,
                    "status must report the current generation "
                        . "(count {$memoryCount}, spread {$spread})"
                );

                // Independently expected active bodies per robot: the current
                // generation's active rows across all disasters, oldest first,
                // excluding retired and older-generation rows.
                $expectedA = $repo->activeMemories('robot_a', $currentGeneration, PHP_INT_MAX)->bodies;
                $expectedB = $repo->activeMemories('robot_b', $currentGeneration, PHP_INT_MAX)->bodies;

                // Count = number of those active bodies.
                $this->assertSame(
                    count($expectedA),
                    $status->amberActiveCount,
                    "Amber's active count must be its current-generation active total "
                        . "(count {$memoryCount}, spread {$spread})"
                );
                $this->assertSame(
                    count($expectedB),
                    $status->jadeActiveCount,
                    "Jade's active count must be its current-generation active total "
                        . "(count {$memoryCount}, spread {$spread})"
                );

                // Lengths = mb_strlen of each active body, oldest first — so a
                // multibyte body is measured in characters, matching the
                // service (Req 5.2).
                $this->assertSame(
                    $this->lengthsOf($expectedA),
                    $status->amberLengths,
                    "Amber's lengths must be the mb_strlen of each active body oldest first "
                        . "(count {$memoryCount}, spread {$spread})"
                );
                $this->assertSame(
                    $this->lengthsOf($expectedB),
                    $status->jadeLengths,
                    "Jade's lengths must be the mb_strlen of each active body oldest first "
                        . "(count {$memoryCount}, spread {$spread})"
                );

                // The count and the lengths list agree in cardinality.
                $this->assertCount($status->amberActiveCount, $status->amberLengths);
                $this->assertCount($status->jadeActiveCount, $status->jadeLengths);
            });
    }

    /**
     * The mb_strlen of each body, oldest first, preserving order — the exact
     * measure MemoryService::status uses (Req 5.2).
     *
     * @param string[] $bodies
     * @return int[]
     */
    private function lengthsOf(array $bodies): array
    {
        return array_map(static fn (string $b): int => mb_strlen($b), $bodies);
    }

    /**
     * Seed up to $memoryCount memories spread across both robots, several
     * disasters, and two generations (some routed to currentGeneration - 1 so
     * the older-generation exclusion bites), with bodies drawn from a mixed
     * ASCII/multibyte pool. Appends go through appendMemory so retirement runs
     * within triples, giving retired rows the status must exclude.
     */
    private function seedMemories(
        InMemoryEpisodeRepository $repo,
        int $currentGeneration,
        int $spread,
        int $memoryCount
    ): void {
        $poolSize = count(self::BODY_POOL);

        for ($i = 0; $i < $memoryCount; $i++) {
            $robot = self::ROBOTS[($spread + $i) % 2 === 0 ? 0 : 1];
            $disaster = self::DISASTERS[abs($spread + $i * 5) % count(self::DISASTERS)];
            // Route roughly one in four to an older generation so it is
            // excluded from the current-generation status.
            $generation = (($spread + $i) % 4 === 0)
                ? $currentGeneration - 1
                : $currentGeneration;

            $body = self::BODY_POOL[abs($spread + $i * 3) % $poolSize];

            $repo->appendMemory(new MemoryRow(
                robotId: $robot,
                generation: $generation,
                disaster: $disaster,
                sourceEpisodeId: 'src_' . $i,
                body: $body,
                status: 'active',
                createdAt: date('Y-m-d H:i:s'),
            ));
        }
    }
}
