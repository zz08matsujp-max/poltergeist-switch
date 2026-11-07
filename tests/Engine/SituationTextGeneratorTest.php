<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
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
     * Load the first-occurrence prompt string for every sid in the pilot JSON
     * fixture. The pilot ran each situation several times; the first entry for
     * a sid is the authoritative prompt (identical across its repeats).
     *
     * @return array<string,string> sid => prompt
     */
    private function loadPromptsBySid(): array
    {
        $path = dirname(__DIR__, 2) . self::FIXTURE;
        $json = file_get_contents($path);
        $this->assertNotFalse($json, "could not read fixture {$path}");

        $data = json_decode($json, true);
        $this->assertIsArray($data, 'fixture did not decode to an array');
        $this->assertArrayHasKey('results', $data);

        $bySid = [];
        foreach ($data['results'] as $entry) {
            $sid = $entry['sid'];
            if (array_key_exists($sid, $bySid)) {
                continue; // keep only the first occurrence
            }
            $this->assertIsString($entry['prompt']);
            $bySid[$sid] = $entry['prompt'];
        }

        return $bySid;
    }

    /**
     * Extract the "Right now: …" paragraph and the shaking paragraph from a
     * full prompt string. Sections are separated by blank lines ("\n\n"); the
     * "Right now:" paragraph is followed immediately by the shaking paragraph
     * (all six golden prompts shake), which is in turn followed by the final
     * line.
     *
     * @return array{0:string,1:string} [rightNow, shaking]
     */
    private function extractSituationSections(string $prompt): array
    {
        $paragraphs = explode("\n\n", $prompt);

        $rightNowIdx = null;
        foreach ($paragraphs as $i => $para) {
            if (str_starts_with($para, 'Right now:')) {
                $rightNowIdx = $i;
                break;
            }
        }

        $this->assertNotNull($rightNowIdx, 'prompt must contain a "Right now:" paragraph');
        $this->assertArrayHasKey(
            $rightNowIdx + 1,
            $paragraphs,
            'prompt must contain a shaking paragraph after "Right now:"'
        );

        return [$paragraphs[$rightNowIdx], $paragraphs[$rightNowIdx + 1]];
    }

    /**
     * S1–S5 golden tests (Req 9.1–9.15, Req 11.9, Req 11.11).
     *
     * For each situation the generated "Right now:" paragraph and shaking
     * paragraph must match the corresponding sections of the golden prompt
     * extracted from the pilot JSON. The world double is parameterised by the
     * SituationSpec-equivalent fields derived from the pilot `set` entries,
     * using the key mapping from design.md:
     *   pilot `you`  → robotARoom, `other` → robotBRoom
     *   man table→sitting_at_table, futon→asleep_in_futon, bath→bathing,
     *       cooking→cooking
     *   rooms living→living_room, bath→bathroom, store→storeroom (others
     *       unchanged)
     * Every situation shakes (shaking=true).
     *
     * @return array<string,array{0:string,1:string,2:string,3:string,4:string,5:bool}>
     *               sid => [time, robotARoom, robotBRoom, manActivity, (unused label), potBoiling]
     */
    public static function situationProvider(): array
    {
        return [
            // sid => [time, robotARoom, robotBRoom, manActivity, potBoiling]
            'S1' => ['S1', 'afternoon', 'kitchen',      'bedroom',     'sitting_at_table', false],
            'S2' => ['S2', 'night',     'living_room',  'kitchen',     'asleep_in_futon',  false],
            'S3' => ['S3', 'afternoon', 'bedroom',      'kitchen',     'bathing',          false],
            'S4' => ['S4', 'morning',   'living_room',  'bedroom',     'cooking',          true],
            'S5' => ['S5', 'afternoon', 'storeroom',    'living_room', 'sitting_at_table', false],
        ];
    }

    #[DataProvider('situationProvider')]
    public function testSituationSectionsMatchGolden(
        string $sid,
        string $time,
        string $robotARoom,
        string $robotBRoom,
        string $manActivity,
        bool $potBoiling
    ): void {
        $prompts = $this->loadPromptsBySid();
        $this->assertArrayHasKey($sid, $prompts, "fixture must contain situation {$sid}");

        [$expectedRightNow, $expectedShaking] = $this->extractSituationSections($prompts[$sid]);

        $world = $this->buildWorld($time, $robotARoom, $robotBRoom, $manActivity, $potBoiling);
        $generator = new SituationTextGenerator();
        $actual = $generator->generate('robot_a', $world, []);

        [$actualRightNow, $actualShaking] = $this->extractSituationSections($actual);

        $this->assertSame(
            $expectedRightNow,
            $actualRightNow,
            "{$sid}: generated \"Right now:\" paragraph must match the golden prompt"
        );
        $this->assertSame(
            $expectedShaking,
            $actualShaking,
            "{$sid}: generated shaking paragraph must match the golden prompt"
        );
    }

    /**
     * Object display phrases per room, in the fixed room order, fixtures
     * excluded. Taken from the pilot golden prompts (V_ROOMS object lists).
     *
     * @return array<string,string[]>
     */
    private static function roomObjectPhrases(): array
    {
        return [
            'living_room' => [
                'a low table',
                'a TV',
                'a black telephone',
                'a transistor radio',
                'a Buddhist altar',
                'a window air conditioner',
            ],
            'kitchen' => [
                'a gas stove',
                'a refrigerator',
                'a sink',
                'a dish cupboard',
                'a flashlight',
            ],
            'bathroom' => [
                'a bathtub',
            ],
            'bedroom' => [
                'a futon',
                'a closet',
                'an electric fan',
            ],
            'entrance' => [
                'a shoe rack',
            ],
            'storeroom' => [
                'candles',
                'matches',
                'a toolbox',
            ],
        ];
    }

    /**
     * Build a SituationView double for an arbitrary turn-1 earthquake
     * situation. Robot_A is the viewpoint; the resident always shouts
     * "What's happening?!" and the floor is shaking this turn.
     */
    private function buildWorld(
        string $time,
        string $robotARoom,
        string $robotBRoom,
        string $manActivity,
        bool $potBoiling
    ): SituationView {
        $objectPhrases = self::roomObjectPhrases();

        return new class(
            $time,
            $robotARoom,
            $robotBRoom,
            $manActivity,
            $potBoiling,
            $objectPhrases
        ) implements SituationView {
            /**
             * @param array<string,string[]> $objectPhrases
             */
            public function __construct(
                private string $time,
                private string $robotARoom,
                private string $robotBRoom,
                private string $manActivity,
                private bool $potBoiling,
                private array $objectPhrases
            ) {
            }

            public function timeOfDay(): string
            {
                return $this->time;
            }

            public function robotRoom(string $robotId): string
            {
                return $robotId === 'robot_a' ? $this->robotARoom : $this->robotBRoom;
            }

            public function residentRoom(): string
            {
                return match ($this->manActivity) {
                    'sitting_at_table' => 'living_room',
                    'asleep_in_futon'  => 'bedroom',
                    'bathing'          => 'bathroom',
                    'cooking'          => 'kitchen',
                    default            => throw new \InvalidArgumentException(
                        "unknown manActivity '{$this->manActivity}'"
                    ),
                };
            }

            public function residentActivity(): string
            {
                return $this->manActivity;
            }

            public function isRoomDark(string $roomKey): bool
            {
                // Turn 1: power is on, nothing is dark regardless of time.
                return false;
            }

            /**
             * @return string[]
             */
            public function visibleObjectPhrases(string $roomKey): array
            {
                return $this->objectPhrases[$roomKey] ?? [];
            }

            public function isShaking(): bool
            {
                return true;
            }

            public function isPotBoiling(): bool
            {
                return $this->potBoiling;
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
