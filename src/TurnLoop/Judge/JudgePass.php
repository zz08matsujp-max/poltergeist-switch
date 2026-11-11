<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop\Judge;

/**
 * The shared judge pass (public-lab Requirement 21). Pure functions, no I/O.
 */
final class JudgePass
{
    /** A new random pass: 32 random bytes as base64url, 43 characters. */
    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function isWellFormedPass(string $pass): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]{43}\z/', $pass) === 1;
    }

    public static function isWellFormedHash(string $hash): bool
    {
        return preg_match('/\A[0-9a-f]{64}\z/', $hash) === 1;
    }

    public static function hash(string $pass): string
    {
        return hash('sha256', $pass);
    }

    public static function matches(string $pass, string $expectedHash): bool
    {
        return hash_equals($expectedHash, hash('sha256', $pass));
    }
}
