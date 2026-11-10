<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Memory;

use PHPUnit\Framework\TestCase;

/**
 * Static checks for the public-lab migration file.
 *
 * sql/2026-11-10_add_public_lab.sql is a FILE only. It is never run against a
 * database by any test or task; the maintainer applies it over FTPS before
 * deploying the matching code. This test reads it as text and asserts its
 * shape: dated name, header wording, additive-only statements, utf8mb4 and no
 * secret-like text. No SQL is executed and no connection is opened.
 *
 * Requirements: 1.6, 1.7, 5.1, 5.3, 13.17.
 */
final class PublicLabMigrationFileTest extends TestCase
{
    private const MIGRATION = __DIR__ . '/../../sql/2026-11-10_add_public_lab.sql';

    private function source(): string
    {
        self::assertFileExists(self::MIGRATION, 'sql/2026-11-10_add_public_lab.sql must exist.');
        $source = file_get_contents(self::MIGRATION);
        self::assertIsString($source);
        self::assertNotSame('', $source);

        return $source;
    }

    /** The leading `--` comment block. */
    private function header(): string
    {
        $lines = [];
        foreach (preg_split('/\R/', $this->source()) ?: [] as $line) {
            if (!str_starts_with(ltrim($line), '--')) {
                break;
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /** The SQL with `--` comment lines removed. */
    private function code(): string
    {
        $kept = [];
        foreach (preg_split('/\R/', $this->source()) ?: [] as $line) {
            if (!str_starts_with(ltrim($line), '--')) {
                $kept[] = $line;
            }
        }

        return implode("\n", $kept);
    }

    public function testFileExistsWithDatedName(): void
    {
        self::assertFileExists(self::MIGRATION);
        self::assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}_add_public_lab\.sql$/', self::MIGRATION);
    }

    public function testHeaderSaysApplyBeforeDeployingAndNeverRunByTasks(): void
    {
        $header = $this->header();
        self::assertNotSame('', $header, 'The file must start with a header comment.');
        self::assertMatchesRegularExpression('/BEFORE deploying/i', $header);
        self::assertMatchesRegularExpression('/NEVER run against a database by any task/i', $header);
        self::assertStringContainsString('FTPS', $header);
    }

    public function testOnlyAdditiveStatementsAppear(): void
    {
        $code = $this->code();

        self::assertSame(2, preg_match_all('/\bCREATE\s+TABLE\s+(device_resets|daily_usage)\b/i', $code));
        self::assertSame(
            preg_match_all('/\bCREATE\s+TABLE\b/i', $code),
            2,
            'Only the two new tables may be created.',
        );
        self::assertMatchesRegularExpression('/ALTER\s+TABLE\s+memories\b/i', $code);
        self::assertSame(1, preg_match_all('/\bALTER\s+TABLE\b/i', $code), 'Only memories is altered.');

        // The one added column is nullable.
        self::assertMatchesRegularExpression('/ADD\s+COLUMN\s+device_id\s+VARCHAR\(64\)\s+NULL\b/i', $code);
        self::assertSame(1, preg_match_all('/\bADD\s+COLUMN\b/i', $code));
        self::assertDoesNotMatchRegularExpression('/ADD\s+COLUMN[^,;]*NOT\s+NULL/i', $code);

        // The two indexes.
        self::assertMatchesRegularExpression('/ADD\s+KEY\s+idx_device_snapshot\b/i', $code);
        self::assertMatchesRegularExpression('/ADD\s+KEY\s+idx_device_pick\b/i', $code);
    }

    public function testNoDestructiveStatements(): void
    {
        $code = $this->code();
        foreach (['DROP', 'UPDATE', 'DELETE', 'TRUNCATE', 'INSERT', 'REPLACE', 'RENAME', 'MODIFY', 'CHANGE'] as $word) {
            self::assertDoesNotMatchRegularExpression(
                '/\b' . $word . '\b/i',
                $code,
                "The migration must not contain {$word}.",
            );
        }
    }

    public function testDeclaresUtf8mb4OnNewTables(): void
    {
        $code = $this->code();
        self::assertSame(
            2,
            preg_match_all('/DEFAULT\s+CHARSET=utf8mb4\b/i', $code),
            'Both new tables must be utf8mb4.',
        );
    }

    public function testNoSecretLikeText(): void
    {
        $source = $this->source();

        self::assertDoesNotMatchRegularExpression('/\bsk-[A-Za-z0-9_-]{8,}/', $source);
        self::assertDoesNotMatchRegularExpression('/\bBearer\s+\S+/i', $source);
        self::assertDoesNotMatchRegularExpression('/\b(password|passwd|passphrase|api[_-]?key)\b\s*[:=]/i', $source);
        self::assertDoesNotMatchRegularExpression('/\b(anthropic|openai|claude|gpt)\b/i', $source);
        self::assertDoesNotMatchRegularExpression('/\bIDENTIFIED\s+BY\b|\bGRANT\b/i', $source);
    }
}
