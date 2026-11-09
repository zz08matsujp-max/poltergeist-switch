<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine;

use PoltergeistSwitch\Engine\Enums\ManActivity;
use PoltergeistSwitch\Engine\Enums\TimeOfDay;

/**
 * Immutable value object describing the starting configuration of an episode.
 *
 * All fields are validated on construction. The factory method fromSeed()
 * derives a reproducible SituationSpec from a Seed instance.
 *
 * Requirements: Req 1.1–1.3, Req 8.1–8.9
 */
readonly class SituationSpec
{
    /** @var string[] Valid room keys */
    private const ROOM_KEYS = [
        'living_room',
        'kitchen',
        'bathroom',
        'bedroom',
        'entrance',
        'storeroom',
    ];

    /** @var string[] Room keys excluding bathroom (used when manActivity=bathing) */
    private const ROOM_KEYS_NO_BATHROOM = [
        'living_room',
        'kitchen',
        'bedroom',
        'entrance',
        'storeroom',
    ];

    /**
     * @var string[] The starting activities a seed may draw. The `standing`
     *               ManActivity is a runtime state the resident reaches only
     *               after a robot moves him via support (Req 7.15); it is never
     *               an episode starting pose, so it is excluded here. Keeping
     *               this list fixed also preserves the fromSeed() draw range.
     */
    private const STARTING_ACTIVITIES = [
        'sitting_at_table',
        'asleep_in_futon',
        'bathing',
        'cooking',
    ];

    public string $time;
    public string $robotARoom;
    public string $robotBRoom;
    public string $manActivity;
    public bool   $potBoiling;

    /**
     * @throws \InvalidArgumentException when any field is invalid or the
     *                                   bathroom+bathing constraint is violated
     */
    public function __construct(
        string $time,
        string $robotARoom,
        string $robotBRoom,
        string $manActivity,
        bool   $potBoiling
    ) {
        // Validate time against TimeOfDay enum values
        $validTimes = array_column(TimeOfDay::cases(), 'value');
        if (!in_array($time, $validTimes, true)) {
            throw new \InvalidArgumentException(
                "Invalid time '{$time}'. Must be one of: " . implode(', ', $validTimes)
            );
        }

        // Validate manActivity against ManActivity enum values
        $validActivities = array_column(ManActivity::cases(), 'value');
        if (!in_array($manActivity, $validActivities, true)) {
            throw new \InvalidArgumentException(
                "Invalid manActivity '{$manActivity}'. Must be one of: " . implode(', ', $validActivities)
            );
        }

        // Validate room keys
        if (!in_array($robotARoom, self::ROOM_KEYS, true)) {
            throw new \InvalidArgumentException(
                "Invalid robotARoom '{$robotARoom}'. Must be one of: " . implode(', ', self::ROOM_KEYS)
            );
        }
        if (!in_array($robotBRoom, self::ROOM_KEYS, true)) {
            throw new \InvalidArgumentException(
                "Invalid robotBRoom '{$robotBRoom}'. Must be one of: " . implode(', ', self::ROOM_KEYS)
            );
        }

        // Bathroom + bathing constraint (Req 8.3): neither robot may be in the
        // bathroom when the man is bathing, because three occupants cannot fit.
        if ($manActivity === ManActivity::bathing->value
            && ($robotARoom === 'bathroom' || $robotBRoom === 'bathroom')
        ) {
            throw new \InvalidArgumentException(
                "A robot cannot start in the bathroom when manActivity is 'bathing'."
            );
        }

        $this->time        = $time;
        $this->robotARoom  = $robotARoom;
        $this->robotBRoom  = $robotBRoom;
        $this->manActivity = $manActivity;
        // Cooking always means the pot is boiling (Req 8.2)
        $this->potBoiling  = ($manActivity === ManActivity::cooking->value) ? true : $potBoiling;
    }

    /**
     * Derives a SituationSpec from a Seed by drawing four values in this exact
     * order (so the draw sequence is always the same for any given seed):
     *
     *   1. time index       (0–2)
     *   2. manActivity index (0–3)
     *   3. robotARoom index  (0–5, or 0–4 when manActivity=bathing)
     *   4. robotBRoom index  (0–5, or 0–4 when manActivity=bathing)
     *
     * Requirements: Req 1.2, Req 8.5
     */
    public static function fromSeed(Seed $seed): self
    {
        $times      = array_column(TimeOfDay::cases(), 'value');    // ['morning','afternoon','night']
        $activities = self::STARTING_ACTIVITIES;                    // ['sitting_at_table',…,'cooking']

        $time        = $times[$seed->next(0, count($times) - 1)];
        $manActivity = $activities[$seed->next(0, count($activities) - 1)];

        if ($manActivity === ManActivity::bathing->value) {
            $roomKeys = self::ROOM_KEYS_NO_BATHROOM;
        } else {
            $roomKeys = self::ROOM_KEYS;
        }

        $robotARoom = $roomKeys[$seed->next(0, count($roomKeys) - 1)];
        $robotBRoom = $roomKeys[$seed->next(0, count($roomKeys) - 1)];

        return new self($time, $robotARoom, $robotBRoom, $manActivity, false);
    }

    /**
     * Derive a heat-wave starting SituationSpec from a Seed (heat-wave
     * Requirement 8.1). The heat wave begins in a fixed starting pose: the
     * resident sitting at the low table in the living room in the early
     * afternoon. The seed still varies the robots' rooms (product brief
     * Principle 8), drawn here from the full six-room list in the same way
     * fromSeed() draws them, so two heat-wave episodes with the same seed place
     * the robots identically and a different seed can place them differently.
     *
     * Unlike fromSeed(), the time and the resident's activity are NOT drawn:
     * they are fixed to 'afternoon' and 'sitting_at_table' so every heat-wave
     * episode opens with the man at the low table in the early afternoon. The
     * pot never boils in a heat-wave start.
     *
     * Requirements: heat-wave 8.1
     */
    public static function forHeatWave(Seed $seed): self
    {
        $roomKeys = self::ROOM_KEYS;

        $robotARoom = $roomKeys[$seed->next(0, count($roomKeys) - 1)];
        $robotBRoom = $roomKeys[$seed->next(0, count($roomKeys) - 1)];

        return new self(
            TimeOfDay::afternoon->value,
            $robotARoom,
            $robotBRoom,
            ManActivity::sitting_at_table->value,
            false,
        );
    }
}
