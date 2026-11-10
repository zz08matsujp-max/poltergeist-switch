<?php

declare(strict_types=1);

/**
 * The IP salt file (public-lab Requirements 13.14 to 13.16, design "IP salt").
 * The salt lives in <secrets_dir>/ip_salt.key, outside the web root and the
 * repository. No salt value is ever written to the repository or to a log.
 */

/**
 * Read the salt (64 hex characters), creating it once when the file does not
 * exist. Returns null when it is unavailable; the caller then skips the IP
 * scope and logs the fixed code `ip_salt_unavailable`.
 *
 * Creation is race-safe: a temporary file in the same directory is hard-linked
 * to the target, and link() fails if the target exists, so two first requests
 * cannot overwrite each other. rename() is a fallback only when the target does
 * not exist. An existing file that is unreadable or not 64 hex characters is
 * never overwritten.
 */
function ps_load_ip_salt(string $secretsDir): ?string
{
    $path = rtrim($secretsDir, '/\\') . DIRECTORY_SEPARATOR . 'ip_salt.key';

    if (!file_exists($path)) {
        ps_create_ip_salt($path);
    }

    $salt = is_file($path) && is_readable($path) ? @file_get_contents($path) : false;
    if (is_string($salt)) {
        $salt = trim($salt);
        if (preg_match('/\A[0-9a-f]{64}\z/', $salt) === 1) {
            return $salt;
        }
    }

    error_log('ip_salt_unavailable');

    return null;
}

/** Create the salt file once; failures are silent (the caller re-reads). */
function ps_create_ip_salt(string $path): void
{
    $dir = dirname($path);
    if (!is_dir($dir) || !is_writable($dir)) {
        return;
    }

    try {
        $salt = bin2hex(random_bytes(32));
    } catch (\Throwable) {
        return;
    }

    $tmp = $dir . DIRECTORY_SEPARATOR . '.ip_salt.' . bin2hex(random_bytes(6)) . '.tmp';
    if (@file_put_contents($tmp, $salt) === false) {
        @unlink($tmp);

        return;
    }
    @chmod($tmp, 0600);

    if (!@link($tmp, $path) && !file_exists($path)) {
        // link() not permitted on this host: rename only when nothing is there.
        @rename($tmp, $path);
    }
    @unlink($tmp);
}
