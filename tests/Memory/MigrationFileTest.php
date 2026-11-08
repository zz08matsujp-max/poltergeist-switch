<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Memory;

use PHPUnit\Framework\TestCase;

/**
 * Static checks for the robot-memory migration file.
 *
 * The migration sql/2026-11-09_add_memory.sql is a FILE only — it is never run
 * against a database by any test; the maintainer applies it on the server over
 * FTPS. This test therefore reads the file as text and asserts its shape: that
 * it exists, declares the utf8mb4 character set, defines the three new tables
 * (memories, memory_generation, reflections), adds the three episodes columns,
 * and adds the `kind` column to both prompts and responses. No SQL is executed,
 * no database connection is opened, and no secret is read.
 *
 * Requirements: 3.1, 8.2, 2.5.
 */
final class MigrationFileTest extends TestCase
{
    private const MIGRATION = __DIR__ . '/../../sql/2026-11-09_add_memory.sql';

    private function migrationSource(): string
    {
        $path = self::MIGRATION;
        self::assertFileExists($path, 'The migration file sql/2026-11-09_add_memory.sql must exist.');

        $source = file_get_contents($path);
        self::assertIsString($source, 'The migration file must be readable.');
        self::assertNotSame('', $source, 'The migration file must not be empty.');

        return $source;
    }

    public function testMigrationFileExists(): void
    {
        self::assertFileExists(self::MIGRATION);
    }

    public function testMigrationDeclaresUtf8mb4(): void
    {
        $source = $this->migrationSource();
        self::assertStringContainsString('utf8mb4', $source, 'The migration must declare the utf8mb4 character set.');
    }

    public function testMigrationCreatesMemoriesTable(): void
    {
        $source = $this->migrationSource();
        self::assertMatchesRegularExpression(
            '/CREATE\s+TABLE\s+memories\b/i',
            $source,
            'The migration must create the memories table.',
        );
    }

    public function testMigrationCreatesMemoryGenerationTable(): void
    {
        $source = $this->migrationSource();
        self::assertMatchesRegularExpression(
            '/CREATE\s+TABLE\s+memory_generation\b/i',
            $source,
            'The migration must create the memory_generation table.',
        );
    }

    public function testMigrationCreatesReflectionsTable(): void
    {
        $source = $this->migrationSource();
        self::assertMatchesRegularExpression(
            '/CREATE\s+TABLE\s+reflections\b/i',
            $source,
            'The migration must create the reflections table.',
        );
    }

    public function testMigrationAddsTheThreeEpisodesColumns(): void
    {
        $source = $this->migrationSource();

        foreach (['memory_generation', 'robot_a_memory_snapshot', 'robot_b_memory_snapshot'] as $column) {
            self::assertStringContainsString(
                $column,
                $source,
                "The migration must add the episodes column {$column}.",
            );
        }

        self::assertMatchesRegularExpression(
            '/ALTER\s+TABLE\s+episodes\b/i',
            $source,
            'The migration must alter the episodes table.',
        );
    }

    public function testMigrationAddsKindColumnToPromptsAndResponses(): void
    {
        $source = $this->migrationSource();

        self::assertMatchesRegularExpression(
            '/ALTER\s+TABLE\s+prompts\s+ADD\s+COLUMN\s+kind\b/i',
            $source,
            'The migration must add the kind column to the prompts table.',
        );

        self::assertMatchesRegularExpression(
            '/ALTER\s+TABLE\s+responses\s+ADD\s+COLUMN\s+kind\b/i',
            $source,
            'The migration must add the kind column to the responses table.',
        );
    }
}
