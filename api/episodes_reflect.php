<?php

declare(strict_types=1);

/**
 * POST /api/episodes/{id}/reflect — ask both robots once, after an ended
 * episode, what they want to remember, and append any non-empty answer as one
 * Memory (robot-memory spec, Requirements 2.1, 2.12).
 *
 * Thin handler: it parses the episode id from the route only, calls
 * ReflectService::reflect once, and writes the per-robot JSON result with
 * Cache-Control: no-store. No rules logic lives here. The guard outcomes
 * (404 unknown; 409 `not_ended`; 409 `not_reflectable` for a pre-feature
 * episode) are raised as ApiError by ReflectService and mapped to the HTTP
 * status by ps_dispatch's catch. The body carries no device id, API key,
 * database credential, secrets path, or AI company/model name (Requirement
 * 2.12).
 *
 * The response names the robots by their screen names Amber (robot_a) and Jade
 * (robot_b), each with answered / memory / activeCount / reason (design "API
 * Design", reflect response shape).
 *
 * Shared helpers (ps_json_response, PsApiResponse) live in bootstrap.php.
 */

use PoltergeistSwitch\Memory\ReflectService;
use PoltergeistSwitch\Memory\Values\ReflectionResult;

/**
 * Handle one reflect request: call the service and serialize the per-robot
 * result. Any ApiError the service throws (404 / 409) propagates to
 * ps_dispatch, which maps it to the HTTP status with a safe body.
 */
function ps_handle_reflect(ReflectService $service, string $episodeId): PsApiResponse
{
    $result = $service->reflect($episodeId);

    return ps_json_response(200, ps_reflection_result_to_array($episodeId, $result));
}

/**
 * Serialize a ReflectionResult to the reflect response shape (design "API
 * Design"): the robots are keyed by their screen names `amber` (robot_a) and
 * `jade` (robot_b), each carrying answered / memory / activeCount / reason.
 * Only memory data and robot-facing names appear — never a device id, key,
 * credential, path, or AI company/model name (Requirement 2.12).
 *
 * @return array<string,mixed>
 */
function ps_reflection_result_to_array(string $episodeId, ReflectionResult $result): array
{
    return [
        'episodeId' => $episodeId,
        'robots'    => [
            'amber' => ps_robot_reflection_to_array($result->robots['robot_a']),
            'jade'  => ps_robot_reflection_to_array($result->robots['robot_b']),
        ],
    ];
}

/**
 * Serialize one robot's RobotReflection: answered / memory / activeCount /
 * reason. `reason` is a short machine code (`empty_answer`, `no_reply`,
 * `timeout`) or null, never a provider name.
 *
 * @return array<string,mixed>
 */
function ps_robot_reflection_to_array(\PoltergeistSwitch\Memory\Values\RobotReflection $robot): array
{
    return [
        'answered'    => $robot->answered,
        'memory'      => $robot->memory,
        'activeCount' => $robot->activeCount,
        'reason'      => $robot->reason,
    ];
}
