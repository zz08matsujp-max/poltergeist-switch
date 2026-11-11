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
use PoltergeistSwitch\TurnLoop\IpHasher;
use PoltergeistSwitch\TurnLoop\Judge\JudgeCookie;
use PoltergeistSwitch\TurnLoop\Judge\JudgePass;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\TurnService;
use PoltergeistSwitch\TurnLoop\UsageKeys;

require_once __DIR__ . '/../../api/bootstrap.php';

/**
 * The judge start branch over the in-memory fakes and an injected clock
 * (public-lab Requirements 21.21 to 21.24, 21.26, 21.27, 21.29, 14.36).
 * All identifiers, passes and keys are generated; no AI call, no DB.
 */
class JudgeStartTest extends TestCase
{
    private const T0 = 1_800_000_000; // 2027-01-15 08:00:00 UTC
    private const DAY = '2027-01-15';

    private InMemoryEpisodeRepository $repo;
    private InMemoryUsageRepository $usage;
    private TurnService $turns;
    private MemoryService $memory;
    private EpisodeStarter $starter;
    private Config $config;
    private int $now = self::T0;
    private bool $failCreate = false;
    private string $dir = '';

    protected function setUp(): void
    {
        $this->build(new Config());
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
                if (is_file($f)) {
                    unlink($f);
                }
            }
            rmdir($this->dir);
            $this->dir = '';
        }
    }

    private function build(Config $config): void
    {
        $this->config = $config;
        $this->repo = new InMemoryEpisodeRepository($config);
        $clock = fn (): int => $this->now;
        $this->usage = new InMemoryUsageRepository($clock);

        $real = new ReplayEngineFactory($this->repo);
        $fail = fn (): bool => $this->failCreate;
        $factory = new class ($real, $fail) implements EngineFactory {
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

        $this->turns = new TurnService(
            new FakeRobotClient(),
            new FakeRobotClient(),
            new FakeRecorderClient(),
            $factory,
            $this->repo,
            $config,
        );
        $this->memory = new MemoryService($this->repo);
        $this->starter = new EpisodeStarter(
            $this->turns,
            $this->memory,
            $this->usage,
            DailyLimitPolicy::fromConfig($config),
            $clock,
        );
    }

    private static function device(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function keys(?string $device = null, ?string $ip = null): UsageKeys
    {
        return new UsageKeys($device ?? bin2hex(random_bytes(32)), $ip ?? bin2hex(random_bytes(32)));
    }

    private function judgeStart(?string $device = null, string $disaster = 'earthquake'): \PoltergeistSwitch\TurnLoop\Values\EpisodeView
    {
        return $this->starter->start($device ?? self::device(), self::keys(), $disaster, true);
    }

    private function episodeTotal(): int
    {
        return count($this->repo->listEndedEpisodes(10000)) + $this->repo->countRunningEpisodes();
    }

    /** @return array<string,array{starts:int, updatedAt:string}> every row except the judge scope */
    private function nonJudgeRows(): array
    {
        return array_filter(
            $this->usage->rows,
            static fn (string $k): bool => !str_contains($k, '|judge|'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function endRunningOf(string $device): void
    {
        $row = $this->repo->findRunningEpisodeForDevice($device);
        $this->assertNotNull($row);
        $this->repo->markEpisodeEnded($row->id);
    }

    private function assertJudgeDenied(callable $start): void
    {
        $before = $this->episodeTotal();
        $rows = $this->usage->rows;
        try {
            $start();
            $this->fail('expected 429 daily_judge_limit');
        } catch (ApiError $e) {
            $this->assertSame(429, $e->status);
            $this->assertSame('daily_judge_limit', $e->errorCode);
            $this->assertSame(['error' => 'daily_judge_limit'], $e->toArray());
        }
        $this->assertSame($before, $this->episodeTotal(), 'nothing is created');
        $this->assertSame($rows, $this->usage->rows, 'the count is unchanged');
    }

    // --- EpisodeStarter on the fakes ---------------------------------------

    public function testJudgeStartIgnoresReachedDeviceIpAndSiteLimitsAndLeavesTheirCounts(): void
    {
        $this->build(new Config(dailyDeviceLimit: 1, dailyIpLimit: 1, dailySiteLimit: 1));
        $keys = self::keys();
        $first = self::device();
        $this->starter->start($first, $keys);
        $this->endRunningOf($first);
        $this->assertSame(['device' => 1, 'ip' => 1, 'site' => 1], $this->usage->counts(self::DAY, $keys));

        // The ordinary path is now refused ...
        try {
            $this->starter->start(self::device(), $keys);
            $this->fail('expected the ordinary denial');
        } catch (ApiError $e) {
            $this->assertSame('daily_device_limit', $e->errorCode);
        }

        // ... the judge path is not, and it does not touch those three rows.
        $before = $this->nonJudgeRows();
        $total = $this->episodeTotal();
        $view = $this->judgeStart();
        $this->assertNotSame('', $view->episodeId);
        $this->assertSame($total + 1, $this->episodeTotal());
        $this->assertSame($before, $this->nonJudgeRows());
        $this->assertSame(['device' => 1, 'ip' => 1, 'site' => 1], $this->usage->counts(self::DAY, $keys));
        $this->assertSame(1, $this->usage->judgeCount(self::DAY));
    }

    public function testFiveRunningEpisodesOfOtherDevicesDoNotStopAJudgeStart(): void
    {
        for ($i = 0; $i < $this->config->maxRunningEpisodes; $i++) {
            $this->starter->start(self::device(), self::keys());
        }
        $this->assertSame(5, $this->repo->countRunningEpisodes());

        try {
            $this->starter->start(self::device(), self::keys());
            $this->fail('expected too_many_running');
        } catch (ApiError $e) {
            $this->assertSame('too_many_running', $e->errorCode);
        }

        $this->judgeStart();
        $this->assertSame(6, $this->repo->countRunningEpisodes());
        $this->assertSame(1, $this->usage->judgeCount(self::DAY));
    }

    public function testDeviceAlreadyRunningComesFirstAndCarriesTheEpisodeId(): void
    {
        $device = self::device();
        $first = $this->judgeStart($device);
        $rows = $this->usage->rows;

        try {
            $this->judgeStart($device);
            $this->fail('expected 409');
        } catch (ApiError $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame('device_already_running', $e->errorCode);
            $this->assertSame($first->episodeId, $e->episodeId);
        }
        $this->assertSame($rows, $this->usage->rows, 'a 409 must not touch the judge count');
    }

    public function testDeviceAlreadyRunningBeatsAReachedJudgeCeiling(): void
    {
        $this->build(new Config(judgeDailyLimit: 1));
        $device = self::device();
        $first = $this->judgeStart($device);

        try {
            $this->judgeStart($device);
            $this->fail('expected 409');
        } catch (ApiError $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame($first->episodeId, $e->episodeId);
        }
    }

    public function testHundredthJudgeStartSucceedsAndHundredFirstIsDenied(): void
    {
        $this->assertSame(100, $this->config->judgeDailyLimit);
        for ($i = 0; $i < 100; $i++) {
            $this->judgeStart();
        }
        $this->assertSame(100, $this->usage->judgeCount(self::DAY));
        $this->assertSame(100, $this->episodeTotal());

        $this->assertJudgeDenied(fn () => $this->judgeStart());
        $this->assertSame(100, $this->usage->judgeCount(self::DAY));
    }

    public function testJudgeCountRestartsAtUtcMidnight(): void
    {
        $limit = random_int(2, 4);
        $this->build(new Config(judgeDailyLimit: $limit));
        $this->now = gmdate_to_ts_judge('2027-01-15 23:59:58');
        for ($i = 0; $i < $limit; $i++) {
            $this->judgeStart();
        }
        $this->assertJudgeDenied(fn () => $this->judgeStart());

        $this->now = gmdate_to_ts_judge('2027-01-16 00:00:00');
        $this->judgeStart();
        $this->assertSame(1, $this->usage->judgeCount('2027-01-16'));
        $this->assertSame($limit, $this->usage->judgeCount('2027-01-15'));
    }

    public function testAThrowingCreateGivesTheJudgeCountBack(): void
    {
        $this->judgeStart();
        $this->assertSame(1, $this->usage->judgeCount(self::DAY));
        $total = $this->episodeTotal();

        $this->failCreate = true;
        try {
            $this->judgeStart();
            $this->fail('expected the engine failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('engine failure', $e->getMessage());
        }
        $this->assertSame(1, $this->usage->judgeCount(self::DAY));

        $this->failCreate = false;
        $this->judgeStart();
        $this->assertSame(2, $this->usage->judgeCount(self::DAY));
        $this->assertGreaterThan($total, $this->episodeTotal());
    }

    public function testNonJudgeStartBehavesAsBeforeAndNeverWritesTheJudgeScope(): void
    {
        $this->build(new Config(maxRunningEpisodes: 1000));
        $keys = self::keys();
        for ($i = 0; $i < 10; $i++) {
            $this->starter->start(self::device(), $keys);
        }
        $this->assertSame(10, $this->usage->counts(self::DAY, $keys)['device']);
        $this->assertSame(0, $this->usage->judgeCount(self::DAY));

        $before = $this->usage->rows;
        try {
            $this->starter->start(self::device(), $keys);
            $this->fail('expected 429');
        } catch (ApiError $e) {
            $this->assertSame(429, $e->status);
            $this->assertSame('daily_device_limit', $e->errorCode);
        }
        $this->assertSame($before, $this->usage->rows);

        // The running cap still applies to the ordinary path.
        $this->build(new Config(maxRunningEpisodes: 1));
        $this->starter->start(self::device(), self::keys());
        try {
            $this->starter->start(self::device(), self::keys());
            $this->fail('expected too_many_running');
        } catch (ApiError $e) {
            $this->assertSame('too_many_running', $e->errorCode);
        }
    }

    // --- Through ps_dispatch ------------------------------------------------

    private function judgeCookie(): JudgeCookie
    {
        $this->dir = sys_get_temp_dir() . '/ps_judge_start_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
        file_put_contents($this->dir . '/judge_key.key', bin2hex(random_bytes(32)));

        $judge = ps_build_judge_cookie(
            ['judge_pass_sha256' => JudgePass::hash(JudgePass::generate()), 'secrets_dir' => $this->dir],
            $this->config,
        );
        $this->assertNotNull($judge);

        return $judge;
    }

    private ?IpHasher $hasher = null;

    private function hasher(): IpHasher
    {
        return $this->hasher ??= new IpHasher(bin2hex(random_bytes(32)));
    }

    /** @param array<string,string> $cookies */
    private function create(array $cookies, JudgeCookie $judge, int $now): \PsApiResponse
    {
        return ps_dispatch(
            $this->turns,
            'POST',
            'episodes',
            $cookies,
            [],
            null,
            $this->memory,
            null,
            $this->starter,
            '198.51.100.7',
            $this->hasher(),
            null,
            [],
            $judge,
            '',
            $now,
        );
    }

    public function testOnlyAValidJudgeCookieLiftsTheDeviceLimitThroughDispatch(): void
    {
        $this->build(new Config(dailyDeviceLimit: 1));
        $judge = $this->judgeCookie();
        $until = $this->config->judgeUntilUnix();
        $value = $judge->issue(self::T0);
        $this->assertNotNull($value);

        $device = self::device();
        $first = $this->create(['ps_device' => $device], $judge, self::T0);
        $this->assertSame(200, $first->status);
        $this->endRunningOf($device);

        $flipped = $value[strlen($value) - 1] === '0' ? '1' : '0';
        $tampered = substr($value, 0, -1) . $flipped;
        $otherExpiry = ($until - 1) . '.' . substr($value, strpos($value, '.') + 1);
        $bad = [
            'garbage' => bin2hex(random_bytes(8)),
            'tampered' => $tampered,
            'wrong expiry' => $otherExpiry,
            'empty' => '',
        ];
        foreach ($bad as $label => $cookie) {
            $r = $this->create(['ps_device' => $device, JudgeCookie::NAME => $cookie], $judge, self::T0);
            $this->assertSame(429, $r->status, $label);
            $this->assertSame('{"error":"daily_device_limit"}', $r->body, $label);
        }

        // Expired: a genuine cookie presented after Judging_End.
        $expired = $this->create(['ps_device' => $device, JudgeCookie::NAME => $value], $judge, $until + 1);
        $this->assertSame(429, $expired->status);
        $this->assertSame('{"error":"daily_device_limit"}', $expired->body);
        $this->assertSame(0, $this->usage->judgeCount(self::DAY));

        // A valid cookie is not limited by the Device count.
        $ok = $this->create(['ps_device' => $device, JudgeCookie::NAME => $value], $judge, self::T0);
        $this->assertSame(200, $ok->status);
        $this->assertSame(1, $this->usage->judgeCount(self::DAY));
    }

    // --- Production wiring --------------------------------------------------

    public function testOneConfigFeedsTheJudgeLimitAndTheCookieExpiry(): void
    {
        $limit = random_int(2, 90);
        $untilUtc = '2027-02-' . str_pad((string) random_int(1, 27), 2, '0', STR_PAD_LEFT) . 'T12:00:00Z';
        $config = new Config(judgeUntilUtc: $untilUtc, judgeDailyLimit: $limit);

        $this->assertSame($limit, DailyLimitPolicy::fromConfig($config)->judge);

        $judge = $this->judgeCookieFor($config);
        $this->assertSame($untilUtc, $judge->untilIso());
        $this->assertSame($config->judgeUntilUnix(), (int) explode('.', (string) $judge->issue(self::T0))[0]);
    }

    private function judgeCookieFor(Config $config): JudgeCookie
    {
        $this->config = $config;

        return $this->judgeCookie();
    }

    public function testFrontControllerPassesOneConfigToTheStarterAndTheCookie(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../api/index.php');
        $code = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $source);

        $this->assertSame(1, substr_count($code, 'new Config('), 'index.php builds exactly one Config');
        $this->assertStringContainsString('$config = new Config();', $code);
        $this->assertStringContainsString('ps_build_episode_starter($config)', $code);
        $this->assertStringContainsString('ps_build_judge_cookie($localConfig, $config)', $code);

        $bootstrap = (string) file_get_contents(__DIR__ . '/../../api/bootstrap.php');
        $this->assertStringContainsString('DailyLimitPolicy::fromConfig($config ?? new Config())', $bootstrap);
    }
}

/** Unix seconds for a UTC "Y-m-d H:i:s" string. */
function gmdate_to_ts_judge(string $utc): int
{
    return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->getTimestamp();
}
