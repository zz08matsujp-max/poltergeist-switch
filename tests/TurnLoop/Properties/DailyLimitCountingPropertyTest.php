<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryUsageRepository;
use PoltergeistSwitch\TurnLoop\DailyLimitPolicy;
use PoltergeistSwitch\TurnLoop\UsageKeys;

/**
 * Feature: public-lab, Property 11: Daily counting matches a simple model
 *
 * For any sequence of start attempts (Device, optional IP key, UTC timestamp)
 * and any positive limits, the repository admits an attempt iff the Device, IP
 * and site counts for that UTC day are all below their limits; a denial returns
 * the first reached of daily_device_limit, daily_ip_limit, daily_site_limit and
 * changes no count; an admission increments exactly the tracked scopes by one;
 * a new UTC day starts from zero.
 *
 * Validates: Requirements 13.3, 13.4, 13.5, 13.6, 13.7, 13.10
 */
class DailyLimitCountingPropertyTest extends TestCase
{
    use TestTrait;

    private const DAY_START = 1794700800; // 2026-11-15 00:00:00 UTC

    /**
     * Feature: public-lab, Property 11: Daily counting matches a simple model
     *
     * Validates: Requirements 13.3, 13.4, 13.5, 13.6, 13.7, 13.10
     */
    public function testDailyCountingMatchesASimpleModel(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generators::choose(1, 4), // device limit
                Generators::choose(1, 6), // ip limit
                Generators::choose(1, 10), // site limit
                Generators::seq(
                    Generators::tuple(
                        Generators::choose(0, 2), // device index
                        Generators::choose(0, 3), // ip index; 3 = no IP key
                        Generators::choose(0, 2), // day index
                        Generators::choose(0, 86399), // second within the day
                    )
                )
            )
            ->then(function (int $dl, int $il, int $sl, array $attempts): void {
                $policy = new DailyLimitPolicy($dl, $il, $sl);
                $repo = new InMemoryUsageRepository();
                /** @var array<string,int> $model keyed "day|scope|key" */
                $model = [];

                foreach ($attempts as [$d, $i, $dayIdx, $sec]) {
                    $ts = self::DAY_START + $dayIdx * 86400 + $sec;
                    $day = DailyLimitPolicy::utcDay($ts);
                    $dk = str_repeat((string) $d, 64);
                    $ik = $i === 3 ? null : str_repeat((string) ($i + 3), 64);
                    $keys = new UsageKeys($dk, $ik);

                    $dc = $model["$day|device|$dk"] ?? 0;
                    $ic = $ik === null ? null : ($model["$day|ip|$ik"] ?? 0);
                    $sc = $model["$day|site|" . UsageKeys::SITE_KEY] ?? 0;

                    $expected = null;
                    if ($dc >= $dl) {
                        $expected = 'daily_device_limit';
                    } elseif ($ic !== null && $ic >= $il) {
                        $expected = 'daily_ip_limit';
                    } elseif ($sc >= $sl) {
                        $expected = 'daily_site_limit';
                    }

                    $before = $repo->rows;
                    $actual = $repo->tryReserve($day, $keys, $policy, gmdate('Y-m-d H:i:s', $ts));
                    $this->assertSame($expected, $actual);

                    if ($expected === null) {
                        $model["$day|device|$dk"] = $dc + 1;
                        if ($ik !== null) {
                            $model["$day|ip|$ik"] = $ic + 1;
                        }
                        $model["$day|site|" . UsageKeys::SITE_KEY] = $sc + 1;
                    } else {
                        $this->assertSame(
                            array_map(static fn (array $r): int => $r['starts'], $before),
                            array_map(static fn (array $r): int => $r['starts'], $repo->rows),
                            'a denial must change no count'
                        );
                    }

                    // Stored counts equal the model for every row ever admitted.
                    $actualCounts = array_map(static fn (array $r): int => $r['starts'], $repo->rows);
                    ksort($actualCounts);
                    $expectedCounts = $model;
                    ksort($expectedCounts);
                    $this->assertSame($expectedCounts, $actualCounts);

                    // counts() agrees with the model for this attempt's keys.
                    $this->assertSame(
                        [
                            'device' => $model["$day|device|$dk"] ?? 0,
                            'ip' => $ik === null ? null : ($model["$day|ip|$ik"] ?? 0),
                            'site' => $model["$day|site|" . UsageKeys::SITE_KEY] ?? 0,
                        ],
                        $repo->counts($day, $keys)
                    );
                }
            });
    }

    /**
     * The (limit)th attempt is admitted and the (limit+1)th denied, per scope.
     *
     * Validates: Requirements 13.4, 13.5, 13.6
     */
    public function testBoundaryAttemptsPerScope(): void
    {
        $this->limitTo(100)
            ->forAll(Generators::choose(1, 8))
            ->then(function (int $limit): void {
                $day = '2026-11-15';
                $now = '2026-11-15 12:00:00';
                $big = 1000;
                $cases = [
                    ['daily_device_limit', new DailyLimitPolicy($limit, $big, $big), 'device'],
                    ['daily_ip_limit', new DailyLimitPolicy($big, $limit, $big), 'ip'],
                    ['daily_site_limit', new DailyLimitPolicy($big, $big, $limit), 'site'],
                ];
                foreach ($cases as [$code, $policy]) {
                    $repo = new InMemoryUsageRepository();
                    $keys = new UsageKeys(str_repeat('a', 64), str_repeat('b', 64));
                    for ($n = 1; $n <= $limit; $n++) {
                        $this->assertNull($repo->tryReserve($day, $keys, $policy, $now), "attempt $n of $limit");
                    }
                    $this->assertSame($code, $repo->tryReserve($day, $keys, $policy, $now));
                    // A fresh UTC day sees zero.
                    $this->assertNull($repo->tryReserve('2026-11-16', $keys, $policy, $now));
                }
            });
    }
}
