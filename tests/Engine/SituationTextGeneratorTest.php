<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Engine\SituationView;

/**
 * S0 golden test for SituationTextGenerator (Requirements: Req 9.1–9.15, Req 11.9, Req 11.10).
 *
 * Req 11.10: the full generated text for Robot_A in S0 is byte-for-byte identical
 * to SITUATION_B from the pilot (straight double-quotes for resident speech).
 *
 * SituationTextGenerator (task 4.1) was built against the narrow read-only
 * interface SituationView, because the concrete WorldModel is not created until
 * tasks 6–7. This test supplies a lightweight test double that implements
 * SituationView and exposes exactly the S0 fields: Robot_A in the kitchen, the
 * man at the low table in the living room, Robot_B in the bedroom,
 * timeOfDay=afternoon, potBoiling=true, shaking=true, power=on.
 *
 * The expected string is the S0 `prompt` from the pilot JSON (identical across
 * all four S0 entries and equal to SITUATION_B). It is loaded from the JSON
 * file so the comparison is byte-for-byte against the real pilot output. The
 * pilot's index.php is deliberately NOT included or parsed: it runs a
 * passphrase check and sends HTTP headers.
 */
class SituationTextGeneratorTest extends TestCase
{
    private const FIXTURE = '/pilot/実験0_状況を変える_2026-11-01_n3.json';

    /**
     * Load the authoritative S0 prompt string from the pilot JSON fixture.
     * Uses the first `results` entry, whose `sid` is "S0".
     */
    private function loadS0Prompt(): string
    {
        $path = dirname(__DIR__, 2) . self::FIXTURE;
        $json = file_get_contents($path);
        $this->assertNotFalse($json, "could not read fixture {$path}");

        $data = json_decode($json, true);
        $this->assertIsArray($data, 'fixture did not decode to an array');
        $this->assertArrayHasKey('results', $data);
        $this->assertNotEmpty($data['results'], 'fixture should hold at least one result');

        $first = $data['results'][0];
        $this->assertSame('S0', $first['sid'], 'first result should be the S0 situation');
        $this->assertArrayHasKey('prompt', $first);
        $this->assertIsString($first['prompt']);

        return $first['prompt'];
    }

    /**
     * Req 11.10: Robot_A's full S0 text matches SITUATION_B byte-for-byte.
     */
    public function testS0FullTextForRobotAMatchesSituationBByteForByte(): void
    {
        $expected  = $this->loadS0Prompt();
        $world     = $this->s0World();
        $generator = new SituationTextGenerator();

        $actual = $generator->generate('robot_a', $world, []);

        $this->assertSame(
            $expected,
            $actual,
            'generated S0 text for robot_a must equal SITUATION_B byte-for-byte'
        );
    }

    /**
     * Build the SituationView test double for S0.
     */
    private function s0World(): SituationView
    {
        return new class implements SituationView {
            public function timeOfDay(): string
            {
                return 'afternoon';
            }

            public function robotRoom(string $robotId): string
            {
                return $robotId === 'robot_a' ? 'kitchen' : 'bedroom';
            }

            public function residentRoom(): string
            {
                return 'living_room';
            }

            public function residentActivity(): string
            {
                return 'sitting_at_table';
            }

            public function isRoomDark(string $roomKey): bool
            {
                // Afternoon with power on: nothing is dark in S0.
                return false;
            }

            /**
             * @return string[]
             */
            public function visibleObjectPhrases(string $roomKey): array
            {
                // Kitchen objects in the fixed room order, fixtures excluded.
                if ($roomKey === 'kitchen') {
                    return [
                        'a gas stove',
                        'a refrigerator',
                        'a sink',
                        'a dish cupboard',
                        'a flashlight',
                    ];
                }

                return [];
            }

            public function isShaking(): bool
            {
                return true;
            }

            public function isPotBoiling(): bool
            {
                return true;
            }

            public function wasShakingPreviousTurn(): bool
            {
                return false;
            }

            public function didLightsGoOutThisTurn(): bool
            {
                return false;
            }

            public function residentSpeaksThisTurn(): bool
            {
                return true;
            }

            public function residentLine(): ?string
            {
                return "What's happening?!";
            }
        };
    }
}
