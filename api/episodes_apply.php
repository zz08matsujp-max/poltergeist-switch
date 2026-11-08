<?php

declare(strict_types=1);

/**
 * POST /api/episodes/{id}/turns/{n}/apply — read both replies, judge the turn
 * once, and store the Four_Layers (Requirement 6; Requirement 9.1).
 *
 * Thin handler: it takes the parsed episode id and turn number, calls
 * TurnService::apply once, and writes the JSON episode view with
 * Cache-Control: no-store. An unknown id (404), a turn-number/ended/wrong-state
 * conflict (409), or a mid-write rollback failure is raised by TurnService and
 * mapped by the router. No rules logic lives here.
 */

use PoltergeistSwitch\TurnLoop\TurnService;

function ps_handle_apply(TurnService $service, string $episodeId, int $turnNumber): PsApiResponse
{
    $view = $service->apply($episodeId, $turnNumber);

    return ps_json_response(200, ps_episode_view_to_array($view));
}
