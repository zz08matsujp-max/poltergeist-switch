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
 * Gated PDO/MySQL tests for PdoUsageRepository (public-lab task 6.6).
 *
 * Every case runs against the in-memory fake (always) and the PDO repository
 * (only when PS_TEST_MYSQL_DSN names a throwaway database), so both are held to
 * the same expected values. The test drops and recreates daily_usage, so the
 * DSN must point at a throwaway database only. Without the DSN every test skips.
 *
 * Requirements: 13.4, 13.5, 13.6, 13.9
 */
class UsageRepositoryPdoTest extends TestCase
{
    private const DAY = '2026-11-10';
    private const NOW = '2026-11-10 12:00:00';

    private static function hash(string $c): string
    {
        return str_repeat($c, 64);
    }

    private function keys(string $device = 'a', ?string $ip = 'b'): UsageKeys
    {
        return new UsageKeys(self::hash($device), $ip === null ? null : self::hash($ip));
    }

    /** Device limit: the 10th start passes and the 11th is denied with nothing counted (Req 13.4). */
    public function testDeviceLimit(): void
    {
        foreach ($this->repositories() as $label => $repo) {
            $policy = new DailyLimitPolicy(10, 30, 100);
            for ($i = 1; $i <= 10; $i++) {
                $this->assertNull($repo->tryReserve(self::DAY, $this->keys(), $policy, self::NOW), "$label #$i");
            }
            $this->assertSame('daily_device_limit', $repo->tryReserve(self::DAY, $this->keys(), $policy, self::NOW), $label);
            $this->assertSame(['device' => 10, 'ip' => 10, 'site' => 10], $repo->counts(self::DAY, $this->keys()), $label);
        }
    }

    /** IP limit applies across Devices sharing an IP (Req 13.5). */
    public function testIpLimit(): void
    {
        foreach ($this->repositories() as $label => $repo) {
            $policy = new DailyLimitPolicy(10, 3, 100);
            foreach (['c', 'd', 'e'] as $device) {
                $this->assertNull($repo->tryReserve(self::DAY, $this->keys($device), $policy, self::NOW), $label);
            }
            $this->assertSame('daily_ip_limit', $repo->tryReserve(self::DAY, $this->keys('f'), $policy, self::NOW), $label);
            $this->assertSame(0, $repo->counts(self::DAY, $this->keys('f'))['device'], "$label: denied start leaves no device count");
            $this->assertSame(3, $repo->counts(self::DAY, $this->keys('f'))['ip'], $label);
        }
    }

    /** Site limit applies across everything; no IP scope when the IP is unknown (Req 13.6). */
    public function testSiteLimitAndUntrackedIp(): void
    {
        foreach ($this->repositories() as $label => $repo) {
            $policy = new DailyLimitPolicy(10, 1, 2);
            $this->assertNull($repo->tryReserve(self::DAY, $this->keys('c', null), $policy, self::NOW), $label);
            $this->assertNull($repo->tryReserve(self::DAY, $this->keys('d', null), $policy, self::NOW), $label);
            $this->assertSame('daily_site_limit', $repo->tryReserve(self::DAY, $this->keys('e', null), $policy, self::NOW), $label);
            $this->assertSame(['device' => 0, 'ip' => null, 'site' => 2], $repo->counts(self::DAY, $this->keys('e', null)), $label);
        }
    }

    /** Denial order: device, then ip, then site (Req 13.4 to 13.6). */
    public function testDenialOrder(): void
    {
        foreach ($this->repositories() as $label => $repo) {
            $policy = new DailyLimitPolicy(1, 1, 1);
            $this->assertNull($repo->tryReserve(self::DAY, $this->keys(), $policy, self::NOW), $label);
            $this->assertSame('daily_device_limit', $repo->tryReserve(self::DAY, $this->keys(), $policy, self::NOW), $label);
            $this->assertSame('daily_ip_limit', $repo->tryReserve(self::DAY, $this->keys('c', 'b'), $policy, self::NOW), $label);
            $this->assertSame('daily_site_limit', $repo->tryReserve(self::DAY, $this->keys('c', 'd'), $policy, self::NOW), $label);
        }
    }

    /** A new UTC day starts from zero (Req 13.9). */
    public function testCountsRestartOnANewDay(): void
    {
        foreach ($this->repositories() as $label => $repo) {
            $policy = new DailyLimitPolicy(1, 1, 1);
            $this->assertNull($repo->tryReserve(self::DAY, $this->keys(), $policy, self::NOW), $label);
            $this->assertNull($repo->tryReserve('2026-11-11', $this->keys(), $policy, '2026-11-11 00:00:00'), $label);
            $this->assertSame(1, $repo->counts(self::DAY, $this->keys())['site'], $label);
            $this->assertSame(1, $repo->counts('2026-11-11', $this->keys())['site'], $label);
        }
    }

    /** Release gives back one on every scope and never goes below zero (Req 13.9). */
    public function testReleaseNeverGoesBelowZero(): void
    {
        foreach ($this->repositories() as $label => $repo) {
            $policy = new DailyLimitPolicy(10, 30, 100);
            $repo->release(self::DAY, $this->keys(), self::NOW);
            $this->assertSame(['device' => 0, 'ip' => 0, 'site' => 0], $repo->counts(self::DAY, $this->keys()), $label);

            $repo->tryReserve(self::DAY, $this->keys(), $policy, self::NOW);
            $repo->tryReserve(self::DAY, $this->keys(), $policy, self::NOW);
            $repo->release(self::DAY, $this->keys(), self::NOW);
            $this->assertSame(['device' => 1, 'ip' => 1, 'site' => 1], $repo->counts(self::DAY, $this->keys()), $label);
            $repo->release(self::DAY, $this->keys(), self::NOW);
            $repo->release(self::DAY, $this->keys(), self::NOW);
            $this->assertSame(['device' => 0, 'ip' => 0, 'site' => 0], $repo->counts(self::DAY, $this->keys()), $label);
        }
    }

    /**
     * Separate connections, run in separate PHP processes at the same time, reserving
     * at the boundary admit exactly limit - current (Req 13.4, 13.9). PDO only.
     */
    public function testConcurrentReservationsAtTheBoundaryAdmitExactlyTheRemainder(): void
    {
        $dsn = $this->requireMysqlOrSkip();
        $pdo = $this->connect($dsn);
        $this->resetSchema($pdo);
        $repo = new PdoUsageRepository($pdo);
        $policy = new DailyLimitPolicy(10, 30, 100);
        $current = 7;
        for ($i = 0; $i < $current; $i++) {
            $this->assertNull($repo->tryReserve(self::DAY, $this->keys(), $policy, self::NOW));
        }

        $workers = 8;
        $procs = [];
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $code = <<<'PHP'
require $argv[1];
$pdo = new PDO($argv[2], $argv[3], $argv[4], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$repo = new PoltergeistSwitch\TurnLoop\Repository\PdoUsageRepository($pdo);
$keys = new PoltergeistSwitch\TurnLoop\UsageKeys(str_repeat('a', 64), str_repeat('b', 64));
$r = $repo->tryReserve('2026-11-10', $keys, new PoltergeistSwitch\TurnLoop\DailyLimitPolicy(10, 30, 100), '2026-11-10 12:00:00');
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
        $denied = count(array_filter($results, static fn (string $r): bool => $r === 'daily_device_limit'));
        $this->assertSame(10 - $current, $admitted, 'exactly limit - current are admitted: ' . implode(',', $results));
        $this->assertSame($workers - ($admitted), $denied, 'the rest are denied');
        $this->assertSame(10, $repo->counts(self::DAY, $this->keys())['device']);
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
