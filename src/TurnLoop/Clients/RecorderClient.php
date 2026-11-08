<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop\Clients;

use PoltergeistSwitch\TurnLoop\Values\Reading;

/**
 * The client that reads one robot reply into a {@see Reading} (Requirement 3).
 *
 * It reads exactly one reply per call (Requirement 3.1) under a fixed, versioned
 * recorder prompt (Requirement 3.5). A reply that yields no readable action,
 * recorder output that is not valid JSON of the Reading shape, or a failed /
 * timed-out call all produce a Reading whose action/operation is `unknown`
 * (Requirements 3.3, 3.6, 3.7); it never retries within one request
 * (Requirement 3.7).
 *
 * {@see prepare()} and {@see finish()} are the primitives TurnService uses to
 * read both robots' replies at once through one `curl_multi` handle (Requirement
 * 6.2); {@see read()} is the synchronous convenience that runs one handle on its
 * own.
 */
interface RecorderClient
{
    /**
     * Read one reply into a Reading using the fixed, versioned recorder prompt.
     *
     * @param RecorderInput $input the ids-only input of Requirement 3.4
     * @param string        $reply the stored robot reply to read
     */
    public function read(RecorderInput $input, string $reply): Reading;

    /**
     * Prepare a configured curl handle so TurnService can read both robots'
     * replies in parallel through one `curl_multi` handle (Requirement 6.2).
     */
    public function prepare(RecorderInput $input, string $reply): \CurlHandle;

    /**
     * Turn a finished curl handle's result into a Reading. A non-null `error`,
     * a non-2xx status, or recorder output that is not valid JSON of the
     * Reading shape yields an `unknown` Reading (Requirements 3.6, 3.7).
     *
     * @param array{status:int,body:?string,seconds:float,error:?string} $result
     */
    public function finish(RecorderInput $input, string $reply, array $result): Reading;

    /** The version id of the fixed recorder prompt, stored with every reading (Requirement 3.5). */
    public function promptVersion(): string;
}
