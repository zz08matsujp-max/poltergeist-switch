<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop\Judge;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\TurnLoop\Judge\JudgePass;

/** JudgePass (public-lab Requirement 21). Every value is generated here. */
class JudgePassTest extends TestCase
{
    public function testGeneratedPassShape(): void
    {
        $pass = JudgePass::generate();

        $this->assertSame(43, strlen($pass));
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $pass);
        $this->assertTrue(JudgePass::isWellFormedPass($pass));
        $decoded = base64_decode(strtr($pass, '-_', '+/') . '=', true);
        $this->assertNotFalse($decoded);
        $this->assertSame(32, strlen($decoded));
    }

    public function testTwoPassesDiffer(): void
    {
        $this->assertNotSame(JudgePass::generate(), JudgePass::generate());
    }

    public function testHashIsSixtyFourLowercaseHex(): void
    {
        $hash = JudgePass::hash(JudgePass::generate());

        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $hash);
        $this->assertTrue(JudgePass::isWellFormedHash($hash));
        $this->assertFalse(JudgePass::isWellFormedPass($hash));
    }

    public function testMatchesOnlyTheGeneratingPass(): void
    {
        $pass = JudgePass::generate();
        $hash = JudgePass::hash($pass);

        $this->assertTrue(JudgePass::matches($pass, $hash));
        $this->assertFalse(JudgePass::matches(JudgePass::generate(), $hash));
        $this->assertFalse(JudgePass::matches('', $hash));
    }

    public function testHashPassedAsPassDoesNotMatch(): void
    {
        $hash = JudgePass::hash(JudgePass::generate());

        $this->assertFalse(JudgePass::matches($hash, $hash));
    }
}
