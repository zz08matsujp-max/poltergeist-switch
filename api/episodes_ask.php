<?php

declare(strict_types=1);

/**
 * POST /api/episodes/{id}/turns/{n}/ask — build each robot's text and collect
 * both replies idempotently (Requirement 5; Requirement 9.1).
 *
 * Thin handler: it takes the parsed episode id and turn number, calls
 * TurnService::ask once, and writes the JSON episode view with
 * Cache-Control: no-store. An unknown id (404) or a turn-number/ended/
 * wrong-state conflict (409) is raised by TurnService and mapped by the router.
 * No rules logic lives here.
 */

use PoltergeistSwitch\TurnLoop\TurnService;

function ps_handle_ask(TurnService $service, string $episodeId, int $turnNumber): PsApiResponse
{
    $view = $service->ask($episodeId, $turnNumber);

    return ps_json_response(200, ps_episode_view_to_array($view));
}
