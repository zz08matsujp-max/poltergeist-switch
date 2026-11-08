<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop;

use PoltergeistSwitch\Engine\Engine;

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
}
