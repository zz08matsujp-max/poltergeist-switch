<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop\Judge;

/**
 * The signed Judge_Cookie (public-lab Requirement 21). No I/O.
 * The signature covers the pass hash, so changing the hash invalidates every cookie.
 */
final class JudgeCookie
{
    public const NAME = 'ps_judge';

    public function __construct(
        private readonly string $passHash,
        private readonly string $key,
        private readonly int $untilUnix,
    ) {
    }

    public function untilIso(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $this->untilUnix);
    }

    public function passMatches(string $pass): bool
    {
        return JudgePass::matches($pass, $this->passHash);
    }

    public function sign(int $expiry): string
    {
        $binKey = hex2bin($this->key);

        return hash_hmac('sha256', $expiry . '.' . $this->passHash, $binKey === false ? '' : $binKey);
    }

    /** The cookie value, or null once Judging_End has passed. */
    public function issue(int $now): ?string
    {
        if ($now >= $this->untilUnix) {
            return null;
        }

        return $this->untilUnix . '.' . $this->sign($this->untilUnix);
    }

    public function setCookieHeader(string $value): string
    {
        return self::NAME . '=' . $value
            . '; Expires=' . gmdate('D, d M Y H:i:s \G\M\T', $this->untilUnix)
            . '; Path=/; HttpOnly; Secure; SameSite=Strict';
    }

    public function isValid(?string $cookie, int $now): bool
    {
        if ($cookie === null || preg_match('/\A([0-9]{1,12})\.([0-9a-f]{64})\z/', $cookie, $m) !== 1) {
            return false;
        }
        $expiry = (int) $m[1];
        if ($expiry !== $this->untilUnix || $expiry <= $now || $now >= $this->untilUnix) {
            return false;
        }

        return hash_equals($this->sign($expiry), $m[2]);
    }
}
