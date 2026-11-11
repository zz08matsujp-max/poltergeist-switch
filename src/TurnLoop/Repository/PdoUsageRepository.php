<?php
declare(strict_types=1);
namespace PoltergeistSwitch\TurnLoop\Repository;

use PDO;
use PoltergeistSwitch\TurnLoop\DailyLimitPolicy;
use PoltergeistSwitch\TurnLoop\UsageKeys;
use RuntimeException;
use Throwable;

/**
 * MySQL implementation of the daily counters. Locks are always taken in primary-key
 * order (scope: device, ip, site) so concurrent callers cannot deadlock.
 */
final class PdoUsageRepository implements UsageRepository
{
    private const JUDGE_SCOPE = 'judge';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function tryReserve(string $utcDay, UsageKeys $keys, DailyLimitPolicy $policy, string $nowUtc): ?string
    {
        $rows = self::rows($keys);
        $this->pdo->beginTransaction();
        try {
            $upsert = $this->pdo->prepare(
                'INSERT INTO daily_usage (usage_date, scope, key_hash, starts, updated_at) '
                . 'VALUES (:d, :s, :k, 0, :n) ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)'
            );
            foreach ($rows as $scope => $hash) {
                $upsert->execute([':d' => $utcDay, ':s' => $scope, ':k' => $hash, ':n' => $nowUtc]);
            }

            $select = $this->pdo->prepare(
                'SELECT starts FROM daily_usage WHERE usage_date = :d AND scope = :s AND key_hash = :k FOR UPDATE'
            );
            $counts = ['device' => 0, 'ip' => null, 'site' => 0];
            foreach ($rows as $scope => $hash) {
                $select->execute([':d' => $utcDay, ':s' => $scope, ':k' => $hash]);
                $value = $select->fetchColumn();
                if ($value === false) {
                    throw new RuntimeException('daily_usage row missing after upsert');
                }
                $counts[$scope] = (int) $value;
            }

            $denial = $policy->denial($counts);
            if ($denial !== null) {
                $this->pdo->rollBack();
                return $denial;
            }

            $bump = $this->pdo->prepare(
                'UPDATE daily_usage SET starts = starts + 1, updated_at = :n '
                . 'WHERE usage_date = :d AND scope = :s AND key_hash = :k'
            );
            foreach ($rows as $scope => $hash) {
                $bump->execute([':n' => $nowUtc, ':d' => $utcDay, ':s' => $scope, ':k' => $hash]);
            }
            $this->pdo->commit();
            return null;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e instanceof RuntimeException ? $e : new RuntimeException('usage reserve failed', 0, $e);
        }
    }

    public function release(string $utcDay, UsageKeys $keys, string $nowUtc): void
    {
        $rows = self::rows($keys);
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE daily_usage SET starts = starts - 1, updated_at = :n '
                . 'WHERE usage_date = :d AND scope = :s AND key_hash = :k AND starts > 0'
            );
            foreach ($rows as $scope => $hash) {
                $stmt->execute([':n' => $nowUtc, ':d' => $utcDay, ':s' => $scope, ':k' => $hash]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e instanceof RuntimeException ? $e : new RuntimeException('usage release failed', 0, $e);
        }
    }

    public function tryReserveJudge(string $utcDay, DailyLimitPolicy $policy, string $nowUtc): ?string
    {
        $this->pdo->beginTransaction();
        try {
            $upsert = $this->pdo->prepare(
                'INSERT INTO daily_usage (usage_date, scope, key_hash, starts, updated_at) '
                . 'VALUES (:d, :s, :k, 0, :n) ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)'
            );
            $params = [':d' => $utcDay, ':s' => self::JUDGE_SCOPE, ':k' => UsageKeys::SITE_KEY];
            $upsert->execute($params + [':n' => $nowUtc]);

            $select = $this->pdo->prepare(
                'SELECT starts FROM daily_usage WHERE usage_date = :d AND scope = :s AND key_hash = :k FOR UPDATE'
            );
            $select->execute($params);
            $value = $select->fetchColumn();
            if ($value === false) {
                throw new RuntimeException('daily_usage row missing after upsert');
            }

            $denial = $policy->judgeDenial((int) $value);
            if ($denial !== null) {
                $this->pdo->rollBack();
                return $denial;
            }

            $bump = $this->pdo->prepare(
                'UPDATE daily_usage SET starts = starts + 1, updated_at = :n '
                . 'WHERE usage_date = :d AND scope = :s AND key_hash = :k'
            );
            $bump->execute($params + [':n' => $nowUtc]);
            $this->pdo->commit();
            return null;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e instanceof RuntimeException ? $e : new RuntimeException('usage reserve failed', 0, $e);
        }
    }

    public function releaseJudge(string $utcDay, string $nowUtc): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE daily_usage SET starts = starts - 1, updated_at = :n '
                . 'WHERE usage_date = :d AND scope = :s AND key_hash = :k AND starts > 0'
            );
            $stmt->execute([
                ':n' => $nowUtc,
                ':d' => $utcDay,
                ':s' => self::JUDGE_SCOPE,
                ':k' => UsageKeys::SITE_KEY,
            ]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e instanceof RuntimeException ? $e : new RuntimeException('usage release failed', 0, $e);
        }
    }

    public function judgeCount(string $utcDay): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT starts FROM daily_usage WHERE usage_date = :d AND scope = :s AND key_hash = :k'
        );
        $stmt->execute([':d' => $utcDay, ':s' => self::JUDGE_SCOPE, ':k' => UsageKeys::SITE_KEY]);
        $value = $stmt->fetchColumn();
        return $value === false ? 0 : (int) $value;
    }

    public function counts(string $utcDay, UsageKeys $keys): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT starts FROM daily_usage WHERE usage_date = :d AND scope = :s AND key_hash = :k'
        );
        $out = ['device' => 0, 'ip' => null, 'site' => 0];
        foreach (self::rows($keys) as $scope => $hash) {
            $stmt->execute([':d' => $utcDay, ':s' => $scope, ':k' => $hash]);
            $value = $stmt->fetchColumn();
            $out[$scope] = $value === false ? 0 : (int) $value;
        }
        return $out;
    }

    /** @return array<string,string> scope => key hash, in primary-key (alphabetical) order */
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
