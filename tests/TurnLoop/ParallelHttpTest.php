<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\TurnLoop\ParallelHttp;

/**
 * Unit tests for ParallelHttp (task 4.2).
 *
 * These tests exercise the result-shaping of {@see ParallelHttp::run()}
 * against prepared curl handles without touching a real provider API or the
 * network: a `file://` handle reads a local temp file for the success shape,
 * and a handle aimed at an unroutable TEST-NET address (RFC 5737, 192.0.2.0/24)
 * with a very short timeout fails fast and offline so we can assert the
 * errored-handle shape.
 *
 * Requirements: 1.6, 1.7
 */
class ParallelHttpTest extends TestCase
{
    /** @var list<string> Temp files to clean up. */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->tmpFiles = [];
    }

    public function testEmptyHandleMapReturnsEmptyResult(): void
    {
        $results = (new ParallelHttp())->run([]);

        $this->assertSame([], $results);
    }

    public function testSuccessfulHandleYieldsBodyAndNullError(): void
    {
        $body = 'hello-from-local-file';
        $path = $this->writeTempFile($body);

        $handle = curl_init();
        curl_setopt($handle, CURLOPT_URL, 'file://' . $path);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);

        $results = (new ParallelHttp())->run(['ok' => $handle]);

        $this->assertArrayHasKey('ok', $results);
        $this->assertNull($results['ok']['error'], 'a successful handle has no error');
        $this->assertSame($body, $results['ok']['body']);
        $this->assertIsFloat($results['ok']['seconds']);
        $this->assertGreaterThanOrEqual(0.0, $results['ok']['seconds']);
    }

    public function testTimedOutHandleYieldsErrorAndNullBody(): void
    {
        $handle = $this->fastFailingHandle();

        $results = (new ParallelHttp())->run(['slow' => $handle]);

        $this->assertArrayHasKey('slow', $results);
        $this->assertNotNull(
            $results['slow']['error'],
            'a timed-out/errored handle must yield a non-null error'
        );
        $this->assertNull($results['slow']['body'], 'an errored handle carries no body');
        $this->assertIsInt($results['slow']['status']);
        $this->assertIsFloat($results['slow']['seconds']);
    }

    public function testRunKeepsPerHandleKeysAndIsolatesSuccessFromFailure(): void
    {
        $body = 'second-local-body';
        $okPath = $this->writeTempFile($body);

        $okHandle = curl_init();
        curl_setopt($okHandle, CURLOPT_URL, 'file://' . $okPath);
        curl_setopt($okHandle, CURLOPT_RETURNTRANSFER, true);

        $badHandle = $this->fastFailingHandle();

        $results = (new ParallelHttp())->run([
            'ok' => $okHandle,
            'bad' => $badHandle,
        ]);

        $this->assertSame(['ok', 'bad'], array_keys($results));

        $this->assertNull($results['ok']['error']);
        $this->assertSame($body, $results['ok']['body']);

        $this->assertNotNull($results['bad']['error']);
        $this->assertNull($results['bad']['body']);
    }

    /**
     * A curl handle aimed at an unroutable TEST-NET address with a very short
     * connect timeout, so it fails fast without any real network egress.
     */
    private function fastFailingHandle(): \CurlHandle
    {
        $handle = curl_init();
        // 192.0.2.0/24 is reserved for documentation/testing (RFC 5737) and is
        // not a routable destination, so the connect attempt times out quickly.
        curl_setopt($handle, CURLOPT_URL, 'http://192.0.2.1/never');
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT_MS, 200);
        curl_setopt($handle, CURLOPT_TIMEOUT_MS, 300);

        return $handle;
    }

    private function writeTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tl_phttp_');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->tmpFiles[] = $path;

        return $path;
    }
}
