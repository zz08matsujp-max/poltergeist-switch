<?php
declare(strict_types=1);
namespace PoltergeistSwitch\Tests\TurnLoop;
use PHPUnit\Framework\TestCase;

/** Feature: public-lab, api/.htaccess denies direct access to api/ PHP files except index.php. */
final class ApiHtaccessTest extends TestCase
{
    private string $text;

    protected function setUp(): void
    {
        $text = file_get_contents(dirname(__DIR__, 2) . '/api/.htaccess');
        $this->assertIsString($text);
        $this->text = $text;
    }

    private function pattern(): string
    {
        $this->assertSame(1, preg_match('/<FilesMatch\s+"([^"]+)">/', $this->text, $m));
        return '~' . str_replace('~', '\~', $m[1]) . '~';
    }

    public function testPatternDeniesEveryPhpFileExceptIndex(): void
    {
        $p = $this->pattern();
        $this->assertSame(0, preg_match($p, 'index.php'));
        foreach (['bootstrap.php', 'episodes_ask.php', 'router.php', 'config.local.php'] as $f) {
            $this->assertSame(1, preg_match($p, $f), $f);
        }
    }

    public function testDenyRulesPresent(): void
    {
        $this->assertStringContainsString('Require all denied', $this->text);
        $this->assertStringContainsString('Order allow,deny', $this->text);
        $this->assertStringContainsString('Deny from all', $this->text);
    }

    public function testDenyComesBeforeRewriteAndExistingRulesRemain(): void
    {
        $this->assertLessThan(strpos($this->text, 'RewriteEngine'), strpos($this->text, 'FilesMatch'));
        $this->assertStringContainsString('RewriteRule ^(.*)$ index.php?route=$1 [QSA,L]', $this->text);
        $this->assertStringContainsString('Header always set Cache-Control "no-store"', $this->text);
    }
}
