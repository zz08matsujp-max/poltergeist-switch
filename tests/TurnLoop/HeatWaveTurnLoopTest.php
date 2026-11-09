<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\TurnLoop\Clients\FakeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\EpisodeListService;
use PoltergeistSwitch\TurnLoop\Http\ApiError;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\TurnService;
use PoltergeistSwitch\TurnLoop\Values\EpisodeView;
use PoltergeistSwitch\TurnLoop\WorldStateBackfill;

/**
 * Example tests for the heat-wave turn loop and API (task 12.2). They drive a
 * real {@see TurnService} over the in-memory repository and the real
 * {@see ReplayEngineFactory}, with fake clients that open no network connection
 * (no real provider API call).
 *
 * Assertions:
 *   - a created heat-wave episode places the resident sitting at the low table
 *     in the living room in the early afternoon, and the seed varies the
 *     robots' rooms (heat-wave Requirement 8.1);
 *   - `GET /api/episodes/{id}` and the ended-episode list both show "Heat wave"
 *     for a heat-wave episode, while an earthquake episode is unchanged
 *     (heat-wave Requirement 8.3, 1.8).
 *
 * Requirements: 8.1, 8.3
 */
class HeatWaveTurnLoopTest extends TestCase
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

    private function listService(InMemoryEpisodeRepository $repo): EpisodeListService
    {
        return new EpisodeListService(
            $repo,
            new WorldStateBackfill($repo, new ReplayEngineFactory($repo)),
        );
    }

    /**
     * Turn 0's recorded World_State for an episode view, the calm starting
     * situation stored by the quiet turn 0.
     *
     * @return array<string,mixed>
     */
    private function turnZeroWorld(EpisodeView $view): array
    {
        foreach ($view->turns as $turn) {
            if ($turn->turnNumber === 0) {
                $this->assertIsArray($turn->world, 'turn 0 must have a recorded world');

                return $turn->world;
            }
        }

        $this->fail('episode has no turn 0');
    }

    public function testCreatedHeatWaveEpisodePlacesResidentAtLowTableInTheAfternoon(): void
    {
        $repo = new InMemoryEpisodeRepository();
        $service = $this->service($repo);

        $view = $service->createEpisode('dev_heat', seed: 4242, disaster: 'heat_wave');

        $this->assertSame('heat_wave', $view->disaster);

        $world = $this->turnZeroWorld($view);

        // The resident sits at the low table in the living room in the early
        // afternoon (heat-wave Requirement 8.1).
        $this->assertSame('afternoon', $world['timeOfDay']);
        $this->assertSame('living_room', $world['man']['room']);
        $this->assertSame('low_table', $world['man']['spot']);
        $this->assertSame('sitting_at_table', $world['man']['activity']);

        // The stored world is a heat-wave world (it carries the heat keys).
        $this->assertSame('heat_wave', $world['disaster']);
    }

    public function testSeedVariesTheRobotsRoomsInAHeatWaveEpisode(): void
    {
        // Two heat-wave episodes with the SAME seed place the robots
        // identically (the draw is reproducible), and at least one other seed
        // places them differently, so the seed — not a fixed layout — drives
        // the robots' rooms while the resident stays at the low table
        // (heat-wave Requirement 8.1).
        $sameA = $this->robotRoomsFor(4242);
        $sameB = $this->robotRoomsFor(4242);
        $this->assertSame($sameA, $sameB, 'the same seed must place the robots identically');

        $varied = false;
        foreach ([1, 2, 3, 7, 99, 100, 2026] as $otherSeed) {
            if ($this->robotRoomsFor($otherSeed) !== $sameA) {
                $varied = true;
                break;
            }
        }
        $this->assertTrue($varied, 'a different seed must be able to vary the robots\' rooms');
    }

    /**
     * The two robots' starting rooms for a heat-wave episode created with the
     * given seed.
     *
     * @return array{robot_a:string,robot_b:string}
     */
    private function robotRoomsFor(int $seed): array
    {
        $repo = new InMemoryEpisodeRepository();
        $view = $this->service($repo)->createEpisode('dev_' . $seed, seed: $seed, disaster: 'heat_wave');
        $world = $this->turnZeroWorld($view);

        return [
            'robot_a' => $world['robots']['robot_a']['room'],
            'robot_b' => $world['robots']['robot_b']['room'],
        ];
    }

    public function testGetShowsHeatWaveDisaster(): void
    {
        $repo = new InMemoryEpisodeRepository();
        $service = $this->service($repo);

        $created = $service->createEpisode('dev_get', seed: 7, disaster: 'heat_wave');
        $fetched = $service->getEpisode($created->episodeId);

        // GET shows the episode's disaster as heat wave (heat-wave Req 8.3).
        $this->assertSame('heat_wave', $fetched->disaster);
    }

    public function testEndedEpisodeListShowsHeatWaveOnlyForHeatWaveItems(): void
    {
        $repo = new InMemoryEpisodeRepository();
        $service = $this->service($repo);

        // One heat-wave episode and one earthquake episode, both ended.
        $heat = $service->createEpisode('dev_heat_list', seed: 11, disaster: 'heat_wave');
        $repo->markEpisodeEnded($heat->episodeId);

        $quake = $service->createEpisode('dev_quake_list', seed: 12);
        $repo->markEpisodeEnded($quake->episodeId);

        $list = $this->listService($repo)->list(20);

        $byId = [];
        foreach ($list as $item) {
            $byId[$item['id']] = $item;
        }

        // The heat-wave item surfaces disaster = heat_wave so the list shows
        // "Heat wave" (heat-wave Requirement 8.3).
        $this->assertArrayHasKey($heat->episodeId, $byId);
        $this->assertSame('heat_wave', $byId[$heat->episodeId]['disaster'] ?? null);

        // The earthquake item carries NO disaster key, staying byte-identical
        // to today (heat-wave Requirement 1.8).
        $this->assertArrayHasKey($quake->episodeId, $byId);
        $this->assertArrayNotHasKey('disaster', $byId[$quake->episodeId]);
    }

    private function assertRefused(TurnService $service, string $id, string $disaster, string $code): void
    {
        try {
            $service->flipDisaster($id, $disaster);
            $this->fail('expected a 409 ' . $code);
        } catch (ApiError $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame($code, $e->errorCode);
        }
    }

    public function testRunningEarthquakeIsNotConvertedToHeatWave(): void
    {
        $repo = new InMemoryEpisodeRepository();
        $service = $this->service($repo);
        $id = $service->createEpisode('dev_guard_run', seed: 77)->episodeId;
        $service->flipDisaster($id);
        $service->ask($id, 1);

        $worldBefore = $repo->loadTurnWorldJson($id);
        $startBefore = $repo->findEpisode($id)->earthquakeStartTurn;

        $this->assertRefused($service, $id, 'heat_wave', 'disaster_already_set');

        $episode = $repo->findEpisode($id);
        $this->assertSame('earthquake', $episode->disaster);
        $this->assertNull($episode->heatWaveStartTurn);
        $this->assertSame($startBefore, $episode->earthquakeStartTurn);
        $this->assertSame($worldBefore, $repo->loadTurnWorldJson($id));
    }

    public function testAskedButUnflippedEpisodeIsNotConverted(): void
    {
        $repo = new InMemoryEpisodeRepository();
        $service = $this->service($repo);
        $id = $service->createEpisode('dev_guard_asked', seed: 78)->episodeId;
        $service->ask($id, 1);

        $this->assertRefused($service, $id, 'heat_wave', 'disaster_already_set');
        $this->assertSame('earthquake', $repo->findEpisode($id)->disaster);
    }

    public function testEndedEarthquakeStillReturnsEpisodeEnded(): void
    {
        $repo = new InMemoryEpisodeRepository();
        $service = $this->service($repo);
        $id = $service->createEpisode('dev_guard_end', seed: 79)->episodeId;
        $service->flipDisaster($id);
        $repo->markEpisodeEnded($id);

        $this->assertRefused($service, $id, 'heat_wave', 'episode_ended');
    }

    public function testEarthquakeFlipOnHeatWaveEpisodeIsRefused(): void
    {
        $repo = new InMemoryEpisodeRepository();
        $service = $this->service($repo);
        $id = $service->createEpisode('dev_guard_heat', seed: 80, disaster: 'heat_wave')->episodeId;

        $this->assertRefused($service, $id, 'earthquake', 'disaster_already_set');
        $this->assertNull($repo->findEpisode($id)->earthquakeStartTurn);

        // Still refused after the heat is fixed.
        $service->flipDisaster($id, 'heat_wave');
        $this->assertRefused($service, $id, 'earthquake', 'disaster_already_set');
        $this->assertNull($repo->findEpisode($id)->earthquakeStartTurn);
    }

    public function testWaitingTurnZeroConversionStillWorksAndIsIdempotent(): void
    {
        $repo = new InMemoryEpisodeRepository();
        $service = $this->service($repo);
        $id = $service->createEpisode('dev_guard_conv', seed: 81)->episodeId;

        $view = $service->flipDisaster($id, 'heat_wave');
        $this->assertSame('heat_wave', $view->disaster);
        $this->assertSame(0, $repo->findEpisode($id)->heatWaveStartTurn);

        $again = $service->flipDisaster($id, 'heat_wave');
        $this->assertSame('heat_wave', $again->disaster);
        $this->assertSame(0, $repo->findEpisode($id)->heatWaveStartTurn);
    }
}
