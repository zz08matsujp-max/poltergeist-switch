<?php

declare(strict_types=1);

/**
 * Resolve the client address for per-IP daily counting (public-lab
 * Requirements 13.14, 13.15). Plain function so a test can call it directly.
 */

use PoltergeistSwitch\TurnLoop\ClientIp;

/**
 * Read the client address from REMOTE_ADDR, or from the header named by the
 * optional `client_ip_header` config key (a $_SERVER key such as
 * 'HTTP_X_REAL_IP'). For a comma-separated list the rightmost entry is used
 * (the one the trusted proxy appended). Returns the normalised address, or null
 * when it is missing, malformed, loopback, private or reserved; a null result
 * logs the fixed code `ip_unresolvable` (never the address).
 *
 * @param array<string,mixed> $server the $_SERVER array
 * @param array<string,mixed> $config the local config
 */
function ps_client_ip(array $server, array $config): ?string
{
    $key = $config['client_ip_header'] ?? null;
    if (!is_string($key) || $key === '') {
        $key = 'REMOTE_ADDR';
    }

    $raw = $server[$key] ?? null;
    if (is_string($raw) && str_contains($raw, ',')) {
        $parts = explode(',', $raw);
        $raw = trim((string) end($parts));
    }

    $ip = ClientIp::normalize(is_string($raw) ? $raw : null);
    if ($ip === null) {
        error_log('ip_unresolvable');
    }

    return $ip;
}
