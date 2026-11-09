<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine;

use Throwable;
use PoltergeistSwitch\Engine\Enums\Disaster;
use PoltergeistSwitch\Engine\Enums\ManActivity;
use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\Enums\PowerState;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\Values\Judgement;
use PoltergeistSwitch\Engine\Values\Outcome;
use PoltergeistSwitch\Engine\Values\TurnActions;
use PoltergeistSwitch\Engine\Values\TurnRecord;
use PoltergeistSwitch\Engine\Values\TurnResult;

/**
 * The engine's single public entry point. `applyTurn` advances the world by
 * exactly one turn and returns a complete, immutable TurnResult. It is pure
 * (no I/O, no AI, no DB) and idempotent: re-calling it with an already-processed
 * turn number returns the stored TurnResult without mutating the world again
 * (Req 10.1–10.7).
 *
 * The Engine owns the other components and the per-turn history. It does not
 * trigger the earthquake itself: the observer layer records the start turn on
 * the WorldModel (`earthquakeStartTurn`); the Engine detects that transition,
 * captures the stove flame state once, and advances the earthquake phase each
 * turn by the offset `current_turn − earthquake_start_turn` (design §d, §g).
 *
 * Requirements: Req 10.1–10.7
 */
class Engine
{
    /** Fixed robot slot order used throughout the turn (design §d). */
    private const ROBOT_A = 'robot_a';
    private const ROBOT_B = 'robot_b';

    /** Target id that names the resident himself (support/move/cover). */
    private const RESIDENT_ID = 'resident';

    /** Object id of the gas stove; a `use` on it turns the flame off (Req 3.5). */
    private const GAS_STOVE_ID = 'gas_stove';

    /** Object id of the window air conditioner (heat-wave Requirement 5). */
    private const AIR_CONDITIONER_ID = 'window_air_conditioner';

    /** The two in-room support destinations that keep the resident's room (Req 7.13). */
    private const SPOT_UNDER_TABLE = 'under_table';
    private const SPOT_BESIDE_FUTON = 'beside_futon';

    /** Earthquake offset on which the power fails and the lights go out (Req 3.3). */
    private const POWER_OUTAGE_OFFSET = 2;

    /**
     * Earthquake offsets on which the house shakes (Req 3.2). Kept here only to
     * derive the per-turn SituationView flag `wasShakingPreviousTurn`; the
     * authoritative shaking state is set on HouseState by EarthquakePhase.
     */
    private const SHAKING_OFFSETS = [1, 2, 3, 4, 12];

    /** @var array<int,TurnResult> processed turn number => stored TurnResult */
    private array $turnHistory = [];

    /**
     * Ordered, append-only history of TurnRecord value objects — one per
     * applied turn, in turn order (design §d step 10, §i). Unlike $turnHistory
     * this is a plain list, never keyed by turn, and it is appended only on the
     * normal processing path: the error path (errorResult) adds nothing. The
     * situation text for the next turn reads the last five entries so the robot
     * sees up to the last five turns of recent events (Req 9.16–9.18).
     *
     * @var list<TurnRecord>
     */
    private array $turnRecords = [];

    /**
     * Tracks the earthquake start turn seen on the previous applyTurn so the
     * Engine can capture the stove flame exactly once, on the turn the
     * earthquake becomes active (design §g, Req 3.5).
     */
    private ?int $lastSeenEarthquakeStartTurn = null;

    /**
     * Number of consecutive turns up to and including the current one on which
     * the resident met every stability condition (Req 10.6). Reset to zero the
     * moment any condition fails, so a stable run must be unbroken. Read only by
     * checkEpisodeEnd().
     */
    private int $stableRunCount = 0;

    /**
     * The resident's injury value recorded at the end of the immediately
     * preceding turn, or null before the first turn is checked. Req 10.6's
     * fourth stability condition compares the current injury against this value
     * (injury must not have risen). On the very first checked turn there is no
     * preceding turn, so the comparison is treated as satisfied.
     */
    private ?int $previousInjury = null;

    /**
     * Turn number whose world has already been advanced by prepareTurn, or null
     * when no turn is prepared ahead of applyTurn (Req 13.3). applyTurn consults
     * this marker so the earthquake world is advanced exactly once for a turn:
     * when it equals the turn being applied, applyTurn skips its own earthquake
     * advance (design §d step 2). prepareTurn itself is idempotent per turn — a
     * second call for the same turn number does nothing.
     *
     * applyTurn consumes the marker (resets it to null) as soon as it takes
     * over the prepared turn, so the marker never outlives its turn and a later
     * turn cannot mistake a stale value for a prepared turn.
     */
    private ?int $preparedTurn = null;

    /**
     * The resident line fixed for the prepared turn (Req 13.8). prepareTurn
     * computes the line the robots will read this turn — a refusal carried from
     * the previous turn, otherwise the scripted line — and stores it here so
     * applyTurn emits exactly that line instead of calling emitDialogueLine a
     * second time (which no robot would read, and which would double-record the
     * line's cooldown). Null when the prepared turn has no line. Consumed (reset
     * to null) by applyTurn together with $preparedTurn once the prepared turn
     * is applied.
     */
    private ?string $preparedResidentLine = null;

    /**
     * A resident line produced while applying a turn (the support refusal
     * "I don't want to move from here.", Req 7.15) that no robot has read yet,
     * held so the NEXT prepareTurn carries it into that turn's text with
     * priority over any scripted line (Req 13.8). Consumed (set back to null)
     * the moment prepareTurn uses it. Written by applyTurn but only ever read by
     * prepareTurn, so an episode that never calls prepareTurn is unaffected
     * (Req 13.7).
     */
    private ?string $pendingResidentLine = null;

    /**
     * Each robot's ordered, verbatim memory bodies for this episode (oldest
     * first), keyed by robot id ('robot_a' / 'robot_b'), as frozen in the
     * episode's Memory_Snapshot (robot-memory Requirement 6.1, 6.2). Set once
     * for an episode through setMemories() before any situationTextFor() call;
     * when it is never set, or a robot's list is empty, that robot's turn text
     * carries no Memories_Block and behaviour is unchanged (Requirement 6.5).
     *
     * @var array<string,string[]>
     */
    private array $memoriesByRobot = [];

    /**
     * The heat-wave phase, the sibling of EarthquakePhase. It is optional so
     * every existing earthquake call site is unchanged: when a caller supplies
     * none, a fresh HeatWavePhase is created, and it is only ever reached from
     * the heat branch of processCurrentTurn (gated on the world's disaster), so
     * an earthquake episode never constructs it into its turn path or touches a
     * heat field (heat-wave Requirement 1.6).
     */
    private HeatWavePhase $heatWave;

    public function __construct(
        private WorldModel $world,
        private EpisodeConfig $config,
        private RulesEvaluator $evaluator,
        private ActionExecutor $executor,
        private EarthquakePhase $earthquake,
        private ResidentController $resident,
        private SituationTextGenerator $textGen,
        ?HeatWavePhase $heatWave = null,
    ) {
        $this->heatWave = $heatWave ?? new HeatWavePhase();
    }

    /**
     * Supply each robot's frozen memory bodies for this episode (robot-memory
     * Requirement 6.2). Call once before building any turn text, with
     * $memoriesByRobot = ['robot_a' => string[], 'robot_b' => string[]] holding
     * each robot's ordered verbatim memory bodies, oldest first. The same list
     * is used for every turn of the episode, so each robot reads the same
     * Memories_Block for the whole run. An absent robot key or an empty list
     * means that robot has no memories this episode and its turn text carries
     * no block (Requirement 6.5), leaving the S0–S5 golden texts unchanged when
     * no memories are set.
     *
     * @param array<string,string[]> $memoriesByRobot
     */
    public function setMemories(array $memoriesByRobot): void
    {
        $this->memoriesByRobot = $memoriesByRobot;
    }

    /**
     * Advance the world by one turn and return its TurnResult (design §d).
     *
     * Idempotency (Req 10.2, Req 10.3): a turn number below the current turn
     * returns the stored result untouched; a turn number above it returns an
     * error result without advancing; the current turn is processed once and
     * its result is stored so a repeat call returns the identical object.
     *
     * No exception propagates out of this method (design §Error Handling): the
     * whole body runs inside a try/catch, and any failure yields an error
     * TurnResult while leaving the world as the rollback left it.
     *
     * @param array<string,Action> $actions actions keyed by robot id
     */
    public function applyTurn(int $turnNumber, array $actions): TurnResult
    {
        // 1. Idempotency check (before any mutation).
        if ($turnNumber < $this->world->currentTurn) {
            // Past turn: hand back exactly what was stored, no re-application.
            if (array_key_exists($turnNumber, $this->turnHistory)) {
                return $this->turnHistory[$turnNumber];
            }

            // No stored result for a past turn means the caller is confused
            // about the clock; report it without touching the world.
            return $this->errorResult($turnNumber, 'turn_already_passed');
        }

        if ($turnNumber > $this->world->currentTurn) {
            // Future turn: cannot be reached yet; leave the world unchanged.
            return $this->errorResult($turnNumber, 'turn_not_reached');
        }

        try {
            return $this->processCurrentTurn($turnNumber, $actions);
        } catch (Throwable $e) {
            // The engine never throws out of applyTurn; surface the failure as
            // an error TurnResult. ActionExecutor already rolls back its own
            // mutations, so the world is left in a consistent state.
            return $this->errorResult($turnNumber, $e->getMessage());
        }
    }

    /**
     * Build the full situation text sent to one robot for the current turn
     * (design §i, Req 10.1). It reads the live world through SituationView and
     * the last five applied TurnRecords (oldest first), which the generator
     * renders as the recent-events section under the "In the last 15 seconds:"
     * heading.
     *
     * This robot's frozen memory bodies (set via setMemories, robot-memory
     * Requirement 6.2) are rendered into the section-3 Memories_Block in the
     * Appendix A form and threaded to the generator. When no memories were set
     * for this robot, or its list is empty, the block is null and no section 3
     * appears, so existing behaviour is unchanged (Requirement 6.5).
     *
     * @param string $robotId 'robot_a' or 'robot_b'
     */
    public function situationTextFor(string $robotId): string
    {
        $recent = array_slice($this->turnRecords, -5);

        $memoriesBlock = $this->textGen->buildMemoriesBlock(
            $this->memoriesByRobot[$robotId] ?? []
        );

        return $this->textGen->generate($robotId, $this->world, $recent, $memoriesBlock);
    }

    /**
     * Return this episode's ordered, append-only {@see TurnRecord} history —
     * one per applied turn, in turn order, exactly as the live loop accumulated
     * it (design §d step 10, §i). Read-only: the list is returned as a plain,
     * zero-based array and the Engine's own copy is never exposed for mutation.
     *
     * The reflection flow (robot-memory Requirement 2.3) reads this after a
     * full replay through {@see \PoltergeistSwitch\TurnLoop\ReplayEngineFactory}
     * to render the fact-only Reflection_Text: it includes the quiet turn 0,
     * which the reflection recap drops because the recap begins at the first
     * played/shaking turn (turn 1). This is a pure read and advances no state.
     *
     * @return list<TurnRecord>
     */
    public function turnRecords(): array
    {
        return array_values($this->turnRecords);
    }

    /**
     * Return the situation-text version (Req 14.1). This is the plain
     * SituationTextGenerator::VERSION constant, which identifies the fixed body
     * text, the fixed house text, and the generator's output format and is
     * bumped whenever any of those change (Req 14.2). Every text produced by
     * situationTextFor comes from that same fixed text and generator, so a
     * caller can store this version alongside each prompt to record what
     * produced it (Req 14.3).
     */
    public function situationTextVersion(): string
    {
        return SituationTextGenerator::VERSION;
    }

    /**
     * Advance the earthquake world to the turn about to be played WITHOUT
     * judging, so a following situationTextFor renders that same turn's world
     * (Req 13.1–13.2, Req 13.8).
     *
     * prepareTurn performs only the world-advancing, text-facing part of a
     * turn: it captures the stove-was-on flag on the earthquake-start
     * transition, advances the earthquake phase by this turn's offset with the
     * same EarthquakePhase::advanceTurn call applyTurn uses (which selects the
     * falling objects on a shaking turn), fixes the resident line for the turn,
     * and sets the four per-turn SituationView flags the text needs. It never
     * evaluates or executes actions, never updates resident state beyond the
     * line it fixes, and never advances the turn clock — applyTurn still owns
     * all of that (design §d).
     *
     * Idempotent per turn (Req 13.3): a $preparedTurn marker records the turn
     * already prepared; a second call for the same turn number returns at once
     * without advancing the earthquake world again. A call for anything other
     * than the engine's current turn is ignored, because only the current turn
     * can be prepared before it is applied.
     *
     * Because applyTurn consults $preparedTurn (step 2), calling
     * prepareTurn($turnNumber) and then applyTurn($turnNumber, …) advances the
     * earthquake exactly once (Req 13.4). An episode that never calls
     * prepareTurn advances it inside applyTurn exactly as before (Req 13.5,
     * Req 13.7).
     *
     * Earthquake-switch mapping (Req 13.6): the observer layer sets
     * earthquakeStartTurn to a non-negative turn T; the first turn played after
     * the switch is offset 1, the first shaking turn, whose paragraph opens
     * "Suddenly the floor begins to shake hard." There is no negative start
     * turn.
     */
    public function prepareTurn(int $turnNumber): void
    {
        // Idempotent per turn: a second call for the same turn does nothing.
        if ($this->preparedTurn === $turnNumber) {
            return;
        }

        // Only the engine's current turn can be prepared ahead of applyTurn;
        // ignore any other turn number without touching the world.
        if ($turnNumber !== $this->world->currentTurn) {
            return;
        }

        // Capture the stove flame once, on the turn the earthquake becomes
        // active (Req 3.5), exactly as applyTurn step 2 would.
        $this->captureStoveOnEarthquakeStart();

        // Advance the earthquake phase by this turn's offset — the same call
        // applyTurn makes — so the shaking, power and falling-object state is
        // the turn's state (Req 13.2). selectFallingObjects runs inside
        // advanceTurn on a shaking turn.
        $offset = null;
        if ($this->world->earthquakeStartTurn !== null) {
            $offset = $turnNumber - $this->world->earthquakeStartTurn;
            $this->earthquake->advanceTurn($this->world, $this->world->seed, $offset);
        }

        // Fix the resident line for this turn (Req 13.8): a refusal produced
        // while applying the previous turn is carried here and takes priority
        // over any scripted line; otherwise emit the scripted line. The line is
        // decided once, in prepareTurn, so the line the robots read in the text
        // is the line of this turn.
        $residentLine = $this->takePendingResidentLine()
            ?? $this->resident->emitDialogueLine(
                $this->world,
                $this->config,
                $turnNumber,
            );
        $this->preparedResidentLine = $residentLine;

        // Set the four per-turn SituationView flags so the next situation text
        // shows the earthquake and resident-speech cues for this turn.
        $this->setSituationFlags($offset, $residentLine);

        // Mark the turn prepared so applyTurn skips its own earthquake advance
        // and reuses the fixed line (Req 13.3, Req 13.4).
        $this->preparedTurn = $turnNumber;
    }

    /**
     * Return and consume any resident line carried from the previous turn (a
     * support refusal that no robot has read yet, Req 13.8). Returns null when
     * nothing is pending. Consuming it here, in prepareTurn, is what makes the
     * refusal surface in turn n+1's text and nowhere else.
     */
    private function takePendingResidentLine(): ?string
    {
        $line = $this->pendingResidentLine;
        $this->pendingResidentLine = null;

        return $line;
    }

    /**
     * Process the current turn (steps 2–10 of design §d). Called only when the
     * idempotency check has confirmed `$turnNumber === currentTurn`.
     *
     * @param array<string,Action> $actions actions keyed by robot id
     */
    private function processCurrentTurn(int $turnNumber, array $actions): TurnResult
    {
        // Capture each robot's room at the START of the turn, before any move
        // in executeActions() (step 4). Rule A compares these start rooms (and
        // the end rooms in the snapshot) to decide whether a robot saw the
        // other robot's action this turn (Req 9.16).
        $roomsAtStart = [
            self::ROBOT_A => $this->world->robotStates[self::ROBOT_A]->room,
            self::ROBOT_B => $this->world->robotStates[self::ROBOT_B]->room,
        ];

        // 2. Advance the earthquake phase if active. The observer layer sets
        //    earthquakeStartTurn on the world; the Engine captures the stove
        //    flame the first turn it sees it set (Req 3.5), then advances the
        //    phase by the current offset every turn until the phase ends.
        //
        //    When prepareTurn already prepared this turn (Req 13.4), the
        //    earthquake world was advanced there, so skip the advance here to
        //    avoid doing it twice; still compute the offset so the later
        //    SituationView flags are correct. An unprepared turn advances the
        //    phase here exactly as before, keeping applyTurn correct whether or
        //    not prepareTurn was called (Req 13.5, Req 13.7).
        $turnWasPrepared = $this->preparedTurn === $turnNumber;

        // Consume the prepared-turn markers the moment applyTurn takes over the
        // turn they belong to (Req 13.4). The line is read into a local so
        // step 6 can still reuse it; both markers are then cleared so they
        // never outlive their turn. This keeps the $preparedTurn guard a local
        // fact about THIS turn rather than relying on turn numbers always
        // increasing: once consumed, no later turn can mistake a stale marker
        // for a prepared turn and wrongly skip its own earthquake advance.
        $preparedLine = null;
        if ($turnWasPrepared) {
            $preparedLine = $this->preparedResidentLine;
            $this->preparedTurn = null;
            $this->preparedResidentLine = null;
        }

        $offset = null;
        if ($this->world->earthquakeStartTurn !== null) {
            $offset = $turnNumber - $this->world->earthquakeStartTurn;
        }

        if (!$turnWasPrepared) {
            $this->captureStoveOnEarthquakeStart();
            if ($offset !== null) {
                $this->earthquake->advanceTurn($this->world, $this->world->seed, $offset);
            }
        }

        // 2b. Advance the heat-wave phase (room temperatures: drift, AC
        //     cooling, open-window bonus, clamp) in a heat-wave turn, before
        //     the resident's heat body rule reads this turn's room temperature.
        //     Gated on the disaster (heat-wave Requirement 1.6): an earthquake
        //     turn never enters this branch, so no earthquake field is touched
        //     and the earthquake path runs exactly as today.
        if ($this->world->disaster === Disaster::heat_wave) {
            $this->heatWave->advanceTurn($this->world, $this->world->seed, $turnNumber);
        }

        // 3. Evaluate both actions in the fixed robot_a → robot_b order.
        $allActions = $this->orderedActions($actions);
        $judgements = $this->evaluateActions($allActions);

        // 4. Execute possible actions in this turn's priority order. On an even
        //    turn robot_a goes first, otherwise robot_b (design §d step 4).
        $outcomes = $this->executeActions($turnNumber, $allActions, $judgements);

        // Clearing the delayed stove hazard on a `use` that turns the flame off
        // is handled inside executeActions(), right after each execution, so
        // the EarthquakePhase flag reflects the flame state before offset 6.

        // 5. Update the resident's mutable state from this turn's actions.
        $turnActions = $this->buildTurnActions($allActions, $outcomes);
        $this->resident->updateState($this->world, $this->config, $turnActions);

        // 5a. In a heat-wave turn, apply the resident's body-temperature and
        //     water heat rules on this turn's final room temperature, then
        //     re-clamp and refresh the weakened flag (heat-wave Req 3.1–3.7).
        //     Run after updateState so the heat body rule, its clamp, and the
        //     weakened refresh are the last word on body_temp/water this turn.
        //     Gated on the disaster: an earthquake turn never runs it, so the
        //     earthquake resident path is unchanged (heat-wave Requirement 1.6).
        if ($this->world->disaster === Disaster::heat_wave) {
            $this->resident->applyHeatToResident($this->world, $this->config);
        }

        // 5a². Resolve the resident's air-conditioner will in a heat-wave turn
        //      (heat-wave Requirement 5). The reply is immediate — spoken this
        //      turn, outside the 5-turn cooldown, at `acLinePriority` above
        //      every other resident line (Req 5.4) — so it takes precedence
        //      over both the scripted line and a support refusal below. The
        //      acceptance test reads the alternative-prepared latch as it stood
        //      BEFORE this turn (it only counts an earlier turn, Req 5.8); the
        //      latch is then advanced from this turn's facts so the next turn
        //      sees it. Gated on the disaster, so an earthquake turn never runs
        //      it and $acLine stays null. See resolveAirConditioner().
        $acLine = null;
        if ($this->world->disaster === Disaster::heat_wave) {
            $acLine = $this->resolveAirConditioner($turnNumber, $allActions, $outcomes);
        }

        // 5b. Resolve any support proposal made this turn: the resident decides
        //     on the turn of the support action (Req 7.15). A refusal ends the
        //     support; the refusal line is produced here, after the robots have
        //     already acted this turn, so no robot reads it this turn — it is
        //     carried into the next turn's text by prepareTurn (Req 13.8).
        $refusalLine = $this->resolveSupportProposals($allActions, $outcomes);

        // 5c. Wake a man who started asleep once the earthquake has struck
        //     (Req 32.1, Req 32.3). This runs during applyTurn (processCurrentTurn),
        //     AFTER prepareTurn has already produced this turn's situation text,
        //     so the first shaking turn (offset 1) text still reads "asleep in
        //     his futon" (Req 32.1); the wake only takes effect in the world
        //     snapshot stored below (step 10) and in every later turn's text,
        //     which prepareTurn renders from the already-woken world at offset
        //     >= 2 (Req 32.3). Both the prepared and unprepared paths reach
        //     here and both compute $offset above, so offset 1's apply always
        //     does the wake. Idempotent: once lying_in_futon he never returns
        //     to asleep_in_futon, and this never fires for the other starting
        //     activities (Req 32.6). Room and spot are untouched (bedroom /
        //     futon); support/standing from lying_in_futon resolves unchanged
        //     because moveResidentTo overwrites the activity (Req 32.5).
        if (
            $offset !== null
            && $offset >= 1
            && $this->world->residentState->activity === ManActivity::asleep_in_futon->value
        ) {
            $this->world->residentState->activity = ManActivity::lying_in_futon->value;
        }

        // 6. Fix this turn's single resident line. When prepareTurn prepared
        //    this turn it already decided the line (a refusal carried from the
        //    previous turn, or the scripted line); reuse it so applyTurn does
        //    not emit a second line that no robot reads and does not
        //    double-record the line's cooldown (Req 13.8). An unprepared turn
        //    keeps the original behaviour (Req 13.7): a refusal produced this
        //    turn claims the line, else the scripted line is emitted now.
        if ($turnWasPrepared) {
            $residentLine = $preparedLine;
        } else {
            $residentLine = $refusalLine
                ?? $this->resident->emitDialogueLine(
                    $this->world,
                    $this->config,
                    $turnNumber,
                );
        }

        // An immediate air-conditioner reply (Req 5.1–5.3) or the cooldown-
        // governed repeat (Req 5.6) is spoken THIS turn at `acLinePriority`
        // above every other resident line (Req 5.4, 5.6), so it replaces
        // whatever line was fixed above — the scripted line, a support refusal,
        // or the prepared line. It is uncooled for the immediate replies and
        // governed by the cooldown only for the repeat, both resolved in
        // resolveAirConditioner() before this point.
        if ($acLine !== null) {
            $residentLine = $acLine;
        }

        // Carry any refusal produced this turn into the next turn's text; it is
        // consumed by the next prepareTurn and takes priority there (Req 13.8).
        // The air-conditioner reply is immediate (this turn) and is never
        // carried forward, so it does not touch pendingResidentLine.
        if ($refusalLine !== null) {
            $this->pendingResidentLine = $refusalLine;
        }

        // Count down each robot's support timer (task 13 set it to 2 on a
        // support action) and move the resident when a countdown reaches 0
        // (Req 7.15).
        $this->advanceSupportCountdowns();

        // Set the four per-turn SituationView flags on the world so the next
        // situation text shows the earthquake and resident-speech cues
        // (added 2026-11-07). Done before clearing cover flags; it reads only
        // the offset and the resident line.
        $this->setSituationFlags($offset, $residentLine);

        // 7. Clear the turn-scoped cover flags on both robots and the resident.
        $this->clearCoverFlags();

        // 8. Check for episode end. Full logic is task 19; here it only sets
        //    episodeEnded in a way task 19 can fill in without changing the
        //    call site.
        $this->checkEpisodeEnd();

        // 9. Advance the turn clock.
        $this->world->currentTurn++;

        // 10. Build, store and return the TurnResult for this turn. The same
        //     post-turn snapshot feeds both the TurnResult and the TurnRecord.
        $snapshot = $this->world->snapshot();

        $result = new TurnResult(
            turnNumber: $turnNumber,
            judgements: $judgements,
            outcomes: $outcomes,
            residentLine: $residentLine,
            worldSnapshot: $snapshot,
            residentState: clone $this->world->residentState,
            episodeEnded: $this->world->episodeEnded,
        );

        $this->turnHistory[$turnNumber] = $result;

        // Append this turn's TurnRecord for the situation-text history (§i).
        // robotActions is keyed by robot id; $allActions is a list, so build an
        // id-keyed map from it. roomsAtStart was captured at the top of the
        // turn before any move.
        $robotActions = [];
        foreach ($allActions as $action) {
            $robotActions[$action->robotId] = $action;
        }

        $this->turnRecords[] = new TurnRecord(
            turnNumber: $turnNumber,
            robotActions: $robotActions,
            judgements: $judgements,
            outcomes: $outcomes,
            residentLine: $residentLine,
            snapshot: $snapshot,
            roomsAtStart: $roomsAtStart,
        );

        return $result;
    }

    /**
     * Capture the stove flame state the first turn the earthquake is active.
     *
     * The observer layer sets `earthquakeStartTurn`; the Engine notices the
     * null → set transition and calls EarthquakePhase::captureStoveWasOn once,
     * so the delayed offset-6 hazard only fires if the flame was lit when the
     * quake began (Req 3.5). Tracking the last-seen value keeps the capture
     * idempotent across repeated calls within the same episode.
     */
    private function captureStoveOnEarthquakeStart(): void
    {
        $start = $this->world->earthquakeStartTurn;
        if ($start !== null && $start !== $this->lastSeenEarthquakeStartTurn) {
            $this->earthquake->captureStoveWasOn($this->world);
            $this->lastSeenEarthquakeStartTurn = $start;
        }
    }

    /**
     * Return this turn's actions in the fixed robot_a → robot_b order, dropping
     * any robot that submitted no action (design §d step 3). The resulting list
     * is what the evaluator receives as `$allActions` for the R3 and
     * priority-conflict checks.
     *
     * @param array<string,Action> $actions actions keyed by robot id
     * @return list<Action>
     */
    private function orderedActions(array $actions): array
    {
        $ordered = [];
        foreach ([self::ROBOT_A, self::ROBOT_B] as $robotId) {
            if (isset($actions[$robotId]) && $actions[$robotId] instanceof Action) {
                $ordered[] = $actions[$robotId];
            }
        }

        return $ordered;
    }

    /**
     * Evaluate every action in robot_a → robot_b order and return the
     * judgements keyed by robot id (design §d step 3).
     *
     * @param list<Action> $allActions
     * @return array<string,Judgement>
     */
    private function evaluateActions(array $allActions): array
    {
        $judgements = [];
        foreach ($allActions as $action) {
            $judgements[$action->robotId] = $this->evaluator->evaluate(
                $action,
                $this->world,
                $this->config,
                $allActions,
            );
        }

        return $judgements;
    }

    /**
     * Execute the possible actions in this turn's priority order and return the
     * outcomes keyed by robot id (design §d step 4).
     *
     * Priority: the robot whose slot index satisfies `turnNumber % 2 == slot`
     * acts first (slot 0 = robot_a, slot 1 = robot_b), so robot_a leads on even
     * turns and robot_b on odd turns. An impossible judgement still produces a
     * failure Outcome (via the executor) so the record carries a result for
     * every robot that acted.
     *
     * After each execution, a `use` that turned the gas stove flame off clears
     * the armed delayed hazard so the offset-6 stove hazard only fires while
     * the flame is still on (Req 3.5).
     *
     * @param list<Action>            $allActions
     * @param array<string,Judgement> $judgements
     * @return array<string,Outcome>
     */
    private function executeActions(int $turnNumber, array $allActions, array $judgements): array
    {
        $order = ($turnNumber % 2 === 0)
            ? [self::ROBOT_A, self::ROBOT_B]
            : [self::ROBOT_B, self::ROBOT_A];

        // Index actions by robot id so the priority order can pick them out.
        $byRobot = [];
        foreach ($allActions as $action) {
            $byRobot[$action->robotId] = $action;
        }

        $outcomes = [];
        foreach ($order as $robotId) {
            if (!isset($byRobot[$robotId])) {
                continue;
            }

            $action = $byRobot[$robotId];
            $judgement = $judgements[$robotId];

            $outcome = $this->executor->execute(
                $action,
                $judgement,
                $this->world,
                $this->config,
            );
            $outcomes[$robotId] = $outcome;

            // A successful `use` that extinguished the stove flame disarms the
            // delayed hazard (Req 3.5). Checked after execution so the flame is
            // already off in the world.
            if ($outcome->success && $this->turnedStoveOff($action)) {
                $this->earthquake->clearStoveWasOn();
            }
        }

        return $outcomes;
    }

    /**
     * Whether this action is a `use` that targeted the gas stove and left its
     * flame off. The ActionExecutor turns the flame off for a `use` on the gas
     * stove, so a successful such action means the hazard should be disarmed.
     */
    private function turnedStoveOff(Action $action): bool
    {
        if ($action->operation !== Operation::use->value) {
            return false;
        }

        if ($action->targetId !== self::GAS_STOVE_ID) {
            return false;
        }

        $stove = $this->world->objects[self::GAS_STOVE_ID] ?? null;

        return $stove !== null && $stove->on === false;
    }

    /**
     * Assemble the per-turn TurnActions the ResidentController reads (design
     * §d step 5). A robot "spoke" this turn when its action carried one or more
     * words; the room it spoke in is its current room. The resident was covered
     * when any successful cover action targeted the resident.
     *
     * @param list<Action>          $allActions
     * @param array<string,Outcome> $outcomes
     */
    private function buildTurnActions(array $allActions, array $outcomes): TurnActions
    {
        $speakingRobots = [];
        $speakRooms = [];
        $residentCovered = false;

        foreach ($allActions as $action) {
            if ($action->words !== []) {
                $speakingRobots[] = $action->robotId;
                $speakRooms[$action->robotId] = $this->world->robotStates[$action->robotId]->room;
            }

            $outcome = $outcomes[$action->robotId] ?? null;
            if ($action->operation === Operation::cover->value
                && $action->targetId === self::RESIDENT_ID
                && $outcome !== null
                && $outcome->success
            ) {
                $residentCovered = true;
            }
        }

        return new TurnActions(
            speakingRobots: $speakingRobots,
            speakRooms: $speakRooms,
            residentCovered: $residentCovered,
        );
    }

    /**
     * Resolve every support proposal that was executed this turn (Req 7.15).
     * The resident decides on the turn of the support action itself.
     *
     * For each robot whose successful `support` this turn started a fresh
     * countdown, the Engine builds the proposal tokens from the support action
     * and both robots' spoken lines (Req 7.14), then asks
     * ResidentController::evaluateRefusal(). A refusal ends the support
     * immediately (the countdown and stored destination are cleared, so no move
     * happens later) and claims this turn's resident line with
     * "I don't want to move from here." — in place of any other scripted line
     * (Req 7.9, Req 7.15). An acceptance leaves the countdown running so the
     * move is carried out when it reaches 0.
     *
     * Returns the refusal line when at least one proposal was refused, else
     * null. At most one line is spoken per turn (Req 7.7), so the first refusal
     * found provides it; any other refused support is still ended.
     *
     * @param list<Action>          $allActions this turn's actions
     * @param array<string,Outcome> $outcomes   keyed by robot id
     */
    private function resolveSupportProposals(array $allActions, array $outcomes): ?string
    {
        $refusalLine = null;

        foreach ($allActions as $action) {
            if ($action->operation !== Operation::support->value) {
                continue;
            }

            $outcome = $outcomes[$action->robotId] ?? null;
            if ($outcome === null || !$outcome->success) {
                continue;
            }

            $robot = $this->world->robotStates[$action->robotId] ?? null;
            // A freshly started support has its countdown at the full duration;
            // decide it now, on the turn it was proposed.
            if ($robot === null || $robot->supportTurnsRemaining <= 0) {
                continue;
            }

            $proposal = $this->buildSupportProposal($allActions, $robot->supportDestination);

            if ($this->resident->evaluateRefusal($proposal, $this->world, $this->config)) {
                // Refused: end the support so no move happens later.
                $robot->supportTurnsRemaining = 0;
                $robot->supportDestination = null;
                $refusalLine ??= $this->resident->refusalLine();
            }
        }

        return $refusalLine;
    }

    /**
     * Resolve the resident's air-conditioner will for this turn and return the
     * line he speaks about the air conditioner, or null when he says nothing
     * about it (heat-wave Requirement 5). Called only in a heat-wave turn,
     * after HeatWavePhase and applyHeatToResident have settled this turn's room
     * temperature and the weakened flag.
     *
     * The method gathers this turn's facts, asks ResidentController for the
     * immediate reply (Req 5.1–5.4), falls back to the cooldown-governed repeat
     * (Req 5.6) when no immediate reply fired, latches acceptance so the
     * resident never objects again once he accepts (Req 5.1), and finally
     * advances the AC_Alternative_Prepared latch from this turn so a LATER turn
     * can count it (Req 5.8). It never turns the air conditioner off (Req 5.5).
     *
     * The alternative-prepared latch is read for the acceptance test BEFORE it
     * is advanced, so "on a turn before the current turn" is honoured: this
     * turn's own fan/water never counts toward this turn's acceptance.
     *
     * @param list<Action>          $allActions this turn's actions
     * @param array<string,Outcome> $outcomes   keyed by robot id
     * @return string|null the air-conditioner line to speak this turn, or null
     */
    private function resolveAirConditioner(
        int $turnNumber,
        array $allActions,
        array $outcomes,
    ): ?string {
        $resident = $this->world->residentState;

        // The alternative-prepared latch as it stood at the START of this turn:
        // only an earlier turn's fan/water counts for this turn's acceptance
        // (Req 5.8). Advanced from this turn's facts at the end of the method.
        $alternativePreparedBefore = $resident->acAlternativePrepared;
        $acceptedBefore = $resident->acAccepted;

        // Every spoken line of either robot this turn, used for the AC mention
        // and the AC_Danger_Reason detection (Req 5.1, 5.2, 5.7).
        $spokenLines = $this->spokenLinesThisTurn($allActions);

        $acMentioned = $this->resident->acMentioned($spokenLines);
        $dangerReason = $this->resident->detectAcDangerReason($spokenLines);
        $acUseTurnedItOn = $this->acUseTurnedItOnThisTurn($allActions, $outcomes);

        // The immediate reply (accept / object), uncooled and at acLinePriority.
        $line = $this->resident->airConditionerReply(
            $this->world,
            $this->config,
            $acUseTurnedItOn,
            $acMentioned,
            $dangerReason,
            $alternativePreparedBefore,
            $acceptedBefore,
            $turnNumber,
        );

        // Latch acceptance the turn it happens so he never objects again
        // (Req 5.1). Acceptance is the one line that is the accept text.
        if ($line === $this->resident->acLineTextAccept()) {
            $resident->acAccepted = true;
        }

        // When no immediate reply fired, the air conditioner may still draw the
        // cooldown-governed repeat while it stays on and he has not accepted
        // (Req 5.6). The repeat records its own delivery turn for the cooldown.
        if ($line === null) {
            $line = $this->resident->airConditionerRepeat(
                $this->world,
                $this->config,
                $turnNumber,
            );
        }

        // Advance the AC_Alternative_Prepared latch from THIS turn's facts so a
        // later turn can count it (Req 5.8): the fan on in the resident's room,
        // or the resident handed water this turn. The latch never flips back.
        if ($this->fanOnInResidentRoom() || $this->residentHandedWaterThisTurn($allActions, $outcomes)) {
            $resident->acAlternativePrepared = true;
        }

        return $line;
    }

    /**
     * Every spoken line of either robot this turn, in robot_a → robot_b order,
     * for the air-conditioner detection (Req 5.1, 5.2, 5.7). A robot's spoken
     * lines are the `words` carried on its action.
     *
     * @param list<Action> $allActions this turn's actions
     * @return list<string>
     */
    private function spokenLinesThisTurn(array $allActions): array
    {
        $lines = [];
        foreach ($allActions as $action) {
            foreach ($action->words as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Whether a `use` action turned the air conditioner on this turn (Req 5.3):
     * a successful `use` targeting the air conditioner this turn, with the air
     * conditioner now on. A `use` that failed (e.g. outside the living room,
     * Req 4.2) does not count, and neither does a spoken mention.
     *
     * @param list<Action>          $allActions this turn's actions
     * @param array<string,Outcome> $outcomes   keyed by robot id
     */
    private function acUseTurnedItOnThisTurn(array $allActions, array $outcomes): bool
    {
        foreach ($allActions as $action) {
            if ($action->operation !== Operation::use->value) {
                continue;
            }
            if ($action->targetId !== self::AIR_CONDITIONER_ID) {
                continue;
            }

            $outcome = $outcomes[$action->robotId] ?? null;
            if ($outcome !== null && $outcome->success && $this->world->acOn()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the electric fan is on in the resident's own room right now, one
     * half of the AC_Alternative_Prepared condition (Req 5.8).
     */
    private function fanOnInResidentRoom(): bool
    {
        return $this->world->fanOn()
            && $this->world->fanRoom() === $this->world->residentState->room;
    }

    /**
     * Whether the resident was handed water this turn, the other half of the
     * AC_Alternative_Prepared condition (Req 5.8): a successful `hand_over`
     * targeting the resident this turn whose held object was a water container.
     * The handed object is parked on the resident by executeHandOver (its room
     * becomes the resident id), so a water container now sitting on the
     * resident is the mark of a hand-over this turn.
     *
     * @param list<Action>          $allActions this turn's actions
     * @param array<string,Outcome> $outcomes   keyed by robot id
     */
    private function residentHandedWaterThisTurn(array $allActions, array $outcomes): bool
    {
        foreach ($allActions as $action) {
            if ($action->operation !== Operation::hand_over->value) {
                continue;
            }
            if ($action->targetId !== self::RESIDENT_ID) {
                continue;
            }

            $outcome = $outcomes[$action->robotId] ?? null;
            if ($outcome === null || !$outcome->success) {
                continue;
            }

            // The handed object is now parked on the resident; a water
            // container there means water was handed over this turn.
            foreach ($this->world->objects as $object) {
                if ($object->room === self::RESIDENT_ID && $object->isWaterContainer) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Build the proposal token string that ResidentController::evaluateRefusal()
     * reads, from a support action and the turn's spoken lines (Req 7.14).
     *
     * A support action is itself the move proposal (Req 7.15), so `move_resident`
     * and `support` are always present. The `destination` token is added only
     * when a spoken line of either robot this turn names the destination,
     * case-insensitively: "table" for `under_table`, "futon" for `beside_futon`,
     * or the destination room's display name for an adjacent room.
     *
     * @param list<Action> $allActions this turn's actions
     * @param string|null  $destination the support's targetSpot destination
     */
    private function buildSupportProposal(array $allActions, ?string $destination): string
    {
        $tokens = ['move_resident', 'support'];

        if ($destination !== null && $this->destinationNamed($allActions, $destination)) {
            $tokens[] = 'destination';
        }

        return implode(' ', $tokens);
    }

    /**
     * Whether any spoken line of either robot this turn names the support
     * destination as the move's purpose, case-insensitively (Req 7.14). The
     * word looked for is "table" for `under_table`, "futon" for `beside_futon`,
     * or the destination room's display name (e.g. "kitchen", "living room")
     * for an adjacent room key.
     *
     * @param list<Action> $allActions this turn's actions
     */
    private function destinationNamed(array $allActions, string $destination): bool
    {
        $needle = $this->destinationWord($destination);
        if ($needle === null) {
            return false;
        }

        $needle = mb_strtolower($needle);

        foreach ($allActions as $action) {
            foreach ($action->words as $line) {
                if (str_contains(mb_strtolower($line), $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The case-insensitive word a spoken line must contain to name the given
     * destination (Req 7.14): "table" for the under-table spot, "futon" for the
     * beside-futon spot, or the room's display name for an adjacent room key.
     * Returns null for an unrecognised destination.
     */
    private function destinationWord(string $destination): ?string
    {
        if ($destination === self::SPOT_UNDER_TABLE) {
            return 'table';
        }

        if ($destination === self::SPOT_BESIDE_FUTON) {
            return 'futon';
        }

        if (array_key_exists($destination, $this->world->rooms)) {
            return $this->world->getRoom($destination)->displayName;
        }

        return null;
    }

    /**
     * Count down each robot's `support` timer by one turn (set to 2 by a
     * support action in task 13) and carry out the resident move when a
     * countdown reaches 0 (Req 7.15).
     *
     * A refused proposal has already been ended in resolveSupportProposals()
     * (its countdown reset to 0), so only accepted supports reach 0 here. When
     * one does, the stored destination relocates the resident:
     *   - an in-room spot (`under_table`, `beside_futon`) sets
     *     ResidentState.spot and keeps his room;
     *   - an adjacent room key sets ResidentState.room, clears the spot, and
     *     sets his activity to `standing` ("on his feet, leaning on his cane").
     * The destination has already been validated by the RulesEvaluator on the
     * support turn (Req 7.13).
     */
    private function advanceSupportCountdowns(): void
    {
        foreach ($this->world->robotStates as $robot) {
            if ($robot->supportTurnsRemaining <= 0) {
                continue;
            }

            $robot->supportTurnsRemaining--;

            if ($robot->supportTurnsRemaining === 0) {
                $this->moveResidentTo($robot->supportDestination);
                $robot->supportDestination = null;
            }
        }
    }

    /**
     * Relocate the resident to the accepted support destination (Req 7.15). An
     * in-room spot sets the resident's spot and leaves his room; an adjacent
     * room key moves him to that room, clears the spot, and stands him up.
     * A null destination (should not occur for an accepted support) is ignored.
     */
    private function moveResidentTo(?string $destination): void
    {
        if ($destination === null) {
            return;
        }

        $resident = $this->world->residentState;

        if ($destination === self::SPOT_UNDER_TABLE || $destination === self::SPOT_BESIDE_FUTON) {
            // An in-room spot keeps his room and records the spot.
            $resident->spot = $destination;

            return;
        }

        // An adjacent room: move him, drop the spot, and stand him up.
        $resident->room = $destination;
        $resident->spot = null;
        $resident->activity = ManActivity::standing->value;
    }

    /**
     * Set the four per-turn SituationView flags on the world (design §i;
     * added 2026-11-07). These are read by SituationTextGenerator to open the
     * shaking paragraph correctly and to voice the resident:
     *   - wasShakingPreviousTurn: the previous earthquake offset was a shaking
     *     offset, so a continuing quake reads "The floor is still shaking hard."
     *     and the stop turn (offset 5, 13) reads "The shaking has stopped.";
     *   - didLightsGoOut: this turn is the power-outage offset (2), so the
     *     paragraph appends "The lights go out.";
     *   - residentSpeaks / residentLine: a scripted line was emitted this turn.
     *
     * When no earthquake is active the shaking flags are false.
     */
    private function setSituationFlags(?int $offset, ?string $residentLine): void
    {
        $this->world->turnWasShakingPreviousTurn = $offset !== null
            && in_array($offset - 1, self::SHAKING_OFFSETS, true);

        $this->world->turnDidLightsGoOut = $offset === self::POWER_OUTAGE_OFFSET
            && $this->world->houseState->power === PowerState::outage;

        $this->world->turnResidentSpeaks = $residentLine !== null;
        $this->world->turnResidentLine = $residentLine;
    }

    /**
     * Clear the turn-scoped cover flags on both robots and the resident
     * (design §d step 7). They are set by cover actions during this turn and
     * must not leak into the next.
     */
    private function clearCoverFlags(): void
    {
        foreach ($this->world->robotStates as $robot) {
            $robot->coverFlag = false;
        }
        $this->world->residentState->coverFlag = false;
    }

    /**
     * Decide whether the episode has ended (design §d step 8, Req 10.6).
     *
     * Called once per turn after the resident state is updated (step 5) and the
     * line is emitted (step 6), but before the turn clock is advanced (step 9),
     * so `$world->currentTurn` still holds the index of the turn just
     * processed. Two independent end conditions are honoured:
     *
     *   1. Turn cap. The episode lasts at most `$config->maxTurns` turns. Turn
     *      numbers are 0-indexed and this runs before the clock increment, so
     *      the turn whose completion makes the count reach `maxTurns` is index
     *      `maxTurns - 1`: once `currentTurn + 1 >= maxTurns` the cap is hit.
     *
     *   2. Stability after the disaster subsides. Every turn the resident is
     *      checked against the four stability conditions (Req 10.6):
     *        - fear     <  $config->fearStableThreshold
     *        - water    >  $config->waterStableThreshold
     *        - bodyTemp <  $config->tempStableThreshold
     *        - injury   <= the injury recorded on the immediately preceding turn
     *      A run of `$config->stableRunLength` consecutive stable turns, while
     *      `disasterSubsided` is true, ends the episode. The run counter resets
     *      the instant any condition fails, so the run must be unbroken; the
     *      injury comparison on the first checked turn (no preceding turn) is
     *      treated as satisfied.
     *
     * The stability run is tracked every turn regardless of `disasterSubsided`
     * so that, on the turn the disaster subsides, an already-steady resident is
     * credited for the turns that led up to it rather than starting from zero.
     * Only the episode-ending decision is gated on `disasterSubsided`.
     *
     * Once set, `episodeEnded` is never cleared: a flag raised here or by
     * another layer stays raised. All thresholds and run lengths come from
     * `EpisodeConfig`; no numeric literal for them appears here.
     */
    private function checkEpisodeEnd(): void
    {
        $this->updateStableRun();

        if ($this->world->episodeEnded) {
            return;
        }

        // Condition 1: the turn cap. currentTurn is still this turn's index.
        if ($this->world->currentTurn + 1 >= $this->config->maxTurns) {
            $this->world->episodeEnded = true;
            return;
        }

        // Condition 2: a stable run once the disaster has subsided.
        if ($this->world->disasterSubsided
            && $this->stableRunCount >= $this->config->stableRunLength
        ) {
            $this->world->episodeEnded = true;
        }
    }

    /**
     * Update the running count of consecutive stable turns from the resident's
     * current state (Req 10.6). Extends the run by one when all four stability
     * conditions hold, otherwise resets it to zero. Records this turn's injury
     * as the baseline for the next turn's injury comparison.
     */
    private function updateStableRun(): void
    {
        $resident = $this->world->residentState;

        $injuryNotWorse = $this->previousInjury === null
            || $resident->injury <= $this->previousInjury;

        $stable = $resident->fear < $this->config->fearStableThreshold
            && $resident->water > $this->config->waterStableThreshold
            && $resident->bodyTemp < $this->config->tempStableThreshold
            && $injuryNotWorse;

        $this->stableRunCount = $stable ? $this->stableRunCount + 1 : 0;

        $this->previousInjury = $resident->injury;
    }

    /**
     * Build an error TurnResult (Req 10.5) without mutating world state. The
     * snapshot and resident state reflect the world as it stands so the caller
     * still receives a complete, inspectable result.
     */
    private function errorResult(int $turnNumber, string $message): TurnResult
    {
        return new TurnResult(
            turnNumber: $turnNumber,
            judgements: [],
            outcomes: [],
            residentLine: null,
            worldSnapshot: $this->world->snapshot(),
            residentState: clone $this->world->residentState,
            episodeEnded: $this->world->episodeEnded,
            isError: true,
            errorMessage: $message,
        );
    }
}
