<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\EarthquakePhase;
use PoltergeistSwitch\Engine\Enums\LightLevel;
use PoltergeistSwitch\Engine\Enums\PowerState;
use PoltergeistSwitch\Engine\ObjectState;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\SituationSpec;
use PoltergeistSwitch\Engine\WorldModel;

/**
 * Unit tests for EarthquakePhase (task 14.2).
 *
 * Covers the earthquake disaster rules driven once per turn by advanceTurn():
 *   - the shaking flag across the 13-offset table (Req 3.2);
 *   - the power outage at offset 2 that never recovers (Req 3.3);
 *   - the light rule that darkens non-afternoon rooms when power fails,
 *     and leaves afternoon rooms lit (Req 2.5, Req 3.3);
 *   - the delayed stove/pot hazard at offset 6, and its cancellation when
 *     the flame is turned off beforehand (Req 3.5);
 *   - the disasterSubsided flag at offset 13 (Req 3.1);
 *   - determinism of the fallen-object set for a fixed seed (Req 3.8).
 *
 * Requirements: Req 3.1–3.8, Req 2.5
 */
class EarthquakePhaseTest extends TestCase
{
    /**
     * A world reset with an explicit SituationSpec. Robots are kept out of the
     * bathroom so any manActivity (including bathing) is valid.
     */
    private function makeWorld(string $time, string $manActivity, bool $potBoiling = false): WorldModel
    {
        $world = new WorldModel();
        $world->reset(
            Seed::fromInt(1),
            new SituationSpec($time, 'kitchen', 'living_room', $manActivity, $potBoiling)
        );

        return $world;
    }

    // ------------------------------------------------------------------
    // Shaking flag across the offset table (Req 3.2).
    // ------------------------------------------------------------------

    /**
     * The house shakes only on offsets 1, 2, 3, 4 and 12; every other offset
     * in the 1–13 range leaves the shaking flag false (Req 3.2).
     */
    public function testShakingFlagMatchesOffsetTable(): void
    {
        $phase = new EarthquakePhase();
        $seed  = Seed::fromInt(42);

        $shakingOffsets    = [1, 2, 3, 4, 12];
        $notShakingOffsets = [5, 6, 7, 8, 9, 10, 11, 13];

        foreach ($shakingOffsets as $offset) {
            $world = $this->makeWorld('night', 'sitting_at_table');
            $phase->advanceTurn($world, $seed, $offset);
            $this->assertTrue(
                $world->houseState->shaking,
                "The house must be shaking at offset {$offset}"
            );
        }

        foreach ($notShakingOffsets as $offset) {
            $world = $this->makeWorld('night', 'sitting_at_table');
            $phase->advanceTurn($world, $seed, $offset);
            $this->assertFalse(
                $world->houseState->shaking,
                "The house must not be shaking at offset {$offset}"
            );
        }
    }

    // ------------------------------------------------------------------
    // Power outage set at offset 2 and never recovers (Req 3.3).
    // ------------------------------------------------------------------

    /**
     * The power fails at offset 2 and stays out through the end of the phase:
     * driving advanceTurn across offsets 2..13 never restores it (Req 3.3).
     */
    public function testPowerFailsAtOffsetTwoAndStaysOut(): void
    {
        $phase = new EarthquakePhase();
        $seed  = Seed::fromInt(7);
        $world = $this->makeWorld('night', 'sitting_at_table');

        $this->assertSame(
            PowerState::on,
            $world->houseState->power,
            'Power starts on before the outage offset'
        );

        for ($offset = 2; $offset <= 13; $offset++) {
            $phase->advanceTurn($world, $seed, $offset);
            $this->assertSame(
                PowerState::outage,
                $world->houseState->power,
                "Power must remain out at offset {$offset}"
            );
        }
    }

    // ------------------------------------------------------------------
    // Light levels when the power fails at offset 2 (Req 2.5, Req 3.3).
    // ------------------------------------------------------------------

    /**
     * At night, with no lit flashlight or candle, the kitchen goes dark the
     * moment the power fails at offset 2 (Req 2.5, Req 3.3).
     */
    public function testNonAfternoonRoomsGoDarkWhenPowerFails(): void
    {
        $phase = new EarthquakePhase();
        $seed  = Seed::fromInt(3);
        $world = $this->makeWorld('night', 'sitting_at_table');

        $this->assertSame(
            LightLevel::normal,
            $world->getRoom('kitchen')->lightLevel,
            'The kitchen is lit before the power fails'
        );

        $phase->advanceTurn($world, $seed, 2);

        $this->assertSame(
            LightLevel::dark,
            $world->getRoom('kitchen')->lightLevel,
            'A night kitchen with no lit light source goes dark at the outage'
        );
    }

    /**
     * In the afternoon, daylight keeps every room lit even after the power
     * fails: the kitchen stays normal at offset 2 (Req 2.5).
     */
    public function testAfternoonRoomsStayLitWhenPowerFails(): void
    {
        $phase = new EarthquakePhase();
        $seed  = Seed::fromInt(3);
        $world = $this->makeWorld('afternoon', 'sitting_at_table');

        $phase->advanceTurn($world, $seed, 2);

        $this->assertSame(
            LightLevel::normal,
            $world->getRoom('kitchen')->lightLevel,
            'Afternoon daylight keeps the kitchen lit despite the outage'
        );
    }

    // ------------------------------------------------------------------
    // Held lit light sources follow their holder (Req 2.4–2.5, Req 4.6).
    // ------------------------------------------------------------------

    /**
     * A lit flashlight held by a robot lights that robot's current room after
     * the power fails at night. The flashlight is parked on the robot id, yet
     * the room the robot stands in stays lit (Req 2.4–2.5, Req 4.6).
     */
    public function testHeldLitFlashlightLightsHoldersRoom(): void
    {
        $phase = new EarthquakePhase();
        $seed  = Seed::fromInt(3);
        $world = $this->makeWorld('night', 'sitting_at_table'); // robot_a in kitchen

        // robot_a holds a lit flashlight (parked on the robot, as `take` does).
        $flashlight = $world->objects['flashlight'];
        $flashlight->on = true;
        $flashlight->room = 'robot_a';
        $world->robotStates['robot_a']->heldObjectId = 'flashlight';

        $phase->advanceTurn($world, $seed, 2); // power fails

        $this->assertSame(
            LightLevel::normal,
            $world->getRoom('kitchen')->lightLevel,
            'The holder\'s room stays lit by the carried flashlight'
        );
    }

    /**
     * When the holder of a lit flashlight moves to an adjacent dark room, the
     * light must follow: the room left behind goes dark and the entered room
     * becomes lit. This drives the move through recomputeRoomLightLevel on both
     * rooms (Req 2.5).
     */
    public function testCarriedLitLightFollowsHolderBetweenRooms(): void
    {
        $phase = new EarthquakePhase();
        $seed  = Seed::fromInt(3);
        $world = $this->makeWorld('night', 'sitting_at_table'); // robot_a in kitchen

        $flashlight = $world->objects['flashlight'];
        $flashlight->on = true;
        $flashlight->room = 'robot_a';
        $world->robotStates['robot_a']->heldObjectId = 'flashlight';

        $phase->advanceTurn($world, $seed, 2); // power fails; kitchen lit, living_room dark

        $this->assertSame(LightLevel::normal, $world->getRoom('kitchen')->lightLevel);
        $this->assertSame(LightLevel::dark, $world->getRoom('living_room')->lightLevel);

        // The robot walks to the adjacent living room; recompute both rooms.
        $world->robotStates['robot_a']->room = 'living_room';
        EarthquakePhase::recomputeRoomLightLevel($world, 'kitchen');
        EarthquakePhase::recomputeRoomLightLevel($world, 'living_room');

        $this->assertSame(
            LightLevel::dark,
            $world->getRoom('kitchen')->lightLevel,
            'The room left behind goes dark once the carried light leaves'
        );
        $this->assertSame(
            LightLevel::normal,
            $world->getRoom('living_room')->lightLevel,
            'The entered room becomes lit as the carried light arrives'
        );
    }

    /**
     * A lit light handed to the resident (parked on room == 'resident') lights
     * the resident's current room (Req 2.5, Req 4.6).
     */
    public function testHeldLitLightLightsResidentsRoom(): void
    {
        $phase = new EarthquakePhase();
        $seed  = Seed::fromInt(3);
        // Resident sits in the living room; robots kept out of the bathroom.
        $world = $this->makeWorld('night', 'sitting_at_table');

        $this->assertSame('living_room', $world->residentState->room);

        $flashlight = $world->objects['flashlight'];
        $flashlight->on = true;
        $flashlight->room = 'resident';

        $phase->advanceTurn($world, $seed, 2); // power fails

        $this->assertSame(
            LightLevel::normal,
            $world->getRoom('living_room')->lightLevel,
            'A lit light held by the resident lights the resident\'s room'
        );
    }

    /**
     * An unlit held light does not light any room: with the flashlight off and
     * parked on the robot, the holder's room still goes dark at the outage
     * (Req 2.4–2.5).
     */
    public function testUnlitHeldLightDoesNotLightRoom(): void
    {
        $phase = new EarthquakePhase();
        $seed  = Seed::fromInt(3);
        $world = $this->makeWorld('night', 'sitting_at_table'); // robot_a in kitchen

        $flashlight = $world->objects['flashlight'];
        $flashlight->on = false;
        $flashlight->room = 'robot_a';
        $world->robotStates['robot_a']->heldObjectId = 'flashlight';

        $phase->advanceTurn($world, $seed, 2); // power fails

        $this->assertSame(
            LightLevel::dark,
            $world->getRoom('kitchen')->lightLevel,
            'An unlit carried light leaves the holder\'s room dark'
        );
    }

    // ------------------------------------------------------------------
    // Delayed stove hazard at offset 6 (Req 3.5).
    // ------------------------------------------------------------------

    /**
     * When the stove was lit as the earthquake began, driving offsets 1..6
     * lands the delayed kitchen hazard at offset 6: the flame goes out, the
     * pot falls, and the kitchen gains the hot-water injury hazard (Req 3.5).
     */
    public function testDelayedStoveHazardAppliedAtOffsetSix(): void
    {
        $phase = new EarthquakePhase();
        $seed  = Seed::fromInt(5);
        // Cooking lights the stove and places a pot on it.
        $world = $this->makeWorld('night', 'cooking');

        $this->assertTrue($world->objects['gas_stove']->on, 'Stove is lit before the quake');
        $this->assertArrayHasKey('pot', $world->objects, 'A pot is on the stove');

        // Arm the hazard at the moment the quake begins.
        $phase->captureStoveWasOn($world);
        $this->assertTrue($phase->stoveWasOn(), 'Hazard is armed while the stove was lit');

        for ($offset = 1; $offset <= 6; $offset++) {
            $phase->advanceTurn($world, $seed, $offset);
        }

        $this->assertFalse(
            $world->objects['gas_stove']->on,
            'The flame goes out when the delayed hazard lands'
        );
        $this->assertTrue(
            $world->objects['pot']->fallen,
            'The pot falls when the delayed hazard lands'
        );
        $this->assertTrue(
            $world->getRoom('kitchen')->hotWaterInjuryHazard,
            'The kitchen gains the hot-water injury hazard at offset 6'
        );
    }

    /**
     * Turning the stove off before offset 6 disarms the delayed hazard:
     * clearStoveWasOn() leaves the kitchen untouched through offset 6, with no
     * hazard and no fallen pot (Req 3.5).
     */
    public function testStoveTurnedOffBeforeOffsetSixCancelsHazard(): void
    {
        $phase = new EarthquakePhase();
        $seed  = Seed::fromInt(5);
        $world = $this->makeWorld('night', 'cooking');

        // Arm the hazard, then simulate the flame being turned off before 6.
        $phase->captureStoveWasOn($world);
        $phase->clearStoveWasOn();
        $this->assertFalse($phase->stoveWasOn(), 'Hazard is disarmed once the flame is off');

        for ($offset = 1; $offset <= 6; $offset++) {
            $phase->advanceTurn($world, $seed, $offset);
        }

        $this->assertFalse(
            $world->getRoom('kitchen')->hotWaterInjuryHazard,
            'A disarmed hazard never touches the kitchen'
        );
        $this->assertFalse(
            $world->objects['pot']->fallen,
            'A disarmed hazard never fells the pot'
        );
    }

    // ------------------------------------------------------------------
    // disasterSubsided at offset 13 (Req 3.1).
    // ------------------------------------------------------------------

    /**
     * The disaster has subsided by the final offset: driving through offset 13
     * sets disasterSubsided to true (Req 3.1).
     */
    public function testDisasterSubsidedAtOffsetThirteen(): void
    {
        $phase = new EarthquakePhase();
        $seed  = Seed::fromInt(9);
        $world = $this->makeWorld('night', 'sitting_at_table');

        $this->assertFalse($world->disasterSubsided, 'The disaster has not subsided at the start');

        for ($offset = 1; $offset <= 12; $offset++) {
            $phase->advanceTurn($world, $seed, $offset);
            $this->assertFalse(
                $world->disasterSubsided,
                "The disaster is still going at offset {$offset}"
            );
        }

        $phase->advanceTurn($world, $seed, 13);
        $this->assertTrue(
            $world->disasterSubsided,
            'The disaster has subsided by offset 13'
        );
    }

    // ------------------------------------------------------------------
    // Determinism of the fallen-object set for a fixed seed (Req 3.8).
    // ------------------------------------------------------------------

    /**
     * Two separate runs with the same seed and no robot actions produce the
     * identical set of fallen object ids at every offset (Req 3.8).
     */
    public function testSameSeedProducesIdenticalFallenObjectSets(): void
    {
        $seed = Seed::fromInt(12345);

        $runA = $this->fallenIdsPerOffset($seed);
        $runB = $this->fallenIdsPerOffset($seed);

        $this->assertSame(
            $runA,
            $runB,
            'The same seed must yield the same fallen-object set at every offset'
        );
    }

    /**
     * Drive a fresh world through offsets 1..13 with no actions and collect the
     * sorted set of fallen object ids for each offset.
     *
     * @return array<int,string[]> offset => sorted fallen object ids
     */
    private function fallenIdsPerOffset(Seed $seed): array
    {
        $phase = new EarthquakePhase();
        $world = $this->makeWorld('night', 'sitting_at_table');

        $perOffset = [];
        for ($offset = 1; $offset <= 13; $offset++) {
            $phase->advanceTurn($world, $seed, $offset);

            $ids = [];
            foreach ($world->objects as $object) {
                if ($object->fallen) {
                    $ids[] = $object->id;
                }
            }
            sort($ids);
            $perOffset[$offset] = $ids;
        }

        return $perOffset;
    }
}
