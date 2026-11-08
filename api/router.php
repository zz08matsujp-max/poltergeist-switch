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

use PoltergeistSwitch\TurnLoop\Http\ApiError;
use PoltergeistSwitch\TurnLoop\TurnService;

require_once __DIR__ . '/episodes_create.php';
require_once __DIR__ . '/episodes_disaster.php';
require_once __DIR__ . '/episodes_ask.php';
require_once __DIR__ . '/episodes_apply.php';
require_once __DIR__ . '/episodes_get.php';

/**
 * Parse the method + route and dispatch to the matching handler, returning the
 * response as a value so a test can inspect it. All errors are mapped to a safe
 * JSON body here, so a handler never has to touch the output globals.
 *
 * @param string               $method  the HTTP method, e.g. 'GET' or 'POST'
 * @param string               $route   the route without a leading slash, e.g.
 *                                       'episodes/abc/turns/2/ask'
 * @param array<string,string> $cookies the request cookies (for the device id)
 */
function ps_dispatch(TurnService $service, string $method, string $route, array $cookies): PsApiResponse
{
    $method = strtoupper($method);
    $route = trim($route, '/');

    try {
        $segments = $route === '' ? [] : explode('/', $route);

        // POST /api/episodes — create
        if ($method === 'POST' && $segments === ['episodes']) {
            return ps_handle_create($service, $cookies);
        }

        // Everything else is /episodes/{id}/...
        if (count($segments) >= 2 && $segments[0] === 'episodes') {
            $episodeId = $segments[1];

            // POST /api/episodes/{id}/disaster
            if ($method === 'POST' && count($segments) === 3 && $segments[2] === 'disaster') {
                return ps_handle_disaster($service, $episodeId);
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

    return is_string($id) && $id !== '' ? $id : null;
}
