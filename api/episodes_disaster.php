<?php

declare(strict_types=1);

/**
 * POST /api/episodes/{id}/disaster — flip the earthquake switch (Requirements
 * 4.4-4.6, 9.1).
 *
 * Thin handler: it takes the parsed episode id, calls TurnService::flipEarthquake
 * once, and writes the JSON episode view with Cache-Control: no-store. The flip
 * is idempotent; an unknown id (404) or an ended episode (409) is raised by
 * TurnService and mapped by the router. No rules logic lives here.
 */

use PoltergeistSwitch\TurnLoop\TurnService;

function ps_handle_disaster(TurnService $service, string $episodeId): PsApiResponse
{
    $view = $service->flipEarthquake($episodeId);

    return ps_json_response(200, ps_episode_view_to_array($view));
}
