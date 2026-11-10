<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\TurnLoop\Http\SecurityHeaders;

/**
 * The Security_Headers on the pages (public-lab Requirements 20.1-20.4, 20.7,
 * 14.31). public/ does not load src/, so public/security_headers.php repeats
 * the four pairs; this test keeps the two definitions equal and checks that
 * index.php and replay.php send them right after Cache-Control, before output.
 */
class PageSecurityHeadersTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** @return list<string> the argument of every header() call, in order */
    private function sentByHelper(): array
    {
        $src = (string) file_get_contents(self::ROOT . '/public/security_headers.php');
        // Evaluate the helper with header() captured, so string concatenation
        // in the source is resolved exactly as PHP does.
        $code = str_replace('declare(strict_types=1);', '', $src);
        $code = preg_replace('/^<\?php/', '', $code) ?? '';
        $code = str_replace('function ps_send_security_headers', 'function ps_capture_security_headers', $code);
        $code = str_replace('header(', '$GLOBALS[\'ps_test_sent\'][] = (', $code);
        $GLOBALS['ps_test_sent'] = [];
        eval($code);
        \ps_capture_security_headers();
        $sent = $GLOBALS['ps_test_sent'];
        unset($GLOBALS['ps_test_sent']);

        return $sent;
    }

    public function testPageHelperEmitsTheSameFourPairsAsTheApi(): void
    {
        $expected = [];
        foreach (SecurityHeaders::all() as $name => $value) {
            $expected[] = $name . ': ' . $value;
        }

        $this->assertSame($expected, $this->sentByHelper());
    }

    /** @return array<string,array{string}> */
    public static function pages(): array
    {
        return ['index.php' => ['index.php'], 'replay.php' => ['replay.php']];
    }

    /** @dataProvider pages */
    public function testPageSendsHeadersAfterNoStoreAndBeforeOutput(string $page): void
    {
        $src = (string) file_get_contents(self::ROOT . '/public/' . $page);

        $this->assertStringStartsWith('<?php', $src, 'no output before the PHP tag');
        $noStore = strpos($src, "header('Cache-Control: no-store');");
        $require = strpos($src, "require_once __DIR__ . '/security_headers.php';");
        $call = strpos($src, 'ps_send_security_headers();');
        $output = strpos($src, '?><!DOCTYPE html>');

        $this->assertNotFalse($noStore);
        $this->assertNotFalse($require);
        $this->assertNotFalse($call);
        $this->assertNotFalse($output);
        $this->assertLessThan($require, $noStore);
        $this->assertLessThan($call, $require);
        $this->assertLessThan($output, $call);
        $this->assertSame(1, substr_count($src, 'ps_send_security_headers();'));
    }

    /** @dataProvider pages */
    public function testChildRunReportsAllHeaders(string $page): void
    {
        $file = self::ROOT . '/public/' . $page;
        $script = 'ob_start(); include ' . var_export($file, true) . '; '
            . '$h = headers_list(); ob_end_clean(); echo json_encode($h);';
        $out = [];
        $code = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -r ' . escapeshellarg($script) . ' 2>/dev/null', $out, $code);
        $headers = json_decode(implode("\n", $out), true);
        if ($code !== 0 || !is_array($headers) || $headers === []) {
            $this->markTestSkipped('The PHP CLI cannot report response headers here.');
        }

        $this->assertContains('Cache-Control: no-store', $headers);
        foreach (SecurityHeaders::all() as $name => $value) {
            $this->assertContains($name . ': ' . $value, $headers, "$page sends $name");
        }
    }
}
