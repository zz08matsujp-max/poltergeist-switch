<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;
use PoltergeistSwitch\TurnLoop\Clients\FakeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\EpisodeListService;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\TurnService;
use PoltergeistSwitch\TurnLoop\Values\EpisodeRow;
use PoltergeistSwitch\TurnLoop\WorldStateBackfill;

/**
 * Feature: public-lab, Property 9: Episode list grouping
 *
 * Over random episodes across three Devices (ended and not), random Reset_Points
 * and a random Featured_List (including ids that do not exist or have not
 * ended), the list has:
 *   - Featured: the configured ids that exist and have ended, in configured
 *     order, for any caller (cookie or not);
 *   - Your runs: the caller's own ended episodes created after its latest
 *     reset, newest first, capped at the limit, with every Featured id left out;
 *   - no other Device's non-featured episode in any group;
 *   - no id in both groups, and no duplicate within a group.
 * An episode removed from "Your runs" by a reset is still returned by
 * TurnService::getEpisode (the logic behind GET /api/episodes/{id}).
 *
 * Created times are strictly distinct and never equal to a Reset_Point, so the
 * "after the reset" boundary is unambiguous.
 *
 * **Validates: Requirements 6.2, 6.3, 6.4, 6.5, 6.6, 6.7, 6.8**
 */
class EpisodeListGroupsPropertyTest extends TestCase
{
    use TestTrait;

    private const DEVICES = ['dev-one', 'dev-two', 'dev-three'];
    private const BASE = 1_793_000_000;

    /**
     * Feature: public-lab, Property 9: Episode list grouping
     */
    public function testEpisodeListGrouping(): void
    {
        $this->limitTo(100)
            ->forAll(Generators::int(), Generators::choose(0, 14), Generators::choose(1, 8))
            ->then(function (int $seed, int $count, int $limit): void {
                $state = ($seed & 0x7fffffff) ^ 0x1b873593;
                $next = static function (int $bound) use (&$state): int {
                    $state = ($state * 1103515245 + 12345) & 0x7fffffff;

                    return $bound <= 0 ? 0 : ($state >> 8) % $bound;
                };

                // A pool of UUIDs; some become episodes, others stay absent.
                $pool = [];
                for ($i = 0; $i < 16; $i++) {
                    $pool[] = sprintf('00000000-0000-4000-8000-%012d', $i);
                }

                // Episodes: pool[0..count-1]; created times strictly increasing,
                // 10 s apart, so a reset at +5 s never ties with a created time.
                $repo = new InMemoryEpisodeRepository();
                $rows = [];
                for ($i = 0; $i < $count; $i++) {
                    $createdAt = gmdate('Y-m-d H:i:s', self::BASE + $i * 10);
                    $row = new EpisodeRow(
                        id: $pool[$i],
                        deviceId: self::DEVICES[$next(3)],
                        disaster: 'earthquake',
                        seed: $next(1_000_000),
                        currentExpectedTurn: 0,
                        earthquakeStartTurn: null,
                        ended: $next(3) !== 0,
                        createdAt: $createdAt,
                        lastActivityAt: $createdAt,
                    );
                    $repo->createEpisode($row);
                    $rows[$row->id] = $row;
                }

                // Featured_List: up to 6 distinct ids from the whole pool (so
                // some are missing or unended), in a random order.
                $featured = [];
                $howMany = $next(7);
                while (count($featured) < $howMany) {
                    $candidate = $pool[$next(count($pool))];
                    if (!in_array($candidate, $featured, true)) {
                        $featured[] = $candidate;
                    }
                }

                // Reset_Points: each Device gets 0-2 resets at +5 s offsets.
                $latestReset = [];
                foreach (self::DEVICES as $device) {
                    $resets = $next(3);
                    $offsets = [];
                    for ($r = 0; $r < $resets; $r++) {
                        $offsets[] = $next(max(1, $count + 1)) * 10 + 5;
                    }
                    sort($offsets);
                    foreach ($offsets as $offset) {
                        $repo->recordDeviceReset($device, gmdate('Y-m-d H:i:s', self::BASE + $offset - 10));
                    }
                    $latestReset[$device] = $offsets === []
                        ? null
                        : gmdate('Y-m-d H:i:s', self::BASE + end($offsets) - 10);
                }

                $config = new Config(featuredEpisodeIds: $featured);
                $service = new EpisodeListService(
                    $repo,
                    new WorldStateBackfill($repo, new ReplayEngineFactory($repo)),
                    $config,
                );
                $turns = new TurnService(
                    new FakeRobotClient(),
                    new FakeRobotClient(),
                    new FakeRecorderClient(),
                    new ReplayEngineFactory($repo),
                    $repo,
                    $config,
                );

                // Oracle: Featured.
                $expectedFeatured = [];
                foreach ($featured as $id) {
                    if (isset($rows[$id]) && $rows[$id]->ended) {
                        $expectedFeatured[] = $id;
                    }
                }

                $callers = array_merge([null], self::DEVICES);
                foreach ($callers as $caller) {
                    $label = "seed {$seed}, count {$count}, limit {$limit}, caller " . ($caller ?? 'none');
                    $result = $service->list($limit, $caller);

                    $featuredIds = array_map(static fn (array $i): string => $i['id'], $result['featured']);
                    $yoursIds = array_map(static fn (array $i): string => $i['id'], $result['yours']);

                    // Featured is the same for every caller, in configured order.
                    $this->assertSame($expectedFeatured, $featuredIds, "featured order ({$label})");

                    // Your runs: oracle.
                    $expectedYours = [];
                    if ($caller !== null) {
                        $mine = array_filter(
                            $rows,
                            static fn (EpisodeRow $r): bool => $r->ended
                                && $r->deviceId === $caller
                                && ($latestReset[$caller] === null || strcmp($r->createdAt, $latestReset[$caller]) > 0)
                                && !in_array($r->id, $featured, true)
                        );
                        usort($mine, static fn (EpisodeRow $a, EpisodeRow $b): int => strcmp($b->createdAt, $a->createdAt));
                        $expectedYours = array_slice(array_map(static fn (EpisodeRow $r): string => $r->id, $mine), 0, $limit);
                    }
                    $this->assertSame($expectedYours, $yoursIds, "your runs ({$label})");

                    // No other Device's non-featured run in any group.
                    foreach (array_merge($featuredIds, $yoursIds) as $id) {
                        $this->assertTrue(
                            in_array($id, $featured, true) || $rows[$id]->deviceId === $caller,
                            "foreign run {$id} leaked ({$label})"
                        );
                    }

                    // No duplicates, and no id in both groups.
                    $this->assertSame($featuredIds, array_values(array_unique($featuredIds)), "dup in featured ({$label})");
                    $this->assertSame($yoursIds, array_values(array_unique($yoursIds)), "dup in yours ({$label})");
                    $this->assertSame([], array_values(array_intersect($featuredIds, $yoursIds)), "id in both groups ({$label})");

                    // A reset-removed run is gone from "Your runs" but still readable by id.
                    if ($caller !== null && $latestReset[$caller] !== null) {
                        foreach ($rows as $row) {
                            if ($row->ended
                                && $row->deviceId === $caller
                                && strcmp($row->createdAt, $latestReset[$caller]) <= 0
                                && !in_array($row->id, $featured, true)
                            ) {
                                $this->assertNotContains($row->id, $yoursIds, "removed run still listed ({$label})");
                                $view = $turns->getEpisode($row->id);
                                $this->assertSame($row->id, $view->episodeId, "removed run unreadable ({$label})");
                                $this->assertTrue($view->ended, "removed run lost its end ({$label})");
                            }
                        }
                    }
                }
            });
    }
}
