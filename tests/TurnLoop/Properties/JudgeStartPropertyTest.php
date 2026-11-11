<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Memory\MemoryService;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryUsageRepository;
use PoltergeistSwitch\TurnLoop\Clients\FakeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\DailyLimitPolicy;
use PoltergeistSwitch\TurnLoop\EpisodeStarter;
use PoltergeistSwitch\TurnLoop\IpHasher;
use PoltergeistSwitch\TurnLoop\Judge\JudgeCookie;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\TurnService;

require_once __DIR__ . '/../../../api/bootstrap.php';

/**
 * Property-based test for the create flow with and without a Judge_Cookie (Eris).
 *
 * Feature: public-lab, Property 29: Without a valid Judge_Cookie the create flow is identical to Requirement 13
 *
 * In-memory fakes and an injected clock only; every device, IP, key and hash
 * is generated; no AI call, no DB.
 *
 * **Validates: Requirements 21.18, 21.21, 21.22, 21.23, 21.24, 21.26, 21.27**
 */
class JudgeStartPropertyTest extends TestCase
{
    use TestTrait;

    private const T0 = 1_800_000_000; // 2027-01-15 08:00:00 UTC
    private const DAY = '2027-01-15';

    private const KIND_ABSENT = 0;
    private const KIND_GARBAGE = 1;
    private const KIND_TAMPERED = 2;
    private const KIND_OTHER_HASH = 3;
    private const KIND_OTHER_KEY = 4;
    private const KIND_GENUINE = 5; // valid until Judging_End, invalid after it
    private const KIND_EMPTY = 6;

    /** @return array{turns:TurnService, memory:MemoryService, starter:EpisodeStarter, repo:InMemoryEpisodeRepository, usage:InMemoryUsageRepository, clock:\stdClass} */
    private function world(Config $config): array
    {
        $clock = new \stdClass();
        $clock->now = self::T0;
        $now = static fn (): int => $clock->now;

        $repo = new InMemoryEpisodeRepository($config);
        $usage = new InMemoryUsageRepository($now);
        $turns = new TurnService(
            new FakeRobotClient(),
            new FakeRobotClient(),
            new FakeRecorderClient(),
            new ReplayEngineFactory($repo),
            $repo,
            $config,
        );
        $memory = new MemoryService($repo);
        $starter = new EpisodeStarter($turns, $memory, $usage, DailyLimitPolicy::fromConfig($config), $now);

        return compact('turns', 'memory', 'starter', 'repo', 'usage', 'clock');
    }

    private static function hex(int $n, string $label): string
    {
        return hash('sha256', $label . ':' . $n);
    }

    private static function deviceId(int $n): string
    {
        return substr(hash('sha256', 'device:' . $n), 0, 32);
    }

    /**
     * @param array{turns:TurnService, memory:MemoryService, starter:EpisodeStarter} $w
     * @param array<string,string> $cookies
     * @return array{status:int, error:?string, created:bool}
     */
    private function create(array $w, IpHasher $hasher, string $ip, array $cookies, string $disaster, ?JudgeCookie $judge, int $now): array
    {
        $r = ps_dispatch(
            $w['turns'],
            'POST',
            'episodes',
            $cookies,
            ['disaster' => $disaster],
            null,
            $w['memory'],
            null,
            $w['starter'],
            $ip,
            $hasher,
            null,
            [],
            $judge,
            '',
            $now,
        );
        $body = json_decode($r->body, true);
        $this->assertIsArray($body);

        return [
            'status' => $r->status,
            'error' => isset($body['error']) ? (string) $body['error'] : null,
            'created' => $r->status === 200 && isset($body['episodeId']),
        ];
    }

    /** @param array{repo:InMemoryEpisodeRepository} $w */
    private function endRunning(array $w, string $device): void
    {
        $row = $w['repo']->findRunningEpisodeForDevice($device);
        if ($row !== null) {
            $w['repo']->markEpisodeEnded($row->id);
        }
    }

    /** @param array{repo:InMemoryEpisodeRepository} $w */
    private function total(array $w): int
    {
        return count($w['repo']->listEndedEpisodes(10000)) + $w['repo']->countRunningEpisodes();
    }

    /**
     * Without a valid cookie the sequence gives the same outcomes and counts as
     * the same sequence with judge mode off.
     */
    public function testWithoutAValidCookieTheCreateFlowIsTheOrdinaryOne(): void
    {
        // Feature: public-lab, Property 29: Without a valid Judge_Cookie the create flow is identical to Requirement 13
        $this
            ->limitTo(100)
            ->forAll(
                Generators::choose(1, 3),    // device limit
                Generators::choose(1, 3),    // ip limit
                Generators::choose(1, 6),    // site limit
                Generators::choose(1, 3),    // running cap
                Generators::choose(0, 1000), // pass-hash / key seed
                Generators::choose(1, 6000), // Judging_End after T0, seconds
                Generators::seq(Generators::tuple(
                    Generators::choose(0, 3),    // device index
                    Generators::choose(0, 2),    // ip index
                    Generators::choose(0, 1),    // disaster
                    Generators::choose(0, 2500), // clock advance, seconds
                    Generators::bool(),          // end the device's running episode first
                    Generators::choose(0, 6),    // cookie kind
                )),
            )
            ->then(function (int $dl, int $il, int $sl, int $run, int $seed, int $untilDelta, array $ops): void {
                $config = new Config(dailyDeviceLimit: $dl, dailyIpLimit: $il, dailySiteLimit: $sl, maxRunningEpisodes: $run);
                $until = self::T0 + $untilDelta;
                $hash = self::hex($seed, 'hash');
                $key = self::hex($seed, 'key');
                $genuine = new JudgeCookie($hash, $key, $until);
                $otherHash = new JudgeCookie(self::hex($seed, 'other-hash'), $key, $until);
                $otherKey = new JudgeCookie($hash, self::hex($seed, 'other-key'), $until);
                $genuineValue = (string) $genuine->issue(self::T0);
                $this->assertNotSame('', $genuineValue);

                $withJudge = $this->world($config);
                $off = $this->world($config);
                $hasher = new IpHasher(self::hex($seed, 'salt'));

                $now = self::T0;
                foreach ($ops as [$devIdx, $ipIdx, $dis, $dt, $endFirst, $kind]) {
                    $now += $dt;
                    $withJudge['clock']->now = $now;
                    $off['clock']->now = $now;

                    $device = self::deviceId($devIdx);
                    $ip = '198.51.100.' . (10 + $ipIdx);
                    $disaster = $dis === 0 ? 'earthquake' : 'heat_wave';
                    if ($endFirst) {
                        $this->endRunning($withJudge, $device);
                        $this->endRunning($off, $device);
                    }

                    $cookies = ['ps_device' => $device];
                    $value = match ($kind) {
                        self::KIND_GARBAGE => bin2hex(random_bytes(8)),
                        self::KIND_TAMPERED => substr($genuineValue, 0, -1) . ($genuineValue[-1] === '0' ? '1' : '0'),
                        self::KIND_OTHER_HASH => (string) $otherHash->issue(self::T0),
                        self::KIND_OTHER_KEY => (string) $otherKey->issue(self::T0),
                        self::KIND_GENUINE => $now < $until ? null : $genuineValue, // a live genuine cookie is not "invalid"
                        self::KIND_EMPTY => '',
                        default => null,
                    };
                    if ($value !== null) {
                        $cookies[JudgeCookie::NAME] = $value;
                    }

                    $a = $this->create($withJudge, $hasher, $ip, $cookies, $disaster, $genuine, $now);
                    $b = $this->create($off, $hasher, $ip, ['ps_device' => $device], $disaster, null, $now);

                    $this->assertSame($b, $a, 'same status, error code and creation');
                    $this->assertSame($off['usage']->rows, $withJudge['usage']->rows, 'same device, IP, site and judge counts');
                    $this->assertSame(0, $withJudge['usage']->judgeCount(self::DAY));
                    $this->assertSame($this->total($off), $this->total($withJudge));
                    $this->assertSame($off['repo']->countRunningEpisodes(), $withJudge['repo']->countRunningEpisodes());
                }
            });
    }

    /**
     * Every request carries a valid cookie: the ordinary counts are never read
     * or changed, the running cap never applies, device_already_running comes
     * first, and the judge ceiling is exact.
     */
    public function testEveryRequestWithAValidCookieFollowsTheJudgeRules(): void
    {
        // Feature: public-lab, Property 29: Without a valid Judge_Cookie the create flow is identical to Requirement 13
        $this
            ->limitTo(100)
            ->forAll(
                Generators::choose(1, 2),    // device limit
                Generators::choose(1, 2),    // ip limit
                Generators::choose(1, 4),    // site limit
                Generators::choose(1, 3),    // running cap
                Generators::choose(1, 5),    // judge daily limit
                Generators::choose(0, 1000), // pass-hash / key seed
                Generators::choose(0, 6),    // ordinary starts made first by other Devices
                Generators::seq(Generators::tuple(
                    Generators::choose(0, 3),   // device index
                    Generators::choose(0, 2),   // ip index
                    Generators::choose(0, 1),   // disaster
                    Generators::choose(0, 100), // clock advance, seconds
                    Generators::bool(),         // end the device's running episode first
                )),
            )
            ->then(function (int $dl, int $il, int $sl, int $run, int $jl, int $seed, int $preseed, array $ops): void {
                $config = new Config(
                    dailyDeviceLimit: $dl,
                    dailyIpLimit: $il,
                    dailySiteLimit: $sl,
                    maxRunningEpisodes: $run,
                    judgeDailyLimit: $jl,
                );
                $judge = new JudgeCookie(self::hex($seed, 'hash'), self::hex($seed, 'key'), self::T0 + 1_000_000);
                $value = (string) $judge->issue(self::T0);
                $w = $this->world($config);
                $hasher = new IpHasher(self::hex($seed, 'salt'));

                // Ordinary usage first, so some scopes may already be at their limit.
                for ($i = 0; $i < $preseed; $i++) {
                    $this->create($w, $hasher, '198.51.100.' . (10 + ($i % 3)), ['ps_device' => self::deviceId(100 + $i)], 'earthquake', $judge, self::T0);
                }
                // (those requests carried no ps_judge cookie, so they were ordinary starts)
                $rowsBefore = $w['usage']->rows;
                $this->assertSame(0, $w['usage']->judgeCount(self::DAY));
                $totalBefore = $this->total($w);

                $now = self::T0;
                $created = 0;
                $running = [];
                foreach ($ops as [$devIdx, $ipIdx, $dis, $dt, $endFirst]) {
                    $now += $dt;
                    $w['clock']->now = $now;
                    $device = self::deviceId($devIdx);
                    if ($endFirst) {
                        $this->endRunning($w, $device);
                        unset($running[$devIdx]);
                    }

                    $r = $this->create(
                        $w,
                        $hasher,
                        '198.51.100.' . (10 + $ipIdx),
                        ['ps_device' => $device, JudgeCookie::NAME => $value],
                        $dis === 0 ? 'earthquake' : 'heat_wave',
                        $judge,
                        $now,
                    );

                    if (isset($running[$devIdx])) {
                        $this->assertSame(409, $r['status']);
                        $this->assertSame('device_already_running', $r['error']);
                    } elseif ($created >= $jl) {
                        $this->assertSame(429, $r['status']);
                        $this->assertSame('daily_judge_limit', $r['error']);
                        $this->assertFalse($r['created']);
                    } else {
                        $this->assertSame(200, $r['status']);
                        $this->assertTrue($r['created']);
                        $this->assertNotSame('too_many_running', $r['error']);
                        $created++;
                        $running[$devIdx] = true;
                    }

                    $this->assertSame($created, $w['usage']->judgeCount(self::DAY));
                    $this->assertSame($totalBefore + $created, $this->total($w));
                    $nonJudge = array_filter(
                        $w['usage']->rows,
                        static fn (string $k): bool => !str_contains($k, '|judge|'),
                        ARRAY_FILTER_USE_KEY,
                    );
                    $this->assertSame($rowsBefore, $nonJudge, 'device, IP and site counts never change');
                }
            });
    }
}
