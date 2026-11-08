<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\ActionExecutor;
use PoltergeistSwitch\Engine\EarthquakePhase;
use PoltergeistSwitch\Engine\Engine;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\ResidentController;
use PoltergeistSwitch\Engine\RulesEvaluator;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\SituationSpec;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Engine\WorldModel;

/**
 * Tests for the situation-text version surfaced by the Engine (task 29.2,
 * Req 14.1–14.3).
 *
 * Req 14.1: the Engine exposes the situation-text version via
 * situationTextVersion(): string.
 * Req 14.3: the version identifies the fixed text and generator that produced
 * the text, so Engine::situationTextVersion() must return the one
 * SituationTextGenerator::VERSION constant and nothing else.
 *
 * Req 14.2 (COUPLING REMINDER): the version SHALL change whenever the fixed
 * body text (SituationTextGenerator::FIXED_BODY), the fixed house text
 * (SituationTextGenerator::FIXED_HOUSE), or the generator's output format
 * (section order and phrasing in SituationTextGenerator::generate()) changes.
 *
 * That coupling cannot be asserted by comparing to a literal here — doing so
 * would just restate the constant and never fail. The real guard is the golden
 * suite in SituationTextGeneratorTest: the S0–S5 and turn-2 golden tests pin
 * the generator's exact output byte-for-byte. If a change moves any golden
 * output, those tests fail until the golden fixtures are updated, and the
 * reviewer updating them MUST bump SituationTextGenerator::VERSION in the same
 * change. In other words: a golden test and this version constant are coupled —
 * never update one without the other.
 */
class EngineSituationTextVersionTest extends TestCase
{
    /**
     * Req 14.1, Req 14.3: Engine::situationTextVersion() returns exactly the
     * SituationTextGenerator::VERSION constant.
     */
    public function testSituationTextVersionReturnsGeneratorVersionConstant(): void
    {
        $config = new EpisodeConfig();
        $world  = new WorldModel($config);
        $world->reset(
            Seed::fromInt(1),
            new SituationSpec(
                time: 'afternoon',
                robotARoom: 'kitchen',
                robotBRoom: 'bedroom',
                manActivity: 'sitting_at_table',
                potBoiling: false,
            ),
        );

        $engine = new Engine(
            $world,
            $config,
            new RulesEvaluator(),
            new ActionExecutor(),
            new EarthquakePhase(),
            new ResidentController(),
            new SituationTextGenerator(),
        );

        $this->assertSame(
            SituationTextGenerator::VERSION,
            $engine->situationTextVersion(),
            'Engine::situationTextVersion() must surface SituationTextGenerator::VERSION',
        );
    }

    /**
     * Req 14.3: the version is a non-empty string that identifies the fixed
     * text and generator. The turn-loop prompts.situation_text_version column
     * is 32 characters wide, so the constant must fit.
     */
    public function testVersionIsNonEmptyAndFitsThePromptColumn(): void
    {
        $version = SituationTextGenerator::VERSION;

        $this->assertNotSame('', $version, 'the situation-text version must not be empty');
        $this->assertLessThanOrEqual(
            32,
            strlen($version),
            'the situation-text version must fit the 32-char prompts.situation_text_version column',
        );
    }
}
