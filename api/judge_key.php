<?php

declare(strict_types=1);

/**
 * The Judge_Cookie signing key file (public-lab Requirement 21, design "Judge Pass").
 * The key lives in <secrets_dir>/judge_key.key, outside the web root and the
 * repository. No key value is ever written to the repository or to a log.
 */

/**
 * Read the key (64 hex characters), creating it once when the file does not
 * exist. Returns null when it is unavailable; the caller then refuses judge
 * cookies and the code `judge_key_unavailable` is logged.
 *
 * Creation is race-safe and never overwrites (same shape as ps_load_ip_salt()).
 */
function ps_load_judge_key(string $secretsDir): ?string
{
    $path = rtrim($secretsDir, '/\\') . DIRECTORY_SEPARATOR . 'judge_key.key';

    if (!file_exists($path)) {
        ps_create_judge_key($path);
    }

    $key = is_file($path) && is_readable($path) ? @file_get_contents($path) : false;
    if (is_string($key)) {
        $key = trim($key);
        if (preg_match('/\A[0-9a-f]{64}\z/', $key) === 1) {
            return $key;
        }
    }

    error_log('judge_key_unavailable');

    return null;
}

/** Create the key file once; failures are silent (the caller re-reads). */
function ps_create_judge_key(string $path): void
{
    $dir = dirname($path);
    if (!is_dir($dir) || !is_writable($dir)) {
        return;
    }

    try {
        $key = bin2hex(random_bytes(32));
        $suffix = bin2hex(random_bytes(6));
    } catch (\Throwable) {
        return;
    }

    $tmp = $dir . DIRECTORY_SEPARATOR . '.judge_key.' . $suffix . '.tmp';
    if (@file_put_contents($tmp, $key) === false) {
        @unlink($tmp);

        return;
    }
    @chmod($tmp, 0600);

    if (!@link($tmp, $path) && !file_exists($path)) {
        @rename($tmp, $path);
    }
    @unlink($tmp);
}
