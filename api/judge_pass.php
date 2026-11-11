<?php

declare(strict_types=1);

/**
 * Judge pass routes (public-lab Requirement 21; design "Judge Pass").
 *
 * ps_build_judge_cookie() is the single place where judge mode is switched on
 * or off. The two handlers never put the pass or its hash in a body, a header
 * or a log line.
 */

use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\Judge\JudgeCookie;
use PoltergeistSwitch\TurnLoop\Judge\JudgePass;

/** Longest accepted request body in bytes. */
const PS_JUDGE_BODY_MAX = 512;

/**
 * Build the cookie service, or null when judge mode is off: no
 * `judge_pass_sha256`, one that is not 64 lowercase hex, or no usable key.
 *
 * @param array<string,mixed> $localConfig
 */
function ps_build_judge_cookie(array $localConfig, Config $config): ?JudgeCookie
{
    $hash = $localConfig['judge_pass_sha256'] ?? null;
    if (!is_string($hash) || !JudgePass::isWellFormedHash($hash)) {
        return null;
    }

    $secretsDir = $localConfig['secrets_dir'] ?? null;
    if (!is_string($secretsDir)) {
        return null;
    }

    $key = ps_load_judge_key($secretsDir);
    if ($key === null) {
        return null;
    }

    return new JudgeCookie($hash, $key, $config->judgeUntilUnix());
}

/**
 * GET /api/judge-pass: whether the request's cookie is a valid Judge_Cookie.
 *
 * @param array<string,string> $cookies
 */
function ps_handle_judge_pass_get(?JudgeCookie $judge, array $cookies, int $now): PsApiResponse
{
    if ($judge !== null && $judge->isValid($cookies[JudgeCookie::NAME] ?? null, $now)) {
        return ps_json_response(200, ['judge' => true, 'until' => $judge->untilIso()]);
    }

    return ps_json_response(200, ['judge' => false, 'until' => null]);
}

/**
 * POST /api/judge-pass: check the pass and issue the cookie.
 */
function ps_handle_judge_pass_post(?JudgeCookie $judge, string $body, int $now): PsApiResponse
{
    if ($judge === null) {
        return ps_json_response(404, ['error' => 'not_found']);
    }

    if (strlen($body) > PS_JUDGE_BODY_MAX) {
        return ps_json_response(400, ['error' => 'bad_request']);
    }

    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['pass']) || !is_string($data['pass'])) {
        return ps_json_response(400, ['error' => 'bad_request']);
    }

    if (!$judge->passMatches($data['pass'])) {
        return ps_json_response(403, ['error' => 'judge_pass_invalid']);
    }

    $value = $judge->issue($now);
    if ($value === null) {
        return ps_json_response(403, ['error' => 'judge_pass_expired']);
    }

    return ps_json_response(
        200,
        ['judge' => true, 'until' => $judge->untilIso()],
        null,
        ['Set-Cookie' => $judge->setCookieHeader($value)],
    );
}
