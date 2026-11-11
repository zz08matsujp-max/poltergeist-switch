<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\TurnLoop\Config;

/** Judge settings of Config (public-lab Requirements 21.1, 21.4). */
class ConfigJudgeTest extends TestCase
{
    public function testDefaults(): void
    {
        $c = new Config();

        $this->assertSame('2027-01-29T21:00:00Z', $c->judgeUntilUtc);
        $this->assertSame(100, $c->judgeDailyLimit);
        $this->assertSame(1801256400, $c->judgeUntilUnix());
    }

    public function testMalformedDateThrows(): void
    {
        foreach (['2027-01-29 21:00:00', '2027-02-30T21:00:00Z', 'soon', ''] as $bad) {
            try {
                new Config(judgeUntilUtc: $bad);
                $this->fail('accepted ' . $bad);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testDailyLimitBelowOneThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Config(judgeDailyLimit: 0);
    }

    public function testDailyLimitOfOneIsAccepted(): void
    {
        $this->assertSame(1, (new Config(judgeDailyLimit: 1))->judgeDailyLimit);
    }
}
