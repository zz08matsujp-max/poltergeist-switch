<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop;

/**
 * Salted keyed hashes for the daily_usage table (Requirement 13.14). Pure.
 * The scope prefix keeps the 'ip' and 'device' key spaces apart.
 */
final class IpHasher
{
    public function __construct(private readonly string $salt)
    {
    }

    public function ipHash(string $normalizedIp): string
    {
        return hash_hmac('sha256', 'ip:' . $normalizedIp, $this->salt);
    }

    public function deviceKey(string $deviceId): string
    {
        return hash_hmac('sha256', 'device:' . $deviceId, $this->salt);
    }
}
