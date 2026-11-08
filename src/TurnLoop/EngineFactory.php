<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop;

use PoltergeistSwitch\Engine\Engine;
use PoltergeistSwitch\Engine\WorldModel;

/**
 * Every HTTP request starts a fresh PHP process that holds no engine in memory,
 * so TurnService cannot keep a live {@see Engine} between requests. An
 * EngineFactory rebuilds the episode's Engine from stored state on each request
 * (Requirement 13.1).
 */
interface EngineFactory
{
    /**
     * Rebuild an episode's Engine by replaying it from stored state
     * (Requirement 13.1). The returned Engine holds the same world as the
     * Engine that played those turns live (Requirement 13.4).
     */
    public function forEpisode(string $episodeId): Engine;

    /**
     * Rebuild the episode's {@see Engine} together with the exact
     * {@see WorldModel} it wraps, both from one replay (Requirement 13.1).
     *
     * {@see forEpisode} hands back only the Engine, whose world is private, so
     * a caller that advances the Engine with prepareTurn/applyTurn cannot read
     * the resulting world. This method returns the SAME world object the Engine
     * holds, so after the caller applies a turn on the returned Engine the
     * returned WorldModel reflects that turn's post-apply state. TurnService
     * uses it to serialise the post-apply world for the per-turn
     * `world_json` (screens spec Requirement 10.1) without changing the turn
     * flow: the returned Engine is prepared and applied exactly as
     * {@see forEpisode}'s would be.
     *
     * @return array{engine: Engine, world: WorldModel}
     */
    public function forEpisodeWithWorld(string $episodeId): array;

    /**
     * Rebuild the episode's {@see WorldModel} to the start of its current turn
     * by the same replay {@see forEpisode} walks (Requirement 13.1), so apply
     * can build the ids-only {@see \PoltergeistSwitch\TurnLoop\Clients\RecorderInput}
     * for each robot (Requirement 3.4) from the robots' positions and held
     * objects at the start of the turn. The Engine keeps its world private, so
     * the factory hands the world back directly; the room membership and held
     * objects the recorder input needs are set by the replayed actions, not by
     * prepareTurn, so a world replayed to the current turn start is correct.
     */
    public function worldForEpisode(string $episodeId): WorldModel;

    /**
     * Replay the episode ONCE and serialise the {@see WorldModel} after each
     * applied turn, returning the per-turn `world_json` keyed by turn number in
     * ascending order (screens spec Requirements 10.7, 10.8).
     *
     * This walks exactly the replay {@see forEpisode} walks — prepareTurn then
     * applyTurn for every applied turn from the stored actions, with NO AI call
     * — but serialises the engine's own world after each turn so the one-time
     * World_State backfill can fill a pre-existing episode's missing turns from
     * a single replay. Because the same seed reproduces the same world on any
     * PHP 8.2 build, a serialised turn equals what a fresh run would have
     * stored (Requirement 10.6). Turn 0 is the initial calm, pre-earthquake
     * world; each later turn is that turn's post-apply world, matching how
     * {@see \PoltergeistSwitch\TurnLoop\TurnService} stores them live.
     *
     * The replay is a single pass: a caller backfilling several missing turns
     * calls this ONCE, never once per turn (the exactly-once guarantee the
     * backfill relies on). A replay that cannot complete throws, and the caller
     * persists nothing.
     *
     * @param callable(WorldModel):string $serialize serialises one world to its
     *                                                stored `world_json` string
     * @return array<int, string> turnNumber => world_json, ascending by turn
     */
    public function worldsPerTurnForEpisode(string $episodeId, callable $serialize): array;
}
