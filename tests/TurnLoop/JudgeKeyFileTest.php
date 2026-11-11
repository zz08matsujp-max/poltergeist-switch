<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/bootstrap.php';

/** ps_load_judge_key (public-lab Requirements 21.14, 21.15). Temp directory only. */
class JudgeKeyFileTest extends TestCase
{
    private string $dir;
    private string $logFile;
    private string|false $oldErrorLog;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ps_judgekey_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
        $this->logFile = $this->dir . '.log';
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

    public function testKeyIsCreatedOnceAndReused(): void
    {
        $key = ps_load_judge_key($this->dir);

        $this->assertNotNull($key);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $key);
        $path = $this->dir . '/judge_key.key';
        $this->assertFileExists($path);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        $this->assertSame($key, ps_load_judge_key($this->dir), 'second call reuses the key');
        $this->assertSame([], glob($this->dir . '/.judge_key.*.tmp') ?: [], 'no temporary file is left');
    }

    public function testInvalidFileIsNotOverwrittenAndGivesNull(): void
    {
        $path = $this->dir . '/judge_key.key';
        file_put_contents($path, 'not-a-key');

        $this->assertNull(ps_load_judge_key($this->dir));
        $this->assertSame('not-a-key', file_get_contents($path));
        $this->assertStringContainsString('judge_key_unavailable', $this->log());
    }

    public function testNonWritableDirectoryGivesNull(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can write to a read-only directory');
        }
        chmod($this->dir, 0500);

        $this->assertNull(ps_load_judge_key($this->dir));
        $this->assertFileDoesNotExist($this->dir . '/judge_key.key');
        $this->assertStringContainsString('judge_key_unavailable', $this->log());
    }
}
