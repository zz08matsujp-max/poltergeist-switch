<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop\Http;

/**
 * A turn-loop error carrying an HTTP status, a short machine code, and the
 * episode's current state, raised by TurnService when a request cannot proceed
 * (design "Error Handling"; Requirements 4.6, 7.3, 7.6, 7.7, 9.5, 9.8, 9.9).
 *
 * The api/ layer catches this and writes the status with a small JSON body of
 * the machine code and the current state. The body carries only the code, the
 * current expected turn, and that turn's state — never a key, credential, path,
 * or AI provider name (Requirements 2.6, 10.7).
 *
 * A 409 body looks like
 *   { "error": "turn_mismatch", "currentExpectedTurn": 3, "turnState": "waiting" }
 * (design "Response shape"); a 404 body carries just the code with no state. A
 * 500 'world_backfill_failed' (screens spec Requirement 10.8) carries just the
 * code too; the underlying cause is kept as the previous exception for logging
 * and never put in the body.
 */
final class ApiError extends \RuntimeException
{
    public function __construct(
        public readonly int $status,       // HTTP status: 400, 404, 409, or 500
        public readonly string $errorCode, // short machine code, e.g. 'turn_mismatch'
        public readonly ?int $currentExpectedTurn = null,
        public readonly ?string $turnState = null,
        ?\Throwable $previous = null,      // the underlying cause, kept off the body
    ) {
        parent::__construct($errorCode, 0, $previous);
    }

    /**
     * Reject with HTTP 409 Conflict and the episode's current state
     * (Requirements 4.6, 7.3, 7.6, 7.7, 9.8, 9.9).
     */
    public static function conflict(
        string $code,
        ?int $currentExpectedTurn = null,
        ?string $turnState = null,
    ): self {
        return new self(409, $code, $currentExpectedTurn, $turnState);
    }

    /**
     * Reject with HTTP 404 Not Found for an unknown episode id (Requirement
     * 9.5). No state is carried, because the episode does not exist.
     */
    public static function notFound(string $code = 'episode_not_found'): self
    {
        return new self(404, $code);
    }

    /**
     * Reject a request with HTTP 400 Bad Request because a query parameter is
     * out of range — used for the ended-episode list's `limit` when it is not
     * an integer, is less than 1, or is greater than 100 (screens spec
     * Requirement 11.6). No episode state is carried; the body is just the
     * machine code (default 'limit_out_of_range'), and no list is returned.
     */
    public static function outOfRange(string $code = 'limit_out_of_range'): self
    {
        return new self(400, $code);
    }

    /**
     * Report that the one-time per-turn World_State backfill could not complete
     * (screens spec Requirement 10.8). The underlying cause is kept as the
     * previous exception for logging but never reaches the JSON body, which
     * carries only the machine code 'world_backfill_failed'. HTTP 500: the read
     * could not produce the stored worlds the screens need, and no stored
     * World_State was changed.
     */
    public static function backfillFailed(?\Throwable $previous = null): self
    {
        return new self(500, 'world_backfill_failed', null, null, $previous);
    }

    /**
     * The error body as an associative array the api/ layer encodes as JSON.
     * Only the code and the current state appear; nulls are omitted so a 404
     * carries just the code.
     *
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        $body = ['error' => $this->errorCode];
        if ($this->currentExpectedTurn !== null) {
            $body['currentExpectedTurn'] = $this->currentExpectedTurn;
        }
        if ($this->turnState !== null) {
            $body['turnState'] = $this->turnState;
        }

        return $body;
    }
}
