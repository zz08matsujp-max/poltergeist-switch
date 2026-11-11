<?php

declare(strict_types=1);

/**
 * POST /api/episodes — create a new earthquake episode (Requirements 9.1, 9.6;
 * robot-memory Requirement 6.1).
 *
 * Thin handler: it reads the device cookie only, mints one when absent, calls
 * TurnService::createEpisode once, then — as part of the same create flow —
 * freezes each robot's current-generation Memory_Snapshot and the episode's
 * generation onto the new episode via MemoryService::snapshotForNewEpisode, so
 * every new episode carries a snapshot and a generation (robot-memory
 * Requirement 6.1). It writes the JSON episode view with Cache-Control:
 * no-store. When the device cookie was absent, a Set-Cookie for the freshly
 * minted device id is added (Requirement 9.6). No rules logic lives here; slot
 * conflicts (409) are raised by TurnService and mapped by the router.
 *
 * The memory service is optional so the turn-loop tests that drive
 * ps_handle_create with a TurnService alone keep working unchanged; production
 * (and the robot-memory tests) wire it so the snapshot is always taken.
 *
 * Shared helpers (ps_json_response, ps_episode_view_to_array, ps_new_device_id,
 * ps_device_id_from_cookies) live in bootstrap.php / router.php.
 */

use PoltergeistSwitch\Memory\MemoryService;
use PoltergeistSwitch\TurnLoop\EpisodeStarter;
use PoltergeistSwitch\TurnLoop\IpHasher;
use PoltergeistSwitch\TurnLoop\TurnService;
use PoltergeistSwitch\TurnLoop\UsageKeys;

/**
 * @param array<string,string> $cookies
 */
function ps_handle_create(
    TurnService $service,
    array $cookies,
    ?MemoryService $memoryService = null,
    string $disaster = 'earthquake',
    ?EpisodeStarter $starter = null,
    ?string $clientIp = null,
    ?IpHasher $hasher = null,
    bool $judge = false,
): PsApiResponse {
    $deviceId = ps_device_id_from_cookies($cookies);
    $setDeviceId = null;

    if ($deviceId === null) {
        // First-time visitor: mint a device id and set it as a cookie.
        $deviceId = ps_new_device_id();
        $setDeviceId = $deviceId;
    }

    // With a starter wired, the start goes through the daily limits (public-lab
    // Requirement 13.1). ApiError (409 / 429) propagates to the router's catch,
    // which maps it to its status; the cookie is set only on success, so a
    // refused start mints nothing. The usage keys are hashes: neither the
    // Device_Id nor the raw IP reaches the usage table or a response.
    if ($starter !== null) {
        $view = $starter->start($deviceId, ps_usage_keys($deviceId, $clientIp, $hasher), $disaster, $judge);

        return ps_json_response(200, ps_episode_view_to_array($view), $setDeviceId);
    }

    // $disaster is 'earthquake' (default) or 'heat_wave'; TurnService treats
    // anything other than 'heat_wave' as earthquake, so the earthquake create
    // is unchanged (heat-wave Requirement 8.1).
    $view = $service->createEpisode($deviceId, disaster: $disaster);

    // Freeze the per-robot Memory_Snapshot and the episode's generation onto the
    // new episode, so every new episode carries a snapshot and a generation
    // (robot-memory Requirement 6.1) and so a NULL snapshot keeps meaning only a
    // pre-feature episode. Part of the create flow, over the same repository the
    // episode was created in.
    if ($memoryService !== null) {
        $memoryService->snapshotForNewEpisode($view->episodeId, $deviceId);
    }

    return ps_json_response(200, ps_episode_view_to_array($view), $setDeviceId);
}

/**
 * Build the hashed usage keys for one start (public-lab Requirements 13.14,
 * 13.15). With a salt the keys are HMAC-SHA-256 hashes and the IP scope is
 * tracked when the IP resolved. With no salt the Device key falls back to a
 * plain SHA-256 of 'device:' + id and the IP scope is skipped.
 */
function ps_usage_keys(string $deviceId, ?string $clientIp, ?IpHasher $hasher): UsageKeys
{
    if ($hasher === null) {
        return new UsageKeys(hash('sha256', 'device:' . $deviceId), null);
    }

    return new UsageKeys(
        $hasher->deviceKey($deviceId),
        $clientIp !== null ? $hasher->ipHash($clientIp) : null,
    );
}
