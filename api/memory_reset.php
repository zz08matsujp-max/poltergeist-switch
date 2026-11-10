<?php

declare(strict_types=1);

/**
 * POST /api/memory/reset — start a fresh generation: advance the current
 * generation and delete no memory row (robot-memory spec, Requirements 4.2,
 * 4.5).
 *
 * Thin handler: it calls MemoryService::reset once and writes the new
 * generation as JSON with Cache-Control: no-store. No rules logic lives here.
 * The body is only `{ "generation": N }` — never a device id, API key,
 * database credential, secrets path, or AI company/model name (Requirement
 * 4.5).
 *
 * Shared helpers (ps_json_response, PsApiResponse) live in bootstrap.php.
 */

use PoltergeistSwitch\Memory\MemoryService;

/**
 * Handle the reset request: advance the current generation and report it.
 */
function ps_handle_memory_reset(MemoryService $service, ?string $deviceId = null): PsApiResponse
{
    // A null Device (no cookie) is a no-op in the service: nothing is recorded
    // and no cookie is minted. A running episode of the Device raises the
    // service's 409 ApiError, which the router's catch maps.
    $status = $service->reset($deviceId);

    return ps_json_response(200, ['generation' => $status->generation]);
}
