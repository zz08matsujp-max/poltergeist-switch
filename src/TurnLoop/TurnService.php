<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop;

use PoltergeistSwitch\TurnLoop\Clients\RecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\RobotClient;
use PoltergeistSwitch\TurnLoop\Http\ApiError;
use PoltergeistSwitch\TurnLoop\Repository\EpisodeRepository;
use PoltergeistSwitch\TurnLoop\Values\AppliedTurnRows;
use PoltergeistSwitch\TurnLoop\Values\EpisodeRow;
use PoltergeistSwitch\TurnLoop\Values\EpisodeView;
use PoltergeistSwitch\TurnLoop\Values\PromptReplyRow;
use PoltergeistSwitch\TurnLoop\Values\TurnRow;
use PoltergeistSwitch\TurnLoop\Values\TurnStateValue;

/**
 * The heart of the turn loop: it sequences the engine and the clients and
 * persists through the repository, holding no rules logic of its own (design
 * "TurnService — the ask/apply orchestration").
 *
 * This file is built up across several tasks; task 10 implements episode
 * creation and the earthquake flip (Requirement 4). The ask/apply/getEpisode
 * methods are added by later tasks.
 *
 * Robot ids are the literals 'robot_a' and 'robot_b'. Turn numbers are
 * zero-based throughout, following the engine's own numbering (Requirements
 * 4.1, 12.3).
 */
class TurnService
{
    private const ROBOT_A = 'robot_a';
    private const ROBOT_B = 'robot_b';

    private ParallelHttp $parallel;

    public function __construct(
        private RobotClient $robotA,          // ClaudeClient in production
        private RobotClient $robotB,          // GptClient in production
        private RecorderClient $recorder,
        private EngineFactory $engineFactory, // builds/rehydrates an Engine for an episode
        private EpisodeRepository $repo,
        private Config $config,
        ?ParallelHttp $parallel = null,       // fans both robots out through one curl_multi (Req 5.1)
    ) {
        $this->parallel = $parallel ?? new ParallelHttp();
    }

    /**
     * Create a new earthquake episode and return its view (Requirement 4.1–4.3,
     * 9.6–9.9).
     *
     * The Device_Episode_Slot rules are enforced first (Requirements 9.7–9.9):
     * the request is rejected with HTTP 409 when this device already has a
     * running episode, or when the across-all-devices running cap is already
     * full. Only then is an episode created.
     *
     * A seed is drawn (or taken from $seed when the caller supplies one, so the
     * reproducibility test can fix it), and the episode is recorded with
     * disaster = earthquake and that seed. The engine's own first turn is turn
     * 0; createEpisode plays a QUIET turn 0 through the engine — no robot calls
     * and no actions, exactly as the engine's own tests play a quiet turn — so
     * that the current expected turn lands at 1. This is what makes the
     * earthquake-switch mapping have no negative start turn (house-rules-engine
     * Requirement 13.6; task 10.1): the engine offset is `turn − startTurn`, so
     * the current expected turn E is offset 1 only when startTurn = E − 1, and
     * with E = 1 after the quiet turn 0 the flip sets startTurn = 0, which is
     * always ≥ 0.
     *
     * The quiet turn 0 is persisted as an applied turn with an empty robot set
     * so ReplayEngineFactory replays it (it is driven by the turns table, not
     * the actions table — see ReplayEngineFactory). The same stored seed
     * reproduces the same initial situation on any PHP 8.2 build, because the
     * engine's Seed wraps a private Mt19937 Randomizer (Requirement 4.3).
     *
     * A device id is assigned when the cookie is absent (an empty $deviceId),
     * so a first-time visitor gets a stable device slot.
     *
     * @param string   $deviceId the device cookie value; '' when absent
     * @param int|null $seed      an explicit seed for reproducible tests; null draws one
     */
    public function createEpisode(string $deviceId, ?int $seed = null): EpisodeView
    {
        $deviceId = $deviceId !== '' ? $deviceId : $this->assignDeviceId();

        // Device_Episode_Slot rules first (Requirements 9.7–9.9). The lazy
        // idle-slot release is applied inside these repository queries.
        if ($this->repo->findRunningEpisodeForDevice($deviceId) !== null) {
            throw ApiError::conflict('device_already_running');
        }
        if ($this->repo->countRunningEpisodes() >= $this->config->maxRunningEpisodes) {
            throw ApiError::conflict('too_many_running');
        }

        $seedValue = $seed ?? $this->drawSeed();
        $now = $this->now();
        $episodeId = $this->mintEpisodeId();

        // Record the episode first so the engine factory can rehydrate it. The
        // current expected turn starts at 0, the engine's first turn; the quiet
        // turn 0 below advances it to 1.
        $this->repo->createEpisode(new EpisodeRow(
            id: $episodeId,
            deviceId: $deviceId,
            disaster: 'earthquake',
            seed: $seedValue,
            currentExpectedTurn: 0,
            earthquakeStartTurn: null,
            ended: false,
            createdAt: $now,
            lastActivityAt: $now,
        ));

        // Play the quiet turn 0 through the engine — no robot calls, no actions,
        // as the engine tests do — so the initial situation is produced from the
        // seed and the current expected turn lands at 1 (task 10.1).
        $engine = $this->engineFactory->forEpisode($episodeId);
        $engine->prepareTurn(0);
        $result = $engine->applyTurn(0, []);

        // Persist the quiet turn 0 as an applied turn with no robot records, so
        // replay walks the same path the live loop walked (empty action list).
        $this->repo->createTurn($episodeId, 0);
        $this->repo->storeAppliedTurn(new AppliedTurnRows(
            episodeId: $episodeId,
            turnNumber: 0,
            robots: [],
            residentLine: $result->residentLine,
        ));
        $this->repo->setTurnState($episodeId, 0, TurnStateValue::applied->value);

        // After the quiet turn 0 the current expected turn is 1. A quiet turn 0
        // never ends the episode, but honour the engine if it somehow did.
        if ($result->episodeEnded) {
            $this->repo->markEpisodeEnded($episodeId);
        } else {
            $this->repo->setCurrentExpectedTurn($episodeId, 1);
        }

        return $this->view($episodeId);
    }

    /**
     * Flip the earthquake switch for an episode (Requirement 4.4–4.6).
     *
     * When no earthquake start turn is recorded and the episode has not ended,
     * the start turn is set to E − 1, where E is the current expected turn, so
     * the next turn played (E) is earthquake offset 1 — the first shaking turn
     * whose paragraph opens "Suddenly the floor begins to shake hard."
     * (Requirement 4.4; house-rules-engine Requirement 13.6). Because
     * createEpisode leaves E at 1 after the quiet turn 0, the start turn is 0,
     * never negative (task 10.1).
     *
     * The flip is idempotent: when a start turn is already recorded it is left
     * unchanged and the current state is returned (Requirement 4.5). When the
     * episode has already ended the request is rejected with HTTP 409 and
     * nothing changes (Requirement 4.6).
     */
    public function flipEarthquake(string $episodeId): EpisodeView
    {
        $episode = $this->repo->findEpisode($episodeId);
        if ($episode === null) {
            throw ApiError::notFound();
        }

        // Ended episode: reject with 409 and change nothing (Requirement 4.6).
        if ($episode->ended) {
            throw ApiError::conflict(
                'episode_ended',
                $episode->currentExpectedTurn,
                $this->currentTurnState($episodeId, $episode->currentExpectedTurn),
            );
        }

        // Idempotent flip: a start turn already recorded is left unchanged
        // (Requirement 4.5).
        if ($episode->earthquakeStartTurn === null) {
            // The next turn played (the current expected turn E) must be
            // earthquake offset 1, so startTurn = E − 1. E is ≥ 1 after the
            // quiet turn 0, so startTurn is ≥ 0 (never negative).
            $this->repo->setEarthquakeStartTurn(
                $episodeId,
                $episode->currentExpectedTurn - 1,
            );
        }

        return $this->view($episodeId);
    }

    /**
     * Build each robot's situation text and collect both replies idempotently
     * (Requirement 5), for a turn whose Turn_State is `waiting`.
     *
     * The engine is rehydrated by replay (Requirement 13.1). The request is
     * rejected with HTTP 409 when the turn number does not equal the episode's
     * current expected turn, when the episode has ended, or when the turn is
     * already `applied` (Requirement 7); an unknown episode id is a 404 raised
     * by findEpisode's null (Requirement 9.5).
     *
     * Engine::prepareTurn is called ONCE before building either robot's text so
     * the text a robot reads and the world its action is later judged in are
     * the same turn's world (Requirements 11.5, 12.4). Only robots that have no
     * stored successful reply AND fewer than 2 attempts are called; each is
     * built with Engine::situationTextFor (no memories — Requirement 4.7) and a
     * prepared curl handle, then all staged robots are fired in parallel
     * through one curl_multi handle (Requirement 5.1).
     *
     * As each reply arrives it is stored with its prompt and the situation-text
     * version from Engine::situationTextVersion (Requirements 8.3, 11.6) and the
     * robot's attempt count is incremented (persisted across repeated ask calls
     * — Requirement 5.4). A robot with a stored success is reused and never
     * called again (Requirements 5.2, 5.3). A failed or abandoned attempt is
     * counted without a retry in this request (Requirement 5.5). A robot that
     * reaches 2 failed attempts with no success is marked no reply
     * (Requirement 5.6). When every robot is answered or marked no reply the
     * turn becomes `replies_complete`; otherwise it stays `waiting`
     * (Requirements 5.7, 5.8).
     */
    public function ask(string $episodeId, int $turnNumber): EpisodeView
    {
        $episode = $this->repo->findEpisode($episodeId);
        if ($episode === null) {
            throw ApiError::notFound();
        }

        // Turn-number / ended / already-applied gate (Requirement 7).
        if ($episode->ended) {
            throw ApiError::conflict(
                'episode_ended',
                $episode->currentExpectedTurn,
                $this->currentTurnState($episodeId, $episode->currentExpectedTurn),
            );
        }
        if ($turnNumber !== $episode->currentExpectedTurn) {
            throw ApiError::conflict(
                'turn_mismatch',
                $episode->currentExpectedTurn,
                $this->currentTurnState($episodeId, $episode->currentExpectedTurn),
            );
        }

        $turn = $this->repo->findTurn($episodeId, $turnNumber);
        if ($turn !== null && $turn->state === TurnStateValue::applied) {
            // ask on an already-applied turn is a wrong-state call (Req 7.7).
            throw ApiError::conflict('turn_applied', $episode->currentExpectedTurn, $turn->state->value);
        }

        // Create the turn row (state `waiting`) before staging. createTurn is
        // idempotent, so a repeated ask never resets the attempt counts.
        if ($turn === null) {
            $this->repo->createTurn($episodeId, $turnNumber);
            $turn = $this->repo->findTurn($episodeId, $turnNumber);
        }

        // Rehydrate the engine and prepare the turn ONCE before building either
        // robot's text (Requirements 11.5, 12.4).
        $engine = $this->engineFactory->forEpisode($episodeId);
        $engine->prepareTurn($turnNumber);
        $version = $engine->situationTextVersion();

        // Stage only the robots that still need a call: no stored successful
        // reply AND fewer than 2 attempts (Requirements 5.2, 5.3, 5.4).
        $clients = [self::ROBOT_A => $this->robotA, self::ROBOT_B => $this->robotB];

        /** @var array<string,\CurlHandle> $handles */
        $handles = [];
        /** @var array<string,string> $prompts */
        $prompts = [];
        foreach ($clients as $robotId => $client) {
            if ($this->repo->findSuccessfulReply($episodeId, $turnNumber, $robotId) !== null) {
                continue; // reuse the stored success; never call again (Req 5.2, 5.3)
            }
            if ($this->attemptsFor($turn, $robotId) >= 2) {
                continue; // attempts spent; counted already, no retry (Req 5.5)
            }

            $prompt = $engine->situationTextFor($robotId); // no memories (Req 4.7)
            $prompts[$robotId] = $prompt;
            $handles[$robotId] = $client->prepare($prompt);
        }

        // Fire the staged robots in parallel through one curl_multi handle
        // (Requirement 5.1); no retry inside this request (Requirement 5.5).
        $results = $this->parallel->run($handles);

        foreach ($handles as $robotId => $handle) {
            $client = $clients[$robotId];
            $result = $results[$robotId] ?? ['status' => 0, 'body' => null, 'seconds' => 0.0, 'error' => 'no_result'];
            $record = $client->finish($prompts[$robotId], $result);

            // Count the attempt (persisted across ask calls — Requirement 5.4).
            $attempt = $this->repo->incrementAttempt($episodeId, $turnNumber, $robotId);

            // Store the prompt and the reply with the situation-text version
            // (Requirements 8.3, 11.6). A failed/abandoned attempt is stored as
            // an unanswered record and simply counted (Requirement 5.5).
            $this->repo->savePromptAndReply(new PromptReplyRow(
                episodeId: $episodeId,
                turnNumber: $turnNumber,
                robotId: $robotId,
                attempt: $attempt,
                situationTextVersion: $version,
                reply: $record,
            ));

            // No explicit curl_close: it has no effect since PHP 8.0 (and is
            // deprecated from 8.5), so the handle is freed when $handle goes
            // out of scope at the end of this request.
            unset($handle);
        }

        // Reload the turn so the fresh attempt counts drive the no-reply and
        // completion decisions below.
        $turn = $this->repo->findTurn($episodeId, $turnNumber);

        // A robot with 2 spent attempts and no stored success is marked no
        // reply (Requirement 5.6). Its recorded action becomes `unknown` at
        // apply time, when a no-reply robot contributes an unknown action.
        $allSettled = true;
        foreach ($clients as $robotId => $client) {
            if ($this->repo->findSuccessfulReply($episodeId, $turnNumber, $robotId) !== null) {
                continue; // answered
            }
            if ($this->attemptsFor($turn, $robotId) >= 2) {
                $this->repo->markNoReply($episodeId, $turnNumber, $robotId);

                continue; // settled as no reply
            }
            $allSettled = false; // still has attempts left; stay `waiting`
        }

        // When every robot is answered or marked no reply, advance to
        // `replies_complete`; otherwise leave the turn `waiting` (Req 5.7, 5.8).
        if ($allSettled) {
            $this->repo->setTurnState($episodeId, $turnNumber, TurnStateValue::replies_complete->value);
        }

        return $this->view($episodeId);
    }

    /**
     * The robot's persisted attempt count on a turn, 0 when the turn row is
     * missing. robot_a reads attemptsA, robot_b reads attemptsB.
     */
    private function attemptsFor(?TurnRow $turn, string $robotId): int
    {
        if ($turn === null) {
            return 0;
        }

        return $robotId === self::ROBOT_A ? $turn->attemptsA : $turn->attemptsB;
    }

    /**
     * Load an episode's view, failing loudly when the episode vanished between
     * a check and this load (a programming error, not a user-facing 404).
     */
    private function view(string $episodeId): EpisodeView
    {
        $view = $this->repo->loadEpisodeView($episodeId);
        if ($view === null) {
            throw ApiError::notFound();
        }

        return $view;
    }

    /**
     * The state of one turn as a string, for an error body. A turn that has not
     * been created yet reports 'waiting', its initial state (Requirement 7.1).
     */
    private function currentTurnState(string $episodeId, int $turnNumber): string
    {
        $turn = $this->repo->findTurn($episodeId, $turnNumber);

        return $turn !== null ? $turn->state->value : TurnStateValue::waiting->value;
    }

    /**
     * Draw a seed for a new episode. Replay is reproducible from the STORED
     * seed on any PHP 8.2 build (Requirement 4.3); the draw itself only needs to
     * vary between episodes, so a cryptographically-seeded draw is fine here.
     */
    private function drawSeed(): int
    {
        return random_int(0, PHP_INT_MAX);
    }

    /**
     * Assign a random device id when the cookie is absent (Requirement 9.6).
     */
    private function assignDeviceId(): string
    {
        return 'dev_' . bin2hex(random_bytes(16));
    }

    /**
     * Mint a new episode id. A UUIDv4-shaped hex id keeps it opaque and
     * collision-free across devices without leaking any secret.
     */
    private function mintEpisodeId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
