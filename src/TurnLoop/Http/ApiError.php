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
 * (design "Response shape"); a 404 body carries just the code with no state.
 */
final class ApiError extends \RuntimeException
{
    public function __construct(
        public readonly int $status,       // HTTP status: 404 or 409
        public readonly string $errorCode, // short machine code, e.g. 'turn_mismatch'
        public readonly ?int $currentExpectedTurn = null,
        public readonly ?string $turnState = null,
    ) {
        parent::__construct($errorCode);
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
