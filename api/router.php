<?php

declare(strict_types=1);

/**
 * Front-controller routing for the api/ layer (design "API Design").
 *
 * This file defines ps_dispatch(), which parses the HTTP method + route and
 * calls ONE TurnService method, then the five thin handlers it dispatches to.
 * It is included by bootstrap.php. The real entry point is api/index.php, which
 * reads the superglobals and hands them to ps_dispatch(); a test can call
 * ps_dispatch() directly with a fake-backed TurnService and injected
 * method/route/cookies/body, capturing the PsApiResponse without a web server.
 *
 * The same five routes are accepted two ways so the API works even if
 * mod_rewrite is unavailable (the .htaccess rewrite is best-effort):
 *   - clean paths:   /api/episodes, /api/episodes/{id}/disaster, etc.
 *   - query string:  index.php?route=episodes, ?route=episodes/{id}/disaster, …
 *
 * Handlers stay thin: each parses the route only (id, turn number), reads the
 * device cookie, calls one TurnService method, and returns ps_json_response().
 * No rules logic lives here. Every response carries Cache-Control: no-store and
 * never a key, credential, path, or provider name (Requirements 9.11, 2.6).
 */

use PoltergeistSwitch\Memory\MemoryService;
use PoltergeistSwitch\Memory\ReflectService;
use PoltergeistSwitch\TurnLoop\EpisodeListService;
use PoltergeistSwitch\TurnLoop\EpisodeStarter;
use PoltergeistSwitch\TurnLoop\IpHasher;
use PoltergeistSwitch\TurnLoop\Http\ApiError;
use PoltergeistSwitch\TurnLoop\Judge\JudgeCookie;
use PoltergeistSwitch\TurnLoop\TurnService;

require_once __DIR__ . '/episodes_create.php';
require_once __DIR__ . '/episodes_disaster.php';
require_once __DIR__ . '/episodes_ask.php';
require_once __DIR__ . '/episodes_apply.php';
require_once __DIR__ . '/episodes_get.php';
require_once __DIR__ . '/episodes_list.php';
require_once __DIR__ . '/episodes_reflect.php';
require_once __DIR__ . '/memory_reset.php';
require_once __DIR__ . '/memory_get.php';

/**
 * Parse the method + route and dispatch to the matching handler, returning the
 * response as a value so a test can inspect it. All errors are mapped to a safe
 * JSON body here, so a handler never has to touch the output globals.
 *
 * @param string               $method      the HTTP method, e.g. 'GET' or 'POST'
 * @param string               $route       the route without a leading slash, e.g.
 *                                           'episodes/abc/turns/2/ask'
 * @param array<string,string> $cookies     the request cookies (for the device id)
 * @param array<string,mixed>  $query       the request query parameters ($_GET);
 *                                           the ended-episode list reads `ended`
 *                                           and `limit` from here
 * @param EpisodeListService|null $listService the service for the ended-episode
 *                                           list route; null when no list
 *                                           service is wired, in which case the
 *                                           list route falls through to 404
 * @param MemoryService|null   $memoryService the service for the memory status
 *                                           and reset routes, and for taking the
 *                                           Memory_Snapshot on create; null when
 *                                           not wired, in which case the two
 *                                           memory routes fall through to 404 and
 *                                           create takes no snapshot
 * @param ReflectService|null  $reflectService the service for the reflect route;
 *                                           null when not wired, in which case
 *                                           the reflect route falls through to
 *                                           404
 * @param EpisodeStarter|null  $starter     the daily-limit-aware episode starter
 *                                           (public-lab Requirement 13.1); null
 *                                           means create is not limited
 * @param string|null          $clientIp    the normalised client IP, or null when
 *                                           it cannot be resolved (IP scope skipped)
 * @param IpHasher|null        $hasher      the salted hasher for usage keys; null
 *                                           means no salt is available
 */
function ps_dispatch(
    TurnService $service,
    string $method,
    string $route,
    array $cookies,
    array $query = [],
    ?EpisodeListService $listService = null,
    ?MemoryService $memoryService = null,
    ?ReflectService $reflectService = null,
    ?EpisodeStarter $starter = null,
    ?string $clientIp = null,
    ?IpHasher $hasher = null,
    ?string $origin = null,
    array $allowedOrigins = [],
    ?JudgeCookie $judge = null,
    string $body = '',
    ?int $now = null,
): PsApiResponse {
    $method = strtoupper($method);
    $route = trim($route, '/');

    // Cross-site guard (public-lab Requirement 19): a POST that carries an
    // Origin header outside the allowed list is refused before routing, the
    // cookie read or any service call. No Origin header (same-origin tools,
    // curl) passes; an empty or missing list refuses every Origin.
    if ($method === 'POST' && $origin !== null && !in_array($origin, $allowedOrigins, true)) {
        return ps_json_response(403, ['error' => 'cross_site']);
    }

    try {
        $segments = $route === '' ? [] : explode('/', $route);

        // Judge pass (public-lab Requirement 21): GET reports the cookie
        // state, POST checks the pass and issues the Judge_Cookie.
        if ($segments === ['judge-pass']) {
            if ($method === 'GET') {
                return ps_handle_judge_pass_get($judge, $cookies, $now ?? time());
            }
            if ($method === 'POST') {
                return ps_handle_judge_pass_post($judge, $body, $now ?? time());
            }
        }

        // GET /api/episodes?ended=1 — the ended-episode list. Recognised by the
        // `ended=1` query on the bare episodes collection, which distinguishes
        // it from any other GET on episodes (design "Ended-episode list
        // endpoint"). All limit validation lives in EpisodeListService.
        if ($method === 'GET'
            && $segments === ['episodes']
            && ($query['ended'] ?? null) === '1'
            && $listService !== null
        ) {
            return ps_handle_list($listService, $query, ps_device_id_from_cookies($cookies));
        }

        // POST /api/episodes — create (takes the Memory_Snapshot when the
        // memory service is wired; robot-memory Requirement 6.1). An optional
        // `disaster` query value (default 'earthquake') lets the Heat wave lever
        // create a heat-wave episode (heat-wave Requirement 8.1); anything other
        // than 'heat_wave' is treated as earthquake by TurnService.
        if ($method === 'POST' && $segments === ['episodes']) {
            $disaster = ps_disaster_from_query($query);

            return ps_handle_create($service, $cookies, $memoryService, $disaster, $starter, $clientIp, $hasher);
        }

        // GET /api/memory — the current generation and per-robot active-memory
        // counts and lengths (robot-memory Requirement 5.1).
        if ($method === 'GET' && $segments === ['memory'] && $memoryService !== null) {
            return ps_handle_memory_get($memoryService, ps_device_id_from_cookies($cookies));
        }

        // POST /api/memory/reset — advance the current generation, delete
        // nothing (robot-memory Requirement 4.5).
        if ($method === 'POST' && $segments === ['memory', 'reset'] && $memoryService !== null) {
            return ps_handle_memory_reset($memoryService, ps_device_id_from_cookies($cookies));
        }

        // Everything else is /episodes/{id}/...
        if (count($segments) >= 2 && $segments[0] === 'episodes') {
            $episodeId = $segments[1];

            // POST /api/episodes/{id}/disaster — disaster-aware flip. An
            // optional `disaster` query value (default 'earthquake') selects the
            // switch: 'heat_wave' fixes the heat (converting a waiting turn-0
            // default episode in place, heat-wave Requirement 8.2); anything
            // else is the unchanged earthquake flip (Requirement 1.6).
            if ($method === 'POST' && count($segments) === 3 && $segments[2] === 'disaster') {
                $disaster = ps_disaster_from_query($query);

                return ps_handle_disaster($service, $episodeId, $disaster);
            }

            // POST /api/episodes/{id}/reflect — ask both robots once after an
            // ended episode (robot-memory Requirement 2.1). 404 unknown and
            // 409 not_ended / not_reflectable are raised as ApiError by the
            // service and mapped by the catch below.
            if ($method === 'POST'
                && count($segments) === 3
                && $segments[2] === 'reflect'
                && $reflectService !== null
            ) {
                return ps_handle_reflect($reflectService, $episodeId);
            }

            // POST /api/episodes/{id}/turns/{n}/ask
            // POST /api/episodes/{id}/turns/{n}/apply
            if ($method === 'POST'
                && count($segments) === 5
                && $segments[2] === 'turns'
                && ($segments[4] === 'ask' || $segments[4] === 'apply')
            ) {
                $turnNumber = ps_parse_turn_number($segments[3]);
                if ($turnNumber === null) {
                    return ps_json_response(404, ['error' => 'not_found']);
                }

                return $segments[4] === 'ask'
                    ? ps_handle_ask($service, $episodeId, $turnNumber)
                    : ps_handle_apply($service, $episodeId, $turnNumber);
            }

            // GET /api/episodes/{id}
            if ($method === 'GET' && count($segments) === 2) {
                return ps_handle_get($service, $episodeId);
            }
        }

        // Nothing matched.
        return ps_json_response(404, ['error' => 'not_found']);
    } catch (ApiError $e) {
        // Map the turn-loop error to its status with the small, safe body
        // (code + current state only — never a key, credential, path, or
        // provider name).
        return ps_json_response($e->status, $e->toArray());
    } catch (\Throwable) {
        // Any unexpected failure (SecretLoadException, DB/connection failure,
        // an engine bug) becomes a generic 500 with a short machine code only.
        // Never leak the exception message, path, stack, key, credential, or
        // provider name (Requirements 2.6, 10.7).
        return ps_json_response(500, ['error' => 'internal_error']);
    }
}

/**
 * Read the optional `disaster` query value, returning 'heat_wave' only when the
 * request explicitly asked for it and 'earthquake' otherwise (heat-wave
 * Requirements 8.1, 8.2). Keeping the default 'earthquake' means a request that
 * names no disaster — every request the earthquake flow makes today — behaves
 * exactly as before, so the earthquake create and flip are unchanged.
 *
 * @param array<string,mixed> $query
 */
function ps_disaster_from_query(array $query): string
{
    $disaster = $query['disaster'] ?? null;

    return $disaster === 'heat_wave' ? 'heat_wave' : 'earthquake';
}

/**
 * Parse a turn-number path segment. Accepts a non-negative integer only (turns
 * are zero-based); anything else is a bad route and yields null (→ 404).
 */
function ps_parse_turn_number(string $segment): ?int
{
    if ($segment === '' || !ctype_digit($segment)) {
        return null;
    }

    return (int) $segment;
}

/**
 * Read the device id from the request cookies, or null when absent.
 *
 * @param array<string,string> $cookies
 */
function ps_device_id_from_cookies(array $cookies): ?string
{
    $id = $cookies[PS_DEVICE_COOKIE] ?? null;

    // Accept only what ps_new_device_id() mints; anything else (empty, long,
    // malformed) is treated as absent (public-lab design C15).
    return is_string($id) && preg_match('/\A[0-9a-f]{32}\z/', $id) === 1 ? $id : null;
}
