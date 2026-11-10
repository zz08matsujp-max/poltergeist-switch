<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Memory\MemoryService;
use PoltergeistSwitch\TurnLoop\Clients\FakeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\DailyLimitPolicy;
use PoltergeistSwitch\TurnLoop\EpisodeStarter;
use PoltergeistSwitch\TurnLoop\IpHasher;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\TurnService;

require_once __DIR__ . '/../../api/bootstrap.php';

/**
 * POST /api/episodes through ps_dispatch with an EpisodeStarter over the
 * in-memory fakes (public-lab Requirements 13.8, 13.14, 13.18, 13.19). No real
 * AI call, no DB.
 */
class CreateRouteLimitsTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const DEV_A = '00112233445566778899aabbccddeeff';
    private const DEV_B = 'ffeeddccbbaa99887766554433221100';
    private const IP = '198.51.100.23';

    private InMemoryEpisodeRepository $repo;
    private InMemoryUsageRepository $usage;
    private TurnService $turns;
    private MemoryService $memory;
    private IpHasher $hasher;

    protected function setUp(): void
    {
        $this->repo = new InMemoryEpisodeRepository();
        $this->usage = new InMemoryUsageRepository(fn (): int => self::NOW);
        $this->turns = new TurnService(
            new FakeRobotClient(),
            new FakeRobotClient(),
            new FakeRecorderClient(),
            new ReplayEngineFactory($this->repo),
            $this->repo,
            new Config(),
        );
        $this->memory = new MemoryService($this->repo);
        $this->hasher = new IpHasher(str_repeat('ab', 32));
    }

    private function starter(int $device, int $ip, int $site): EpisodeStarter
    {
        return new EpisodeStarter(
            $this->turns,
            $this->memory,
            $this->usage,
            new DailyLimitPolicy($device, $ip, $site),
            fn (): int => self::NOW,
        );
    }

    /** @param array<string,string> $cookies */
    private function create(EpisodeStarter $starter, array $cookies, ?string $ip = self::IP, bool $salt = true): \PsApiResponse
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
            $starter,
            $ip,
            $salt ? $this->hasher : null,
        );
    }

    private function endAll(): void
    {
        foreach ($this->runningIds() as $id) {
            $this->repo->markEpisodeEnded($id);
        }
    }

    /** @return list<string> */
    private function runningIds(): array
    {
        $ids = [];
        foreach ([self::DEV_A, self::DEV_B] as $d) {
            $row = $this->repo->findRunningEpisodeForDevice($d);
            if ($row !== null) {
                $ids[] = $row->id;
            }
        }
        return $ids;
    }

    public function test429BodyIsExactlyTheCodeWithNoStore(): void
    {
        $starter = $this->starter(1, 30, 100);
        $this->assertSame(200, $this->create($starter, ['ps_device' => self::DEV_A])->status);
        $this->endAll();

        $r = $this->create($starter, ['ps_device' => self::DEV_A]);

        $this->assertSame(429, $r->status);
        $this->assertSame('{"error":"daily_device_limit"}', $r->body);
        $this->assertSame('no-store', $r->headers['Cache-Control']);
        $this->assertArrayNotHasKey('Set-Cookie', $r->headers);
    }

    public function testIpAndSiteLimitsGiveTheirOwnCodes(): void
    {
        $starter = $this->starter(10, 1, 100);
        $this->create($starter, ['ps_device' => self::DEV_A]);
        $this->endAll();
        $r = $this->create($starter, ['ps_device' => self::DEV_B]);
        $this->assertSame('{"error":"daily_ip_limit"}', $r->body);

        $starter = $this->starter(10, 30, 1);
        $r = $this->create($starter, ['ps_device' => self::DEV_B], '192.0.2.200');
        $this->assertSame(429, $r->status);
        $this->assertSame('{"error":"daily_site_limit"}', $r->body);
    }

    public function testRunningEpisode409CarriesEpisodeIdAndComesBefore429(): void
    {
        $starter = $this->starter(1, 1, 1);
        $first = json_decode($this->create($starter, ['ps_device' => self::DEV_A])->body, true);

        $r = $this->create($starter, ['ps_device' => self::DEV_A]);

        $this->assertSame(409, $r->status);
        $this->assertSame(['error' => 'device_already_running', 'episodeId' => $first['episodeId']], json_decode($r->body, true));
        $this->assertSame('no-store', $r->headers['Cache-Control']);
    }

    /** @dataProvider badCookies */
    public function testMalformedCookieIsTreatedAsAbsent(string $bad): void
    {
        $r = $this->create($this->starter(10, 30, 100), ['ps_device' => $bad]);

        $this->assertSame(200, $r->status);
        $this->assertArrayHasKey('Set-Cookie', $r->headers, 'a fresh Device is minted');
        $this->assertStringNotContainsString($bad, $r->headers['Set-Cookie']);
    }

    /** @return array<string,array{string}> */
    public static function badCookies(): array
    {
        return [
            'short' => ['dev_a'],
            'upper' => [strtoupper(self::DEV_A)],
            'oversized' => [str_repeat('a', 500)],
            'non hex' => [str_repeat('g', 32)],
            'newline' => [self::DEV_A . "\n"],
        ];
    }

    public function testCookieIsMintedOnlyOnSuccessfulCreate(): void
    {
        $ok = $this->create($this->starter(10, 30, 100), []);
        $this->assertSame(200, $ok->status);
        $this->assertArrayHasKey('Set-Cookie', $ok->headers);

        $withCookie = $this->create($this->starter(10, 30, 100), ['ps_device' => self::DEV_B]);
        $this->assertSame(200, $withCookie->status);
        $this->assertArrayNotHasKey('Set-Cookie', $withCookie->headers);

        $this->endAll();
        $denied = $this->create($this->starter(10, 30, 2), []);
        $this->assertSame(429, $denied->status);
        $this->assertArrayNotHasKey('Set-Cookie', $denied->headers);
    }

    public function testOnlyHashesAreStored(): void
    {
        $ok = $this->create($this->starter(10, 30, 100), ['ps_device' => self::DEV_A]);
        $this->assertSame(200, $ok->status);

        $day = DailyLimitPolicy::utcDay(self::NOW);
        $this->assertCount(3, $this->usage->rows);
        $this->assertArrayHasKey("$day|device|" . $this->hasher->deviceKey(self::DEV_A), $this->usage->rows);
        $this->assertArrayHasKey("$day|ip|" . $this->hasher->ipHash(self::IP), $this->usage->rows);

        $dump = json_encode($this->usage->rows);
        $this->assertStringNotContainsString(self::IP, (string) $dump);
        $this->assertStringNotContainsString(self::DEV_A, (string) $dump);
        $this->assertStringNotContainsString(self::IP, $ok->body);
        $this->assertStringNotContainsString(self::DEV_A, $ok->body);
        $this->assertStringNotContainsString($this->hasher->ipHash(self::IP), $ok->body);
    }

    public function testWithoutSaltTheDeviceKeyFallsBackAndIpIsSkipped(): void
    {
        $this->create($this->starter(10, 30, 100), ['ps_device' => self::DEV_A], self::IP, false);

        $day = DailyLimitPolicy::utcDay(self::NOW);
        $this->assertArrayHasKey("$day|device|" . hash('sha256', 'device:' . self::DEV_A), $this->usage->rows);
        $this->assertCount(2, $this->usage->rows, 'device and site only');
    }

    public function testNullIpSkipsTheIpScope(): void
    {
        $this->create($this->starter(10, 30, 100), ['ps_device' => self::DEV_A], null);

        $this->assertCount(2, $this->usage->rows);
    }
}
