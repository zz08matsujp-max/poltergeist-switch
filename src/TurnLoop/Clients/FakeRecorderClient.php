<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop\Clients;

use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\TurnLoop\Values\Reading;

/**
 * A test double for {@see RecorderClient} that returns a configured Reading
 * deterministically and opens no network connection (Requirements 3.1, 14.1).
 *
 * It counts how many times it was called so idempotency tests can assert "at
 * most once per reply". The fake has no real curl handle: {@see prepare()} and
 * {@see finish()} both route through {@see read()}, so every path that produces
 * a Reading bumps the counter exactly once per logical call and no transport is
 * ever touched.
 */
final class FakeRecorderClient implements RecorderClient
{
    /** The reading returned for any reply, unless a per-reply reading is set. */
    private Reading $default;

    /** @var array<string,Reading> Optional per-reply readings, keyed by reply text. */
    private array $byReply = [];

    /** The fixed recorder prompt version this fake reports. */
    private string $promptVersion;

    /** Number of logical reads made so far. */
    private int $callCount = 0;

    public function __construct(?Reading $default = null, string $promptVersion = 'recorder-fake')
    {
        $this->promptVersion = $promptVersion;
        $this->default = $default ?? new Reading(
            new Action('robot', Operation::wait->value),
            false,
            '{"operation":"wait"}',
            $promptVersion,
        );
    }

    /**
     * Configure the reading returned for a specific reply text. When a reply
     * has no configured reading, {@see read()} returns the default.
     */
    public function setReadingForReply(string $reply, Reading $reading): void
    {
        $this->byReply[$reply] = $reading;
    }

    public function read(RecorderInput $input, string $reply): Reading
    {
        $this->callCount++;

        return $this->byReply[$reply] ?? $this->default;
    }

    public function promptVersion(): string
    {
        return $this->promptVersion;
    }

    /**
     * The fake has no real curl handle; a bare handle is returned so the
     * interface contract is satisfied, but it is never run over the network —
     * {@see finish()} ignores it and routes through {@see read()}.
     */
    public function prepare(RecorderInput $input, string $reply): \CurlHandle
    {
        return curl_init();
    }

    /**
     * @param array{status:int,body:?string,seconds:float,error:?string} $result
     */
    public function finish(RecorderInput $input, string $reply, array $result): Reading
    {
        return $this->read($input, $reply);
    }

    /** How many logical reads have been made. */
    public function callCount(): int
    {
        return $this->callCount;
    }
}
