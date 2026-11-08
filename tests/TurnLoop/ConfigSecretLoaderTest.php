<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\SecretLoader;
use PoltergeistSwitch\TurnLoop\SecretLoadException;

/**
 * Unit tests for Config and SecretLoader (task 3.3).
 *
 * These tests guard the API keys (required, decided by the maintainer on
 * 2026-11-08). They use a temporary fake secrets directory and a fake,
 * non-secret key value only — never a real key — and assert that no secret
 * value and no secrets path ever leaks into a returned string or an exception
 * message.
 *
 * Requirements: 2.4, 2.5, 2.6, 1.12
 */
class ConfigSecretLoaderTest extends TestCase
{
    /** A clearly-fake, non-secret sentinel used as a key value in tests. */
    private const FAKE_KEY = 'FAKE-TEST-KEY-not-a-real-credential-0123456789';

    private string $tmpRoot;
    private string $secretsDir;
    private string $configPath;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'tl_secrets_test_' . bin2hex(random_bytes(6));
        $this->secretsDir = $this->tmpRoot . DIRECTORY_SEPARATOR . 'secrets';
        mkdir($this->secretsDir, 0700, true);

        $this->configPath = $this->tmpRoot . DIRECTORY_SEPARATOR . 'config.local.php';
        $this->writeConfig($this->secretsDir);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
    }

    // --- Config ---

    public function testPerCallTimeoutIsStrictlyBelowPerRequestBudget(): void
    {
        $config = new Config();

        $this->assertLessThan(
            $config->perRequestBudgetSeconds,
            $config->perCallTimeoutSeconds,
            'per-call timeout must be strictly below the per-request budget (Req 1.12)'
        );
    }

    public function testConfigRejectsPerCallTimeoutNotBelowBudget(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Config(perCallTimeoutSeconds: 85, perRequestBudgetSeconds: 85);
    }

    public function testConfigCarriesDraftCostRatesAndSlotLimits(): void
    {
        $config = new Config();

        $this->assertSame(0.10 / 1_000_000, $config->inputCostPerToken);
        $this->assertSame(0.50 / 1_000_000, $config->outputCostPerToken);
        $this->assertSame(5, $config->maxRunningEpisodes);
        $this->assertSame(300, $config->idleSlotReleaseSeconds);
    }

    // --- SecretLoader: happy path ---

    public function testReadsKeyFromFakeFile(): void
    {
        $this->writeKey('anthropic', self::FAKE_KEY);

        $loader = new SecretLoader($this->configPath);

        $this->assertSame(self::FAKE_KEY, $loader->readKey('anthropic'));
    }

    public function testReadKeyTrimsSurroundingWhitespace(): void
    {
        $this->writeKey('openai', "  " . self::FAKE_KEY . "\n");

        $loader = new SecretLoader($this->configPath);

        $this->assertSame(self::FAKE_KEY, $loader->readKey('openai'));
    }

    // --- SecretLoader: missing config stops before any provider call ---

    public function testMissingConfigRaisesConfigError(): void
    {
        $loader = new SecretLoader($this->tmpRoot . DIRECTORY_SEPARATOR . 'nope.php');

        try {
            $loader->secretsDir();
            $this->fail('expected a SecretLoadException for a missing config');
        } catch (SecretLoadException $e) {
            $this->assertSame(SecretLoadException::CODE_CONFIG_MISSING, $e->reason());
            $this->assertNoLeak($e->getMessage());
        }
    }

    public function testMissingConfigStopsBeforeKeyRead(): void
    {
        // Even a key that would exist is never reached when config is missing:
        $this->writeKey('anthropic', self::FAKE_KEY);
        $loader = new SecretLoader($this->tmpRoot . DIRECTORY_SEPARATOR . 'nope.php');

        $this->expectException(SecretLoadException::class);
        $loader->readKey('anthropic');
    }

    // --- SecretLoader: missing / empty key file is an error for that client ---

    public function testMissingKeyFileRaisesKeyError(): void
    {
        $loader = new SecretLoader($this->configPath);

        try {
            $loader->readKey('anthropic');
            $this->fail('expected a SecretLoadException for a missing key file');
        } catch (SecretLoadException $e) {
            $this->assertSame(SecretLoadException::CODE_KEY_MISSING, $e->reason());
            $this->assertNoLeak($e->getMessage());
        }
    }

    public function testEmptyKeyFileRaisesKeyError(): void
    {
        $this->writeKey('openai', "   \n\t ");

        $loader = new SecretLoader($this->configPath);

        try {
            $loader->readKey('openai');
            $this->fail('expected a SecretLoadException for an empty key file');
        } catch (SecretLoadException $e) {
            $this->assertSame(SecretLoadException::CODE_KEY_EMPTY, $e->reason());
            $this->assertNoLeak($e->getMessage());
        }
    }

    public function testOneMissingKeyDoesNotAffectAnotherClient(): void
    {
        $this->writeKey('anthropic', self::FAKE_KEY);
        // openai key deliberately absent.

        $loader = new SecretLoader($this->configPath);

        $this->assertSame(self::FAKE_KEY, $loader->readKey('anthropic'));
        $this->expectException(SecretLoadException::class);
        $loader->readKey('openai');
    }

    // --- SecretLoader: no secret or path leaks into any output ---

    public function testNoSecretValueOrPathLeaksInExceptionMessages(): void
    {
        // Config invalid (no secrets_dir): message must not reveal the path.
        $badConfig = $this->tmpRoot . DIRECTORY_SEPARATOR . 'bad.php';
        file_put_contents($badConfig, "<?php\nreturn ['nothing' => true];\n");

        $loader = new SecretLoader($badConfig);
        try {
            $loader->secretsDir();
            $this->fail('expected a SecretLoadException for an invalid config');
        } catch (SecretLoadException $e) {
            $this->assertSame(SecretLoadException::CODE_CONFIG_INVALID, $e->reason());
            $this->assertNoLeak($e->getMessage());
        }
    }

    public function testKeyErrorMessageDoesNotRevealFakeKeyValue(): void
    {
        // Write a fake key, then make the file unreadable-as-empty case so the
        // error path runs; assert the message never contains the value or path.
        $this->writeKey('anthropic', "");

        $loader = new SecretLoader($this->configPath);
        try {
            $loader->readKey('anthropic');
            $this->fail('expected a SecretLoadException');
        } catch (SecretLoadException $e) {
            $this->assertStringNotContainsString(self::FAKE_KEY, $e->getMessage());
            $this->assertNoLeak($e->getMessage());
        }
    }

    // --- helpers ---

    private function writeConfig(string $secretsDir): void
    {
        $contents = "<?php\nreturn ['secrets_dir' => " . var_export($secretsDir, true) . "];\n";
        file_put_contents($this->configPath, $contents);
    }

    private function writeKey(string $name, string $value): void
    {
        file_put_contents($this->secretsDir . DIRECTORY_SEPARATOR . $name . '.key', $value);
    }

    /**
     * Assert a message leaks neither the fake key value nor the secrets path.
     */
    private function assertNoLeak(string $message): void
    {
        $this->assertStringNotContainsString(self::FAKE_KEY, $message);
        $this->assertStringNotContainsString($this->secretsDir, $message);
        $this->assertStringNotContainsString($this->tmpRoot, $message);
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
            }
            rmdir($path);
            return;
        }
        unlink($path);
    }
}
