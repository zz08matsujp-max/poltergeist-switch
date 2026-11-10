<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Memory;

use PoltergeistSwitch\Engine\ReflectionTextBuilder;
use PoltergeistSwitch\Engine\Values\TurnRecord;
use PoltergeistSwitch\Memory\Values\MemoryRow;
use PoltergeistSwitch\Memory\Values\MemorySnapshot;
use PoltergeistSwitch\Memory\Values\ReflectionResult;
use PoltergeistSwitch\Memory\Values\RobotReflection;
use PoltergeistSwitch\TurnLoop\Clients\RobotClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\EngineFactory;
use PoltergeistSwitch\TurnLoop\Http\ApiError;
use PoltergeistSwitch\TurnLoop\ParallelHttp;
use PoltergeistSwitch\TurnLoop\Repository\EpisodeRepository;
use PoltergeistSwitch\TurnLoop\Values\PromptReplyRow;
use PoltergeistSwitch\TurnLoop\Values\ReplyRecord;

/**
 * Orchestrates one reflect request: after an ended episode it asks each robot
 * once, in its own words, what it wants to remember, and appends any non-empty
 * answer as one Memory (robot-memory spec, Requirement 2).
 *
 * The service reuses the turn-loop plumbing unchanged — the {@see RobotClient}
 * interface and its {@see ParallelHttp} `curl_multi` fan-out, the
 * {@see EngineFactory} replay, and the turn-loop {@see Config} (timeouts, cost
 * rates) — and holds no rules logic of its own: the fact-only Reflection_Text
 * is built by the pure {@see ReflectionTextBuilder}, and all storage goes
 * through the extended {@see EpisodeRepository}. The long AI call never sits
 * inside a held row lock: a short claim transaction marks each robot's slot, the
 * call is made outside any lock, and a short commit transaction stores the
 * answer and any retirement atomically (design "Reflection idempotency and the
 * MySQL 5.7 locking design").
 *
 * Idempotent per robot per episode (Requirements 2.8, 2.9, 2.10): a robot that
 * already answered is reused and never re-asked; a robot a concurrent call is
 * asking right now is skipped; a robot whose earlier attempt failed is asked
 * again. No device id, API key, database credential, secrets path, or AI
 * company/model name ever appears in a result (Requirement 2.12).
 *
 * PHP 8.2 only.
 */
final class ReflectService
{
    /** The two robot ids, asked in the fixed robot_a then robot_b order. */
    private const ROBOT_A = 'robot_a';
    private const ROBOT_B = 'robot_b';

    /**
     * The reserved sentinel turn number for a reflection prompt/response, so a
     * reflection row never collides with a turn read, which iterates the real
     * turn numbers 0..n (design "Storing reflection prompts and responses";
     * Requirement 2.5). One reflection per robot per episode, so attempt 0.
     */
    private const REFLECTION_TURN = -1;
    private const REFLECTION_ATTEMPT = 0;

    /** The single reflection call sends at most 4000 output tokens (Req 2.4). */
    private const MAX_OUTPUT_TOKENS = 4000;

    private ParallelHttp $parallel;

    /**
     * Returns the current wall-clock datetime as a 'Y-m-d H:i:s' string, used
     * to stamp a stored memory's created time. Injectable so a test can fix
     * "now" deterministically; defaults to the real clock.
     *
     * @var callable():string
     */
    private $clock;

    /**
     * @param RobotClient          $robotA        ClaudeClient in production
     * @param RobotClient          $robotB        GptClient in production
     * @param callable():string|null $clock        returns 'Y-m-d H:i:s'; defaults to date()
     */
    public function __construct(
        private RobotClient $robotA,
        private RobotClient $robotB,
        private ReflectionTextBuilder $builder,
        private EngineFactory $engineFactory,
        private EpisodeRepository $repo,
        private Config $config,
        ?ParallelHttp $parallel = null,
        ?callable $clock = null,
    ) {
        $this->parallel = $parallel ?? new ParallelHttp();
        $this->clock = $clock ?? static fn (): string => date('Y-m-d H:i:s');
    }

    /**
     * Ask both robots the Reflection_Question for one ended episode and append
     * any non-empty answer as one Memory (Requirement 2.1).
     *
     * @throws ApiError 404 when the episode is unknown; 409 `not_ended` when it
     *                  has not ended (including an idle-released episode, which
     *                  is not marked ended); 409 `not_reflectable` for a
     *                  pre-feature episode whose snapshot columns are NULL. In
     *                  every guard case no stored state changes and no
     *                  Robot_Client call is made.
     */
    public function reflect(string $episodeId): ReflectionResult
    {
        // 1. Guard: ended, reflectable episodes only (Requirements 2.2, 2.11).
        $episode = $this->repo->findEpisode($episodeId);
        if ($episode === null) {
            throw ApiError::notFound();
        }
        if (!$episode->ended) {
            // Not ended — including an episode released after 5 idle minutes,
            // which is never marked ended. Change no state, make no call.
            throw ApiError::conflict('not_ended');
        }
        if (!$this->repo->episodeHasSnapshot($episodeId)) {
            // Pre-feature episode (NULL snapshot columns): robots' memories
            // start only from episodes run after this feature shipped. Change
            // no state, make no call.
            throw ApiError::conflict('not_reflectable');
        }

        // 2. Rebuild the episode to obtain its ordered TurnRecords, earthquake
        //    start turn, and final world. forEpisodeWithWorld sets memories
        //    from the stored snapshot and returns the engine together with the
        //    very world it wraps, replayed through every applied turn.
        $built = $this->engineFactory->forEpisodeWithWorld($episodeId);
        $engine = $built['engine'];
        $finalWorld = $built['world'];

        // The recap begins at the first played/shaking turn (turn 1); turn 0 is
        // the quiet create-time turn and is dropped (design, "Reflection walks
        // the episode forwards").
        $records = $this->recapRecords($engine->turnRecords());
        $earthquakeStartTurn = $episode->earthquakeStartTurn ?? 0;

        $generation = $this->repo->episodeGeneration($episodeId);
        $disaster = $episode->disaster;
        // The Device that owns the episode: memory is scoped to it
        // (public-lab Requirements 2.1, 4.8). Read once, never sent to a robot.
        $deviceId = $episode->deviceId;

        // 3. Per robot, claim a slot. A claim is one of: reuse (already
        //    answered — reuse the stored body, no call), skip (a fresh
        //    concurrent in-progress claim — do nothing), or claimed (new,
        //    failed, or stale — build text and call).
        $clients = [self::ROBOT_A => $this->robotA, self::ROBOT_B => $this->robotB];

        /** @var array<string,RobotReflection> $reflections per-robot outcome */
        $reflections = [];
        /** @var array<string,\CurlHandle> $handles claimed robots to call */
        $handles = [];
        /** @var array<string,string> $prompts claimed robots' Reflection_Texts */
        $prompts = [];

        foreach ($clients as $robotId => $client) {
            $claim = $this->repo->claimReflectionSlot($episodeId, $robotId);

            if ($claim->isReuse()) {
                // Already answered: reuse the stored body verbatim, no call
                // (Requirement 2.8). Its reusedBody is the non-empty answer.
                $reflections[$robotId] = new RobotReflection(
                    answered: true,
                    memory: $claim->reusedBody,
                    activeCount: $this->repo->activeCount($deviceId, $robotId, $generation, $disaster),
                    reason: null,
                );

                continue;
            }

            if ($claim->isSkip()) {
                // A concurrent caller owns a fresh claim; do not double-ask
                // (Requirement 2.9). Report this robot's current answered state
                // without a call: if it has a stored answer reuse it, else it is
                // still being asked and has no memory yet.
                $reflections[$robotId] = $this->skippedReflection($episodeId, $robotId, $deviceId, $generation, $disaster);

                continue;
            }

            // Claimed (new, failed, or stale re-claim): build the Reflection_Text
            // and stage the call (Requirement 2.10). The text already ends with
            // the question, so it is sent as-is (Requirement 2.3).
            $prompt = $this->builder->build(
                $robotId,
                $records,
                $earthquakeStartTurn,
                $finalWorld,
                $this->snapshotFor($episodeId, $robotId),
            );
            $prompts[$robotId] = $prompt;
            $handles[$robotId] = $client->prepare($prompt);
        }

        // 4. Fan the claimed robots out in parallel through one curl_multi
        //    handle, with the same per-call timeout and failure handling as a
        //    turn (Requirements 2.3, 2.4). The clients own the one-user-message,
        //    no-system-prompt, max-4000-token, timeout configuration through
        //    prepare().
        $results = $this->parallel->run($handles);

        foreach ($handles as $robotId => $handle) {
            $client = $clients[$robotId];
            $result = $results[$robotId]
                ?? ['status' => 0, 'body' => null, 'seconds' => 0.0, 'error' => 'no_result'];
            $record = $client->finish($prompts[$robotId], $result);

            // 5. Store the full prompt with its Reflection_Text_Version and the
            //    full response with its usage metadata, tagged kind =
            //    'reflection' at the sentinel turn number -1, so no turn read
            //    ever picks it up (Requirement 2.5).
            $this->repo->savePromptAndReply(new PromptReplyRow(
                episodeId: $episodeId,
                turnNumber: self::REFLECTION_TURN,
                robotId: $robotId,
                attempt: self::REFLECTION_ATTEMPT,
                situationTextVersion: $this->builder->versionFor($finalWorld),
                reply: $record,
                kind: 'reflection',
            ));

            // 6. Commit the answer or the failure in a short transaction
            //    (Requirements 2.6, 2.7).
            $reflections[$robotId] = $this->commit(
                $episodeId,
                $robotId,
                $deviceId,
                $generation,
                $disaster,
                $record,
            );

            // No explicit curl_close: it is a no-op since PHP 8.0; the handle is
            // freed when it goes out of scope at the end of the request.
            unset($handle);
        }

        // 7. Return a per-robot result, keyed robot_a / robot_b, in the fixed
        //    order (Requirement 2.6). The body carries no secret or provider
        //    name (Requirement 2.12).
        return new ReflectionResult([
            self::ROBOT_A => $reflections[self::ROBOT_A],
            self::ROBOT_B => $reflections[self::ROBOT_B],
        ]);
    }

    /**
     * Commit one called robot's reflection (Requirements 2.6, 2.7): a non-empty
     * answer is stored verbatim as one Memory with the cap/retirement applied in
     * the same transaction and the slot marked answered; an empty answer or a
     * failed/abandoned call adds no memory, records a short reason, and releases
     * the slot to `failed` for a later retry.
     */
    private function commit(
        string $episodeId,
        string $robotId,
        string $deviceId,
        int $generation,
        string $disaster,
        ReplyRecord $record,
    ): RobotReflection {
        // A failed or abandoned call: no answer came back (Requirement 2.7).
        if ($record->unanswered) {
            $reason = $this->failureReasonOf($record);
            $this->repo->commitReflectionFailure($episodeId, $robotId, $reason);

            return new RobotReflection(
                answered: false,
                memory: null,
                activeCount: $this->repo->activeCount($deviceId, $robotId, $generation, $disaster),
                reason: $reason,
            );
        }

        // The call succeeded but the robot wants to keep nothing: a normal
        // outcome, not a failure (Requirement 2.7). An answer is "empty" when it
        // is blank or only whitespace.
        if (trim($record->reply) === '') {
            $this->repo->commitReflectionFailure($episodeId, $robotId, 'empty_answer');

            return new RobotReflection(
                answered: false,
                memory: null,
                activeCount: $this->repo->activeCount($deviceId, $robotId, $generation, $disaster),
                reason: 'empty_answer',
            );
        }

        // A non-empty answer: store it verbatim as one Memory tagged with the
        // robot, the episode's generation, the episode's disaster, the source
        // episode id, and a created time; the cap/retirement is applied in the
        // same transaction and the slot is marked answered (Requirement 2.6,
        // 8.4). The body is the reply byte-for-byte — not trimmed — so the
        // memory round-trips exactly as the robot wrote it.
        $memory = new MemoryRow(
            robotId: $robotId,
            generation: $generation,
            disaster: $disaster,
            sourceEpisodeId: $episodeId,
            body: $record->reply,
            status: 'active',
            createdAt: ($this->clock)(),
            deviceId: $deviceId,
        );
        $this->repo->commitReflectionAnswer($episodeId, $robotId, $memory);

        return new RobotReflection(
            answered: true,
            memory: $record->reply,
            activeCount: $this->repo->activeCount($deviceId, $robotId, $generation, $disaster),
            reason: null,
        );
    }

    /**
     * The outcome for a robot whose slot a concurrent caller is asking right now
     * (a fresh in-progress claim): this caller makes no call (Requirement 2.9).
     * If a stored answer already exists (a race where the other caller just
     * committed) it is reused; otherwise the robot is still being asked and has
     * no memory yet, reported as not answered with no reason.
     */
    private function skippedReflection(
        string $episodeId,
        string $robotId,
        string $deviceId,
        int $generation,
        string $disaster,
    ): RobotReflection {
        $stored = $this->repo->reflectionAnswer($episodeId, $robotId);
        if ($stored !== null) {
            return new RobotReflection(
                answered: true,
                memory: $stored->body,
                activeCount: $this->repo->activeCount($deviceId, $robotId, $generation, $disaster),
                reason: null,
            );
        }

        return new RobotReflection(
            answered: false,
            memory: null,
            activeCount: $this->repo->activeCount($deviceId, $robotId, $generation, $disaster),
            reason: null,
        );
    }

    /**
     * Map an unanswered ReplyRecord to the short reflection reason the result
     * reports (Requirement 2.7): a timed-out call is `timeout`, any other
     * failure or abandon is `no_reply`. The client's own failure reason token is
     * a short machine code (never a key, credential, path, or provider name), so
     * a `timeout` token maps straight through and everything else collapses to
     * `no_reply`, keeping the result's reasons to the small fixed set the End
     * card phrases.
     */
    private function failureReasonOf(ReplyRecord $record): string
    {
        return $record->failureReason === 'timeout' ? 'timeout' : 'no_reply';
    }

    /**
     * Drop the quiet create-time turn 0 from the replay's TurnRecords, leaving
     * the played turns the recap covers (turn 1 to the last), oldest first.
     *
     * @param list<TurnRecord> $all every applied turn's record, in turn order
     * @return TurnRecord[]
     */
    private function recapRecords(array $all): array
    {
        $records = [];
        foreach ($all as $record) {
            if ($record->turnNumber >= 1) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * The robot's frozen Memory_Snapshot for this episode, read from the stored
     * episode row (not a live query), so the Reflection_Text's memories block
     * is the verbatim block the live run showed (Requirement 6.6).
     */
    private function snapshotFor(string $episodeId, string $robotId): MemorySnapshot
    {
        return $this->repo->episodeSnapshot($episodeId, $robotId);
    }
}
