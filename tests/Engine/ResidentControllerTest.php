<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\Enums\LightLevel;
use PoltergeistSwitch\Engine\Enums\PowerState;
use PoltergeistSwitch\Engine\ResidentController;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\SituationSpec;
use PoltergeistSwitch\Engine\WorldModel;
use PoltergeistSwitch\Engine\Values\TurnActions;

/**
 * Unit tests for ResidentController::updateState (task 15.2).
 *
 * Covers the per-turn resident state transitions driven once per turn:
 *   - injury from falling objects in the resident's room (Req 6.3);
 *   - the cover override that zeroes the injury increment (Req 6.3);
 *   - fear from shaking (Req 6.4);
 *   - fear from a dark room during a power outage (Req 6.5);
 *   - fear reduction from robot speech, same-room vs other-room (Req 6.6);
 *   - clamping of every field to its configured range (Req 6.1, 6.9);
 *   - the weakened flag set/cleared against the threshold (Req 6.10).
 *
 * Requirements: Req 6.1–6.11
 */
class ResidentControllerTest extends TestCase
{
    /**
     * A deterministic world placed with an explicit SituationSpec: the
     * resident sits at the table in the living room, robot_a starts in the
     * kitchen and robot_b in the bedroom, no boiling pot. Individual tests
     * then mutate residentState / houseState / objects / room light directly
     * to build each scenario.
     */
    private function makeWorld(): WorldModel
    {
        $world = new WorldModel();
        $world->reset(
            Seed::fromInt(1),
            new SituationSpec('afternoon', 'kitchen', 'bedroom', 'sitting_at_table', false)
        );

        return $world;
    }

    /**
     * A TurnActions with no speech and no cover, the common no-op default.
     */
    private function noActions(): TurnActions
    {
        return new TurnActions([], [], false);
    }

    // ------------------------------------------------------------------
    // 1. Injury from falling objects in the resident's room (Req 6.3).
    // ------------------------------------------------------------------

    public function testInjuryDeltaAppliedWhenShakingWithFallenObjectInResidentRoom(): void
    {
        $world  = $this->makeWorld();
        $config = new EpisodeConfig();

        // Shaking house with objects falling, and a fallen object in the
        // resident's own room (living room).
        $world->houseState->shaking        = true;
        $world->houseState->fallingObjects = true;
        $world->objects['tv']->fallen      = true; // tv lives in the living room

        $before = $world->residentState->injury;

        (new ResidentController())->updateState($world, $config, $this->noActions());

        $this->assertSame(
            $before + $config->injuryDeltaFalling,
            $world->residentState->injury,
            'Injury must rise by injuryDeltaFalling when a fallen object is in the resident room'
        );
    }

    public function testNoInjuryWhenFallenObjectIsInAnotherRoom(): void
    {
        $world  = $this->makeWorld();
        $config = new EpisodeConfig();

        // Shaking and falling, but the only fallen object is elsewhere.
        $world->houseState->shaking            = true;
        $world->houseState->fallingObjects     = true;
        $world->objects['refrigerator']->fallen = true; // kitchen, not the resident room

        $before = $world->residentState->injury;

        (new ResidentController())->updateState($world, $config, $this->noActions());

        $this->assertSame(
            $before,
            $world->residentState->injury,
            'Injury must not rise when the fallen object is in another room'
        );
    }

    // ------------------------------------------------------------------
    // 2. Cover override: injury increment is zero when covered (Req 6.3).
    // ------------------------------------------------------------------

    public function testInjuryDeltaIsZeroWhenResidentIsCovered(): void
    {
        $world  = $this->makeWorld();
        $config = new EpisodeConfig();

        $world->houseState->shaking         = true;
        $world->houseState->fallingObjects  = true;
        $world->objects['tv']->fallen       = true;
        // The cover override reads the resident's own coverFlag.
        $world->residentState->coverFlag    = true;

        $before = $world->residentState->injury;

        (new ResidentController())->updateState($world, $config, $this->noActions());

        $this->assertSame(
            $before,
            $world->residentState->injury,
            'A set coverFlag must zero the falling-object injury increment'
        );
    }

    public function testInjuryDeltaIsZeroWhenResidentIsUnderTable(): void
    {
        $world  = $this->makeWorld();
        $config = new EpisodeConfig();

        $world->houseState->shaking        = true;
        $world->houseState->fallingObjects = true;
        $world->objects['tv']->fallen      = true;
        // Crouching under the low table shields the resident just like cover
        // does (Req 7.16), even with no coverFlag set this turn.
        $world->residentState->coverFlag   = false;
        $world->residentState->spot        = 'under_table';

        $before = $world->residentState->injury;

        (new ResidentController())->updateState($world, $config, $this->noActions());

        $this->assertSame(
            $before,
            $world->residentState->injury,
            'The under_table spot must zero the falling-object injury increment'
        );
    }

    // ------------------------------------------------------------------
    // 3. Fear from shaking (Req 6.4).
    // ------------------------------------------------------------------

    public function testFearDeltaAppliedWhenShaking(): void
    {
        $world  = $this->makeWorld();
        $config = new EpisodeConfig();

        $world->houseState->shaking = true;

        $before = $world->residentState->fear;

        (new ResidentController())->updateState($world, $config, $this->noActions());

        $this->assertSame(
            $before + $config->fearDeltaShaking,
            $world->residentState->fear,
            'Fear must rise by fearDeltaShaking on a shaking turn'
        );
    }

    // ------------------------------------------------------------------
    // 4. Fear from darkness during a power outage (Req 6.5).
    // ------------------------------------------------------------------

    public function testFearDeltaAppliedWhenPowerOutAndResidentRoomDark(): void
    {
        $world  = $this->makeWorld();
        $config = new EpisodeConfig();

        $world->houseState->power = PowerState::outage;
        $world->getRoom($world->residentState->room)->lightLevel = LightLevel::dark;

        $before = $world->residentState->fear;

        (new ResidentController())->updateState($world, $config, $this->noActions());

        $this->assertSame(
            $before + $config->fearDeltaDark,
            $world->residentState->fear,
            'Fear must rise by fearDeltaDark when power is out and the resident room is dark'
        );
    }

    public function testNoDarknessFearWhenRoomDarkButPowerOn(): void
    {
        $world  = $this->makeWorld();
        $config = new EpisodeConfig();

        // Dark room but power still on → no darkness fear.
        $world->houseState->power = PowerState::on;
        $world->getRoom($world->residentState->room)->lightLevel = LightLevel::dark;

        $before = $world->residentState->fear;

        (new ResidentController())->updateState($world, $config, $this->noActions());

        $this->assertSame(
            $before,
            $world->residentState->fear,
            'Darkness fear requires a power outage, not just a dark room'
        );
    }

    // ------------------------------------------------------------------
    // 5. Fear reduction from speech, same-room vs other-room (Req 6.6).
    // ------------------------------------------------------------------

    public function testFearDecreasesBySameRoomSpeechDelta(): void
    {
        $world  = $this->makeWorld();
        $config = new EpisodeConfig();

        // Give the resident some fear to reduce so we do not clamp at zero.
        $world->residentState->fear = 50;
        $residentRoom = $world->residentState->room;

        $actions = new TurnActions(
            ['robot_a'],
            ['robot_a' => $residentRoom],
            false
        );

        (new ResidentController())->updateState($world, $config, $actions);

        $this->assertSame(
            50 - $config->fearDeltaSpeakSameRoom,
            $world->residentState->fear,
            'Speech in the resident room reduces fear at the same-room rate'
        );
    }

    public function testFearDecreasesByOtherRoomSpeechDelta(): void
    {
        $world  = $this->makeWorld();
        $config = new EpisodeConfig();

        $world->residentState->fear = 50;

        // robot_a speaks in the kitchen while the resident is in the living room.
        $actions = new TurnActions(
            ['robot_a'],
            ['robot_a' => 'kitchen'],
            false
        );

        (new ResidentController())->updateState($world, $config, $actions);

        $this->assertSame(
            50 - $config->fearDeltaSpeakOtherRoom,
            $world->residentState->fear,
            'Speech in another room reduces fear at the other-room rate'
        );
    }

    // ------------------------------------------------------------------
    // 6. Clamping of every field to its configured range (Req 6.1, 6.9).
    // ------------------------------------------------------------------

    public function testAllStateValuesClampedToTheirRanges(): void
    {
        $world  = $this->makeWorld();
        $config = new EpisodeConfig();

        // Drive every field out of range in both directions across two runs.
        // First: push everything above the maximum.
        $world->residentState->bodyTemp = 99.0;
        $world->residentState->water    = 500;
        $world->residentState->injury   = 500;
        $world->residentState->fear     = 500;

        (new ResidentController())->updateState($world, $config, $this->noActions());

        $this->assertSame($config->bodyTempMax, $world->residentState->bodyTemp, 'body_temp clamps to its max');
        $this->assertSame($config->waterMax, $world->residentState->water, 'water clamps to its max');
        $this->assertSame($config->injuryMax, $world->residentState->injury, 'injury clamps to its max');
        $this->assertSame($config->fearMax, $world->residentState->fear, 'fear clamps to its max');

        // Second: push everything below the minimum.
        $world->residentState->bodyTemp = -5.0;
        $world->residentState->water    = -50;
        $world->residentState->injury   = -50;
        $world->residentState->fear     = -50;

        (new ResidentController())->updateState($world, $config, $this->noActions());

        $this->assertSame($config->bodyTempMin, $world->residentState->bodyTemp, 'body_temp clamps to its min');
        $this->assertSame($config->waterMin, $world->residentState->water, 'water clamps to its min');
        $this->assertSame($config->injuryMin, $world->residentState->injury, 'injury clamps to its min');
        $this->assertSame($config->fearMin, $world->residentState->fear, 'fear clamps to its min');
    }

    // ------------------------------------------------------------------
    // 7. Weakened flag set/cleared against the threshold (Req 6.10).
    // ------------------------------------------------------------------

    public function testWeakenedFlagSetWhenBodyTempReachesThreshold(): void
    {
        $world  = $this->makeWorld();
        $config = new EpisodeConfig();

        $world->residentState->bodyTemp = $config->weakenedThreshold;
        $world->residentState->weakened = false;

        (new ResidentController())->updateState($world, $config, $this->noActions());

        $this->assertTrue(
            $world->residentState->weakened,
            'weakened must be set when bodyTemp reaches weakenedThreshold'
        );
    }

    public function testWeakenedFlagClearedWhenBodyTempBelowThreshold(): void
    {
        $world  = $this->makeWorld();
        $config = new EpisodeConfig();

        // Start weakened, then drop just below the threshold.
        $world->residentState->bodyTemp = $config->weakenedThreshold - 0.1;
        $world->residentState->weakened = true;

        (new ResidentController())->updateState($world, $config, $this->noActions());

        $this->assertFalse(
            $world->residentState->weakened,
            'weakened must be cleared when bodyTemp drops below weakenedThreshold'
        );
    }

    // ==================================================================
    // emitDialogueLine (task 16.2) — scripted resident lines, Req 7.1–7.7.
    //
    // The resident speaks at most one scripted line per turn. The engine
    // walks $config->linePriority (earthquake, fear, water, darkness) and
    // returns the first line whose condition holds and whose cooldown has
    // expired. The earthquake-start shout carries no cooldown; every other
    // line is suppressed for lineCooldownTurns turns after it was delivered.
    // ==================================================================

    // ------------------------------------------------------------------
    // 8. Earthquake line fires on earthquakeStartTurn, ignoring cooldown.
    // ------------------------------------------------------------------

    public function testEarthquakeLineFiresOnEarthquakeStartTurn(): void
    {
        $world  = $this->makeWorld();
        $config = new EpisodeConfig();

        $world->earthquakeStartTurn = 3;

        $line = (new ResidentController())->emitDialogueLine($world, $config, 3);

        $this->assertSame(
            "What's happening?!",
            $line,
            'The earthquake shout fires on the turn the earthquake starts'
        );
    }

    public function testEarthquakeLineDoesNotFireOnOtherTurns(): void
    {
        $world  = $this->makeWorld();
        $config = new EpisodeConfig();

        $world->earthquakeStartTurn = 3;

        $this->assertNull(
            (new ResidentController())->emitDialogueLine($world, $config, 4),
            'The earthquake shout only fires on the earthquakeStartTurn itself'
        );
    }

    public function testEarthquakeLineIgnoresCooldown(): void
    {
        $world  = $this->makeWorld();
        $config = new EpisodeConfig();

        $controller = new ResidentController();

        // The earthquake start turn is 3, and the earthquake line was
        // "delivered" on turn 3 (one turn ago). Despite being inside the
        // cooldown window, the shout still fires because turnNumber still
        // equals earthquakeStartTurn — the earthquake line has no cooldown.
        $world->earthquakeStartTurn = 3;
        $world->residentState->lastLineDeliveredOn = ['earthquake' => 3];

        $this->assertSame(
            "What's happening?!",
            $controller->emitDialogueLine($world, $config, 3),
            'The earthquake shout ignores the cooldown and fires whenever turnNumber == earthquakeStartTurn'
        );
    }

    // ------------------------------------------------------------------
    // 9. Fear line fires at/above threshold and obeys the cooldown.
    // ------------------------------------------------------------------

    public function testFearLineFiresWhenFearAtThreshold(): void
    {
        $world  = $this->makeWorld();
        $config = new EpisodeConfig();

        $world->residentState->fear = $config->fearSpeechThreshold; // 70

        $line = (new ResidentController())->emitDialogueLine($world, $config, 5);

        $this->assertSame(
            "I'm scared...",
            $line,
            'The fear line fires when fear reaches fearSpeechThreshold'
        );
        $this->assertSame(
            5,
            $world->residentState->lastLineDeliveredOn['fear'],
            'Delivering the fear line records the turn number for the cooldown'
        );
    }

    public function testFearLineSuppressedWithinCooldownWindow(): void
    {
        $world  = $this->makeWorld();
        $config = new EpisodeConfig();

        $world->residentState->fear = $config->fearSpeechThreshold;
        // Delivered on turn 5; cooldown is 5 turns. Turn 9 is 4 turns later,
        // still inside the window (9 - 5 = 4 <= 5), so the line is suppressed.
        $world->residentState->lastLineDeliveredOn = ['fear' => 5];

        $this->assertNull(
            (new ResidentController())->emitDialogueLine($world, $config, 9),
            'The fear line is suppressed within lineCooldownTurns of its last delivery'
        );
    }

    public function testFearLineFiresAgainOnceCooldownExpires(): void
    {
        $world  = $this->makeWorld();
        $config = new EpisodeConfig();

        $world->residentState->fear = $config->fearSpeechThreshold;
        // Delivered on turn 5; the cooldown of 5 turns expires once the gap
        // exceeds 5. Turn 11 (11 - 5 = 6 > 5) is clear, so it fires again.
        $world->residentState->lastLineDeliveredOn = ['fear' => 5];

        $this->assertSame(
            "I'm scared...",
            (new ResidentController())->emitDialogueLine($world, $config, 11),
            'The fear line fires again once more than lineCooldownTurns have passed'
        );
    }

    // ------------------------------------------------------------------
    // 10. Water line fires at/below threshold and obeys the cooldown.
    // ------------------------------------------------------------------

    public function testWaterLineFiresWhenWaterAtThreshold(): void
    {
        $world  = $this->makeWorld();
        $config = new EpisodeConfig();

        $world->residentState->water = $config->waterSpeechThreshold; // 40

        $line = (new ResidentController())->emitDialogueLine($world, $config, 2);

        $this->assertSame(
            'Water... give me some water.',
            $line,
            'The water line fires when water drops to waterSpeechThreshold'
        );
        $this->assertSame(
            2,
            $world->residentState->lastLineDeliveredOn['water'],
            'Delivering the water line records the turn number for the cooldown'
        );
    }

    public function testWaterLineSuppressedWithinCooldownWindow(): void
    {
        $world  = $this->makeWorld();
        $config = new EpisodeConfig();

        $world->residentState->water = $config->waterSpeechThreshold;
        // Delivered on turn 2; turn 6 is 4 turns later (6 - 2 = 4 <= 5), so
        // the line is still on cooldown and must not fire.
        $world->residentState->lastLineDeliveredOn = ['water' => 2];

        $this->assertNull(
            (new ResidentController())->emitDialogueLine($world, $config, 6),
            'The water line is suppressed within lineCooldownTurns of its last delivery'
        );
    }

    // ------------------------------------------------------------------
    // 11. Darkness line fires when the resident room is dark, obeys cooldown.
    // ------------------------------------------------------------------

    public function testDarknessLineFiresWhenResidentRoomDark(): void
    {
        $world  = $this->makeWorld();
        $config = new EpisodeConfig();

        $world->getRoom($world->residentState->room)->lightLevel = LightLevel::dark;

        $line = (new ResidentController())->emitDialogueLine($world, $config, 7);

        $this->assertSame(
            "I can't see anything.",
            $line,
            'The darkness line fires when the resident room light level is dark'
        );
        $this->assertSame(
            7,
            $world->residentState->lastLineDeliveredOn['darkness'],
            'Delivering the darkness line records the turn number for the cooldown'
        );
    }

    public function testDarknessLineSuppressedWithinCooldownWindow(): void
    {
        $world  = $this->makeWorld();
        $config = new EpisodeConfig();

        $world->getRoom($world->residentState->room)->lightLevel = LightLevel::dark;
        // Delivered on turn 7; turn 10 is 3 turns later (10 - 7 = 3 <= 5), so
        // the darkness line is still on cooldown.
        $world->residentState->lastLineDeliveredOn = ['darkness' => 7];

        $this->assertNull(
            (new ResidentController())->emitDialogueLine($world, $config, 10),
            'The darkness line is suppressed within lineCooldownTurns of its last delivery'
        );
    }

    // ------------------------------------------------------------------
    // 12. At most one line per turn; the highest-priority line wins (Req 7.7).
    // ------------------------------------------------------------------

    public function testOnlyHighestPriorityLineReturnedWhenAllConditionsHold(): void
    {
        $world  = $this->makeWorld();
        $config = new EpisodeConfig();

        // Satisfy every condition at once: earthquake start this turn, high
        // fear, low water, and a dark resident room. Priority order is
        // earthquake, fear, water, darkness — so the earthquake shout wins.
        $world->earthquakeStartTurn       = 4;
        $world->residentState->fear       = $config->fearSpeechThreshold;
        $world->residentState->water      = $config->waterSpeechThreshold;
        $world->getRoom($world->residentState->room)->lightLevel = LightLevel::dark;

        $line = (new ResidentController())->emitDialogueLine($world, $config, 4);

        $this->assertSame(
            "What's happening?!",
            $line,
            'When several conditions hold at once, the highest-priority (earthquake) line is returned'
        );

        // Only the earthquake line is recorded as delivered; the lower-priority
        // lines never fired, so their cooldown slots stay empty.
        $this->assertArrayHasKey('earthquake', $world->residentState->lastLineDeliveredOn);
        $this->assertArrayNotHasKey('fear', $world->residentState->lastLineDeliveredOn);
        $this->assertArrayNotHasKey('water', $world->residentState->lastLineDeliveredOn);
        $this->assertArrayNotHasKey('darkness', $world->residentState->lastLineDeliveredOn);
    }

    public function testFearWinsOverWaterAndDarknessWhenEarthquakeAbsent(): void
    {
        $world  = $this->makeWorld();
        $config = new EpisodeConfig();

        // No earthquake this turn, but fear, water, and darkness all hold.
        // Next in priority after earthquake is fear.
        $world->residentState->fear  = $config->fearSpeechThreshold;
        $world->residentState->water = $config->waterSpeechThreshold;
        $world->getRoom($world->residentState->room)->lightLevel = LightLevel::dark;

        $line = (new ResidentController())->emitDialogueLine($world, $config, 6);

        $this->assertSame(
            "I'm scared...",
            $line,
            'With no earthquake, the fear line outranks the water and darkness lines'
        );
        $this->assertArrayNotHasKey('water', $world->residentState->lastLineDeliveredOn);
        $this->assertArrayNotHasKey('darkness', $world->residentState->lastLineDeliveredOn);
    }

    // ==================================================================
    // evaluateRefusal (task 17.2) — the resident's move-refusal rule,
    // Req 7.8–7.11. The proposal string carries the two per-turn facts the
    // resident weighs: a `support` token means a robot supported him this
    // turn, a `destination` token means a robot named where they are taking
    // him. A weakened resident accepts any move regardless of those facts.
    //   true  = refuse,  false = accept.
    // ==================================================================

    // ------------------------------------------------------------------
    // 13. weakened=false, no support action → refuse (Req 7.8–7.9).
    // ------------------------------------------------------------------

    public function testRefusesMoveWhenNotWeakenedAndNoSupport(): void
    {
        $world  = $this->makeWorld();
        $config = new EpisodeConfig();

        $world->residentState->weakened = false;

        $this->assertTrue(
            (new ResidentController())->evaluateRefusal('move_resident', $world, $config),
            'A move proposal without a support action is refused'
        );
    }

    // ------------------------------------------------------------------
    // 14. weakened=false, support but no destination reason → refuse (Req 7.9).
    // ------------------------------------------------------------------

    public function testRefusesMoveWhenSupportButNoDestinationReason(): void
    {
        $world  = $this->makeWorld();
        $config = new EpisodeConfig();

        $world->residentState->weakened = false;

        $this->assertTrue(
            (new ResidentController())->evaluateRefusal('move_resident support', $world, $config),
            'A supported move is still refused when no robot named the destination'
        );
    }

    // ------------------------------------------------------------------
    // 15. weakened=false, support AND destination reason → accept (Req 7.10).
    // ------------------------------------------------------------------

    public function testAcceptsMoveWhenSupportedAndDestinationNamed(): void
    {
        $world  = $this->makeWorld();
        $config = new EpisodeConfig();

        $world->residentState->weakened = false;

        $this->assertFalse(
            (new ResidentController())->evaluateRefusal(
                'move_resident support destination',
                $world,
                $config
            ),
            'A supported move with a named destination is accepted'
        );
    }

    // ------------------------------------------------------------------
    // 16. weakened=true → always accept, overriding support/reason (Req 7.11).
    // ------------------------------------------------------------------

    public function testWeakenedResidentAlwaysAcceptsMove(): void
    {
        $world  = $this->makeWorld();
        $config = new EpisodeConfig();

        $world->residentState->weakened = true;

        // Neither a support action nor a destination reason is present, yet a
        // weakened resident accepts the move anyway (Req 7.11 overrides 9–10).
        $this->assertFalse(
            (new ResidentController())->evaluateRefusal('move_resident', $world, $config),
            'A weakened resident accepts a move proposal regardless of support or reason'
        );
    }

    // ------------------------------------------------------------------
    // 17. No move proposal → nothing to refuse (accept/no-op).
    // ------------------------------------------------------------------

    public function testNoRefusalWhenNoMoveProposed(): void
    {
        $world  = $this->makeWorld();
        $config = new EpisodeConfig();

        $world->residentState->weakened = false;

        $this->assertFalse(
            (new ResidentController())->evaluateRefusal('', $world, $config),
            'With no move proposal there is nothing to refuse'
        );
    }

    // ------------------------------------------------------------------
    // 18. The refusal line is the fixed English text (Req 7.9).
    // ------------------------------------------------------------------

    public function testRefusalLineText(): void
    {
        $this->assertSame(
            "I don't want to move from here.",
            (new ResidentController())->refusalLine(),
            'The refusal line matches the scripted text in Req 7.9'
        );
    }
}
