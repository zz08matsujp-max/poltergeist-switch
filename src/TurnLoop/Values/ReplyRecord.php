<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop\Values;

use InvalidArgumentException;

/**
 * The stored result of one Robot_Client call (Requirements 1.4, 1.5, 1.8, 8.4).
 *
 * A successful call carries the full prompt and reply, non-negative token
 * counts, the seconds elapsed, the cost, and the truncated flag. An unanswered
 * call (failure, timeout, or abandon — Requirement 1.8) carries an empty reply,
 * zero tokens, and zero cost, and may carry a short machine-only failure reason
 * token (never a key, credential, path, provider name, or provider message).
 */
readonly class ReplyRecord
{
    public function __construct(
        public string $prompt,        // full prompt text sent
        public string $reply,         // full reply text ('' when unanswered)
        public int $inputTokens,      // >= 0; 0 when unanswered
        public int $outputTokens,     // >= 0; 0 when unanswered
        public float $seconds,        // wall-clock seconds for the call
        public float $cost,           // 0.0 when unanswered
        public bool $unanswered,      // true on failure/timeout/abandon (Req 1.8)
        public bool $truncated,       // provider cut off at the token limit (Req 1.5)
        public ?string $failureReason = null, // short machine token why it failed; null when answered (Req 1.8)
    ) {
        if ($inputTokens < 0 || $outputTokens < 0) {
            throw new InvalidArgumentException('Token counts must be non-negative.');
        }
    }

    /**
     * Build the record for a failed, timed-out, or abandoned attempt: empty
     * reply, zero tokens, zero cost, unanswered = true (Requirement 1.8). The
     * optional $failureReason is a short machine-only token (never a key,
     * credential, path, provider name, or provider message).
     */
    public static function unanswered(string $prompt, float $seconds = 0.0, ?string $failureReason = null): self
    {
        return new self($prompt, '', 0, 0, $seconds, 0.0, true, false, $failureReason);
    }
}
