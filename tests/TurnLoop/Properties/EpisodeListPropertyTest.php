<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;
use PoltergeistSwitch\TurnLoop\EpisodeListService;
use PoltergeistSwitch\TurnLoop\Http\ApiError;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\Values\EpisodeRow;
use PoltergeistSwitch\TurnLoop\WorldStateBackfill;

/**
 * Property-based tests for the ended-episode list (Eris).
 *
 * These exercise {@see EpisodeListService} over the shared in-memory
 * repository double and its `listEndedEpisodes` query (screens spec
 * Requirement 11). Episodes are seeded directly as {@see EpisodeRow}s with
 * chosen id, device id, created date, and ended flag, and WITHOUT any turns —
 * so the service's per-episode world backfill is a no-op (an episode with no
 * turns has no missing `world_json`, so {@see WorldStateBackfill::backfill}
 * replays nothing and never throws) and the one-liner degrades to its safe
 * placeholder. That keeps these four properties focused on ordering, the
 * limit, and the no-leak contract, none of which assert the one-liner's
 * content.
 *
 * The ordering oracle mirrors the repository's `listEndedEpisodes` exactly:
 * keep only episodes whose `ended` is true, then sort by created date
 * descending (strcmp on the 'Y-m-d H:i:s' string) and, for a tie, by id
 * descending (strcmp). Equal created dates are generated on purpose so the
 * id-descending tiebreak is exercised.
 *
 * This Eris release (giorgiosironi/eris ^1.3) controls the number of
 * iterations with `limitTo()`, not `withMinSuccesses()`.
 *
 * Feature: screens, Property 4: List returns only ended episodes, newest first
 * Feature: screens, Property 5: Limit bounds the result count
 * Feature: screens, Property 6: Out-of-range limit is rejected with no list
 * Feature: screens, Property 7: List leaks no device id, key, credential, or provider name
 */
class EpisodeListPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Provider words and model names that must never appear in a list body,
     * reusing the forbidden-substring style of
     * {@see \PoltergeistSwitch\Tests\TurnLoop\ApiTest}.
     */
    private const FORBIDDEN_SUBSTRINGS = [
        'Anthropic', 'anthropic',
        'OpenAI', 'openai',
        'Claude', 'claude',
        'GPT', 'gpt',
        'claude-haiku-5-5',
        'gpt-6-luna',
    ];

    /**
     * Feature: screens, Property 4: List returns only ended episodes, newest
     * first.
     *
     * Generate a random mix of ended and not-ended episodes with random created
     * dates (drawn from a small pool so ties occur), random ids, and random
     * device ids, seed them into the repository, and read the list with a
     * high limit so none are dropped. Assert the result ids are EXACTLY the
     * ended episodes' ids, in created-date descending then id descending order
     * — so every not-ended episode is excluded and the order matches the oracle.
     *
     * Validates: Requirements 11.1, 11.2
     */
    public function testListReturnsOnlyEndedNewestFirst(): void
    {
        $this->limitTo(100)
            ->forAll(Generators::int(), Generators::choose(0, 12))
            ->then(function (int $seed, int $count): void {
                $episodes = $this->generateEpisodes($seed, $count);

                $repo = new InMemoryEpisodeRepository();
                foreach ($episodes as $row) {
                    $repo->createEpisode($row);
                }

                $service = $this->makeService($repo);

                // A limit of 100 (the max) so ordering, not the cap, is tested.
                $items = $service->list(100);
                $actualIds = array_map(static fn (array $item): string => $item['id'], $items);

                $expectedIds = $this->orderedEndedIds($episodes, 100);

                $this->assertSame(
                    $expectedIds,
                    $actualIds,
                    "the list must be exactly the ended ids, created-desc then id-desc "
                    . "(seed {$seed}, count {$count})"
                );
            });
    }

    /**
     * Feature: screens, Property 5: Limit bounds the result count.
     *
     * For a random valid limit (1–100 inclusive) the result size is at most the
     * limit and is exactly min(limit, number-of-ended); and when the limit is
     * absent (null) the size is at most 20 (the default) and is exactly
     * min(20, number-of-ended).
     *
     * Validates: Requirements 11.4, 11.5
     */
    public function testLimitBoundsResultCount(): void
    {
        $this->limitTo(100)
            ->forAll(Generators::int(), Generators::choose(0, 40), Generators::choose(1, 100))
            ->then(function (int $seed, int $count, int $limit): void {
                $episodes = $this->generateEpisodes($seed, $count);

                $repo = new InMemoryEpisodeRepository();
                foreach ($episodes as $row) {
                    $repo->createEpisode($row);
                }

                $service = $this->makeService($repo);

                $endedCount = count($this->endedRows($episodes));

                // A supplied limit caps at min(limit, number-of-ended).
                $items = $service->list($limit);
                $this->assertLessThanOrEqual(
                    $limit,
                    count($items),
                    "the result must not exceed the limit (seed {$seed}, limit {$limit})"
                );
                $this->assertSame(
                    min($limit, $endedCount),
                    count($items),
                    "the result size must be min(limit, ended) (seed {$seed}, limit {$limit})"
                );

                // An absent limit caps at min(20, number-of-ended).
                $defaultItems = $service->list(null);
                $this->assertLessThanOrEqual(
                    20,
                    count($defaultItems),
                    "the default result must not exceed 20 (seed {$seed})"
                );
                $this->assertSame(
                    min(20, $endedCount),
                    count($defaultItems),
                    "the default result size must be min(20, ended) (seed {$seed})"
                );
            });
    }

    /**
     * Feature: screens, Property 6: Out-of-range limit is rejected with no list.
     *
     * For a random invalid limit — a non-integer string ("20.0", "abc", a
     * float form), an integer below 1, or an integer above 100 — the service
     * throws the out-of-range {@see ApiError} (HTTP 400, code
     * 'limit_out_of_range') and returns NO list.
     *
     * Validates: Requirements 11.6
     */
    public function testOutOfRangeLimitIsRejectedWithNoList(): void
    {
        $this->limitTo(100)
            ->forAll(Generators::int(), Generators::choose(0, 6), Generators::int())
            ->then(function (int $seed, int $count, int $badSeed): void {
                $episodes = $this->generateEpisodes($seed, $count);

                $repo = new InMemoryEpisodeRepository();
                foreach ($episodes as $row) {
                    $repo->createEpisode($row);
                }

                $service = $this->makeService($repo);

                $badLimit = $this->invalidLimit($badSeed);

                $threw = false;
                $returned = null;
                try {
                    $returned = $service->list($badLimit);
                } catch (ApiError $e) {
                    $threw = true;
                    $this->assertSame(
                        400,
                        $e->status,
                        "an out-of-range limit must be HTTP 400 (seed {$seed}, limit "
                        . var_export($badLimit, true) . ')'
                    );
                    $this->assertSame(
                        'limit_out_of_range',
                        $e->errorCode,
                        "the error code must be limit_out_of_range (seed {$seed}, limit "
                        . var_export($badLimit, true) . ')'
                    );
                }

                $this->assertTrue(
                    $threw,
                    "an invalid limit must be rejected (seed {$seed}, limit "
                    . var_export($badLimit, true) . ')'
                );
                // No list is returned: the throw happened before any list value.
                $this->assertNull(
                    $returned,
                    "no list may be returned for an invalid limit (seed {$seed}, limit "
                    . var_export($badLimit, true) . ')'
                );
            });
    }

    /**
     * Feature: screens, Property 7: List leaks no device id, key, credential,
     * or provider name.
     *
     * Generate ended episodes with random (and deliberately recognisable)
     * device ids, serialise the whole list result to JSON, and assert:
     *
     *   - each item carries only the keys {id, createdDate, turns,
     *     startingSituation} — the four-field shape (Requirement 11.7);
     *   - no episode's device id appears anywhere in the serialised result;
     *   - none of the forbidden provider/model substrings appears.
     *
     * Validates: Requirements 11.7
     */
    public function testListLeaksNoDeviceIdKeyCredentialOrProviderName(): void
    {
        $this->limitTo(100)
            ->forAll(Generators::int(), Generators::choose(1, 12))
            ->then(function (int $seed, int $count): void {
                // Force every episode ended so the list is non-empty and every
                // device id is a candidate leak to scan for.
                $episodes = $this->generateEpisodes($seed, $count, allEnded: true);

                $repo = new InMemoryEpisodeRepository();
                foreach ($episodes as $row) {
                    $repo->createEpisode($row);
                }

                $service = $this->makeService($repo);

                $items = $service->list(100);

                // The item shape is exactly the four fields.
                foreach ($items as $item) {
                    $this->assertSame(
                        ['id', 'createdDate', 'turns', 'startingSituation'],
                        array_keys($item),
                        "each list item must carry only the four fields (seed {$seed})"
                    );
                }

                $json = json_encode($items, JSON_UNESCAPED_UNICODE);
                $this->assertIsString($json, "the list must serialise to JSON (seed {$seed})");

                // No device id appears anywhere in the serialised result.
                foreach ($episodes as $row) {
                    $this->assertStringNotContainsString(
                        $row->deviceId,
                        $json,
                        "device id '{$row->deviceId}' must not leak into the list (seed {$seed})"
                    );
                }

                // No provider/model substring appears.
                foreach (self::FORBIDDEN_SUBSTRINGS as $needle) {
                    $this->assertStringNotContainsString(
                        $needle,
                        $json,
                        "forbidden substring '{$needle}' must not appear in the list (seed {$seed})"
                    );
                }
            });
    }

    // --- helpers ----------------------------------------------------------

    /**
     * Build an {@see EpisodeListService} over the given repository with a real
     * world backfill (a {@see ReplayEngineFactory} over the same repo). For the
     * turn-less episodes these properties seed, the backfill is a no-op, so no
     * AI is ever called and no replay runs.
     */
    private function makeService(InMemoryEpisodeRepository $repo): EpisodeListService
    {
        $backfill = new WorldStateBackfill($repo, new ReplayEngineFactory($repo));

        return new EpisodeListService($repo, $backfill);
    }

    /**
     * Deterministically generate a mix of episodes from $seed: each gets a
     * random id, a random device id, a created date drawn from a small pool (so
     * equal dates occur and the id-descending tiebreak is exercised), and —
     * unless $allEnded is set — a random ended flag. No turns are created, so
     * the service's world backfill stays a no-op. Ids are unique across the mix
     * so the ordering is total and the oracle is unambiguous.
     *
     * @return list<EpisodeRow>
     */
    private function generateEpisodes(int $seed, int $count, bool $allEnded = false): array
    {
        // A tiny LCG seeded from $seed gives reproducible pseudo-random draws
        // without depending on any engine PRNG, keeping these list properties
        // self-contained.
        $state = ($seed & 0x7fffffff) ^ 0x2545f49;
        $next = static function (int $bound) use (&$state): int {
            $state = ($state * 1103515245 + 12345) & 0x7fffffff;

            return $bound <= 0 ? 0 : $state % $bound;
        };

        // A small pool of created dates so ties occur.
        $datePool = [
            '2026-11-01 09:00:00',
            '2026-11-01 09:00:00', // duplicated on purpose for ties
            '2026-11-02 18:30:00',
            '2026-11-03 23:15:00',
            '2026-11-03 23:15:00', // duplicated on purpose for ties
            '2026-11-04 07:45:00',
        ];

        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            // Unique, zero-padded id so string ordering is stable and total.
            $id = 'ep_' . str_pad((string) $i, 3, '0', STR_PAD_LEFT)
                . '_' . str_pad((string) $next(1000), 3, '0', STR_PAD_LEFT);
            $deviceId = 'device-' . str_pad((string) $next(100000), 6, '0', STR_PAD_LEFT);
            $createdAt = $datePool[$next(count($datePool))];
            $ended = $allEnded ? true : ($next(2) === 1);

            $rows[] = new EpisodeRow(
                id: $id,
                deviceId: $deviceId,
                disaster: 'earthquake',
                seed: $next(1_000_000),
                currentExpectedTurn: 0,
                earthquakeStartTurn: null,
                ended: $ended,
                createdAt: $createdAt,
                lastActivityAt: $createdAt,
            );
        }

        return $rows;
    }

    /**
     * The oracle: the ended episodes' ids in created-date descending then
     * id-descending order, capped at $limit. Mirrors the repository's
     * `listEndedEpisodes` ordering exactly.
     *
     * @param list<EpisodeRow> $episodes
     * @return list<string>
     */
    private function orderedEndedIds(array $episodes, int $limit): array
    {
        $ended = $this->endedRows($episodes);

        usort($ended, static function (EpisodeRow $a, EpisodeRow $b): int {
            $byDate = strcmp($b->createdAt, $a->createdAt);
            if ($byDate !== 0) {
                return $byDate;
            }

            return strcmp($b->id, $a->id);
        });

        $ids = array_map(static fn (EpisodeRow $row): string => $row->id, $ended);

        return array_slice($ids, 0, $limit);
    }

    /**
     * @param list<EpisodeRow> $episodes
     * @return list<EpisodeRow>
     */
    private function endedRows(array $episodes): array
    {
        return array_values(array_filter(
            $episodes,
            static fn (EpisodeRow $row): bool => $row->ended
        ));
    }

    /**
     * Deterministically pick one invalid limit from $badSeed: a non-integer
     * string (a float form, an exponent form, a word), an integer below 1, or
     * an integer above 100. Every value here is rejected by the service's
     * limit validation (Requirement 11.6).
     *
     * @return mixed
     */
    private function invalidLimit(int $badSeed): mixed
    {
        $candidates = [
            '20.0',   // fractional string: not an integer
            '2e1',    // exponent string: not an integer
            'abc',    // non-numeric string
            '',       // empty string
            ' 20',    // leading space
            0,        // below the minimum of 1
            -1,       // below the minimum of 1
            -50,      // below the minimum of 1
            101,      // above the maximum of 100
            1000,     // above the maximum of 100
        ];

        return $candidates[abs($badSeed) % count($candidates)];
    }
}
