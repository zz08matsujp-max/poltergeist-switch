<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\TurnLoop\Clients\FakeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\TurnService;

/**
 * Task 11 follow-up: an episode that keeps stepping must keep its running slot.
 *
 * The lazy idle-slot release (Requirement 9.10) frees a running slot only after
 * no ask or apply activity for Config::$idleSlotReleaseSeconds (300 s). One
 * earthquake run steps for well over that window, so every accepted ask and
 * apply records activity (touchActivity). These tests drive a real
 * {@see TurnService} over the in-memory repository with a shared, advanceable
 * clock (no real sleeping) and prove two things:
 *   - an episode that asks/applies every 2 minutes still counts as running
 *     6 minutes after creation (the slot is NOT freed);
 *   - an episode with no activity for 5+ minutes after creation does NOT count
 *     as running (the release still works when it should).
 *
 * Requirements: 9.10
 */
class TurnServiceActivityTest extends TestCase
{
    /**
     * A shared mutable "now", in Unix seconds, that both the repository and the
     * service read through injected clocks. Advancing it moves time for both.
     */
    private \ArrayObject $clock;

    protected function setUp(): void
    {
        // A fixed, parseable start time so 'Y-m-d H:i:s' round-trips cleanly.
        $this->clock = new \ArrayObject(['now' => (int) strtotime('2026-11-08 12:00:00')]);
    }

    private function advanceSeconds(int $seconds): void
    {
        $this->clock['now'] += $seconds;
    }

    /**
     * Build an in-memory repo and a service that both read the shared clock, so
     * the service's stamped last_activity_at and the repo's running-state check
     * agree on "now".
     *
     * @return array{0:TurnService, 1:InMemoryEpisodeRepository}
     */
    private function serviceWithClock(): array
    {
        $clock = $this->clock;

        $repo = new InMemoryEpisodeRepository(
            new Config(),
            static fn (): int => $clock['now'],
        );

        $service = new TurnService(
            new FakeRobotClient(),
            new FakeRobotClient(),
            new FakeRecorderClient(),
            new ReplayEngineFactory($repo),
            $repo,
            new Config(),
            null,
            static fn (): string => date('Y-m-d H:i:s', $clock['now']),
        );

        return [$service, $repo];
    }

    public function testEpisodeSteppingEveryTwoMinutesStaysRunning(): void
    {
        [$service, $repo] = $this->serviceWithClock();

        $created = $service->createEpisode('dev_active', seed: 2024);
        $service->flipEarthquake($created->episodeId);
        $episodeId = $created->episodeId;

        // Guard: fresh, so it is running right now.
        $this->assertNotNull(
            $repo->findRunningEpisodeForDevice('dev_active'),
            'a just-created episode must occupy a running slot',
        );

        // Step with an ask every 2 minutes for 6 minutes. 2 minutes < the
        // 300 s idle window, so each ask refreshes last_activity_at before the
        // slot could be released.
        for ($minute = 2; $minute <= 6; $minute += 2) {
            $this->advanceSeconds(120);
            // Re-asking turn 1 is idempotent; the point is that an accepted ask
            // records activity (Requirement 9.10).
            $service->ask($episodeId, 1);
        }

        // 6 minutes after creation the episode is still running: the repeated
        // asks kept its slot.
        $this->assertNotNull(
            $repo->findRunningEpisodeForDevice('dev_active'),
            'an episode that keeps asking must keep its running slot',
        );
        $this->assertSame(
            1,
            $repo->countRunningEpisodes(),
            'the actively-stepping episode still counts as running',
        );
    }

    public function testIdleEpisodeIsReleasedAfterFiveMinutes(): void
    {
        [$service, $repo] = $this->serviceWithClock();

        $created = $service->createEpisode('dev_idle', seed: 2024);
        $service->flipEarthquake($created->episodeId);

        // No ask or apply activity. Past the 300 s idle window the slot frees.
        $this->advanceSeconds(301);

        $this->assertNull(
            $repo->findRunningEpisodeForDevice('dev_idle'),
            'an idle episode past the threshold must free its slot',
        );
        $this->assertSame(
            0,
            $repo->countRunningEpisodes(),
            'the idle episode no longer counts as running',
        );
    }
}
