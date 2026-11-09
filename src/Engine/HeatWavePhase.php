<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine;

/**
 * Drives the heat-wave disaster forward one turn at a time, the sibling of
 * EarthquakePhase. `Engine::applyTurn` calls `advanceTurn()` once per turn
 * while the episode's disaster is heat wave; an earthquake episode never
 * constructs this phase into its turn path (heat-wave Requirement 1.6), so
 * no earthquake field is ever touched here.
 *
 * The phase owns the per-turn room-temperature rule (heat-wave Requirements
 * 2.2–2.6). It is pure and deterministic: temperature movement is a fixed
 * drift toward the episode's outside temperature and needs no RNG, so two
 * runs of the same heat-wave episode with the same actions produce the same
 * temperatures on any PHP 8.2 build (Requirement 2.8). The power stays on and
 * no outage rule is applied (Requirement 2.5).
 *
 * Every number comes from EpisodeConfig; there is no literal numeric constant
 * in the temperature logic (Requirement 2.7). The resident body/water branch
 * (Requirement 3) and the air-conditioner will (Requirement 5) live in
 * ResidentController and are wired in by later tasks; this task implements the
 * room-temperature rule only.
 *
 * Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.7
 */
class HeatWavePhase
{
    /** The air conditioner cools only the living room (heat-wave Req 2.3, 4.1). */
    private const LIVING_ROOM = 'living_room';

    /**
     * Advance the heat wave by one turn.
     *
     * The per-turn order is fixed (design "Turn order (explicit)"):
     *   1. room drift — every room moves toward the outside temperature by
     *      `roomTempDriftPerTurn`, plus `openWindowDriftBonus` for a room whose
     *      window is open (Req 2.2, 2.4);
     *   2. AC cooling — when the air conditioner is on, the living room is
     *      lowered by `acCoolPerTurn` but never below `acTargetTemp` (Req 2.3);
     *   3. clamp — every room temperature is clamped to
     *      `[roomTempMin, roomTempMax]` (Req 2.6).
     * The power is left on and no outage rule runs (Req 2.5).
     *
     * `$seed` and `$turnNumber` are accepted for symmetry with EarthquakePhase
     * and are unused: the drift is a fixed, RNG-free movement, which is what
     * makes a heat-wave episode reproducible (Req 2.8).
     */
    public function advanceTurn(WorldModel $world, Seed $seed, int $turnNumber): void
    {
        $config = $world->config();

        foreach ($world->rooms as $room) {
            $this->updateRoomTemperature($world, $config, $room->key);
        }
    }

    /**
     * Move one room's temperature toward the outside temperature by this turn's
     * drift, apply the air-conditioner cooling for the living room, then clamp.
     *
     * The drift is directional: a room cooler than outside warms, a room warmer
     * than outside cools, and the step never overshoots the outside temperature
     * (the move is capped at the remaining distance). An open window adds
     * `openWindowDriftBonus` to the step toward outside. The air conditioner,
     * when on, pulls the living room down by `acCoolPerTurn` but never below
     * `acTargetTemp`. The result is clamped to `[roomTempMin, roomTempMax]`.
     */
    private function updateRoomTemperature(
        WorldModel $world,
        EpisodeConfig $config,
        string $roomKey
    ): void {
        $room = $world->getRoom($roomKey);

        $outside = $world->houseState->outsideTemperature;
        $temp = $room->temperature;

        // 1. drift toward the outside temperature (Req 2.2), with the
        //    open-window bonus added to the step (Req 2.4).
        $step = $config->roomTempDriftPerTurn;
        if ($room->windowOpen) {
            $step += $config->openWindowDriftBonus;
        }

        $gap = $outside - $temp;
        if ($gap > 0.0) {
            // The room is cooler than outside: warm it, but not past outside.
            $temp += min($step, $gap);
        } elseif ($gap < 0.0) {
            // The room is warmer than outside: cool it, but not past outside.
            $temp -= min($step, -$gap);
        }

        // 2. the air conditioner cools the living room (Req 2.3), never below
        //    the target temperature.
        if ($roomKey === self::LIVING_ROOM && $world->acOn()) {
            $temp -= $config->acCoolPerTurn;
            if ($temp < $config->acTargetTemp) {
                $temp = $config->acTargetTemp;
            }
        }

        // 3. clamp to the configured range (Req 2.6).
        if ($temp < $config->roomTempMin) {
            $temp = $config->roomTempMin;
        } elseif ($temp > $config->roomTempMax) {
            $temp = $config->roomTempMax;
        }

        $room->temperature = $temp;
    }
}
