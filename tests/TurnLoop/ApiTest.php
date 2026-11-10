<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\TurnLoop\Clients\FakeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\EpisodeListService;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\TurnService;
use PoltergeistSwitch\TurnLoop\WorldStateBackfill;

// The api/ layer is plain PHP with its own PSR-4 autoloader (no Composer on the
// server), so the handlers and the routing/response helpers are global
// functions and a global PsApiResponse class. Load them once for the test.
// This does NOT build a production TurnService — the test injects a
// fake-backed one into ps_dispatch(), so no config, secret, or DB is touched.
require_once __DIR__ . '/../../api/bootstrap.php';

/**
 * Integration tests for the api/ entry points (task 14.2). They drive the
 * handlers through ps_dispatch() with a fake-backed {@see TurnService} — the
 * same TurnService the unit tests build over the in-memory repository and the
 * real {@see ReplayEngineFactory}, with fake clients that open no network
 * connection (Requirement 14.1). No real web server, superglobals, or network
 * is involved: ps_dispatch() takes the method, route, and cookies as values and
 * returns a PsApiResponse with the status, headers, and JSON body.
 *
 * Assertions (design "API Design"; Requirements 9.2, 9.3, 9.5, 9.6, 2.6):
 *   - every response parses as JSON and carries Cache-Control: no-store;
 *   - an unknown episode id -> 404;
 *   - a request with no device cookie sets a device cookie;
 *   - no response body carries a key, credential, filesystem path, or provider
 *     name (the models claude-haiku-5-5 / gpt-6-luna and the provider words
 *     Anthropic / OpenAI / Claude / GPT must not appear).
 */
class ApiTest extends TestCase
{
    /** Provider words and model names that must never appear in any body. */
    private const FORBIDDEN_SUBSTRINGS = [
        'Anthropic', 'anthropic',
        'OpenAI', 'openai',
        'Claude', 'claude',
        'GPT', 'gpt',
        'claude-haiku-5-5',
        'gpt-6-luna',
        '.key',
        'secrets_dir',
        'password',
        'config.local',
        '/path/outside',
    ];

    private function service(?InMemoryEpisodeRepository $repo = null): TurnService
    {
        $repo ??= new InMemoryEpisodeRepository();

        return new TurnService(
            new FakeRobotClient(),
            new FakeRobotClient(),
            new FakeRecorderClient(),
            new ReplayEngineFactory($repo),
            $repo,
            new Config(),
        );
    }

    /**
     * Build the ended-episode list service over the same in-memory repo the
     * fake-backed TurnService uses, mirroring how index.php wires the
     * production list service beside the TurnService. No config, secret, DB, or
     * network is touched.
     */
    private function listService(InMemoryEpisodeRepository $repo): EpisodeListService
    {
        return new EpisodeListService(
            $repo,
            new WorldStateBackfill($repo, new ReplayEngineFactory($repo)),
            new \PoltergeistSwitch\TurnLoop\Config(),
        );
    }

    /**
     * Create one ended episode in $repo through a real TurnService over fake
     * clients (no network, no AI), so the list has a real ended episode with a
     * stored turn 0 world to build its one-liner from. Returns the episode id.
     */
    private function seedEndedEpisode(InMemoryEpisodeRepository $repo): string
    {
        $service = $this->service($repo);
        $episodeId = $service->createEpisode(str_repeat('ab', 16), seed: 1)->episodeId;
        $repo->markEpisodeEnded($episodeId);

        return $episodeId;
    }

    /**
     * Assert a response is valid JSON, carries Cache-Control: no-store, and
     * leaks no secret or provider name. Returns the decoded body.
     *
     * @return array<string,mixed>
     */
    private function assertSafeJson(\PsApiResponse $response): array
    {
        $this->assertSame('no-store', $response->headers['Cache-Control'] ?? null);

        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded, 'response body must parse as JSON');

        foreach (self::FORBIDDEN_SUBSTRINGS as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $response->body,
                'response body must not leak a secret or provider name: ' . $needle,
            );
        }

        return $decoded;
    }

    public function testCreateWithoutCookieSetsADeviceCookieAndReturnsJson(): void
    {
        $response = ps_dispatch($this->service(), 'POST', 'episodes', []);

        $this->assertSame(200, $response->status);
        $body = $this->assertSafeJson($response);
        $this->assertArrayHasKey('episodeId', $body);

        // A request with no device cookie must set one (Requirement 9.6).
        $this->assertArrayHasKey('Set-Cookie', $response->headers);
        $setCookie = $response->headers['Set-Cookie'];
        $this->assertStringContainsString('ps_device=', $setCookie);
        $this->assertStringContainsString('HttpOnly', $setCookie);
        $this->assertStringContainsString('Secure', $setCookie);
        $this->assertStringContainsString('SameSite=Lax', $setCookie);
        $this->assertStringContainsString('Path=/', $setCookie);
    }

    public function testCreateWithExistingCookieDoesNotSetANewCookie(): void
    {
        $response = ps_dispatch(
            $this->service(),
            'POST',
            'episodes',
            ['ps_device' => '0123456789abcdef0123456789abcdef'],
        );

        $this->assertSame(200, $response->status);
        $this->assertSafeJson($response);
        // A request that already carries a device cookie is not re-set.
        $this->assertArrayNotHasKey('Set-Cookie', $response->headers);
    }

    public function testUnknownEpisodeIdReturns404OnGet(): void
    {
        $response = ps_dispatch($this->service(), 'GET', 'episodes/does-not-exist', []);

        $this->assertSame(404, $response->status);
        $body = $this->assertSafeJson($response);
        $this->assertSame('episode_not_found', $body['error'] ?? null);
    }

    public function testUnknownEpisodeIdReturns404OnDisaster(): void
    {
        $response = ps_dispatch($this->service(), 'POST', 'episodes/nope/disaster', []);

        $this->assertSame(404, $response->status);
        $this->assertSafeJson($response);
    }

    public function testUnknownEpisodeIdReturns404OnAsk(): void
    {
        $response = ps_dispatch($this->service(), 'POST', 'episodes/nope/turns/1/ask', []);

        $this->assertSame(404, $response->status);
        $this->assertSafeJson($response);
    }

    public function testFullTurnDriveStaysJsonAndUncacheable(): void
    {
        // Drive the whole loop through the handlers the way the browser would:
        // create, flip, then ask + apply for the first turn. Every response
        // must be JSON, uncacheable, and leak-free.
        $repo = new InMemoryEpisodeRepository();
        $service = $this->service($repo);

        $create = ps_dispatch($service, 'POST', 'episodes', []);
        $created = $this->assertSafeJson($create);
        $episodeId = $created['episodeId'];
        $this->assertIsString($episodeId);

        // Reuse the device cookie that create set, so later calls are the same
        // device (not strictly required here, but mirrors the browser).
        $cookies = ['ps_device' => '0123456789abcdef0123456789abcd01'];

        $flip = ps_dispatch($service, 'POST', 'episodes/' . $episodeId . '/disaster', $cookies);
        $this->assertSame(200, $flip->status);
        $this->assertSafeJson($flip);

        $ask = ps_dispatch($service, 'POST', 'episodes/' . $episodeId . '/turns/1/ask', $cookies);
        $this->assertSame(200, $ask->status);
        $this->assertSafeJson($ask);

        $apply = ps_dispatch($service, 'POST', 'episodes/' . $episodeId . '/turns/1/apply', $cookies);
        $this->assertSame(200, $apply->status);
        $applied = $this->assertSafeJson($apply);

        // The GET view carries the applied turn's Four_Layers; it must stay
        // leak-free even once robot replies and readings are serialized.
        $get = ps_dispatch($service, 'GET', 'episodes/' . $episodeId, $cookies);
        $this->assertSame(200, $get->status);
        $view = $this->assertSafeJson($get);
        $this->assertSame($episodeId, $view['episodeId']);
        $this->assertArrayHasKey('turns', $view);
    }

    public function testQueryStringRouteIsEquivalentToCleanPath(): void
    {
        // The front controller accepts ?route=... exactly as a clean path, so
        // the API works when mod_rewrite is unavailable. ps_dispatch() takes
        // the already-resolved route string, so both forms funnel here; this
        // guards that a nested route with an id dispatches correctly.
        $repo = new InMemoryEpisodeRepository();
        $service = $this->service($repo);

        $create = ps_dispatch($service, 'POST', 'episodes', []);
        $episodeId = $this->assertSafeJson($create)['episodeId'];

        $get = ps_dispatch($service, 'GET', 'episodes/' . $episodeId, []);
        $this->assertSame(200, $get->status);
        $this->assertSafeJson($get);
    }

    public function testSecondCreateReturns409WithRunningEpisodeId(): void
    {
        // The 409 body carries the running episode's id so Notice_Resume works
        // after a reload (public-lab Requirements 10.4, 13.8).
        $service = $this->service(new InMemoryEpisodeRepository());
        $cookies = ['ps_device' => '0123456789abcdef0123456789abcd02'];

        $first = $this->assertSafeJson(ps_dispatch($service, 'POST', 'episodes', $cookies));
        $second = ps_dispatch($service, 'POST', 'episodes', $cookies);

        $this->assertSame(409, $second->status);
        $this->assertSame(
            ['error' => 'device_already_running', 'episodeId' => $first['episodeId']],
            $this->assertSafeJson($second),
        );
    }

    public function testUnmatchedRouteReturns404(): void
    {
        $response = ps_dispatch($this->service(), 'POST', 'nonsense/path', []);

        $this->assertSame(404, $response->status);
        $this->assertSafeJson($response);
    }

    public function testBadTurnNumberSegmentReturns404(): void
    {
        $repo = new InMemoryEpisodeRepository();
        $service = $this->service($repo);
        $episodeId = $this->assertSafeJson(ps_dispatch($service, 'POST', 'episodes', []))['episodeId'];

        // A non-numeric turn segment is a bad route, not a 409.
        $response = ps_dispatch($service, 'POST', 'episodes/' . $episodeId . '/turns/x/ask', []);
        $this->assertSame(404, $response->status);
        $this->assertSafeJson($response);
    }

    public function testEndedEpisodeListIsJsonUncacheableAndLeakFree(): void
    {
        // GET /api/episodes?ended=1 returns the ended-episode list as a bare
        // JSON array, uncacheable (Requirement 11.9) and leak-free
        // (Requirement 11.7). The route is recognised by `ended=1` on the bare
        // episodes collection.
        $repo = new InMemoryEpisodeRepository();
        $episodeId = $this->seedEndedEpisode($repo);

        $response = ps_dispatch(
            $this->service($repo),
            'GET',
            'episodes',
            [PS_DEVICE_COOKIE => str_repeat('ab', 16)],
            ['route' => 'episodes', 'ended' => '1', 'limit' => '20'],
            $this->listService($repo),
        );

        $this->assertSame(200, $response->status);
        // Cache-Control: no-store on every API response (Requirement 11.9).
        $this->assertSame('no-store', $response->headers['Cache-Control'] ?? null);

        // The body is a bare JSON array of list items; it must parse and leak
        // no secret or provider name.
        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded, 'list body must parse as a JSON array');
        foreach (self::FORBIDDEN_SUBSTRINGS as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $response->body,
                'list body must not leak a secret or provider name: ' . $needle,
            );
        }

        $this->assertSame(['featured', 'yours'], array_keys($decoded));
        $yours = $decoded['yours'];
        $this->assertCount(1, $yours);
        $this->assertSame($episodeId, $yours[0]['id'] ?? null);
        $this->assertArrayHasKey('createdDate', $yours[0]);
        $this->assertArrayHasKey('turns', $yours[0]);
        $this->assertArrayHasKey('startingSituation', $yours[0]);
    }

    public function testEndedEpisodeListRejectsOutOfRangeLimit(): void
    {
        // An out-of-range `limit` is rejected with 400 limit_out_of_range and
        // no list (Requirement 11.6); the 400 body is still uncacheable and
        // leak-free. The service validates and throws; the router maps it.
        $repo = new InMemoryEpisodeRepository();
        $this->seedEndedEpisode($repo);

        $response = ps_dispatch(
            $this->service($repo),
            'GET',
            'episodes',
            [],
            ['route' => 'episodes', 'ended' => '1', 'limit' => '0'],
            $this->listService($repo),
        );

        $this->assertSame(400, $response->status);
        $body = $this->assertSafeJson($response);
        $this->assertSame('limit_out_of_range', $body['error'] ?? null);
    }

    public function testEpisodesCollectionWithoutEndedIsNotTheListRoute(): void
    {
        // A GET on the bare episodes collection WITHOUT `ended=1` is not the
        // list route and falls through to 404 (the list is recognised only by
        // `ended=1`).
        $repo = new InMemoryEpisodeRepository();

        $response = ps_dispatch(
            $this->service($repo),
            'GET',
            'episodes',
            [],
            ['route' => 'episodes'],
            $this->listService($repo),
        );

        $this->assertSame(404, $response->status);
        $this->assertSafeJson($response);
    }
}
