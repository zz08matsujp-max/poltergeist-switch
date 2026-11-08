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

    public function __construct(
        private RobotClient $robotA,          // ClaudeClient in production
        private RobotClient $robotB,          // GptClient in production
        private RecorderClient $recorder,
        private EngineFactory $engineFactory, // builds/rehydrates an Engine for an episode
        private EpisodeRepository $repo,
        private Config $config,
    ) {}

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
