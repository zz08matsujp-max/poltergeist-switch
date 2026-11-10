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
use PoltergeistSwitch\TurnLoop\Values\TurnStateValue;
use PoltergeistSwitch\TurnLoop\WorldStateBackfill;

require_once __DIR__ . '/../../api/bootstrap.php';

/**
 * Direct replay links (public-lab Requirements 7.1, 7.2, 7.3, 14.8).
 *
 * API side: GET /api/episodes/{id} serves an ended episode that appears in no
 * list and an episode that belongs to another Device. Page side (source
 * check): replay.php / replay.js play ?episode=<id> without consulting a list
 * first, and an unknown id shows the not-found message and then the list.
 */
final class DirectAccessTest extends TestCase
{
    private const OWNER = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const OTHER = '0f9e8d7c6b5a49382716f5e4d3c2b1a0';

    private int $now = 1_700_000_000;

    public function testEndedEpisodeInNoListIsServedByDirectGet(): void
    {
        $repo = new InMemoryEpisodeRepository(new Config(), fn (): int => $this->now);
        $service = $this->turnService($repo);
        $id = $this->endedEpisode($repo, $service, self::OWNER);

        // The owner resets after the run: the episode leaves "Your runs", and it
        // is not Featured, so it is in no list for anyone.
        $this->now += 100;
        $repo->recordDeviceReset(self::OWNER, date('Y-m-d H:i:s', $this->now));
        $list = $this->listService($repo);
        foreach ([[], [PS_DEVICE_COOKIE => self::OWNER], [PS_DEVICE_COOKIE => self::OTHER]] as $cookies) {
            $response = ps_dispatch($service, 'GET', 'episodes', $cookies, ['ended' => '1'], $list);
            $body = json_decode($response->body, true);
            $this->assertSame(200, $response->status);
            $ids = array_merge(array_column($body['featured'], 'id'), array_column($body['yours'], 'id'));
            $this->assertNotContains($id, $ids, 'the episode is in no list');
        }

        $get = ps_dispatch($service, 'GET', 'episodes/' . $id, []);
        $this->assertSame(200, $get->status);
        $this->assertSame('no-store', $get->headers['Cache-Control'] ?? null);
        $view = json_decode($get->body, true);
        $this->assertTrue($view['ended']);
    }

    public function testAnotherDevicesEpisodeIsServedWithAndWithoutCookies(): void
    {
        $repo = new InMemoryEpisodeRepository(new Config(), fn (): int => $this->now);
        $service = $this->turnService($repo);
        $id = $this->endedEpisode($repo, $service, self::OWNER);

        $bodies = [];
        foreach ([[], [PS_DEVICE_COOKIE => self::OTHER], [PS_DEVICE_COOKIE => self::OWNER]] as $cookies) {
            $response = ps_dispatch($service, 'GET', 'episodes/' . $id, $cookies);
            $this->assertSame(200, $response->status);
            $bodies[] = $response->body;
        }
        $this->assertSame($bodies[0], $bodies[1], 'another Device sees the same bytes');
        $this->assertSame($bodies[0], $bodies[2]);
        $this->assertStringNotContainsString(self::OWNER, $bodies[0], 'no Device_Id in the body');
    }

    public function testUnknownIdIs404(): void
    {
        $repo = new InMemoryEpisodeRepository(new Config(), fn (): int => $this->now);
        $response = ps_dispatch(
            $this->turnService($repo),
            'GET',
            'episodes/00000000-0000-4000-8000-000000000000',
            [],
        );
        $this->assertSame(404, $response->status);
    }

    public function testReplayPagePlaysEpisodeIdWithoutConsultingAList(): void
    {
        $js = (string) file_get_contents(__DIR__ . '/../../public/js/replay.js');
        $php = (string) file_get_contents(__DIR__ . '/../../public/replay.php');

        // replay.php is static: no PHP reads of a list or the database.
        $this->assertStringNotContainsString('episodes?ended', $php);
        $this->assertStringNotContainsString('listEnded', $php);

        // The mode dispatch calls directLoad(id) for ?episode= and showList() otherwise.
        $this->assertMatchesRegularExpression(
            '/if \(episodeId\) \{\s*directLoad\(episodeId\);\s*\} else \{\s*showList\(\);\s*\}/',
            $js,
        );

        // directLoad fetches the one episode and, on success, starts playback
        // without touching the list.
        $this->assertSame(1, preg_match('/function directLoad\(id\) \{(.*?)\n  \}\n/s', $js, $m));
        $body = $m[1];
        $this->assertStringContainsString('api.getEpisode(id)', $body);
        $this->assertStringNotContainsString('listEnded', $body);
        $this->assertStringNotContainsString('showList(', $body);
        $success = substr($body, (int) strpos($body, 'showPlaybackRegion()'));
        $success = substr($success, 0, (int) strpos($success, '}, function'));
        $this->assertStringContainsString('startPlayback(res.body)', $success);
        $this->assertStringNotContainsString('fallbackToList', $success);
    }

    public function testUnknownIdShowsNotFoundMessageThenTheList(): void
    {
        $js = (string) file_get_contents(__DIR__ . '/../../public/js/replay.js');

        $this->assertStringContainsString("fallbackToList('That episode could not be found.')", $js);
        $this->assertSame(1, preg_match('/function fallbackToList\(message\) \{(.*?)\n  \}\n/s', $js, $m));
        $this->assertLessThan(
            (int) strpos($m[1], 'showList()'),
            (int) strpos($m[1], 'showError(message)'),
            'the message is shown, then the list',
        );
    }

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

    private function listService(InMemoryEpisodeRepository $repo): EpisodeListService
    {
        return new EpisodeListService(
            $repo,
            new WorldStateBackfill($repo, new ReplayEngineFactory($repo)),
            new Config(),
        );
    }

    /** Create an earthquake episode for $device and drive it to its end. */
    private function endedEpisode(InMemoryEpisodeRepository $repo, TurnService $service, string $device): string
    {
        $id = $service->createEpisode($device, seed: 4242)->episodeId;
        $service->flipEarthquake($id);

        for ($applied = 0; $applied <= 100; $applied++) {
            $episode = $repo->findEpisode($id);
            self::assertNotNull($episode);
            if ($episode->ended) {
                return $id;
            }
            $n = $episode->currentExpectedTurn;
            $guard = 0;
            do {
                $service->ask($id, $n);
                $turn = $repo->findTurn($id, $n);
                self::assertNotNull($turn);
            } while ($turn->state === TurnStateValue::waiting && ++$guard < 5);
            $service->apply($id, $n);
        }
        self::fail('the episode did not end within the bound');
    }
}
