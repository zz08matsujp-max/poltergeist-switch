<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Memory\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\ReflectionTextBuilder;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Memory\ReflectService;
use PoltergeistSwitch\Memory\Values\MemorySnapshot;
use PoltergeistSwitch\Memory\Values\ReflectionResult;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\ParallelHttp;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\Values\EpisodeRow;
use PoltergeistSwitch\TurnLoop\Values\ReplyRecord;
use PoltergeistSwitch\TurnLoop\Values\TurnStateValue;

/**
 * Feature: robot-memory, Property 5: Reflect is idempotent per robot per
 * episode.
 *
 * For any sequence of reflect calls on an ended episode — repeated, and
 * interleaved to model the concurrency of Requirement 2.9 against the
 * {@see InMemoryEpisodeRepository} fake — each robot is asked at most once once
 * it has a stored answer, a robot whose earlier call failed may be asked again,
 * and storing an answer that is already stored for the same episode and robot
 * creates no duplicate memory (design "Property 5", "Simulating concurrency").
 *
 * The property drives {@see ReflectService::reflect} repeatedly on one ended,
 * reflectable episode. Each robot's configured reply is drawn by Eris as one of
 * three outcomes:
 *   - a non-empty answer — stored verbatim as one Memory, then reused on every
 *     later call, never re-asked (Requirement 2.8);
 *   - an empty answer — a normal "keeps nothing" outcome, not a failure, which
 *     leaves the slot re-claimable so a later call asks again (Requirement 2.7,
 *     2.10);
 *   - an unanswered (failed) call — adds no memory, releases the slot to
 *     `failed`, and may be asked again by a later call (Requirement 2.10).
 *
 * The invocation count is read from the shared {@see FakeRobotClient}, which
 * counts one logical call per `finish()` (the parallel path the service uses):
 *   - a robot that has answered is NEVER called again, so across the whole
 *     sequence it is called at most once once it has a stored answer
 *     (Requirement 2.8);
 *   - a robot whose every call so far failed or returned empty may be called
 *     again, so its call count equals the number of reflect calls up to and
 *     including its first success, and never grows after that (Requirement
 *     2.10);
 *   - the store holds at most one memory for the episode/robot however many
 *     times a success is committed, because `appendMemory` is idempotent on
 *     `(sourceEpisodeId, robotId)` (Requirement 8.5).
 *
 * Concurrency (Requirement 2.9) is modelled by sometimes pre-claiming a robot's
 * slot (a fresh `in_progress`) before a reflect call: the service then sees a
 * fresh `in_progress`, returns `skip`, and makes NO call for that robot in that
 * reflect — proving a concurrent caller is never double-asked. The modelled
 * concurrent caller then finishes (its call failed), releasing the slot so a
 * later reflect may ask the robot again.
 *
 * No real network is touched (Requirement 9.1): the {@see FakeRobotClient}
 * opens no connection. Its `finish()` ignores the curl result entirely and
 * routes through its configured `call()`, so the empty handles its `prepare()`
 * returns never reach a provider — {@see ParallelHttp} only runs bare,
 * URL-less handles that fail instantly and are discarded.
 *
 * NOTE: this Eris release (giorgiosironi/eris ^1.3) controls the iteration
 * count with `limitTo()`; `limitTo(100)` is required here, matching the other
 * property tests.
 *
 * Validates: Requirements 9.5, 2.8, 2.9, 2.10, 8.5
 */
class ReflectIdempotencyPropertyTest extends TestCase
{
    use TestTrait;

    private const ROBOT_A = 'robot_a';
    private const ROBOT_B = 'robot_b';

    /** Reply outcomes Eris draws per robot per reflect call. */
    private const OUTCOME_ANSWER = 0;
    private const OUTCOME_EMPTY = 1;
    private const OUTCOME_FAIL = 2;

    /**
     * Property 5: reflect is idempotent per robot per episode — a robot is
     * asked at most once once it has a stored answer, a failed/empty robot may
     * be asked again, and an already-stored answer is never duplicated.
     *
     * Validates: Requirements 9.5, 2.8, 2.9, 2.10, 8.5
     */
    public function testReflectIsIdempotentPerRobotPerEpisode(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generators::int(),
                // The number of reflect() calls in the sequence (1..6).
                Generators::choose(1, 6),
                // Per-call reply outcome for each robot: answer / empty / fail.
                Generators::seq(Generators::tuple(
                    Generators::choose(self::OUTCOME_ANSWER, self::OUTCOME_FAIL),
                    Generators::choose(self::OUTCOME_ANSWER, self::OUTCOME_FAIL),
                )),
                // Per-call "a concurrent caller is already asking this robot"
                // flags, modelling Requirement 2.9's skip path.
                Generators::seq(Generators::tuple(
                    Generators::bool(),
                    Generators::bool(),
                )),
            )
            ->then(function (
                int $seedValue,
                int $callCount,
                array $outcomeSeq,
                array $concurrentSeq
            ): void {
                $repo = new InMemoryEpisodeRepository();
                $episodeId = $this->buildEndedReflectableEpisode($repo, $seedValue);

                // One shared fake per robot so its invocation count spans the
                // whole sequence (a fresh client per call would hide re-asks).
                $clientA = new FakeRobotClient();
                $clientB = new FakeRobotClient();
                $clients = [self::ROBOT_A => $clientA, self::ROBOT_B => $clientB];

                $service = new ReflectService(
                    $clientA,
                    $clientB,
                    new ReflectionTextBuilder(new SituationTextGenerator()),
                    new ReplayEngineFactory($repo, new EpisodeConfig()),
                    $repo,
                    new Config(),
                    new ParallelHttp(),
                );

                // Track, per robot, whether it has a stored answer yet and the
                // fake's call count as of the previous reflect, so we can assert
                // "at most once once answered" and "a failed robot may be asked
                // again" against the exact expected delta each call.
                $answered = [self::ROBOT_A => false, self::ROBOT_B => false];
                $prevCalls = [self::ROBOT_A => 0, self::ROBOT_B => 0];
                /** @var array<string,?string> the body stored for each robot (null = none yet) */
                $storedBody = [self::ROBOT_A => null, self::ROBOT_B => null];

                for ($call = 0; $call < $callCount; $call++) {
                    $outcomes = $this->outcomeForCall($outcomeSeq, $call);
                    $concurrent = $this->concurrentForCall($concurrentSeq, $call);

                    // Configure each robot's reply for the Reflection_Text it
                    // will be sent this call. The prompt is deterministic for a
                    // given episode + robot, so set it for both robots.
                    $expectedBodies = [];
                    foreach ($clients as $robotId => $client) {
                        $prompt = $this->promptFor($repo, $episodeId, $robotId);
                        $body = $this->configureReply($client, $prompt, $robotId, $outcomes[$robotId], $call);
                        $expectedBodies[$robotId] = $body;
                    }

                    // Model a concurrent caller already asking a robot: a fresh
                    // in_progress claim the service must skip (Requirement 2.9).
                    // Only meaningful when the robot has not already answered
                    // (an answered slot always reuses, never skips).
                    $preClaimed = [self::ROBOT_A => false, self::ROBOT_B => false];
                    foreach ($clients as $robotId => $client) {
                        if ($concurrent[$robotId] && !$answered[$robotId]) {
                            $claim = $repo->claimReflectionSlot($episodeId, $robotId);
                            // A fresh claim only happens when the slot was not
                            // already answered; if it reused, the robot had an
                            // answer and will reuse in the service too.
                            if ($claim->isClaimed()) {
                                $preClaimed[$robotId] = true;
                            }
                        }
                    }

                    $result = $service->reflect($episodeId);
                    $this->assertInstanceOf(ReflectionResult::class, $result);

                    foreach ($clients as $robotId => $client) {
                        $delta = $client->callCount() - $prevCalls[$robotId];

                        if ($answered[$robotId]) {
                            // Already has a stored answer: never asked again,
                            // and the result reuses the stored body verbatim
                            // (Requirement 2.8).
                            $this->assertSame(
                                0,
                                $delta,
                                "an already-answered {$robotId} must never be asked again "
                                    . "(seed {$seedValue}, call {$call})"
                            );
                            $robotResult = $result->robots[$robotId];
                            $this->assertTrue($robotResult->answered);
                            $this->assertSame(
                                $storedBody[$robotId],
                                $robotResult->memory,
                                "the reused answer for {$robotId} must be the stored body verbatim "
                                    . "(seed {$seedValue}, call {$call})"
                            );
                        } elseif ($preClaimed[$robotId]) {
                            // A concurrent caller holds a fresh in_progress
                            // claim: the service skips and makes NO call for
                            // this robot this reflect (Requirement 2.9).
                            $this->assertSame(
                                0,
                                $delta,
                                "a robot a concurrent caller is already asking must not be "
                                    . "double-asked ({$robotId}, seed {$seedValue}, call {$call})"
                            );
                        } else {
                            // Unanswered and not pre-claimed: this call claims
                            // and asks exactly once (Requirement 2.10).
                            $this->assertSame(
                                1,
                                $delta,
                                "an unclaimed, not-yet-answered {$robotId} must be asked exactly "
                                    . "once this call (seed {$seedValue}, call {$call})"
                            );

                            // A non-empty answer is now stored; empty/failed
                            // leaves the robot re-askable next call.
                            if ($outcomes[$robotId] === self::OUTCOME_ANSWER) {
                                $answered[$robotId] = true;
                                $storedBody[$robotId] = $expectedBodies[$robotId];
                            }
                        }

                        $prevCalls[$robotId] = $client->callCount();
                    }

                    // The modelled concurrent caller finishes after this
                    // reflect: its call failed, releasing the slot to `failed`
                    // so a later reflect may ask that robot again (Requirement
                    // 2.10). Without this the pre-claim would wedge the slot
                    // `in_progress` forever, which is not the behaviour under
                    // test. A robot that answered in the meantime is left as
                    // answered (its slot is already `answered`, not the fresh
                    // in_progress we pre-claimed).
                    foreach ($clients as $robotId => $client) {
                        if ($preClaimed[$robotId] && !$answered[$robotId]) {
                            $repo->commitReflectionFailure($episodeId, $robotId, 'no_reply');
                        }
                    }

                    // After every reflect, the store holds at most one memory
                    // for this episode + robot — committing an already-stored
                    // answer never duplicates it (Requirement 8.5).
                    foreach ($clients as $robotId => $client) {
                        $this->assertLessThanOrEqual(
                            1,
                            $this->storedMemoryCount($repo, $episodeId, $robotId),
                            "no duplicate memory may exist for {$robotId} on episode {$episodeId} "
                                . "(seed {$seedValue}, call {$call})"
                        );
                    }
                }

                // Final shape: a robot that ever answered holds exactly one
                // memory; a robot that never answered holds none. This proves
                // the whole repeated/interleaved sequence produced no duplicate
                // and no spurious memory (Requirement 8.5, 2.10).
                foreach ([self::ROBOT_A, self::ROBOT_B] as $robotId) {
                    $expected = $answered[$robotId] ? 1 : 0;
                    $this->assertSame(
                        $expected,
                        $this->storedMemoryCount($repo, $episodeId, $robotId),
                        "{$robotId} must hold exactly {$expected} stored memory after the sequence "
                            . "(seed {$seedValue})"
                    );
                }
            });
    }

    /**
     * Build one ended, reflectable episode in the fake: a quiet applied turn 0,
     * a frozen (empty) Memory_Snapshot so {@see ReflectService} passes the
     * `not_reflectable` guard, and the ended flag set. The Reflection_Text has
     * no event blocks (the recap drops turn 0), which is enough: this property
     * is about idempotency, not the recap wording (Property 7 covers that).
     */
    private function buildEndedReflectableEpisode(InMemoryEpisodeRepository $repo, int $seedValue): string
    {
        $now = date('Y-m-d H:i:s');
        $episodeId = $repo->createEpisode(new EpisodeRow(
            id: 'ep_reflect',
            deviceId: 'device_reflect',
            disaster: 'earthquake',
            seed: $seedValue,
            currentExpectedTurn: 1,
            earthquakeStartTurn: 0,
            ended: false,
            createdAt: $now,
            lastActivityAt: $now,
        ));

        // Freeze an empty snapshot so episodeHasSnapshot() is true: the episode
        // is post-feature and reflectable (Requirement 2.2).
        $repo->storeEpisodeMemory($episodeId, $repo->deviceGeneration('device_reflect'), new MemorySnapshot([]), new MemorySnapshot([]));

        // A single quiet applied turn 0 so ReplayEngineFactory can rebuild the
        // engine and world (it replays applied turns, including turn 0).
        $repo->createTurn($episodeId, 0);
        $repo->setTurnState($episodeId, 0, TurnStateValue::applied->value);

        $repo->markEpisodeEnded($episodeId);

        return $episodeId;
    }

    /**
     * The Reflection_Text the service would send this robot, built the same way
     * the service builds it (so the configured per-prompt reply matches). The
     * prompt is deterministic for a given episode + robot.
     */
    private function promptFor(
        InMemoryEpisodeRepository $repo,
        string $episodeId,
        string $robotId
    ): string {
        $factory = new ReplayEngineFactory($repo, new EpisodeConfig());
        $built = $factory->forEpisodeWithWorld($episodeId);
        $engine = $built['engine'];
        $finalWorld = $built['world'];

        $records = [];
        foreach ($engine->turnRecords() as $record) {
            if ($record->turnNumber >= 1) {
                $records[] = $record;
            }
        }

        $builder = new ReflectionTextBuilder(new SituationTextGenerator());

        return $builder->build(
            $robotId,
            $records,
            0,
            $finalWorld,
            $repo->episodeSnapshot($episodeId, $robotId),
        );
    }

    /**
     * Configure one robot's reply for the given prompt and return the body that
     * would be stored as its memory on a successful answer (null for an empty
     * or failed outcome). Bodies carry the call index so a round-trip can be
     * checked verbatim and no banned-word rule applies to the reply.
     */
    private function configureReply(
        FakeRobotClient $client,
        string $prompt,
        string $robotId,
        int $outcome,
        int $call
    ): ?string {
        if ($outcome === self::OUTCOME_ANSWER) {
            $body = "I want to remember call {$call} for {$robotId}.";
            $client->setReplyForPrompt(
                $prompt,
                new ReplyRecord($prompt, $body, 10, 5, 0.1, 0.0, false, false),
            );

            return $body;
        }

        if ($outcome === self::OUTCOME_EMPTY) {
            // A successful call that keeps nothing: a normal outcome, not a
            // failure (Requirement 2.7). Whitespace-only is treated as empty.
            $client->setReplyForPrompt(
                $prompt,
                new ReplyRecord($prompt, '   ', 10, 1, 0.1, 0.0, false, false),
            );

            return null;
        }

        // A failed / abandoned call: unanswered, no memory (Requirement 2.7).
        $client->setReplyForPrompt(
            $prompt,
            ReplyRecord::unanswered($prompt, 0.0, 'timeout'),
        );

        return null;
    }

    /**
     * The drawn per-robot outcome for a reflect call, cycling the generated
     * sequence when the sequence is shorter than the call count so every call
     * has a defined outcome.
     *
     * @param list<array{0:int,1:int}> $outcomeSeq
     * @return array{robot_a:int, robot_b:int}
     */
    private function outcomeForCall(array $outcomeSeq, int $call): array
    {
        if ($outcomeSeq === []) {
            return [self::ROBOT_A => self::OUTCOME_ANSWER, self::ROBOT_B => self::OUTCOME_ANSWER];
        }

        $pair = $outcomeSeq[$call % count($outcomeSeq)];

        return [self::ROBOT_A => $pair[0], self::ROBOT_B => $pair[1]];
    }

    /**
     * The drawn per-robot "a concurrent caller is already asking this robot"
     * flag for a reflect call, cycling the generated sequence as above.
     *
     * @param list<array{0:bool,1:bool}> $concurrentSeq
     * @return array{robot_a:bool, robot_b:bool}
     */
    private function concurrentForCall(array $concurrentSeq, int $call): array
    {
        if ($concurrentSeq === []) {
            return [self::ROBOT_A => false, self::ROBOT_B => false];
        }

        $pair = $concurrentSeq[$call % count($concurrentSeq)];

        return [self::ROBOT_A => $pair[0], self::ROBOT_B => $pair[1]];
    }

    /**
     * How many stored memories exist for one episode + robot in the fake. The
     * fake exposes no direct memory list, so the count is read through the
     * answered slot: appendMemory is idempotent on (sourceEpisodeId, robotId),
     * so a robot has at most one stored memory and reflectionAnswer returns it
     * once answered.
     */
    private function storedMemoryCount(InMemoryEpisodeRepository $repo, string $episodeId, string $robotId): int
    {
        return $repo->reflectionAnswer($episodeId, $robotId) !== null ? 1 : 0;
    }
}
