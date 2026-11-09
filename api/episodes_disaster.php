<?php

declare(strict_types=1);

/**
 * POST /api/episodes/{id}/disaster — flip a disaster switch (Requirements
 * 4.4-4.6, 9.1; heat-wave Requirement 8.2).
 *
 * Thin handler: it takes the parsed episode id and the optional `disaster`
 * query value, calls TurnService::flipDisaster once, and writes the JSON
 * episode view with Cache-Control: no-store. The flip is idempotent for both
 * disasters; an unknown id (404) or an ended episode (409) is raised by
 * TurnService and mapped by the router. No rules logic lives here.
 */

use PoltergeistSwitch\TurnLoop\TurnService;

function ps_handle_disaster(
    TurnService $service,
    string $episodeId,
    string $disaster = 'earthquake',
): PsApiResponse {
    // $disaster selects the switch (heat-wave Requirement 8.2): 'heat_wave'
    // fixes the heat idempotently (converting a waiting turn-0 default episode
    // in place), anything else is the unchanged earthquake flip. Both are
    // idempotent and never return a 409 on a repeated flip of a live episode.
    $view = $service->flipDisaster($episodeId, $disaster);

    return ps_json_response(200, ps_episode_view_to_array($view));
}
