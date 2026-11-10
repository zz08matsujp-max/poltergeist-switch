<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop;

/**
 * The hashed keys under which one start is counted (Requirement 13.14).
 * No raw Device_Id and no raw IP address is held here.
 */
final class UsageKeys
{
    /** Fixed key of the site-wide scope: 64 zeros. */
    public const SITE_KEY = '0000000000000000000000000000000000000000000000000000000000000000';

    public readonly string $siteKey;

    public function __construct(
        public readonly string $deviceKey,
        public readonly ?string $ipKey = null,
    ) {
        $this->siteKey = self::SITE_KEY;
    }
}
