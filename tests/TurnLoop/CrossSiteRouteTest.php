<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\ReflectionTextBuilder;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Memory\MemoryService;
use PoltergeistSwitch\Memory\ReflectService;
use PoltergeistSwitch\TurnLoop\Clients\FakeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\DailyLimitPolicy;
use PoltergeistSwitch\TurnLoop\EpisodeStarter;
use PoltergeistSwitch\TurnLoop\IpHasher;
use PoltergeistSwitch\TurnLoop\ParallelHttp;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\TurnService;

require_once __DIR__ . '/../../api/bootstrap.php';

/**
 * The cross-site Origin guard of ps_dispatch (public-lab Requirements 19.1-19.7,
 * 14.29), through a fake-backed TurnService. No real AI call, no DB.
 */
class CrossSiteRouteTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const DEVICE = '00112233445566778899aabbccddeeff';
    private const IP = '198.51.100.23';
    private const ALLOWED = 'https://kiro.pyol.net';
    private const FOREIGN = 'https://evil.example';

    private InMemoryEpisodeRepository $repo;
    private InMemoryUsageRepository $usage;
    private FakeRobotClient $robotA;
    private FakeRobotClient $robotB;
    private FakeRobotClient $reflectA;
    private FakeRobotClient $reflectB;
    private FakeRecorderClient $recorder;
    private TurnService $turns;
    private MemoryService $memory;
    private ReflectService $reflect;
    private EpisodeStarter $starter;
    private IpHasher $hasher;

    protected function setUp(): void
    {
        $this->repo = new InMemoryEpisodeRepository();
        $this->usage = new InMemoryUsageRepository(fn (): int => self::NOW);
        $this->robotA = new FakeRobotClient();
        $this->robotB = new FakeRobotClient();
        $this->reflectA = new FakeRobotClient();
        $this->reflectB = new FakeRobotClient();
        $this->recorder = new FakeRecorderClient();
        $this->turns = new TurnService(
            $this->robotA,
            $this->robotB,
            $this->recorder,
            new ReplayEngineFactory($this->repo),
            $this->repo,
            new Config(),
        );
        $this->memory = new MemoryService($this->repo);
        $this->reflect = new ReflectService(
            $this->reflectA,
            $this->reflectB,
            new ReflectionTextBuilder(new SituationTextGenerator()),
            new ReplayEngineFactory($this->repo, new EpisodeConfig()),
            $this->repo,
            new Config(),
            new ParallelHttp(),
        );
        $this->hasher = new IpHasher(str_repeat('ab', 32));
        $this->starter = new EpisodeStarter(
            $this->turns,
            $this->memory,
            $this->usage,
            new DailyLimitPolicy(1000, 1000, 1000),
            fn (): int => self::NOW,
        );
    }

    /**
     * @param array<string,string> $cookies
     * @param list<string>         $allowed
     */
    private function call(
        string $method,
        string $route,
        ?string $origin,
        array $allowed = [self::ALLOWED],
        array $cookies = [],
    ): \PsApiResponse {
        return ps_dispatch(
            $this->turns,
            $method,
            $route,
            $cookies,
            [],
            null,
            $this->memory,
            $this->reflect,
            $this->starter,
            self::IP,
            $this->hasher,
            $origin,
            $allowed,
        );
    }

    private function startEpisode(): string
    {
        $r = $this->call('POST', 'episodes', null, [], ['ps_device' => self::DEVICE]);
        $this->assertSame(200, $r->status);

        return (string) json_decode($r->body, true)['episodeId'];
    }

    private function clientCalls(): int
    {
        return $this->robotA->callCount() + $this->robotB->callCount()
            + $this->reflectA->callCount() + $this->reflectB->callCount();
    }

    /** @return array<string,array{string,string}> */
    public static function postRoutes(): array
    {
        return [
            'create' => ['episodes', ''],
            'disaster' => ['episodes/{id}/disaster', ''],
            'ask' => ['episodes/{id}/turns/1/ask', ''],
            'apply' => ['episodes/{id}/turns/1/apply', ''],
            'reflect' => ['episodes/{id}/reflect', ''],
            'memory reset' => ['memory/reset', ''],
        ];
    }

    /** @dataProvider postRoutes */
    public function testForeignOriginIsRefusedAndChangesNothing(string $route, string $unused): void
    {
        $id = $this->startEpisode();
        $route = str_replace('{id}', $id, $route);
        $rowsBefore = $this->usage->rows;
        $runningBefore = $this->repo->countRunningEpisodes();
        $memoryBefore = $this->call('GET', 'memory', null, [], ['ps_device' => self::DEVICE])->body;
        $callsBefore = $this->clientCalls();

        // No device cookie, so a Set-Cookie on create would be visible.
        $r = $this->call('POST', $route, self::FOREIGN);

        $this->assertSame(403, $r->status);
        $this->assertSame('{"error":"cross_site"}', $r->body);
        $this->assertSame('no-store', $r->headers['Cache-Control']);
        $this->assertArrayNotHasKey('Set-Cookie', $r->headers);
        $this->assertSame($rowsBefore, $this->usage->rows, 'no usage count');
        $this->assertSame($runningBefore, $this->repo->countRunningEpisodes(), 'no episode');
        $this->assertSame($callsBefore, $this->clientCalls(), 'no client call');
        $this->assertSame(
            $memoryBefore,
            $this->call('GET', 'memory', null, [], ['ps_device' => self::DEVICE])->body,
            'memory untouched'
        );
    }

    /** @dataProvider postRoutes */
    public function testAllowedAndAbsentOriginGiveTheSameResponse(string $route, string $unused): void
    {
        $out = [];
        foreach ([self::ALLOWED, null] as $origin) {
            $this->setUp();
            $id = $this->startEpisode();
            $r = $this->call('POST', str_replace('{id}', $id, $route), $origin, [self::ALLOWED], ['ps_device' => self::DEVICE]);
            $this->assertNotSame(403, $r->status);
            $out[] = [
                $r->status,
                // The starting situation is drawn at random per episode, so compare
                // the response shape and turn states rather than the world bytes.
                $this->shape(json_decode($r->body, true)),
                $r->headers['Cache-Control'] ?? null,
            ];
        }

        $this->assertSame($out[0], $out[1]);
    }

    /** @return array<string,mixed> */
    private function shape(mixed $body): array
    {
        $this->assertIsArray($body);
        $turns = [];
        foreach ($body['turns'] ?? [] as $t) {
            $turns[] = [$t['turnNumber'] ?? null, $t['state'] ?? null];
        }
        $keys = array_keys($body);
        sort($keys);

        return ['keys' => $keys, 'turns' => $turns, 'error' => $body['error'] ?? null];
    }

    /** @return array<string,array{?string,list<string>}> */
    public static function refusedOrigins(): array
    {
        $ok = 'https://kiro.pyol.net';

        return [
            'empty list' => [$ok, []],
            'null literal string' => ['null', [$ok]],
            'trailing slash' => [$ok . '/', [$ok]],
            'different case' => ['https://KIRO.pyol.net', [$ok]],
            'different port' => ['https://kiro.pyol.net:8443', [$ok]],
            'different scheme' => ['http://kiro.pyol.net', [$ok]],
            'empty string' => ['', [$ok]],
            'list with empty string only' => ['https://x.example', ['']],
        ];
    }

    /** @dataProvider refusedOrigins
     * @param list<string> $allowed
     */
    public function testNearMissOriginsAreRefused(string $origin, array $allowed): void
    {
        $r = $this->call('POST', 'episodes', $origin, $allowed);

        $this->assertSame(403, $r->status);
        $this->assertSame('{"error":"cross_site"}', $r->body);
        $this->assertSame(0, $this->repo->countRunningEpisodes());
    }

    public function testMissingListMeansDefaultEmptyListAndRefusesAnyOrigin(): void
    {
        // ps_dispatch called without the trailing parameters at all, plus an Origin.
        $r = ps_dispatch(
            $this->turns,
            'POST',
            'episodes',
            [],
            [],
            null,
            $this->memory,
            null,
            $this->starter,
            self::IP,
            $this->hasher,
            self::ALLOWED,
        );

        $this->assertSame(403, $r->status);
        $this->assertSame('{"error":"cross_site"}', $r->body);
    }

    public function testGetRequestsAreNotChecked(): void
    {
        $id = $this->startEpisode();

        $get = $this->call('GET', "episodes/$id", self::FOREIGN);
        $this->assertSame(200, $get->status);
        $this->assertSame(200, $this->call('GET', 'memory', self::FOREIGN, [])->status);
    }

    public function testErrorBodyContainsOnlyTheCode(): void
    {
        $r = $this->call('POST', 'episodes', self::FOREIGN);

        $this->assertSame(['error' => 'cross_site'], json_decode($r->body, true));
        $this->assertStringNotContainsString(self::FOREIGN, $r->body);
        $this->assertStringNotContainsString(self::ALLOWED, $r->body);
    }
}
