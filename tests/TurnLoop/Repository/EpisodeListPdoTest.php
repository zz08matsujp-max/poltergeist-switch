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
 * Gated PDO/MySQL tests for the two list queries of PdoEpisodeRepository
 * (public-lab task 8.7): listEndedEpisodesByIds and listEndedEpisodesForDevice.
 *
 * Every test runs the same scenario against the in-memory fake (always) and the
 * PDO repository (only when PS_TEST_MYSQL_DSN names a throwaway database), so
 * both are held to the same expected values. The PDO half skips without the
 * DSN. The test drops and recreates the episodes table, so the DSN must point
 * at a throwaway database only.
 *
 * Requirements: 6.2, 6.3, 6.5, 6.7
 */
class EpisodeListPdoTest extends TestCase
{
    private const DEVICE = 'dev_a';
    private const OTHER = 'dev_b';

    /** Ended episodes come back in the caller's order; unknown and running ones are absent (Req 6.2). */
    public function testByIdsKeepsCallerOrderAndDropsUnknownAndRunning(): void
    {
        foreach ($this->repositories() as $label => $repo) {
            $repo->createEpisode($this->episode(1, '2026-11-01 10:00:00'));
            $repo->createEpisode($this->episode(2, '2026-11-02 10:00:00'));
            $repo->createEpisode($this->episode(3, '2026-11-03 10:00:00', ended: false));

            $ids = $this->ids($repo->listEndedEpisodesByIds([
                $this->id(2),
                $this->id(99),
                $this->id(3),
                $this->id(1),
            ]));

            $this->assertSame([$this->id(2), $this->id(1)], $ids, $label);
        }
    }

    /** Repeated ids appear once; an empty list gives an empty result (Req 6.2). */
    public function testByIdsDeduplicatesAndHandlesEmpty(): void
    {
        foreach ($this->repositories() as $label => $repo) {
            $repo->createEpisode($this->episode(1, '2026-11-01 10:00:00'));

            $this->assertSame(
                [$this->id(1)],
                $this->ids($repo->listEndedEpisodesByIds([$this->id(1), $this->id(1)])),
                $label
            );
            $this->assertSame([], $repo->listEndedEpisodesByIds([]), $label);
        }
    }

    /** Only the Device's ended episodes, newest first, id descending on a tie (Req 6.3, 6.7). */
    public function testForDeviceReturnsOnlyOwnEndedNewestFirst(): void
    {
        foreach ($this->repositories() as $label => $repo) {
            $repo->createEpisode($this->episode(1, '2026-11-01 10:00:00'));
            $repo->createEpisode($this->episode(2, '2026-11-03 10:00:00'));
            $repo->createEpisode($this->episode(3, '2026-11-03 10:00:00'));
            $repo->createEpisode($this->episode(4, '2026-11-04 10:00:00', ended: false));
            $repo->createEpisode($this->episode(5, '2026-11-05 10:00:00', device: self::OTHER));

            $ids = $this->ids($repo->listEndedEpisodesForDevice(self::DEVICE, null, [], 50));

            $this->assertSame([$this->id(3), $this->id(2), $this->id(1)], $ids, $label);
            $this->assertSame([], $repo->listEndedEpisodesForDevice('dev_unknown', null, [], 50), $label);
        }
    }

    /** Episodes created at or before the reset time are hidden; later ones stay (Req 6.3, 6.5). */
    public function testForDeviceHonoursTheResetTime(): void
    {
        foreach ($this->repositories() as $label => $repo) {
            $repo->createEpisode($this->episode(1, '2026-11-01 10:00:00'));
            $repo->createEpisode($this->episode(2, '2026-11-02 10:00:00'));
            $repo->createEpisode($this->episode(3, '2026-11-03 10:00:00'));

            $ids = $this->ids($repo->listEndedEpisodesForDevice(self::DEVICE, '2026-11-02 10:00:00', [], 50));

            $this->assertSame([$this->id(3)], $ids, $label);
        }
    }

    /** Excluded (Featured) ids are left out, and the limit caps the newest rows (Req 6.3, 6.7). */
    public function testForDeviceExcludesIdsAndAppliesTheLimit(): void
    {
        foreach ($this->repositories() as $label => $repo) {
            for ($i = 1; $i <= 5; $i++) {
                $repo->createEpisode($this->episode($i, "2026-11-0{$i} 10:00:00"));
            }

            $this->assertSame(
                [$this->id(4), $this->id(2), $this->id(1)],
                $this->ids($repo->listEndedEpisodesForDevice(self::DEVICE, null, [$this->id(5), $this->id(3)], 50)),
                "{$label}: excluded ids are absent"
            );
            $this->assertSame(
                [$this->id(5), $this->id(4)],
                $this->ids($repo->listEndedEpisodesForDevice(self::DEVICE, null, [], 2)),
                "{$label}: the limit keeps the newest"
            );
            $this->assertSame(
                [$this->id(4), $this->id(2)],
                $this->ids($repo->listEndedEpisodesForDevice(self::DEVICE, null, [$this->id(5), $this->id(3)], 2)),
                "{$label}: exclusion applies before the limit"
            );
        }
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * The repositories under test, each starting empty: the fake always, the
     * PDO one only when a throwaway database is configured.
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

    /** Drop and recreate episodes (same shape as sql/turn_loop.sql plus the heat column). */
    private function resetSchema(PDO $pdo): void
    {
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
    }

    /** A fixed 36-character id so both repositories keep the same value. */
    private function id(int $n): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $n);
    }

    private function episode(
        int $n,
        string $createdAt,
        bool $ended = true,
        string $device = self::DEVICE,
    ): EpisodeRow {
        return new EpisodeRow(
            id: $this->id($n),
            deviceId: $device,
            disaster: 'earthquake',
            seed: $n,
            currentExpectedTurn: 0,
            earthquakeStartTurn: null,
            ended: $ended,
            createdAt: $createdAt,
            lastActivityAt: $createdAt,
        );
    }

    /**
     * @param EpisodeRow[] $episodes
     * @return string[]
     */
    private function ids(array $episodes): array
    {
        return array_map(static fn (EpisodeRow $e): string => $e->id, $episodes);
    }
}
