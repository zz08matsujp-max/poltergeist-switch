<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\Seed;

/**
 * Unit tests for Seed (Requirements: Req 1.11, Req 3.8)
 *
 * Req 1.11: reset() called twice with the same seed produces identical values.
 * Req 3.8:  Given the same seed and no robot actions, the same outcome is always produced.
 */
class SeedTest extends TestCase
{
    // ------------------------------------------------------------------
    // Test 1: Same seed + same call count → same sequence
    // ------------------------------------------------------------------

    public function testSameSeedProducesSameSequence(): void
    {
        $a = Seed::fromInt(42);
        $b = Seed::fromInt(42);

        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(
                $a->next(0, 100),
                $b->next(0, 100),
                "Draw #$i should be identical for two Seed(42) instances"
            );
        }
    }

    public function testSameSeedDifferentCallCountsProduceSameValues(): void
    {
        // Replay the exact same draw pattern; verifies reproducibility
        $seed1 = Seed::fromInt(99);
        $draws1 = [];
        for ($i = 0; $i < 5; $i++) {
            $draws1[] = $seed1->next(1, 6);
        }

        $seed2 = Seed::fromInt(99);
        $draws2 = [];
        for ($i = 0; $i < 5; $i++) {
            $draws2[] = $seed2->next(1, 6);
        }

        $this->assertSame($draws1, $draws2, 'Identical seeds must produce identical draw sequences');
    }

    public function testSeedValueIsStoredAndReadable(): void
    {
        $seed = Seed::fromInt(12345);
        $this->assertSame(12345, $seed->value);
    }

    // ------------------------------------------------------------------
    // Test 2: Two distinct instances from the same int produce the same
    //         sequence independently (their Randomizer state does not bleed
    //         between instances)
    // ------------------------------------------------------------------

    public function testTwoInstancesFromSameIntAreIndependent(): void
    {
        $first  = Seed::fromInt(7);
        $second = Seed::fromInt(7);

        // Advance $first by several draws before $second starts
        for ($i = 0; $i < 5; $i++) {
            $first->next(0, 99);
        }

        // Now start drawing from $second; it should still begin at draw #0
        $expectedFirst  = Seed::fromInt(7);
        $expectedSecond = Seed::fromInt(7);

        // Fast-forward a fresh instance to draw #5
        for ($i = 0; $i < 5; $i++) {
            $expectedFirst->next(0, 99);
        }

        $this->assertSame(
            $expectedFirst->next(0, 99),
            $first->next(0, 99),
            '$first at draw #5 should equal a fresh Seed(7) also at draw #5'
        );

        $this->assertSame(
            $expectedSecond->next(0, 99),
            $second->next(0, 99),
            '$second at draw #0 should equal a fresh Seed(7) at draw #0'
        );
    }

    public function testInstancesShareNoGlobalState(): void
    {
        // Create and exhaust one instance; a new instance with the same int
        // must still start at draw #0 regardless of what the first instance did.
        $discard = Seed::fromInt(1000);
        for ($i = 0; $i < 20; $i++) {
            $discard->next(0, PHP_INT_MAX);
        }

        $fresh1 = Seed::fromInt(1000);
        $fresh2 = Seed::fromInt(1000);

        $this->assertSame(
            $fresh1->next(0, PHP_INT_MAX),
            $fresh2->next(0, PHP_INT_MAX),
            'Fresh instances must not be affected by a previously exhausted instance'
        );
    }

    // ------------------------------------------------------------------
    // Test 3: next() with $min > $max throws InvalidArgumentException
    // ------------------------------------------------------------------

    public function testNextThrowsWhenMinGreaterThanMax(): void
    {
        $seed = Seed::fromInt(0);

        $this->expectException(\InvalidArgumentException::class);
        $seed->next(10, 5);
    }

    public function testNextThrowsWhenMinEqualsPlusOneAboveMax(): void
    {
        $seed = Seed::fromInt(0);

        $this->expectException(\InvalidArgumentException::class);
        $seed->next(1, 0);
    }

    public function testNextDoesNotThrowWhenMinEqualsMax(): void
    {
        // $min === $max is a valid range (single possible value)
        $seed   = Seed::fromInt(0);
        $result = $seed->next(5, 5);
        $this->assertSame(5, $result);
    }

    public function testNextReturnsValueWithinRange(): void
    {
        $seed = Seed::fromInt(314);

        for ($i = 0; $i < 50; $i++) {
            $value = $seed->next(10, 20);
            $this->assertGreaterThanOrEqual(10, $value);
            $this->assertLessThanOrEqual(20, $value);
        }
    }
}
