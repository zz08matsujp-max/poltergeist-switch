<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\TurnLoop\Clients\FakeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\Http\ApiError;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\TurnService;
use PoltergeistSwitch\TurnLoop\Values\EpisodeView;

/**
 * Unit tests for TurnService::createEpisode and TurnService::flipEarthquake
 * (task 10.2). They drive a real {@see TurnService} over the in-memory
 * repository and the real {@see ReplayEngineFactory}, with fake clients that
 * open no network connection (Requirement 14.1).
 *
 * Requirements: 4.3, 4.4, 4.5, 4.6, 9.8, 9.9
 */
class TurnServiceCreateTest extends TestCase
{
    private const SHAKE_OPENING = 'Suddenly the floor begins to shake hard.';

    private function service(InMemoryEpisodeRepository $repo, Config $config): TurnService
    {
        return new TurnService(
            new FakeRobotClient(),
            new FakeRobotClient(),
            new FakeRecorderClient(),
            new ReplayEngineFactory($repo),
            $repo,
            $config,
        );
    }

    public function testQuietTurnZeroLeavesCurrentExpectedTurnAtOne(): void
    {
        $repo = new InMemoryEpisodeRepository();
        $service = $this->service($repo, new Config());

        $view = $service->createEpisode('dev_1', seed: 12345);

        $this->assertSame('earthquake', $view->disaster);
        // The quiet turn 0 advances the engine, so the current expected turn is
        // 1 and the start turn is not yet set (task 10.1).
        $this->assertSame(1, $view->currentExpectedTurn);
        $this->assertNull($view->earthquakeStartTurn);
    }

    public function testSameSeedProducesIdenticalInitialSituation(): void
    {
        // Two episodes with the same seed must produce the same initial world,
        // so the text a robot would read for turn 1 is identical (Requirement
        // 4.3). Different devices keep both episodes within the slot rules.
        $repoA = new InMemoryEpisodeRepository();
        $repoB = new InMemoryEpisodeRepository();

        $viewA = $this->service($repoA, new Config())->createEpisode('dev_a', seed: 777);
        $viewB = $this->service($repoB, new Config())->createEpisode('dev_b', seed: 777);

        $textA = $this->turnOneTextFor($repoA, $viewA->episodeId);
        $textB = $this->turnOneTextFor($repoB, $viewB->episodeId);

        $this->assertSame($textA, $textB);
    }

    public function testSecondEpisodeForSameDeviceIsRejected(): void
    {
        $repo = new InMemoryEpisodeRepository();
        $service = $this->service($repo, new Config());

        $service->createEpisode('dev_same', seed: 1);

        $this->expectException(ApiError::class);
        try {
            $service->createEpisode('dev_same', seed: 2);
        } catch (ApiError $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame('device_already_running', $e->errorCode);
            throw $e;
        }
    }

    public function testSixthRunningEpisodeIsRejected(): void
    {
        $repo = new InMemoryEpisodeRepository();
        $service = $this->service($repo, new Config());

        // Five running episodes across five devices fill the cap (Req 9.9).
        for ($i = 1; $i <= 5; $i++) {
            $service->createEpisode('dev_' . $i, seed: $i);
        }

        $this->expectException(ApiError::class);
        try {
            $service->createEpisode('dev_6', seed: 6);
        } catch (ApiError $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame('too_many_running', $e->errorCode);
            throw $e;
        }
    }

    public function testFlipSetsStartTurnSoNextTurnOpensTheShaking(): void
    {
        $repo = new InMemoryEpisodeRepository();
        $service = $this->service($repo, new Config());

        $created = $service->createEpisode('dev_flip', seed: 999);
        $flipped = $service->flipEarthquake($created->episodeId);

        // E − 1 with E = 1 after the quiet turn 0, so the start turn is 0 and
        // never negative (task 10.1).
        $this->assertSame(0, $flipped->earthquakeStartTurn);

        // The first turn played (turn 1, offset 1) is the first shaking turn:
        // building its text the way ask will — prepareTurn then situationTextFor
        // — opens with the shaking sentence (Requirement 4.4).
        $text = $this->turnOneTextFor($repo, $created->episodeId);
        $this->assertStringContainsString(self::SHAKE_OPENING, $text);
    }

    public function testRepeatedFlipLeavesStartTurnUnchanged(): void
    {
        $repo = new InMemoryEpisodeRepository();
        $service = $this->service($repo, new Config());

        $created = $service->createEpisode('dev_twice', seed: 42);
        $first = $service->flipEarthquake($created->episodeId);
        $second = $service->flipEarthquake($created->episodeId);

        $this->assertSame(0, $first->earthquakeStartTurn);
        $this->assertSame($first->earthquakeStartTurn, $second->earthquakeStartTurn);
    }

    public function testFlipOnEndedEpisodeIsRejectedAndChangesNothing(): void
    {
        $repo = new InMemoryEpisodeRepository();
        $service = $this->service($repo, new Config());

        $created = $service->createEpisode('dev_ended', seed: 7);
        $repo->markEpisodeEnded($created->episodeId);

        try {
            $service->flipEarthquake($created->episodeId);
            $this->fail('expected ApiError for a flip on an ended episode');
        } catch (ApiError $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame('episode_ended', $e->errorCode);
        }

        // Nothing changed: no start turn was recorded (Requirement 4.6).
        $episode = $repo->findEpisode($created->episodeId);
        $this->assertNotNull($episode);
        $this->assertNull($episode->earthquakeStartTurn);
    }

    /**
     * Build the text a robot would read for turn 1 (offset 1) the same way ask
     * will: rehydrate the episode's engine by replay, prepareTurn(1), then
     * situationTextFor. This exercises the real factory and engine rather than
     * a stub, so the switch mapping is proven end to end.
     */
    private function turnOneTextFor(InMemoryEpisodeRepository $repo, string $episodeId): string
    {
        $engine = (new ReplayEngineFactory($repo))->forEpisode($episodeId);
        $engine->prepareTurn(1);

        return $engine->situationTextFor('robot_a');
    }
}
