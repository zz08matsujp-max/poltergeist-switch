<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop;

/**
 * Loads API keys from files outside the web root, via an uncommitted local
 * Config_File that lives outside the repository tree (Requirement 2).
 *
 * The Config_File is a plain PHP file (like `pilot/config.local.php`) that
 * returns an array carrying at least a `secrets_dir` entry — the path to a
 * directory, outside the web root, that holds one file per client key named
 * `<client>.key` (for example `anthropic.key`, `openai.key`).
 *
 * Failures are surfaced as SecretLoadException so the caller can stop the
 * request before any provider call (Requirements 2.4, 2.5). No key value, no
 * credential, and no secrets directory path is ever placed in a returned value
 * or an exception message (Requirement 2.6).
 */
final class SecretLoader
{
    private string $configFilePath;

    /** Cached secrets directory, resolved lazily from the Config_File. */
    private ?string $secretsDir = null;

    /**
     * @param string $configFilePath Absolute path to the uncommitted local
     *                               Config_File that returns the `secrets_dir`.
     */
    public function __construct(string $configFilePath)
    {
        $this->configFilePath = $configFilePath;
    }

    /**
     * Resolve the secrets directory from the Config_File (Requirement 2.1).
     *
     * @throws SecretLoadException if the config is missing, unreadable, or does
     *                             not yield a usable `secrets_dir`.
     */
    public function secretsDir(): string
    {
        if ($this->secretsDir !== null) {
            return $this->secretsDir;
        }

        if (!is_file($this->configFilePath) || !is_readable($this->configFilePath)) {
            throw SecretLoadException::configMissing();
        }

        $config = @include $this->configFilePath;

        if (!is_array($config)) {
            throw SecretLoadException::configInvalid();
        }

        $dir = $config['secrets_dir'] ?? null;
        if (!is_string($dir) || trim($dir) === '') {
            throw SecretLoadException::configInvalid();
        }

        $dir = rtrim(trim($dir), '/\\');
        if (!is_dir($dir)) {
            throw SecretLoadException::configInvalid();
        }

        $this->secretsDir = $dir;

        return $dir;
    }

    /**
     * Read a named client key from a `<name>.key` file in the secrets
     * directory, trimmed (Requirement 2.2). An empty-after-trim value is
     * rejected (Requirement 2.5).
     *
     * @param string $name Client name, e.g. `anthropic` or `openai`.
     * @return string The trimmed key value.
     *
     * @throws SecretLoadException if the config, or this key file, cannot be
     *                             read, or the key is empty after trimming.
     */
    public function readKey(string $name): string
    {
        $dir = $this->secretsDir();

        $path = $dir . DIRECTORY_SEPARATOR . $name . '.key';

        if (!is_file($path) || !is_readable($path)) {
            throw SecretLoadException::keyMissing($name);
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw SecretLoadException::keyMissing($name);
        }

        $key = trim($raw);
        if ($key === '') {
            throw SecretLoadException::keyEmpty($name);
        }

        return $key;
    }
}
