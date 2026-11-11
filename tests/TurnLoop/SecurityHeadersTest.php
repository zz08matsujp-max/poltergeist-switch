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
use PoltergeistSwitch\TurnLoop\Http\SecurityHeaders;
use PoltergeistSwitch\TurnLoop\IpHasher;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\Repository\UsageRepository;
use PoltergeistSwitch\TurnLoop\TurnService;
use PoltergeistSwitch\TurnLoop\UsageKeys;

require_once __DIR__ . '/../../api/bootstrap.php';

/**
 * The Security_Headers on the API (public-lab Requirements 20.1-20.4, 20.7):
 * the exact four strings, and every ps_dispatch response status carries them
 * beside Cache-Control: no-store. No real AI call, no DB.
 */
class SecurityHeadersTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const DEVICE = '00112233445566778899aabbccddeeff';
    private const IP = '198.51.100.23';
    private const ALLOWED = 'https://kiro.pyol.net';
    private const FOREIGN = 'https://evil.example';

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

    private function starter(int $device, ?UsageRepository $usage = null): EpisodeStarter
    {
        return new EpisodeStarter(
            $this->turns,
            $this->memory,
            $usage ?? $this->usage,
            new DailyLimitPolicy($device, 1000, 1000),
            fn (): int => self::NOW,
        );
    }

    /** @param array<string,string> $cookies */
    private function call(
        string $method,
        string $route,
        EpisodeStarter $starter,
        array $cookies = ['ps_device' => self::DEVICE],
        ?string $origin = null,
    ): \PsApiResponse {
        return ps_dispatch(
            $this->turns,
            $method,
            $route,
            $cookies,
            [],
            null,
            $this->memory,
            null,
            $starter,
            self::IP,
            $this->hasher,
            $origin,
            [self::ALLOWED],
        );
    }

    private function assertSecure(\PsApiResponse $response, int $status): void
    {
        $this->assertSame($status, $response->status);
        $this->assertSame('no-store', $response->headers['Cache-Control'] ?? null);
        foreach (SecurityHeaders::all() as $name => $value) {
            $this->assertSame($value, $response->headers[$name] ?? null, "$status carries $name");
        }
    }

    public function testAllIsExactlyTheFourPairs(): void
    {
        $this->assertSame(
            [
                'Content-Security-Policy' => "default-src 'self'; script-src 'self'; "
                    . "style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; "
                    . "img-src 'self' data:; connect-src 'self'; object-src 'none'; "
                    . "base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
                'X-Frame-Options' => 'DENY',
                'Referrer-Policy' => 'strict-origin-when-cross-origin',
                'Strict-Transport-Security' => 'max-age=31536000',
            ],
            SecurityHeaders::all()
        );
    }

    public function testPolicyIsNeverLoosened(): void
    {
        $csp = SecurityHeaders::all()['Content-Security-Policy'];
        $this->assertStringNotContainsString('unsafe-inline', $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
        $this->assertStringNotContainsString('*', $csp);
    }

    public function test200Carries(): void
    {
        $r = $this->call('POST', 'episodes', $this->starter(10));
        $this->assertSecure($r, 200);
    }

    public function test403CrossSiteCarries(): void
    {
        $r = $this->call('POST', 'episodes', $this->starter(10), [], self::FOREIGN);
        $this->assertSecure($r, 403);
        $this->assertSame('{"error":"cross_site"}', $r->body);
    }

    public function test404Carries(): void
    {
        $this->assertSecure($this->call('GET', 'episodes/nope', $this->starter(10)), 404);
    }

    public function test409Carries(): void
    {
        $starter = $this->starter(10);
        $this->assertSame(200, $this->call('POST', 'episodes', $starter)->status);

        $this->assertSecure($this->call('POST', 'episodes', $starter), 409);
    }

    public function test429Carries(): void
    {
        $starter = $this->starter(1);
        $this->assertSame(200, $this->call('POST', 'episodes', $starter)->status);
        $row = $this->repo->findRunningEpisodeForDevice(self::DEVICE);
        $this->assertNotNull($row);
        $this->repo->markEpisodeEnded($row->id);

        $this->assertSecure($this->call('POST', 'episodes', $starter), 429);
    }

    public function test500Carries(): void
    {
        $throwing = new class () implements UsageRepository {
            public function tryReserve(string $utcDay, UsageKeys $keys, DailyLimitPolicy $policy, string $nowUtc): ?string
            {
                throw new \RuntimeException('boom');
            }

            public function release(string $utcDay, UsageKeys $keys, string $nowUtc): void
            {
            }

            public function tryReserveJudge(string $utcDay, DailyLimitPolicy $policy, string $nowUtc): ?string
            {
                throw new \RuntimeException('boom');
            }

            public function releaseJudge(string $utcDay, string $nowUtc): void
            {
            }

            public function judgeCount(string $utcDay): int
            {
                throw new \RuntimeException('boom');
            }

            public function counts(string $utcDay, UsageKeys $keys): array
            {
                throw new \RuntimeException('boom');
            }
        };

        $r = $this->call('POST', 'episodes', $this->starter(10, $throwing));

        $this->assertSecure($r, 500);
        $this->assertSame('{"error":"internal_error"}', $r->body);
    }
}
