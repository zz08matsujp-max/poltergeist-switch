<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\DailyLimitPolicy;
use PoltergeistSwitch\TurnLoop\UsageKeys;

/**
 * Requirements: 13.4, 13.5, 13.6, 13.7, 13.10, 14.17
 */
class DailyLimitPolicyTest extends TestCase
{
    private function policy(): DailyLimitPolicy
    {
        return DailyLimitPolicy::fromConfig(new Config());
    }

    public function testFromConfigUsesConfigLimits(): void
    {
        $p = $this->policy();
        $this->assertSame([10, 30, 100], [$p->device, $p->ip, $p->site]);
    }

    public function testDeviceBoundary(): void
    {
        $p = $this->policy();
        $this->assertNull($p->denial(['device' => 9, 'ip' => 0, 'site' => 0]));
        $this->assertSame('daily_device_limit', $p->denial(['device' => 10, 'ip' => 0, 'site' => 0]));
        $this->assertSame('daily_device_limit', $p->denial(['device' => 11, 'ip' => 0, 'site' => 0]));
    }

    public function testIpBoundary(): void
    {
        $p = $this->policy();
        $this->assertNull($p->denial(['device' => 0, 'ip' => 29, 'site' => 0]));
        $this->assertSame('daily_ip_limit', $p->denial(['device' => 0, 'ip' => 30, 'site' => 0]));
        $this->assertSame('daily_ip_limit', $p->denial(['device' => 0, 'ip' => 31, 'site' => 0]));
    }

    public function testSiteBoundary(): void
    {
        $p = $this->policy();
        $this->assertNull($p->denial(['device' => 0, 'ip' => 0, 'site' => 99]));
        $this->assertSame('daily_site_limit', $p->denial(['device' => 0, 'ip' => 0, 'site' => 100]));
        $this->assertSame('daily_site_limit', $p->denial(['device' => 0, 'ip' => 0, 'site' => 101]));
    }

    public function testErrorOrderWhenSeveralReached(): void
    {
        $p = $this->policy();
        $this->assertSame('daily_device_limit', $p->denial(['device' => 10, 'ip' => 30, 'site' => 100]));
        $this->assertSame('daily_ip_limit', $p->denial(['device' => 1, 'ip' => 30, 'site' => 100]));
        $this->assertSame('daily_site_limit', $p->denial(['device' => 1, 'ip' => 1, 'site' => 100]));
    }

    public function testNullIpIsSkipped(): void
    {
        $p = $this->policy();
        $this->assertNull($p->denial(['device' => 0, 'ip' => null, 'site' => 0]));
        $this->assertSame('daily_site_limit', $p->denial(['device' => 0, 'ip' => null, 'site' => 100]));
    }

    public function testUtcDayAroundMidnight(): void
    {
        $midnight = gmmktime(0, 0, 0, 3, 5, 2027);
        $this->assertSame('2027-03-04', DailyLimitPolicy::utcDay($midnight - 1));
        $this->assertSame('2027-03-05', DailyLimitPolicy::utcDay($midnight));
    }

    public function testUsageKeys(): void
    {
        $k = new UsageKeys('d', null);
        $this->assertSame('d', $k->deviceKey);
        $this->assertNull($k->ipKey);
        $this->assertSame(str_repeat('0', 64), UsageKeys::SITE_KEY);
        $this->assertSame(UsageKeys::SITE_KEY, $k->siteKey);
    }
}
