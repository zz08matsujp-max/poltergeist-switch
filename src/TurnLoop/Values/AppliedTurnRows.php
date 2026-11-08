<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop\Values;

/**
 * The full set of records for one applied turn, written in a single
 * transaction by EpisodeRepository::storeAppliedTurn (Requirements 6.4, 8.8,
 * 8.10, 13.3).
 *
 * Bundles the per-robot Four_Layers for robot_a and robot_b
 * (AppliedRobotRow[]) together with the episode id, the turn number, and the
 * resident line for that turn. The repository commits only after every record
 * is written (Requirement 8.8); a repeated store creates no duplicates
 * (Requirement 8.10).
 *
 * $worldJson carries the serialised post-apply World_State for this turn
 * (screens spec Requirement 10.1): the repository persists it on the turns row
 * as part of the same applied-turn write. It is null when no world is supplied,
 * in which case the turns row's world_json is left as it was.
 */
readonly class AppliedTurnRows
{
    /**
     * @param array<string, AppliedRobotRow> $robots keyed by robot id ('robot_a', 'robot_b')
     */
    public function __construct(
        public string $episodeId,
        public int $turnNumber,
        public array $robots,                // AppliedRobotRow[] keyed by robot id
        public ?string $residentLine = null, // turns.resident_line for this turn
        public ?string $worldJson = null,    // post-apply World_State for this turn (Req 10.1)
    ) {}
}
