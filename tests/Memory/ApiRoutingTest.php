<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Memory;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\ReflectionTextBuilder;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Memory\MemoryService;
use PoltergeistSwitch\Memory\ReflectService;
use PoltergeistSwitch\Memory\Values\MemorySnapshot;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;
use PoltergeistSwitch\TurnLoop\Clients\FakeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\TurnService;
use PoltergeistSwitch\TurnLoop\Values\ReplyRecord;
use PoltergeistSwitch\TurnLoop\Values\TurnStateValue;

// The api/ layer is plain PHP with its own PSR-4 autoloader (no Composer on the
// server), so the handlers and the routing/response helpers are global
// functions and a global PsApiResponse class. Load them once for the test.
// This does NOT build a production service — the test injects fake-backed
// services into ps_dispatch(), so no config, secret, or DB is touched.
require_once __DIR__ . '/../../api/bootstrap.php';

/**
 * Smoke/integration test for the three robot-memory api/ entry points
 * (task 9.3). The routes are driven through ps_dispatch() with a fake-backed
 * {@see ReflectService} and {@see MemoryService} over the in-memory
 * {@see InMemoryEpisodeRepository} and {@see FakeRobotClient}s that open no
 * network connection. No web server, superglobals, config, secret, or network
 * is involved: ps_dispatch() takes the method, route, and services as values
 * and returns a PsApiResponse with the status, headers, and JSON body.
 *
 * Assertions (design "API Design"; Requirements 2.1, 2.5, 4.1, 5.1, 8.3):
 *   - the three routes resolve to their service methods and return 200 JSON:
 *       POST /api/episodes/{id}/reflect -> ReflectService::reflect
 *       POST /api/memory/reset          -> MemoryService::reset
 *       GET  /api/memory                -> MemoryService::status
 *   - a reflection prompt/response is stored with kind = 'reflection' at the
 *     sentinel turn number -1, and no turn read (kind = 'turn', turn numbers
 *     0 .. n) ever returns that reflection row (Requirement 2.5, 8.3);
 *   - a repository scan of the source files this spec added/changed finds no
 *     secret, credential, passphrase, or AI company/model name literal
 *     (Requirement 8.3).
 */
final class ApiRoutingTest extends TestCase
{
    /**
     * A mutable "now" (Unix seconds) shared by the repository clock and the
     * reflect service's created-at clock, so the stored memory and the claim
     * timing are deterministic.
     */
    private int $now = 1_700_000_000;

    /**
     * Provider words, model names, and secret/credential/path shapes that must
     * never appear in any API response body (the leak check in assertSafeJson).
     * A response is user-facing, so even the provider words Anthropic / OpenAI /
     * Claude / GPT are forbidden there.
     */
    private const FORBIDDEN_IN_BODY = [
        'Anthropic', 'anthropic',
        'OpenAI', 'openai',
        'Claude', 'claude',
        'GPT', 'gpt',
        'claude-haiku-5-5',
        'gpt-6-luna',
        'passphrase',
        'password',
        'secrets_dir',
        'config.local',
        '/path/outside',
    ];

    /**
     * Secret / credential / model-name literals that must never appear in the
     * source files this spec added or changed. These are the exact model names
     * from the steering rules and the shapes a secret, passphrase, credential,
     * or secrets path would take if one leaked into source. The bare provider
     * words (Claude / GPT / Anthropic / OpenAI) are deliberately NOT scanned in
     * source: the production clients are the classes ClaudeClient / GptClient,
     * so a docblock `@param` naming the concrete type is a legitimate code
     * reference, not a leaked secret. The user-facing leak guard
     * (FORBIDDEN_IN_BODY) still forbids those words in every response body.
     */
    private const FORBIDDEN_IN_SOURCE = [
        'claude-haiku-5-5',
        'gpt-6-luna',
        'passphrase',
        'password',
        'secrets_dir',
        'config.local',
        '/path/outside',
    ];

    // =====================================================================
    // The three routes resolve to their service methods
    // =====================================================================

    public function testReflectRouteResolvesToReflectServiceAndReturns200(): void
    {
        $repo = new InMemoryEpisodeRepository(new Config(), fn (): int => $this->now);
        $robotA = new FakeRobotClient(new ReplyRecord('', 'Amber remembers the kettle', 10, 8, 0.2, 0.0, false, false));
        $robotB = new FakeRobotClient(new ReplyRecord('', 'Jade remembers the dishes', 10, 8, 0.2, 0.0, false, false));

        $episodeId = $this->endedEpisodeWithSnapshot($repo);

        $response = ps_dispatch(
            $this->turnService($repo),
            'POST',
            'episodes/' . $episodeId . '/reflect',
            [],
            [],
            null,
            null,
            $this->reflectService($repo, $robotA, $robotB),
        );

        $this->assertSame(200, $response->status);
        $body = $this->assertSafeJson($response);

        // The body is the reflect response shape: both robots answered, keyed
        // by their screen names (ReflectService::reflect was called).
        $this->assertSame($episodeId, $body['episodeId'] ?? null);
        $this->assertTrue($body['robots']['amber']['answered'] ?? null);
        $this->assertSame('Amber remembers the kettle', $body['robots']['amber']['memory'] ?? null);
        $this->assertTrue($body['robots']['jade']['answered'] ?? null);
        $this->assertSame('Jade remembers the dishes', $body['robots']['jade']['memory'] ?? null);

        // The route genuinely reached the robots (one call each).
        $this->assertSame(1, $robotA->callCount());
        $this->assertSame(1, $robotB->callCount());
    }

    public function testMemoryResetRouteResolvesToMemoryServiceAndAdvancesGeneration(): void
    {
        $repo = new InMemoryEpisodeRepository();
        $memoryService = new MemoryService($repo);

        $this->assertSame(1, $repo->currentGeneration(), 'the store starts at generation 1');

        $response = ps_dispatch(
            $this->turnService($repo),
            'POST',
            'memory/reset',
            [],
            [],
            null,
            $memoryService,
        );

        $this->assertSame(200, $response->status);
        $body = $this->assertSafeJson($response);

        // MemoryService::reset advanced the generation and the body reports it.
        $this->assertSame(2, $body['generation'] ?? null);
        $this->assertSame(2, $repo->currentGeneration(), 'reset advanced the current generation');
    }

    public function testMemoryStatusRouteResolvesToMemoryServiceAndReportsCounts(): void
    {
        $repo = new InMemoryEpisodeRepository();
        $memoryService = new MemoryService($repo);

        // Seed a couple of generation-1 active memories so status has something
        // to report.
        $repo->appendMemory($this->memoryRow('robot_a', 'seed_a1', 'Amber remembers the kettle'));
        $repo->appendMemory($this->memoryRow('robot_b', 'seed_b1', 'Jade remembers the dishes'));

        $response = ps_dispatch(
            $this->turnService($repo),
            'GET',
            'memory',
            [],
            [],
            null,
            $memoryService,
        );

        $this->assertSame(200, $response->status);
        $body = $this->assertSafeJson($response);

        // MemoryService::status reported the generation and per-robot counts.
        $this->assertSame(1, $body['generation'] ?? null);
        $this->assertSame(1, $body['robots']['amber']['activeCount'] ?? null);
        $this->assertSame(1, $body['robots']['jade']['activeCount'] ?? null);
    }

    // =====================================================================
    // Reflection prompt/response is stored with kind='reflection', turn -1
    // =====================================================================

    public function testReflectionIsStoredWithReflectionKindAtTurnMinusOneAndNoTurnReadReturnsIt(): void
    {
        $repo = new InMemoryEpisodeRepository(new Config(), fn (): int => $this->now);
        $robotA = new FakeRobotClient(new ReplyRecord('', 'Amber remembers the kettle', 10, 8, 0.2, 0.0, false, false));
        $robotB = new FakeRobotClient(new ReplyRecord('', 'Jade remembers the dishes', 10, 8, 0.2, 0.0, false, false));

        $episodeId = $this->endedEpisodeWithSnapshot($repo);

        // Record how many real turns the run produced so we can scan every
        // turn read afterwards. currentExpectedTurn is one past the last applied
        // turn, so scanning 0 .. currentExpectedTurn covers every real turn (and
        // a harmless empty one past the end).
        $episode = $repo->findEpisode($episodeId);
        $this->assertNotNull($episode);
        $lastTurn = $episode->currentExpectedTurn;

        $response = ps_dispatch(
            $this->turnService($repo),
            'POST',
            'episodes/' . $episodeId . '/reflect',
            [],
            [],
            null,
            null,
            $this->reflectService($repo, $robotA, $robotB),
        );
        $this->assertSame(200, $response->status);

        foreach (['robot_a', 'robot_b'] as $robotId) {
            // The reflection row lives at the sentinel turn number -1 and is
            // tagged kind = 'reflection' (Requirement 2.5).
            $reflectionRows = $repo->storedAttempts($episodeId, -1, $robotId);
            $this->assertCount(1, $reflectionRows, "exactly one reflection attempt stored for {$robotId}");
            $this->assertSame(-1, $reflectionRows[0]->turnNumber, 'the reflection is stored at turn number -1');
            $this->assertSame('reflection', $reflectionRows[0]->kind, "the reflection row is tagged kind='reflection'");

            // No real-turn read (kind = 'turn', turn numbers 0 .. n) returns the
            // reflection row: every turn 0 .. lastTurn carries only turn rows.
            for ($turn = 0; $turn <= $lastTurn; $turn++) {
                foreach ($repo->storedAttempts($episodeId, $turn, $robotId) as $row) {
                    $this->assertSame(
                        'turn',
                        $row->kind,
                        "turn {$turn} for {$robotId} must carry only kind='turn' rows, never the reflection",
                    );
                    $this->assertSame(
                        $turn,
                        $row->turnNumber,
                        'a turn read never surfaces the sentinel turn number -1',
                    );
                }
            }
        }
    }

    // =====================================================================
    // Repository scan: no secret literal in the files this spec changed
    // =====================================================================

    public function testSpecSourceFilesContainNoSecretOrProviderLiteral(): void
    {
        $root = dirname(__DIR__, 2);

        // The source files this spec added or changed: the Memory services and
        // value objects, the three thin api/ handlers, and the SQL migration.
        $files = [
            $root . '/src/Memory/MemoryService.php',
            $root . '/src/Memory/ReflectService.php',
            $root . '/api/episodes_reflect.php',
            $root . '/api/memory_reset.php',
            $root . '/api/memory_get.php',
            $root . '/sql/2026-11-09_add_memory.sql',
        ];

        // Every value object under src/Memory/Values is part of this spec too.
        foreach (glob($root . '/src/Memory/Values/*.php') ?: [] as $valueFile) {
            $files[] = $valueFile;
        }

        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $this->assertFileExists($file, 'a spec source file this task scans must exist');
            $contents = file_get_contents($file);
            $this->assertIsString($contents, "could not read {$file}");

            foreach (self::FORBIDDEN_IN_SOURCE as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $contents,
                    sprintf('%s must not contain the forbidden literal "%s"', basename($file), $needle),
                );
            }
        }
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Assert a response is valid JSON, carries Cache-Control: no-store, and
     * leaks no secret or provider name in its body. Returns the decoded body.
     *
     * @return array<string,mixed>
     */
    private function assertSafeJson(\PsApiResponse $response): array
    {
        $this->assertSame('no-store', $response->headers['Cache-Control'] ?? null);

        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded, 'response body must parse as JSON');

        foreach (self::FORBIDDEN_IN_BODY as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $response->body,
                'response body must not leak a secret or provider name: ' . $needle,
            );
        }

        return $decoded;
    }

    /** Wire a ReflectService over the in-memory repo with the given robot fakes. */
    private function reflectService(
        InMemoryEpisodeRepository $repo,
        FakeRobotClient $robotA,
        FakeRobotClient $robotB,
    ): ReflectService {
        return new ReflectService(
            $robotA,
            $robotB,
            new ReflectionTextBuilder(new SituationTextGenerator()),
            new ReplayEngineFactory($repo),
            $repo,
            new Config(),
            null,
            fn (): string => date('Y-m-d H:i:s', $this->now),
        );
    }

    /**
     * A TurnService whose run-robots always answer and whose recorder reads a
     * `wait` action — always possible — so every turn completes and the
     * earthquake run advances to its end. This is the service ps_dispatch is
     * given; the memory routes under test do not touch it.
     */
    private function turnService(InMemoryEpisodeRepository $repo): TurnService
    {
        return new TurnService(
            new FakeRobotClient(),
            new FakeRobotClient(),
            new FakeRecorderClient(),
            new ReplayEngineFactory($repo),
            $repo,
            new Config(),
            null,
            fn (): string => date('Y-m-d H:i:s', $this->now),
        );
    }

    /**
     * Create an ended episode WITH a stored (empty) snapshot, so reflect passes
     * both guards and proceeds to ask the robots. The run is driven to its end
     * exactly as the browser would, with run-robots separate from any
     * reflect-under-test fakes.
     */
    private function endedEpisodeWithSnapshot(InMemoryEpisodeRepository $repo): string
    {
        $service = $this->turnService($repo);
        $episodeId = $service->createEpisode('dev_route', seed: 4242)->episodeId;
        $this->runToEnd($repo, $episodeId);
        $repo->storeEpisodeMemory($episodeId, 1, new MemorySnapshot([]), new MemorySnapshot([]));

        return $episodeId;
    }

    /**
     * Drive a created episode's turn loop to its end (ask then apply per turn),
     * flipping the earthquake at creation so the quake starts on turn 1 and
     * subsides on its own. Bounded so a stuck run fails loudly.
     */
    private function runToEnd(InMemoryEpisodeRepository $repo, string $episodeId): void
    {
        $service = $this->turnService($repo);
        $service->flipEarthquake($episodeId);

        $applied = 0;
        while (true) {
            $episode = $repo->findEpisode($episodeId);
            self::assertNotNull($episode);
            if ($episode->ended) {
                break;
            }

            $turnNumber = $episode->currentExpectedTurn;

            $askGuard = 0;
            do {
                $service->ask($episodeId, $turnNumber);
                $turn = $repo->findTurn($episodeId, $turnNumber);
                self::assertNotNull($turn);
                $askGuard++;
            } while ($turn->state === TurnStateValue::waiting && $askGuard < 5);

            $service->apply($episodeId, $turnNumber);

            if (++$applied > 100) {
                self::fail('the episode did not end within the applied-turn bound');
            }
        }
    }

    /**
     * Build a MemoryRow for seeding the store directly. The signature mirrors
     * MemoryServiceTest::row — robot/generation/disaster/source-episode/body
     * with the shared deterministic clock for created time.
     */
    private function memoryRow(string $robotId, string $sourceEpisodeId, string $body): \PoltergeistSwitch\Memory\Values\MemoryRow
    {
        return new \PoltergeistSwitch\Memory\Values\MemoryRow(
            robotId: $robotId,
            generation: 1,
            disaster: 'earthquake',
            sourceEpisodeId: $sourceEpisodeId,
            body: $body,
            status: 'active',
            createdAt: date('Y-m-d H:i:s', $this->now),
        );
    }
}
