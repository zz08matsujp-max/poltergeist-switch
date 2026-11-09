<?php
declare(strict_types=1);
namespace PoltergeistSwitch\Tests\Engine;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\ReflectionTextBuilder;
use PoltergeistSwitch\Engine\SituationTextGenerator;
/**
 * Version strings are stored in VARCHAR columns. MySQL is not available in the
 * local suite, so a too-long value is only caught on the live server (strict
 * mode raises SQLSTATE 22001). This database-free test pins the widths.
 *
 * Column widths: sql/turn_loop.sql
 *   prompts.situation_text_version     VARCHAR(32)
 *   recordings.recorder_prompt_version VARCHAR(32)
 *   responses/recordings.failure_reason VARCHAR(64)
 *   episodes.disaster                   VARCHAR(16)
 * and sql/2026-11-09_add_memory.sql: reason VARCHAR(32), kind VARCHAR(12).
 */
class VersionColumnWidthTest extends TestCase
{
    private const VERSION_WIDTH = 32;

    public function testTextVersionsFitTheVersionColumn(): void
    {
        $versions = [
            'SituationTextGenerator::VERSION' => SituationTextGenerator::VERSION,
            'SituationTextGenerator::VERSION_HEAT' => SituationTextGenerator::VERSION_HEAT,
            'ReflectionTextBuilder::REFLECTION_TEXT_VERSION' => ReflectionTextBuilder::REFLECTION_TEXT_VERSION,
            'ReflectionTextBuilder::REFLECTION_TEXT_VERSION_HEAT' => ReflectionTextBuilder::REFLECTION_TEXT_VERSION_HEAT,
        ];
        foreach ($versions as $name => $value) {
            $this->assertLessThanOrEqual(self::VERSION_WIDTH, strlen($value), $name . ' = ' . $value);
        }
    }

    public function testRecorderPromptVersionFitsTheVersionColumn(): void
    {
        $const = new \ReflectionClassConstant(
            \PoltergeistSwitch\TurnLoop\Clients\ClaudeRecorderClient::class,
            'PROMPT_VERSION'
        );
        $this->assertLessThanOrEqual(self::VERSION_WIDTH, strlen((string) $const->getValue()));
    }

    public function testDisasterAndFailureTokensFitTheirColumns(): void
    {
        $this->assertLessThanOrEqual(16, strlen('heat_wave'));
        $this->assertLessThanOrEqual(16, strlen('earthquake'));
        $this->assertLessThanOrEqual(64, strlen('no_reply'));
        // Longest rules reason (memory.reason is VARCHAR(32)).
        $this->assertLessThanOrEqual(32, strlen('ac_only_in_living_room'));
    }
}
