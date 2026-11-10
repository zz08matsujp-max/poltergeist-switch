<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Memory\Repository;

use PDO;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Memory\Values\MemoryRow;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;
use PoltergeistSwitch\TurnLoop\Repository\EpisodeRepository;
use PoltergeistSwitch\TurnLoop\Repository\PdoEpisodeRepository;

/**
 * Gated PDO/MySQL tests for the Device-scoped memory methods of
 * PdoEpisodeRepository (public-lab task 5.1).
 *
 * Every test runs the same scenario against the in-memory fake (always) and the
 * PDO repository (only when PS_TEST_MYSQL_DSN names a throwaway database), so
 * the two implementations are held to the same expected values. The PDO half
 * skips without the DSN, like the other PDO tests. The test drops and recreates
 * the memory tables, so the DSN must point at a throwaway database only.
 *
 * Requirements: 2.10, 2.11, 5.5, 14.14
 */
class DeviceRepositoryPdoTest extends TestCase
{
    private const CAP = 6;
    private const DEVICE = 'dev_a';
    private const OTHER = 'dev_b';

    /**
     * Retire-oldest regression (design C1): the oldest surplus is retired and
     * the newest 6 stay active, oldest first (Req 2.10, 14.14).
     */
    public function testOldestSurplusIsRetiredAndNewestSixStayActive(): void
    {
        foreach ($this->repositories() as $label => $repo) {
            for ($i = 1; $i <= 9; $i++) {
                $repo->appendMemory($this->row("ep_{$i}", "m{$i}"));
            }

            $this->assertSame(self::CAP, $repo->activeCount(self::DEVICE, 'robot_a', 1, 'earthquake'), $label);
            $this->assertSame(
                ['m4', 'm5', 'm6', 'm7', 'm8', 'm9'],
                $repo->activeMemories(self::DEVICE, 'robot_a', 1, 18)->bodies,
                "{$label}: the newest 6 stay active and the oldest surplus is retired"
            );
        }
    }

    /**
     * The same retire order holds for the reflection commit path
     * (commitReflectionAnswer), not only appendMemory (Req 2.10).
     */
    public function testCommitReflectionAnswerRetiresTheOldestSurplus(): void
    {
        foreach ($this->repositories() as $label => $repo) {
            for ($i = 1; $i <= 8; $i++) {
                $episode = "ep_{$i}";
                $this->assertTrue($repo->claimReflectionSlot($episode, 'robot_a')->isClaimed(), $label);
                $repo->commitReflectionAnswer($episode, 'robot_a', $this->row($episode, "m{$i}"));
            }

            $this->assertSame(
                ['m3', 'm4', 'm5', 'm6', 'm7', 'm8'],
                $repo->activeMemories(self::DEVICE, 'robot_a', 1, 18)->bodies,
                $label
            );
        }
    }

    /** Device isolation: another Device's cap, reads and retirement are separate (Req 2.10, 2.11). */
    public function testDevicesAreIsolated(): void
    {
        foreach ($this->repositories() as $label => $repo) {
            for ($i = 1; $i <= 8; $i++) {
                $repo->appendMemory($this->row("ep_a_{$i}", "a{$i}"));
            }
            $repo->appendMemory($this->row('ep_b_1', 'b1', deviceId: self::OTHER));

            $this->assertSame(self::CAP, $repo->activeCount(self::DEVICE, 'robot_a', 1, 'earthquake'), $label);
            $this->assertSame(1, $repo->activeCount(self::OTHER, 'robot_a', 1, 'earthquake'), $label);
            $this->assertSame(['b1'], $repo->activeMemories(self::OTHER, 'robot_a', 1, 18)->bodies, $label);
            $this->assertNotContains('b1', $repo->activeMemories(self::DEVICE, 'robot_a', 1, 18)->bodies, $label);
            $this->assertSame(0, $repo->activeCount('dev_unknown', 'robot_a', 1, 'earthquake'), $label);
        }
    }

    /** A row with no owner is ignored by every read and never counts toward a cap (Req 2.6, 5.2). */
    public function testRowsWithNoDeviceAreIgnored(): void
    {
        foreach ($this->repositories() as $label => $repo) {
            for ($i = 1; $i <= 8; $i++) {
                $repo->appendMemory($this->row("ep_legacy_{$i}", "legacy{$i}", deviceId: null));
            }
            $repo->appendMemory($this->row('ep_a_1', 'a1'));

            $this->assertSame(1, $repo->activeCount(self::DEVICE, 'robot_a', 1, 'earthquake'), $label);
            $this->assertSame(['a1'], $repo->activeMemories(self::DEVICE, 'robot_a', 1, 18)->bodies, $label);
            $this->assertSame([], $repo->activeMemories(self::OTHER, 'robot_a', 1, 18)->bodies, $label);
        }
    }

    /** A reset raises only that Device's generation and reports its latest time (Req 2.10, 5.5). */
    public function testResetRaisesOnlyThatDevicesGeneration(): void
    {
        foreach ($this->repositories() as $label => $repo) {
            $this->assertSame(1, $repo->deviceGeneration(self::DEVICE), $label);
            $this->assertNull($repo->latestDeviceResetAt(self::DEVICE), $label);

            $this->assertSame(2, $repo->recordDeviceReset(self::DEVICE, '2026-11-10 10:00:00'), $label);
            $this->assertSame(3, $repo->recordDeviceReset(self::DEVICE, '2026-11-10 11:00:00'), $label);

            $this->assertSame(3, $repo->deviceGeneration(self::DEVICE), $label);
            $this->assertSame(1, $repo->deviceGeneration(self::OTHER), $label);
            $this->assertSame('2026-11-10 11:00:00', $repo->latestDeviceResetAt(self::DEVICE), $label);
            $this->assertNull($repo->latestDeviceResetAt(self::OTHER), $label);
        }
    }

    /**
     * A reset appends exactly one device_resets row and updates or deletes
     * nothing in memories (Req 2.10, 5.5). SQL-level check, PDO only.
     */
    public function testResetAppendsOneRowAndUpdatesNone(): void
    {
        $pdo = $this->connect($this->requireMysqlOrSkip());
        $this->resetSchema($pdo);
        $repo = new PdoEpisodeRepository($pdo);

        for ($i = 1; $i <= 3; $i++) {
            $repo->appendMemory($this->row("ep_{$i}", "m{$i}"));
        }
        $repo->recordDeviceReset(self::OTHER, '2026-11-10 09:00:00');

        $memoriesBefore = $this->dump($pdo, 'SELECT * FROM memories ORDER BY id');
        $resetsBefore = $this->dump($pdo, 'SELECT * FROM device_resets ORDER BY id');
        $this->assertCount(1, $resetsBefore);

        $generation = $repo->recordDeviceReset(self::DEVICE, '2026-11-10 10:00:00');

        $this->assertSame(2, $generation);
        $resetsAfter = $this->dump($pdo, 'SELECT * FROM device_resets ORDER BY id');
        $this->assertCount(2, $resetsAfter, 'exactly one row is appended');
        $this->assertSame($resetsBefore[0], $resetsAfter[0], 'the earlier reset row is not updated');
        $this->assertSame(self::DEVICE, $resetsAfter[1]['device_id']);
        $this->assertSame(
            $memoriesBefore,
            $this->dump($pdo, 'SELECT * FROM memories ORDER BY id'),
            'a reset leaves every memory row unchanged and deletes none'
        );

        // The Device's reads now use the new generation: nothing is visible.
        $this->assertSame(0, $repo->activeCount(self::DEVICE, 'robot_a', 2, 'earthquake'));
    }

    /**
     * Retirement marks rows retired, never deletes them (Req 2.8, 2.10).
     * SQL-level check, PDO only.
     */
    public function testRetiredRowsAreKeptNotDeleted(): void
    {
        $pdo = $this->connect($this->requireMysqlOrSkip());
        $this->resetSchema($pdo);
        $repo = new PdoEpisodeRepository($pdo);

        for ($i = 1; $i <= 8; $i++) {
            $repo->appendMemory($this->row("ep_{$i}", "m{$i}"));
        }

        $rows = $this->dump($pdo, 'SELECT body, status FROM memories ORDER BY id');
        $this->assertCount(8, $rows, 'no row is ever deleted');
        $this->assertSame(
            ['retired', 'retired', 'active', 'active', 'active', 'active', 'active', 'active'],
            array_column($rows, 'status')
        );
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
        $repos = ['fake' => new InMemoryEpisodeRepository()];

        $dsn = getenv('PS_TEST_MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            $this->markTestSkipped('no MySQL available (set PS_TEST_MYSQL_DSN to run)');
        }
        $pdo = $this->connect($dsn);
        $this->resetSchema($pdo);
        $repos['pdo'] = new PdoEpisodeRepository($pdo);

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

    /** @return list<array<string, mixed>> */
    private function dump(PDO $pdo, string $sql): array
    {
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Drop and recreate the memory tables (same shape as EpisodeRepositoryTest). */
    private function resetSchema(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS reflections');
        $pdo->exec('DROP TABLE IF EXISTS memories');
        $pdo->exec('DROP TABLE IF EXISTS memory_generation');
        $pdo->exec('DROP TABLE IF EXISTS device_resets');

        $pdo->exec(
            'CREATE TABLE memories ('
            . 'id BIGINT NOT NULL AUTO_INCREMENT, '
            . 'robot_id VARCHAR(16) NOT NULL, '
            . 'generation INT NOT NULL DEFAULT 1, '
            . "disaster VARCHAR(16) NOT NULL DEFAULT 'earthquake', "
            . 'source_episode_id CHAR(36) NOT NULL, '
            . 'body MEDIUMTEXT NOT NULL, '
            . "status VARCHAR(8) NOT NULL DEFAULT 'active', "
            . 'created_at DATETIME NOT NULL, '
            . 'retired_at DATETIME NULL, '
            . 'device_id VARCHAR(64) NULL, '
            . 'PRIMARY KEY (id), '
            . 'KEY idx_active_pick (robot_id, disaster, generation, status, id), '
            . 'KEY idx_snapshot (robot_id, generation, status, id), '
            . 'KEY idx_device_snapshot (device_id, robot_id, generation, status, id), '
            . 'KEY idx_device_pick (device_id, robot_id, disaster, generation, status, id), '
            . 'UNIQUE KEY uq_source (source_episode_id, robot_id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE memory_generation ('
            . 'id TINYINT NOT NULL DEFAULT 1, '
            . 'generation INT NOT NULL DEFAULT 1, '
            . 'updated_at DATETIME NOT NULL, '
            . 'PRIMARY KEY (id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'INSERT INTO memory_generation (id, generation, updated_at) '
            . 'VALUES (1, 1, UTC_TIMESTAMP())'
        );
        $pdo->exec(
            'CREATE TABLE device_resets ('
            . 'id BIGINT NOT NULL AUTO_INCREMENT, '
            . 'device_id VARCHAR(64) NOT NULL, '
            . 'reset_at DATETIME NOT NULL, '
            . 'PRIMARY KEY (id), '
            . 'KEY idx_device (device_id, id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE reflections ('
            . 'episode_id CHAR(36) NOT NULL, '
            . 'robot_id VARCHAR(16) NOT NULL, '
            . "status VARCHAR(12) NOT NULL DEFAULT 'in_progress', "
            . 'answered_memory_id BIGINT NULL, '
            . 'reason VARCHAR(32) NULL, '
            . 'claimed_at DATETIME NOT NULL, '
            . 'PRIMARY KEY (episode_id, robot_id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function row(
        string $sourceEpisodeId,
        string $body,
        string $robotId = 'robot_a',
        int $generation = 1,
        string $disaster = 'earthquake',
        ?string $deviceId = self::DEVICE,
    ): MemoryRow {
        return new MemoryRow(
            robotId: $robotId,
            generation: $generation,
            disaster: $disaster,
            sourceEpisodeId: $sourceEpisodeId,
            body: $body,
            status: 'active',
            createdAt: '2026-11-09 12:00:00',
            deviceId: $deviceId,
        );
    }
}
