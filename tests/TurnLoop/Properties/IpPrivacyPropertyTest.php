<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Memory\MemoryService;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryUsageRepository;
use PoltergeistSwitch\TurnLoop\Clients\FakeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\ClientIp;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\DailyLimitPolicy;
use PoltergeistSwitch\TurnLoop\EpisodeStarter;
use PoltergeistSwitch\TurnLoop\IpHasher;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\TurnService;

require_once __DIR__ . '/../../../api/bootstrap.php';

/**
 * Property-based test (Eris), against the in-memory fakes; no real AI call.
 *
 * Feature: public-lab, Property 15: IP privacy and hash stability
 *
 * For any salt, public IP and Device_Id: the IP_Hash is a pure function of
 * (salt, normalised IP), 64 lowercase hex characters, and differs when the salt
 * differs. The raw IP and the Device_Id appear in no daily_usage fake row, no
 * response body (success, 409 and 429) and no error_log message of the create
 * path.
 *
 * Validates: Requirements 13.14, 13.18, 1.7, 3.3, 6.10
 */
class IpPrivacyPropertyTest extends TestCase
{
    use TestTrait;

    private const NOW = 1_800_000_000;

    /**
     * Feature: public-lab, Property 15: IP privacy and hash stability
     *
     * Validates: Requirements 13.14, 13.18, 1.7, 3.3, 6.10
     */
    public function testIpAndDeviceNeverLeakAndHashesAreStable(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generators::choose(11, 99),          // first octet: public, never private/loopback
                Generators::choose(0, 255),
                Generators::choose(0, 255),
                Generators::choose(1, 254),
                Generators::choose(0, 0x7fffffff),   // device id parts
                Generators::choose(0, 0x7fffffff),
                Generators::choose(0, 0x7fffffff),   // salt parts
                Generators::choose(0, 0x7fffffff),
                Generators::bool(),                  // client sent a cookie
            )
            ->then(function (int $a, int $b, int $c, int $d, int $d1, int $d2, int $s1, int $s2, bool $withCookie): void {
                $rawIp = "{$a}.{$b}.{$c}.{$d}";
                $deviceId = sprintf('%08x%08x%08x%08x', $d1, $d2, $d1 ^ 0x5a5a5a5a, $d2 ^ 0x3c3c3c3c);
                $salt = hash('sha256', "salt-{$s1}-{$s2}");
                $label = "(ip {$rawIp}, device {$deviceId}, cookie {$withCookie})";

                $logFile = sys_get_temp_dir() . '/ps_p15_' . bin2hex(random_bytes(6)) . '.log';
                $oldLog = ini_set('error_log', $logFile);

                try {
                    // The create path resolves the IP through ps_client_ip().
                    $ip = ps_client_ip(['REMOTE_ADDR' => $rawIp], []);
                    $this->assertSame($rawIp, $ip, $label);
                    // An unresolvable address logs only the fixed code.
                    $this->assertNull(ps_client_ip(['REMOTE_ADDR' => '10.' . "{$b}.{$c}.{$d}"], []), $label);

                    $hasher = new IpHasher($salt);
                    $hash = $hasher->ipHash((string) ClientIp::normalize($rawIp));
                    $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $hash, $label);
                    $this->assertSame($hash, (new IpHasher($salt))->ipHash($rawIp), 'stable ' . $label);
                    $this->assertNotSame($hash, (new IpHasher($salt . 'x'))->ipHash($rawIp), 'salt matters ' . $label);

                    $repo = new InMemoryEpisodeRepository();
                    $turns = new TurnService(
                        new FakeRobotClient(),
                        new FakeRobotClient(),
                        new FakeRecorderClient(),
                        new ReplayEngineFactory($repo),
                        $repo,
                        new Config(),
                    );
                    $memory = new MemoryService($repo);
                    $usage = new InMemoryUsageRepository(fn (): int => self::NOW);
                    $starter = new EpisodeStarter(
                        $turns,
                        $memory,
                        $usage,
                        new DailyLimitPolicy(1, 30, 100),
                        fn (): int => self::NOW,
                    );

                    $cookies = $withCookie ? [PS_DEVICE_COOKIE => $deviceId] : [];
                    $create = static fn (array $cookies) => ps_dispatch(
                        $turns, 'POST', 'episodes', $cookies, [], null, $memory, null, $starter, $ip, $hasher,
                    );

                    $ok = $create($cookies);
                    $this->assertSame(200, $ok->status, $label);
                    $mintedId = null;
                    if (!$withCookie) {
                        $this->assertSame(1, preg_match('/ps_device=([0-9a-f]{32})/', $ok->headers['Set-Cookie'] ?? '', $m), $label);
                        $mintedId = $m[1];
                    }
                    $device = $mintedId ?? $deviceId;
                    $cookies = [PS_DEVICE_COOKIE => $device];

                    $conflict = $create($cookies);
                    $this->assertSame(409, $conflict->status, $label);

                    $running = $repo->findRunningEpisodeForDevice($device);
                    $this->assertNotNull($running, $label);
                    $repo->markEpisodeEnded($running->id);
                    $limited = $create($cookies);
                    $this->assertSame(429, $limited->status, $label);

                    // Hash stability inside the stored rows.
                    $day = DailyLimitPolicy::utcDay(self::NOW);
                    $this->assertArrayHasKey("$day|ip|$hash", $usage->rows, $label);

                    // Nothing raw in the stored rows or any response body.
                    $rows = json_encode($usage->rows, JSON_THROW_ON_ERROR);
                    foreach ([$rawIp, $deviceId, $device] as $secret) {
                        $this->assertStringNotContainsString($secret, $rows, "row leaks {$secret} {$label}");
                        foreach (['create 200' => $ok, 'create 409' => $conflict, 'create 429' => $limited] as $name => $response) {
                            $this->assertStringNotContainsString($secret, $response->body, "{$name} leaks {$secret} {$label}");
                            $this->assertStringNotContainsString($hash, $response->body, "{$name} leaks the IP_Hash {$label}");
                        }
                    }

                    // Nothing raw in the error log.
                    $log = is_file($logFile) ? (string) file_get_contents($logFile) : '';
                    $this->assertStringContainsString('ip_unresolvable', $log, $label);
                    foreach ([$rawIp, $deviceId, $device, $hash, $salt] as $secret) {
                        $this->assertStringNotContainsString($secret, $log, "log leaks {$secret} {$label}");
                    }
                } finally {
                    if ($oldLog !== false) {
                        ini_set('error_log', $oldLog);
                    }
                    @unlink($logFile);
                }
            });
    }
}
