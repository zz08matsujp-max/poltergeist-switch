<?php

declare(strict_types=1);

/**
 * POST /api/episodes — create a new earthquake episode (Requirements 9.1, 9.6).
 *
 * Thin handler: it reads the device cookie only, mints one when absent, calls
 * TurnService::createEpisode once, and writes the JSON episode view with
 * Cache-Control: no-store. When the device cookie was absent, a Set-Cookie for
 * the freshly minted device id is added (Requirement 9.6). No rules logic lives
 * here; slot conflicts (409) are raised by TurnService and mapped by the
 * router.
 *
 * Shared helpers (ps_json_response, ps_episode_view_to_array, ps_new_device_id,
 * ps_device_id_from_cookies) live in bootstrap.php / router.php.
 */

use PoltergeistSwitch\TurnLoop\TurnService;

/**
 * @param array<string,string> $cookies
 */
function ps_handle_create(TurnService $service, array $cookies): PsApiResponse
{
    $deviceId = ps_device_id_from_cookies($cookies);
    $setDeviceId = null;

    if ($deviceId === null) {
        // First-time visitor: mint a device id and set it as a cookie.
        $deviceId = ps_new_device_id();
        $setDeviceId = $deviceId;
    }

    $view = $service->createEpisode($deviceId);

    return ps_json_response(200, ps_episode_view_to_array($view), $setDeviceId);
}
