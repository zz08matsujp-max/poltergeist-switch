<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine;

use Throwable;
use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\Enums\PowerState;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\Values\Judgement;
use PoltergeistSwitch\Engine\Values\Outcome;
use PoltergeistSwitch\Engine\Values\TurnActions;
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

    public function __construct(
        private WorldModel $world,
        private EpisodeConfig $config,
        private RulesEvaluator $evaluator,
        private ActionExecutor $executor,
        private EarthquakePhase $earthquake,
        private ResidentController $resident,
        private SituationTextGenerator $textGen,
    ) {}

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
     * Process the current turn (steps 2–10 of design §d). Called only when the
     * idempotency check has confirmed `$turnNumber === currentTurn`.
     *
     * @param array<string,Action> $actions actions keyed by robot id
     */
    private function processCurrentTurn(int $turnNumber, array $actions): TurnResult
    {
        // 2. Advance the earthquake phase if active. The observer layer sets
        //    earthquakeStartTurn on the world; the Engine captures the stove
        //    flame the first turn it sees it set (Req 3.5), then advances the
        //    phase by the current offset every turn until the phase ends.
        $this->captureStoveOnEarthquakeStart();

        $offset = null;
        if ($this->world->earthquakeStartTurn !== null) {
            $offset = $turnNumber - $this->world->earthquakeStartTurn;
            $this->earthquake->advanceTurn($this->world, $this->world->seed, $offset);
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

        // 6. Emit at most one scripted resident line for this turn.
        $residentLine = $this->resident->emitDialogueLine(
            $this->world,
            $this->config,
            $turnNumber,
        );

        // Count down each robot's support timer (task 13 set it to 2 on a
        // support action). The actual resident move when it reaches 0 is out of
        // scope here — see the TODO in advanceSupportCountdowns().
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

        // 10. Build, store and return the TurnResult for this turn.
        $result = new TurnResult(
            turnNumber: $turnNumber,
            judgements: $judgements,
            outcomes: $outcomes,
            residentLine: $residentLine,
            worldSnapshot: $this->world->snapshot(),
            residentState: clone $this->world->residentState,
            episodeEnded: $this->world->episodeEnded,
        );

        $this->turnHistory[$turnNumber] = $result;

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
     * Count down each robot's `support` timer by one turn (set to 2 by a
     * support action in task 13). When a robot's countdown reaches 0 the
     * resident would be moved with that robot — but only the countdown
     * mechanics are prepared here.
     *
     */
    private function advanceSupportCountdowns(): void
    {
        foreach ($this->world->robotStates as $robot) {
            if ($robot->supportTurnsRemaining <= 0) {
                continue;
            }

            $robot->supportTurnsRemaining--;

            if ($robot->supportTurnsRemaining === 0) {
                // TODO (task 25. Resident move via support): when the support
                // countdown reaches 0, move the resident to the destination —
                // but ONLY if ResidentController::evaluateRefusal() accepts
                // (weakened, or support executed AND the destination was named
                // this turn). The destination source is undecided in the spec
                // (a room, or a spot such as "under the table"); the maintainer
                // must settle it before the move is wired up (decision
                // 2026-11-07). Until then the resident is deliberately NOT
                // relocated here; only the countdown is maintained. Only the
                // countdown mechanics and the evaluateRefusal() gating may be
                // prepared in this task; the actual position change is out of
                // scope. Example once decided:
                //   $proposal = 'move_resident support destination';
                //   if (!$this->resident->evaluateRefusal($proposal, $this->world, $this->config)) {
                //       // relocate the resident to the chosen destination
                //   }
                continue;
            }
        }
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
