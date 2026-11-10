<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/bootstrap.php';

/**
 * ps_client_ip (public-lab Requirements 13.14, 13.15).
 */
class ClientIpApiTest extends TestCase
{
    private string $logFile;
    private string|false $oldErrorLog;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/ps_ip_' . bin2hex(random_bytes(6)) . '.log';
        $this->oldErrorLog = ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        if ($this->oldErrorLog !== false) {
            ini_set('error_log', $this->oldErrorLog);
        }
        @unlink($this->logFile);
    }

    public function testDefaultsToRemoteAddr(): void
    {
        $this->assertSame('198.51.100.7', ps_client_ip(['REMOTE_ADDR' => '198.51.100.7'], []));
    }

    public function testConfiguredHeaderUsesRightmostEntry(): void
    {
        $server = ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_REAL_IP' => '45.33.1.2, 52.1.2.3'];

        $this->assertSame('52.1.2.3', ps_client_ip($server, ['client_ip_header' => 'HTTP_X_REAL_IP']));
    }

    public function testProxyAddressGivesNullAndLogsFixedCode(): void
    {
        $this->assertNull(ps_client_ip(['REMOTE_ADDR' => '10.1.2.3'], []));
        $this->assertNull(ps_client_ip(['REMOTE_ADDR' => '127.0.0.1'], []));
        $this->assertNull(ps_client_ip([], []));

        $log = (string) file_get_contents($this->logFile);
        $this->assertStringContainsString('ip_unresolvable', $log);
        $this->assertStringNotContainsString('10.1.2.3', $log);
    }
}
