<?php

declare(strict_types=1);

/**
 * GET /api/episodes?ended=1&limit=20 — the ended-episode list for the
 * Replay_Screen (screens spec Requirements 11.9, 11.4, 11.5, 11.6).
 *
 * Thin handler: it reads the raw `limit` query value, calls
 * EpisodeListService::list() once, and writes the JSON list with
 * Cache-Control: no-store. ALL validation lives in src/ — the service rejects
 * a limit that is not an integer 1–100 with an 'limit_out_of_range' ApiError
 * (HTTP 400), which the router's catch maps to the safe JSON body. No rules
 * logic lives here, and the body carries only the four list fields, never a
 * device id, key, credential, path, or provider name (Requirement 11.7).
 *
 * The list route is distinguished from the other episode GETs by the presence
 * of `ended=1` in the query (design "Ended-episode list endpoint"); the router
 * recognises it before the id route and passes the EpisodeListService in.
 */

use PoltergeistSwitch\TurnLoop\EpisodeListService;

/**
 * @param array<string,mixed> $query the request query parameters ($_GET)
 */
function ps_handle_list(EpisodeListService $listService, array $query, ?string $deviceId = null): PsApiResponse
{
    // Pass the raw `limit` value straight through: the service validates it
    // (an integer 1–100, default 20 when absent) and throws on anything else,
    // so the handler stays thin. `ended=1` only selected this route; the
    // service already returns ended episodes only.
    $rawLimit = $query['limit'] ?? null;

    // The Device is the nullable cookie value (never minted here); without it
    // the "yours" group is empty. The Device_Id never appears in the body.
    $list = $listService->list($rawLimit, $deviceId);

    // The list body is {featured: [...], yours: [...]}, each item
    // {id, createdDate, turns, startingSituation[, disaster]}.
    // ps_json_response sets Cache-Control: no-store (Requirement 11.9).
    return ps_json_response(200, $list);
}
