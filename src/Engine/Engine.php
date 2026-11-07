<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine;

use Throwable;
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
     * Build the full situation text sent to one robot for the current turn
     * (design §i, Req 10.1). It reads the live world through SituationView and
     * the last five applied TurnRecords (oldest first), which the generator
     * renders as the recent-events section under the "In the last 15 seconds:"
     * heading. Memories are not supplied here.
     *
     * @param string $robotId 'robot_a' or 'robot_b'
     */
    public function situationTextFor(string $robotId): string
    {
        $recent = array_slice($this->turnRecords, -5);

        return $this->textGen->generate($robotId, $this->world, $recent);
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

        // 5b. Resolve any support proposal made this turn: the resident decides
        //     on the turn of the support action (Req 7.15). A refusal both ends
        //     the support and overrides this turn's scripted line with
        //     "I don't want to move from here." (Req 7.9, Req 7.15).
        $refusalLine = $this->resolveSupportProposals($allActions, $outcomes);

        // 6. Emit at most one scripted resident line for this turn, unless a
        //    support refusal already claimed the line (Req 7.15).
        $residentLine = $refusalLine
            ?? $this->resident->emitDialogueLine(
                $this->world,
                $this->config,
                $turnNumber,
            );

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
