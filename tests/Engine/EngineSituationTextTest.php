<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\ActionExecutor;
use PoltergeistSwitch\Engine\EarthquakePhase;
use PoltergeistSwitch\Engine\Engine;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\ResidentController;
use PoltergeistSwitch\Engine\RulesEvaluator;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\SituationSpec;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\WorldModel;

/**
 * Integration tests for Engine::situationTextFor() (task 26.3). They drive the
 * Engine through whole turns and assert on the per-robot situation text the
 * generator produces from the live world and the last five TurnRecords:
 *
 *   - at earthquake offset 2 the text for BOTH robots reads "The lights go
 *     out." (Req 9.14);
 *   - at earthquake offset 5 the text for both robots reads "The shaking has
 *     stopped." (Req 9.18);
 *   - on the turn after a robot speaks, the OTHER robot's text carries the
 *     spoken line under the "In the last 15 seconds:" heading (Req 9.12,
 *     Req 9.16).
 *
 * Requirements: Req 9.12–9.18, Req 10.1
 */
class EngineSituationTextTest extends TestCase
{
    private EpisodeConfig $config;

    protected function setUp(): void
    {
        $this->config = new EpisodeConfig();
    }

    /**
     * Build an Engine over a world reset with the given SituationSpec. The
     * returned WorldModel is live, so a test can set earthquakeStartTurn on it
     * before driving turns. The observer layer normally sets that flag; here
     * the test stands in for it.
     *
     * @return array{Engine,WorldModel}
     */
    private function makeEngine(SituationSpec $spec): array
    {
        $world = new WorldModel($this->config);
        $world->reset(Seed::fromInt(1), $spec);

        $engine = new Engine(
            $world,
            $this->config,
            new RulesEvaluator(),
            new ActionExecutor(),
            new EarthquakePhase(),
            new ResidentController(),
            new SituationTextGenerator(),
        );

        return [$engine, $world];
    }

    /**
     * A night-time layout so the power outage at offset 2 darkens rooms (the
     * light rule leaves afternoon lit). robot_a and robot_b are in different
     * rooms; the resident sits at the low table in the living room.
     */
    private function nightSpec(): SituationSpec
    {
        return new SituationSpec(
            time: 'night',
            robotARoom: 'kitchen',
            robotBRoom: 'bedroom',
            manActivity: 'sitting_at_table',
            potBoiling: false,
        );
    }

    /**
     * A man asleep in his futon in the bedroom. Both robots stand in other
     * rooms (kitchen, living room) so each hears the earthquake shout from
     * another room ("You hear the man shout, ...").
     */
    private function bedroomAsleepSpec(): SituationSpec
    {
        return new SituationSpec(
            time: 'afternoon',
            robotARoom: 'kitchen',
            robotBRoom: 'living_room',
            manActivity: 'asleep_in_futon',
            potBoiling: false,
        );
    }

    // ------------------------------------------------------------------
    // Req 32: a man who started asleep wakes once the earthquake has struck.
    // The first shaking turn's text still reads "asleep in his futon" (the
    // wake happens in applyTurn, after prepareTurn produced that text); the
    // world stored after that turn, and every later text, read "lying in his
    // futon" (Req 32.1, Req 32.3). A man who started sitting is unaffected
    // (Req 32.6).
    // ------------------------------------------------------------------

    public function testAsleepManStillReadsAsleepOnFirstShakingTurn(): void
    {
        [$engine, $world] = $this->makeEngine($this->bedroomAsleepSpec());

        // Earthquake begins on turn 0; the first shaking turn is offset 1 (turn
        // 1). Apply turn 0, then prepare turn 1 and read its text before apply.
        $world->earthquakeStartTurn = 0;

        $engine->applyTurn(0, []);
        $engine->prepareTurn(1);

        $textA = $engine->situationTextFor('robot_a');

        // Req 32.1: the first shaking turn still describes him as asleep, and
        // the earthquake shout reaches the robot in another room.
        $this->assertStringContainsString('asleep in his futon', $textA);
        $this->assertStringContainsString('What\'s happening?!', $textA);
    }

    public function testAsleepManReadsLyingFromTheNextTurn(): void
    {
        [$engine, $world] = $this->makeEngine($this->bedroomAsleepSpec());

        $world->earthquakeStartTurn = 0;

        // Offset 1 is the first shaking turn; offset 2 is the next turn.
        $engine->applyTurn(0, []);
        $engine->prepareTurn(1);
        $engine->applyTurn(1, []);
        $engine->prepareTurn(2);

        $textA = $engine->situationTextFor('robot_a');

        // Req 32.3: from the next turn on he reads "lying in his futon" and the
        // text no longer says "asleep".
        $this->assertStringContainsString('lying in his futon', $textA);
        $this->assertStringNotContainsString('asleep', $textA);
    }

    public function testWorldStoredAfterFirstShakingTurnHasLyingActivity(): void
    {
        [$engine, $world] = $this->makeEngine($this->bedroomAsleepSpec());

        $world->earthquakeStartTurn = 0;

        // Apply the earthquake-start turn (offset 0) and the first shaking turn
        // (offset 1). The wake runs inside applyTurn(1, ...), so the live world
        // and the TurnResult snapshot stored for that turn hold lying_in_futon.
        $engine->applyTurn(0, []);
        $result = $engine->applyTurn(1, []);

        // Req 32.3: the stored world after the first shaking turn is awake.
        $this->assertSame('lying_in_futon', $world->residentState->activity);
        $this->assertSame('lying_in_futon', $result->residentState->activity);
    }

    public function testSittingManIsUnaffectedByTheWake(): void
    {
        // nightSpec() seats the man at the low table (sitting_at_table).
        [$engine, $world] = $this->makeEngine($this->nightSpec());

        $world->earthquakeStartTurn = 0;

        // Drive the same offsets a sleeping man would wake across.
        $engine->applyTurn(0, []);
        $engine->prepareTurn(1);
        $textOffsetOne = $engine->situationTextFor('robot_a');

        $engine->applyTurn(1, []);
        $engine->prepareTurn(2);
        $textOffsetTwo = $engine->situationTextFor('robot_a');

        // Req 32.6: the other starting activities are untouched — the activity
        // stays sitting_at_table and no text mentions sleeping or lying.
        $this->assertSame('sitting_at_table', $world->residentState->activity);
        $this->assertStringNotContainsString('lying in his futon', $textOffsetOne);
        $this->assertStringNotContainsString('lying in his futon', $textOffsetTwo);
        $this->assertStringNotContainsString('asleep', $textOffsetOne);
        $this->assertStringNotContainsString('asleep', $textOffsetTwo);
    }

    // ------------------------------------------------------------------
    // Offset 1 (first shaking turn): the earthquake shout reaches both robots
    // inside the shaking paragraph (Req 7.1, Req 9.10–9.11).
    // ------------------------------------------------------------------

    public function testEarthquakeShoutAppearsOnFirstShakingTurnForBothRobots(): void
    {
        [$engine, $world] = $this->makeEngine($this->nightSpec());

        // The earthquake begins on turn 0 (offset 0); the first shaking turn is
        // offset 1 — turn 1. The resident sits in the living room while robot_a
        // is in the kitchen and robot_b in the bedroom, so each hears the shout
        // from another room ("You hear the man shout, ...").
        $world->earthquakeStartTurn = 0;

        // Prepare turn 1 so the situation text renders that turn's world, then
        // read the text before applying — this mirrors the real server flow
        // where the robots read the prompt for the turn about to be played.
        $engine->applyTurn(0, []);
        $engine->prepareTurn(1);

        $textA = $engine->situationTextFor('robot_a');
        $textB = $engine->situationTextFor('robot_b');

        $this->assertStringContainsString('Suddenly the floor begins to shake hard.', $textA);
        $this->assertStringContainsString('Suddenly the floor begins to shake hard.', $textB);
        $this->assertStringContainsString(
            'You hear the man shout, "What\'s happening?!"',
            $textA,
        );
        $this->assertStringContainsString(
            'You hear the man shout, "What\'s happening?!"',
            $textB,
        );
    }

    // ------------------------------------------------------------------
    // Offset 2: "The lights go out." for both robots (Req 9.14).
    // ------------------------------------------------------------------

    public function testLightsGoOutAtOffsetTwoForBothRobots(): void
    {
        [$engine, $world] = $this->makeEngine($this->nightSpec());

        // The earthquake begins on turn 0, so the offset equals the turn
        // number: turn 2 is offset 2, the power-outage turn.
        $world->earthquakeStartTurn = 0;

        for ($turn = 0; $turn <= 2; $turn++) {
            $engine->applyTurn($turn, []);
        }

        $textA = $engine->situationTextFor('robot_a');
        $textB = $engine->situationTextFor('robot_b');

        $this->assertStringContainsString('The lights go out.', $textA);
        $this->assertStringContainsString('The lights go out.', $textB);
    }

    // ------------------------------------------------------------------
    // Offset 5: "The shaking has stopped." for both robots (Req 9.18).
    // ------------------------------------------------------------------

    public function testShakingStoppedAtOffsetFiveForBothRobots(): void
    {
        [$engine, $world] = $this->makeEngine($this->nightSpec());

        $world->earthquakeStartTurn = 0;

        // Offsets 1–4 shake; offset 5 is the first still turn, so its text
        // reads "The shaking has stopped."
        for ($turn = 0; $turn <= 5; $turn++) {
            $engine->applyTurn($turn, []);
        }

        $textA = $engine->situationTextFor('robot_a');
        $textB = $engine->situationTextFor('robot_b');

        $this->assertStringContainsString('The shaking has stopped.', $textA);
        $this->assertStringContainsString('The shaking has stopped.', $textB);
    }

    // ------------------------------------------------------------------
    // Speech propagation: the turn after robot_a speaks, robot_b hears it
    // under the "In the last 15 seconds:" heading (Req 9.12, Req 9.16).
    // ------------------------------------------------------------------

    public function testSpokenLineReachesOtherRobotOnTheNextTurn(): void
    {
        // A quiet world (no earthquake). robot_a in the kitchen, robot_b in the
        // bedroom — different rooms, which is fine: spoken words are heard in
        // every room (Req 9.12).
        $spec = new SituationSpec(
            time: 'afternoon',
            robotARoom: 'kitchen',
            robotBRoom: 'bedroom',
            manActivity: 'sitting_at_table',
            potBoiling: false,
        );
        [$engine] = $this->makeEngine($spec);

        $spokenLine = 'Please stay where you are.';

        // Turn 0: robot_a speaks (a speak action carrying one word line).
        $speak = new Action(
            robotId: 'robot_a',
            operation: Operation::speak->value,
            words: [$spokenLine],
        );
        $engine->applyTurn(0, ['robot_a' => $speak]);

        // Now building the text for the next turn (turn 1): the only recent
        // TurnRecord is turn 0, so it is the latest block, headed "In the last
        // 15 seconds:", and it carries robot_a's spoken line.
        $textB = $engine->situationTextFor('robot_b');

        $this->assertStringContainsString('In the last 15 seconds:', $textB);
        $this->assertStringContainsString(
            'You heard the other robot say, "' . $spokenLine . '"',
            $textB,
        );
    }
}
