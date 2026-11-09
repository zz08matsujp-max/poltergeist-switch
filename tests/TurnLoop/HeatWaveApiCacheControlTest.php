<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\TurnLoop\Clients\FakeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\TurnService;

// Load the api/ handlers and the routing/response helpers once, exactly as the
// turn-loop ApiTest does. This injects a fake-backed TurnService into
// ps_dispatch(), so no config, secret, DB, or network is touched.
require_once __DIR__ . '/../../api/bootstrap.php';

/**
 * Integration check for the heat-wave API responses (task 12.5). It drives the
 * api/ handlers through ps_dispatch() with a fake-backed {@see TurnService}
 * (fake clients open no network connection, so no real provider API call) and
 * asserts that a heat-wave API response carries the HTTP header
 * `Cache-Control: no-store` (heat-wave Requirement 8.7).
 *
 * This is an example check, not a property: it exercises the heat-wave create
 * (`POST /api/episodes` with `disaster=heat_wave`), the heat-wave flip
 * (`POST /api/episodes/{id}/disaster` with `disaster=heat_wave`), and the GET
 * view of a heat-wave episode, confirming the shared no-store header is set on
 * each.
 *
 * Requirements: 8.7
 */
class HeatWaveApiCacheControlTest extends TestCase
{
    private function service(InMemoryEpisodeRepository $repo): TurnService
    {
        return new TurnService(
            new FakeRobotClient(),
            new FakeRobotClient(),
            new FakeRecorderClient(),
            new ReplayEngineFactory($repo),
            $repo,
            new Config(),
        );
    }

    public function testHeatWaveResponsesSendCacheControlNoStore(): void
    {
        $repo = new InMemoryEpisodeRepository();
        $service = $this->service($repo);

        // Create a heat-wave episode via the disaster query param.
        $create = ps_dispatch(
            $service,
            'POST',
            'episodes',
            [],
            ['route' => 'episodes', 'disaster' => 'heat_wave'],
        );
        $this->assertSame(200, $create->status);
        $this->assertSame(
            'no-store',
            $create->headers['Cache-Control'] ?? null,
            'the heat-wave create response must send Cache-Control: no-store',
        );

        $created = json_decode($create->body, true);
        $this->assertIsArray($created);
        $this->assertSame('heat_wave', $created['disaster'] ?? null);
        $episodeId = $created['episodeId'];
        $this->assertIsString($episodeId);

        $cookies = ['ps_device' => 'device-heat'];

        // Fix the heat idempotently via the disaster endpoint.
        $flip = ps_dispatch(
            $service,
            'POST',
            'episodes/' . $episodeId . '/disaster',
            $cookies,
            ['route' => 'episodes/' . $episodeId . '/disaster', 'disaster' => 'heat_wave'],
        );
        $this->assertSame(200, $flip->status);
        $this->assertSame(
            'no-store',
            $flip->headers['Cache-Control'] ?? null,
            'the heat-wave flip response must send Cache-Control: no-store',
        );

        // The GET view of a heat-wave episode is also uncacheable.
        $get = ps_dispatch(
            $service,
            'GET',
            'episodes/' . $episodeId,
            $cookies,
            ['route' => 'episodes/' . $episodeId],
        );
        $this->assertSame(200, $get->status);
        $this->assertSame(
            'no-store',
            $get->headers['Cache-Control'] ?? null,
            'the heat-wave GET response must send Cache-Control: no-store',
        );

        $view = json_decode($get->body, true);
        $this->assertIsArray($view);
        $this->assertSame('heat_wave', $view['disaster'] ?? null);
    }

    public function testConvertingAWaitingDefaultEpisodeToHeatWaveStaysUncacheable(): void
    {
        // The dual-state lever path: a default episode created first, then
        // converted to heat wave in place by the disaster endpoint (no second
        // episode, no 409). The conversion response must still be uncacheable.
        $repo = new InMemoryEpisodeRepository();
        $service = $this->service($repo);

        $create = ps_dispatch($service, 'POST', 'episodes', []);
        $this->assertSame(200, $create->status);
        $episodeId = json_decode($create->body, true)['episodeId'];
        $cookies = ['ps_device' => 'device-convert'];

        $convert = ps_dispatch(
            $service,
            'POST',
            'episodes/' . $episodeId . '/disaster',
            $cookies,
            ['route' => 'episodes/' . $episodeId . '/disaster', 'disaster' => 'heat_wave'],
        );

        $this->assertSame(200, $convert->status);
        $this->assertSame(
            'no-store',
            $convert->headers['Cache-Control'] ?? null,
            'the convert-to-heat-wave response must send Cache-Control: no-store',
        );

        $converted = json_decode($convert->body, true);
        $this->assertIsArray($converted);
        // Converted in place: same episode id, now a heat wave.
        $this->assertSame($episodeId, $converted['episodeId'] ?? null);
        $this->assertSame('heat_wave', $converted['disaster'] ?? null);
    }
}
