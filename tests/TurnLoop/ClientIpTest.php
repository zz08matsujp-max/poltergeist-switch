<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\TurnLoop\ClientIp;

/**
 * Requirements: 13.14, 14.19
 */
class ClientIpTest extends TestCase
{
    public function testPublicV4IsCanonical(): void
    {
        $this->assertSame('8.8.8.8', ClientIp::normalize('8.8.8.8'));
        $this->assertSame('1.1.1.1', ClientIp::normalize(' 1.1.1.1 '));
    }

    public function testPublicV6ReducedToSlash64(): void
    {
        $a = ClientIp::normalize('2606:4700:4700::1111');
        $b = ClientIp::normalize('2606:4700:4700:0:aaaa:bbbb:cccc:dddd');
        $this->assertNotNull($a);
        $this->assertSame($a, $b);
        $this->assertSame('2606:4700:4700::/64', $a);
        $this->assertNotSame($a, ClientIp::normalize('2606:4700:4701::1'));
    }

    public function testMappedV4IsUnwrapped(): void
    {
        $this->assertSame('8.8.8.8', ClientIp::normalize('::ffff:8.8.8.8'));
        $this->assertNull(ClientIp::normalize('::ffff:10.0.0.1'));
    }

    /** @dataProvider rejected */
    public function testRejected(?string $raw): void
    {
        $this->assertNull(ClientIp::normalize($raw));
    }

    /** @return array<string, array{?string}> */
    public static function rejected(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'loopback v4' => ['127.0.0.1'],
            'private 10' => ['10.1.2.3'],
            'private 172' => ['172.16.0.1'],
            'private 192.168' => ['192.168.1.5'],
            'link-local v4' => ['169.254.1.1'],
            'reserved' => ['240.0.0.1'],
            'loopback v6' => ['::1'],
            'unspecified v6' => ['::'],
            'link-local v6' => ['fe80::1'],
            'unique local v6' => ['fd00::1'],
            'garbage' => ['not an ip'],
            'partial' => ['1.2.3'],
        ];
    }

    public function testIdempotentOnV4(): void
    {
        $once = ClientIp::normalize('8.8.4.4');
        $this->assertSame($once, ClientIp::normalize($once));
    }
}
