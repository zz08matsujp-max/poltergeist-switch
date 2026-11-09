<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\ReflectionTextBuilder;
use PoltergeistSwitch\Engine\SituationTextGenerator;

/**
 * Golden test for the reflection-text version (task 5.3, Req 1.8, Req 1.9).
 *
 * Req 1.8: the reflection prompt is stored with a Reflection_Text_Version that
 * identifies the fixed body/house text, the lead-in, the event-block forms, and
 * the question wording.
 *
 * Req 1.9 (COUPLING REMINDER): the version SHALL change whenever any of those
 * change. This literal assertion pins the current version so that any edit to
 * the fixed text, the lead-in (ReflectionTextBuilder::LEAD_IN), an event-block
 * form, or the question (ReflectionTextBuilder::QUESTION) forces a reviewer to
 * consciously bump ReflectionTextBuilder::REFLECTION_TEXT_VERSION in the same
 * change, since this test — and the structure/wording golden tests — would
 * otherwise fail. Never update one without the other.
 */
class ReflectionTextVersionTest extends TestCase
{
    /**
     * Req 1.8, Req 1.9: the version constant is pinned to its current dated
     * string. A change to the fixed text, lead-in, event-block forms, or the
     * question must be accompanied by a bump of this literal.
     */
    public function testReflectionTextVersionConstantIsPinned(): void
    {
        $this->assertSame(
            'reflection-text-2026-11-09d',
            ReflectionTextBuilder::REFLECTION_TEXT_VERSION,
            'bump REFLECTION_TEXT_VERSION whenever the fixed text, lead-in, '
            . 'event-block forms, or the question changes',
        );
    }

    /**
     * Req 1.8: version() surfaces the REFLECTION_TEXT_VERSION constant and
     * nothing else, so the stored Reflection_Text_Version always matches.
     */
    public function testVersionMethodReturnsTheConstant(): void
    {
        $builder = new ReflectionTextBuilder(new SituationTextGenerator());

        $this->assertSame(
            'reflection-text-2026-11-09d',
            $builder->version(),
            'ReflectionTextBuilder::version() must surface REFLECTION_TEXT_VERSION',
        );
        $this->assertSame(
            ReflectionTextBuilder::REFLECTION_TEXT_VERSION,
            $builder->version(),
            'version() and the constant must never diverge',
        );
    }

    /**
     * Req 1.8: the reflection-text version is a non-empty string that fits the
     * 32-char prompts version column it is stored in.
     */
    public function testVersionIsNonEmptyAndFitsThePromptColumn(): void
    {
        $version = ReflectionTextBuilder::REFLECTION_TEXT_VERSION;

        $this->assertNotSame('', $version, 'the reflection-text version must not be empty');
        $this->assertLessThanOrEqual(
            32,
            strlen($version),
            'the reflection-text version must fit the 32-char prompts version column',
        );
    }

    /**
     * Heat-wave Req 7.4 (COUPLING REMINDER): the heat-wave reflection version
     * is pinned to its current dated string and MUST differ from the earthquake
     * version. Any edit to the heat-wave fixed text, the heat lead-in, the
     * minute-heading forms, a heat event-block form, or the question must be
     * accompanied by a bump of REFLECTION_TEXT_VERSION_HEAT in the same change,
     * since this test would otherwise fail. The earthquake version must stay
     * byte-for-byte unchanged alongside it (heat-wave Requirement 1.7).
     */
    public function testHeatVersionConstantIsPinnedAndDiffersFromEarthquake(): void
    {
        $this->assertSame(
            'reflection-text-heat-2026-11-10a',
            ReflectionTextBuilder::REFLECTION_TEXT_VERSION_HEAT,
            'bump REFLECTION_TEXT_VERSION_HEAT whenever the heat fixed text, lead-in, '
            . 'minute headings, event-block forms, or the question changes',
        );
        $this->assertSame(
            'reflection-text-2026-11-09d',
            ReflectionTextBuilder::REFLECTION_TEXT_VERSION,
            'the earthquake reflection version must stay byte-for-byte unchanged',
        );
        $this->assertNotSame(
            ReflectionTextBuilder::REFLECTION_TEXT_VERSION,
            ReflectionTextBuilder::REFLECTION_TEXT_VERSION_HEAT,
            'the heat-wave version must differ from the earthquake version',
        );
    }

    /**
     * Heat-wave Req 7.4: heatVersion() surfaces the heat constant, which is the
     * design-mandated dated string. The version column is widened for the
     * heat-wave versions in the storage task; this only pins that the method
     * and the constant never diverge and that the version is non-empty.
     */
    public function testHeatVersionMethodReturnsTheConstant(): void
    {
        $builder = new ReflectionTextBuilder(new SituationTextGenerator());

        $this->assertSame(
            ReflectionTextBuilder::REFLECTION_TEXT_VERSION_HEAT,
            $builder->heatVersion(),
            'heatVersion() must surface REFLECTION_TEXT_VERSION_HEAT',
        );
        $this->assertNotSame(
            '',
            ReflectionTextBuilder::REFLECTION_TEXT_VERSION_HEAT,
            'the heat reflection version must not be empty',
        );
    }
}
