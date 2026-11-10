<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Memory;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\ReflectionTextBuilder;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Memory\ReflectService;
use PoltergeistSwitch\Memory\Values\MemorySnapshot;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;
use PoltergeistSwitch\TurnLoop\Clients\ClaudeClient;
use PoltergeistSwitch\TurnLoop\Clients\FakeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\Clients\GptClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\Http\ApiError;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\TurnService;
use PoltergeistSwitch\TurnLoop\Values\ReplyRecord;
use PoltergeistSwitch\TurnLoop\Values\TurnStateValue;

/**
 * Example tests for ReflectService (task 8.3).
 *
 * Property 5 (ReflectIdempotencyPropertyTest, task 8.2) covers the universal
 * "at most once per robot per episode" claim. These examples nail the specific
 * guard and branch behaviours that are easier to read as concrete scenarios:
 *
 *   - the ended-only guard: a not-ended episode (including one released after
 *     5 idle minutes, which is never marked ended) is rejected 409 `not_ended`
 *     and no {@see FakeRobotClient} call is made (Requirements 2.2, 2.11);
 *   - the pre-feature guard: an episode whose snapshot columns are NULL (no
 *     storeEpisodeMemory at creation) is rejected 409 `not_reflectable` and no
 *     client call is made (Requirement 2.2);
 *   - the retry path: after robot_a's earlier reflection failed, a second
 *     reflect asks only robot_a again and leaves the already-answered robot_b
 *     untouched (Requirement 2.10);
 *   - the stale-claim re-claim path: an `in_progress` slot older than 120 s is
 *     re-claimable and the robot is asked again, while a fresh one is skipped
 *     without a call (Requirements 2.9, 2.10);
 *   - the empty-answer vs failed-call reasons: a blank/whitespace reply stores
 *     no memory with reason `empty_answer`, while a failed call reports
 *     `no_reply` and a timed-out call reports `timeout` (Requirement 2.7);
 *   - the per-call settings: the reflect clients send one user message, no
 *     system prompt, and max 4000 output tokens, under the turn-loop per-call
 *     timeout (Requirement 2.4), asserted on the real clients' request shape
 *     exactly as the turn-loop RobotClient tests assert it.
 *
 * Everything runs against the in-memory {@see InMemoryEpisodeRepository} fake
 * and {@see FakeRobotClient}s that open no network connection, with an injected
 * clock so the 120 s staleness window is exercised deterministically. No real
 * provider API is ever called.
 *
 * Requirements: 2.2, 2.4, 2.7, 2.10, 2.11
 */
final class ReflectServiceTest extends TestCase
{
    /** A placeholder API key for the real clients' request-shape check; never a real secret. */
    private const PLACEHOLDER_KEY = 'test-placeholder-key';

    /**
     * A mutable "now" (Unix seconds) shared by the repository clock and the
     * service's created-at clock, so a test can advance time and prove the
     * 120 s stale-claim recovery deterministically.
     */
    private int $now = 1_700_000_000;

    // ---------- Guards: no call, no state change ----------

    public function testNotEndedEpisodeIsRejectedWithoutAnyRobotCall(): void
    {
        $repo = new InMemoryEpisodeRepository(new Config(), fn (): int => $this->now);
        $robotA = new FakeRobotClient();
        $robotB = new FakeRobotClient();

        // A created but not-yet-ended episode, with a stored snapshot so the
        // rejection is the not_ended guard (not the not_reflectable one).
        $episodeId = $this->createEpisode($repo, $robotA, $robotB);
        $repo->storeEpisodeMemory($episodeId, 1, new MemorySnapshot([]), new MemorySnapshot([]));

        $service = $this->reflectService($repo, $robotA, $robotB);

        try {
            $service->reflect($episodeId);
            $this->fail('a not-ended episode must be rejected');
        } catch (ApiError $error) {
            $this->assertSame(409, $error->status);
            $this->assertSame('not_ended', $error->errorCode);
        }

        $this->assertSame(0, $robotA->callCount(), 'no robot_a call on the not_ended guard');
        $this->assertSame(0, $robotB->callCount(), 'no robot_b call on the not_ended guard');
    }

    public function testIdleReleasedEpisodeIsRejectedAsNotEndedWithoutAnyRobotCall(): void
    {
        $repo = new InMemoryEpisodeRepository(new Config(), fn (): int => $this->now);
        $robotA = new FakeRobotClient();
        $robotB = new FakeRobotClient();

        $episodeId = $this->createEpisode($repo, $robotA, $robotB);
        $repo->storeEpisodeMemory($episodeId, 1, new MemorySnapshot([]), new MemorySnapshot([]));

        // Advance well past the idle-release window: the episode's slot is
        // released, but it is NOT marked ended, so reflect still rejects it
        // with not_ended (Requirement 2.11) and makes no call.
        $this->now += (new Config())->idleSlotReleaseSeconds + 60;
        $this->assertNull(
            $repo->findRunningEpisodeForDevice('dev_reflect'),
            'the idle episode has released its running slot'
        );

        $service = $this->reflectService($repo, $robotA, $robotB);

        try {
            $service->reflect($episodeId);
            $this->fail('an idle-released (not ended) episode must be rejected');
        } catch (ApiError $error) {
            $this->assertSame(409, $error->status);
            $this->assertSame('not_ended', $error->errorCode);
        }

        $this->assertSame(0, $robotA->callCount());
        $this->assertSame(0, $robotB->callCount());
    }

    public function testPreFeatureEpisodeWithNullSnapshotIsRejectedWithoutAnyRobotCall(): void
    {
        $repo = new InMemoryEpisodeRepository(new Config(), fn (): int => $this->now);
        $robotA = new FakeRobotClient();
        $robotB = new FakeRobotClient();

        // An ended episode with NO stored snapshot — a pre-feature episode whose
        // memory columns are NULL. storeEpisodeMemory is never called.
        $episodeId = $this->createEpisode($repo, $robotA, $robotB);
        $this->runToEnd($repo, $episodeId);
        $this->assertFalse(
            $repo->episodeHasSnapshot($episodeId),
            'the pre-feature episode has no stored snapshot'
        );

        $service = $this->reflectService($repo, $robotA, $robotB);

        try {
            $service->reflect($episodeId);
            $this->fail('a pre-feature episode must be rejected as not_reflectable');
        } catch (ApiError $error) {
            $this->assertSame(409, $error->status);
            $this->assertSame('not_reflectable', $error->errorCode);
        }

        $this->assertSame(0, $robotA->callCount(), 'no robot_a call on the not_reflectable guard');
        $this->assertSame(0, $robotB->callCount(), 'no robot_b call on the not_reflectable guard');
    }

    // ---------- Retry: a failed robot is asked again, an answered one is not ----------

    public function testRetryAfterFailedRobotAsksOnlyThatRobotAgain(): void
    {
        $repo = new InMemoryEpisodeRepository(new Config(), fn (): int => $this->now);

        // robot_a fails its reflection (unanswered); robot_b answers.
        $robotA = new FakeRobotClient(ReplyRecord::unanswered('', 1.0, 'transport_error'));
        $robotB = new FakeRobotClient(new ReplyRecord('', 'Jade remembers the dishes', 10, 8, 0.2, 0.0, false, false));

        $episodeId = $this->endedEpisodeWithSnapshot($repo, $robotA, $robotB);
        $service = $this->reflectService($repo, $robotA, $robotB);

        // First reflect: robot_a fails (no memory, reason no_reply), robot_b
        // answers (one memory stored).
        $first = $service->reflect($episodeId);
        $this->assertFalse($first->robots['robot_a']->answered, 'robot_a failed the first reflect');
        $this->assertSame('no_reply', $first->robots['robot_a']->reason);
        $this->assertTrue($first->robots['robot_b']->answered, 'robot_b answered the first reflect');
        $this->assertSame('Jade remembers the dishes', $first->robots['robot_b']->memory);

        $this->assertSame(1, $robotA->callCount(), 'robot_a was asked once on the first reflect');
        $this->assertSame(1, $robotB->callCount(), 'robot_b was asked once on the first reflect');

        // Second reflect: only robot_a (failed → re-claimable) is asked again;
        // robot_b (answered) is reused with no new call.
        $second = $service->reflect($episodeId);

        $this->assertSame(2, $robotA->callCount(), 'robot_a is asked again after its failure');
        $this->assertSame(1, $robotB->callCount(), 'the answered robot_b is never re-asked');

        $this->assertTrue(
            $second->robots['robot_b']->answered,
            'robot_b reuses its stored answer'
        );
        $this->assertSame('Jade remembers the dishes', $second->robots['robot_b']->memory);
    }

    // ---------- Stale claim: re-claimable after 120 s; a fresh one is skipped ----------

    public function testStaleInProgressClaimIsReclaimedWhileAFreshOneIsSkipped(): void
    {
        $repo = new InMemoryEpisodeRepository(new Config(), fn (): int => $this->now);
        $robotA = new FakeRobotClient(new ReplyRecord('', 'Amber remembers the kettle', 10, 8, 0.2, 0.0, false, false));
        $robotB = new FakeRobotClient(new ReplyRecord('', 'Jade remembers the dishes', 10, 8, 0.2, 0.0, false, false));

        $episodeId = $this->endedEpisodeWithSnapshot($repo, $robotA, $robotB);

        // Simulate a concurrent caller mid-call: robot_a's slot is claimed now,
        // then (90 s later) robot_b's slot is claimed. Neither has committed.
        $repo->claimReflectionSlot($episodeId, 'robot_a');
        $this->now += 90;
        $repo->claimReflectionSlot($episodeId, 'robot_b');

        $service = $this->reflectService($repo, $robotA, $robotB);

        // --- A reflect that arrives while BOTH claims are still fresh (< 120 s)
        // skips both: no call is made and neither robot has an answer yet.
        $fresh = $service->reflect($episodeId);
        $this->assertSame(0, $robotA->callCount(), 'a fresh in_progress claim is not double-asked');
        $this->assertSame(0, $robotB->callCount(), 'a fresh in_progress claim is not double-asked');
        $this->assertFalse($fresh->robots['robot_a']->answered);
        $this->assertNull($fresh->robots['robot_a']->reason, 'a skipped robot has no failure reason');
        $this->assertFalse($fresh->robots['robot_b']->answered);

        // --- Advance 40 s more: robot_a's claim is now 130 s old (stale, >=
        // 120 s) while robot_b's is 40 s old (fresh). A reflect now re-claims
        // and asks the stale robot_a, but still skips the fresh robot_b.
        $this->now += 40;

        $staleRun = $service->reflect($episodeId);

        // robot_a's stale slot is re-claimed and asked; robot_b's fresh slot is
        // still skipped.
        $this->assertSame(1, $robotA->callCount(), 'the stale robot_a claim is re-claimed and asked');
        $this->assertSame(0, $robotB->callCount(), 'the fresh robot_b claim is still skipped');
        $this->assertTrue($staleRun->robots['robot_a']->answered, 'robot_a now has a stored answer');
        $this->assertSame('Amber remembers the kettle', $staleRun->robots['robot_a']->memory);
        $this->assertFalse($staleRun->robots['robot_b']->answered, 'robot_b is still being asked by the other caller');
    }

    // ---------- Reasons: empty answer vs failed call vs timeout ----------

    public function testStoredReflectionVersionMatchesTheDisaster(): void
    {
        $expected = [
            'earthquake' => ReflectionTextBuilder::REFLECTION_TEXT_VERSION,
            'heat_wave' => ReflectionTextBuilder::REFLECTION_TEXT_VERSION_HEAT,
        ];

        foreach ($expected as $disaster => $version) {
            $repo = new InMemoryEpisodeRepository(new Config(), fn (): int => $this->now);
            $robotA = new FakeRobotClient();
            $robotB = new FakeRobotClient();

            $episodeId = $this->endedEpisodeWithSnapshot($repo, $robotA, $robotB, $disaster);
            $this->reflectService($repo, $robotA, $robotB)->reflect($episodeId);

            foreach (['robot_a', 'robot_b'] as $robotId) {
                $attempts = $repo->storedAttempts($episodeId, -1, $robotId);
                $this->assertNotEmpty($attempts, "{$disaster}: a reflection prompt is stored for {$robotId}");
                $this->assertSame(
                    $version,
                    $attempts[0]->situationTextVersion,
                    "{$disaster}: the stored reflection version matches the text built for {$robotId}"
                );
            }
        }
    }

    public function testEmptyAnswerStoresNoMemoryWithEmptyAnswerReason(): void
    {
        $repo = new InMemoryEpisodeRepository(new Config(), fn (): int => $this->now);

        // robot_a returns a whitespace-only reply (a successful call, but the
        // robot wants to keep nothing); robot_b returns a real answer.
        $robotA = new FakeRobotClient(new ReplyRecord('', "   \n\t ", 5, 1, 0.1, 0.0, false, false));
        $robotB = new FakeRobotClient(new ReplyRecord('', 'Jade remembers the dishes', 10, 8, 0.2, 0.0, false, false));

        $episodeId = $this->endedEpisodeWithSnapshot($repo, $robotA, $robotB);
        $service = $this->reflectService($repo, $robotA, $robotB);

        $result = $service->reflect($episodeId);

        $this->assertFalse($result->robots['robot_a']->answered, 'an empty answer stores no memory');
        $this->assertNull($result->robots['robot_a']->memory);
        $this->assertSame('empty_answer', $result->robots['robot_a']->reason);
        $this->assertSame(0, $result->robots['robot_a']->activeCount, 'robot_a gained no active memory');

        $this->assertTrue($result->robots['robot_b']->answered);
        $this->assertSame(1, $result->robots['robot_b']->activeCount);
    }

    public function testStoredReflectionBelongsToTheEpisodesDeviceOnly(): void
    {
        $repo = new InMemoryEpisodeRepository(new Config(), fn (): int => $this->now);
        $robotA = new FakeRobotClient(new ReplyRecord('', 'Amber remembers the kettle', 10, 8, 0.2, 0.0, false, false));
        $robotB = new FakeRobotClient(new ReplyRecord('', 'Jade remembers the dishes', 10, 8, 0.2, 0.0, false, false));
        $episodeId = $this->endedEpisodeWithSnapshot($repo, $robotA, $robotB);

        $this->reflectService($repo, $robotA, $robotB)->reflect($episodeId);

        // The episode was created by 'dev_reflect' (see createEpisode); the
        // memory is tagged with it (public-lab Req 2.1, 2.12).
        $this->assertSame(
            ['Amber remembers the kettle'],
            $repo->activeMemories('dev_reflect', 'robot_a', 1, 18)->bodies
        );
        $this->assertSame(1, $repo->activeCount('dev_reflect', 'robot_b', 1, 'earthquake'));
        $this->assertSame([], $repo->activeMemories('dev_other', 'robot_a', 1, 18)->bodies);
        $this->assertSame(0, $repo->activeCount('dev_other', 'robot_b', 1, 'earthquake'));
    }

    public function testFailedCallReportsNoReplyAndTimedOutCallReportsTimeout(): void
    {
        $repo = new InMemoryEpisodeRepository(new Config(), fn (): int => $this->now);

        // robot_a: a generic failed call (failureReason != 'timeout') → no_reply.
        // robot_b: a timed-out call (failureReason == 'timeout') → timeout.
        $robotA = new FakeRobotClient(ReplyRecord::unanswered('', 1.0, 'transport_error'));
        $robotB = new FakeRobotClient(ReplyRecord::unanswered('', 75.0, 'timeout'));

        $episodeId = $this->endedEpisodeWithSnapshot($repo, $robotA, $robotB);
        $service = $this->reflectService($repo, $robotA, $robotB);

        $result = $service->reflect($episodeId);

        $this->assertFalse($result->robots['robot_a']->answered);
        $this->assertSame('no_reply', $result->robots['robot_a']->reason, 'a non-timeout failure maps to no_reply');

        $this->assertFalse($result->robots['robot_b']->answered);
        $this->assertSame('timeout', $result->robots['robot_b']->reason, 'a timed-out call maps to timeout');
    }

    // ---------- Per-call settings: one user message, no system, max 4000, timeout ----------

    public function testReflectClientsSendOneUserMessageNoSystemAndMax4000UnderTheTurnLoopTimeout(): void
    {
        // The reflect path reuses the turn-loop RobotClients unchanged, so the
        // per-call settings (one user message, no system prompt, provider
        // default parameters, max 4000 output tokens — Requirement 2.4) are the
        // clients' own request shape, asserted exactly as the turn-loop
        // RobotClient tests assert them. The timeout is Config's per-call
        // timeout, which the clients apply via CURLOPT_TIMEOUT; it is kept
        // strictly below the per-request budget so a turn/reflect call can time
        // out and still leave the request time to respond.
        $config = new Config();

        $claude = new ClaudeClient(self::PLACEHOLDER_KEY, $config);
        $gpt = new GptClient(self::PLACEHOLDER_KEY, $config);

        // Build a real Reflection_Text so the assertion is about the exact text
        // the reflect path would send, not a placeholder string.
        $reflectionText = 'the reflection text that ends with the question';

        $claudeBody = $claude->requestBody($reflectionText);
        $this->assertSame(4000, $claudeBody['max_tokens'], 'Claude reflect call sends max 4000 output tokens');
        $this->assertArrayNotHasKey('system', $claudeBody, 'Claude reflect call sends no system prompt');
        $this->assertCount(1, $claudeBody['messages'], 'Claude reflect call sends exactly one message');
        $this->assertSame('user', $claudeBody['messages'][0]['role'], 'the one Claude message is a user message');
        $this->assertSame($reflectionText, $claudeBody['messages'][0]['content'], 'the Claude message is the reflection text as-is');

        $gptBody = $gpt->requestBody($reflectionText);
        $this->assertSame(4000, $gptBody['max_output_tokens'], 'GPT reflect call sends max 4000 output tokens');
        $this->assertArrayNotHasKey('system', $gptBody, 'GPT reflect call sends no system prompt');
        $this->assertArrayNotHasKey('instructions', $gptBody, 'GPT reflect call sends no instructions');
        $this->assertCount(1, $gptBody['input'], 'GPT reflect call sends exactly one input');
        $this->assertSame('user', $gptBody['input'][0]['role'], 'the one GPT input is a user message');
        $this->assertSame($reflectionText, $gptBody['input'][0]['content'][0]['text'], 'the GPT input is the reflection text as-is');

        // The per-call timeout the reflect calls run under is the turn-loop
        // Config value, strictly below the per-request budget so a call can time
        // out within one short HTTP request (robot-input / turn-loop timing).
        $this->assertSame(75, $config->perCallTimeoutSeconds, 'reflect calls use the turn-loop per-call timeout');
        $this->assertLessThan(
            $config->perRequestBudgetSeconds,
            $config->perCallTimeoutSeconds,
            'the per-call timeout stays strictly below the per-request budget'
        );
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /** Wire a ReflectService over the in-memory repo with the given robot fakes. */
    private function reflectService(
        InMemoryEpisodeRepository $repo,
        FakeRobotClient $robotA,
        FakeRobotClient $robotB,
    ): ReflectService {
        return new ReflectService(
            $robotA,
            $robotB,
            new ReflectionTextBuilder(new SituationTextGenerator()),
            new ReplayEngineFactory($repo),
            $repo,
            new Config(),
            null,
            fn (): string => date('Y-m-d H:i:s', $this->now),
        );
    }

    /**
     * Create an episode (quiet turn 0 applied) through TurnService, mirroring
     * the browser create flow. The episode is not yet ended and carries no
     * memory snapshot. The turn loop is driven by SEPARATE run-robots, so the
     * reflect-under-test fakes keep a clean call count of zero.
     */
    private function createEpisode(
        InMemoryEpisodeRepository $repo,
        FakeRobotClient $reflectA,
        FakeRobotClient $reflectB,
        string $disaster = 'earthquake',
    ): string {
        // The reflect fakes return replies configured for the reflection call,
        // which may be unanswered or empty — useless for driving the turn loop.
        // Drive the run with plain run-robots instead; the ended episode then
        // persists in the shared repo for the reflect-under-test fakes.
        $service = $this->turnService($repo);
        $created = $service->createEpisode('dev_reflect', seed: 4242, disaster: $disaster);

        return $created->episodeId;
    }

    /**
     * Create an ended episode WITH a stored (empty) snapshot, so reflect passes
     * both guards and proceeds to ask the robots. The earthquake is flipped at
     * creation and the run is driven to its end exactly as the browser would.
     * An empty snapshot is enough for these tests: the Reflection_Text is built
     * either way and the fakes return a configured reply regardless of its text.
     */
    private function endedEpisodeWithSnapshot(
        InMemoryEpisodeRepository $repo,
        FakeRobotClient $reflectA,
        FakeRobotClient $reflectB,
        string $disaster = 'earthquake',
    ): string {
        $episodeId = $this->createEpisode($repo, $reflectA, $reflectB, $disaster);
        $this->runToEnd($repo, $episodeId, $disaster);
        // Post-feature episodes carry a snapshot (even an empty one) from
        // creation; store one so episodeHasSnapshot is true and reflect is
        // allowed.
        $repo->storeEpisodeMemory($episodeId, 1, new MemorySnapshot([]), new MemorySnapshot([]));

        // The reflect-under-test fakes were never used to drive the run, so
        // their call count is still zero here.
        self::assertSame(0, $reflectA->callCount());
        self::assertSame(0, $reflectB->callCount());

        return $episodeId;
    }

    /**
     * Drive a created episode's turn loop to its end, exactly as the browser
     * would (ask then apply per turn), flipping the earthquake at creation so
     * the quake starts on turn 1 and subsides on its own. Bounded so a stuck
     * run fails loudly rather than hanging. Uses its own run-robots.
     */
    private function runToEnd(
        InMemoryEpisodeRepository $repo,
        string $episodeId,
        string $disaster = 'earthquake',
    ): void {
        $service = $this->turnService($repo);
        $service->flipDisaster($episodeId, $disaster);

        $applied = 0;
        while (true) {
            $episode = $repo->findEpisode($episodeId);
            self::assertNotNull($episode);
            if ($episode->ended) {
                break;
            }

            $turnNumber = $episode->currentExpectedTurn;

            $askGuard = 0;
            do {
                $service->ask($episodeId, $turnNumber);
                $turn = $repo->findTurn($episodeId, $turnNumber);
                self::assertNotNull($turn);
                $askGuard++;
            } while ($turn->state === TurnStateValue::waiting && $askGuard < 5);

            $service->apply($episodeId, $turnNumber);

            if (++$applied > 100) {
                self::fail('the episode did not end within the applied-turn bound');
            }
        }
    }

    /**
     * A TurnService whose run-robots always answer (the default FakeRobotClient
     * reply) and whose recorder reads a `wait` action — always possible — so
     * every turn completes and the earthquake run advances to its end. These
     * run-robots are separate from the reflect-under-test fakes.
     */
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
}
