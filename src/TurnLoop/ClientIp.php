<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop;

/**
 * Normalises a client address for per-IP counting (Requirements 13.14, 13.15). Pure.
 */
final class ClientIp
{
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $packed = @inet_pton($raw);
        if ($packed === false) {
            return null;
        }

        if (strlen($packed) === 16) {
            // IPv4-mapped IPv6 (::ffff:a.b.c.d): unwrap to IPv4.
            if (substr($packed, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
                $raw = inet_ntop(substr($packed, 12));
                if ($raw === false) {
                    return null;
                }
                return self::publicV4($raw);
            }
            if (filter_var(
                $raw,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false) {
                return null;
            }
            // Link-local fe80::/10 and unspecified/loopback are rejected explicitly.
            $first = ord($packed[0]);
            $second = ord($packed[1]);
            if ($first === 0xfe && ($second & 0xc0) === 0x80) {
                return null;
            }
            if ($packed === str_repeat("\0", 16) || $packed === str_repeat("\0", 15) . "\x01") {
                return null;
            }
            $prefix = inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8));
            if ($prefix === false) {
                return null;
            }
            return $prefix . '/64';
        }

        return self::publicV4($raw);
    }

    private static function publicV4(string $ip): ?string
    {
        if (filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false) {
            return null;
        }
        $packed = inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        $a = ord($packed[0]);
        $b = ord($packed[1]);
        // Link-local 169.254.0.0/16 is not a client address.
        if ($a === 169 && $b === 254) {
            return null;
        }
        return inet_ntop($packed) ?: null;
    }
}
