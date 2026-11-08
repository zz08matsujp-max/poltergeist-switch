<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop\Clients;

use PoltergeistSwitch\TurnLoop\Values\ReplyRecord;

/**
 * A test double for {@see RobotClient} that returns a configured ReplyRecord
 * deterministically and opens no network connection (Requirements 1.1, 14.1).
 *
 * It counts how many times it was called so the ask-idempotency property test
 * can assert "at most once per turn" ({@see callCount()}). The fake has no real
 * curl handle: {@see prepare()} and {@see finish()} both route through
 * {@see call()}, so every path that produces a ReplyRecord bumps the counter
 * exactly once per logical call and no transport is ever touched.
 */
final class FakeRobotClient implements RobotClient
{
    /** The record returned for any prompt, unless a per-prompt record is set. */
    private ReplyRecord $default;

    /** @var array<string,ReplyRecord> Optional per-prompt records, keyed by prompt. */
    private array $byPrompt = [];

    /** Number of logical calls made so far. */
    private int $callCount = 0;

    public function __construct(?ReplyRecord $default = null)
    {
        $this->default = $default ?? new ReplyRecord('', 'ok', 1, 1, 0.0, 0.0, false, false);
    }

    /**
     * Configure the record returned for a specific prompt. When a prompt has no
     * configured record, {@see call()} returns the default with its `prompt`
     * field set to the prompt that was passed in.
     */
    public function setReplyForPrompt(string $prompt, ReplyRecord $record): void
    {
        $this->byPrompt[$prompt] = $record;
    }

    public function call(string $prompt): ReplyRecord
    {
        $this->callCount++;

        if (isset($this->byPrompt[$prompt])) {
            return $this->byPrompt[$prompt];
        }

        $d = $this->default;

        return new ReplyRecord(
            $prompt,
            $d->reply,
            $d->inputTokens,
            $d->outputTokens,
            $d->seconds,
            $d->cost,
            $d->unanswered,
            $d->truncated,
            $d->failureReason,
        );
    }

    /**
     * The fake has no real curl handle; TurnService uses {@see call()} on the
     * fake. A bare handle is returned so the interface contract is satisfied,
     * but it is never run over the network — {@see finish()} ignores it and
     * routes through {@see call()}.
     */
    public function prepare(string $prompt): \CurlHandle
    {
        return curl_init();
    }

    /**
     * @param array{status:int,body:?string,seconds:float,error:?string} $result
     */
    public function finish(string $prompt, array $result): ReplyRecord
    {
        return $this->call($prompt);
    }

    /** How many logical calls have been made (Requirement 14.2 testing). */
    public function callCount(): int
    {
        return $this->callCount;
    }
}
