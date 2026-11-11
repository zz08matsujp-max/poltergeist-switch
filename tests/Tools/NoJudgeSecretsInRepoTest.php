<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Tools;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\TurnLoop\Judge\JudgePass;

/**
 * Repository-wide scan: no tracked or unignored file holds a Judge_Link with a
 * real-looking pass or a `judge_pass_sha256` assignment to a 64-hex literal.
 * Never reads api/config.local.php, pilot/config.local.php or any *.key file.
 *
 * **Validates: Requirements 21.32, 14.39**
 */
final class NoJudgeSecretsInRepoTest extends TestCase
{
    private const LINK_PATTERN = '/#judge=[A-Za-z0-9_-]{43}(?![A-Za-z0-9_-])/';
    private const HASH_PATTERN = '/judge_pass_sha256[\'"]?\s*(?:=>|=|:)\s*[\'"][0-9a-f]{64}[\'"]/';
    private const MAX_BYTES = 5_000_000;

    private static function root(): string
    {
        $root = realpath(__DIR__ . '/../..');
        self::assertNotFalse($root);
        return $root;
    }

    private static function detect(string $text): bool
    {
        return preg_match(self::LINK_PATTERN, $text) === 1
            || preg_match(self::HASH_PATTERN, $text) === 1;
    }

    private static function isForbiddenToRead(string $relative): bool
    {
        $base = basename($relative);
        return $base === 'config.local.php' || str_ends_with($base, '.key');
    }

    /**
     * @return list<string>|null null when git is unavailable
     */
    private static function listFiles(string $root): ?array
    {
        $files = [];
        foreach (['ls-files -z', 'ls-files -z --others --exclude-standard'] as $args) {
            $cmd = 'git -C ' . escapeshellarg($root) . ' ' . $args . ' 2>/dev/null';
            $out = shell_exec($cmd);
            if (!is_string($out)) {
                return null;
            }
            foreach (explode("\0", $out) as $path) {
                if ($path !== '') {
                    $files[$path] = true;
                }
            }
        }
        if ($files === []) {
            return null;
        }
        return array_keys($files);
    }

    public function testDetectorFlagsGeneratedSamples(): void
    {
        $pass = JudgePass::generate();
        $hash = JudgePass::hash($pass);

        $this->assertTrue(self::detect('https://kiro.pyol.net/#judge=' . $pass . "\n"));
        $this->assertTrue(self::detect("'judge_pass_sha256' => '" . $hash . "',"));
        $this->assertTrue(self::detect('judge_pass_sha256 = "' . $hash . '"'));
        $this->assertTrue(self::detect('"judge_pass_sha256": "' . $hash . '"'));

        $this->assertFalse(self::detect('https://kiro.pyol.net/#judge=<Judge_Pass>'));
        $this->assertFalse(self::detect('#judge=' . substr($pass, 0, 42)));
        $this->assertFalse(self::detect("'judge_pass_sha256' => '<64 lowercase hex characters>',"));
        $this->assertFalse(self::detect('judge_pass_sha256: ' . $hash));
    }

    public function testForbiddenFilesAreNeverRead(): void
    {
        $this->assertTrue(self::isForbiddenToRead('api/config.local.php'));
        $this->assertTrue(self::isForbiddenToRead('pilot/config.local.php'));
        $this->assertTrue(self::isForbiddenToRead('secrets/judge_key.key'));
        $this->assertFalse(self::isForbiddenToRead('api/config.example.php'));
    }

    public function testNoJudgeSecretsInTrackedOrUnignoredFiles(): void
    {
        $root = self::root();
        $files = self::listFiles($root);
        if ($files === null) {
            $this->markTestSkipped('git is unavailable or this is not a git work tree.');
        }

        $hits = [];
        $scanned = 0;
        foreach ($files as $relative) {
            if (self::isForbiddenToRead($relative)) {
                continue;
            }
            $path = $root . DIRECTORY_SEPARATOR . $relative;
            if (!is_file($path) || is_link($path)) {
                continue;
            }
            $size = filesize($path);
            if ($size === false || $size > self::MAX_BYTES) {
                continue;
            }
            $text = file_get_contents($path);
            if ($text === false || str_contains($text, "\0")) {
                continue;
            }
            $scanned++;
            if (self::detect($text)) {
                $hits[] = $relative;
            }
        }

        $this->assertGreaterThan(0, $scanned, 'The scan must read at least one file.');
        $this->assertSame([], $hits, 'Judge secret pattern found in: ' . implode(', ', $hits));
    }
}
