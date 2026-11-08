<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop\Clients;

use PoltergeistSwitch\TurnLoop\Values\ReplyRecord;

/**
 * One uniform interface drives both companies identically (Requirement 1.1).
 *
 * It takes the full prompt text and returns a {@see ReplyRecord}; it never
 * retries within one request (Requirement 1.9) and never throws out to the
 * caller — a failure or timeout yields a ReplyRecord with `unanswered = true`
 * (Requirements 1.8, 1.9).
 *
 * {@see prepare()} and {@see finish()} are the primitives TurnService uses to
 * fire both robots at once through one `curl_multi` handle (Requirement 1.6):
 * `prepare()` stages a configured curl handle, ParallelHttp runs it, and
 * `finish()` turns that handle's result into a ReplyRecord applying this
 * provider's truncation rule (Requirements 1.4, 1.5). {@see call()} is the
 * synchronous convenience that runs one handle on its own.
 */
interface RobotClient
{
    /**
     * Send exactly one user message (no system prompt, provider default
     * parameters, max output 4000 tokens — Requirement 1.2) and return a
     * ReplyRecord. Never retries within one request (Requirement 1.9). A
     * failure or timeout returns a ReplyRecord with unanswered = true
     * (Requirement 1.8).
     *
     * @param string $prompt the full situation text to send
     */
    public function call(string $prompt): ReplyRecord;

    /**
     * Prepare a configured curl handle so TurnService can fire both robots in
     * parallel through one `curl_multi` handle (Requirement 1.6). TurnService
     * reads each handle's result back through {@see finish()}.
     */
    public function prepare(string $prompt): \CurlHandle;

    /**
     * Turn a finished curl handle's result (status, body, elapsed seconds, and
     * any error) into a ReplyRecord, applying this provider's truncation rule
     * (Requirements 1.4, 1.5). A non-null `error`, a non-2xx status, or a body
     * that is not valid JSON of the expected shape yields an unanswered record
     * (Requirement 1.8).
     *
     * @param array{status:int,body:?string,seconds:float,error:?string} $result
     */
    public function finish(string $prompt, array $result): ReplyRecord;
}
