<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine;

use PoltergeistSwitch\Engine\Enums\LightLevel;
use PoltergeistSwitch\Engine\Enums\PowerState;
use PoltergeistSwitch\Engine\Values\TurnActions;

/**
 * Drives the scripted elderly resident's mutable state each turn. The
 * controller owns no persistent state of its own: it reads the live
 * WorldModel and the per-turn TurnActions, applies the configured deltas in a
 * fixed order, clamps every field to its range, and refreshes the weakened
 * flag. Every numeric constant comes from EpisodeConfig — no literal appears
 * in this class (Req 6.1–6.11).
 *
 * Task 15.1 implements updateState only. Task 16.1 adds emitDialogueLine;
 * task 17.1 adds evaluateRefusal.
 *
 * Requirements: Req 6.1–6.11, Req 7.1–7.12
 */
class ResidentController
{
    /**
     * The resident's spot while crouching under the low table. Like being
     * covered, it zeroes the falling-object injury increment (Req 7.16).
     */
    private const SPOT_UNDER_TABLE = 'under_table';

    /**
     * The line key under which the cooldown-governed air-conditioner repeat
     * records its delivery turn (heat-wave Requirement 5.6). It is distinct
     * from the scripted line keys so the repeat has its own cooldown window and
     * never shares a window with a scripted line.
     */
    private const AC_LINE_KEY = 'air_conditioner';

    /**
     * The phrase spellings a spoken line may contain to mention the air
     * conditioner (Req 5.1–5.2). Each is matched case-insensitively as a plain
     * substring on the lowercased line. The whole-word spellings "ac" and "a/c"
     * are handled separately in acMentioned() with word-boundary matching so a
     * substring inside "back", "aircon", "peace" etc. never counts.
     *
     * @var list<string>
     */
    private const AC_MENTION_PHRASES = [
        'air conditioner',
        'air-conditioner',
        'air conditioning',
        'air-conditioning',
        'aircon',
    ];

    /**
     * The heat words of the AC_Danger_Reason rule (heat-wave Requirement 5.7).
     * A heat word alone is never a reason; it counts only together with a harm
     * word in the same line.
     *
     * @var list<string>
     */
    private const HEAT_WORDS = ['heat', 'hot', 'temperature'];

    /**
     * The harm words of the AC_Danger_Reason rule (heat-wave Requirement 5.7),
     * the fixed set approved in the design. A heat word together with any of
     * these in the same line is a reason.
     *
     * @var list<string>
     */
    private const HARM_WORDS = [
        'danger', 'dangerous', 'sick', 'ill', 'faint', 'collapse',
        'health', 'body', 'risk', 'hurt', 'harm',
    ];

    /**
     * Apply one turn's deltas to the resident and re-clamp (design §h).
     *
     * Order (applied sequentially before clamping):
     *   1. Injury from falling objects: if the house is shaking and objects
     *      are falling, and the resident's own room is shaking, and the
     *      resident was not covered this turn, add `injuryDeltaFalling`. The
     *      cover flag (set by a robot's cover action this turn) overrides the
     *      increment to zero (Req 6.3).
     *   2. Fear from shaking: if shaking, add `fearDeltaShaking`.
     *   3. Fear from darkness: if power is out and the resident's room is dark,
     *      add `fearDeltaDark`.
     *   4. Fear reduction from same-room speech: for each robot that spoke in
     *      the resident's room this turn, subtract `fearDeltaSpeakSameRoom`.
     *   5. Fear reduction from other-room speech: for each robot that spoke in
     *      a different room this turn, subtract `fearDeltaSpeakOtherRoom`. Each
     *      robot's speech reduces fear once, at the same-room rate when it
     *      spoke in the resident's room and the other-room rate otherwise.
     *   6. Clamp every field to its configured range.
     *   7. Refresh the weakened flag against `weakenedThreshold`.
     */
    public function updateState(
        WorldModel $world,
        EpisodeConfig $config,
        TurnActions $turnActions,
    ): void {
        $resident = $world->residentState;
        $house = $world->houseState;
        $residentRoomKey = $resident->room;

        // 1. Injury from falling objects. The resident must be in a shaking
        //    house with objects falling, and the hazard applies only in the
        //    resident's own room. A set cover flag (Req 6.3) OR crouching under
        //    the low table (Req 7.16) zeroes the increment.
        $residentRoomShaking = $house->shaking
            && $house->fallingObjects
            && $this->roomHasFallenObject($world, $residentRoomKey);

        $shielded = $resident->coverFlag
            || $resident->spot === self::SPOT_UNDER_TABLE;

        if ($residentRoomShaking && !$shielded) {
            $resident->injury += $config->injuryDeltaFalling;
        }

        // 2. Fear from shaking.
        if ($house->shaking) {
            $resident->fear += $config->fearDeltaShaking;
        }

        // 3. Fear from darkness: power out and the resident's room dark.
        $residentRoom = $world->getRoom($residentRoomKey);
        if ($house->power === PowerState::outage
            && $residentRoom->lightLevel === LightLevel::dark
        ) {
            $resident->fear += $config->fearDeltaDark;
        }

        // 4/5. Fear reduction from speech. Each speaking robot reduces fear
        //      once: at the same-room rate when it spoke in the resident's
        //      room, otherwise at the other-room rate. speakRooms is keyed by
        //      robot id so the same robot can never count twice in one turn.
        foreach ($turnActions->speakingRobots as $robotId) {
            if (!array_key_exists($robotId, $turnActions->speakRooms)) {
                continue;
            }

            if ($turnActions->speakRooms[$robotId] === $residentRoomKey) {
                $resident->fear -= $config->fearDeltaSpeakSameRoom;
            } else {
                $resident->fear -= $config->fearDeltaSpeakOtherRoom;
            }
        }

        // 6. Clamp every field to its configured range.
        $resident->bodyTemp = $this->clampFloat(
            $resident->bodyTemp,
            $config->bodyTempMin,
            $config->bodyTempMax,
        );
        $resident->water = $this->clampInt(
            $resident->water,
            $config->waterMin,
            $config->waterMax,
        );
        $resident->injury = $this->clampInt(
            $resident->injury,
            $config->injuryMin,
            $config->injuryMax,
        );
        $resident->fear = $this->clampInt(
            $resident->fear,
            $config->fearMin,
            $config->fearMax,
        );

        // 7. Refresh the weakened flag: set when body temperature reaches the
        //    threshold, cleared when it drops below (Req 6.10).
        $resident->weakened = $resident->bodyTemp >= $config->weakenedThreshold;
    }

    /**
     * Apply one heat-wave turn's body-temperature and water rules to the
     * resident, then clamp every field and refresh the weakened flag
     * (heat-wave Requirements 3.1–3.7).
     *
     * This runs only in a heat-wave turn, wired in by Engine's heat branch
     * AFTER HeatWavePhase has moved the room temperatures (drift + AC cooling)
     * so the body rule is evaluated on this turn's final room temperature
     * (Req 3.1, 3.4). It touches only body_temp and water; injury and fear are
     * not heat-driven (they keep whatever updateState left them).
     *
     * Rules, read entirely from EpisodeConfig (no literal here, Req 3.8):
     *   - Hot room (resident's room temperature strictly above
     *     `bodyHeatThreshold`): body_temp rises by `bodyTempRisePerTurn` and
     *     water falls by `waterLossPerTurnHot` (Req 3.1). When the electric fan
     *     is on in the resident's own room, the rise is reduced by
     *     `fanBodyRiseReduction`; the reduced rise never goes below zero so the
     *     fan can slow the rise but never cool him by itself (Req 3.2).
     *   - Cool room (at or below `bodyHeatThreshold`): body_temp recovers toward
     *     the resident's starting body temperature by `bodyTempRecoveryPerTurn`,
     *     never dropping below `residentInitBodyTemp` (Req 3.4). Water does not
     *     change on a cool turn (only the hand-over of Req 3.3, applied
     *     elsewhere, raises it).
     *
     * The clamp (Req 3.7) and the weakened set/clear at `weakenedThreshold`
     * (Req 3.5–3.6) run here, LAST, so the weakened flag reflects this turn's
     * heat update exactly as `updateState` step 7 does for the earthquake.
     *
     * The cold-water hand-over that raises water (Req 3.3) is handled in the
     * action layer like any water container, not here.
     */
    public function applyHeatToResident(
        WorldModel $world,
        EpisodeConfig $config,
    ): void {
        $resident = $world->residentState;

        $roomTemp = $world->roomTemperature($resident->room);

        if ($roomTemp > $config->bodyHeatThreshold) {
            // Hot room: body temperature rises and water is lost (Req 3.1).
            $rise = $config->bodyTempRisePerTurn;

            // The electric fan, on in the resident's own room, slows the rise
            // (Req 3.2) but never turns it into cooling.
            if ($world->fanOn() && $world->fanRoom() === $resident->room) {
                $rise -= $config->fanBodyRiseReduction;
                if ($rise < 0.0) {
                    $rise = 0.0;
                }
            }

            $resident->bodyTemp += $rise;
            $resident->water -= $config->waterLossPerTurnHot;
        } else {
            // Cool room: body temperature recovers toward the starting floor
            // and never drops below it (Req 3.4).
            $resident->bodyTemp -= $config->bodyTempRecoveryPerTurn;
            if ($resident->bodyTemp < $config->residentInitBodyTemp) {
                $resident->bodyTemp = $config->residentInitBodyTemp;
            }
        }

        // Clamp every field to its configured range (Req 3.7), matching the
        // ranges updateState uses.
        $resident->bodyTemp = $this->clampFloat(
            $resident->bodyTemp,
            $config->bodyTempMin,
            $config->bodyTempMax,
        );
        $resident->water = $this->clampInt(
            $resident->water,
            $config->waterMin,
            $config->waterMax,
        );
        $resident->injury = $this->clampInt(
            $resident->injury,
            $config->injuryMin,
            $config->injuryMax,
        );
        $resident->fear = $this->clampInt(
            $resident->fear,
            $config->fearMin,
            $config->fearMax,
        );

        // Refresh the weakened flag against the threshold so it reflects this
        // turn's heat update (Req 3.5–3.6), set at/above and cleared below.
        $resident->weakened = $resident->bodyTemp >= $config->weakenedThreshold;
    }

    /**
     * Emit at most one scripted dialogue line for the resident this turn, or
     * null when no line fires (design §h, Req 7.1–7.7).
     *
     * The configured priority order (`$config->linePriority`) is walked in
     * sequence; the first line whose condition holds and whose cooldown has
     * expired is delivered. Returning on the first match guarantees no more
     * than one line per turn (Req 7.7). The delivery turn is recorded in
     * `residentState->lastLineDeliveredOn[$lineKey]` so the cooldown applies on
     * later turns (Req 7.6).
     *
     * Line conditions:
     *   earthquake — fires on the first shaking turn, i.e. offset 1
     *                (`turnNumber == earthquakeStartTurn + 1`); it is the
     *                earthquake-start shout and carries no cooldown (Req 7.1).
     *   fear       — fires when `fear >= fearSpeechThreshold` (Req 7.2–7.3).
     *   water      — fires when `water <= waterSpeechThreshold` (Req 7.4).
     *   darkness   — fires when the resident's room is dark (Req 7.5).
     *
     * Cooldown (Req 7.6): every line except the earthquake line is suppressed
     * when it was delivered within the preceding `$config->lineCooldownTurns`
     * turns; it may fire again once that many turns have passed, or if it has
     * never been delivered.
     *
     * @return string|null the spoken line, or null when nothing fires
     */
    public function emitDialogueLine(
        WorldModel $world,
        EpisodeConfig $config,
        int $turnNumber,
    ): ?string {
        $resident = $world->residentState;

        foreach ($config->linePriority as $lineKey) {
            if (!$this->lineConditionHolds($lineKey, $world, $config, $turnNumber)) {
                continue;
            }

            // The earthquake-start shout ignores the cooldown; every other
            // line must have expired its cooldown to fire again.
            if ($lineKey !== 'earthquake'
                && $this->lineOnCooldown($resident, $config, $lineKey, $turnNumber)
            ) {
                continue;
            }

            $resident->lastLineDeliveredOn[$lineKey] = $turnNumber;

            return $this->lineText($lineKey);
        }

        return null;
    }

    /**
     * Decide whether the resident refuses a robot's proposal to move him this
     * turn (design §h, Req 7.8–7.11). Returns true to refuse, false to accept.
     *
     * The method decides from three inputs:
     *   - the weakened flag, read live from `$world->residentState->weakened`;
     *   - whether a robot executed a `support` action targeting the resident
     *     this turn;
     *   - whether a robot emitted a dialogue event naming the destination room
     *     this turn.
     *
     * The method signature carries only the `$proposal` string (design §h), so
     * the two per-turn facts travel inside it. `$proposal` is a token string
     * assembled by the caller (the Engine, task 18) from the turn's actions and
     * dialogue events. Tokens are separated by any run of whitespace and/or
     * commas, so order does not matter and the empty string is a valid "nothing
     * proposed" value. Recognised tokens:
     *   - `move_resident` — a robot proposed moving the resident this turn;
     *   - `support`       — a robot executed a support action targeting the
     *                       resident this turn;
     *   - `destination`   — a robot emitted a dialogue event naming the
     *                       destination room as the move's purpose this turn.
     * Any other token is ignored.
     *
     * Decision (Req 7.8–7.11):
     *   - No move proposal → nothing to refuse; return false (accept/no-op).
     *   - `weakened=true` overrides everything: accept (return false), Req 7.11.
     *   - `weakened=false`: accept only when BOTH a support action was executed
     *     AND the destination was named this turn (Req 7.10); otherwise refuse
     *     with "I don't want to move from here." (Req 7.8–7.9).
     *
     * Air conditioner refusal (Req 7.12) is NOT implemented here: it belongs to
     * the heat-wave scenario, which is out of scope for this spec's tests. When
     * that scenario is built, the air-conditioner proposal would be recognised
     * here (e.g. an `activate_air_conditioner` token) and would accept only when
     * the resident was told why the heat is dangerous this turn AND an
     * alternative cooling device was prepared on an earlier turn, or when
     * `weakened=true`; otherwise it refuses with "I hate the air conditioner."
     *
     * @param string $proposal token string describing the proposal and the
     *                          turn's supporting facts (see above)
     * @return bool true = refuse the proposal; false = accept it (or no move
     *              proposal was made)
     */
    public function evaluateRefusal(
        string $proposal,
        WorldModel $world,
        EpisodeConfig $config,
    ): bool {
        $tokens = $this->parseProposalTokens($proposal);

        // Nothing to refuse unless a move of the resident was actually proposed.
        if (!in_array('move_resident', $tokens, true)) {
            return false;
        }

        // A weakened resident accepts any move proposal, overriding the support
        // and destination-reason conditions entirely (Req 7.11).
        if ($world->residentState->weakened) {
            return false;
        }

        // Otherwise the resident moves only when a robot both supported him and
        // named where they are taking him this turn (Req 7.10). Missing either
        // fact is a refusal (Req 7.8–7.9).
        $supported        = in_array('support', $tokens, true);
        $destinationNamed = in_array('destination', $tokens, true);

        return !($supported && $destinationNamed);
    }

    /**
     * The fixed English line the resident speaks when he refuses a move
     * proposal (Req 7.9). Kept as a named helper so the Engine (task 18) can
     * surface it as the refusal dialogue event without duplicating the string.
     */
    public function refusalLine(): string
    {
        return "I don't want to move from here.";
    }

    /**
     * Decide the resident's immediate air-conditioner reply for this turn, or
     * null when he says nothing about the air conditioner (heat-wave
     * Requirement 5.1–5.4). The reply, when non-null, is spoken THIS turn,
     * outside the 5-turn line cooldown, at `acLinePriority` above every other
     * resident line that turn — exactly like the move refusal (Req 5.4); the
     * caller (Engine) places it in front of any scripted line.
     *
     * A proposal is any of: a `use` turned the air conditioner on this turn
     * ($acUseTurnedItOnThisTurn), or a spoken line of either robot mentioned
     * the air conditioner this turn ($acMentionedThisTurn). When neither holds
     * there is no proposal, so this returns null and the scripted line (or the
     * cooldown-governed repeat, resolved separately by the caller) stands.
     *
     * The acceptance test (Req 5.1) is met when EITHER an AC_Danger_Reason was
     * spoken this turn AND AC_Alternative_Prepared holds from an earlier turn,
     * OR the resident is weakened. On acceptance the resident says
     * "All right. Just for a while." The caller latches $resident->acAccepted
     * so he never objects again (Req 5.1); once accepted, this returns null so
     * no objection is produced.
     *
     * Before acceptance, the objection is one of two mutually exclusive lines
     * (Req 5.2, 5.3): when a `use` turned the air conditioner on this turn the
     * resident says "I hate the air conditioner. Turn it off."; otherwise (a
     * spoken mention only, with no `use` turning it on) he says
     * "I hate the air conditioner." The on-turn line fires in place of the
     * spoken-only line, never both.
     *
     * The method never turns the air conditioner off and never mutates the
     * world — it only reads the facts the caller passes in and the latched
     * acceptance flag. This keeps the resident from ever switching the air
     * conditioner off himself (Req 5.5). The cooldown-governed repeat of
     * "I hate the air conditioner. Turn it off." while the air conditioner
     * stays on (Req 5.6) is the only air-conditioner line on the cooldown and
     * is resolved by the caller, not here.
     *
     * @param bool $acUseTurnedItOnThisTurn a use action turned the AC on this turn
     * @param bool $acMentionedThisTurn     a spoken line mentioned the AC this turn
     * @param bool $dangerReasonGivenThisTurn an AC_Danger_Reason was spoken this turn
     * @param bool $alternativePreparedBefore AC_Alternative_Prepared holds (earlier turn)
     * @param bool $acAlreadyAccepted         the resident already accepted earlier this episode
     * @param int  $turnNumber                the current turn, recorded on delivery
     * @return string|null the immediate line to speak this turn, or null
     */
    public function airConditionerReply(
        WorldModel $world,
        EpisodeConfig $config,
        bool $acUseTurnedItOnThisTurn,
        bool $acMentionedThisTurn,
        bool $dangerReasonGivenThisTurn,
        bool $alternativePreparedBefore,
        bool $acAlreadyAccepted,
        int $turnNumber,
    ): ?string {
        // No proposal this turn → the resident says nothing about the AC.
        if (!$acUseTurnedItOnThisTurn && !$acMentionedThisTurn) {
            return null;
        }

        // Once he has accepted, he never objects again (Req 5.1); the immediate
        // reply is silent so the scripted line (if any) stands.
        if ($acAlreadyAccepted) {
            return null;
        }

        // Acceptance (Req 5.1): a danger reason this turn plus a prepared
        // alternative from an earlier turn, OR a weakened resident.
        $accepts = ($dangerReasonGivenThisTurn && $alternativePreparedBefore)
            || $world->residentState->weakened;

        if ($accepts) {
            // The immediate reply is uncooled (Req 5.4) — it never checks the
            // cooldown — but it still records its delivery turn so the
            // cooldown-governed repeat (Req 5.6) shares one window with it and
            // never fires one turn too early.
            $world->residentState->lastLineDeliveredOn[self::AC_LINE_KEY] = $turnNumber;

            return $this->acLineText('accept');
        }

        // Before acceptance, the two objection lines are mutually exclusive
        // (Req 5.2, 5.3): a `use` that turned it on this turn draws the
        // turn-it-off line in place of the spoken-only line. Each objection
        // records its delivery turn for the shared AC cooldown window (Req 5.6)
        // while staying uncooled itself (Req 5.4).
        if ($acUseTurnedItOnThisTurn) {
            $world->residentState->lastLineDeliveredOn[self::AC_LINE_KEY] = $turnNumber;

            return $this->acLineText('object_on');
        }

        $world->residentState->lastLineDeliveredOn[self::AC_LINE_KEY] = $turnNumber;

        return $this->acLineText('object_spoken');
    }

    /**
     * The cooldown-governed repeat of "I hate the air conditioner. Turn it
     * off." while the air conditioner stays on after the resident objected and
     * the acceptance conditions are still not met (heat-wave Requirement 5.6).
     * This is the ONLY air-conditioner line governed by the 5-turn cooldown and
     * the one-line-per-turn rule; the immediate replies of Req 5.1–5.3 are
     * uncooled (Req 5.4).
     *
     * Returns the repeat line when it should fire this turn, or null when: the
     * air conditioner is off, the resident has accepted, or the line is still
     * within its cooldown window. On firing, the delivery turn is recorded in
     * `lastLineDeliveredOn` under the air-conditioner key so the cooldown
     * applies on later turns, exactly like the scripted lines.
     *
     * The caller only invokes this when no immediate air-conditioner reply
     * fired this turn, so the two never collide; both carry `acLinePriority`,
     * above every scripted line (Req 5.6, 5.10).
     *
     * @return string|null the repeat line, or null when it does not fire
     */
    public function airConditionerRepeat(
        WorldModel $world,
        EpisodeConfig $config,
        int $turnNumber,
    ): ?string {
        $resident = $world->residentState;

        // Only while the air conditioner is on and he has not accepted (Req 5.6).
        if (!$world->acOn() || $resident->acAccepted) {
            return null;
        }

        // Subject to the 5-turn cooldown (Req 5.6): suppressed when it was last
        // delivered within the preceding `lineCooldownTurns` turns.
        if ($this->lineOnCooldown($resident, $config, self::AC_LINE_KEY, $turnNumber)) {
            return null;
        }

        $resident->lastLineDeliveredOn[self::AC_LINE_KEY] = $turnNumber;

        return $this->acLineText('object_on');
    }

    /**
     * Whether any spoken line of either robot this turn is an AC_Danger_Reason,
     * decided deterministically by whole-word, case-insensitive matching
     * (heat-wave Requirement 5.7). A line counts WHEN it contains the whole
     * word "heatstroke", OR the phrase "heat stroke", OR a word beginning with
     * "dehydrat" (so "dehydrate"/"dehydration" match), OR a heat word ("heat",
     * "hot", "temperature") together in the SAME line with a harm word from the
     * fixed set; a heat word alone is not a reason. Whole-word matching means
     * "heater", "shot", "photo" never match.
     *
     * @param list<string> $spokenLines every spoken line of either robot this turn
     */
    public function detectAcDangerReason(array $spokenLines): bool
    {
        foreach ($spokenLines as $line) {
            if ($this->lineIsDangerReason($line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a spoken line mentions the air conditioner, case-insensitively
     * (heat-wave Requirement 5.1–5.2); this is the spoken half of "a proposal
     * is made". A line counts when, on its lowercased form, it contains ANY of
     * the phrase spellings ("air conditioner", "air-conditioner",
     * "air conditioning", "air-conditioning", "aircon") as a substring, OR the
     * whole word "ac", OR the whole word "a/c". The whole-word spellings match
     * on word boundaries so a substring inside "back", "aircon", "peace" etc.
     * never counts.
     *
     * @param list<string> $spokenLines every spoken line of either robot this turn
     */
    public function acMentioned(array $spokenLines): bool
    {
        foreach ($spokenLines as $line) {
            $lower = mb_strtolower($line);

            foreach (self::AC_MENTION_PHRASES as $phrase) {
                if (str_contains($lower, $phrase)) {
                    return true;
                }
            }

            // The whole word "ac" (word boundaries) so "back"/"peace" etc. do
            // not match; the haystack is already lowercased.
            if ($this->containsWord($lower, 'ac')) {
                return true;
            }

            // The whole word "a/c": the slash is not a word char, so build a
            // boundary-aware check that rejects "aac", "a/cx", "a/co" etc.
            if (preg_match('/(?<![\w\/])a\/c(?![\w\/])/u', $lower) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a single spoken line is an AC_Danger_Reason (Req 5.7). Pure,
     * case-insensitive, whole-word. The heat words, harm words, and the fixed
     * phrases are matched on word boundaries so a substring inside a longer
     * word never counts.
     */
    private function lineIsDangerReason(string $line): bool
    {
        $lower = mb_strtolower($line);

        // Direct terms: "heatstroke", "heat stroke", or a word starting with
        // "dehydrat". Each is a reason on its own.
        if ($this->containsWord($lower, 'heatstroke')
            || $this->containsPhrase($lower, 'heat stroke')
            || $this->containsWordPrefix($lower, 'dehydrat')
        ) {
            return true;
        }

        // A heat word together with a harm word in the same line.
        $hasHeatWord = false;
        foreach (self::HEAT_WORDS as $heatWord) {
            if ($this->containsWord($lower, $heatWord)) {
                $hasHeatWord = true;
                break;
            }
        }

        if (!$hasHeatWord) {
            return false;
        }

        foreach (self::HARM_WORDS as $harmWord) {
            if ($this->containsWord($lower, $harmWord)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the already-lowercased haystack contains the whole word $word,
     * delimited by word boundaries so "heater" never matches "heat" and
     * "shot"/"photo" never match. The word is escaped for the regex.
     */
    private function containsWord(string $haystack, string $word): bool
    {
        return preg_match('/\b' . preg_quote($word, '/') . '\b/u', $haystack) === 1;
    }

    /**
     * Whether the already-lowercased haystack contains a whole word that STARTS
     * with $prefix (a word boundary before the prefix, letters allowed after),
     * so "dehydrat" matches "dehydrate" and "dehydration" but not a substring
     * inside an unrelated word.
     */
    private function containsWordPrefix(string $haystack, string $prefix): bool
    {
        return preg_match('/\b' . preg_quote($prefix, '/') . '\w*/u', $haystack) === 1;
    }

    /**
     * Whether the already-lowercased haystack contains the given two-word
     * phrase on word boundaries, so "heat stroke" matches as a standalone
     * phrase. Any run of whitespace between the words is allowed.
     */
    private function containsPhrase(string $haystack, string $phrase): bool
    {
        $words = preg_split('/\s+/', $phrase) ?: [];
        $escaped = array_map(static fn (string $w): string => preg_quote($w, '/'), $words);
        $pattern = '/\b' . implode('\s+', $escaped) . '\b/u';

        return preg_match($pattern, $haystack) === 1;
    }

    /**
     * The fixed acceptance line "All right. Just for a while." (Req 5.1),
     * exposed so the Engine can recognise an acceptance and latch
     * acAccepted without duplicating the string.
     */
    public function acLineTextAccept(): string
    {
        return $this->acLineText('accept');
    }

    /**
     * The fixed English text for each air-conditioner line (Req 5.1–5.3, 5.6).
     */
    private function acLineText(string $key): string
    {
        return match ($key) {
            'accept'        => 'All right. Just for a while.',
            'object_spoken' => 'I hate the air conditioner.',
            'object_on'     => 'I hate the air conditioner. Turn it off.',
            default         => throw new \InvalidArgumentException(
                "Unknown air-conditioner line key: {$key}"
            ),
        };
    }

    /**
     * Split a proposal token string into a list of tokens. Tokens are
     * separated by any run of whitespace and/or commas; empty pieces are
     * dropped, so the empty string yields an empty list.
     *
     * @return list<string>
     */
    private function parseProposalTokens(string $proposal): array
    {
        $pieces = preg_split('/[\s,]+/', trim($proposal));

        if ($pieces === false) {
            return [];
        }

        return array_values(array_filter($pieces, static fn (string $t): bool => $t !== ''));
    }

    /**
     * Whether the condition for the given line key holds this turn (design §h).
     * Unknown line keys never fire.
     */
    private function lineConditionHolds(
        string $lineKey,
        WorldModel $world,
        EpisodeConfig $config,
        int $turnNumber,
    ): bool {
        $resident = $world->residentState;

        return match ($lineKey) {
            // The shout fires on the first shaking turn — offset 1, i.e.
            // turnNumber == earthquakeStartTurn + 1 — not on the offset-0 turn
            // the robots never read as a shaking turn (Req 7.1).
            'earthquake' => $world->earthquakeStartTurn !== null
                && $turnNumber === $world->earthquakeStartTurn + 1,
            'fear'     => $resident->fear >= $config->fearSpeechThreshold,
            'water'    => $resident->water <= $config->waterSpeechThreshold,
            'darkness' => $world->getRoom($resident->room)->lightLevel === LightLevel::dark,
            default    => false,
        };
    }

    /**
     * Whether the line is still within its cooldown window. A line is on
     * cooldown when it was last delivered within the preceding
     * `$config->lineCooldownTurns` turns (turn T−1 … T−cooldown inclusive); a
     * line never delivered is never on cooldown (Req 7.6).
     */
    private function lineOnCooldown(
        ResidentState $resident,
        EpisodeConfig $config,
        string $lineKey,
        int $turnNumber,
    ): bool {
        if (!array_key_exists($lineKey, $resident->lastLineDeliveredOn)) {
            return false;
        }

        $lastDelivered = $resident->lastLineDeliveredOn[$lineKey];

        return ($turnNumber - $lastDelivered) <= $config->lineCooldownTurns;
    }

    /**
     * The fixed English text for each scripted line key (Req 7.1–7.5).
     */
    private function lineText(string $lineKey): string
    {
        return match ($lineKey) {
            'earthquake' => "What's happening?!",
            'fear'       => "I'm scared...",
            'water'      => 'Water... give me some water.',
            'darkness'   => "I can't see anything.",
            default      => throw new \InvalidArgumentException(
                "Unknown dialogue line key: {$lineKey}"
            ),
        };
    }

    /**
     * Whether any object in the given room has fallen this phase. Falling
     * objects injure the resident only in the room where they fell, so the
     * house-wide `fallingObjects` flag is narrowed to the resident's room by
     * checking for a fallen object there.
     */
    private function roomHasFallenObject(WorldModel $world, string $roomKey): bool
    {
        foreach ($world->objects as $object) {
            if ($object->room === $roomKey && $object->fallen) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clamp an integer field to the inclusive [$min, $max] range.
     */
    private function clampInt(int $value, int $min, int $max): int
    {
        if ($value < $min) {
            return $min;
        }

        if ($value > $max) {
            return $max;
        }

        return $value;
    }

    /**
     * Clamp a float field to the inclusive [$min, $max] range.
     */
    private function clampFloat(float $value, float $min, float $max): float
    {
        if ($value < $min) {
            return $min;
        }

        if ($value > $max) {
            return $max;
        }

        return $value;
    }
}
