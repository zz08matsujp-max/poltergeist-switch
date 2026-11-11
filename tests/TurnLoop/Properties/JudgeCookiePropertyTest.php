<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\TurnLoop\Judge\JudgeCookie;

/**
 * Property-based test for the Judge_Cookie (Eris).
 *
 * Feature: public-lab, Property 27: A Judge_Cookie is accepted only with the right signature and before both expiries
 *
 * **Validates: Requirements 21.14, 21.16, 21.17, 21.18**
 */
class JudgeCookiePropertyTest extends TestCase
{
    use TestTrait;

    private const MUTATIONS = [
        'none', 'expiry_plus', 'expiry_minus', 'sig_flip', 'other_hash', 'other_key',
        'expiry_at_now', 'wrong_shape', 'empty', 'null', 'upper_sig', 'short_sig',
    ];

    private static function hex(int $seed, string $label): string
    {
        return hash('sha256', $label . ':' . $seed);
    }

    private static function sig(int $expiry, string $hash, string $keyHex): string
    {
        return hash_hmac('sha256', $expiry . '.' . $hash, (string) hex2bin($keyHex));
    }

    public function testIssuedCookieIsAcceptedBeforeJudgingEnd(): void
    {
        // Feature: public-lab, Property 27: A Judge_Cookie is accepted only with the right signature and before both expiries
        $this
            ->limitTo(100)
            ->forAll(
                Generators::choose(0, 1_000_000),
                Generators::choose(0, 1_000_000),
                Generators::choose(1_000_000_000, 2_000_000_000),
                Generators::choose(1, 100_000_000),
            )
            ->then(function (int $hs, int $ks, int $end, int $back): void {
                $cookie = new JudgeCookie(self::hex($hs, 'h'), self::hex($ks, 'k'), $end);
                $now = $end - $back;
                $value = $cookie->issue($now);
                $this->assertNotNull($value);
                $this->assertTrue($cookie->isValid($value, $now));
                $this->assertTrue($cookie->isValid($value, $end - 1));
            });
    }

    public function testIsValidMatchesTheModelForEveryMutation(): void
    {
        // Feature: public-lab, Property 27: A Judge_Cookie is accepted only with the right signature and before both expiries
        $this
            ->limitTo(100)
            ->forAll(
                Generators::choose(0, 1_000_000),
                Generators::choose(0, 1_000_000),
                Generators::choose(1_000_000_000, 2_000_000_000),
                Generators::choose(-100_000_000, 100_000_000), // now relative to Judging_End
                Generators::elements(...self::MUTATIONS),
                Generators::choose(1, 100_000_000),            // size of an expiry change
                Generators::choose(0, 63),                     // signature position to flip
            )
            ->then(function (int $hs, int $ks, int $end, int $delta, string $mutation, int $step, int $pos): void {
                $hash = self::hex($hs, 'h');
                $key = self::hex($ks, 'k');
                $now = $end + $delta;
                $cookie = new JudgeCookie($hash, $key, $end);

                $expiry = $end;
                $sig = self::sig($end, $hash, $key);
                $raw = null;
                switch ($mutation) {
                    case 'expiry_plus':
                        $expiry = $end + $step; // signature stays that of the old expiry
                        break;
                    case 'expiry_minus':
                        $expiry = $end - $step;
                        break;
                    case 'sig_flip':
                        $sig[$pos] = $sig[$pos] === '0' ? '1' : '0';
                        break;
                    case 'other_hash':
                        $sig = self::sig($end, self::hex($hs + 1, 'h'), $key);
                        break;
                    case 'other_key':
                        $sig = self::sig($end, $hash, self::hex($ks + 1, 'k'));
                        break;
                    case 'expiry_at_now':
                        $expiry = $now;
                        $sig = self::sig($expiry, $hash, $key); // correctly signed, but not later than now
                        break;
                    case 'wrong_shape':
                        $raw = $end . '-' . $sig;
                        break;
                    case 'empty':
                        $raw = '';
                        break;
                    case 'null':
                        $raw = null;
                        $expiry = -1;
                        break;
                    case 'upper_sig':
                        $sig = strtoupper($sig);
                        break;
                    case 'short_sig':
                        $sig = substr($sig, 0, 63);
                        break;
                }

                if ($mutation === 'null') {
                    $value = null;
                    $expected = false;
                } elseif ($mutation === 'empty' || $mutation === 'wrong_shape') {
                    $value = $raw;
                    $expected = false;
                } else {
                    $value = $expiry . '.' . $sig;
                    $expected = $sig === self::sig($expiry, $hash, $key)
                        && $expiry === $end
                        && $expiry > $now
                        && $now < $end;
                }

                $this->assertSame($expected, $cookie->isValid($value, $now));
                if ($now >= $end) {
                    $this->assertFalse($cookie->isValid($value, $now));
                    $this->assertNull($cookie->issue($now));
                }
            });
    }
}
