<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\ActionExecutor;
use PoltergeistSwitch\Engine\EarthquakePhase;
use PoltergeistSwitch\Engine\Engine;
use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\ReflectionTextBuilder;
use PoltergeistSwitch\Engine\ResidentController;
use PoltergeistSwitch\Engine\RulesEvaluator;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\WorldModel;
use PoltergeistSwitch\Memory\Values\MemorySnapshot;
use PoltergeistSwitch\TurnLoop\Clients\FakeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\WorldStateSerializer;

/**
 * Earthquake golden-master (heat-wave spec Requirement 1, task 1).
 *
 * This REQUIRED, FIRST test pins the finished earthquake's behaviour BEFORE any
 * heat-wave change to the engine, texts, database, API, or screens is made
 * (Requirement 1.1). It records, for a fixed set of seeds and a fixed seeded
 * per-turn action sequence, every value that defines how an earthquake episode
 * behaves, reads, and replays, and FAILS the moment any of those values differs
 * from the committed fixture (Requirements 1.2, 1.3). The fixture is committed
 * once and is NEVER regenerated or edited by any later task: no later task
 * changes an expected earthquake value to make a test pass (Requirement 1.4).
 * If a value differs, the work stops and reports the difference (Requirement
 * 1.5) — this test is the mechanism that enforces that.
 *
 * How it pins the earthquake (Requirement 1.2):
 *   - At least 30 fixed integer seeds (seeds 1..40) so the drawn SituationSpec
 *     covers every starting situation: all three times of day, all four
 *     starting activities, and the bathing-in-bathroom constraint (several
 *     bathing seeds, where SituationSpec guarantees neither robot starts in the
 *     bathroom). See {@see SEEDS} and the coverage assertion.
 *   - Every seed is run through the full fixed action sequence TWICE: once with
 *     no memory snapshot and once with a non-empty memory snapshot set via
 *     Engine::setMemories, so both memory paths are pinned for every seed.
 *   - The per-turn action sequence is itself seeded (derived from each episode
 *     seed) and includes moves, takes, uses, hand-overs, supports, covers,
 *     speech, and `unknown` readings, and SPECIFICALLY includes `use` and
 *     `take` of the window, the window air conditioner, the electric fan, and
 *     the refrigerator, plus hand_over actions, so the earthquake behaviour of
 *     the exact targets the heat code will later touch is pinned before any
 *     heat code exists.
 *
 * What it records per turn, for both robots (Requirement 1.2): the situation
 * text, the judgements, the outcomes, the WorldStateSerializer output (not a
 * raw snapshot), the resident state, the resident line, and the end flag; plus
 * every reflection text at the end. Each field is stored as a full value or a
 * SHA-256 hash (hashes keep the fixture small); the test recomputes each value
 * and fails on any difference.
 *
 * No real API call: the driver issues deterministic {@see Action} values
 * straight to the pure Engine — the only way to pin exact, reproducible engine
 * behaviour — and the existing {@see FakeRobotClient}/{@see FakeRecorderClient}
 * fakes are instantiated to assert the run opens no network connection.
 *
 * The earthquake is flipped on turn 0 (earthquakeStartTurn = 0), so the
 * earthquake offset equals the turn number, matching the model-solution path in
 * EngineIntegrationTest. Every episode is driven for a fixed TURNS turns.
 *
 * Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.7, 1.10, 11.9, 11.10, 11.11
 */
final class EarthquakeGoldenMasterTest extends TestCase
{
    /**
     * The committed fixture. It is written ONCE (by this test with
     * GOLDEN_MASTER_GENERATE=1 set, immediately before it is committed) and is
     * never regenerated or edited afterwards (Requirement 1.4).
     */
    private const FIXTURE_PATH = __DIR__ . '/fixtures/earthquake_golden_master.json';

    /**
     * The at-least-30 fixed integer seeds. Seeds 1..40 together draw every
     * starting situation — all three times of day, all four starting
     * activities, and several bathing starts (SituationSpec::fromSeed enforces
     * the bathing-in-bathroom constraint, so no bathing seed ever puts a robot
     * in the bathroom). The coverage is asserted in
     * {@see testSeedsCoverEveryStartingSituation}.
     *
     * @var int[]
     */
    private const SEEDS = [
        1, 2, 3, 4, 5, 6, 7, 8, 9, 10,
        11, 12, 13, 14, 15, 16, 17, 18, 19, 20,
        21, 22, 23, 24, 25, 26, 27, 28, 29, 30,
        31, 32, 33, 34, 35, 36, 37, 38, 39, 40,
    ];

    /** How many turns each episode is driven (turns 0..TURNS-1). */
    private const TURNS = 14;

    /**
     * The non-empty memory snapshot used on the second run of every seed. These
     * are plain fact-only bodies (a robot's own words); they exercise the
     * Memories_Block path in both the situation text and the reflection text.
     *
     * @var array<string,string[]>
     */
    private const MEMORY_BODIES = [
        'robot_a' => [
            'The floor shook and the dishes fell.',
            'I stayed beside the old man under the table.',
        ],
        'robot_b' => [
            'The lights went out after the shaking.',
        ],
    ];

    // ------------------------------------------------------------------
    // The golden-master comparison (Requirements 1.2, 1.3, 1.4, 1.5).
    // ------------------------------------------------------------------

    public function testEarthquakeBehaviourMatchesTheCommittedGoldenMaster(): void
    {
        $live = $this->buildLiveFixture();

        // Write the fixture exactly once, only when explicitly asked, right
        // before it is committed. A normal run never writes; it only compares.
        if (getenv('GOLDEN_MASTER_GENERATE') === '1') {
            $this->writeFixture($live);
            self::markTestIncomplete(
                'Golden-master fixture generated at ' . self::FIXTURE_PATH
                . '. Commit it, then run without GOLDEN_MASTER_GENERATE to compare.'
            );
        }

        self::assertFileExists(
            self::FIXTURE_PATH,
            'The committed earthquake golden-master fixture is missing. It must be '
            . 'generated ONCE (GOLDEN_MASTER_GENERATE=1) and committed; it is never '
            . 'regenerated by a later task (Requirement 1.4).'
        );

        $expected = $this->readFixture();

        // Compare the whole structure. On any difference the test fails and
        // reports WHERE the earthquake drifted; a later task must stop and
        // report it rather than edit the expected value (Requirements 1.3–1.5).
        self::assertSame(
            $expected,
            $live,
            'An earthquake value differs from the committed golden master. '
            . 'STOP and report the difference — do NOT change the expected value '
            . '(heat-wave spec Requirements 1.3, 1.4, 1.5).'
        );
    }

    /**
     * The fixed seed set covers every starting situation (Requirement 1.2):
     * all three times of day, all four starting activities, and at least one
     * bathing start (which, by SituationSpec::fromSeed, never places a robot in
     * the bathroom — the bathing-in-bathroom constraint).
     */
    public function testSeedsCoverEveryStartingSituation(): void
    {
        self::assertGreaterThanOrEqual(30, count(self::SEEDS), 'at least 30 fixed seeds are required');

        $times = [];
        $activities = [];
        $bathingSeeds = 0;

        foreach (self::SEEDS as $seed) {
            $spec = \PoltergeistSwitch\Engine\SituationSpec::fromSeed(Seed::fromInt($seed));
            $times[$spec->time] = true;
            $activities[$spec->manActivity] = true;
            if ($spec->manActivity === 'bathing') {
                $bathingSeeds++;
                // The bathing-in-bathroom constraint: neither robot is ever in
                // the bathroom when the man is bathing.
                self::assertNotSame('bathroom', $spec->robotARoom);
                self::assertNotSame('bathroom', $spec->robotBRoom);
            }
        }

        self::assertCount(3, $times, 'all three times of day must be covered');
        self::assertCount(4, $activities, 'all four starting activities must be covered');
        self::assertGreaterThan(0, $bathingSeeds, 'at least one bathing start must be covered');
    }

    /**
     * The fakes open no network connection: the golden master drives the pure
     * Engine directly, and the fakes return configured values with a zero call
     * count until used (Requirement 1.2 "no real API call").
     */
    public function testFakesOpenNoNetworkConnection(): void
    {
        $robot = new FakeRobotClient();
        $recorder = new FakeRecorderClient();

        self::assertSame(0, $robot->callCount());
        self::assertSame(0, $recorder->callCount());
    }

    // ------------------------------------------------------------------
    // Live computation of the full fixture.
    // ------------------------------------------------------------------

    /**
     * Build the complete live fixture: every seed, each run both without and
     * with memory, with every recorded field hashed or kept whole.
     *
     * @return array<string,mixed>
     */
    private function buildLiveFixture(): array
    {
        $fixture = [
            'schema' => 'earthquake-golden-master-v1',
            'turns' => self::TURNS,
            'situationTextVersion' => SituationTextGenerator::VERSION,
            'reflectionTextVersion' => ReflectionTextBuilder::REFLECTION_TEXT_VERSION,
            'seeds' => self::SEEDS,
            'runs' => [],
        ];

        foreach (self::SEEDS as $seed) {
            $fixture['runs'][(string) $seed] = [
                'no_memory' => $this->runEpisode($seed, false),
                'with_memory' => $this->runEpisode($seed, true),
            ];
        }

        return $fixture;
    }

    /**
     * Drive one full earthquake episode and return every recorded value for it.
     *
     * The engine is built directly over a freshly reset world for $seed, the
     * earthquake is flipped on turn 0 (offset == turn), and the fixed seeded
     * action sequence is applied turn by turn. The situation text for each turn
     * is captured exactly as a robot reads it (prepareTurn then situationTextFor
     * on a parallel preview engine, mirroring the live loop), then the turn is
     * applied. After the run, each robot's reflection text is built from the
     * recorded turns.
     *
     * @return array<string,mixed>
     */
    private function runEpisode(int $seed, bool $withMemory): array
    {
        $config = new EpisodeConfig();
        $textGen = new SituationTextGenerator();

        $engine = $this->makeEngine($seed, $config, $textGen);
        $preview = $this->makeEngine($seed, $config, $textGen);

        if ($withMemory) {
            $engine->setMemories(self::MEMORY_BODIES);
            $preview->setMemories(self::MEMORY_BODIES);
        }

        $serializer = new WorldStateSerializer();

        $turns = [];
        $endFlag = false;

        for ($turn = 0; $turn < self::TURNS; $turn++) {
            $actions = $this->actionsForTurn($seed, $turn);

            // Capture the situation text both robots read for this turn on a
            // parallel preview engine advanced to the same point, exactly as
            // the browser loop does (prepareTurn then situationTextFor), then
            // mirror the same actions on the preview so it stays in lockstep.
            $preview->prepareTurn($turn);
            $situationA = $preview->situationTextFor('robot_a');
            $situationB = $preview->situationTextFor('robot_b');
            $preview->applyTurn($turn, $actions);

            // Apply the turn on the record engine and read the authoritative
            // result (judgements, outcomes, resident line, resident state, end).
            $result = $engine->applyTurn($turn, $actions);
            $world = $this->worldOf($engine);

            $turns[] = [
                'turn' => $turn,
                'situationText' => [
                    'robot_a' => $this->hash($situationA),
                    'robot_b' => $this->hash($situationB),
                ],
                'judgements' => [
                    'robot_a' => $this->hashJudgement($result->judgements['robot_a'] ?? null),
                    'robot_b' => $this->hashJudgement($result->judgements['robot_b'] ?? null),
                ],
                'outcomes' => [
                    'robot_a' => $this->hashOutcome($result->outcomes['robot_a'] ?? null),
                    'robot_b' => $this->hashOutcome($result->outcomes['robot_b'] ?? null),
                ],
                'worldState' => $this->hash($serializer->toJson($world)),
                'residentState' => $this->hashResidentState($result->residentState),
                'residentLine' => $result->residentLine,
                'episodeEnded' => $result->episodeEnded,
            ];

            if ($result->episodeEnded) {
                $endFlag = true;
                break;
            }
        }

        // Reflections: build each robot's fact-only reflection text from the
        // recorded turns, exactly as the reflection flow does after a replay.
        $records = $engine->turnRecords();
        $finalWorld = $this->worldOf($engine);
        $snapshot = $withMemory
            ? new MemorySnapshot(self::MEMORY_BODIES['robot_a'])
            : new MemorySnapshot([]);
        $snapshotB = $withMemory
            ? new MemorySnapshot(self::MEMORY_BODIES['robot_b'])
            : new MemorySnapshot([]);

        $reflectionBuilder = new ReflectionTextBuilder($textGen);
        $reflectionA = $reflectionBuilder->build('robot_a', $records, 0, $finalWorld, $snapshot);
        $reflectionB = $reflectionBuilder->build('robot_b', $records, 0, $finalWorld, $snapshotB);

        return [
            'endFlag' => $endFlag,
            'turns' => $turns,
            'reflections' => [
                'robot_a' => $this->hash($reflectionA),
                'robot_b' => $this->hash($reflectionB),
            ],
        ];
    }

    /**
     * Build an Engine over a world freshly reset for $seed, with the earthquake
     * flipped on turn 0 so the earthquake offset equals the turn number.
     */
    private function makeEngine(int $seed, EpisodeConfig $config, SituationTextGenerator $textGen): Engine
    {
        $world = new WorldModel($config);
        $world->reset(Seed::fromInt($seed));
        // The observer layer normally records the earthquake start; here the
        // test stands in for it so every seed runs a real earthquake.
        $world->earthquakeStartTurn = 0;

        return new Engine(
            $world,
            $config,
            new RulesEvaluator(),
            new ActionExecutor(),
            new EarthquakePhase(),
            new ResidentController(),
            $textGen,
        );
    }

    /**
     * Read the private WorldModel an Engine wraps, so the golden master can
     * serialise the post-apply world and inspect room state. The Engine keeps
     * its world private; this is a read-only reflection used only in the test.
     */
    private function worldOf(Engine $engine): WorldModel
    {
        $ref = new \ReflectionProperty(Engine::class, 'world');
        /** @var WorldModel $world */
        $world = $ref->getValue($engine);

        return $world;
    }

    // ------------------------------------------------------------------
    // The fixed, seeded per-turn action sequence.
    // ------------------------------------------------------------------

    /**
     * The fixed per-turn actions for both robots on $turn of episode $seed.
     *
     * The sequence is itself deterministic from the episode seed, so it is the
     * same on every execution on any PHP 8.2 build. It opens with the heat
     * targets the later heat code will touch — the window, the window air
     * conditioner, the electric fan, and the refrigerator — via `use` and
     * `take`, plus hand_over, so their earthquake behaviour is pinned before
     * any heat code exists. It then cycles through moves, supports, covers,
     * speech, and an `unknown` reading, so every operation is exercised.
     *
     * The actions are issued straight to the engine regardless of whether they
     * are judged possible: the golden master records the exact judgement AND
     * outcome of each, so an impossible action (e.g. a `use` on an object in
     * another room) is pinned just as a possible one is.
     *
     * @return array<string,Action>
     */
    private function actionsForTurn(int $seed, int $turn): array
    {
        return [
            'robot_a' => $this->actionForRobot('robot_a', $seed, $turn),
            'robot_b' => $this->actionForRobot('robot_b', $seed, $turn),
        ];
    }

    private function actionForRobot(string $robotId, int $seed, int $turn): Action
    {
        // A per-robot offset so the two robots never march in lockstep, mixed
        // with the episode seed so the sequence varies by seed yet stays fixed.
        $offset = $robotId === 'robot_a' ? 0 : 7;
        $i = ($turn + $offset + ($seed % 5)) % 13;

        switch ($i) {
            case 0:
                // use the window (recognised specially, like the gas-main valve).
                return new Action($robotId, Operation::use->value, targetId: 'window');
            case 1:
                // take the window air conditioner (a heat target).
                return new Action($robotId, Operation::take->value, targetId: 'window_air_conditioner');
            case 2:
                // use the window air conditioner (a heat target).
                return new Action($robotId, Operation::use->value, targetId: 'window_air_conditioner');
            case 3:
                // take the electric fan (a heat target).
                return new Action($robotId, Operation::take->value, targetId: 'electric_fan');
            case 4:
                // use the electric fan (a heat target).
                return new Action($robotId, Operation::use->value, targetId: 'electric_fan');
            case 5:
                // take the refrigerator (a heat target).
                return new Action($robotId, Operation::take->value, targetId: 'refrigerator');
            case 6:
                // use the refrigerator (a heat target).
                return new Action($robotId, Operation::use->value, targetId: 'refrigerator');
            case 7:
                // hand something over to the resident.
                return new Action($robotId, Operation::hand_over->value, targetId: 'resident');
            case 8:
                // support the resident toward a spot in his room.
                return new Action(
                    $robotId,
                    Operation::support->value,
                    targetId: 'resident',
                    targetSpot: 'under_table',
                    words: ['Let us get you under the table.'],
                );
            case 9:
                // cover the resident.
                return new Action($robotId, Operation::cover->value, targetId: 'resident');
            case 10:
                // speak to the resident.
                return new Action($robotId, Operation::speak->value, words: ["It's all right, I'm here."]);
            case 11:
                // move toward the living room (adjacency decides if it happens).
                return new Action($robotId, Operation::move->value, targetId: 'living_room');
            default:
                // an unknown reading: no quote backed the action (Req 12.3).
                return new Action($robotId, Operation::unknown->value);
        }
    }

    // ------------------------------------------------------------------
    // Hashing helpers — each field is stored as a SHA-256 hash of a stable,
    // fully-ordered representation, or as a whole value where that is clearer.
    // ------------------------------------------------------------------

    private function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    private function hashJudgement(?\PoltergeistSwitch\Engine\Values\Judgement $j): ?string
    {
        if ($j === null) {
            return null;
        }

        return $this->hash($this->stableJson([
            'robotId' => $j->robotId,
            'possible' => $j->possible,
            'reason' => $j->reason,
            'dangerous' => $j->dangerous,
            'dangerCode' => $j->dangerCode,
            'duration' => $j->duration,
        ]));
    }

    private function hashOutcome(?\PoltergeistSwitch\Engine\Values\Outcome $o): ?string
    {
        if ($o === null) {
            return null;
        }

        return $this->hash($this->stableJson([
            'robotId' => $o->robotId,
            'success' => $o->success,
            'whatHappenedText' => $o->whatHappenedText,
            'seenByOtherText' => $o->seenByOtherText,
            'errorMessage' => $o->errorMessage,
            'worldChanges' => $o->worldChanges,
        ]));
    }

    private function hashResidentState(mixed $state): string
    {
        /** @var \PoltergeistSwitch\Engine\ResidentState $state */
        return $this->hash($this->stableJson([
            'bodyTemp' => $state->bodyTemp,
            'water' => $state->water,
            'injury' => $state->injury,
            'fear' => $state->fear,
            'weakened' => $state->weakened,
            'room' => $state->room,
            'spot' => $state->spot,
            'activity' => $state->activity,
            'coverFlag' => $state->coverFlag,
        ]));
    }

    /**
     * A stable JSON encoding for hashing: whole-number floats keep their
     * fraction (so 36.0 hashes as a float, not 36) and unicode survives
     * verbatim, matching WorldStateSerializer's own round-trip guarantee.
     */
    private function stableJson(mixed $value): string
    {
        return (string) json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    // ------------------------------------------------------------------
    // Fixture I/O.
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $fixture
     */
    private function writeFixture(array $fixture): void
    {
        $dir = dirname(self::FIXTURE_PATH);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        // Compact (no pretty-print): the fixture is machine-read by this test
        // and never hand-edited, and the per-field SHA-256 hashes already keep
        // it small — compact encoding keeps it smaller still.
        file_put_contents(
            self::FIXTURE_PATH,
            json_encode($fixture, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n"
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function readFixture(): array
    {
        $json = (string) file_get_contents(self::FIXTURE_PATH);

        /** @var array<string,mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
