<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop\Repository;

use PDO;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;
use PoltergeistSwitch\TurnLoop\Repository\EpisodeRepository;
use PoltergeistSwitch\TurnLoop\Repository\PdoEpisodeRepository;
use PoltergeistSwitch\TurnLoop\Values\EpisodeRow;

/**
 * Gated PDO/MySQL test for the running-slot rule of PdoEpisodeRepository
 * (public-lab task 22.5, Requirement 19): an episode with no turn row of 1 or
 * more frees its slot after 60 s of silence, one with such a row after 300 s,
 * and an ended one never counts.
 *
 * Every case runs against the in-memory fake (always, on the real clock) and
 * the PDO repository (only when PS_TEST_MYSQL_DSN names a throwaway database),
 * so both give identical answers. Rows are inserted with chosen
 * last_activity_at values (now minus an age); nothing waits. The ages keep a
 * two-second margin from each boundary so the test does not depend on how long
 * it takes to run. The test drops and recreates the episodes and turns tables,
 * so the DSN must point at a throwaway database only.
 *
 * Requirements: 19.8, 19.9, 19.10, 19.11, 19.12, 14.30
 */
class RunningSlotPdoTest extends TestCase
{
    private const DEVICE = 'dev_a';

    /** An unasked episode counts just inside 60 s and not just outside it. */
    public function testUnaskedEpisodeBoundary(): void
    {
        foreach ($this->repositories() as $label => $repo) {
            $repo->createEpisode($this->episode(1, 58));
            $this->assertSame(1, $repo->countRunningEpisodes(), "{$label}: 58 s");
            $this->assertSame($this->id(1), $repo->findRunningEpisodeForDevice(self::DEVICE)?->id, "{$label}: 58 s");
        }
        foreach ($this->repositories() as $label => $repo) {
            $repo->createEpisode($this->episode(1, 62));
            $this->assertSame(0, $repo->countRunningEpisodes(), "{$label}: 62 s");
            $this->assertNull($repo->findRunningEpisodeForDevice(self::DEVICE), "{$label}: 62 s");
        }
    }

    /** An episode with a turn row of 1 or more counts to 300 s and not past it. */
    public function testAskedEpisodeBoundary(): void
    {
        foreach ($this->repositories() as $label => $repo) {
            $repo->createEpisode($this->episode(1, 298));
            $repo->createTurn($this->id(1), 0);
            $repo->createTurn($this->id(1), 1);
            $this->assertSame(1, $repo->countRunningEpisodes(), "{$label}: 298 s");
            $this->assertSame($this->id(1), $repo->findRunningEpisodeForDevice(self::DEVICE)?->id, "{$label}: 298 s");
        }
        foreach ($this->repositories() as $label => $repo) {
            $repo->createEpisode($this->episode(1, 302));
            $repo->createTurn($this->id(1), 0);
            $repo->createTurn($this->id(1), 1);
            $this->assertSame(0, $repo->countRunningEpisodes(), "{$label}: 302 s");
            $this->assertNull($repo->findRunningEpisodeForDevice(self::DEVICE), "{$label}: 302 s");
        }
    }

    /** A turn row 0 alone does not count as asked: the 60 s rule still applies. */
    public function testTurnZeroAloneStaysUnasked(): void
    {
        foreach ($this->repositories() as $label => $repo) {
            $repo->createEpisode($this->episode(1, 120));
            $repo->createTurn($this->id(1), 0);
            $this->assertSame(0, $repo->countRunningEpisodes(), "{$label}: 120 s with turn 0 only");
            $this->assertNull($repo->findRunningEpisodeForDevice(self::DEVICE), "{$label}: 120 s with turn 0 only");
        }
    }

    /** An ended episode never counts, however fresh. */
    public function testEndedEpisodeNeverCounts(): void
    {
        foreach ($this->repositories() as $label => $repo) {
            $repo->createEpisode($this->episode(1, 1, ended: true));
            $repo->createTurn($this->id(1), 1);
            $this->assertSame(0, $repo->countRunningEpisodes(), $label);
            $this->assertNull($repo->findRunningEpisodeForDevice(self::DEVICE), $label);
        }
    }

    /** A released episode comes back once activity is touched and a turn row exists. */
    public function testReleasedEpisodeRunsAgainAfterTouchWithTurnRow(): void
    {
        foreach ($this->repositories() as $label => $repo) {
            $repo->createEpisode($this->episode(1, 120));
            $this->assertSame(0, $repo->countRunningEpisodes(), "{$label}: released");

            $repo->createTurn($this->id(1), 1);
            $repo->touchActivity($this->id(1), date('Y-m-d H:i:s', time()));
            $this->assertSame(1, $repo->countRunningEpisodes(), "{$label}: running again");
            $this->assertSame($this->id(1), $repo->findRunningEpisodeForDevice(self::DEVICE)?->id, $label);
        }
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * The repositories under test, each starting empty: the fake always, the
     * PDO one only when a throwaway database is configured. Both read the real
     * clock, so a row's last activity is "now minus its age".
     *
     * @return array<string, EpisodeRepository>
     */
    private function repositories(): array
    {
        $dsn = getenv('PS_TEST_MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            $this->markTestSkipped('no MySQL available (set PS_TEST_MYSQL_DSN to run)');
        }
        $pdo = $this->connect($dsn);
        $this->resetSchema($pdo);

        return [
            'fake' => new InMemoryEpisodeRepository(),
            'pdo' => new PdoEpisodeRepository($pdo),
        ];
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

    /** Drop and recreate episodes and turns (same shape as sql/turn_loop.sql plus the heat column). */
    private function resetSchema(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS turns');
        $pdo->exec('DROP TABLE IF EXISTS episodes');
        $pdo->exec(
            'CREATE TABLE episodes ('
            . 'id CHAR(36) NOT NULL, '
            . 'device_id VARCHAR(64) NOT NULL, '
            . "disaster VARCHAR(16) NOT NULL DEFAULT 'earthquake', "
            . 'seed BIGINT NOT NULL, '
            . 'current_expected_turn INT NOT NULL DEFAULT 0, '
            . 'earthquake_start_turn INT NULL, '
            . 'ended TINYINT(1) NOT NULL DEFAULT 0, '
            . 'created_at DATETIME NOT NULL, '
            . 'last_activity_at DATETIME NOT NULL, '
            . 'heat_wave_start_turn INT NULL, '
            . 'PRIMARY KEY (id), '
            . 'KEY idx_running (ended, last_activity_at), '
            . 'KEY idx_device (device_id, ended)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE turns ('
            . 'episode_id CHAR(36) NOT NULL, '
            . 'turn_number INT NOT NULL, '
            . "state VARCHAR(16) NOT NULL DEFAULT 'waiting', "
            . 'attempts_a INT NOT NULL DEFAULT 0, '
            . 'attempts_b INT NOT NULL DEFAULT 0, '
            . 'no_reply_a TINYINT(1) NOT NULL DEFAULT 0, '
            . 'no_reply_b TINYINT(1) NOT NULL DEFAULT 0, '
            . 'resident_line TEXT NULL, '
            . 'world_json MEDIUMTEXT NULL, '
            . 'created_at DATETIME NOT NULL, '
            . 'PRIMARY KEY (episode_id, turn_number)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** A fixed 36-character id so both repositories keep the same value. */
    private function id(int $n): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $n);
    }

    /** An episode whose last activity was $ageSeconds ago. */
    private function episode(int $n, int $ageSeconds, bool $ended = false): EpisodeRow
    {
        $at = date('Y-m-d H:i:s', time() - $ageSeconds);

        return new EpisodeRow(
            id: $this->id($n),
            deviceId: self::DEVICE,
            disaster: 'earthquake',
            seed: $n,
            currentExpectedTurn: 0,
            earthquakeStartTurn: null,
            ended: $ended,
            createdAt: $at,
            lastActivityAt: $at,
        );
    }
}
