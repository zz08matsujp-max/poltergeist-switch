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

/**
 * The running-slot rule on the fake repository (public-lab Requirement 19):
 * an episode that was never asked frees its slot after 60 s of silence, an
 * asked one after 300 s, an ended one never counts, and an accepted ask on a
 * released episode makes it count again. The clock is injected; nothing sleeps.
 *
 * Requirements: 19.8, 19.9, 19.10, 19.11, 19.12, 19.13, 19.14, 14.30
 */
class RunningSlotTest extends TestCase
{
    private \ArrayObject $clock;

    protected function setUp(): void
    {
        $this->clock = new \ArrayObject(['now' => (int) strtotime('2026-11-08 12:00:00')]);
    }

    private function advance(int $seconds): void
    {
        $this->clock['now'] += $seconds;
    }

    /** @return array{0:TurnService, 1:InMemoryEpisodeRepository} */
    private function build(?Config $config = null): array
    {
        $clock = $this->clock;
        $config ??= new Config();
        $repo = new InMemoryEpisodeRepository($config, static fn (): int => $clock['now']);
        $service = new TurnService(
            new FakeRobotClient(),
            new FakeRobotClient(),
            new FakeRecorderClient(),
            new ReplayEngineFactory($repo),
            $repo,
            $config,
            null,
            static fn (): string => date('Y-m-d H:i:s', $clock['now']),
        );

        return [$service, $repo];
    }

    public function testUnaskedEpisodeCountsAt59SecondsAndNotAt61(): void
    {
        [$service, $repo] = $this->build();
        $service->createEpisode('dev_a', seed: 11);

        $this->advance(59);
        $this->assertSame(1, $repo->countRunningEpisodes());
        $this->assertNotNull($repo->findRunningEpisodeForDevice('dev_a'));

        $this->advance(2); // 61 s
        $this->assertSame(0, $repo->countRunningEpisodes());
        $this->assertNull($repo->findRunningEpisodeForDevice('dev_a'));
    }

    public function testAskedEpisodeCountsAt299SecondsAndNotAt301(): void
    {
        [$service, $repo] = $this->build();
        $created = $service->createEpisode('dev_a', seed: 11);
        $service->flipEarthquake($created->episodeId);
        $service->ask($created->episodeId, 1);

        $this->advance(299);
        $this->assertSame(1, $repo->countRunningEpisodes());
        $this->assertNotNull($repo->findRunningEpisodeForDevice('dev_a'));

        $this->advance(2); // 301 s since the ask
        $this->assertSame(0, $repo->countRunningEpisodes());
        $this->assertNull($repo->findRunningEpisodeForDevice('dev_a'));
    }

    public function testDeviceAlreadyRunningCarriesEpisodeIdUntilReleased(): void
    {
        [$service] = $this->build();
        $created = $service->createEpisode('dev_a', seed: 11);

        $this->advance(59);
        try {
            $service->assertCanStart('dev_a');
            $this->fail('expected device_already_running');
        } catch (ApiError $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame('device_already_running', $e->errorCode);
            $this->assertSame($created->episodeId, $e->episodeId);
        }

        $this->advance(2);
        $service->assertCanStart('dev_a');
        $this->addToAssertionCount(1);
    }

    public function testTooManyRunningFollowsTheSameRule(): void
    {
        [$service] = $this->build(new Config(maxRunningEpisodes: 2));
        $service->createEpisode('dev_a', seed: 1);
        $service->createEpisode('dev_b', seed: 2);

        $this->advance(59);
        try {
            $service->assertCanStart('dev_c');
            $this->fail('expected too_many_running');
        } catch (ApiError $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame('too_many_running', $e->errorCode);
            $this->assertNull($e->episodeId);
        }

        $this->advance(2);
        $service->assertCanStart('dev_c');
        $this->addToAssertionCount(1);
    }

    public function testAfterReleaseDeviceStartsNewEpisodeAndOldOneIsUnchanged(): void
    {
        [$service, $repo] = $this->build();
        $old = $service->createEpisode('dev_a', seed: 11);
        $before = $repo->findEpisode($old->episodeId);

        $this->advance(61);
        $new = $service->createEpisode('dev_a', seed: 12);

        $this->assertNotSame($old->episodeId, $new->episodeId);
        $this->assertEquals($before, $repo->findEpisode($old->episodeId));
        $this->assertFalse($repo->findEpisode($old->episodeId)->ended);
        $this->assertSame($old->episodeId, $service->getEpisode($old->episodeId)->episodeId);
    }

    public function testAcceptedAskOnReleasedEpisodeMakesItRunningAgain(): void
    {
        [$service, $repo] = $this->build();
        $created = $service->createEpisode('dev_a', seed: 11);
        $service->flipEarthquake($created->episodeId);

        $this->advance(120);
        $this->assertNull($repo->findRunningEpisodeForDevice('dev_a'));

        $service->ask($created->episodeId, 1);

        $this->assertSame(1, $repo->countRunningEpisodes());
        $running = $repo->findRunningEpisodeForDevice('dev_a');
        $this->assertNotNull($running);
        $this->assertSame($created->episodeId, $running->id);
    }

    public function testEndedEpisodeNeverCounts(): void
    {
        [$service, $repo] = $this->build();
        $created = $service->createEpisode('dev_a', seed: 11);

        $repo->markEpisodeEnded($created->episodeId);

        $this->assertSame(0, $repo->countRunningEpisodes());
        $this->assertNull($repo->findRunningEpisodeForDevice('dev_a'));
        $service->assertCanStart('dev_a');
        $this->addToAssertionCount(1);
    }
}
