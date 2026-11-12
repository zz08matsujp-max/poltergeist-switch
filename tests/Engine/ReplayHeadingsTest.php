<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use ReflectionClassConstant;

/**
 * Drift guard: the two memories headings in public/js/replay.js must match the
 * ones SituationTextGenerator renders, byte for byte.
 */
class ReplayHeadingsTest extends TestCase
{
    /** @return array<string, string> */
    private function headings(): array
    {
        $out = [];
        foreach (['MEMORIES_HEADING', 'MEMORIES_HEADING_HEAT'] as $name) {
            $out[$name] = (new ReflectionClassConstant(SituationTextGenerator::class, $name))->getValue();
        }
        return $out;
    }

    private function replaySource(): string
    {
        $path = dirname(__DIR__, 2) . '/public/js/replay.js';
        $source = file_get_contents($path);
        $this->assertIsString($source);
        return $source;
    }

    public function testEachHeadingOccursAsSingleQuotedStringInReplayJs(): void
    {
        $source = $this->replaySource();
        foreach ($this->headings() as $name => $value) {
            $this->assertStringContainsString("'" . $value . "'", $source, $name . ' drifted from replay.js');
        }
    }

    public function testTheTwoHeadingsDiffer(): void
    {
        $headings = $this->headings();
        $this->assertNotSame($headings['MEMORIES_HEADING'], $headings['MEMORIES_HEADING_HEAT']);
    }
}
