<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\TurnLoop\IpHasher;

/**
 * Uses a clearly fake salt only.
 *
 * Requirements: 13.14, 14.19
 */
class IpHasherTest extends TestCase
{
    private const SALT = 'fake-test-salt-not-a-secret';

    public function testDeterministic(): void
    {
        $h = new IpHasher(self::SALT);
        $this->assertSame($h->ipHash('8.8.8.8'), $h->ipHash('8.8.8.8'));
        $this->assertSame($h->deviceKey('abc'), (new IpHasher(self::SALT))->deviceKey('abc'));
    }

    public function testSixtyFourLowercaseHex(): void
    {
        $h = new IpHasher(self::SALT);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $h->ipHash('8.8.8.8'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $h->deviceKey('abc'));
    }

    public function testDifferentSaltDiffers(): void
    {
        $a = new IpHasher(self::SALT);
        $b = new IpHasher(self::SALT . '-other');
        $this->assertNotSame($a->ipHash('8.8.8.8'), $b->ipHash('8.8.8.8'));
        $this->assertNotSame($a->deviceKey('abc'), $b->deviceKey('abc'));
    }

    public function testScopesNeverCollide(): void
    {
        $h = new IpHasher(self::SALT);
        $this->assertNotSame($h->ipHash('same'), $h->deviceKey('same'));
    }

    public function testMatchesDocumentedConstruction(): void
    {
        $h = new IpHasher(self::SALT);
        $this->assertSame(hash_hmac('sha256', 'ip:1.2.3.4', self::SALT), $h->ipHash('1.2.3.4'));
        $this->assertSame(hash_hmac('sha256', 'device:xyz', self::SALT), $h->deviceKey('xyz'));
    }
}
