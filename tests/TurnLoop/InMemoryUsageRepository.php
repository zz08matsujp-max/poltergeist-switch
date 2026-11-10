<?php
declare(strict_types=1);
namespace PoltergeistSwitch\Tests\TurnLoop;

use PoltergeistSwitch\TurnLoop\DailyLimitPolicy;
use PoltergeistSwitch\TurnLoop\Repository\UsageRepository;
use PoltergeistSwitch\TurnLoop\UsageKeys;

/**
 * In-memory fake with the same all-or-nothing rule as PdoUsageRepository.
 * Rows are only created when a reservation succeeds (a denied one leaves nothing behind).
 */
final class InMemoryUsageRepository implements UsageRepository
{
    /** @var array<string,array{starts:int, updatedAt:string}> keyed "day|scope|hash" */
    public array $rows = [];

    /** @var callable fn(): int unix seconds; used when $nowUtc is the empty string */
    private $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function tryReserve(string $utcDay, UsageKeys $keys, DailyLimitPolicy $policy, string $nowUtc): ?string
    {
        $denial = $policy->denial($this->counts($utcDay, $keys));
        if ($denial !== null) {
            return $denial;
        }
        $now = $this->now($nowUtc);
        foreach (self::rows($keys) as $scope => $hash) {
            $k = "$utcDay|$scope|$hash";
            $this->rows[$k] = ['starts' => ($this->rows[$k]['starts'] ?? 0) + 1, 'updatedAt' => $now];
        }
        return null;
    }

    public function release(string $utcDay, UsageKeys $keys, string $nowUtc): void
    {
        $now = $this->now($nowUtc);
        foreach (self::rows($keys) as $scope => $hash) {
            $k = "$utcDay|$scope|$hash";
            if (isset($this->rows[$k]) && $this->rows[$k]['starts'] > 0) {
                $this->rows[$k] = ['starts' => $this->rows[$k]['starts'] - 1, 'updatedAt' => $now];
            }
        }
    }

    public function counts(string $utcDay, UsageKeys $keys): array
    {
        $out = ['device' => 0, 'ip' => null, 'site' => 0];
        foreach (self::rows($keys) as $scope => $hash) {
            $out[$scope] = $this->rows["$utcDay|$scope|$hash"]['starts'] ?? 0;
        }
        return $out;
    }

    private function now(string $nowUtc): string
    {
        return $nowUtc !== '' ? $nowUtc : gmdate('Y-m-d H:i:s', ($this->clock)());
    }

    /** @return array<string,string> */
    private static function rows(UsageKeys $keys): array
    {
        $rows = ['device' => $keys->deviceKey];
        if ($keys->ipKey !== null) {
            $rows['ip'] = $keys->ipKey;
        }
        $rows['site'] = $keys->siteKey;
        return $rows;
    }
}
