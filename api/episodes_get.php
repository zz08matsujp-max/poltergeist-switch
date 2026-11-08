<?php

declare(strict_types=1);

/**
 * GET /api/episodes/{id} — return the episode state and its stored turn records
 * (Requirements 9.4, 9.5, 9.1).
 *
 * Thin handler: it takes the parsed episode id, calls TurnService::getEpisode
 * once, and writes the JSON episode view with Cache-Control: no-store. An
 * unknown id (404) is raised by TurnService and mapped by the router. No rules
 * logic lives here; this is a pure read that never advances the state machine.
 */

use PoltergeistSwitch\TurnLoop\TurnService;

function ps_handle_get(TurnService $service, string $episodeId): PsApiResponse
{
    $view = $service->getEpisode($episodeId);

    return ps_json_response(200, ps_episode_view_to_array($view));
}
