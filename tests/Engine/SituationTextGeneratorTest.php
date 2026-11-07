<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine;

use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\RobotState;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Engine\SituationView;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\Values\Outcome;
use PoltergeistSwitch\Engine\Values\ReportedSpeech;
use PoltergeistSwitch\Engine\Values\TurnRecord;
use PoltergeistSwitch\Engine\Values\WorldSnapshot;

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
    use TestTrait;

    private const FIXTURE = '/pilot/実験0_状況を変える_2026-11-01_n3.json';

    /** The pilot turn-2 fixture: 18 runs = 3 worlds × 3 runs × 2 robots. */
    private const FIXTURE_TURN2 = '/pilot/予備実験_2手目_2026-11-02_n3.json';

    /** The six full room keys used throughout the engine. */
    private const ROOM_KEYS = [
        'living_room',
        'kitchen',
        'bathroom',
        'bedroom',
        'entrance',
        'storeroom',
    ];

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

            public function residentSpot(): ?string
            {
                return null;
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

            public function residentSpot(): ?string
            {
                return null;
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

    // ==================================================================
    // Task 24.2 — turn-2 recent-events tests (Req 11.12, 11.13, 11.15)
    // ==================================================================

    /** Fixed robot-id convention for the pilot runs: claude=robot_a, gpt=robot_b. */
    private const WHO_TO_ID = ['claude' => 'robot_a', 'gpt' => 'robot_b'];

    /** Map a pilot room abbreviation to the full engine room key. */
    private static function fullRoom(string $pilotRoom): string
    {
        return match ($pilotRoom) {
            'living' => 'living_room',
            'bath'   => 'bathroom',
            'store'  => 'storeroom',
            default  => $pilotRoom, // kitchen, bedroom, entrance already full
        };
    }

    /**
     * Load and decode the 18-run turn-2 pilot fixture.
     *
     * @return array<string,mixed>
     */
    private function loadTurn2Fixture(): array
    {
        $path = dirname(__DIR__, 2) . self::FIXTURE_TURN2;
        $json = file_get_contents($path);
        $this->assertNotFalse($json, "could not read fixture {$path}");

        $data = json_decode($json, true);
        $this->assertIsArray($data, 'turn-2 fixture did not decode to an array');
        $this->assertArrayHasKey('runs', $data);
        $this->assertCount(18, $data['runs'], 'turn-2 fixture should hold 18 runs');

        return $data;
    }

    /**
     * Pull the "In the last 15 seconds:" block and the shaking paragraph out of
     * a full prompt. The recent-events block is the paragraph that starts with
     * "In the last 15 seconds:"; the shaking paragraph is the one immediately
     * after the "Right now:" paragraph.
     *
     * @return array{0:string,1:string} [recentEvents, shaking]
     */
    private function extractTurn2Sections(string $prompt): array
    {
        $paragraphs = explode("\n\n", $prompt);

        $recent  = null;
        $shaking = null;
        foreach ($paragraphs as $i => $para) {
            if (str_starts_with($para, 'In the last 15 seconds:')) {
                $recent = $para;
            }
            if (str_starts_with($para, 'Right now:')) {
                $shaking = $paragraphs[$i + 1] ?? null;
            }
        }

        $this->assertNotNull($recent, 'prompt must contain an "In the last 15 seconds:" block');
        $this->assertNotNull($shaking, 'prompt must contain a shaking paragraph after "Right now:"');

        return [$recent, $shaking];
    }

    /**
     * Build the turn-1 TurnRecord for a (world, run) pair, seen from scratch:
     * both robots' turn-1 readings become Actions + Outcomes, start rooms come
     * from the top-level "worlds"[world], end rooms are the same (turn 1 ends
     * where turn 1 started in the pilot — the reading never moves either robot
     * out of its room). Keyed by the fixed claude=robot_a / gpt=robot_b ids.
     *
     * @param array<string,mixed> $data full fixture
     */
    private function buildTurn1Record(array $data, string $world, int $run): TurnRecord
    {
        // Collect the two partner runs for this (world, run).
        $byId = [];
        foreach ($data['runs'] as $r) {
            if ($r['world'] === $world && $r['run'] === $run) {
                $byId[self::WHO_TO_ID[$r['who']]] = $r;
            }
        }
        $this->assertArrayHasKey('robot_a', $byId, "missing claude run for {$world}/{$run}");
        $this->assertArrayHasKey('robot_b', $byId, "missing gpt run for {$world}/{$run}");

        $worldRooms = $data['worlds'][$world];
        $roomsAtStart = [
            'robot_a' => self::fullRoom($worldRooms['claude']),
            'robot_b' => self::fullRoom($worldRooms['gpt']),
        ];

        $actions  = [];
        $outcomes = [];
        $states   = [];
        foreach (['robot_a', 'robot_b'] as $id) {
            $reading = $byId[$id]['reading'];

            $reported = [];
            foreach ($reading['indirect'] as $ind) {
                $reported[] = new ReportedSpeech($ind['quote'], $ind['heard_as'], $ind['own_form']);
            }

            // The exact operation value only needs to be non-'unknown' so the
            // action line is kept; the pilot op string serves fine.
            $actions[$id] = new Action(
                robotId: $id,
                operation: $reading['op'],
                words: $reading['words'],
                actionQuote: $reading['quote'],
                reported: $reported,
            );

            $outcomes[$id] = new Outcome(
                robotId: $id,
                success: true,
                whatHappenedText: $reading['result_for_self'],
                seenByOtherText: $reading['seen_by_other'],
            );

            // Turn 1 ends in the same room it started in.
            $states[$id] = new RobotState(id: $id, room: $roomsAtStart[$id]);
        }

        $snapshot = new WorldSnapshot(
            objects: [],
            robotStates: $states,
            residentState: null,
            houseState: null,
        );

        return new TurnRecord(
            turnNumber: 1,
            robotActions: $actions,
            judgements: [],
            outcomes: $outcomes,
            residentLine: null,
            snapshot: $snapshot,
            roomsAtStart: $roomsAtStart,
        );
    }

    /**
     * A SituationView double for a turn-2 earthquake: still shaking, lights go
     * out this turn, resident silent. The viewpoint robot sits in $room, the
     * other in $otherRoom; the pot boils only when $stoveOn (the generator
     * shows the pot sentence only when the viewpoint is in the kitchen).
     */
    private function turn2World(
        string $viewpointId,
        string $room,
        string $otherRoom,
        bool $stoveOn
    ): SituationView {
        $otherId = $viewpointId === 'robot_a' ? 'robot_b' : 'robot_a';
        $objectPhrases = self::roomObjectPhrases();

        return new class(
            $viewpointId,
            $otherId,
            $room,
            $otherRoom,
            $stoveOn,
            $objectPhrases
        ) implements SituationView {
            /** @param array<string,string[]> $objectPhrases */
            public function __construct(
                private string $viewpointId,
                private string $otherId,
                private string $room,
                private string $otherRoom,
                private bool $stoveOn,
                private array $objectPhrases
            ) {
            }

            public function timeOfDay(): string
            {
                return 'afternoon';
            }

            public function robotRoom(string $robotId): string
            {
                return $robotId === $this->viewpointId ? $this->room : $this->otherRoom;
            }

            public function residentRoom(): string
            {
                return 'living_room'; // the man is at the low table in every run
            }

            public function residentActivity(): string
            {
                return 'sitting_at_table';
            }

            public function residentSpot(): ?string
            {
                return null;
            }

            public function isRoomDark(string $roomKey): bool
            {
                // The lights only go out at the end of this turn; the "Right now:"
                // object list still appears in the pilot turn-2 prompts.
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
                return $this->stoveOn;
            }

            public function wasShakingPreviousTurn(): bool
            {
                return true; // turn 2 is still shaking
            }

            public function didLightsGoOutThisTurn(): bool
            {
                return true; // every turn-2 prompt ends with "The lights go out."
            }

            public function residentSpeaksThisTurn(): bool
            {
                return false; // the man is silent on turn 2
            }

            public function residentLine(): ?string
            {
                return null;
            }
        };
    }

    /**
     * Data provider over the 18 turn-2 runs, keyed "world/run/who".
     *
     * @return array<string,array{0:string,1:int,2:string}>
     */
    public static function turn2RunProvider(): array
    {
        $cases = [];
        foreach (['P1', 'P2', 'P3'] as $world) {
            foreach ([1, 2, 3] as $run) {
                foreach (['claude', 'gpt'] as $who) {
                    $cases["{$world}/{$run}/{$who}"] = [$world, $run, $who];
                }
            }
        }

        return $cases;
    }

    /**
     * TEST 1 — turn-2 golden (Req 11.12, 11.13).
     *
     * For each of the 18 runs, build the turn-1 TurnRecord from the run's own
     * reading and its partner's reading, generate the viewpoint robot's turn-2
     * text, and assert the "In the last 15 seconds:" block and the shaking
     * paragraph match the pilot's turn2.prompt byte-for-byte.
     */
    #[DataProvider('turn2RunProvider')]
    public function testTurn2RecentEventsAndShakingMatchGolden(
        string $world,
        int $run,
        string $who
    ): void {
        $data = $this->loadTurn2Fixture();

        $this_run = null;
        foreach ($data['runs'] as $r) {
            if ($r['world'] === $world && $r['run'] === $run && $r['who'] === $who) {
                $this_run = $r;
                break;
            }
        }
        $this->assertNotNull($this_run, "missing run {$world}/{$run}/{$who}");

        [$expectedRecent, $expectedShaking] = $this->extractTurn2Sections($this_run['turn2']['prompt']);

        $viewpointId = self::WHO_TO_ID[$who];
        $record = $this->buildTurn1Record($data, $world, $run);

        $world2 = $this->turn2World(
            $viewpointId,
            self::fullRoom($this_run['turn2']['room']),
            self::fullRoom($this_run['turn2']['other_room']),
            (bool) $this_run['turn2']['stove_on'],
        );

        $generator = new SituationTextGenerator();
        $actual = $generator->generate($viewpointId, $world2, [$record]);

        [$actualRecent, $actualShaking] = $this->extractTurn2Sections($actual);

        $this->assertSame(
            $expectedRecent,
            $actualRecent,
            "{$world}/{$run}/{$who}: \"In the last 15 seconds:\" block must match the golden prompt"
        );
        $this->assertSame(
            $expectedShaking,
            $actualShaking,
            "{$world}/{$run}/{$who}: shaking paragraph must match the golden prompt"
        );
    }

    // ------------------------------------------------------------------
    // TEST 2 — rule A property (Req 11.13 / Property 11)
    // ------------------------------------------------------------------

    /**
     * Build a one-turn TurnRecord for the rule-A property: the other robot both
     * acts (a non-'unknown' action with seenByOtherText) and speaks a line and
     * carries a reported-speech item. The viewpoint robot does nothing, so the
     * block holds only the other robot's lines, isolating rule A.
     */
    private function ruleARecord(
        string $selfStart,
        string $otherStart,
        string $selfEnd,
        string $otherEnd
    ): TurnRecord {
        $otherAction = new Action(
            robotId: 'robot_b',
            operation: 'cover',
            words: ['Stay low!'],
            actionQuote: 'I cover my head',
            reported: [new ReportedSpeech(
                'I call to the other robot to take cover',
                'call to you to take cover',
                'called to the other robot to take cover',
            )],
        );

        $otherOutcome = new Outcome(
            robotId: 'robot_b',
            success: true,
            whatHappenedText: 'The other robot covered its head.',
            seenByOtherText: 'The other robot covered its head.',
        );

        $snapshot = new WorldSnapshot(
            objects: [],
            robotStates: [
                'robot_a' => new RobotState(id: 'robot_a', room: $selfEnd),
                'robot_b' => new RobotState(id: 'robot_b', room: $otherEnd),
            ],
            residentState: null,
            houseState: null,
        );

        return new TurnRecord(
            turnNumber: 1,
            robotActions: ['robot_b' => $otherAction],
            judgements: [],
            outcomes: ['robot_b' => $otherOutcome],
            residentLine: null,
            snapshot: $snapshot,
            roomsAtStart: ['robot_a' => $selfStart, 'robot_b' => $otherStart],
        );
    }

    /**
     * TEST 2 — rule A (Req 11.13 / Property 11).
     *
     * For any pair of start and end rooms, the other robot's ACTION line appears
     * in the recent-events block iff the robots shared a room at the start OR at
     * the end of the turn; its spoken words appear in every case (shared-room
     * phrasing when they shared, "You heard the other robot say," otherwise);
     * its reported speech ("You heard the other robot …") appears in every case.
     *
     * **Validates: Requirements 11.13**
     */
    public function testRuleAProperty(): void
    {
        $this->limitTo(100);

        $rooms = Generator\elements(self::ROOM_KEYS);

        $this->forAll($rooms, $rooms, $rooms, $rooms)->then(
            function (string $selfStart, string $otherStart, string $selfEnd, string $otherEnd): void {
                $record = $this->ruleARecord($selfStart, $otherStart, $selfEnd, $otherEnd);

                $generator = new SituationTextGenerator();
                $text = $generator->generate('robot_a', $this->ruleAWorld(), [$record]);

                $shared = ($selfStart === $otherStart) || ($selfEnd === $otherEnd);

                $actionLine = 'The other robot covered its head.';
                $sharedSay  = 'The other robot said, "Stay low!"';
                $heardSay   = 'You heard the other robot say, "Stay low!"';
                $reported   = 'You heard the other robot call to you to take cover.';

                if ($shared) {
                    $this->assertStringContainsString($actionLine, $text, 'shared room: action line present');
                    $this->assertStringContainsString($sharedSay, $text, 'shared room: spoken line uses shared phrasing');
                    $this->assertStringNotContainsString($heardSay, $text, 'shared room: no "heard" phrasing');
                } else {
                    $this->assertStringNotContainsString($actionLine, $text, 'separate rooms: action line absent');
                    $this->assertStringContainsString($heardSay, $text, 'separate rooms: spoken line uses "heard" phrasing');
                }

                // Reported speech is heard in every room.
                $this->assertStringContainsString($reported, $text, 'reported speech present in every case');
            }
        );
    }

    /**
     * A minimal still-shaking turn-2 world for the rule-A / six-turn properties.
     * The viewpoint robot is in the living room; the shaking paragraph and
     * "Right now:" text are irrelevant to these assertions.
     */
    private function ruleAWorld(): SituationView
    {
        return $this->turn2World('robot_a', 'living_room', 'bedroom', false);
    }

    // ------------------------------------------------------------------
    // TEST 3 — six-turn example + at-most-five-blocks property (Req 11.15)
    // ------------------------------------------------------------------

    /**
     * Build a simple one-turn TurnRecord where only the other robot acts and
     * speaks, with the given start/end rooms for both robots. Used to assemble
     * multi-turn histories for the block-count tests.
     */
    private function simpleOtherTurn(
        int $turnNumber,
        string $selfRoom,
        string $otherRoom
    ): TurnRecord {
        $otherAction = new Action(
            robotId: 'robot_b',
            operation: 'cover',
            words: ['Stay low!'],
            actionQuote: 'I cover my head',
            reported: [],
        );
        $otherOutcome = new Outcome(
            robotId: 'robot_b',
            success: true,
            whatHappenedText: 'The other robot covered its head.',
            seenByOtherText: 'The other robot covered its head.',
        );
        $snapshot = new WorldSnapshot(
            objects: [],
            robotStates: [
                'robot_a' => new RobotState(id: 'robot_a', room: $selfRoom),
                'robot_b' => new RobotState(id: 'robot_b', room: $otherRoom),
            ],
            residentState: null,
            houseState: null,
        );

        return new TurnRecord(
            turnNumber: $turnNumber,
            robotActions: ['robot_b' => $otherAction],
            judgements: [],
            outcomes: ['robot_b' => $otherOutcome],
            residentLine: null,
            snapshot: $snapshot,
            roomsAtStart: ['robot_a' => $selfRoom, 'robot_b' => $otherRoom],
        );
    }

    /**
     * Extract the recent-events section (every block up to and including the
     * "In the last 15 seconds:" block) from a full prompt. The section is the
     * run of consecutive paragraphs ending at the "In the last 15 seconds:"
     * paragraph, each starting with one of the known block headings.
     *
     * @return string[] the block paragraphs, oldest first
     */
    private function extractRecentBlocks(string $prompt): array
    {
        $paragraphs = explode("\n\n", $prompt);
        $headings = [
            '60 to 75 seconds ago:',
            '45 to 60 seconds ago:',
            '30 to 45 seconds ago:',
            '15 to 30 seconds ago:',
            'In the last 15 seconds:',
        ];

        $blocks = [];
        foreach ($paragraphs as $para) {
            foreach ($headings as $h) {
                if (str_starts_with($para, $h)) {
                    $blocks[] = $para;
                    break;
                }
            }
        }

        return $blocks;
    }

    /**
     * TEST 3a — hand-written six-turn example (Req 11.15).
     *
     * Passing five TurnRecords, oldest first, yields exactly five blocks with
     * the fixed headings in order. One turn shares a room (its block shows the
     * other robot's action) and one does not (its block omits the action),
     * demonstrating rule A per block.
     */
    public function testSixthTurnShowsAtMostFiveBlocksOldestFirst(): void
    {
        // Five previous turns, oldest first. Turns 2 and 4 share the living
        // room; turns 1, 3 and 5 keep the robots in separate rooms.
        $records = [
            $this->simpleOtherTurn(1, 'kitchen', 'bedroom'),      // separate
            $this->simpleOtherTurn(2, 'living_room', 'living_room'), // shared
            $this->simpleOtherTurn(3, 'kitchen', 'bedroom'),      // separate
            $this->simpleOtherTurn(4, 'living_room', 'living_room'), // shared
            $this->simpleOtherTurn(5, 'kitchen', 'bedroom'),      // separate
        ];

        $generator = new SituationTextGenerator();
        $text = $generator->generate('robot_a', $this->ruleAWorld(), $records);

        $blocks = $this->extractRecentBlocks($text);

        $this->assertCount(5, $blocks, 'the sixth turn shows exactly five recent-events blocks');

        $expectedHeadings = [
            '60 to 75 seconds ago:',
            '45 to 60 seconds ago:',
            '30 to 45 seconds ago:',
            '15 to 30 seconds ago:',
            'In the last 15 seconds:',
        ];
        foreach ($expectedHeadings as $i => $heading) {
            $this->assertStringStartsWith(
                $heading,
                $blocks[$i],
                "block {$i} must be headed \"{$heading}\" (oldest first)"
            );
        }

        $actionLine = 'The other robot covered its head.';
        // Oldest-first mapping: records[0] -> block 0 ... records[4] -> block 4.
        // Shared turns (records 1 and 3) show the action; separate turns do not.
        $this->assertStringNotContainsString($actionLine, $blocks[0], 'separate-room block omits the action');
        $this->assertStringContainsString($actionLine, $blocks[1], 'shared-room block shows the action');
        $this->assertStringNotContainsString($actionLine, $blocks[2], 'separate-room block omits the action');
        $this->assertStringContainsString($actionLine, $blocks[3], 'shared-room block shows the action');
        $this->assertStringNotContainsString($actionLine, $blocks[4], 'separate-room block omits the action');
    }

    /**
     * TEST 3b — at-most-five-blocks property (Req 11.15).
     *
     * For a history of up to eight turns, the recent-events section holds at
     * most five blocks, oldest first, ends with the "In the last 15 seconds:"
     * block, and applies rule A to each block separately (a shared-room block
     * shows the other robot's action; a separate-room block omits it).
     *
     * **Validates: Requirements 11.15**
     */
    public function testRecentEventsHoldsAtMostFiveBlocks(): void
    {
        $this->limitTo(100);

        // A sequence of 1..8 booleans; each flag marks whether that turn shared
        // a room (true) or kept the robots apart (false).
        $sharedFlags = Generator\seq(Generator\bool());

        $this->forAll(
            Generator\suchThat(
                static fn (array $flags): bool => count($flags) >= 1 && count($flags) <= 8,
                $sharedFlags
            )
        )->then(
            function (array $flags): void {
                $records = [];
                foreach (array_values($flags) as $i => $shared) {
                    $records[] = $shared
                        ? $this->simpleOtherTurn($i + 1, 'living_room', 'living_room')
                        : $this->simpleOtherTurn($i + 1, 'kitchen', 'bedroom');
                }

                $generator = new SituationTextGenerator();
                $text = $generator->generate('robot_a', $this->ruleAWorld(), $records);

                $blocks = $this->extractRecentBlocks($text);

                $kept = min(count($records), 5);
                $this->assertCount($kept, $blocks, 'at most five (and exactly min(n,5)) blocks are shown');
                $this->assertLessThanOrEqual(5, count($blocks), 'never more than five blocks');

                // The final block is always "In the last 15 seconds:".
                $last = $blocks[count($blocks) - 1];
                $this->assertStringStartsWith('In the last 15 seconds:', $last, 'ends with the latest block');

                // Earlier blocks count back with the fixed headings, oldest first.
                $expected = array_slice([
                    '60 to 75 seconds ago:',
                    '45 to 60 seconds ago:',
                    '30 to 45 seconds ago:',
                    '15 to 30 seconds ago:',
                    'In the last 15 seconds:',
                ], 5 - $kept);
                foreach ($expected as $i => $heading) {
                    $this->assertStringStartsWith($heading, $blocks[$i], 'headings in order, oldest first');
                }

                // Rule A per block: the kept records are the newest $kept, in
                // oldest-first order. A shared turn shows the action line; a
                // separate turn omits it.
                $keptRecords = array_slice(array_values($flags), -$kept);
                $actionLine = 'The other robot covered its head.';
                foreach ($keptRecords as $i => $shared) {
                    if ($shared) {
                        $this->assertStringContainsString($actionLine, $blocks[$i], 'shared block shows the action');
                    } else {
                        $this->assertStringNotContainsString($actionLine, $blocks[$i], 'separate block omits the action');
                    }
                }
            }
        );
    }
}
