<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\Engine;
use PoltergeistSwitch\Engine\WorldModel;
use PoltergeistSwitch\Memory\MemoryService;
use PoltergeistSwitch\TurnLoop\Clients\FakeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\DailyLimitPolicy;
use PoltergeistSwitch\TurnLoop\EngineFactory;
use PoltergeistSwitch\TurnLoop\EpisodeStarter;
use PoltergeistSwitch\TurnLoop\Http\ApiError;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\TurnService;
use PoltergeistSwitch\TurnLoop\UsageKeys;

/**
 * EpisodeStarter over the in-memory fakes and fake clients (no network, no AI).
 *
 * Requirements: 13.4, 13.5, 13.6, 13.7, 13.8, 13.9, 13.10, 14.17, 14.18
 */
class EpisodeStarterTest extends TestCase
{
    private const T0 = 1_800_000_000; // 2027-01-15 08:00:00 UTC

    private InMemoryEpisodeRepository $repo;
    private InMemoryUsageRepository $usage;
    private EpisodeStarter $starter;
    private int $now = self::T0;
    private bool $failCreate = false;
    private int $n = 0;

    protected function setUp(): void
    {
        $this->build(new Config(maxRunningEpisodes: 1000));
    }

    private function build(Config $config): void
    {
        $this->repo = new InMemoryEpisodeRepository($config);
        $clock = fn (): int => $this->now;
        $this->usage = new InMemoryUsageRepository($clock);

        $real = new ReplayEngineFactory($this->repo);
        $failing = function () {
            return $this->failCreate;
        };
        $factory = new class ($real, $failing) implements EngineFactory {
            /** @param callable():bool $fail */
            public function __construct(private EngineFactory $inner, private $fail)
            {
            }

            public function forEpisode(string $episodeId): Engine
            {
                return $this->inner->forEpisode($episodeId);
            }

            public function forEpisodeWithWorld(string $episodeId): array
            {
                if (($this->fail)()) {
                    throw new \RuntimeException('engine failure');
                }
                return $this->inner->forEpisodeWithWorld($episodeId);
            }

            public function worldForEpisode(string $episodeId): WorldModel
            {
                return $this->inner->worldForEpisode($episodeId);
            }

            public function worldsPerTurnForEpisode(string $episodeId, callable $serialize): array
            {
                return $this->inner->worldsPerTurnForEpisode($episodeId, $serialize);
            }
        };

        $turns = new TurnService(
            new FakeRobotClient(),
            new FakeRobotClient(),
            new FakeRecorderClient(),
            $factory,
            $this->repo,
            $config,
        );
        $this->starter = new EpisodeStarter(
            $turns,
            new MemoryService($this->repo),
            $this->usage,
            DailyLimitPolicy::fromConfig($config),
            $clock,
        );
    }

    /** Start with a fresh Device each time; $keys decides which scopes are shared. */
    private function startWith(UsageKeys $keys, ?string $device = null): void
    {
        $this->starter->start($device ?? 'dev_' . (++$this->n), $keys);
    }

    private function fresh(string $prefix): string
    {
        return $prefix . str_pad((string) (++$this->n), 8, '0', STR_PAD_LEFT);
    }

    private function assertDenied(string $code, callable $start): void
    {
        $before = count($this->repo->listEndedEpisodes(1000)) + $this->repo->countRunningEpisodes();
        $rowsBefore = $this->usage->rows;
        try {
            $start();
            $this->fail('expected 429 ' . $code);
        } catch (ApiError $e) {
            $this->assertSame(429, $e->status);
            $this->assertSame($code, $e->errorCode);
            $this->assertSame(['error' => $code], $e->toArray());
        }
        $this->assertSame($before, count($this->repo->listEndedEpisodes(1000)) + $this->repo->countRunningEpisodes());
        $this->assertSame($rowsBefore, $this->usage->rows);
    }

    public function testDeviceLimitTenthSucceedsEleventhDenied(): void
    {
        $keys = new UsageKeys('d1', 'ip1');
        for ($i = 0; $i < 10; $i++) {
            $this->startWith($keys);
        }
        $this->assertSame(10, $this->usage->counts('2027-01-15', $keys)['device']);
        $this->assertDenied('daily_device_limit', fn () => $this->startWith($keys));
    }

    public function testIpLimitThirtiethSucceedsThirtyFirstDenied(): void
    {
        // A different device key each time, one shared IP.
        for ($i = 0; $i < 30; $i++) {
            $this->startWith(new UsageKeys($this->fresh('d'), 'ipShared'));
        }
        $this->assertDenied('daily_ip_limit', fn () => $this->startWith(new UsageKeys($this->fresh('d'), 'ipShared')));
    }

    public function testSiteLimitHundredthSucceedsHundredFirstDenied(): void
    {
        // Different device and IP every time, so only the site scope fills.
        for ($i = 0; $i < 100; $i++) {
            $this->startWith(new UsageKeys($this->fresh('d'), $this->fresh('i')));
        }
        $this->assertDenied(
            'daily_site_limit',
            fn () => $this->startWith(new UsageKeys($this->fresh('d'), $this->fresh('i'))),
        );
    }

    public function testUntrackedIpIsNotCountedButDeviceAndSiteAre(): void
    {
        $keys = new UsageKeys('dNoIp', null);
        for ($i = 0; $i < 10; $i++) {
            $this->startWith($keys);
        }
        $this->assertNull($this->usage->counts('2027-01-15', $keys)['ip']);
        $this->assertDenied('daily_device_limit', fn () => $this->startWith($keys));
    }

    public function testErrorOrderIsDeviceThenIpThenSite(): void
    {
        $high = new DailyLimitPolicy(1000, 1000, 1000);
        $day = '2027-01-15';
        $now = gmdate('Y-m-d H:i:s', self::T0);

        // Fill everything: device, ip and site all at their limits -> device wins.
        $all = new UsageKeys('dA', 'ipA');
        for ($i = 0; $i < 100; $i++) {
            $this->usage->tryReserve($day, $all, $high, $now);
        }
        $this->assertDenied('daily_device_limit', fn () => $this->startWith($all));

        // New device, same IP (already at 100): ip limit before site limit.
        $this->assertDenied('daily_ip_limit', fn () => $this->startWith(new UsageKeys('dB', 'ipA')));

        // New device and IP: only the site is full.
        $this->assertDenied('daily_site_limit', fn () => $this->startWith(new UsageKeys('dC', 'ipC')));
    }

    public function testCountsRestartAtUtcMidnight(): void
    {
        $keys = new UsageKeys('dMid', 'ipMid');
        $this->now = gmdate_to_ts('2027-01-15 23:59:58');
        for ($i = 0; $i < 10; $i++) {
            $this->startWith($keys);
        }
        $this->assertDenied('daily_device_limit', fn () => $this->startWith($keys));

        $this->now = gmdate_to_ts('2027-01-16 00:00:00');
        $this->startWith($keys);
        $this->assertSame(1, $this->usage->counts('2027-01-16', $keys)['device']);
        $this->assertSame(10, $this->usage->counts('2027-01-15', $keys)['device']);
    }

    public function testRunningEpisodeConflictComesBeforeLimits(): void
    {
        $keys = new UsageKeys('dRun', 'ipRun');
        $first = $this->starter->start('dev_running', $keys);
        // Fill the device, ip and site scopes completely.
        $high = new DailyLimitPolicy(1000, 1000, 1000);
        for ($i = 0; $i < 100; $i++) {
            $this->usage->tryReserve('2027-01-15', $keys, $high, gmdate('Y-m-d H:i:s', self::T0));
        }
        $rows = $this->usage->rows;

        try {
            $this->starter->start('dev_running', $keys);
            $this->fail('expected 409');
        } catch (ApiError $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame('device_already_running', $e->errorCode);
            $this->assertSame($first->episodeId, $e->episodeId);
        }
        $this->assertSame($rows, $this->usage->rows, 'a 409 must not touch the counts');
    }

    public function testTooManyRunningComesBeforeLimits(): void
    {
        $this->build(new Config(maxRunningEpisodes: 1));
        $this->starter->start('dev_one', new UsageKeys('dX', 'ipX'));
        try {
            $this->starter->start('dev_two', new UsageKeys('dX', 'ipX'));
            $this->fail('expected 409');
        } catch (ApiError $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame('too_many_running', $e->errorCode);
        }
        $this->assertSame(1, $this->usage->counts('2027-01-15', new UsageKeys('dX', 'ipX'))['device']);
    }

    public function testRunningEpisodeIsUnaffectedWhenALimitIsReached(): void
    {
        $this->build(new Config(maxRunningEpisodes: 1000, dailyDeviceLimit: 2, dailyIpLimit: 30, dailySiteLimit: 100));
        $keys = new UsageKeys('dKeep', 'ipKeep');
        $running = $this->starter->start('dev_keep', $keys);
        $this->startWith($keys);
        $this->assertDenied('daily_device_limit', fn () => $this->startWith($keys));

        $episode = $this->repo->findEpisode($running->episodeId);
        $this->assertNotNull($episode);
        $this->assertFalse($episode->ended);
        $this->assertSame($running->episodeId, $this->repo->findRunningEpisodeForDevice('dev_keep')?->id);
    }

    public function testFailingCreateEpisodeReleasesTheCountAndRethrows(): void
    {
        $keys = new UsageKeys('dFail', 'ipFail');
        $this->startWith($keys);
        $this->assertSame(1, $this->usage->counts('2027-01-15', $keys)['device']);

        $this->failCreate = true;
        try {
            $this->starter->start('dev_fail', $keys);
            $this->fail('expected the engine failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('engine failure', $e->getMessage());
        }
        $counts = $this->usage->counts('2027-01-15', $keys);
        $this->assertSame(1, $counts['device']);
        $this->assertSame(1, $counts['ip']);
        $this->assertSame(1, $counts['site']);

        // The released slot is usable again.
        $this->failCreate = false;
        $this->startWith($keys);
        $this->assertSame(2, $this->usage->counts('2027-01-15', $keys)['device']);
    }

    public function testNullKeysCountNothing(): void
    {
        $this->starter->start('dev_nokeys', null);
        $this->assertSame([], $this->usage->rows);
    }
}

/** Unix seconds for a UTC "Y-m-d H:i:s" string. */
function gmdate_to_ts(string $utc): int
{
    return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->getTimestamp();
}
