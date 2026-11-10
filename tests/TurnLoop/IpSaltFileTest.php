<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\TurnLoop\IpHasher;

require_once __DIR__ . '/../../api/bootstrap.php';

/**
 * ps_load_ip_salt (public-lab Requirements 13.14 to 13.16). Works in a temp
 * directory only; no real salt or secret is involved.
 */
class IpSaltFileTest extends TestCase
{
    private string $dir;
    private string $logFile;
    private string|false $oldErrorLog;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ps_salt_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
        $this->logFile = $this->dir . '.log'; // outside $dir, which one test makes read-only
        $this->oldErrorLog = ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        if ($this->oldErrorLog !== false) {
            ini_set('error_log', $this->oldErrorLog);
        }
        @chmod($this->dir, 0700);
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->dir);
        @unlink($this->logFile);
    }

    private function log(): string
    {
        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }

    public function testSaltIsCreatedOnceAndReused(): void
    {
        $salt = ps_load_ip_salt($this->dir);

        $this->assertNotNull($salt);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $salt);
        $this->assertFileExists($this->dir . '/ip_salt.key');
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->dir . '/ip_salt.key')), -4));
        $this->assertSame($salt, ps_load_ip_salt($this->dir), 'two loads return the same value');
        $this->assertSame([$this->dir . '/ip_salt.key'], glob($this->dir . '/ip_salt.key'));
        $this->assertSame([], glob($this->dir . '/.ip_salt.*.tmp') ?: [], 'no temporary file is left');
    }

    public function testExistingValidFileIsUsedAsIs(): void
    {
        $given = str_repeat('0123456789abcdef', 4);
        file_put_contents($this->dir . '/ip_salt.key', $given . "\n");

        $this->assertSame($given, ps_load_ip_salt($this->dir));
    }

    public function testInvalidFileIsNotOverwrittenAndGivesNull(): void
    {
        file_put_contents($this->dir . '/ip_salt.key', 'not-a-salt');

        $this->assertNull(ps_load_ip_salt($this->dir));
        $this->assertSame('not-a-salt', file_get_contents($this->dir . '/ip_salt.key'));
        $this->assertStringContainsString('ip_salt_unavailable', $this->log());
    }

    public function testNonWritableDirectoryGivesNullAndFallbackKeyWorks(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can write to a read-only directory');
        }
        chmod($this->dir, 0500);

        $this->assertNull(ps_load_ip_salt($this->dir));
        $this->assertFileDoesNotExist($this->dir . '/ip_salt.key');
        $this->assertStringContainsString('ip_salt_unavailable', $this->log());

        // No salt: the create path falls back to a plain Device hash, IP skipped.
        $keys = ps_usage_keys('0123456789abcdef0123456789abcdef', '203.0.113.9', null);
        $this->assertSame(hash('sha256', 'device:0123456789abcdef0123456789abcdef'), $keys->deviceKey);
        $this->assertNull($keys->ipKey);
    }

    public function testSaltDrivesTheHasher(): void
    {
        $salt = ps_load_ip_salt($this->dir);
        $this->assertNotNull($salt);

        $a = new IpHasher($salt);
        $b = new IpHasher((string) ps_load_ip_salt($this->dir));
        $this->assertSame($a->ipHash('198.51.100.7'), $b->ipHash('198.51.100.7'));
    }
}
