<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine;

/**
 * Read-only view of the world that SituationTextGenerator needs to produce
 * the text sent to one robot on one turn.
 *
 * WorldModel (task 6/7) implements this interface. Because SituationTextGenerator
 * is built (task 4) before WorldModel exists, it depends on this narrow interface
 * rather than the concrete class. The S0–S5 golden tests (tasks 4.2 and 5) supply
 * a lightweight test double that implements the same methods.
 *
 * All methods are pure reads; the generator never mutates world state.
 *
 * Requirements: Req 9.1–9.15
 */
interface SituationView
{
    /** TimeOfDay value: 'morning', 'afternoon', or 'night'. */
    public function timeOfDay(): string;

    /** Room key where the given robot currently is ('robot_a'|'robot_b'). */
    public function robotRoom(string $robotId): string;

    /** Room key where the resident currently is. */
    public function residentRoom(): string;

    /** ManActivity value: the resident's current activity. */
    public function residentActivity(): string;

    /** True when the resident's current room is dark (cannot see the objects). */
    public function isRoomDark(string $roomKey): bool;

    /**
     * Object display phrases visible in the given room, in the fixed room order,
     * e.g. ['a gas stove', 'a refrigerator', ...]. Fixtures are excluded.
     */
    public function visibleObjectPhrases(string $roomKey): array;

    /** True while the floor is shaking this turn. */
    public function isShaking(): bool;

    /** True when a pot of water is boiling on the gas stove. */
    public function isPotBoiling(): bool;

    /**
     * True when the previous turn was also shaking (so the opening sentence is
     * "The floor is still shaking hard." instead of "Suddenly the floor begins
     * to shake hard.").
     */
    public function wasShakingPreviousTurn(): bool;

    /** True on the turn the power changes to an outage ("The lights go out."). */
    public function didLightsGoOutThisTurn(): bool;

    /** True when the resident delivers a line this turn. */
    public function residentSpeaksThisTurn(): bool;

    /** The resident's spoken line this turn (only read when residentSpeaksThisTurn()). */
    public function residentLine(): ?string;
}
