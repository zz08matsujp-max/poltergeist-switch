<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\TurnLoop\Clients\FakeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\Clients\RecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\RecorderInput;
use PoltergeistSwitch\TurnLoop\Clients\RobotClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\Http\ApiError;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\TurnService;
use PoltergeistSwitch\TurnLoop\Values\Reading;
use PoltergeistSwitch\TurnLoop\Values\ReplyRecord;
use PoltergeistSwitch\TurnLoop\Values\TurnStateValue;

/**
 * Turn_Lock and reserve-first tests over the in-memory repository and fake
 * clients (public-lab Requirement 18). No network connection is opened.
 *
 * Requirements: 18.1, 18.2, 18.3, 18.4, 18.5, 18.7, 18.8, 18.9, 14.25, 14.26
 */
class TurnLockTest extends TestCase
{
    private const SENTINEL_NOW = '2031-01-01 00:00:00';

    /** The clock the service stamps activity with; switched to a sentinel after setup. */
    private string $now = '2026-11-01 10:00:00';

    /**
     * @return array{0:TurnService, 1:InMemoryEpisodeRepository}
     */
    private function build(
        RobotClient $robotA,
        RobotClient $robotB,
        RecorderClient $recorder,
        ?InMemoryEpisodeRepository $repo = null,
    ): array {
        $repo ??= new InMemoryEpisodeRepository();
        $service = new TurnService(
            $robotA,
            $robotB,
            $recorder,
            new ReplayEngineFactory($repo),
            $repo,
            new Config(),
            null,
            fn (): string => $this->now,
        );

        return [$service, $repo];
    }

    private function newEpisode(TurnService $service, string $device = 'dev_lock'): string
    {
        $created = $service->createEpisode($device, seed: 2024);
        $service->flipEarthquake($created->episodeId);

        return $created->episodeId;
    }

    private function failingRobot(): FakeRobotClient
    {
        return new FakeRobotClient(ReplyRecord::unanswered(''));
    }

    public function testBusyAskLockGivesTurnBusyWithNoCallNoAttemptAndNoActivityTouch(): void
    {
        $robotA = $this->failingRobot();
        $robotB = $this->failingRobot();
        [$service, $repo] = $this->build($robotA, $robotB, new FakeRecorderClient());
        $episodeId = $this->newEpisode($service);

        // One real ask first so both attempt counters sit at 1.
        $service->ask($episodeId, 1);
        $turn = $repo->findTurn($episodeId, 1);
        $this->assertNotNull($turn);
        $this->assertSame(1, $turn->attemptsA);
        $this->assertSame(1, $turn->attemptsB);

        $this->now = self::SENTINEL_NOW;
        $before = $repo->findEpisode($episodeId);
        $this->assertNotNull($before);
        $repo->holdTurnLock('ask', $episodeId, 1);

        try {
            $service->ask($episodeId, 1);
            $this->fail('a busy ask lock must answer turn_busy');
        } catch (ApiError $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame('turn_busy', $e->errorCode);
            $this->assertSame($before->currentExpectedTurn, $e->currentExpectedTurn);
            $this->assertSame(TurnStateValue::waiting->value, $e->turnState);
        }

        $this->assertSame(1, $robotA->callCount());
        $this->assertSame(1, $robotB->callCount());
        $after = $repo->findTurn($episodeId, 1);
        $this->assertNotNull($after);
        $this->assertSame(1, $after->attemptsA);
        $this->assertSame(1, $after->attemptsB);
        $episode = $repo->findEpisode($episodeId);
        $this->assertNotNull($episode);
        $this->assertNotSame(self::SENTINEL_NOW, $episode->lastActivityAt, 'touchActivity must not run');
        $this->assertSame($before->lastActivityAt, $episode->lastActivityAt);
    }

    public function testBusyApplyLockGivesTurnBusyWithNoRecorderCallAndNothingStored(): void
    {
        $recorder = new FakeRecorderClient();
        [$service, $repo] = $this->build(new FakeRobotClient(), new FakeRobotClient(), $recorder);
        $episodeId = $this->newEpisode($service);
        $service->ask($episodeId, 1);

        $repo->holdTurnLock('apply', $episodeId, 1);

        try {
            $service->apply($episodeId, 1);
            $this->fail('a busy apply lock must answer turn_busy');
        } catch (ApiError $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame('turn_busy', $e->errorCode);
            $this->assertSame(1, $e->currentExpectedTurn);
            $this->assertSame(TurnStateValue::replies_complete->value, $e->turnState);
        }

        $this->assertSame(0, $recorder->callCount());
        $turn = $repo->findTurn($episodeId, 1);
        $this->assertNotNull($turn);
        $this->assertSame(TurnStateValue::replies_complete, $turn->state);
        $episode = $repo->findEpisode($episodeId);
        $this->assertNotNull($episode);
        $this->assertSame(1, $episode->currentExpectedTurn);
    }

    public function testAskLockAndApplyLockAreIndependent(): void
    {
        $recorder = new FakeRecorderClient();
        [$service, $repo] = $this->build(new FakeRobotClient(), new FakeRobotClient(), $recorder);
        $episodeId = $this->newEpisode($service);
        $service->ask($episodeId, 1);

        // A held ask lock does not block apply.
        $repo->holdTurnLock('ask', $episodeId, 1);
        $service->apply($episodeId, 1);
        $turn = $repo->findTurn($episodeId, 1);
        $this->assertNotNull($turn);
        $this->assertSame(TurnStateValue::applied, $turn->state);

        // A held apply lock does not block ask (turn 2 is the next expected turn).
        $robotA = new FakeRobotClient();
        [$service2, $repo2] = $this->build($robotA, new FakeRobotClient(), new FakeRecorderClient());
        $episode2 = $this->newEpisode($service2, 'dev_lock_2');
        $repo2->holdTurnLock('apply', $episode2, 1);
        $service2->ask($episode2, 1);
        $this->assertSame(1, $robotA->callCount());
    }

    public function testLockIsReleasedAfterANormalAsk(): void
    {
        [$service, $repo] = $this->build(new FakeRobotClient(), new FakeRobotClient(), new FakeRecorderClient());
        $episodeId = $this->newEpisode($service);

        $service->ask($episodeId, 1);

        $this->assertSame(1, $repo->turnLockAcquireCount());
        $this->assertSame($repo->turnLockAcquireCount(), $repo->turnLockReleaseCount());
    }

    public function testLockIsReleasedWhenAnAskClientThrows(): void
    {
        $throwing = new class () implements RobotClient {
            public function call(string $prompt): ReplyRecord
            {
                throw new \RuntimeException('provider blew up');
            }

            public function prepare(string $prompt): \CurlHandle
            {
                return curl_init();
            }

            public function finish(string $prompt, array $result): ReplyRecord
            {
                throw new \RuntimeException('provider blew up');
            }
        };
        [$service, $repo] = $this->build($throwing, new FakeRobotClient(), new FakeRecorderClient());
        $episodeId = $this->newEpisode($service);

        try {
            $service->ask($episodeId, 1);
            $this->fail('the client failure must propagate');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(ApiError::class, $e);
        }

        $this->assertSame(1, $repo->turnLockAcquireCount());
        $this->assertSame($repo->turnLockAcquireCount(), $repo->turnLockReleaseCount());

        // The lock is free again, so a following ask obtains it.
        $this->assertSame(1, $this->tryAcquire($repo, 'ask', $episodeId, 1));
    }

    public function testLockIsReleasedAfterAnApply(): void
    {
        [$service, $repo] = $this->build(new FakeRobotClient(), new FakeRobotClient(), new FakeRecorderClient());
        $episodeId = $this->newEpisode($service);
        $service->ask($episodeId, 1);
        $acquiredBefore = $repo->turnLockAcquireCount();

        $service->apply($episodeId, 1);

        $this->assertSame($acquiredBefore + 1, $repo->turnLockAcquireCount());
        $this->assertSame($repo->turnLockAcquireCount(), $repo->turnLockReleaseCount());
    }

    public function testLockIsReleasedWhenAnApplyThrows(): void
    {
        $throwing = new class () implements RecorderClient {
            public function read(RecorderInput $input, string $reply): Reading
            {
                throw new \RuntimeException('recorder blew up');
            }

            public function prepare(RecorderInput $input, string $reply): \CurlHandle
            {
                throw new \RuntimeException('recorder blew up');
            }

            public function finish(RecorderInput $input, string $reply, array $result): Reading
            {
                throw new \RuntimeException('recorder blew up');
            }

            public function promptVersion(): string
            {
                return 'recorder-throwing';
            }
        };
        [$service, $repo] = $this->build(new FakeRobotClient(), new FakeRobotClient(), $throwing);
        $episodeId = $this->newEpisode($service);
        $service->ask($episodeId, 1);

        try {
            $service->apply($episodeId, 1);
            $this->fail('the recorder failure must propagate');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(ApiError::class, $e);
        }

        $this->assertSame($repo->turnLockAcquireCount(), $repo->turnLockReleaseCount());
        $turn = $repo->findTurn($episodeId, 1);
        $this->assertNotNull($turn);
        $this->assertSame(TurnStateValue::replies_complete, $turn->state);
    }

    public function testRepeatedApplyOfAnAppliedTurnReturnsStoredViewWithoutARecorderCall(): void
    {
        $recorder = new FakeRecorderClient();
        [$service, $repo] = $this->build(new FakeRobotClient(), new FakeRobotClient(), $recorder);
        $episodeId = $this->newEpisode($service);
        $service->ask($episodeId, 1);
        $first = $service->apply($episodeId, 1);
        $callsAfterFirst = $recorder->callCount();

        $second = $service->apply($episodeId, 1);

        $this->assertSame($callsAfterFirst, $recorder->callCount());
        $this->assertSame($first->currentExpectedTurn, $second->currentExpectedTurn);
        $this->assertCount(count($first->turns), $second->turns);
        $this->assertSame($repo->turnLockAcquireCount(), $repo->turnLockReleaseCount());
    }

    public function testAttemptIsAlreadyReservedWhenTheProviderIsCalled(): void
    {
        $repo = new InMemoryEpisodeRepository();
        $seen = [];
        $observe = static function (string $label, string $episodeId) use ($repo, &$seen): void {
            $turn = $repo->findTurn($episodeId, 1);
            $seen[$label][] = $turn === null ? -1 : ($label === 'a' ? $turn->attemptsA : $turn->attemptsB);
        };

        $makeClient = static function (string $label) use ($observe, &$episodeIdRef): RobotClient {
            return new class ($label, $observe, $episodeIdRef) implements RobotClient {
                public function __construct(
                    private string $label,
                    private \Closure $observe,
                    private ?string &$episodeId,
                ) {
                }

                public function call(string $prompt): ReplyRecord
                {
                    return new ReplyRecord($prompt, 'ok', 1, 1, 0.0, 0.0, false, false);
                }

                public function prepare(string $prompt): \CurlHandle
                {
                    // The provider call is staged after the reservation.
                    ($this->observe)($this->label, (string) $this->episodeId);

                    return curl_init();
                }

                public function finish(string $prompt, array $result): ReplyRecord
                {
                    ($this->observe)($this->label, (string) $this->episodeId);

                    return $this->call($prompt);
                }
            };
        };
        $episodeIdRef = null;

        [$service] = $this->build($makeClient('a'), $makeClient('b'), new FakeRecorderClient(), $repo);
        $episodeIdRef = $this->newEpisode($service);

        $service->ask($episodeIdRef, 1);

        $this->assertSame([1, 1], $seen['a'], 'robot_a attempt count at call time');
        $this->assertSame([1, 1], $seen['b'], 'robot_b attempt count at call time');
    }

    /** Try to take a lock on the repository; releases it again; returns 1 when obtained, 0 when busy. */
    private function tryAcquire(InMemoryEpisodeRepository $repo, string $kind, string $episodeId, int $turn): int
    {
        if (!$repo->acquireTurnLock($kind, $episodeId, $turn)) {
            return 0;
        }
        $repo->releaseTurnLock($kind, $episodeId, $turn);

        return 1;
    }
}
