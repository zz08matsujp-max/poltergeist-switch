<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\ReflectionTextBuilder;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Memory\MemoryService;
use PoltergeistSwitch\Memory\ReflectService;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryUsageRepository;
use PoltergeistSwitch\TurnLoop\Clients\FakeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\DailyLimitPolicy;
use PoltergeistSwitch\TurnLoop\EpisodeListService;
use PoltergeistSwitch\TurnLoop\EpisodeStarter;
use PoltergeistSwitch\TurnLoop\IpHasher;
use PoltergeistSwitch\TurnLoop\ParallelHttp;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\TurnService;
use PoltergeistSwitch\TurnLoop\UsageKeys;
use PoltergeistSwitch\TurnLoop\WorldStateBackfill;

require_once __DIR__ . '/../../../api/bootstrap.php';

/**
 * Feature: public-lab, Property 14: Only starting an episode is counted or limited
 *
 * For any usage state, including Device, IP and site at their limits, `ask`,
 * `apply`, `reflect`, `GET /api/episodes/{id}`, the episode list and
 * `GET /api/memory` behave as if no limit existed and leave every usage count
 * unchanged. In-memory fakes only; no real AI call.
 *
 * Validates: Requirements 13.1, 13.2
 */
class OnlyStartIsLimitedPropertyTest extends TestCase
{
    use TestTrait;

    private const NOW = 1_800_000_000;
    private const DEVICE = '00112233445566778899aabbccddeeff';
    private const IP = '198.51.100.23';

    /**
     * Feature: public-lab, Property 14: Only starting an episode is counted or limited
     *
     * Validates: Requirements 13.1, 13.2
     */
    public function testOnlyStartingAnEpisodeIsCountedOrLimited(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generators::choose(1, 3), // device limit
                Generators::choose(1, 3), // ip limit
                Generators::choose(1, 3), // site limit
                Generators::choose(0, 3), // extra usage beyond the limit: 0 = exactly at limit
                Generators::int(),        // seed
            )
            ->then(function (int $dl, int $il, int $sl, int $extra, int $seed): void {
                $limited = $this->runFlow($dl, $il, $sl, $extra, $seed, true);
                $free = $this->runFlow($dl, $il, $sl, 0, $seed, false);

                $this->assertSame(
                    $free['statuses'],
                    $limited['statuses'],
                    'responses with every scope at its limit equal those with no limit'
                );
                foreach ($limited['statuses'] as $label => $status) {
                    $this->assertSame(200, $status, "$label must succeed at the limits");
                }
                $this->assertSame(
                    $limited['before'],
                    $limited['after'],
                    'ask, apply, reflect, get, list and memory change no usage count'
                );
            });
    }

    /**
     * @return array{statuses: array<string,int>, before: array<string,int>, after: array<string,int>}
     */
    private function runFlow(int $dl, int $il, int $sl, int $extra, int $seed, bool $atLimits): array
    {
        $repo = new InMemoryEpisodeRepository();
        $usage = new InMemoryUsageRepository(fn (): int => self::NOW);
        $turns = new TurnService(
            new FakeRobotClient(),
            new FakeRobotClient(),
            new FakeRecorderClient(),
            new ReplayEngineFactory($repo),
            $repo,
            new Config(),
        );
        $memory = new MemoryService($repo);
        $hasher = new IpHasher(str_repeat('ab', 32));
        $reflect = new ReflectService(
            new FakeRobotClient(),
            new FakeRobotClient(),
            new ReflectionTextBuilder(new SituationTextGenerator()),
            new ReplayEngineFactory($repo, new EpisodeConfig()),
            $repo,
            new Config(),
            new ParallelHttp(),
        );
        $lister = new EpisodeListService($repo, new WorldStateBackfill($repo, new ReplayEngineFactory($repo)), new Config());
        $cookies = ['ps_device' => self::DEVICE];

        // Start one episode with generous limits, then raise every scope to its limit.
        $roomy = new EpisodeStarter($turns, $memory, $usage, new DailyLimitPolicy(1000, 1000, 1000), fn (): int => self::NOW);
        $created = ps_dispatch($turns, 'POST', 'episodes', $cookies, [], null, $memory, null, $roomy, self::IP, $hasher);
        $this->assertSame(200, $created->status);
        $id = (string) json_decode($created->body, true)['episodeId'];

        $day = DailyLimitPolicy::utcDay(self::NOW);
        $stamp = gmdate('Y-m-d H:i:s', self::NOW);
        if ($atLimits) {
            $usage->rows["$day|device|" . $hasher->deviceKey(self::DEVICE)] = ['starts' => $dl + $extra, 'updatedAt' => $stamp];
            $usage->rows["$day|ip|" . $hasher->ipHash(self::IP)] = ['starts' => $il + $extra, 'updatedAt' => $stamp];
            $usage->rows["$day|site|" . UsageKeys::SITE_KEY] = ['starts' => $sl + $extra, 'updatedAt' => $stamp];
        }
        $counts = static fn (): array => array_map(static fn (array $r): int => $r['starts'], $usage->rows);
        $before = $counts();

        // The limited starter is wired into every call, as in production.
        $starter = new EpisodeStarter($turns, $memory, $usage, new DailyLimitPolicy($dl, $il, $sl), fn (): int => self::NOW);
        $call = fn (string $method, string $route, array $query = []) => ps_dispatch(
            $turns,
            $method,
            $route,
            $cookies,
            $query,
            $lister,
            $memory,
            $reflect,
            $starter,
            self::IP,
            $hasher,
        );

        $statuses = [];
        $statuses['disaster'] = $call('POST', "episodes/$id/disaster")->status;
        $statuses['ask'] = $call('POST', "episodes/$id/turns/1/ask")->status;
        $statuses['ask again'] = $call('POST', "episodes/$id/turns/1/ask")->status;
        $statuses['apply'] = $call('POST', "episodes/$id/turns/1/apply")->status;
        $statuses['apply again'] = $call('POST', "episodes/$id/turns/1/apply")->status;
        $statuses['get'] = $call('GET', "episodes/$id")->status;
        $statuses['memory'] = $call('GET', 'memory')->status;

        $repo->markEpisodeEnded($id);
        $statuses['reflect'] = $call('POST', "episodes/$id/reflect")->status;
        $statuses['reflect again'] = $call('POST', "episodes/$id/reflect")->status;
        $statuses['list'] = $call('GET', 'episodes', ['ended' => '1'])->status;
        $statuses['get ended'] = $call('GET', "episodes/$id")->status;

        return ['statuses' => $statuses, 'before' => $before, 'after' => $counts()];
    }
}
