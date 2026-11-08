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
}
