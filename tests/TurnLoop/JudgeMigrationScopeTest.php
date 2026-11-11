<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop;

use PHPUnit\Framework\TestCase;

/**
 * The `judge` usage scope needs no SQL change (public-lab Requirements 21.29,
 * 14.36): it must fit daily_usage.scope and no SQL file may mention it.
 */
class JudgeMigrationScopeTest extends TestCase
{
    private const SQL_DIR = __DIR__ . '/../../sql';

    public function testScopeColumnIsWideEnoughForJudge(): void
    {
        $sql = file_get_contents(self::SQL_DIR . '/2026-11-10_add_public_lab.sql');
        $this->assertIsString($sql);

        $this->assertSame(
            1,
            preg_match('/CREATE TABLE\s+daily_usage\s*\((.*?)\)\s*ENGINE/is', $sql, $table),
            'daily_usage table definition not found',
        );
        $this->assertSame(
            1,
            preg_match('/^\s*scope\s+VARCHAR\((\d+)\)/im', $table[1], $m),
            'daily_usage.scope width not found',
        );
        $this->assertGreaterThanOrEqual(strlen('judge'), (int) $m[1]);
    }

    public function testNoSqlFileMentionsJudge(): void
    {
        $files = glob(self::SQL_DIR . '/*') ?: [];
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            if (is_file($file)) {
                // Whole word: the existing `judgement_json` column is unrelated.
                $this->assertSame(
                    0,
                    preg_match('/\bjudge\b/i', (string) file_get_contents($file)),
                    basename($file),
                );
            }
        }
    }
}
