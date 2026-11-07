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
 * Task 15.1 implements updateState only. emitDialogueLine (task 16) and
 * evaluateRefusal (task 17) are added later.
 *
 * Requirements: Req 6.1–6.11
 */
class ResidentController
{
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
        //    resident's own room. A set cover flag zeroes the increment.
        $residentRoomShaking = $house->shaking
            && $house->fallingObjects
            && $this->roomHasFallenObject($world, $residentRoomKey);

        if ($residentRoomShaking && !$resident->coverFlag) {
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
