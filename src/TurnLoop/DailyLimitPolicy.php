<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop;

/**
 * Daily start limits per scope (Requirements 13.3 to 13.7, 13.10). Pure.
 */
final class DailyLimitPolicy
{
    public function __construct(
        public readonly int $device,
        public readonly int $ip,
        public readonly int $site,
        public readonly int $judge = 100,
    ) {
    }

    public static function fromConfig(Config $c): self
    {
        return new self($c->dailyDeviceLimit, $c->dailyIpLimit, $c->dailySiteLimit, $c->judgeDailyLimit);
    }

    /** @return 'daily_judge_limit'|null the Judge_Cookie ceiling (public-lab 21.23) */
    public function judgeDenial(int $count): ?string
    {
        return $count >= $this->judge ? 'daily_judge_limit' : null;
    }

    /**
     * @param array{device:int, ip:?int, site:int} $counts starts so far today; ip null = scope not tracked
     * @return 'daily_device_limit'|'daily_ip_limit'|'daily_site_limit'|null first reached, in that order
     */
    public function denial(array $counts): ?string
    {
        if ($counts['device'] >= $this->device) {
            return 'daily_device_limit';
        }
        if ($counts['ip'] !== null && $counts['ip'] >= $this->ip) {
            return 'daily_ip_limit';
        }
        if ($counts['site'] >= $this->site) {
            return 'daily_site_limit';
        }
        return null;
    }

    public static function utcDay(int $unixTime): string
    {
        return gmdate('Y-m-d', $unixTime);
    }
}
