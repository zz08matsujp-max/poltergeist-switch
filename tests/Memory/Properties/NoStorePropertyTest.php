<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Memory\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\ReflectionTextBuilder;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Memory\MemoryService;
use PoltergeistSwitch\Memory\ReflectService;
use PoltergeistSwitch\Memory\Values\MemoryRow;
use PoltergeistSwitch\Memory\Values\MemorySnapshot;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;
use PoltergeistSwitch\TurnLoop\Clients\FakeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\ParallelHttp;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\TurnService;
use PoltergeistSwitch\TurnLoop\Values\EpisodeRow;
use PoltergeistSwitch\TurnLoop\Values\ReplyRecord;
use PoltergeistSwitch\TurnLoop\Values\TurnStateValue;

// The api/ layer is plain PHP with its own PSR-4 autoloader (no Composer on the
// server), so the handlers and the routing/response helpers are global
// functions and a global PsApiResponse class. Load them once for the test.
// This does NOT build a production TurnService — the test injects fake-backed
// services into ps_dispatch(), so no config, secret, or DB is touched.
require_once __DIR__ . '/../../../api/bootstrap.php';

/**
 * Feature: robot-memory, Property 10: Every memory response is JSON and
 * uncacheable.
 *
 * For any request to the reflect, reset, or status endpoint, the response body
 * parses as JSON and the response carries the `Cache-Control: no-store` header
 * (design "Property 10"; Requirements 2.12, 4.5, 5.5). An nginx cache sits in
 * front of PHP, so a missing no-store on any memory response would let a page
 * or API response be cached and served stale — the invariant this property
 * guards for every one of the three routes.
 *
 * The three routes are driven in-process through {@see ps_dispatch} with
 * fake-backed services over the {@see InMemoryEpisodeRepository} and two
 * {@see FakeRobotClient}s — the same harness the turn-loop api test uses — so
 * no real web server, config, secret, or network is involved. The state is
 * varied by seed and by whether each robot answers or fails, and the memory
 * store is varied from empty to populated, so every response shape the three
 * routes produce (a reflect result with answered/failed robots, a status with
 * counts and lengths, and a reset generation) is exercised and each must parse
 * as JSON and carry Cache-Control: no-store.
 *
 * NOTE: this Eris release (giorgiosironi/eris ^1.3) controls the iteration
 * count with `limitTo()`; `limitTo(100)` is required here, matching the other
 * property tests.
 *
 * Validates: Requirements 2.12, 4.5, 5.5
 */
class NoStorePropertyTest extends TestCase
{
    use TestTrait;

    private const ROBOT_A = 'robot_a';
    private const ROBOT_B = 'robot_b';
    private const LIMIT_DEVICE = '5e17c0de5e17c0de5e17c0de5e17c0de';
    private const LIMIT_IP = '198.51.100.77';

    /**
     * Property 10: every reflect, reset, and status response parses as JSON and
     * carries Cache-Control: no-store.
     *
     * Validates: Requirements 2.12, 4.5, 5.5
     */
    public function testEveryMemoryResponseIsJsonAndUncacheable(): void
    {
        $this->limitTo(100)
            ->forAll(
                // A seed so the episode (and so its Reflection_Text) varies.
                Generators::int(),
                // Whether each robot answers or fails, so the reflect body
                // varies between the answered-memory and failure-reason shapes.
                Generators::bool(),
                Generators::bool(),
                // How many prior memories to seed (0..8), so status/reflect
                // counts range from empty to populated.
                Generators::choose(0, 8),
            )
            ->then(function (
                int $seedValue,
                bool $robotAAnswers,
                bool $robotBAnswers,
                int $seedCount
            ): void {
                $repo = new InMemoryEpisodeRepository();
                $this->seedMemories($repo, $seedCount);

                $episodeId = $this->buildEndedReflectableEpisode($repo, $seedValue);

                $clientA = new FakeRobotClient();
                $clientB = new FakeRobotClient();
                $this->configureReply($repo, $episodeId, self::ROBOT_A, $clientA, $robotAAnswers);
                $this->configureReply($repo, $episodeId, self::ROBOT_B, $clientB, $robotBAnswers);

                $turnService = new TurnService(
                    $clientA,
                    $clientB,
                    new FakeRecorderClient(),
                    new ReplayEngineFactory($repo),
                    $repo,
                    new Config(),
                );
                $memoryService = new MemoryService($repo);
                $reflectService = new ReflectService(
                    $clientA,
                    $clientB,
                    new ReflectionTextBuilder(new SituationTextGenerator()),
                    new ReplayEngineFactory($repo, new EpisodeConfig()),
                    $repo,
                    new Config(),
                    new ParallelHttp(),
                );

                // Reflect, then status, then reset — every one must be JSON and
                // uncacheable. Running reflect first also leaves a populated
                // store for status to report.
                $responses = [
                    'reflect' => ps_dispatch(
                        $turnService,
                        'POST',
                        'episodes/' . $episodeId . '/reflect',
                        [],
                        [],
                        null,
                        $memoryService,
                        $reflectService,
                    ),
                    'status' => ps_dispatch(
                        $turnService,
                        'GET',
                        'memory',
                        [],
                        [],
                        null,
                        $memoryService,
                        $reflectService,
                    ),
                    'reset' => ps_dispatch(
                        $turnService,
                        'POST',
                        'memory/reset',
                        [],
                        [],
                        null,
                        $memoryService,
                        $reflectService,
                    ),
                ];

                foreach ($responses as $label => $response) {
                    $this->assertSame(
                        200,
                        $response->status,
                        "{$label} must succeed (seed {$seedValue})"
                    );

                    // Cache-Control: no-store on every memory response
                    // (Requirements 4.5, 5.5).
                    $this->assertSame(
                        'no-store',
                        $response->headers['Cache-Control'] ?? null,
                        "{$label} must carry Cache-Control: no-store (seed {$seedValue})"
                    );

                    // The body parses as JSON (Requirement 5.5).
                    $decoded = json_decode($response->body, true);
                    $this->assertIsArray(
                        $decoded,
                        "{$label} body must parse as JSON (seed {$seedValue})"
                    );
                }
            });
    }

    /**
     * The 429 and 409 create responses and the reset 409 (public-lab
     * Requirements 3.3, 3.4, 4.9, 13.18, 13.19).
     */
    public function testLimitAndConflictResponsesAreUncacheable(): void
    {
        $this->limitTo(30)
            ->forAll(Generators::choose(0, 3))
            ->then(function (int $scope): void {
                $repo = new InMemoryEpisodeRepository();
                $turns = new TurnService(
                    new FakeRobotClient(),
                    new FakeRobotClient(),
                    new FakeRecorderClient(),
                    new ReplayEngineFactory($repo),
                    $repo,
                    new Config(),
                );
                $memory = new MemoryService($repo);
                $usage = new \PoltergeistSwitch\Tests\TurnLoop\InMemoryUsageRepository();
                $hasher = new \PoltergeistSwitch\TurnLoop\IpHasher(str_repeat('cd', 32));
                $limits = [[1, 30, 100], [10, 1, 100], [10, 30, 1], [10, 30, 100]][$scope];
                $starter = new \PoltergeistSwitch\TurnLoop\EpisodeStarter(
                    $turns,
                    $memory,
                    $usage,
                    new \PoltergeistSwitch\TurnLoop\DailyLimitPolicy($limits[0], $limits[1], $limits[2]),
                );
                $create = static fn (string $device, string $ip) => ps_dispatch(
                    $turns,
                    'POST',
                    'episodes',
                    [PS_DEVICE_COOKIE => $device],
                    [],
                    null,
                    $memory,
                    null,
                    $starter,
                    $ip,
                    $hasher,
                );

                $first = $create(self::LIMIT_DEVICE, self::LIMIT_IP);
                $this->assertSame(200, $first->status);

                // 409: the Device's episode is still running (wins over any limit).
                $conflict = $create(self::LIMIT_DEVICE, self::LIMIT_IP);
                $this->assertSame(409, $conflict->status);

                // Reset 409 while that episode runs.
                $resetConflict = ps_dispatch(
                    $turns,
                    'POST',
                    'memory/reset',
                    [PS_DEVICE_COOKIE => self::LIMIT_DEVICE],
                    [],
                    null,
                    $memory,
                );
                $this->assertSame(409, $resetConflict->status);

                $responses = ['create 409' => $conflict, 'reset 409' => $resetConflict];

                // 429: end the episode, then start again (scope 3 has no limit to hit).
                $running = $repo->findRunningEpisodeForDevice(self::LIMIT_DEVICE);
                $this->assertNotNull($running);
                $repo->markEpisodeEnded($running->id);
                $second = $create(
                    $scope === 0 ? self::LIMIT_DEVICE : '00000000000000000000000000000001',
                    $scope === 2 ? '192.0.2.200' : self::LIMIT_IP,
                );
                if ($scope < 3) {
                    $this->assertSame(429, $second->status);
                    $responses['create 429'] = $second;
                }

                foreach ($responses as $label => $response) {
                    $this->assertSame('no-store', $response->headers['Cache-Control'] ?? null, "{$label} no-store (scope {$scope})");
                    $this->assertIsArray(json_decode($response->body, true), "{$label} parses as JSON (scope {$scope})");
                }
            });
    }

    /**
     * Seed up to $count clean active memories of the current generation, spread
     * across both robots, so status and reflect report a range of counts. The
     * bodies are plain, non-secret strings.
     */
    private function seedMemories(InMemoryEpisodeRepository $repo, int $count): void
    {
        $generation = $repo->currentGeneration();
        $now = date('Y-m-d H:i:s');

        for ($i = 0; $i < $count; $i++) {
            $robotId = ($i % 2 === 0) ? self::ROBOT_A : self::ROBOT_B;
            $repo->appendMemory(new MemoryRow(
                robotId: $robotId,
                generation: $generation,
                disaster: 'earthquake',
                sourceEpisodeId: 'seed_' . $i,
                body: "A quiet note number {$i}.",
                status: 'active',
                createdAt: $now,
            ));
        }
    }

    /**
     * Build one ended, reflectable episode: a quiet applied turn 0, an (empty)
     * frozen Memory_Snapshot so ReflectService passes the not_reflectable
     * guard, and the ended flag set.
     */
    private function buildEndedReflectableEpisode(InMemoryEpisodeRepository $repo, int $seedValue): string
    {
        $now = date('Y-m-d H:i:s');
        $episodeId = $repo->createEpisode(new EpisodeRow(
            id: 'ep_nostore',
            deviceId: 'device_nostore',
            disaster: 'earthquake',
            seed: $seedValue,
            currentExpectedTurn: 1,
            earthquakeStartTurn: 0,
            ended: false,
            createdAt: $now,
            lastActivityAt: $now,
        ));

        $repo->storeEpisodeMemory(
            $episodeId,
            $repo->currentGeneration(),
            new MemorySnapshot([]),
            new MemorySnapshot([]),
        );

        $repo->createTurn($episodeId, 0);
        $repo->setTurnState($episodeId, 0, TurnStateValue::applied->value);

        $repo->markEpisodeEnded($episodeId);

        return $episodeId;
    }

    /**
     * Configure one robot's reflect reply so the reflect body varies between
     * the answered-memory shape (a non-empty answer stored verbatim) and the
     * failure shape (an unanswered call reporting a short machine reason).
     */
    private function configureReply(
        InMemoryEpisodeRepository $repo,
        string $episodeId,
        string $robotId,
        FakeRobotClient $client,
        bool $answers
    ): void {
        $prompt = $this->promptFor($repo, $episodeId, $robotId);

        if ($answers) {
            $client->setReplyForPrompt(
                $prompt,
                new ReplyRecord(
                    $prompt,
                    "I want to remember this time ({$robotId}).",
                    10,
                    5,
                    0.1,
                    0.0,
                    false,
                    false,
                ),
            );

            return;
        }

        $client->setReplyForPrompt(
            $prompt,
            ReplyRecord::unanswered($prompt, 0.0, 'timeout'),
        );
    }

    /**
     * The Reflection_Text the service would send this robot, built the same way
     * the service builds it so the per-prompt reply matches.
     */
    private function promptFor(
        InMemoryEpisodeRepository $repo,
        string $episodeId,
        string $robotId
    ): string {
        $factory = new ReplayEngineFactory($repo, new EpisodeConfig());
        $built = $factory->forEpisodeWithWorld($episodeId);
        $engine = $built['engine'];
        $finalWorld = $built['world'];

        $records = [];
        foreach ($engine->turnRecords() as $record) {
            if ($record->turnNumber >= 1) {
                $records[] = $record;
            }
        }

        $builder = new ReflectionTextBuilder(new SituationTextGenerator());

        return $builder->build(
            $robotId,
            $records,
            0,
            $finalWorld,
            $repo->episodeSnapshot($episodeId, $robotId),
        );
    }
}
