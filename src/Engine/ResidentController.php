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
     *   earthquake — fires when `turnNumber == earthquakeStartTurn`; it is the
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
            'earthquake' => $world->earthquakeStartTurn !== null
                && $turnNumber === $world->earthquakeStartTurn,
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
