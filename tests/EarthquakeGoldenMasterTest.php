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
use PoltergeistSwitch\Engine\SituationSpec;
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
 * (Requirement 1.1). It records, for a fixed set of seeds and several fixed,
 * seeded per-turn action sequences, every value that defines how an earthquake
 * episode behaves, reads, and replays, and FAILS the moment any of those values
 * differs from the committed fixture (Requirements 1.2, 1.3). The fixture is
 * committed once and is NEVER regenerated or edited by any later task: no later
 * task changes an expected earthquake value to make a test pass (Requirement
 * 1.4). If a value differs, the work stops and reports the difference
 * (Requirement 1.5) -- this test is the mechanism that enforces that.
 *
 * STRENGTHENING (task 1, revision): the first fixture missed two outcome texts
 * because the only run family flipped the quake on turn 0 (so the power was out
 * from offset 2) and never moved a robot to an in-room spot:
 *   - "You switched on {object}." (the electric fan / window air conditioner)
 *     was never reached: the robots rarely stood in the appliance's room while
 *     the power was still on.
 *   - "You moved to the window." (a same-room move onto the window spot) was
 *     never reached: the old sequence only moved between rooms.
 * Every existing run is KEPT unchanged (the quake_turn_0 family below is the
 * original sequence, seeds, turns, and both memory variants), and two run
 * families are added:
 *   - room_aware: each robot picks its targets (by the seed) from the objects
 *     in its CURRENT room, moves to ANY adjacent room (not only the living
 *     room), moves to spots (window, gas stove, under the table), and carries a
 *     held object into another room before using it or handing it over.
 *   - pre_quake: earthquakeStartTurn = 6, so while the power is still on the
 *     robots switch on the electric fan and the window air conditioner, carry
 *     the fan to another room, open and take from the refrigerator, and then
 *     the quake starts.
 * Both memory variants are recorded for every run, as before.
 *
 * How it pins the earthquake (Requirement 1.2):
 *   - At least 30 fixed integer seeds (seeds 1..40) so the drawn SituationSpec
 *     covers every starting situation: all three times of day, all four
 *     starting activities, and the bathing-in-bathroom constraint. See
 *     {@see SEEDS} and the coverage assertion.
 *   - Every seed is run through every family's full fixed action sequence
 *     TWICE: once with no memory snapshot and once with a non-empty memory
 *     snapshot set via Engine::setMemories, so both memory paths are pinned for
 *     every seed and every family.
 *   - The per-turn action sequences are themselves deterministic from the
 *     episode seed, so they are identical on every execution on any PHP 8.2
 *     build. Together they exercise moves (between rooms and onto spots), takes,
 *     uses, hand-overs, supports, covers, speech, and `unknown` readings, and
 *     SPECIFICALLY include `use` and `take` of the window, the window air
 *     conditioner, the electric fan, and the refrigerator, plus hand_over
 *     actions, so the earthquake behaviour of the exact targets the heat code
 *     will later touch is pinned before any heat code exists.
 *
 * What it records per turn, for both robots (Requirement 1.2): the situation
 * text, the judgements, the outcomes, the WorldStateSerializer output (not a
 * raw snapshot), the resident state, the resident line, and the end flag; plus
 * every reflection text at the end. Each field is stored as a full value or a
 * SHA-256 hash (hashes keep the fixture small); the test recomputes each value
 * and fails on any difference.
 *
 * No real API call: the driver issues deterministic {@see Action} values
 * straight to the pure Engine -- the only way to pin exact, reproducible engine
 * behaviour -- and the existing {@see FakeRobotClient}/{@see FakeRecorderClient}
 * fakes are instantiated to assert the run opens no network connection.
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
     * starting situation -- all three times of day, all four starting
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

    /** How many turns the quake_turn_0 and room_aware episodes are driven. */
    private const TURNS = 14;

    /**
     * How many turns a pre_quake episode is driven. The quake is flipped on
     * turn 6, so this runs well past the power-outage offset (turn 8) and the
     * delayed stove hazard (turn 12) while staying under EpisodeConfig::maxTurns.
     */
    private const PRE_QUAKE_TURNS = 16;

    /** The turn the quake is flipped on in the pre_quake family. */
    private const PRE_QUAKE_START = 6;

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
        // report it rather than edit the expected value (Requirements 1.3-1.5).
        self::assertSame(
            $expected,
            $live,
            'An earthquake value differs from the committed golden master. '
            . 'STOP and report the difference -- do NOT change the expected value '
            . '(heat-wave spec Requirements 1.3, 1.4, 1.5).'
        );
    }

    /**
     * The fixed seed set covers every starting situation (Requirement 1.2):
     * all three times of day, all four starting activities, and at least one
     * bathing start (which, by SituationSpec::fromSeed, never places a robot in
     * the bathroom -- the bathing-in-bathroom constraint).
     */
    public function testSeedsCoverEveryStartingSituation(): void
    {
        self::assertGreaterThanOrEqual(30, count(self::SEEDS), 'at least 30 fixed seeds are required');

        $times = [];
        $activities = [];
        $bathingSeeds = 0;

        foreach (self::SEEDS as $seed) {
            $spec = SituationSpec::fromSeed(Seed::fromInt($seed));
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

    /**
     * The outcome texts the first fixture missed are now reached by the new run
     * families somewhere across the seed set, for at least one robot:
     *   - "You switched on a flashlight." -- the literal switch-on line (a light
     *     source), produced by the pre_quake family while the power is on;
     *   - "You used an electric fan." and "You used a window air conditioner."
     *     -- a successful `use` on each heat appliance the heat code will later
     *     touch, also pinned by the pre_quake family while the power is on;
     *   - "You moved to the window." -- a same-room move onto the window spot in
     *     the room_aware family.
     * This asserts the strengthening actually closes the gaps the planted
     * changes exposed, independently of the hashed fixture.
     */
    public function testNewFamiliesReachThePreviouslyMissedOutcomeTexts(): void
    {
        $sawSwitchedOn = false;
        $sawFan = false;
        $sawAirConditioner = false;
        $movedToWindow = false;

        foreach (self::SEEDS as $seed) {
            foreach ($this->collectOutcomeTexts($seed, 'pre_quake') as $text) {
                if ($text === 'You switched on a flashlight.') {
                    $sawSwitchedOn = true;
                }
                if ($text === 'You used an electric fan.') {
                    $sawFan = true;
                }
                if ($text === 'You used a window air conditioner.') {
                    $sawAirConditioner = true;
                }
            }

            foreach ($this->collectOutcomeTexts($seed, 'room_aware') as $text) {
                if ($text === 'You moved to the window.') {
                    $movedToWindow = true;
                }
            }

            if ($sawSwitchedOn && $sawFan && $sawAirConditioner && $movedToWindow) {
                break;
            }
        }

        self::assertTrue(
            $sawSwitchedOn,
            'The pre_quake family must reach the literal "You switched on a '
            . 'flashlight." outcome while the power is still on.'
        );
        self::assertTrue(
            $sawFan,
            'The pre_quake family must switch on the electric fan while the '
            . 'power is on ("You used an electric fan.").'
        );
        self::assertTrue(
            $sawAirConditioner,
            'The pre_quake family must switch on the window air conditioner '
            . 'while the power is on ("You used a window air conditioner.").'
        );
        self::assertTrue(
            $movedToWindow,
            'The room_aware family must move a robot onto the window spot '
            . '("You moved to the window.").'
        );
    }
    // ------------------------------------------------------------------
    // Live computation of the full fixture.
    // ------------------------------------------------------------------

    /**
     * Build the complete live fixture. Every seed is run through three families
     * -- the original quake_turn_0 sequence (kept unchanged), the room_aware
     * sequence, and the pre_quake sequence -- and each family is recorded both
     * without and with a memory snapshot.
     *
     * @return array<string,mixed>
     */
    private function buildLiveFixture(): array
    {
        $fixture = [
            'schema' => 'earthquake-golden-master-v2',
            'turns' => self::TURNS,
            'preQuakeTurns' => self::PRE_QUAKE_TURNS,
            'preQuakeStart' => self::PRE_QUAKE_START,
            'situationTextVersion' => SituationTextGenerator::VERSION,
            'reflectionTextVersion' => ReflectionTextBuilder::REFLECTION_TEXT_VERSION,
            'seeds' => self::SEEDS,
            'families' => ['quake_turn_0', 'room_aware', 'pre_quake'],
            'runs' => [],
        ];

        foreach (self::SEEDS as $seed) {
            $fixture['runs'][(string) $seed] = [
                'quake_turn_0' => [
                    'no_memory' => $this->runEpisode($seed, 'quake_turn_0', false),
                    'with_memory' => $this->runEpisode($seed, 'quake_turn_0', true),
                ],
                'room_aware' => [
                    'no_memory' => $this->runEpisode($seed, 'room_aware', false),
                    'with_memory' => $this->runEpisode($seed, 'room_aware', true),
                ],
                'pre_quake' => [
                    'no_memory' => $this->runEpisode($seed, 'pre_quake', false),
                    'with_memory' => $this->runEpisode($seed, 'pre_quake', true),
                ],
            ];
        }

        return $fixture;
    }

    /**
     * Drive one full earthquake episode for a family and return every recorded
     * value for it.
     *
     * The engine is built directly over a freshly reset world for $seed. The
     * quake start turn and the turn count depend on the family:
     *   - quake_turn_0 / room_aware: quake flipped on turn 0 (offset == turn),
     *     driven for TURNS turns;
     *   - pre_quake: quake flipped on turn PRE_QUAKE_START, driven for
     *     PRE_QUAKE_TURNS turns, so robots act while the power is still on and
     *     then the quake strikes.
     *
     * The situation text for each turn is captured exactly as a robot reads it
     * (prepareTurn then situationTextFor on a parallel preview engine advanced
     * in lockstep), then the turn is applied on the record engine. After the
     * run, each robot's reflection text is built from the recorded turns.
     *
     * @return array<string,mixed>
     */
    private function runEpisode(int $seed, string $family, bool $withMemory): array
    {
        $config = new EpisodeConfig();
        $textGen = new SituationTextGenerator();

        $quakeStart = $family === 'pre_quake' ? self::PRE_QUAKE_START : 0;
        $turnCount = $family === 'pre_quake' ? self::PRE_QUAKE_TURNS : self::TURNS;

        $engine = $this->makeEngine($seed, $quakeStart, $config, $textGen);
        $preview = $this->makeEngine($seed, $quakeStart, $config, $textGen);

        if ($withMemory) {
            $engine->setMemories(self::MEMORY_BODIES);
            $preview->setMemories(self::MEMORY_BODIES);
        }

        $serializer = new WorldStateSerializer();

        $turns = [];
        $endFlag = false;

        for ($turn = 0; $turn < $turnCount; $turn++) {
            // The action sequence may depend on the live world (room_aware and
            // pre_quake pick targets from each robot's current room/hands), so
            // the actions are chosen from the record engine's world BEFORE the
            // turn is applied. The preview engine is advanced to the same point
            // first so its situation text is this turn's text.
            $actions = $this->actionsForTurn($engine, $seed, $family, $turn);

            $preview->prepareTurn($turn);
            $situationA = $preview->situationTextFor('robot_a');
            $situationB = $preview->situationTextFor('robot_b');
            $preview->applyTurn($turn, $actions);

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
     * flipped on $quakeStart so the earthquake offset equals turn - $quakeStart.
     */
    private function makeEngine(int $seed, int $quakeStart, EpisodeConfig $config, SituationTextGenerator $textGen): Engine
    {
        $world = new WorldModel($config);
        $world->reset(Seed::fromInt($seed));
        // The observer layer normally records the earthquake start; here the
        // test stands in for it so every seed runs a real earthquake.
        $world->earthquakeStartTurn = $quakeStart;

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
     * serialise the post-apply world, inspect room state, and choose room-aware
     * actions. The Engine keeps its world private; this is a read-only
     * reflection used only in the test.
     */
    private function worldOf(Engine $engine): WorldModel
    {
        $ref = new \ReflectionProperty(Engine::class, 'world');

        /** @var WorldModel $world */
        $world = $ref->getValue($engine);

        return $world;
    }

    // ------------------------------------------------------------------
    // The fixed, seeded per-turn action sequences, one per family.
    // ------------------------------------------------------------------

    /**
     * The fixed per-turn actions for both robots on $turn of episode $seed, for
     * the given family. Each family is deterministic from the seed (and, for
     * the room-aware and pre-quake families, the live world), so the sequence
     * is the same on every execution on any PHP 8.2 build.
     *
     * The actions are issued straight to the engine regardless of whether they
     * are judged possible: the golden master records the exact judgement AND
     * outcome of each, so an impossible action is pinned just as a possible one.
     *
     * @return array<string,Action>
     */
    private function actionsForTurn(Engine $engine, int $seed, string $family, int $turn): array
    {
        $world = $this->worldOf($engine);

        return [
            'robot_a' => $this->actionForRobot($world, 'robot_a', $seed, $family, $turn),
            'robot_b' => $this->actionForRobot($world, 'robot_b', $seed, $family, $turn),
        ];
    }

    private function actionForRobot(WorldModel $world, string $robotId, int $seed, string $family, int $turn): Action
    {
        return match ($family) {
            'quake_turn_0' => $this->quakeTurn0Action($robotId, $seed, $turn),
            'room_aware' => $this->roomAwareAction($world, $robotId, $seed, $turn),
            'pre_quake' => $this->preQuakeAction($world, $robotId, $seed, $turn),
            default => new Action($robotId, Operation::unknown->value),
        };
    }

    /**
     * The ORIGINAL quake_turn_0 per-turn action sequence, kept byte-for-byte so
     * every value recorded for this family in the first fixture is unchanged
     * (the heat targets, hand_over, support, cover, speak, move, and unknown).
     * It does not read the world; it is a pure function of robot, seed, turn.
     */
    private function quakeTurn0Action(string $robotId, int $seed, int $turn): Action
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

    /**
     * The room-aware per-turn action sequence. Each robot picks its targets
     * from the objects in its CURRENT room (chosen deterministically from the
     * seed), moves to ANY adjacent room (not only the living room), moves onto
     * spots (window, gas stove, under the table), and carries a held object
     * into another room before using it or handing it over.
     *
     * The cycle (per-robot, 12 steps) is deterministic from the seed, robot and
     * turn via a derived MT19937 draw (the codebase's crc32+Mt19937 idiom), so
     * two runs produce the same sequence on any PHP 8.2 build:
     *   0  move onto the window spot in the current room  -> "You moved to the window."
     *   1  move onto the gas stove spot in the current room
     *   2  move onto the under_table spot in the current room
     *   3  take a deterministic object from the current room
     *   4  carry the held object to an adjacent room (move)
     *   5  use the held object (now in another room) or an object in the room
     *   6  hand the held object over to the resident
     *   7  use a deterministic object in the current room
     *   8  move to a deterministic adjacent room
     *   9  support the resident toward an in-room spot
     *   10 cover the resident
     *   11 speak / unknown
     */
    private function roomAwareAction(WorldModel $world, string $robotId, int $seed, int $turn): Action
    {
        $i = $this->derivedInt("{$seed}:room_aware:{$robotId}:{$turn}", 0, 11);
        $room = $world->robotStates[$robotId]->room;
        $held = $world->robotStates[$robotId]->heldObjectId;

        switch ($i) {
            case 0:
                return new Action($robotId, Operation::move->value, targetId: $room, targetSpot: 'window');
            case 1:
                return new Action($robotId, Operation::move->value, targetId: $room, targetSpot: 'gas_stove');
            case 2:
                return new Action($robotId, Operation::move->value, targetId: $room, targetSpot: 'under_table');
            case 3:
                return new Action(
                    $robotId,
                    Operation::take->value,
                    targetId: $this->pickObjectInRoom($world, $room, "{$seed}:ra_take:{$robotId}:{$turn}"),
                );
            case 4:
                // Carry whatever is held (if anything) to a deterministic
                // adjacent room; when hands are empty this is still a plain
                // move, which the golden master records just the same.
                return new Action(
                    $robotId,
                    Operation::move->value,
                    targetId: $this->pickAdjacentRoom($world, $room, "{$seed}:ra_carry:{$robotId}:{$turn}"),
                );
            case 5:
                // Use the held object if the robot carries one (now in another
                // room), else use an object visible in the current room.
                $target = $held ?? $this->pickObjectInRoom($world, $room, "{$seed}:ra_use:{$robotId}:{$turn}");
                return new Action($robotId, Operation::use->value, targetId: $target);
            case 6:
                return new Action($robotId, Operation::hand_over->value, targetId: 'resident');
            case 7:
                return new Action(
                    $robotId,
                    Operation::use->value,
                    targetId: $this->pickObjectInRoom($world, $room, "{$seed}:ra_use2:{$robotId}:{$turn}"),
                );
            case 8:
                return new Action(
                    $robotId,
                    Operation::move->value,
                    targetId: $this->pickAdjacentRoom($world, $room, "{$seed}:ra_move:{$robotId}:{$turn}"),
                );
            case 9:
                return new Action(
                    $robotId,
                    Operation::support->value,
                    targetId: 'resident',
                    targetSpot: 'under_table',
                    words: ['Let us get you under the table.'],
                );
            case 10:
                return new Action($robotId, Operation::cover->value, targetId: 'resident');
            default:
                return ($turn % 2 === 0)
                    ? new Action($robotId, Operation::speak->value, words: ['Stay with me.'])
                    : new Action($robotId, Operation::unknown->value);
        }
    }

    /**
     * The pre-quake per-turn action sequence (earthquakeStartTurn = 6). While
     * the power is still ON (offset < 2, i.e. turns 0..7), the robots reach the
     * appliances and operate them, so the outcome texts the old fixture never
     * reached are pinned here:
     *
     *   robot_a: walk to the kitchen, switch ON the flashlight (a light source,
     *            "You switched on a flashlight."), then take the refrigerator
     *            and carry it into an adjacent room and use it there.
     *   robot_b: walk to the bedroom, switch on the electric fan ("You used an
     *            electric fan." -- a non-light appliance), take it, carry it
     *            into the living room and switch on the window air conditioner
     *            there too.
     *
     * So the pre_quake family pins, while the power is on: a successful `use`
     * on each heat appliance (electric fan, window air conditioner) and on the
     * refrigerator, a `take` and cross-room carry of each, AND the literal
     * "You switched on {object}." line via the flashlight. Navigation is a
     * deterministic one-step-toward-goal over the fixed room adjacency graph
     * (BFS next hop). From turn 8 on (quake struck at turn 6, power out at
     * offset 2) the robots fall back to a seeded mix of covers, supports,
     * speech and `unknown` readings so the post-quake turns are still exercised.
     */
    private function preQuakeAction(WorldModel $world, string $robotId, int $seed, int $turn): Action
    {
        if ($turn <= 7) {
            return $robotId === 'robot_a'
                ? $this->preQuakeRobotA($world, $robotId)
                : $this->preQuakeRobotB($world, $robotId);
        }

        // Post-quake fallback: a seeded mix so the later turns stay varied.
        $i = $this->derivedInt("{$seed}:pre_quake_post:{$robotId}:{$turn}", 0, 4);
        switch ($i) {
            case 0:
                return new Action($robotId, Operation::cover->value, targetId: 'resident');
            case 1:
                return new Action(
                    $robotId,
                    Operation::support->value,
                    targetId: 'resident',
                    targetSpot: 'under_table',
                    words: ['Let us get you under the table.'],
                );
            case 2:
                return new Action($robotId, Operation::cover->value);
            case 3:
                return new Action($robotId, Operation::speak->value, words: ["It's all right, I'm here."]);
            default:
                return new Action($robotId, Operation::unknown->value);
        }
    }

    /**
     * robot_a's pre-quake programme: drive to the kitchen, switch ON the
     * flashlight (the light source that yields "You switched on a flashlight."),
     * then take the refrigerator, carry it to an adjacent room, and use it.
     */
    private function preQuakeRobotA(WorldModel $world, string $robotId): Action
    {
        $room = $world->robotStates[$robotId]->room;
        $held = $world->robotStates[$robotId]->heldObjectId;
        $flashlight = $world->objects['flashlight'] ?? null;
        $fridge = $world->objects['refrigerator'] ?? null;

        // Phase 1: reach the kitchen and switch the flashlight on.
        if ($flashlight !== null && $flashlight->on === false) {
            if ($room !== 'kitchen') {
                return new Action($robotId, Operation::move->value, targetId: $this->nextHopToward($world, $room, 'kitchen'));
            }

            return new Action($robotId, Operation::use->value, targetId: 'flashlight');
        }

        // Phase 2: take the refrigerator (still in the kitchen), carry it out,
        // and use it in the next room.
        if ($held === null) {
            if ($fridge !== null && $fridge->room === $room) {
                return new Action($robotId, Operation::take->value, targetId: 'refrigerator');
            }
            if ($room !== 'kitchen') {
                return new Action($robotId, Operation::move->value, targetId: $this->nextHopToward($world, $room, 'kitchen'));
            }

            return new Action($robotId, Operation::use->value, targetId: 'refrigerator');
        }

        // Carry the held refrigerator to an adjacent room, then use it there.
        if ($room === 'kitchen') {
            return new Action($robotId, Operation::move->value, targetId: $this->firstAdjacent($world, $room));
        }

        return new Action($robotId, Operation::use->value, targetId: $held);
    }

    /**
     * robot_b's pre-quake programme: drive to the bedroom, switch on the
     * electric fan ("You used an electric fan."), take it, carry it to the
     * living room, and there switch on the window air conditioner too.
     */
    private function preQuakeRobotB(WorldModel $world, string $robotId): Action
    {
        $room = $world->robotStates[$robotId]->room;
        $held = $world->robotStates[$robotId]->heldObjectId;
        $fan = $world->objects['electric_fan'] ?? null;
        $ac = $world->objects['window_air_conditioner'] ?? null;

        // Phase 1: reach the bedroom and switch the electric fan on.
        if ($fan !== null && $fan->on === false && $held === null) {
            if ($room !== 'bedroom') {
                return new Action($robotId, Operation::move->value, targetId: $this->nextHopToward($world, $room, 'bedroom'));
            }

            return new Action($robotId, Operation::use->value, targetId: 'electric_fan');
        }

        // Phase 2: take the fan (in the bedroom), carry it to the living room.
        if ($held === null) {
            if ($fan !== null && $fan->room === $room) {
                return new Action($robotId, Operation::take->value, targetId: 'electric_fan');
            }
            if ($room !== 'bedroom') {
                return new Action($robotId, Operation::move->value, targetId: $this->nextHopToward($world, $room, 'bedroom'));
            }
        }

        if ($held !== null && $room !== 'living_room') {
            return new Action($robotId, Operation::move->value, targetId: $this->nextHopToward($world, $room, 'living_room'));
        }

        // In the living room: switch on the window air conditioner if it is off
        // ("You used a window air conditioner."), else use the carried fan.
        if ($ac !== null && $ac->on === false) {
            return new Action($robotId, Operation::use->value, targetId: 'window_air_conditioner');
        }

        return new Action($robotId, Operation::use->value, targetId: $held ?? 'window_air_conditioner');
    }

    // ------------------------------------------------------------------
    // Deterministic room / object selection helpers.
    // ------------------------------------------------------------------

    /**
     * A deterministic integer in [$min,$max] derived from a label, using the
     * same crc32 + MT19937 idiom EarthquakePhase uses for its fall draws, so it
     * never touches global RNG state and is identical on any PHP 8.2 build.
     */
    private function derivedInt(string $label, int $min, int $max): int
    {
        $randomizer = new \Random\Randomizer(new \Random\Engine\Mt19937(crc32($label)));

        return $randomizer->getInt($min, $max);
    }

    /**
     * Pick a registered object id that currently stands in $room, chosen
     * deterministically from $label. When the room holds no objects, fall back
     * to a fixed heat target so the action is still a real, recordable `take` /
     * `use` of a known object id (its judgement will be object_not_in_room).
     */
    private function pickObjectInRoom(WorldModel $world, string $room, string $label): string
    {
        $ids = [];
        foreach ($world->objects as $object) {
            if ($object->room === $room) {
                $ids[] = $object->id;
            }
        }

        if ($ids === []) {
            return 'refrigerator';
        }

        return $ids[$this->derivedInt($label, 0, count($ids) - 1)];
    }

    /**
     * Pick a room adjacent to $room, chosen deterministically from $label.
     * Every room in this house has at least one neighbour.
     */
    private function pickAdjacentRoom(WorldModel $world, string $room, string $label): string
    {
        $adjacent = $world->getRoom($room)->adjacentRooms;

        return $adjacent[$this->derivedInt($label, 0, count($adjacent) - 1)];
    }

    /** The first adjacent room of $room, in the fixed adjacency order. */
    private function firstAdjacent(WorldModel $world, string $room): string
    {
        return $world->getRoom($room)->adjacentRooms[0];
    }

    /**
     * The next room to step to on a shortest path from $from to $goal over the
     * fixed room adjacency graph (breadth-first). Returns $goal when adjacent,
     * $from when already there or unreachable (which never happens in this
     * fully-connected house).
     */
    private function nextHopToward(WorldModel $world, string $from, string $goal): string
    {
        if ($from === $goal) {
            return $from;
        }

        // BFS from $from, remembering the first hop taken to reach each room.
        $queue = [];
        $firstHop = [];
        foreach ($world->getRoom($from)->adjacentRooms as $neighbour) {
            $queue[] = $neighbour;
            $firstHop[$neighbour] = $neighbour;
        }

        $visited = [$from => true];
        while ($queue !== []) {
            $room = array_shift($queue);
            if (isset($visited[$room])) {
                continue;
            }
            $visited[$room] = true;

            if ($room === $goal) {
                return $firstHop[$room];
            }

            foreach ($world->getRoom($room)->adjacentRooms as $neighbour) {
                if (!isset($visited[$neighbour]) && !isset($firstHop[$neighbour])) {
                    $firstHop[$neighbour] = $firstHop[$room];
                    $queue[] = $neighbour;
                }
            }
        }

        return $from;
    }


    /**
     * Drive a full episode of $family for $seed (no memory) and collect every
     * robot's whatHappenedText across all turns, so a coverage test can assert
     * a specific outcome phrase was produced without touching the hashed
     * fixture. Mirrors runEpisode's driving but records only the texts.
     *
     * @return string[]
     */
    private function collectOutcomeTexts(int $seed, string $family): array
    {
        $config = new EpisodeConfig();
        $textGen = new SituationTextGenerator();

        $quakeStart = $family === 'pre_quake' ? self::PRE_QUAKE_START : 0;
        $turnCount = $family === 'pre_quake' ? self::PRE_QUAKE_TURNS : self::TURNS;

        $engine = $this->makeEngine($seed, $quakeStart, $config, $textGen);

        $texts = [];
        for ($turn = 0; $turn < $turnCount; $turn++) {
            $actions = $this->actionsForTurn($engine, $seed, $family, $turn);
            $result = $engine->applyTurn($turn, $actions);

            foreach (['robot_a', 'robot_b'] as $robotId) {
                $outcome = $result->outcomes[$robotId] ?? null;
                if ($outcome !== null && $outcome->whatHappenedText !== '') {
                    $texts[] = $outcome->whatHappenedText;
                }
            }

            if ($result->episodeEnded) {
                break;
            }
        }

        return $texts;
    }

    // ------------------------------------------------------------------
    // Hashing helpers -- each field is stored as a SHA-256 hash of a stable,
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
        // it small -- compact encoding keeps it smaller still.
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
