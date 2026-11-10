<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop;

use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\Values\Judgement;
use PoltergeistSwitch\Engine\Values\Outcome;
use PoltergeistSwitch\TurnLoop\Clients\RecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\RecorderInput;
use PoltergeistSwitch\TurnLoop\Clients\RobotClient;
use PoltergeistSwitch\TurnLoop\Clients\VerifiedReading;
use PoltergeistSwitch\TurnLoop\Http\ApiError;
use PoltergeistSwitch\TurnLoop\Repository\EpisodeRepository;
use PoltergeistSwitch\TurnLoop\Values\AppliedRobotRow;
use PoltergeistSwitch\TurnLoop\Values\AppliedTurnRows;
use PoltergeistSwitch\TurnLoop\Values\EpisodeRow;
use PoltergeistSwitch\TurnLoop\Values\EpisodeView;
use PoltergeistSwitch\TurnLoop\Values\PromptReplyRow;
use PoltergeistSwitch\TurnLoop\Values\Reading;
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

    /**
     * Serialises the engine's post-apply (and initial calm) WorldModel to the
     * per-turn `world_json` (screens spec Requirements 10.1, 10.2). A pure,
     * I/O-free mapper; constructed here since it holds no state.
     */
    private WorldStateSerializer $worldSerializer;

    /**
     * Fills a pre-existing episode's missing per-turn World_State once, by
     * replaying the engine (never AI), when the episode is read (screens spec
     * Requirements 10.7, 10.8). Built here from the same engine factory,
     * repository, and serializer the service already holds, so both this read
     * path and the ended-episode list (a later task) share one backfill.
     */
    private WorldStateBackfill $worldBackfill;

    /**
     * Returns the current wall-clock datetime as a 'Y-m-d H:i:s' string, used
     * to stamp episode activity (touchActivity). Injectable so a test can
     * advance "now" deterministically; defaults to the real clock via date().
     *
     * @var callable():string
     */
    private $clock;

    /**
     * @param callable():string|null $clock returns 'Y-m-d H:i:s'; defaults to
     *                                       date('Y-m-d H:i:s')
     */
    public function __construct(
        private RobotClient $robotA,          // ClaudeClient in production
        private RobotClient $robotB,          // GptClient in production
        private RecorderClient $recorder,
        private EngineFactory $engineFactory, // builds/rehydrates an Engine for an episode
        private EpisodeRepository $repo,
        private Config $config,
        ?ParallelHttp $parallel = null,       // fans both robots out through one curl_multi (Req 5.1)
        ?callable $clock = null,              // injectable clock for deterministic activity stamps
    ) {
        $this->parallel = $parallel ?? new ParallelHttp();
        $this->clock = $clock ?? static fn (): string => date('Y-m-d H:i:s');
        $this->worldSerializer = new WorldStateSerializer();
        $this->worldBackfill = new WorldStateBackfill(
            $this->repo,
            $this->engineFactory,
            $this->worldSerializer,
        );
    }

    /**
     * The Device_Episode_Slot checks (Requirements 9.7–9.9; public-lab 1.2,
     * 10.4, 13.8): 409 `device_already_running` (carrying the running
     * episode's id) when this device already has a running episode, then 409
     * `too_many_running` when the site-wide cap is reached. The lazy idle-slot
     * release is applied inside these repository queries.
     *
     * @throws ApiError
     */
    public function assertCanStart(string $deviceId): void
    {
        $running = $this->repo->findRunningEpisodeForDevice($deviceId);
        if ($running !== null) {
            throw ApiError::conflict('device_already_running', null, null, $running->id);
        }
        if ($this->repo->countRunningEpisodes() >= $this->config->maxRunningEpisodes) {
            throw ApiError::conflict('too_many_running');
        }
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
     * A heat-wave episode (heat-wave Requirements 8.1, 8.3) is created by
     * passing $disaster = 'heat_wave': the episode row records
     * disaster = 'heat_wave', the quiet turn 0 is played through the engine
     * rebuilt as a heat-wave world (so turn 0's stored World_State is the heat
     * starting situation — the resident at the low table in the early
     * afternoon, with the seed still varying the starting minute and the
     * robots' rooms), and the heat is fixed before turn 1 by recording the
     * heat-wave start turn (E − 1 = 0 after the quiet turn 0), exactly mirroring
     * the earthquake flip's non-negative start-turn mapping. An earthquake
     * episode (the default) is created exactly as before, with no heat field
     * touched (Requirement 1.6, 1.8).
     *
     * @param string   $deviceId the device cookie value; '' when absent
     * @param int|null $seed      an explicit seed for reproducible tests; null draws one
     * @param string   $disaster  'earthquake' (default) or 'heat_wave'
     */
    public function createEpisode(
        string $deviceId,
        ?int $seed = null,
        string $disaster = 'earthquake',
    ): EpisodeView {
        $deviceId = $deviceId !== '' ? $deviceId : $this->assignDeviceId();

        // Device_Episode_Slot rules first (Requirements 9.7–9.9).
        $this->assertCanStart($deviceId);

        $seedValue = $seed ?? $this->drawSeed();
        $now = $this->now();
        $episodeId = $this->mintEpisodeId();

        // Only the two disasters this spec knows are accepted; anything else
        // falls back to earthquake so a stray value never creates a malformed
        // episode. Heavy rain stays out of scope (its lever is disabled).
        $disaster = $disaster === 'heat_wave' ? 'heat_wave' : 'earthquake';

        // Record the episode first so the engine factory can rehydrate it. The
        // current expected turn starts at 0, the engine's first turn; the quiet
        // turn 0 below advances it to 1. A heat-wave episode stores
        // disaster = 'heat_wave' so the factory rebuilds it as a heat-wave
        // world (heat-wave Requirement 8.3); heat_wave_start_turn stays null
        // here and is set after the quiet turn 0, when the heat is fixed before
        // turn 1 (Requirement 8.1). An earthquake episode is unchanged.
        $this->repo->createEpisode(new EpisodeRow(
            id: $episodeId,
            deviceId: $deviceId,
            disaster: $disaster,
            seed: $seedValue,
            currentExpectedTurn: 0,
            earthquakeStartTurn: null,
            ended: false,
            createdAt: $now,
            lastActivityAt: $now,
            heatWaveStartTurn: null,
        ));

        // Play the quiet turn 0 through the engine — no robot calls, no actions,
        // as the engine tests do — so the initial situation is produced from the
        // seed and the current expected turn lands at 1 (task 10.1). Build the
        // engine together with the world it wraps so the initial calm,
        // pre-earthquake World_State can be serialised for turn 0 (Req 10.2).
        $built = $this->engineFactory->forEpisodeWithWorld($episodeId);
        $engine = $built['engine'];
        $world = $built['world'];
        $engine->prepareTurn(0);
        $result = $engine->applyTurn(0, []);

        // The initial World_State is the calm, pre-earthquake house after the
        // quiet turn 0 — no earthquake has been flipped yet (Req 10.2). Store it
        // as turn 0's world when the turn row is created, so GET returns it and
        // the Observe screen can show the calm turn-0 house before any turn
        // runs (design §4 "Start pressed").
        $initialWorldJson = $this->worldSerializer->toJson($world);

        // Persist the quiet turn 0 as an applied turn with no robot records, so
        // replay walks the same path the live loop walked (empty action list).
        $this->repo->createTurn($episodeId, 0, $initialWorldJson);
        $this->repo->storeAppliedTurn(new AppliedTurnRows(
            episodeId: $episodeId,
            turnNumber: 0,
            robots: [],
            residentLine: $result->residentLine,
            worldJson: $initialWorldJson,
        ));
        $this->repo->setTurnState($episodeId, 0, TurnStateValue::applied->value);

        // After the quiet turn 0 the current expected turn is 1. A quiet turn 0
        // never ends the episode, but honour the engine if it somehow did.
        if ($result->episodeEnded) {
            $this->repo->markEpisodeEnded($episodeId);
        } else {
            $this->repo->setCurrentExpectedTurn($episodeId, 1);
        }

        // Fix the heat wave before turn 1 (heat-wave Requirement 8.1): record
        // the heat-wave start turn as E − 1 = 0, exactly like the earthquake
        // flip's non-negative start-turn mapping, so the heat is on from the
        // first played turn. This is skipped for an earthquake episode, which
        // never touches the heat field. The episode is already a heat-wave
        // world (the row stored disaster = 'heat_wave'), so the heat rules run
        // every turn; the start turn only marks the fix so a later flip is
        // idempotent.
        if ($disaster === 'heat_wave' && !$result->episodeEnded) {
            $this->repo->setHeatWaveStartTurn($episodeId, 0);
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
        return $this->flipDisaster($episodeId, 'earthquake');
    }

    /**
     * Flip a disaster switch for an episode, disaster-aware and idempotent
     * (Requirement 4.4–4.6 for earthquake; heat-wave Requirement 8.2 for heat).
     *
     * $disaster selects which switch the observer raised:
     *   - 'earthquake' (default): the unchanged earthquake flip. When no start
     *     turn is recorded and the episode has not ended, the start turn is set
     *     to E − 1 so the next turn played opens the shaking; a repeated flip
     *     leaves it unchanged; an ended episode is rejected with 409. The
     *     earthquake path is byte-identical to today (Requirement 1.6).
     *   - 'heat_wave': the Heat wave flip. It converts a waiting (turn-0,
     *     not-yet-asked) default episode to a heat-wave episode in place — same
     *     seed, early afternoon, resident at the low table — then fixes the heat
     *     before turn 1 by recording the heat-wave start turn (E − 1 = 0). It is
     *     idempotent: a repeated flip on an already-heat-wave episode leaves the
     *     heat-wave start turn unchanged and returns success, never a 409
     *     (Requirement 8.2). An ended episode is still rejected with 409.
     *
     * Conversion is permitted ONLY while no turn has been asked, i.e. the
     * episode is still at the waiting turn 0 (current expected turn 1, nothing
     * past turn 0 applied); this is always true immediately after create, which
     * is the only moment the lever fires. An already-heat-wave episode skips
     * the conversion and only re-fixes the heat idempotently.
     */
    public function flipDisaster(string $episodeId, string $disaster = 'earthquake'): EpisodeView
    {
        $episode = $this->repo->findEpisode($episodeId);
        if ($episode === null) {
            throw ApiError::notFound();
        }

        // Ended episode: reject with 409 and change nothing (Requirement 4.6;
        // heat-wave Requirement 8.2 leaves the ended guard as the earthquake
        // flow has it).
        if ($episode->ended) {
            throw ApiError::conflict(
                'episode_ended',
                $episode->currentExpectedTurn,
                $this->currentTurnState($episodeId, $episode->currentExpectedTurn),
            );
        }

        // Server-side guard (heat-wave Requirement 8.2, "while no turn has been
        // asked yet"): a disaster can only be changed while the episode is the
        // waiting turn-0 state. Anything else is refused with 409 and nothing
        // is written, so a running earthquake is never turned into a heat wave
        // and a heat-wave episode never gets an earthquake start turn.
        if ($disaster === 'heat_wave') {
            if ($episode->disaster !== 'heat_wave' && !$this->isWaitingTurnZero($episode)) {
                throw $this->disasterAlreadySet($episode);
            }
            $this->fixHeatWave($episode);

            return $this->view($episodeId);
        }

        if ($episode->disaster === 'heat_wave') {
            throw $this->disasterAlreadySet($episode);
        }

        // Idempotent earthquake flip: a start turn already recorded is left
        // unchanged (Requirement 4.5).
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
     * True while the episode is the waiting turn-0 state: no earthquake start
     * turn, current expected turn 1, and turn 1 has no attempt, no no-reply
     * mark and no stored successful reply for either robot.
     */
    private function isWaitingTurnZero(EpisodeRow $episode): bool
    {
        if ($episode->earthquakeStartTurn !== null || $episode->currentExpectedTurn !== 1) {
            return false;
        }

        $turn = $this->repo->findTurn($episode->id, 1);
        if ($turn !== null
            && ($turn->attemptsA > 0 || $turn->attemptsB > 0 || $turn->noReplyA || $turn->noReplyB)
        ) {
            return false;
        }

        return $this->repo->findSuccessfulReply($episode->id, 1, self::ROBOT_A) === null
            && $this->repo->findSuccessfulReply($episode->id, 1, self::ROBOT_B) === null;
    }

    private function disasterAlreadySet(EpisodeRow $episode): ApiError
    {
        return ApiError::conflict(
            'disaster_already_set',
            $episode->currentExpectedTurn,
            $this->currentTurnState($episode->id, $episode->currentExpectedTurn),
        );
    }

    /**
     * Fix the heat wave for an episode (heat-wave Requirements 8.1, 8.2),
     * converting a waiting default episode to heat wave in place when needed.
     *
     * Idempotency is the load-bearing property: once the heat-wave start turn
     * is recorded, a repeated flip changes nothing and returns success, never a
     * 409 (Requirement 8.2). The conversion (setting the disaster and
     * re-laying the heat-wave turn-0 world) happens at most once, guarded by
     * the heat-wave start turn being null.
     */
    private function fixHeatWave(EpisodeRow $episode): void
    {
        // Already fixed: leave the heat-wave start turn unchanged and return
        // success (Requirement 8.2 idempotency). No second episode, no 409.
        if ($episode->heatWaveStartTurn !== null) {
            return;
        }

        // Convert a waiting default (earthquake) episode to heat wave in place
        // (Requirement 8.2): set its disaster and re-lay turn 0's World_State as
        // the heat-wave starting situation, built from the SAME seed so the
        // draw (the starting minute and the robots' rooms) is unchanged. An
        // episode already stored as heat wave (created with disaster=heat_wave)
        // skips this, so the conversion runs at most once.
        if ($episode->disaster !== 'heat_wave') {
            $this->repo->setDisaster($episode->id, 'heat_wave');

            // Rebuild turn 0 as a heat-wave world now that the row says heat
            // wave, and overwrite the stored turn-0 World_State so GET and the
            // screens read the heat starting situation (resident at the low
            // table, early afternoon). The engine factory reads the disaster
            // from the row, so forEpisodeWithWorld builds a heat-wave world.
            $built = $this->engineFactory->forEpisodeWithWorld($episode->id);
            $engine = $built['engine'];
            $world = $built['world'];
            $engine->prepareTurn(0);
            $engine->applyTurn(0, []);

            $this->repo->setTurnWorldJson(
                $episode->id,
                0,
                $this->worldSerializer->toJson($world),
            );
        }

        // Fix the heat before turn 1 (Requirement 8.1): record the heat-wave
        // start turn as E − 1, mirroring the earthquake flip's non-negative
        // mapping. After the quiet turn 0 the current expected turn is 1, so
        // the start turn is 0.
        $this->repo->setHeatWaveStartTurn(
            $episode->id,
            $episode->currentExpectedTurn - 1,
        );
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

        // Turn_Lock (public-lab Requirement 18): take the non-waiting ask lock
        // before anything else runs. A busy lock answers Turn_Busy with no
        // activity touch, no engine and no provider call.
        if (!$this->repo->acquireTurnLock('ask', $episodeId, $turnNumber)) {
            throw $this->turnBusy($episode);
        }

        try {
            return $this->askLocked($episodeId, $turnNumber);
        } finally {
            $this->repo->releaseTurnLock('ask', $episodeId, $turnNumber);
        }
    }

    /**
     * The 409 `turn_busy` answer: the existing 409 shape, so Observe can read
     * the current expected turn and the turn state.
     */
    private function turnBusy(EpisodeRow $episode): ApiError
    {
        return ApiError::conflict(
            'turn_busy',
            $episode->currentExpectedTurn,
            $this->currentTurnState($episode->id, $episode->currentExpectedTurn),
        );
    }

    /** The body of ask(), run while the ask Turn_Lock is held. */
    private function askLocked(string $episodeId, int $turnNumber): EpisodeView
    {
        // Re-read on fresh values now that the lock is held: another request
        // may have reserved attempts or applied the turn in between.
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

        // The request is accepted (the 404/409 gate above passed), so record
        // activity to keep this actively-stepping episode's running slot
        // (Requirement 9.10). A rejected ask never reaches here, so it does not
        // touch activity.
        $this->repo->touchActivity($episodeId, $this->now());

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
        /** @var array<string,int> $reservedAttempts attempt number reserved before the call */
        $reservedAttempts = [];
        foreach ($clients as $robotId => $client) {
            if ($this->repo->findSuccessfulReply($episodeId, $turnNumber, $robotId) !== null) {
                continue; // reuse the stored success; never call again (Req 5.2, 5.3)
            }
            if ($this->attemptsFor($turn, $robotId) >= 2) {
                continue; // attempts spent; counted already, no retry (Req 5.5)
            }

            // Reserve the attempt BEFORE the provider call (Requirement 18):
            // a request that dies after this costs one attempt, and a
            // reservation above the limit stages no call.
            $reserved = $this->repo->incrementAttempt($episodeId, $turnNumber, $robotId);
            if ($reserved > 2) {
                continue;
            }
            $reservedAttempts[$robotId] = $reserved;

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

            // The attempt was counted before the call (persisted across ask
            // calls — Requirements 5.4, 18); store under that number.
            $attempt = $reservedAttempts[$robotId];

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
     * Read both robots' replies, judge the turn once, and store the Four_Layers
     * for a turn whose Turn_State is `replies_complete` (Requirement 6).
     *
     * The request is rejected with HTTP 409 when the turn is in neither
     * `replies_complete` nor `applied` — e.g. still `waiting` — reading no
     * reply and not calling applyTurn (Requirements 6.1, 7.7); an ended episode
     * or a turn-number mismatch is rejected the same way ask gates them
     * (Requirement 7); an unknown episode id is a 404 (Requirement 9.5). For an
     * already-`applied` turn the current stored state is returned without
     * reading replies or calling applyTurn again (Requirement 6.6), which keeps
     * apply idempotent.
     *
     * On an accepted apply, activity is recorded so an actively-stepping
     * episode keeps its running slot (Requirement 9.10).
     *
     * For `replies_complete`: both stored replies are read with the recorder in
     * parallel under the per-call timeout with no retry (Requirement 6.2);
     * EvidenceCheck::verify runs on each reading so an action whose actionQuote
     * is missing or not an exact substring of the reply becomes `unknown`
     * (Requirement 6.3); a robot with no stored successful reply (marked no
     * reply) contributes an `unknown` action (Requirement 6.8). The rehydrated
     * engine is prepared with Engine::prepareTurn before Engine::applyTurn — it
     * is idempotent, so this is safe whether or not ask already prepared the
     * turn — so the stored resident line matches the one the robots read
     * (Requirement 6.3); applyTurn is then called EXACTLY ONCE with the actions
     * keyed by 'robot_a'/'robot_b'.
     *
     * The Four_Layers for each robot — situation text, reading, judgement,
     * outcome — plus the EvidenceCheck-verified action_json are stored within
     * one transaction via storeAppliedTurn (Requirements 6.4, 8.8, 13.3). On
     * success the turn's state is set to `applied` (Requirement 6.5). On a
     * mid-write failure storeAppliedTurn rolls back; the turn is left
     * `replies_complete` and the repository failure is propagated as the apply
     * failure (Requirement 6.7). When applyTurn reports the episode ended the
     * episode is marked ended; otherwise the current expected turn is advanced
     * to turnNumber + 1 (Requirements 7.4, 7.5).
     */
    public function apply(string $episodeId, int $turnNumber): EpisodeView
    {
        $episode = $this->repo->findEpisode($episodeId);
        if ($episode === null) {
            throw ApiError::notFound();
        }

        // Turn_Lock (public-lab Requirement 18): a busy apply lock answers
        // Turn_Busy before the recorder is called or anything is stored.
        if (!$this->repo->acquireTurnLock('apply', $episodeId, $turnNumber)) {
            throw $this->turnBusy($episode);
        }

        try {
            return $this->applyLocked($episodeId, $turnNumber);
        } finally {
            $this->repo->releaseTurnLock('apply', $episodeId, $turnNumber);
        }
    }

    /** The body of apply(), run while the apply Turn_Lock is held. */
    private function applyLocked(string $episodeId, int $turnNumber): EpisodeView
    {
        // Re-read on fresh values now that the lock is held.
        $episode = $this->repo->findEpisode($episodeId);
        if ($episode === null) {
            throw ApiError::notFound();
        }

        $turn = $this->repo->findTurn($episodeId, $turnNumber);

        // Already applied: return the current stored state without reading
        // replies or calling applyTurn again (Requirement 6.6). This is checked
        // BEFORE the ended/turn-mismatch gate so a repeated apply of a turn the
        // episode has already moved past (current expected turn advanced, or
        // the episode ended on this very turn) still returns its stored state
        // idempotently rather than being rejected.
        if ($turn !== null && $turn->state === TurnStateValue::applied) {
            return $this->view($episodeId);
        }

        // Ended / turn-number gate, consistent with ask (Requirement 7).
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

        // The turn must be `replies_complete` to apply; anything else (a
        // missing turn or a `waiting` one) is a wrong-state call rejected with
        // 409, reading no reply and not calling applyTurn (Requirements 6.1,
        // 7.7).
        if ($turn === null || $turn->state !== TurnStateValue::replies_complete) {
            $state = $turn !== null ? $turn->state->value : TurnStateValue::waiting->value;

            throw ApiError::conflict('turn_not_ready', $episode->currentExpectedTurn, $state);
        }

        // Accepted: record activity to keep this actively-stepping episode's
        // running slot (Requirement 9.10). A rejected apply never reaches here.
        $this->repo->touchActivity($episodeId, $this->now());

        // Build each robot's recorder input from the world at the start of this
        // turn (Requirement 3.4), and read the stored replies.
        $world = $this->engineFactory->worldForEpisode($episodeId);

        /** @var array<string,Action> $actions keyed by robot id for applyTurn */
        $actions = [];
        /** @var array<string,Reading> $readings keyed by robot id for storage */
        $readings = [];
        /** @var array<string,string> $replyTexts keyed by robot id */
        $replyTexts = [];

        // Stage recorder reads for the robots that produced a reply; a robot
        // with no stored successful reply contributes an `unknown` action and
        // is not read (Requirement 6.8).
        /** @var array<string,\CurlHandle> $handles */
        $handles = [];
        /** @var array<string,RecorderInput> $inputs */
        $inputs = [];
        foreach ([self::ROBOT_A, self::ROBOT_B] as $robotId) {
            $reply = $this->repo->findSuccessfulReply($episodeId, $turnNumber, $robotId);
            if ($reply === null) {
                // No reply: an `unknown` reading/action (Requirement 6.8).
                $readings[$robotId] = $this->unknownReading($robotId);
                $actions[$robotId] = $readings[$robotId]->action;
                $replyTexts[$robotId] = '';

                continue;
            }

            $replyTexts[$robotId] = $reply->reply;
            $inputs[$robotId] = RecorderInput::forRobot($world, $robotId);
            $handles[$robotId] = $this->recorder->prepare($inputs[$robotId], $reply->reply);
        }

        // Read both staged replies in parallel through one curl_multi handle,
        // under the per-call timeout, no retry (Requirement 6.2).
        $results = $this->parallel->run($handles);

        foreach ($handles as $robotId => $handle) {
            $result = $results[$robotId] ?? ['status' => 0, 'body' => null, 'seconds' => 0.0, 'error' => 'no_result'];
            $reading = $this->recorder->finish($inputs[$robotId], $replyTexts[$robotId], $result);

            // EvidenceCheck: an action not backed by an exact quote of the
            // reply becomes `unknown` (Requirement 6.3, 3.8). The reading's
            // action is rebound to this robot's id before judgement.
            $verified = VerifiedReading::verify($replyTexts[$robotId], $reading);
            $readings[$robotId] = $verified;
            $actions[$robotId] = $this->actionForRobot($robotId, $verified->action);

            unset($handle);
        }

        // Rehydrate the engine together with the world it wraps, prepare the
        // turn (idempotent) so the resident line matches the one the robots
        // read, then apply the turn EXACTLY ONCE (Requirement 6.3). The world
        // handed back is the engine's own, so after applyTurn it holds this
        // turn's post-apply state (screens spec Requirement 10.1).
        $built = $this->engineFactory->forEpisodeWithWorld($episodeId);
        $engine = $built['engine'];
        $postApplyWorld = $built['world'];
        $engine->prepareTurn($turnNumber);
        $result = $engine->applyTurn($turnNumber, $actions);

        // Serialise the post-apply World_State for this turn; it is written on
        // the turns row in the same transaction as the four-layer write, via
        // AppliedTurnRows below (Requirement 10.1).
        $worldJson = $this->worldSerializer->toJson($postApplyWorld);

        // Build the per-robot Four_Layers and store them in one transaction
        // (Requirements 6.4, 8.8, 13.3). On a mid-write failure storeAppliedTurn
        // rolls back and throws; the turn is left `replies_complete` and the
        // failure is propagated (Requirement 6.7).
        $version = $engine->situationTextVersion();
        $robots = [];
        foreach ([self::ROBOT_A, self::ROBOT_B] as $robotId) {
            $reading = $readings[$robotId];
            $action = $actions[$robotId];
            $judgement = $result->judgements[$robotId] ?? new Judgement($robotId, false);
            $outcome = $result->outcomes[$robotId] ?? Outcome::failure($robotId, 'no_outcome');

            $robots[$robotId] = new AppliedRobotRow(
                robotId: $robotId,
                situationText: $this->situationTextFor($episodeId, $turnNumber, $robotId),
                situationTextVersion: $version,
                recorderJson: $reading->rawJson,
                recorderUnknown: $reading->unknown,
                recorderPromptVersion: $reading->promptVersion !== ''
                    ? $reading->promptVersion
                    : $this->recorder->promptVersion(),
                judgementJson: $this->encodeJudgement($judgement),
                outcomeJson: $this->encodeOutcome($outcome),
                actionJson: ReplayedAction::toJson($action),
                recorderFailureReason: $reading->failureReason,
            );
        }

        // storeAppliedTurn is single-transaction and idempotent; it throws on a
        // mid-write failure after rolling back, leaving the turn
        // `replies_complete` (Requirement 6.7).
        $this->repo->storeAppliedTurn(new AppliedTurnRows(
            episodeId: $episodeId,
            turnNumber: $turnNumber,
            robots: $robots,
            residentLine: $result->residentLine,
            worldJson: $worldJson,
        ));

        // The write committed: advance the turn to `applied` (Requirement 6.5).
        $this->repo->setTurnState($episodeId, $turnNumber, TurnStateValue::applied->value);

        // Advance the episode: end it when applyTurn reports the end, otherwise
        // set the current expected turn to turnNumber + 1 (Requirements 7.4,
        // 7.5).
        if ($result->episodeEnded) {
            $this->repo->markEpisodeEnded($episodeId);
        } else {
            $this->repo->setCurrentExpectedTurn($episodeId, $turnNumber + 1);
        }

        return $this->view($episodeId);
    }

    /**
     * Return an episode's current state and its stored turn records as an
     * EpisodeView (Requirement 9.4; design "TurnService — the ask/apply
     * orchestration", GET /api/episodes/{id}).
     *
     * This is a pure read: it delegates straight to
     * EpisodeRepository::loadEpisodeView and never advances the state machine
     * or touches activity. An unknown episode id yields a 404 (Requirement
     * 9.5), raised by the shared view() helper when loadEpisodeView returns
     * null — the same path createEpisode/ask/apply use to return their views.
     */
    public function getEpisode(string $episodeId): EpisodeView
    {
        // Fill a pre-existing episode's missing per-turn World_State once before
        // returning the view, by replaying the engine exactly once (never AI)
        // and filling only the missing turns; turns already holding a world are
        // left byte-for-byte unchanged (screens spec Requirement 10.7). An
        // unknown id has no turns, so the backfill is a no-op and the 404 is
        // raised below by view(), leaving stored data unchanged (Requirement
        // 10.5). A replay that cannot complete throws a 'world_backfill_failed'
        // ApiError and persists nothing (Requirement 10.8). A freshly-run
        // episode already has every world, so this never replays for it.
        $this->worldBackfill->backfill($episodeId);

        return $this->view($episodeId);
    }

    /**
     * The stored situation text sent to one robot this turn, taken from the
     * robot's stored prompt when ask persisted one. A no-reply robot that was
     * never prompted (no stored reply) falls back to the empty string; the
     * other three layers are still stored (Requirement 6.8).
     */
    private function situationTextFor(string $episodeId, int $turnNumber, string $robotId): string
    {
        $reply = $this->repo->findSuccessfulReply($episodeId, $turnNumber, $robotId);

        return $reply !== null ? $reply->prompt : '';
    }

    /**
     * An `unknown` Reading for a robot that produced no reply (Requirement 6.8).
     * It carries the recorder prompt version so the stored recording layer
     * still records which recorder produced the (empty) reading.
     */
    private function unknownReading(string $robotId): Reading
    {
        return new Reading(
            action: new Action($robotId, Operation::unknown->value),
            unknown: true,
            rawJson: '',
            promptVersion: $this->recorder->promptVersion(),
            failureReason: 'no_reply',
        );
    }

    /**
     * Rebind a verified action to a specific robot id. The recorder returns an
     * action keyed with a generic placeholder (RecorderInput is ids-only and
     * carries no robot id), so apply rebinds it to 'robot_a'/'robot_b' before
     * keying it for applyTurn.
     */
    private function actionForRobot(string $robotId, Action $action): Action
    {
        if ($action->robotId === $robotId) {
            return $action;
        }

        return new Action(
            robotId: $robotId,
            operation: $action->operation,
            targetId: $action->targetId,
            targetSpot: $action->targetSpot,
            words: $action->words,
            actionQuote: $action->actionQuote,
            reported: $action->reported,
        );
    }

    /**
     * Encode a Judgement to the actions.judgement_json shape the repositories
     * decode (fields: possible, reason, dangerous, dangerCode, duration).
     */
    private function encodeJudgement(Judgement $judgement): string
    {
        $encoded = json_encode([
            'possible' => $judgement->possible,
            'reason' => $judgement->reason,
            'dangerous' => $judgement->dangerous,
            'dangerCode' => $judgement->dangerCode,
            'duration' => $judgement->duration,
        ]);

        return $encoded === false ? '{}' : $encoded;
    }

    /**
     * Encode an Outcome to the actions.outcome_json shape the repositories
     * decode (fields: success, whatHappenedText, seenByOtherText, errorMessage,
     * worldChanges).
     */
    private function encodeOutcome(Outcome $outcome): string
    {
        $encoded = json_encode([
            'success' => $outcome->success,
            'whatHappenedText' => $outcome->whatHappenedText,
            'seenByOtherText' => $outcome->seenByOtherText,
            'errorMessage' => $outcome->errorMessage,
            'worldChanges' => $outcome->worldChanges,
        ]);

        return $encoded === false ? '{}' : $encoded;
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
        return ($this->clock)();
    }
}
