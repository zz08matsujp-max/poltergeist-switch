<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\ActionExecutor;
use PoltergeistSwitch\Engine\EarthquakePhase;
use PoltergeistSwitch\Engine\Engine;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\ResidentController;
use PoltergeistSwitch\Engine\RulesEvaluator;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\SituationSpec;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Engine\SituationView;
use PoltergeistSwitch\Engine\WorldModel;

/**
 * Golden tests for the robot-memory memories-block threading (robot-memory
 * Requirements 9.8, 6.5, 6.8; task 6.3).
 *
 * The memories block is the new section-3 of a turn's situation text. It only
 * appears when a robot has memories; with no memories set the block is absent
 * and the S0–S5 situation texts must be byte-for-byte identical to the
 * established golden expectations from the pilot JSON. This file pins exactly
 * that: no memories ⇒ no change.
 *
 * To make "byte-for-byte unchanged" meaningful it reuses the SAME golden
 * fixtures and the SAME S0–S5 world doubles as the established golden suite in
 * SituationTextGeneratorTest: the pilot prompt for S0 is compared in full, and
 * the "Right now:"/shaking sections for S1–S5 are compared against the pilot
 * prompts, exactly as the established suite does. If adding the memories-block
 * machinery had perturbed any no-memory output, these comparisons would fail.
 *
 * The second check pins the situation-text version to
 * 'situation-text-2026-11-09': adding the block to the generator forced a
 * conscious bump from the previous 'situation-text-2026-11-08c', and this
 * golden keeps any later change to the generator honest (robot-memory
 * Requirement 6.8).
 *
 * The pilot's index.php is deliberately NOT included or parsed: it runs a
 * passphrase check and sends HTTP headers. Only its stored JSON output is read.
 */
class SituationTextMemoriesBlockTest extends TestCase
{
    /** The pilot S0–S5 golden fixture (same file the established golden suite uses). */
    private const FIXTURE = '/pilot/実験0_状況を変える_2026-11-01_n3.json';

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
     * Extract the "Right now: …" paragraph and the shaking paragraph that
     * follows it. Sections are separated by blank lines ("\n\n").
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
     * "What's happening?!" and the floor is shaking this turn. Identical to the
     * established golden suite's world double.
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
            /** @param array<string,string[]> $objectPhrases */
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

            public function residentSpot(): ?string
            {
                return null;
            }

            public function isRoomDark(string $roomKey): bool
            {
                return false;
            }

            /** @return string[] */
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
     * Build the SituationView test double for S0 (identical to the established
     * golden suite's S0 world): Robot_A in the kitchen, the man at the low
     * table in the living room, Robot_B in the bedroom, afternoon, pot boiling,
     * shaking, power on.
     */
    private function s0World(): SituationView
    {
        return $this->buildWorld('afternoon', 'kitchen', 'bedroom', 'sitting_at_table', true);
    }

    /**
     * S0–S5 world parameters, keyed by sid (matching the established golden
     * suite; S0 is the full-text case, S1–S5 are the section cases).
     *
     * @return array<string,array{0:string,1:string,2:string,3:string,4:bool}>
     *               sid => [time, robotARoom, robotBRoom, manActivity, potBoiling]
     */
    public static function situationProvider(): array
    {
        return [
            'S1' => ['afternoon', 'kitchen',     'bedroom',     'sitting_at_table', false],
            'S2' => ['night',     'living_room', 'kitchen',     'asleep_in_futon',  false],
            'S3' => ['afternoon', 'bedroom',     'kitchen',     'bathing',          false],
            'S4' => ['morning',   'living_room', 'bedroom',     'cooking',          true],
            'S5' => ['afternoon', 'storeroom',   'living_room', 'sitting_at_table', false],
        ];
    }

    /**
     * Req 9.8, 6.5: with no memories (the generator receives no memories block)
     * the full S0 situation text is byte-for-byte identical to the golden pilot
     * prompt. The no-memories path is `generate(..., $memoriesBlock = null)`,
     * which is exactly what the Engine passes when setMemories is never called
     * or an empty list is set (buildMemoriesBlock([]) === null).
     */
    public function testS0FullTextUnchangedWithNoMemories(): void
    {
        $prompts = $this->loadPromptsBySid();
        $this->assertArrayHasKey('S0', $prompts, 'fixture must contain situation S0');

        $generator = new SituationTextGenerator();

        // No memories: an empty body list yields a null block, so no section is
        // inserted and the text must equal the pre-memory golden output.
        $this->assertNull(
            $generator->buildMemoriesBlock([]),
            'an empty memory list must produce no memories block'
        );

        $actual = $generator->generate('robot_a', $this->s0World(), [], null);

        $this->assertSame(
            $prompts['S0'],
            $actual,
            'with no memories, the S0 situation text must be byte-for-byte unchanged'
        );
    }

    /**
     * Req 9.8, 6.5: with no memories the S1–S5 "Right now:" and shaking
     * paragraphs are byte-for-byte identical to the golden pilot prompts.
     */
    #[DataProvider('situationProvider')]
    public function testSituationSectionsUnchangedWithNoMemories(
        string $time,
        string $robotARoom,
        string $robotBRoom,
        string $manActivity,
        bool $potBoiling
    ): void {
        $sid     = array_search(
            [$time, $robotARoom, $robotBRoom, $manActivity, $potBoiling],
            self::situationProvider(),
            true
        );
        $prompts = $this->loadPromptsBySid();
        $this->assertArrayHasKey($sid, $prompts, "fixture must contain situation {$sid}");

        [$expectedRightNow, $expectedShaking] = $this->extractSituationSections($prompts[$sid]);

        $world     = $this->buildWorld($time, $robotARoom, $robotBRoom, $manActivity, $potBoiling);
        $generator = new SituationTextGenerator();
        $actual    = $generator->generate('robot_a', $world, [], null);

        [$actualRightNow, $actualShaking] = $this->extractSituationSections($actual);

        $this->assertSame(
            $expectedRightNow,
            $actualRightNow,
            "{$sid}: with no memories the \"Right now:\" paragraph must be byte-for-byte unchanged"
        );
        $this->assertSame(
            $expectedShaking,
            $actualShaking,
            "{$sid}: with no memories the shaking paragraph must be byte-for-byte unchanged"
        );
    }

    /**
     * Req 6.8: Engine::situationTextVersion() equals 'situation-text-2026-11-09'.
     * Adding the memories block to the generator forced a conscious bump from
     * the previous version; this golden keeps any later change honest.
     */
    public function testEngineSituationTextVersionIsBumpedForTheMemoriesBlock(): void
    {
        $config = new EpisodeConfig();
        $world  = new WorldModel($config);
        $world->reset(
            Seed::fromInt(1),
            new SituationSpec(
                time: 'afternoon',
                robotARoom: 'kitchen',
                robotBRoom: 'bedroom',
                manActivity: 'sitting_at_table',
                potBoiling: false,
            ),
        );

        $engine = new Engine(
            $world,
            $config,
            new RulesEvaluator(),
            new ActionExecutor(),
            new EarthquakePhase(),
            new ResidentController(),
            new SituationTextGenerator(),
        );

        $this->assertSame(
            'situation-text-2026-11-09',
            $engine->situationTextVersion(),
            'the situation-text version must be bumped to situation-text-2026-11-09 for the memories block'
        );
    }
}
