<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine;

use PoltergeistSwitch\Engine\Enums\LightLevel;
use PoltergeistSwitch\Engine\Enums\PowerState;
use PoltergeistSwitch\Engine\Enums\TimeOfDay;

/**
 * Drives the earthquake disaster forward one turn at a time. The phase lasts
 * 13 turn offsets (1–13); `Engine::applyTurn` calls `advanceTurn()` once per
 * turn with the offset `current_turn − earthquake_start_turn`.
 *
 * EarthquakePhase sets the house-wide flags the SituationView reads — still
 * shaking, lights go out, shaking stopped — and owns the single light rule for
 * the whole house. `ActionExecutor` delegates its per-room light recomputation
 * to the shared helper here so one piece of code decides when a room is dark
 * (added 2026-11-07).
 *
 * All behaviour is a pure function of the (seed, offset) pair plus the world
 * state: `selectFallingObjects()` seeds a fresh Randomizer from
 * `crc32("{$seed->value}:fall:{$offset}")`, so the global RNG state is never
 * touched and two runs with the same seed and no robot actions produce the
 * same fall sequence and hazard outcomes every time (Req 3.8).
 *
 * Requirements: Req 3.1–3.8, Req 2.5
 */
class EarthquakePhase
{
    /** Turn offsets on which the house is shaking (Req 3.2). */
    private const SHAKING_OFFSETS = [1, 2, 3, 4, 12];

    /** Offset at which the power fails and stays out (Req 3.3). */
    private const POWER_OUTAGE_OFFSET = 2;

    /** Offset at which the delayed stove/pot hazard lands (Req 3.5). */
    private const DELAYED_HAZARD_OFFSET = 6;

    /** Final offset; the disaster has subsided by now (Req 3.1). */
    private const SUBSIDED_OFFSET = 13;

    /** Rooms whose breakable objects can fall during shaking (Req 3.4). */
    private const FALL_ROOMS = ['kitchen', 'living_room'];

    /** One in this many breakable objects falls on a shaking turn (Req 3.4). */
    private const FALL_ONE_IN = 3;

    /** Object ids touched by the delayed stove hazard (Req 3.5). */
    private const GAS_STOVE_ID = 'gas_stove';
    private const POT_ID = 'pot';
    private const KITCHEN_ROOM = 'kitchen';

    /**
     * Whether the stove flame was lit when the earthquake began. Captured by
     * `captureStoveWasOn()` at the moment `earthquakeStartTurn` is set, and
     * cleared by `clearStoveWasOn()` when any ActionExecutor call turns the
     * flame off before offset 6. Only then is the delayed hazard applied
     * (Req 3.5).
     */
    private bool $stoveWasOn = false;

    /**
     * Record the stove flame state at the start of the earthquake. Called when
     * `earthquakeStartTurn` is set (Req 3.5). If the stove was lit, the delayed
     * hazard is armed until the flame is turned off or offset 6 is reached.
     */
    public function captureStoveWasOn(WorldModel $world): void
    {
        $stove = $world->objects[self::GAS_STOVE_ID] ?? null;
        $this->stoveWasOn = $stove !== null && $stove->on;
    }

    /**
     * Disarm the delayed hazard. Called when the stove flame is turned off by
     * any ActionExecutor call before offset 6 (Req 3.5).
     */
    public function clearStoveWasOn(): void
    {
        $this->stoveWasOn = false;
    }

    /** Whether the delayed stove hazard is still armed (for callers/tests). */
    public function stoveWasOn(): bool
    {
        return $this->stoveWasOn;
    }

    /**
     * Advance the disaster by one turn.
     *
     * The falling-object flag is cleared first so each turn starts clean; the
     * shaking flag is then set from the offset table; offset-specific events
     * (power outage, delayed hazard, subsided flag) are applied; and on a
     * shaking turn the fall selection runs, marking the chosen objects broken
     * and raising `fallingObjects` when at least one fell (Req 3.2–3.5).
     *
     * @param int $turnOffset `current_turn − earthquake_start_turn` (1–13)
     */
    public function advanceTurn(WorldModel $world, Seed $seed, int $turnOffset): void
    {
        // Each turn starts with no falling objects; a shaking turn may set it
        // again below (design §g: both flags cleared at the start of the turn).
        $world->houseState->fallingObjects = false;

        $shaking = in_array($turnOffset, self::SHAKING_OFFSETS, true);
        $world->houseState->shaking = $shaking;

        if ($turnOffset === self::POWER_OUTAGE_OFFSET) {
            // The power fails and never comes back in this spec (Req 3.3).
            $world->houseState->power = PowerState::outage;
            $this->updateLightLevels($world, $world->houseState->timeOfDay);
        }

        if ($turnOffset === self::DELAYED_HAZARD_OFFSET) {
            $this->applyDelayedStoveHazard($world);
        }

        if ($turnOffset === self::SUBSIDED_OFFSET) {
            $world->disasterSubsided = true;
        }

        if ($shaking) {
            $fallen = $this->selectFallingObjects($world, $seed, $turnOffset);
            $world->houseState->fallingObjects = $fallen !== [];
        }
    }

    /**
     * Apply the delayed kitchen hazard if the stove was still lit when this
     * turn began (Req 3.5): the flame goes out, the pot falls, and the kitchen
     * gains the hot-water injury hazard. The gas main is never closed by the
     * earthquake (Req 3.6). A disarmed flag (stove turned off earlier, or never
     * lit) leaves the kitchen untouched.
     */
    private function applyDelayedStoveHazard(WorldModel $world): void
    {
        if (!$this->stoveWasOn) {
            return;
        }

        $stove = $world->objects[self::GAS_STOVE_ID] ?? null;
        if ($stove !== null) {
            $stove->on = false;
        }

        $pot = $world->objects[self::POT_ID] ?? null;
        if ($pot !== null) {
            $pot->fallen = true;
        }

        $world->getRoom(self::KITCHEN_ROOM)->hotWaterInjuryHazard = true;

        // The hazard is spent once applied; it never fires twice.
        $this->stoveWasOn = false;
    }

    /**
     * Choose which breakable objects fall on a shaking turn and break them.
     *
     * Only breakable objects in the kitchen and living room are candidates
     * (Req 3.4). A fresh Randomizer is seeded from
     * `crc32("{$seed->value}:fall:{$offset}")`, so the result depends only on
     * the (seed, offset) pair and the global RNG state is never touched
     * (Req 3.8). Each candidate has a 1-in-3 chance of falling; a fallen object
     * stays in its room and is marked `broken=true`.
     *
     * @return ObjectState[] the objects that fell this turn (possibly empty)
     */
    public function selectFallingObjects(WorldModel $world, Seed $seed, int $offset): array
    {
        $derived = crc32("{$seed->value}:fall:{$offset}");
        $randomizer = new \Random\Randomizer(new \Random\Engine\Mt19937($derived));

        $fallen = [];
        foreach ($world->objects as $object) {
            if (!$object->breakable) {
                continue;
            }
            if (!in_array($object->room, self::FALL_ROOMS, true)) {
                continue;
            }

            // 1-in-3 chance: draw 0..2, a 0 means the object falls.
            if ($randomizer->getInt(0, self::FALL_ONE_IN - 1) === 0) {
                $object->fallen = true;
                $object->broken = true;
                $fallen[] = $object;
            }
        }

        return $fallen;
    }

    /**
     * Recompute every room's light level after the power state changes
     * (Req 2.5). Outside the afternoon, rooms without a lit flashlight or lit
     * candle go dark; in the afternoon daylight keeps every room lit, so the
     * levels are left unchanged.
     *
     * This is the single owner of the light rule for the whole house; it reuses
     * the same per-room helper that `ActionExecutor` calls when a robot lights a
     * flashlight or candle.
     */
    public function updateLightLevels(WorldModel $world, string $timeOfDay): void
    {
        if ($timeOfDay === TimeOfDay::afternoon->value) {
            return;
        }

        foreach ($world->rooms as $room) {
            self::recomputeRoomLightLevel($world, $room->key);
        }
    }

    /**
     * The one light rule, shared by EarthquakePhase and ActionExecutor: a room
     * is dark only while the power is out, the time of day is not afternoon, and
     * no lit flashlight or candle stands in it; otherwise it is lit.
     */
    public static function recomputeRoomLightLevel(WorldModel $world, string $roomKey): void
    {
        $room = $world->getRoom($roomKey);

        $powerOut = $world->houseState->power === PowerState::outage;
        $isAfternoon = $world->houseState->timeOfDay === TimeOfDay::afternoon->value;

        if (!$powerOut || $isAfternoon) {
            $room->lightLevel = LightLevel::normal;

            return;
        }

        $room->lightLevel = self::roomHasLitLight($world, $roomKey)
            ? LightLevel::normal
            : LightLevel::dark;
    }

    /**
     * Whether the given room contains a lit light source (flashlight or candle).
     */
    private static function roomHasLitLight(WorldModel $world, string $roomKey): bool
    {
        foreach ($world->objects as $object) {
            if ($object->room === $roomKey && $object->isLightSource && $object->on) {
                return true;
            }
        }

        return false;
    }
}
