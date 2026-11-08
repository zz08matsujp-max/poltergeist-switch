<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\Engine;
use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\WorldModel;
use PoltergeistSwitch\TurnLoop\Clients\FakeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\EngineFactory;
use PoltergeistSwitch\TurnLoop\Http\ApiError;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\TurnService;
use PoltergeistSwitch\TurnLoop\Values\AppliedTurnRows;
use PoltergeistSwitch\TurnLoop\Values\Reading;
use PoltergeistSwitch\TurnLoop\Values\ReplyRecord;
use PoltergeistSwitch\TurnLoop\Values\TurnStateValue;

/**
 * Unit tests for TurnService::apply (task 12.2). They drive a real
 * {@see TurnService} over the in-memory repository and the real
 * {@see ReplayEngineFactory}, with fake robot/recorder clients that open no
 * network connection (Requirement 14.1). The load-bearing guarantees are:
 *   - a `waiting` turn is rejected with 409 and never reaches the engine;
 *   - a single apply advances the turn once and lands `applied`;
 *   - a second apply returns the stored state without re-advancing;
 *   - a no-reply robot still gets all four layers with action `unknown`;
 *   - a mid-write failure rolls back and leaves the turn `replies_complete`.
 *
 * Requirements: 6.1, 6.6, 6.7, 6.8
 */
class TurnServiceApplyTest extends TestCase
{
    /**
     * Build a service over the given repo with an engine factory that counts
     * its rebuilds, so a test can assert the engine was (or was not) touched.
     *
     * @return array{0:TurnService, 1:CountingEngineFactory, 2:FakeRecorderClient}
     */
    private function service(
        \PoltergeistSwitch\TurnLoop\Repository\EpisodeRepository $repo,
        ?FakeRobotClient $robotA = null,
        ?FakeRobotClient $robotB = null,
        ?FakeRecorderClient $recorder = null,
    ): array {
        $robotA ??= new FakeRobotClient();
        $robotB ??= new FakeRobotClient();
        $recorder ??= new FakeRecorderClient();
        $factory = new CountingEngineFactory(new ReplayEngineFactory($repo));

        $service = new TurnService(
            $robotA,
            $robotB,
            $recorder,
            $factory,
            $repo,
            new Config(),
        );

        return [$service, $factory, $recorder];
    }

    /**
     * Create an episode, flip the earthquake, and run ask so turn 1 reaches
     * `replies_complete`. Returns the episode id.
     */
    private function episodeReadyToApply(TurnService $service, string $device): string
    {
        $created = $service->createEpisode($device, seed: 2024);
        $service->flipEarthquake($created->episodeId);
        $service->ask($created->episodeId, 1);

        return $created->episodeId;
    }

    public function testApplyOnWaitingTurnRejectsWithNoEngineCall(): void
    {
        $repo = new InMemoryEpisodeRepository();

        // robot_a never answers, so after one ask the turn stays `waiting`
        // (robot_a still has an attempt left).
        $failing = new FakeRobotClient(ReplyRecord::unanswered(''));
        $succeeding = new FakeRobotClient();
        [$service, $factory, $recorder] = $this->service($repo, $failing, $succeeding);

        $created = $service->createEpisode('dev_waiting', seed: 2024);
        $service->flipEarthquake($created->episodeId);
        $episodeId = $created->episodeId;
        $service->ask($episodeId, 1);

        // Guard: the turn is `waiting`.
        $turn = $repo->findTurn($episodeId, 1);
        $this->assertNotNull($turn);
        $this->assertSame(TurnStateValue::waiting, $turn->state);

        // The engine factory was touched by ask (createEpisode + ask), so reset
        // the counter to isolate apply's behaviour.
        $factory->resetCounts();

        try {
            $service->apply($episodeId, 1);
            $this->fail('apply on a waiting turn must reject with 409');
        } catch (ApiError $e) {
            $this->assertSame(409, $e->status);
        }

        // apply read no reply and called no engine (Requirement 6.1).
        $this->assertSame(0, $factory->forEpisodeCount, 'apply must not rehydrate the engine for a waiting turn');
        $this->assertSame(0, $factory->worldForEpisodeCount, 'apply must not read the world for a waiting turn');
        $this->assertSame(0, $recorder->callCount(), 'apply must not read replies for a waiting turn');

        // The turn is untouched.
        $turnAfter = $repo->findTurn($episodeId, 1);
        $this->assertNotNull($turnAfter);
        $this->assertSame(TurnStateValue::waiting, $turnAfter->state);
    }

    public function testSingleApplyAdvancesOnceAndLandsApplied(): void
    {
        $repo = new InMemoryEpisodeRepository();
        [$service] = $this->service($repo);
        $episodeId = $this->episodeReadyToApply($service, 'dev_apply');

        $view = $service->apply($episodeId, 1);

        $turn = $repo->findTurn($episodeId, 1);
        $this->assertNotNull($turn);
        $this->assertSame(TurnStateValue::applied, $turn->state, 'a single apply lands `applied`');

        // The episode advanced to the next expected turn (Requirement 7.4),
        // unless applyTurn ended the episode.
        $episode = $repo->findEpisode($episodeId);
        $this->assertNotNull($episode);
        if ($episode->ended) {
            $this->assertTrue(true, 'episode ended on this turn');
        } else {
            $this->assertSame(2, $episode->currentExpectedTurn);
            $this->assertSame(2, $view->currentExpectedTurn);
        }

        // Both robots have all four layers stored for the applied turn.
        $turnView = $this->turnViewFor($view, 1);
        $this->assertArrayHasKey('robot_a', $turnView->robots);
        $this->assertArrayHasKey('robot_b', $turnView->robots);
    }

    public function testSecondApplyReturnsStoredStateWithoutReAdvancing(): void
    {
        $repo = new InMemoryEpisodeRepository();
        [$service, $factory, $recorder] = $this->service($repo);
        $episodeId = $this->episodeReadyToApply($service, 'dev_idem');

        $service->apply($episodeId, 1);

        $episodeAfterFirst = $repo->findEpisode($episodeId);
        $this->assertNotNull($episodeAfterFirst);
        $expectedTurnAfterFirst = $episodeAfterFirst->currentExpectedTurn;
        $endedAfterFirst = $episodeAfterFirst->ended;

        // Isolate the second apply.
        $factory->resetCounts();
        $recorderCallsBefore = $recorder->callCount();

        $service->apply($episodeId, 1);

        // The second apply returns the stored state without reading replies or
        // rehydrating the engine again (Requirement 6.6).
        $this->assertSame(0, $factory->forEpisodeCount, 'a repeated apply must not rehydrate/apply again');
        $this->assertSame(0, $factory->worldForEpisodeCount, 'a repeated apply must not read the world again');
        $this->assertSame($recorderCallsBefore, $recorder->callCount(), 'a repeated apply must not read replies again');

        // Nothing re-advanced.
        $episodeAfterSecond = $repo->findEpisode($episodeId);
        $this->assertNotNull($episodeAfterSecond);
        $this->assertSame($expectedTurnAfterFirst, $episodeAfterSecond->currentExpectedTurn);
        $this->assertSame($endedAfterFirst, $episodeAfterSecond->ended);

        $turn = $repo->findTurn($episodeId, 1);
        $this->assertNotNull($turn);
        $this->assertSame(TurnStateValue::applied, $turn->state);
    }

    public function testNoReplyRobotStillGetsAllFourLayersWithUnknownAction(): void
    {
        $repo = new InMemoryEpisodeRepository();

        // robot_a always fails; robot_b succeeds. Two asks spend robot_a's two
        // attempts and mark it no reply, completing the turn.
        $failing = new FakeRobotClient(ReplyRecord::unanswered(''));
        $succeeding = new FakeRobotClient(new ReplyRecord('', 'I take cover.', 1, 1, 0.0, 0.0, false, false));
        [$service] = $this->service($repo, $failing, $succeeding);

        $created = $service->createEpisode('dev_noreply', seed: 2024);
        $service->flipEarthquake($created->episodeId);
        $episodeId = $created->episodeId;

        $service->ask($episodeId, 1);
        $service->ask($episodeId, 1);

        $turn = $repo->findTurn($episodeId, 1);
        $this->assertNotNull($turn);
        $this->assertSame(TurnStateValue::replies_complete, $turn->state, 'guard: turn ready to apply');
        $this->assertTrue($turn->noReplyA, 'guard: robot_a is marked no reply');

        $view = $service->apply($episodeId, 1);

        $turnView = $this->turnViewFor($view, 1);

        // robot_a (no reply) still has all four layers, with action `unknown`
        // and an empty reply (Requirement 6.8).
        $this->assertArrayHasKey('robot_a', $turnView->robots);
        $a = $turnView->robots['robot_a'];
        $this->assertSame(Operation::unknown->value, $a->reading->action->operation);
        $this->assertTrue($a->reading->unknown);
        $this->assertSame('', $a->reply);

        // robot_b has its four layers too.
        $this->assertArrayHasKey('robot_b', $turnView->robots);
    }

    public function testMidWriteFailureRollsBackAndLeavesTurnRepliesComplete(): void
    {
        $inner = new InMemoryEpisodeRepository();
        $repo = new ThrowingStoreRepository($inner);
        [$service] = $this->service($repo);
        $episodeId = $this->episodeReadyToApply($service, 'dev_rollback');

        // The quiet turn 0's storeAppliedTurn already happened during
        // createEpisode; arm the failure for the turn-1 write only.
        $repo->armFailure(turnNumber: 1);

        try {
            $service->apply($episodeId, 1);
            $this->fail('a mid-write failure must propagate as the apply failure');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(ApiError::class, $e, 'the failure is the repo write failure, not a 409/404');
        }

        // The rollback left no applied turn, and the turn stays
        // `replies_complete` so a retry is possible (Requirement 6.7).
        $turn = $repo->findTurn($episodeId, 1);
        $this->assertNotNull($turn);
        $this->assertSame(TurnStateValue::replies_complete, $turn->state);

        $episode = $repo->findEpisode($episodeId);
        $this->assertNotNull($episode);
        $this->assertFalse($episode->ended);
        $this->assertSame(1, $episode->currentExpectedTurn, 'the episode did not advance past the failed turn');
    }

    // --- helpers ----------------------------------------------------------

    private function turnViewFor(\PoltergeistSwitch\TurnLoop\Values\EpisodeView $view, int $turnNumber): \PoltergeistSwitch\TurnLoop\Values\TurnView
    {
        foreach ($view->turns as $turn) {
            if ($turn->turnNumber === $turnNumber) {
                return $turn;
            }
        }

        $this->fail("no stored turn {$turnNumber} in the view");
    }
}

/**
 * An EngineFactory that delegates to the real {@see ReplayEngineFactory} but
 * counts how many times each rebuild entry point was called, so a test can
 * assert that apply does (or does not) touch the engine.
 */
final class CountingEngineFactory implements EngineFactory
{
    public int $forEpisodeCount = 0;

    public int $worldForEpisodeCount = 0;

    public function __construct(private readonly ReplayEngineFactory $inner) {}

    public function forEpisode(string $episodeId): Engine
    {
        $this->forEpisodeCount++;

        return $this->inner->forEpisode($episodeId);
    }

    public function forEpisodeWithWorld(string $episodeId): array
    {
        // A rebuild entry point like forEpisode, counted the same way so a
        // test can assert how many times apply touched the engine.
        $this->forEpisodeCount++;

        return $this->inner->forEpisodeWithWorld($episodeId);
    }

    public function worldForEpisode(string $episodeId): WorldModel
    {
        $this->worldForEpisodeCount++;

        return $this->inner->worldForEpisode($episodeId);
    }

    public function worldsPerTurnForEpisode(string $episodeId, callable $serialize): array
    {
        // A rebuild entry point like forEpisode, counted the same way so a test
        // can assert the one-time World_State backfill replays at most once.
        $this->forEpisodeCount++;

        return $this->inner->worldsPerTurnForEpisode($episodeId, $serialize);
    }

    public function resetCounts(): void
    {
        $this->forEpisodeCount = 0;
        $this->worldForEpisodeCount = 0;
    }
}

/**
 * A repository decorator that throws on the storeAppliedTurn for a selected
 * turn, standing in for a mid-write failure so apply's rollback path can be
 * exercised (Requirement 6.7). Every other call forwards to the wrapped
 * in-memory repository; because the throw happens before the inner store runs,
 * no applied-turn record is written for that turn, mirroring a rolled-back
 * transaction. InMemoryEpisodeRepository is final, so this is a decorator
 * rather than a subclass.
 */
final class ThrowingStoreRepository implements \PoltergeistSwitch\TurnLoop\Repository\EpisodeRepository
{
    private ?int $failTurn = null;

    public function __construct(private readonly InMemoryEpisodeRepository $inner) {}

    public function armFailure(int $turnNumber): void
    {
        $this->failTurn = $turnNumber;
    }

    public function storeAppliedTurn(AppliedTurnRows $rows): void
    {
        if ($this->failTurn !== null && $rows->turnNumber === $this->failTurn) {
            $this->failTurn = null; // one-shot, so a retry could succeed

            throw new \RuntimeException('storeAppliedTurn failed; rolled back');
        }

        $this->inner->storeAppliedTurn($rows);
    }

    public function createEpisode(\PoltergeistSwitch\TurnLoop\Values\EpisodeRow $episode): string
    {
        return $this->inner->createEpisode($episode);
    }

    public function findEpisode(string $id): ?\PoltergeistSwitch\TurnLoop\Values\EpisodeRow
    {
        return $this->inner->findEpisode($id);
    }

    public function countRunningEpisodes(): int
    {
        return $this->inner->countRunningEpisodes();
    }

    public function findRunningEpisodeForDevice(string $deviceId): ?\PoltergeistSwitch\TurnLoop\Values\EpisodeRow
    {
        return $this->inner->findRunningEpisodeForDevice($deviceId);
    }

    public function setEarthquakeStartTurn(string $id, int $turn): void
    {
        $this->inner->setEarthquakeStartTurn($id, $turn);
    }

    public function markEpisodeEnded(string $id): void
    {
        $this->inner->markEpisodeEnded($id);
    }

    public function setCurrentExpectedTurn(string $id, int $turn): void
    {
        $this->inner->setCurrentExpectedTurn($id, $turn);
    }

    public function touchActivity(string $id, string $now): void
    {
        $this->inner->touchActivity($id, $now);
    }

    public function findTurn(string $episodeId, int $turnNumber): ?\PoltergeistSwitch\TurnLoop\Values\TurnRow
    {
        return $this->inner->findTurn($episodeId, $turnNumber);
    }

    public function createTurn(string $episodeId, int $turnNumber, ?string $worldJson = null): void
    {
        $this->inner->createTurn($episodeId, $turnNumber, $worldJson);
    }

    public function setTurnState(string $episodeId, int $turnNumber, string $state): void
    {
        $this->inner->setTurnState($episodeId, $turnNumber, $state);
    }

    public function savePromptAndReply(\PoltergeistSwitch\TurnLoop\Values\PromptReplyRow $row): void
    {
        $this->inner->savePromptAndReply($row);
    }

    public function findSuccessfulReply(string $episodeId, int $turnNumber, string $robotId): ?\PoltergeistSwitch\TurnLoop\Values\ReplyRecord
    {
        return $this->inner->findSuccessfulReply($episodeId, $turnNumber, $robotId);
    }

    public function incrementAttempt(string $episodeId, int $turnNumber, string $robotId): int
    {
        return $this->inner->incrementAttempt($episodeId, $turnNumber, $robotId);
    }

    public function markNoReply(string $episodeId, int $turnNumber, string $robotId): void
    {
        $this->inner->markNoReply($episodeId, $turnNumber, $robotId);
    }

    public function loadAppliedActionsForReplay(string $episodeId): array
    {
        return $this->inner->loadAppliedActionsForReplay($episodeId);
    }

    public function loadTurnWorldJson(string $episodeId): array
    {
        return $this->inner->loadTurnWorldJson($episodeId);
    }

    public function fillMissingWorldJson(string $episodeId, array $worldByTurn): void
    {
        $this->inner->fillMissingWorldJson($episodeId, $worldByTurn);
    }

    public function loadEpisodeView(string $id): ?\PoltergeistSwitch\TurnLoop\Values\EpisodeView
    {
        return $this->inner->loadEpisodeView($id);
    }
}
