<?php
declare(strict_types=1);
namespace PoltergeistSwitch\Tests\TurnLoop\Repository;
use PDO;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\TurnLoop\Clients\FakeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\Http\ApiError;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\Repository\PdoEpisodeRepository;
use PoltergeistSwitch\TurnLoop\TurnService;
use PoltergeistSwitch\TurnLoop\Values\EpisodeRow;
/**
 * Gated PDO/MySQL tests for the Turn_Lock of PdoEpisodeRepository (public-lab
 * task 21.5). Skipped unless PS_TEST_MYSQL_DSN names a throwaway database; no
 * server is contacted otherwise. The service test drops and recreates the
 * episodes and turns tables, so the DSN must point at a throwaway database.
 *
 * Requirements: 18.10, 18.11, 18.12, 14.27
 */
class TurnLockPdoTest extends TestCase
{
    private const EPISODE = '00000000-0000-4000-8000-000000000001';
    private ?string $dsn = null;
    /** @var PDO[] connections opened by the current test */
    private array $pdos = [];
    protected function setUp(): void
    {
        $dsn = getenv('PS_TEST_MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            $this->markTestSkipped('no MySQL available (set PS_TEST_MYSQL_DSN to run)');
        }
        $this->dsn = $dsn;
    }
    protected function tearDown(): void
    {
        $this->pdos = [];
    }
    private function connect(): PDO
    {
        $user = (string) (getenv('PS_TEST_MYSQL_USER') ?: '');
        $pass = (string) (getenv('PS_TEST_MYSQL_PASS') ?: '');
        try {
            $pdo = new PDO((string) $this->dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL connection failed');
        }
        $this->pdos[] = $pdo;
        return $pdo;
    }
    /** A lock held through one connection blocks the other until it is released. */
    public function testSecondConnectionIsBlockedUntilRelease(): void
    {
        $first = new PdoEpisodeRepository($this->connect());
        $second = new PdoEpisodeRepository($this->connect());
        $this->assertTrue($first->acquireTurnLock('ask', self::EPISODE, 1));
        $this->assertFalse($second->acquireTurnLock('ask', self::EPISODE, 1));
        $first->releaseTurnLock('ask', self::EPISODE, 1);
        $this->assertTrue($second->acquireTurnLock('ask', self::EPISODE, 1));
        $second->releaseTurnLock('ask', self::EPISODE, 1);
    }
    /** Closing the holding connection frees the lock. */
    public function testClosingTheHoldingConnectionFreesTheLock(): void
    {
        $holderPdo = $this->connect();
        $holder = new PdoEpisodeRepository($holderPdo);
        $second = new PdoEpisodeRepository($this->connect());
        $this->assertTrue($holder->acquireTurnLock('apply', self::EPISODE, 2));
        $this->assertFalse($second->acquireTurnLock('apply', self::EPISODE, 2));
        unset($holder);
        $holderPdo = null;
        $this->pdos = [$this->pdos[1]];
        $this->assertTrue($second->acquireTurnLock('apply', self::EPISODE, 2));
        $second->releaseTurnLock('apply', self::EPISODE, 2);
    }
    /** The ask lock and the apply lock for the same turn are independent. */
    public function testAskAndApplyLocksDoNotBlockEachOther(): void
    {
        $first = new PdoEpisodeRepository($this->connect());
        $second = new PdoEpisodeRepository($this->connect());
        $this->assertTrue($first->acquireTurnLock('ask', self::EPISODE, 3));
        $this->assertTrue($second->acquireTurnLock('apply', self::EPISODE, 3));
        $first->releaseTurnLock('ask', self::EPISODE, 3);
        $second->releaseTurnLock('apply', self::EPISODE, 3);
    }
    /** A 500-character episode id still gives a working lock (name within 64 characters). */
    public function testVeryLongEpisodeIdStillLocks(): void
    {
        $id = str_repeat('x', 500);
        $first = new PdoEpisodeRepository($this->connect());
        $second = new PdoEpisodeRepository($this->connect());
        $this->assertTrue($first->acquireTurnLock('ask', $id, 1));
        $this->assertFalse($second->acquireTurnLock('ask', $id, 1));
        $first->releaseTurnLock('ask', $id, 1);
        $this->assertTrue($second->acquireTurnLock('ask', $id, 1));
        $second->releaseTurnLock('ask', $id, 1);
    }
    /** ask through the second connection while the first holds the lock gives turn_busy with no client call. */
    public function testAskOnBusyLockGivesTurnBusyAndCallsNoClient(): void
    {
        $setup = $this->connect();
        $this->resetSchema($setup);
        $firstRepo = new PdoEpisodeRepository($setup);
        $firstRepo->createEpisode(new EpisodeRow(
            id: self::EPISODE,
            deviceId: 'dev_lock',
            disaster: 'earthquake',
            seed: 1,
            currentExpectedTurn: 1,
            earthquakeStartTurn: null,
            ended: false,
            createdAt: '2026-11-01 10:00:00',
            lastActivityAt: '2026-11-01 10:00:00',
        ));
        $secondRepo = new PdoEpisodeRepository($this->connect());
        $robotA = new FakeRobotClient();
        $robotB = new FakeRobotClient();
        $service = new TurnService(
            $robotA,
            $robotB,
            new FakeRecorderClient(),
            new ReplayEngineFactory($secondRepo),
            $secondRepo,
            new Config(),
        );
        $this->assertTrue($firstRepo->acquireTurnLock('ask', self::EPISODE, 1));
        try {
            $service->ask(self::EPISODE, 1);
            $this->fail('ask should have thrown turn_busy');
        } catch (ApiError $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame('turn_busy', $e->errorCode);
        } finally {
            $firstRepo->releaseTurnLock('ask', self::EPISODE, 1);
        }
        $this->assertSame(0, $robotA->callCount());
        $this->assertSame(0, $robotB->callCount());
    }
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
            . 'PRIMARY KEY (id)'
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
}
