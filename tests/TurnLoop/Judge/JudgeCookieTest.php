<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop\Judge;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\TurnLoop\Judge\JudgeCookie;
use PoltergeistSwitch\TurnLoop\Judge\JudgePass;

/** JudgeCookie (public-lab Requirements 21.14 to 21.18). Every value is generated here. */
class JudgeCookieTest extends TestCase
{
    private const UNTIL = 1801256400;
    private const NOW = 1800000000;

    private string $hash;
    private string $key;

    protected function setUp(): void
    {
        $this->hash = JudgePass::hash(JudgePass::generate());
        $this->key = bin2hex(random_bytes(32));
    }

    private function cookie(?string $hash = null, ?string $key = null, int $until = self::UNTIL): JudgeCookie
    {
        return new JudgeCookie($hash ?? $this->hash, $key ?? $this->key, $until);
    }

    private function issued(): string
    {
        $value = $this->cookie()->issue(self::NOW);
        $this->assertNotNull($value);

        return $value;
    }

    public function testRoundTrip(): void
    {
        $this->assertTrue($this->cookie()->isValid($this->issued(), self::NOW));
        $this->assertSame('2027-01-29T21:00:00Z', $this->cookie()->untilIso());
    }

    public function testPassMatches(): void
    {
        $pass = JudgePass::generate();
        $cookie = new JudgeCookie(JudgePass::hash($pass), $this->key, self::UNTIL);

        $this->assertTrue($cookie->passMatches($pass));
        $this->assertFalse($cookie->passMatches(JudgePass::generate()));
    }

    public function testChangedExpiryIsRefused(): void
    {
        [, $sig] = explode('.', $this->issued());

        $this->assertFalse($this->cookie()->isValid((self::UNTIL - 1) . '.' . $sig, self::NOW));
        $this->assertFalse($this->cookie()->isValid((self::UNTIL + 1) . '.' . $sig, self::NOW));
    }

    public function testChangedSignatureIsRefused(): void
    {
        [$exp, $sig] = explode('.', $this->issued());
        $flipped = ($sig[0] === 'a' ? 'b' : 'a') . substr($sig, 1);

        $this->assertFalse($this->cookie()->isValid($exp . '.' . $flipped, self::NOW));
    }

    public function testOtherHashIsRefused(): void
    {
        $other = JudgePass::hash(JudgePass::generate());

        $this->assertFalse($this->cookie($other)->isValid($this->issued(), self::NOW));
    }

    public function testOtherKeyIsRefused(): void
    {
        $this->assertFalse($this->cookie(null, bin2hex(random_bytes(32)))->isValid($this->issued(), self::NOW));
    }

    public function testExpiryInThePastIsRefused(): void
    {
        $this->assertFalse($this->cookie()->isValid($this->issued(), self::UNTIL + 10));
        $this->assertFalse($this->cookie()->isValid($this->issued(), self::UNTIL));
    }

    public function testTimeAtJudgingEndIsRefusedEvenWithLaterCookieExpiry(): void
    {
        // A cookie signed for a later expiry is still refused once Judging_End has passed,
        // and it is refused when its expiry differs from the configured Judging_End.
        $later = $this->cookie(null, null, self::UNTIL + 1000);
        $value = $later->issue(self::NOW);
        $this->assertNotNull($value);

        $this->assertFalse($this->cookie()->isValid($value, self::NOW));
        $this->assertFalse($later->isValid($value, self::UNTIL + 1000));
    }

    public function testIssueAfterJudgingEndGivesNull(): void
    {
        $this->assertNull($this->cookie()->issue(self::UNTIL));
        $this->assertNull($this->cookie()->issue(self::UNTIL + 5));
    }

    public function testMalformedValuesAndNullAreRefused(): void
    {
        [$exp, $sig] = explode('.', $this->issued());
        $cases = [
            null, '', '.', 'abc', $exp, $exp . '.', '.' . $sig, $exp . '.' . substr($sig, 1),
            $exp . '.' . $sig . '0', $exp . '.' . strtoupper($sig), 'x' . $exp . '.' . $sig,
            $exp . '.' . $sig . "\n", $exp . '..' . $sig, str_repeat('9', 13) . '.' . $sig,
        ];
        foreach ($cases as $case) {
            $this->assertFalse($this->cookie()->isValid($case, self::NOW), var_export($case, true));
        }
    }

    public function testSetCookieHeader(): void
    {
        $header = $this->cookie()->setCookieHeader($this->issued());

        $this->assertStringStartsWith(JudgeCookie::NAME . '=', $header);
        $this->assertSame('ps_judge', JudgeCookie::NAME);
        $this->assertStringContainsString('Expires=Fri, 29 Jan 2027 21:00:00 GMT', $header);
        foreach (['Path=/', 'HttpOnly', 'Secure', 'SameSite=Strict'] as $part) {
            $this->assertStringContainsString($part, $header);
        }
    }
}
