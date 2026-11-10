<?php

declare(strict_types=1);

/**
 * GET /api/memory — the current generation and, per robot reported by its
 * screen name Amber (robot_a) and Jade (robot_b), the active-memory count and
 * each active memory's character length (robot-memory spec, Requirements 5.1,
 * 5.4, 5.5).
 *
 * Thin handler: it calls MemoryService::status once and writes the JSON with
 * Cache-Control: no-store. No rules logic lives here. The body carries only
 * generation and per-robot counts/lengths — never a device id, API key,
 * database credential, secrets path, or AI company/model name (Requirement
 * 5.4).
 *
 * Shared helpers (ps_json_response, PsApiResponse) live in bootstrap.php.
 */

use PoltergeistSwitch\Memory\MemoryService;
use PoltergeistSwitch\Memory\Values\MemoryStatus;

/**
 * Handle the status request: report the current generation and per-robot
 * counts and lengths.
 */
function ps_handle_memory_get(MemoryService $service, ?string $deviceId = null): PsApiResponse
{
    // A null Device (no cookie) gets the empty status; no cookie is minted.
    return ps_json_response(200, ps_memory_status_to_array($service->status($deviceId)));
}

/**
 * Serialize a MemoryStatus to the status response shape (design "API Design"):
 * the current generation and, per robot keyed `amber` (robot_a) and `jade`
 * (robot_b), the active-memory count and the length of each active memory,
 * oldest first. Only memory data and robot-facing names appear.
 *
 * @return array<string,mixed>
 */
function ps_memory_status_to_array(MemoryStatus $status): array
{
    return [
        'generation' => $status->generation,
        'robots'     => [
            'amber' => [
                'activeCount' => $status->amberActiveCount,
                'lengths'     => $status->amberLengths,
            ],
            'jade' => [
                'activeCount' => $status->jadeActiveCount,
                'lengths'     => $status->jadeLengths,
            ],
        ],
    ];
}
