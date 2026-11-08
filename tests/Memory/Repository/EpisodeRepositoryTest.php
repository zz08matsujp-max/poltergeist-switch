<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Memory\Repository;

use PDO;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Memory\Values\MemoryRow;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;
use PoltergeistSwitch\TurnLoop\Repository\PdoEpisodeRepository;

/**
 * Unit tests for the robot-memory EpisodeRepository additions (task 4.5).
 *
 * The in-memory fake is the required path and carries the full logic coverage
 * (mirroring the turn-loop spec): the atomic store that commits only after
 * insert + retire, the idempotent store per (source episode, robot), and the
 * reflection claim's stale-recovery / fresh-skip semantics. The fake's clock is
 * injectable (constructor `?callable $clock`) so staleness is driven
 * deterministically without sleeping.
 *
 * The PDO/MySQL locking and atomicity tests exercise the real two-connection
 * row-lock behaviour the design relies on, but are written to SKIP when no
 * MySQL test database is available (guarded by the PS_TEST_MYSQL_DSN
 * environment variable, the same gating the turn-loop PDO tests use), so the
 * suite stays green on a machine without a database.
 *
 * Requirements: 8.4, 8.5, 2.8, 2.9, 2.10
 */
class EpisodeRepositoryTest extends TestCase
{
    /** The per-(robot, disaster, generation) active cap (Requirements 3.3, 8.4). */
    private const TRIPLE_CAP = 6;

    /** A claim at least this many seconds old is stale and re-claimable (Req 2.10). */
    private const STALE_SECONDS = 120;

    // =====================================================================
    // In-memory fake — required behaviour
    // =====================================================================

    /**
     * The atomic store commits only after insert + retire: once a seventh
     * memory for one (robot, disaster, generation) triple is stored the active
     * count settles at exactly the cap of 6, the oldest active row is retired
     * (not deleted), and the retired body never appears in any read
     * (Requirements 8.4, 3.4, 3.5).
     */
    public function testAtomicStoreCommitsOnlyAfterInsertAndRetire(): void
    {
        $repo = new InMemoryEpisodeRepository();

        // Store the cap plus one for the same triple; each store appends then
        // retires down to 6 in the same atomic operation.
        for ($i = 1; $i <= self::TRIPLE_CAP + 1; $i++) {
            $repo->appendMemory($this->row(
                sourceEpisodeId: "ep_{$i}",
                body: "memory number {$i}",
            ));

            // After every committed store the active count never exceeds the
            // cap: the store has both inserted and retired before returning.
            $this->assertLessThanOrEqual(
                self::TRIPLE_CAP,
                $repo->activeCount('robot_a', 1, 'earthquake'),
                'the active count must never exceed the cap after a committed store'
            );
        }

        // The committed state is exactly the cap: 7 stored, 1 retired.
        $this->assertSame(
            self::TRIPLE_CAP,
            $repo->activeCount('robot_a', 1, 'earthquake'),
            'a seventh store must leave exactly the cap active'
        );

        // The surviving active bodies are the newest 6, oldest first; the very
        // first (oldest) body was retired and no longer reads back.
        $snapshot = $repo->activeMemories('robot_a', 1, 18);
        $this->assertSame(
            [
                'memory number 2',
                'memory number 3',
                'memory number 4',
                'memory number 5',
                'memory number 6',
                'memory number 7',
            ],
            $snapshot->bodies,
            'the oldest active memory must be retired and excluded; the newest 6 remain oldest first'
        );
        $this->assertNotContains(
            'memory number 1',
            $snapshot->bodies,
            'a retired memory must never appear in any read'
        );
    }

    /**
     * A duplicate store for the same source episode + robot creates no
     * duplicate: the second appendMemory for the same (sourceEpisodeId,
     * robotId) leaves the store unchanged, mirroring the PDO uq_source guard
     * (Requirement 8.5).
     */
    public function testDuplicateStoreForSameSourceAndRobotCreatesNoDuplicate(): void
    {
        $repo = new InMemoryEpisodeRepository();

        $memory = $this->row(sourceEpisodeId: 'ep_dup', body: 'remember the kettle');

        $repo->appendMemory($memory);
        $this->assertSame(1, $repo->activeCount('robot_a', 1, 'earthquake'));

        // A second store of the SAME source episode + robot is a no-op.
        $repo->appendMemory($memory);
        $this->assertSame(
            1,
            $repo->activeCount('robot_a', 1, 'earthquake'),
            'a duplicate store for the same source episode + robot must create no duplicate'
        );

        // A re-store with a different body but the same source+robot is still a
        // no-op: the first stored body is kept verbatim.
        $repo->appendMemory($this->row(sourceEpisodeId: 'ep_dup', body: 'a different body'));
        $snapshot = $repo->activeMemories('robot_a', 1, 18);
        $this->assertSame(
            ['remember the kettle'],
            $snapshot->bodies,
            'the idempotency guard keys on (source episode, robot), never on the body'
        );

        // The same source episode for the OTHER robot is a distinct store.
        $repo->appendMemory($this->row(
            robotId: 'robot_b',
            sourceEpisodeId: 'ep_dup',
            body: 'the other robot remembers',
        ));
        $this->assertSame(
            1,
            $repo->activeCount('robot_b', 1, 'earthquake'),
            'the same source episode for the other robot is a separate store'
        );
    }

    /**
     * A stale in_progress claim (older than 120 s) is re-claimable, while a
     * fresh one returns skip. The fake's injectable clock drives "now" so the
     * 120 s window is crossed deterministically (Requirements 2.9, 2.10).
     */
    public function testStaleInProgressIsReclaimableWhileFreshSkips(): void
    {
        $now = 1_000_000;
        $clock = function () use (&$now): int {
            return $now;
        };
        $repo = new InMemoryEpisodeRepository(clock: $clock);

        // First caller claims a fresh slot.
        $first = $repo->claimReflectionSlot('ep_1', 'robot_a');
        $this->assertTrue($first->isClaimed(), 'the first claim of a fresh slot must be granted');

        // A concurrent caller a few seconds later sees a FRESH in_progress and
        // skips so the robot is not double-asked (Requirement 2.9).
        $now += 10;
        $concurrent = $repo->claimReflectionSlot('ep_1', 'robot_a');
        $this->assertTrue(
            $concurrent->isSkip(),
            'a fresh in_progress claim must make a concurrent caller skip'
        );

        // Advance past the 120 s stale window: the owner is assumed dead and
        // the slot becomes re-claimable (Requirement 2.10).
        $now += self::STALE_SECONDS;
        $reclaim = $repo->claimReflectionSlot('ep_1', 'robot_a');
        $this->assertTrue(
            $reclaim->isClaimed(),
            'a stale in_progress claim (older than 120 s) must be re-claimable'
        );

        // Immediately after the re-claim the slot is fresh again, so a further
        // concurrent caller skips once more.
        $now += 5;
        $again = $repo->claimReflectionSlot('ep_1', 'robot_a');
        $this->assertTrue(
            $again->isSkip(),
            'the re-claimed slot is fresh again, so a concurrent caller skips'
        );
    }

    // =====================================================================
    // PDO/MySQL — locking and atomicity (SKIP when no MySQL available)
    // =====================================================================

    /**
     * The two-connection locking test: a second claimReflectionSlot on a
     * second connection blocks on the row lock held by the first connection's
     * open transaction, then — once the first commits as `answered` — reads the
     * committed state and reuses the stored answer rather than claiming
     * (Requirement 2.9). Skipped when no MySQL test database is available.
     */
    public function testPdoSecondClaimBlocksThenReadsCommittedState(): void
    {
        $dsnA = $this->requireMysqlOrSkip();
        $pdoA = $this->connect($dsnA);
        $pdoB = $this->connect($dsnA);

        $this->resetMemorySchema($pdoA);

        $repoA = new PdoEpisodeRepository($pdoA);

        // Connection A claims the fresh slot (its own short transaction commits
        // inside claimReflectionSlot), then commits an answer, linking a stored
        // memory to the slot.
        $claimA = $repoA->claimReflectionSlot('ep_lock', 'robot_a');
        $this->assertTrue($claimA->isClaimed(), 'connection A owns the fresh claim');

        $repoA->commitReflectionAnswer('ep_lock', 'robot_a', $this->row(
            sourceEpisodeId: 'ep_lock',
            body: 'A wrote this down',
        ));

        // Connection B now claims: it serialises on the reflections row, sees
        // the committed `answered` state, and reuses A's stored body rather
        // than issuing a second call (Requirement 2.9).
        $repoB = new PdoEpisodeRepository($pdoB);
        $claimB = $repoB->claimReflectionSlot('ep_lock', 'robot_a');

        $this->assertTrue(
            $claimB->isReuse(),
            'the second claim must read the committed answered state and reuse it'
        );
        $this->assertSame(
            'A wrote this down',
            $claimB->reusedBody,
            'the reused body must be the verbatim answer the first connection committed'
        );
    }

    /**
     * The two per-triple commits serialise to exactly 6 active: two
     * appendMemory commits for the same (robot, disaster, generation), each
     * from a different source episode, starting from a full cap of 6, serialise
     * on the triple's FOR UPDATE lock so the committed active count settles at
     * exactly 6 — never 7 (Requirement 8.4). Skipped when no MySQL available.
     */
    public function testPdoTwoPerTripleCommitsSerializeToExactlySix(): void
    {
        $dsn = $this->requireMysqlOrSkip();
        $pdo = $this->connect($dsn);
        $this->resetMemorySchema($pdo);

        $repo = new PdoEpisodeRepository($pdo);

        // Fill the triple to the cap of 6.
        for ($i = 1; $i <= self::TRIPLE_CAP; $i++) {
            $repo->appendMemory($this->row(
                sourceEpisodeId: "ep_seed_{$i}",
                body: "seed {$i}",
            ));
        }
        $this->assertSame(
            self::TRIPLE_CAP,
            $repo->activeCount('robot_a', 1, 'earthquake'),
            'the triple must start at the cap'
        );

        // Two further commits for the SAME triple from two different episodes.
        // On a single-threaded test driver they run one after another, but each
        // commit's own insert+retire transaction keeps the committed active
        // count at the cap: the first adds a 7th then retires one, the second
        // adds an 8th then retires one again, so it never settles above 6.
        $repo->appendMemory($this->row(sourceEpisodeId: 'ep_x', body: 'x'));
        $this->assertSame(
            self::TRIPLE_CAP,
            $repo->activeCount('robot_a', 1, 'earthquake'),
            'after the first further commit the active count must be exactly the cap'
        );

        $repo->appendMemory($this->row(sourceEpisodeId: 'ep_y', body: 'y'));
        $this->assertSame(
            self::TRIPLE_CAP,
            $repo->activeCount('robot_a', 1, 'earthquake'),
            'two interleaved per-triple commits must serialise to exactly the cap, never 7'
        );

        // The surviving active bodies are the newest 6 in finish order; the two
        // oldest seeds were retired.
        $snapshot = $repo->activeMemories('robot_a', 1, 18);
        $this->assertSame(
            ['seed 3', 'seed 4', 'seed 5', 'seed 6', 'x', 'y'],
            $snapshot->bodies,
            'the two oldest seeds must be retired, leaving the newest 6 oldest first'
        );
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Return the MySQL DSN from the environment, or skip the test when none is
     * set — the same gating the turn-loop PDO tests use.
     */
    private function requireMysqlOrSkip(): string
    {
        $dsn = getenv('PS_TEST_MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            $this->markTestSkipped('no MySQL available (set PS_TEST_MYSQL_DSN to run)');
        }

        return $dsn;
    }

    /** Open a PDO connection to the test MySQL, or skip when it cannot connect. */
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

    /**
     * Drop and recreate the three memory tables so each PDO test starts from a
     * known-empty, generation-1 state. Matches sql/2026-11-09_add_memory.sql
     * (the tables these tests touch); the episodes/prompts/responses tables are
     * not needed for the claim/append paths exercised here.
     */
    private function resetMemorySchema(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS reflections');
        $pdo->exec('DROP TABLE IF EXISTS memories');
        $pdo->exec('DROP TABLE IF EXISTS memory_generation');

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
            . 'PRIMARY KEY (id), '
            . 'KEY idx_active_pick (robot_id, disaster, generation, status, id), '
            . 'KEY idx_snapshot (robot_id, generation, status, id), '
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

    /** Build a draft MemoryRow; the store assigns the finish-order seq and status. */
    private function row(
        string $sourceEpisodeId,
        string $body,
        string $robotId = 'robot_a',
        int $generation = 1,
        string $disaster = 'earthquake',
        string $createdAt = '2026-11-09 12:00:00',
    ): MemoryRow {
        return new MemoryRow(
            robotId: $robotId,
            generation: $generation,
            disaster: $disaster,
            sourceEpisodeId: $sourceEpisodeId,
            body: $body,
            status: 'active',
            createdAt: $createdAt,
        );
    }
}
