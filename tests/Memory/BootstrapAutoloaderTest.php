<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Memory;

use PHPUnit\Framework\TestCase;

/**
 * Guard test for the hand-written PSR-4 autoloader in api/bootstrap.php.
 *
 * The server (onamae.com RS) has no Composer and no vendor/ directory, so the
 * production entry points rely on the small PSR-4 autoloader registered in
 * api/bootstrap.php. If a new top-level namespace ships under src/ without a
 * matching prefix in that autoloader, the matching api/ endpoints resolve no
 * class and return HTTP 500 on the server (while passing locally under the
 * Composer autoloader, so the gap is invisible in development).
 *
 * This test asserts the invariant that keeps the two in sync: for every
 * top-level directory <Dir> under src/, the hand-written prefix list in
 * api/bootstrap.php maps `PoltergeistSwitch\<Dir>\` to `../src/<Dir>/`. It parses
 * the bootstrap source statically; it runs no SQL, opens no network connection,
 * and reads no secret.
 */
final class BootstrapAutoloaderTest extends TestCase
{
    private const SRC_DIR = __DIR__ . '/../../src';
    private const BOOTSTRAP = __DIR__ . '/../../api/bootstrap.php';

    /**
     * Every top-level namespace directory under src/ must be registered in the
     * hand-written autoloader prefix list in api/bootstrap.php.
     */
    public function testEverySrcNamespaceIsRegisteredInBootstrap(): void
    {
        $prefixes = $this->bootstrapPrefixMap();

        foreach ($this->srcNamespaceDirs() as $dir) {
            $prefix = 'PoltergeistSwitch\\' . $dir . '\\';
            $expectedTarget = '../src/' . $dir . '/';

            self::assertArrayHasKey(
                $prefix,
                $prefixes,
                "src/{$dir} has no '{$prefix}' entry in the api/bootstrap.php autoloader; "
                    . 'the matching api/ endpoints would return HTTP 500 on the server.',
            );

            self::assertSame(
                $expectedTarget,
                $prefixes[$prefix],
                "The '{$prefix}' entry in api/bootstrap.php must map to '{$expectedTarget}'.",
            );
        }
    }

    /**
     * Sanity: the Memory namespace in particular is registered (this task's
     * reason for the guard).
     */
    public function testMemoryNamespaceIsRegistered(): void
    {
        $prefixes = $this->bootstrapPrefixMap();

        self::assertArrayHasKey('PoltergeistSwitch\\Memory\\', $prefixes);
        self::assertSame('../src/Memory/', $prefixes['PoltergeistSwitch\\Memory\\']);
    }

    /**
     * The top-level directory names directly under src/ (each one a namespace).
     *
     * @return list<string>
     */
    private function srcNamespaceDirs(): array
    {
        $srcDir = realpath(self::SRC_DIR);
        self::assertIsString($srcDir, 'src/ directory must exist.');

        $dirs = [];
        foreach (scandir($srcDir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($srcDir . DIRECTORY_SEPARATOR . $entry)) {
                $dirs[] = $entry;
            }
        }
        sort($dirs);

        self::assertNotEmpty($dirs, 'Expected at least one namespace directory under src/.');

        return $dirs;
    }

    /**
     * Parse the hand-written `$prefixes` map in api/bootstrap.php into
     * prefix => target pairs, where the target is normalised to the path that
     * follows `__DIR__ . '/' ` (e.g. '../src/Memory/').
     *
     * @return array<string,string>
     */
    private function bootstrapPrefixMap(): array
    {
        $source = file_get_contents(self::BOOTSTRAP);
        self::assertIsString($source, 'api/bootstrap.php must be readable.');

        // Match entries of the form:
        //   'PoltergeistSwitch\\Memory\\' => __DIR__ . '/../src/Memory/',
        $pattern = "/'(PoltergeistSwitch\\\\[^']+)'\\s*=>\\s*__DIR__\\s*\\.\\s*'([^']+)'/";
        $matches = [];
        preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);

        $map = [];
        foreach ($matches as $match) {
            // $match[1] keeps the doubled backslashes from the source literal
            // (e.g. 'PoltergeistSwitch\\Memory\\'); decode them to real ones.
            $prefix = str_replace('\\\\', '\\', $match[1]);
            // $match[2] is like '/../src/Memory/'; drop the leading slash so it
            // matches the '../src/Memory/' form used in the assertions.
            $target = ltrim($match[2], '/');
            $map[$prefix] = $target;
        }

        self::assertNotEmpty($map, 'Expected at least one autoloader prefix in api/bootstrap.php.');

        return $map;
    }
}
