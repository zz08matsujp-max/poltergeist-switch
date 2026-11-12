<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\TurnLoop\Config;

/**
 * Requirements: 6.1, 13.3
 */
class ConfigPublicLabTest extends TestCase
{
    public function testFeaturedIdsExactAndInOrder(): void
    {
        $this->assertSame([
            '16fe8bdc-4e6c-4c14-ba34-58a80c9f1843',
            'b9931223-fc11-4162-8a4d-a8cf55886e1e',
            '450e9249-94db-4744-987d-ec6774bf5057',
            '4113e82c-2f09-49e4-b647-a1481d794807',
            '2a3800d5-4029-448c-8152-fd622693a775',
            '3ef560a5-c35c-4632-869f-d01fb2dd2e2b',
            'bae88cfc-7eea-4cb6-a742-a49c0ccc401c',
            '36e82c3e-03bc-4396-bfdc-e3fc33ffe671',
            '186823c0-3ff4-4289-ad30-ca98ef97708e',
        ], (new Config())->featuredEpisodeIds);
    }

    public function testDefaultLimits(): void
    {
        $c = new Config();
        $this->assertSame(60, $c->unaskedReleaseSeconds);
        $this->assertSame(300, $c->idleSlotReleaseSeconds);
        $this->assertSame(10, $c->dailyDeviceLimit);
        $this->assertSame(30, $c->dailyIpLimit);
        $this->assertSame(100, $c->dailySiteLimit);
    }

    public function testLimitBelowOneIsRejected(): void
    {
        foreach (['dailyDeviceLimit', 'dailyIpLimit', 'dailySiteLimit'] as $name) {
            try {
                new Config(...[$name => 0]);
                $this->fail("$name = 0 should be rejected");
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testLimitOfOneIsAccepted(): void
    {
        $this->assertSame(1, (new Config(dailyDeviceLimit: 1))->dailyDeviceLimit);
    }

    public function testMalformedFeaturedIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Config(featuredEpisodeIds: ['not-a-uuid']);
    }
}
