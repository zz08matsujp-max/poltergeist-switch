<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Tools;

use PHPUnit\Framework\TestCase;

/**
 * Tests for tools/make_judge_pass.php, run as a child process.
 * Output paths live in a per-test temp dir outside the repository.
 *
 * **Validates: Requirements 21.30, 21.31, 21.32, 14.38**
 */
final class MakeJudgePassTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $base = realpath(sys_get_temp_dir());
        $this->assertNotFalse($base);
        $this->tmp = $base . DIRECTORY_SEPARATOR . 'mjp_' . bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($this->tmp, 0700));
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            foreach (scandir($this->tmp) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    @unlink($this->tmp . DIRECTORY_SEPARATOR . $name);
                }
            }
            @rmdir($this->tmp);
        }
    }

    /**
     * @param list<string> $args
     * @return array{int, string, string}
     */
    private function runTool(array $args): array
    {
        $tool = realpath(__DIR__ . '/../../tools/make_judge_pass.php');
        $this->assertNotFalse($tool);
        $cmd = array_merge([PHP_BINARY, $tool], $args);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $out, $err];
    }

    private function passFrom(string $file): string
    {
        $content = (string) file_get_contents($file);
        $this->assertSame(1, preg_match('/\Ahttps:\/\/kiro\.pyol\.net\/#judge=([A-Za-z0-9_-]{43})\n\z/', $content, $m));
        return $m[1];
    }

    public function testSuccessfulRun(): void
    {
        $file = $this->tmp . '/pass.txt';
        [$code, $out, $err] = $this->runTool([$file]);

        $this->assertSame(0, $code);
        $this->assertSame('', $err);
        $this->assertSame(1, preg_match('/\Ajudge_pass_sha256: ([0-9a-f]{64})\n\z/', $out, $m));

        $pass = $this->passFrom($file);
        $this->assertSame(hash('sha256', $pass), $m[1]);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
        $this->assertStringNotContainsString($pass, $out);
        $this->assertStringNotContainsString($pass, $err);
    }

    public function testSecondRunOnSamePathFailsAndKeepsFile(): void
    {
        $file = $this->tmp . '/pass.txt';
        [$code1] = $this->runTool([$file]);
        $this->assertSame(0, $code1);
        $before = (string) file_get_contents($file);

        [$code2, $out2, $err2] = $this->runTool([$file]);
        $this->assertSame(1, $code2);
        $this->assertSame('', $out2);
        $this->assertNotSame('', $err2);
        $this->assertStringNotContainsString($this->passFrom($file), $err2);
        $this->assertSame($before, (string) file_get_contents($file));
    }

    public function testWrongArgumentCountFailsWithoutWritingAFile(): void
    {
        [$c0, $o0] = $this->runTool([]);
        $this->assertSame(1, $c0);
        $this->assertSame('', $o0);

        [$c2, $o2] = $this->runTool([$this->tmp . '/a.txt', $this->tmp . '/b.txt']);
        $this->assertSame(1, $c2);
        $this->assertSame('', $o2);

        $this->assertSame([], array_values(array_diff(scandir($this->tmp) ?: [], ['.', '..'])));
    }

    public function testPathInsideRepositoryIsRefused(): void
    {
        $repo = realpath(__DIR__ . '/../..');
        $this->assertNotFalse($repo);
        $file = $repo . '/judge_pass_test_' . bin2hex(random_bytes(6)) . '.txt';

        [$code, $out] = $this->runTool([$file]);
        $this->assertSame(1, $code);
        $this->assertSame('', $out);
        $this->assertFileDoesNotExist($file);
        if (file_exists($file)) {
            unlink($file);
        }
    }

    public function testTwoRunsGiveDifferentPasses(): void
    {
        $a = $this->tmp . '/a.txt';
        $b = $this->tmp . '/b.txt';
        [$ca, $oa] = $this->runTool([$a]);
        [$cb, $ob] = $this->runTool([$b]);
        $this->assertSame(0, $ca);
        $this->assertSame(0, $cb);
        $this->assertNotSame($this->passFrom($a), $this->passFrom($b));
        $this->assertNotSame($oa, $ob);
    }
}
