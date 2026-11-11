<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop\Repository;

use PDO;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryUsageRepository;
use PoltergeistSwitch\TurnLoop\DailyLimitPolicy;
use PoltergeistSwitch\TurnLoop\Repository\PdoUsageRepository;
use PoltergeistSwitch\TurnLoop\Repository\UsageRepository;
use PoltergeistSwitch\TurnLoop\UsageKeys;

/**
 * Gated PDO/MySQL tests for the judge usage scope (public-lab task 26.4).
 *
 * Every case runs against the in-memory fake and the PDO repository with identical
 * expected values. Same gating and schema reset as UsageRepositoryPdoTest: the DSN
 * must name a throwaway database; without PS_TEST_MYSQL_DSN every test skips.
 *
 * Requirements: 21.23, 21.24, 21.26, 21.29, 14.36
 */
class JudgeUsagePdoTest extends TestCase
{
    private const DAY = '2026-11-10';
    private const NOW = '2026-11-10 12:00:00';
    private const LIMIT = 5;

    private function keys(): UsageKeys
    {
        return new UsageKeys(str_repeat('a', 64), str_repeat('b', 64));
    }

    /** The limit-th reserve passes and the next is denied with no change (Req 21.23). */
    public function testCeilingBoundary(): void
    {
        foreach ($this->repositories() as $label => $repo) {
            $policy = new DailyLimitPolicy(10, 30, 100, self::LIMIT);
            for ($i = 1; $i <= self::LIMIT; $i++) {
                $this->assertNull($repo->tryReserveJudge(self::DAY, $policy, self::NOW), "$label #$i");
            }
            $this->assertSame(self::LIMIT, $repo->judgeCount(self::DAY), $label);
            $this->assertSame('daily_judge_limit', $repo->tryReserveJudge(self::DAY, $policy, self::NOW), $label);
            $this->assertSame('daily_judge_limit', $repo->tryReserveJudge(self::DAY, $policy, self::NOW), $label);
            $this->assertSame(self::LIMIT, $repo->judgeCount(self::DAY), "$label: denial changes nothing");
        }
    }

    /** Release gives back one and never goes below zero (Req 21.24). */
    public function testReleaseNeverGoesBelowZero(): void
    {
        foreach ($this->repositories() as $label => $repo) {
            $policy = new DailyLimitPolicy(10, 30, 100, self::LIMIT);
            $repo->releaseJudge(self::DAY, self::NOW);
            $this->assertSame(0, $repo->judgeCount(self::DAY), $label);

            $repo->tryReserveJudge(self::DAY, $policy, self::NOW);
            $repo->tryReserveJudge(self::DAY, $policy, self::NOW);
            $repo->releaseJudge(self::DAY, self::NOW);
            $this->assertSame(1, $repo->judgeCount(self::DAY), $label);
            $repo->releaseJudge(self::DAY, self::NOW);
            $repo->releaseJudge(self::DAY, self::NOW);
            $this->assertSame(0, $repo->judgeCount(self::DAY), $label);
            $this->assertNull($repo->tryReserveJudge(self::DAY, $policy, self::NOW), "$label: reserve works after release");
        }
    }

    /** A new UTC day starts from zero (Req 21.26). */
    public function testNewUtcDayStartsFromZero(): void
    {
        foreach ($this->repositories() as $label => $repo) {
            $policy = new DailyLimitPolicy(10, 30, 100, 1);
            $this->assertNull($repo->tryReserveJudge(self::DAY, $policy, self::NOW), $label);
            $this->assertSame('daily_judge_limit', $repo->tryReserveJudge(self::DAY, $policy, self::NOW), $label);
            $this->assertNull($repo->tryReserveJudge('2026-11-11', $policy, '2026-11-11 00:00:00'), $label);
            $this->assertSame(1, $repo->judgeCount(self::DAY), $label);
            $this->assertSame(1, $repo->judgeCount('2026-11-11'), $label);
        }
    }

    /** The judge row leaves the device, IP and site rows alone, and the reverse (Req 21.29). */
    public function testJudgeRowDoesNotChangeOtherScopes(): void
    {
        foreach ($this->repositories() as $label => $repo) {
            $policy = new DailyLimitPolicy(10, 30, 100, self::LIMIT);
            $this->assertNull($repo->tryReserve(self::DAY, $this->keys(), $policy, self::NOW), $label);
            $before = $repo->counts(self::DAY, $this->keys());
            $this->assertSame(['device' => 1, 'ip' => 1, 'site' => 1], $before, $label);

            $this->assertNull($repo->tryReserveJudge(self::DAY, $policy, self::NOW), $label);
            $this->assertSame($before, $repo->counts(self::DAY, $this->keys()), "$label: after judge reserve");
            $repo->releaseJudge(self::DAY, self::NOW);
            $this->assertSame($before, $repo->counts(self::DAY, $this->keys()), "$label: after judge release");

            $this->assertNull($repo->tryReserveJudge(self::DAY, $policy, self::NOW), $label);
            $repo->release(self::DAY, $this->keys(), self::NOW);
            $this->assertSame(1, $repo->judgeCount(self::DAY), "$label: ordinary release leaves judge");
            $this->assertSame(['device' => 0, 'ip' => 0, 'site' => 0], $repo->counts(self::DAY, $this->keys()), $label);
        }
    }

    /**
     * Separate connections in separate PHP processes reserving at the boundary admit
     * exactly limit - current (Req 21.23, 14.36). PDO only.
     */
    public function testConcurrentReservationsAtTheBoundaryAdmitExactlyTheRemainder(): void
    {
        $dsn = $this->requireMysqlOrSkip();
        $pdo = $this->connect($dsn);
        $this->resetSchema($pdo);
        $repo = new PdoUsageRepository($pdo);
        $policy = new DailyLimitPolicy(10, 30, 100, 10);
        $current = 7;
        for ($i = 0; $i < $current; $i++) {
            $this->assertNull($repo->tryReserveJudge(self::DAY, $policy, self::NOW));
        }

        $workers = 8;
        $procs = [];
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $code = <<<'PHP'
require $argv[1];
$pdo = new PDO($argv[2], $argv[3], $argv[4], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$repo = new PoltergeistSwitch\TurnLoop\Repository\PdoUsageRepository($pdo);
$r = $repo->tryReserveJudge('2026-11-10', new PoltergeistSwitch\TurnLoop\DailyLimitPolicy(10, 30, 100, 10), '2026-11-10 12:00:00');
echo $r ?? 'ok';
PHP;
        $user = (string) (getenv('PS_TEST_MYSQL_USER') ?: '');
        $pass = (string) (getenv('PS_TEST_MYSQL_PASS') ?: '');
        for ($i = 0; $i < $workers; $i++) {
            $p = proc_open(
                [PHP_BINARY, '-r', $code, $autoload, $dsn, $user, $pass],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            $this->assertIsResource($p);
            $procs[] = [$p, $pipes];
        }
        $results = [];
        foreach ($procs as [$p, $pipes]) {
            $results[] = trim((string) stream_get_contents($pipes[1]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($p);
        }

        $admitted = count(array_filter($results, static fn (string $r): bool => $r === 'ok'));
        $denied = count(array_filter($results, static fn (string $r): bool => $r === 'daily_judge_limit'));
        $this->assertSame(10 - $current, $admitted, 'exactly limit - current are admitted: ' . implode(',', $results));
        $this->assertSame($workers - $admitted, $denied, 'the rest are denied');
        $this->assertSame(10, $repo->judgeCount(self::DAY));
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /** @return array<string, UsageRepository> */
    private function repositories(): array
    {
        $repos = ['fake' => new InMemoryUsageRepository()];

        $pdo = $this->connect($this->requireMysqlOrSkip());
        $this->resetSchema($pdo);
        $repos['pdo'] = new PdoUsageRepository($pdo);

        return $repos;
    }

    private function requireMysqlOrSkip(): string
    {
        $dsn = getenv('PS_TEST_MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            $this->markTestSkipped('no MySQL available (set PS_TEST_MYSQL_DSN to run)');
        }

        return $dsn;
    }

    private function connect(string $dsn): PDO
    {
        $user = (string) (getenv('PS_TEST_MYSQL_USER') ?: '');
        $pass = (string) (getenv('PS_TEST_MYSQL_PASS') ?: '');

        try {
            return new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL connection failed');
        }
    }

    /** Drop and recreate daily_usage (same shape as sql/2026-11-10_add_public_lab.sql). */
    private function resetSchema(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS daily_usage');
        $pdo->exec(
            'CREATE TABLE daily_usage ('
            . 'usage_date DATE NOT NULL, '
            . 'scope VARCHAR(8) NOT NULL, '
            . 'key_hash CHAR(64) NOT NULL, '
            . 'starts INT UNSIGNED NOT NULL DEFAULT 0, '
            . 'updated_at DATETIME NOT NULL, '
            . 'PRIMARY KEY (usage_date, scope, key_hash)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
