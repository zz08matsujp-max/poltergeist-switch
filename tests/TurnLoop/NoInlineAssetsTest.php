<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop;

use PHPUnit\Framework\TestCase;

/**
 * The page policy has no 'unsafe-inline', so the pages, the stylesheet and the
 * scripts must hold no inline style or script (public-lab Requirements 20.1,
 * 20.7, 14.31).
 */
class NoInlineAssetsTest extends TestCase
{
    private const PUBLIC_DIR = __DIR__ . '/../../public';

    /** @return array<string,array{string}> */
    public static function assets(): array
    {
        $files = [
            self::PUBLIC_DIR . '/index.php',
            self::PUBLIC_DIR . '/replay.php',
            self::PUBLIC_DIR . '/css/screens.css',
        ];
        foreach (glob(self::PUBLIC_DIR . '/js/*.js') ?: [] as $js) {
            $files[] = $js;
        }

        $cases = [];
        foreach ($files as $file) {
            $cases[basename($file)] = [$file];
        }

        return $cases;
    }

    public function testTheAssetListIsNotEmpty(): void
    {
        $names = array_keys(self::assets());
        $this->assertContains('index.php', $names);
        $this->assertContains('replay.php', $names);
        $this->assertContains('screens.css', $names);
        $this->assertGreaterThan(3, count($names), 'every public/js file is listed');
    }

    /** @dataProvider assets */
    public function testHasNoInlineStyleOrScript(string $file): void
    {
        $src = (string) file_get_contents($file);
        $this->assertNotSame('', $src);

        $this->assertDoesNotMatchRegularExpression('/<style/i', $src, 'no <style');
        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=\s*["\']/i', $src, 'no style attribute');
        $this->assertStringNotContainsString('style="', $src);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=\s*["\']/i', $src, 'no on<event> attribute');
        $this->assertDoesNotMatchRegularExpression('/javascript\s*:/i', $src, 'no javascript: URL');

        // Every <script ...> element must be an empty external one.
        if (preg_match_all('#<script\b([^>]*)>(.*?)</script>#is', $src, $m, PREG_SET_ORDER)) {
            foreach ($m as $tag) {
                $this->assertMatchesRegularExpression('/\bsrc\s*=/i', $tag[1], 'script has a src');
                $this->assertSame('', trim($tag[2]), 'script has no inline body');
            }
        }
        $this->assertSame(
            substr_count(strtolower($src), '<script'),
            substr_count(strtolower($src), '</script>'),
            'every <script is closed'
        );
    }
}
