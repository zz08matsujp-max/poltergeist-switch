<?php
declare(strict_types=1);
namespace PoltergeistSwitch\Tests\TurnLoop;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\TurnLoop\Clients\FakeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\TurnService;

require_once __DIR__ . '/../../api/bootstrap.php';

/**
 * Protection pin (public-lab Requirements 1.3, 1.4, 1.5): the exact bytes of an
 * earthquake episode view, serialized as the API does, captured from the code
 * as it was before any public-lab change. The expected values below are literals
 * and are never edited afterwards.
 *
 * The episode id is random, so it is replaced by a fixed text before encoding.
 */
final class EpisodeViewBytesTest extends TestCase
{
    private const EXPECTED_LENGTH = 10313;
    private const EXPECTED_SHA256 = '1ac398b7278f64eb1fa03b6632510eaf5963a8b26f0bd5c1380f9aa8bf65d92b';

    public function testSeededEarthquakeEpisodeViewBytesAreUnchanged(): void
    {
        $json = $this->viewJson();

        $this->assertSame(self::EXPECTED_LENGTH, strlen($json));
        $this->assertSame(self::EXPECTED_SHA256, hash('sha256', $json));
    }

    private function viewJson(): string
    {
        $repo = new InMemoryEpisodeRepository();
        $service = new TurnService(
            new FakeRobotClient(),
            new FakeRobotClient(),
            new FakeRecorderClient(),
            new ReplayEngineFactory($repo),
            $repo,
            new Config(),
        );
        $created = $service->createEpisode('dev_bytes', seed: 20261103);
        $episodeId = $created->episodeId;
        $service->flipEarthquake($episodeId);
        for ($turn = 1; $turn <= 3; $turn++) {
            $service->ask($episodeId, $turn);
            $service->apply($episodeId, $turn);
        }
        $array = ps_episode_view_to_array($service->getEpisode($episodeId));
        $array['episodeId'] = 'fixed-episode-id';

        return json_encode($array, JSON_THROW_ON_ERROR);
    }
}
