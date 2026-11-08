<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop\Values;

/**
 * One turn as carried in an EpisodeView (Requirement 9.4; design "Response
 * shape (EpisodeView)").
 *
 * An applied turn carries the per-robot Four_Layers for robot_a and robot_b
 * (Requirement 10.2). The $robots map is keyed by robot id ('robot_a',
 * 'robot_b') with RobotFourLayers values.
 *
 * $world is the per-turn recorded World_State (screens spec Requirement 10.4):
 * the parsed World_State array for this turn, so `GET /api/episodes/{id}`
 * returns the stored world per turn as the JSON object the House_Renderer and
 * Act_Out consume (design Components §3). It is the decode of the turn row's
 * stored `world_json`, or null when no world has been recorded for the turn
 * (the one-time backfill is a later task). The stored string round-trips
 * exactly, so the decoded array equals what WorldStateSerializer produced
 * (Requirement 10.6).
 */
readonly class TurnView
{
    /**
     * @param array<string, RobotFourLayers> $robots keyed by robot id ('robot_a', 'robot_b')
     * @param array<string, mixed>|null      $world  parsed World_State for this turn, or null
     */
    public function __construct(
        public int $turnNumber,
        public TurnStateValue $state,
        public array $robots,
        public ?string $residentLine = null,
        public ?array $world = null,
    ) {}
}
