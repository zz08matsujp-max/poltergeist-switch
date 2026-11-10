<?php
declare(strict_types=1);
namespace PoltergeistSwitch\TurnLoop\Repository;

use PoltergeistSwitch\TurnLoop\DailyLimitPolicy;
use PoltergeistSwitch\TurnLoop\UsageKeys;

/**
 * Daily episode-start counters (Requirements 13.1, 13.3 to 13.6, 13.9, 13.14).
 * Only hashed keys are ever passed in; no raw Device_Id or IP address.
 */
interface UsageRepository
{
    /**
     * Atomic: null and +1 on every tracked scope, or the denial code and no change.
     *
     * @param string $utcDay 'Y-m-d' in UTC
     * @param string $nowUtc 'Y-m-d H:i:s' in UTC (stored as updated_at)
     * @return 'daily_device_limit'|'daily_ip_limit'|'daily_site_limit'|null
     */
    public function tryReserve(string $utcDay, UsageKeys $keys, DailyLimitPolicy $policy, string $nowUtc): ?string;

    /** Gives back one reservation on every tracked scope; never goes below zero. */
    public function release(string $utcDay, UsageKeys $keys, string $nowUtc): void;

    /** @return array{device:int, ip:?int, site:int} ip is null when the IP scope is not tracked */
    public function counts(string $utcDay, UsageKeys $keys): array;
}
