<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine;

use PoltergeistSwitch\Engine\Enums\Disaster;

/**
 * The extra read-only view SituationTextGenerator needs to produce the
 * heat-wave situation text (heat-wave Requirement 6). It is a *separate*
 * interface from SituationView rather than new methods on SituationView so
 * that the earthquake SituationView test doubles (which implement only
 * SituationView) keep compiling and the earthquake path stays byte-for-byte
 * unchanged.
 *
 * WorldModel implements both interfaces. The generator chooses the heat path
 * only when the view is a HeatSituationView whose disaster() is
 * Disaster::heat_wave; an earthquake view (concrete or test double) never
 * implements this interface, so it always takes the earthquake path.
 *
 * All methods are pure reads; the generator never mutates world state.
 *
 * Requirements: heat-wave Req 6.1–6.8
 */
interface HeatSituationView
{
    /** The disaster this world runs; the generator gates the heat path on it. */
    public function disaster(): Disaster;

    /** Whether the window air conditioner is switched on. */
    public function acOn(): bool;

    /** Whether the electric fan is switched on. */
    public function fanOn(): bool;

    /**
     * The room the electric fan is in, or null when the fan is not present.
     * When a robot holds the fan this is the robot's room (the carry keeps the
     * fan's room in step with its carrier).
     */
    public function fanRoom(): ?string;

    /** Whether the window of the given room is open. */
    public function windowOpen(string $roomKey): bool;

    /**
     * The wall thermometer reading for the given room, the room temperature
     * rounded to a whole °C. The thermometer is a fixture, never listed among
     * a room's objects.
     */
    public function thermometerReading(string $roomKey): int;

    /**
     * The resident's visible heat stage, resolved from his weakened flag and
     * the temperature of his room against the config threshold, one of:
     *   'weakened' — body_temp >= weakenedThreshold
     *   'hot'      — his room temperature is above bodyHeatThreshold
     *   'normal'   — otherwise
     * The stage decision (and its config thresholds) lives in the world so the
     * generator holds no heat number; it only maps the stage to its fact line.
     */
    public function residentHeatStage(): string;
}
