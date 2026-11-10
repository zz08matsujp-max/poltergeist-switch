<?php
declare(strict_types=1);
namespace PoltergeistSwitch\Tests;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\ReflectionTextBuilder;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\TurnLoop\Clients\ClaudeRecorderClient;

/**
 * Protection pins (public-lab Requirements 1.1, 1.2, 1.5): the text versions,
 * the EpisodeConfig defaults and the earthquake golden master, exactly as they
 * were before any public-lab change. The literals are never edited afterwards.
 */
final class ProtectedVersionsTest extends TestCase
{
    public function testSituationTextVersionsArePinned(): void
    {
        $this->assertSame('situation-text-2026-11-09b', SituationTextGenerator::VERSION);
        $this->assertSame('situation-text-heat-2026-11-10c', SituationTextGenerator::VERSION_HEAT);
    }

    public function testReflectionTextVersionsArePinned(): void
    {
        $this->assertSame('reflection-text-2026-11-09d', ReflectionTextBuilder::REFLECTION_TEXT_VERSION);
        $this->assertSame('reflection-text-heat-2026-11-10c', ReflectionTextBuilder::REFLECTION_TEXT_VERSION_HEAT);
    }

    public function testRecorderPromptVersionIsPinned(): void
    {
        $constants = (new \ReflectionClass(ClaudeRecorderClient::class))->getConstants();
        $this->assertSame('recorder-2c', $constants['PROMPT_VERSION']);
    }

    public function testEpisodeConfigDefaultsAreUnchanged(): void
    {
        $json = json_encode(get_object_vars(new EpisodeConfig()), JSON_THROW_ON_ERROR);

        $this->assertSame('ed620fc01bbf7dafc44a33ad0c8dbded715f2ee0da80f3bfae03cc280371a7b5', hash('sha256', $json));
    }

    public function testEarthquakeGoldenMasterFileIsUnchanged(): void
    {
        $hash = hash_file('sha256', __DIR__ . '/fixtures/earthquake_golden_master.json');

        $this->assertStringStartsWith('c2aba50f87c22fe6', $hash);
    }
}
