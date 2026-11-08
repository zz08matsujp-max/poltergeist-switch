<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\TurnLoop\Clients\FakeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\TurnService;
use PoltergeistSwitch\TurnLoop\Values\PromptReplyRow;
use PoltergeistSwitch\TurnLoop\Values\ReplyRecord;
use PoltergeistSwitch\TurnLoop\Values\TurnStateValue;

/**
 * Unit tests for TurnService::ask (task 11.2). They drive a real
 * {@see TurnService} over the in-memory repository and the real
 * {@see ReplayEngineFactory}, with fake robot/recorder clients that open no
 * network connection (Requirement 14.1). The load-bearing guarantee here is
 * idempotency: a repeated ask never re-calls an already-answered robot.
 *
 * Requirements: 5.2, 5.3, 5.6, 5.7, 8.3
 */
class TurnServiceAskTest extends TestCase
{
    /**
     * Build the service with two independent fake robots so each one's call
     * count can be asserted separately, and return them alongside the service.
     *
     * @return array{0:TurnService, 1:FakeRobotClient, 2:FakeRobotClient}
     */
    private function service(
        InMemoryEpisodeRepository $repo,
        ?FakeRobotClient $robotA = null,
        ?FakeRobotClient $robotB = null,
    ): array {
        $robotA ??= new FakeRobotClient();
        $robotB ??= new FakeRobotClient();

        $service = new TurnService(
            $robotA,
            $robotB,
            new FakeRecorderClient(),
            new ReplayEngineFactory($repo),
            $repo,
            new Config(),
        );

        return [$service, $robotA, $robotB];
    }

    /**
     * Create an episode for a device, flip the earthquake, and return the id.
     * The quiet turn 0 leaves the current expected turn at 1 (task 10.1).
     */
    private function newEpisode(TurnService $service, string $device = 'dev_ask'): string
    {
        $created = $service->createEpisode($device, seed: 2024);
        $service->flipEarthquake($created->episodeId);

        return $created->episodeId;
    }

    public function testSingleAskCallsBothRobotsOnce(): void
    {
        $repo = new InMemoryEpisodeRepository();
        [$service, $robotA, $robotB] = $this->service($repo);
        $episodeId = $this->newEpisode($service);

        $view = $service->ask($episodeId, 1);

        $this->assertSame(1, $robotA->callCount());
        $this->assertSame(1, $robotB->callCount());

        // Both answered, so the turn is complete (Requirement 5.7).
        $turn = $repo->findTurn($episodeId, 1);
        $this->assertNotNull($turn);
        $this->assertSame(TurnStateValue::replies_complete, $turn->state);
        $this->assertSame(1, $view->currentExpectedTurn);
    }

    public function testSecondAskWithBothSuccessesCallsNeitherRobot(): void
    {
        $repo = new InMemoryEpisodeRepository();
        [$service, $robotA, $robotB] = $this->service($repo);
        $episodeId = $this->newEpisode($service);

        $service->ask($episodeId, 1);
        $service->ask($episodeId, 1);

        // Across both ask calls each robot is invoked at most once: the stored
        // success is reused, never re-called (Requirements 5.2, 5.3, 14.2).
        $this->assertSame(1, $robotA->callCount());
        $this->assertSame(1, $robotB->callCount());
    }

    public function testRobotWithStoredSuccessIsReusedAndOnlyMissingRobotIsCalled(): void
    {
        $repo = new InMemoryEpisodeRepository();

        // robot_a already has a stored successful reply for turn 1; robot_b has
        // none. The first ask must call only robot_b (Requirements 5.2, 5.3).
        $this->seedStoredSuccess($repo, 'dev_reuse', 2024, robotId: 'robot_a');

        $episodeId = $this->onlyEpisodeId($repo);
        [$service, $robotA, $robotB] = $this->serviceForExisting($repo);

        $service->ask($episodeId, 1);

        $this->assertSame(0, $robotA->callCount(), 'robot_a has a stored success and must not be called');
        $this->assertSame(1, $robotB->callCount(), 'robot_b was missing and must be called once');

        $turn = $repo->findTurn($episodeId, 1);
        $this->assertNotNull($turn);
        $this->assertSame(TurnStateValue::replies_complete, $turn->state);
    }

    public function testRobotThatFailsTwiceIsMarkedNoReply(): void
    {
        $repo = new InMemoryEpisodeRepository();

        // robot_a always fails (unanswered); robot_b always succeeds.
        $failing = new FakeRobotClient(ReplyRecord::unanswered(''));
        $succeeding = new FakeRobotClient();
        [$service] = $this->service($repo, $failing, $succeeding);
        $episodeId = $this->newEpisode($service, 'dev_fail');

        // First ask: robot_a fails once (1 attempt), robot_b succeeds. robot_a
        // still has an attempt left, so the turn stays `waiting` (Req 5.8).
        $service->ask($episodeId, 1);
        $turnAfterOne = $repo->findTurn($episodeId, 1);
        $this->assertNotNull($turnAfterOne);
        $this->assertSame(1, $turnAfterOne->attemptsA);
        $this->assertSame(TurnStateValue::waiting, $turnAfterOne->state);
        $this->assertFalse($turnAfterOne->noReplyA);

        // Second ask: robot_a fails again (2 attempts) and is marked no reply;
        // robot_b is reused. Every robot is now settled, so the turn completes
        // (Requirements 5.6, 5.7).
        $service->ask($episodeId, 1);
        $turnAfterTwo = $repo->findTurn($episodeId, 1);
        $this->assertNotNull($turnAfterTwo);
        $this->assertSame(2, $turnAfterTwo->attemptsA);
        $this->assertTrue($turnAfterTwo->noReplyA, 'two failed attempts must mark robot_a no reply');
        $this->assertSame(TurnStateValue::replies_complete, $turnAfterTwo->state);

        // robot_a never produced a stored successful reply.
        $this->assertNull($repo->findSuccessfulReply($episodeId, 1, 'robot_a'));

        // A third ask does not retry robot_a: its attempts are spent (Req 5.5).
        $service->ask($episodeId, 1);
        $turnAfterThree = $repo->findTurn($episodeId, 1);
        $this->assertNotNull($turnAfterThree);
        $this->assertSame(2, $turnAfterThree->attemptsA, 'spent attempts are never retried');
    }

    public function testStoredPromptCarriesTheSituationTextVersion(): void
    {
        $repo = new InMemoryEpisodeRepository();
        [$service] = $this->service($repo);
        $episodeId = $this->newEpisode($service, 'dev_version');

        // The version ask stored must equal the engine's own
        // situationTextVersion for the rehydrated turn (Requirements 8.3, 11.6).
        $engine = (new ReplayEngineFactory($repo))->forEpisode($episodeId);
        $engine->prepareTurn(1);
        $expectedVersion = $engine->situationTextVersion();
        $this->assertNotSame('', $expectedVersion);

        $service->ask($episodeId, 1);

        foreach (['robot_a', 'robot_b'] as $robotId) {
            $attempts = $repo->storedAttempts($episodeId, 1, $robotId);
            $this->assertCount(1, $attempts, "exactly one stored attempt for {$robotId}");
            $this->assertSame($expectedVersion, $attempts[0]->situationTextVersion);
            // The stored prompt is the non-empty situation text sent (Req 8.3).
            $this->assertNotSame('', $attempts[0]->reply->prompt);
        }
    }

    // --- helpers ----------------------------------------------------------

    /**
     * Seed the repo with an episode (quiet turn 0 applied, earthquake flipped)
     * and one stored successful reply for the given robot on turn 1, without
     * calling ask. This lets the reuse test prove only the missing robot is
     * called. A throwaway service plays createEpisode/flipEarthquake.
     */
    private function seedStoredSuccess(
        InMemoryEpisodeRepository $repo,
        string $device,
        int $seed,
        string $robotId,
    ): void {
        [$service] = $this->service($repo);
        $created = $service->createEpisode($device, seed: $seed);
        $service->flipEarthquake($created->episodeId);

        // Create the waiting turn 1 and store a successful reply for the robot.
        $repo->createTurn($created->episodeId, 1);
        $attempt = $repo->incrementAttempt($created->episodeId, 1, $robotId);
        $repo->savePromptAndReply(new PromptReplyRow(
            episodeId: $created->episodeId,
            turnNumber: 1,
            robotId: $robotId,
            attempt: $attempt,
            situationTextVersion: 'seed-version',
            reply: new ReplyRecord('seeded prompt', 'seeded reply', 1, 1, 0.0, 0.0, false, false),
        ));
    }

    private function onlyEpisodeId(InMemoryEpisodeRepository $repo): string
    {
        // The reuse test seeds exactly one episode; find it by scanning for the
        // single running episode across the (single) seeded device.
        $episode = $repo->findRunningEpisodeForDevice('dev_reuse');
        $this->assertNotNull($episode);

        return $episode->id;
    }

    /**
     * A fresh service over an already-seeded repo, with fresh fakes so their
     * call counts start at zero.
     *
     * @return array{0:TurnService, 1:FakeRobotClient, 2:FakeRobotClient}
     */
    private function serviceForExisting(InMemoryEpisodeRepository $repo): array
    {
        return $this->service($repo, new FakeRobotClient(), new FakeRobotClient());
    }
}
