<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\Enums\Disaster;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\ResidentController;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\SituationSpec;
use PoltergeistSwitch\Engine\WorldModel;

/**
 * Example tests for the resident's heat body rules (task 5.2),
 * ResidentController::applyHeatToResident.
 *
 * Covers:
 *   - a hot room (room temperature above bodyHeatThreshold) raises body_temp
 *     by bodyTempRisePerTurn and lowers water by waterLossPerTurnHot (Req 3.1);
 *   - the electric fan on in the resident's own room reduces the rise by
 *     fanBodyRiseReduction (Req 3.2);
 *   - a cool room (at or below bodyHeatThreshold) recovers body_temp toward the
 *     starting floor by bodyTempRecoveryPerTurn, never below residentInitBodyTemp
 *     (Req 3.4).
 *
 * The cold-water hand-over (Req 3.3) is tested with the heat objects (task 6).
 * applyHeatToResident reads the resident's room temperature directly, so these
 * tests set it on the room and call the controller — no Engine or network.
 *
 * Requirements: 3.1, 3.2, 3.4
 */
class ResidentHeatTest extends TestCase
{
    private const EPSILON = 1.0e-9;

    /**
     * A heat-wave world with the resident sitting at the low table in the
     * living room. Tests set the living-room temperature and fan state to
     * build each scenario.
     */
    private function makeHeatWorld(?EpisodeConfig $config = null): WorldModel
    {
        $config ??= new EpisodeConfig();

        $world = new WorldModel($config);
        $world->reset(
            Seed::fromInt(1),
            new SituationSpec(
                time: 'afternoon',
                robotARoom: 'living_room',
                robotBRoom: 'bedroom',
                manActivity: 'sitting_at_table',
                potBoiling: false,
            ),
            Disaster::heat_wave
        );

        return $world;
    }

    // ------------------------------------------------------------------
    // Hot room: body rise + water loss (Req 3.1).
    // ------------------------------------------------------------------

    /**
     * When the resident's room is above bodyHeatThreshold, body_temp rises by
     * bodyTempRisePerTurn and water falls by waterLossPerTurnHot (Req 3.1).
     */
    public function testHotRoomRaisesBodyTempAndLowersWater(): void
    {
        $config = new EpisodeConfig();
        $world  = $this->makeHeatWorld($config);

        // Living room (the resident's room) above the threshold; the fan is off.
        $world->getRoom('living_room')->temperature = $config->bodyHeatThreshold + 2.0;

        $bodyBefore  = $world->residentState->bodyTemp;
        $waterBefore = $world->residentState->water;

        (new ResidentController())->applyHeatToResident($world, $config);

        $this->assertEqualsWithDelta(
            $bodyBefore + $config->bodyTempRisePerTurn,
            $world->residentState->bodyTemp,
            self::EPSILON,
            'body_temp must rise by bodyTempRisePerTurn in a hot room'
        );
        $this->assertSame(
            $waterBefore - $config->waterLossPerTurnHot,
            $world->residentState->water,
            'water must fall by waterLossPerTurnHot in a hot room'
        );
    }

    // ------------------------------------------------------------------
    // Fan reduces the rise (Req 3.2).
    // ------------------------------------------------------------------

    /**
     * With the electric fan on in the resident's own room, the per-turn rise is
     * reduced by fanBodyRiseReduction (Req 3.2). Compared against the same hot
     * room with the fan off, the fan turn rises strictly less.
     */
    public function testFanInResidentRoomReducesTheRise(): void
    {
        $config = new EpisodeConfig();

        // Baseline: hot room, fan off.
        $noFan = $this->makeHeatWorld($config);
        $noFan->getRoom('living_room')->temperature = $config->bodyHeatThreshold + 2.0;
        $bodyBefore = $noFan->residentState->bodyTemp;
        (new ResidentController())->applyHeatToResident($noFan, $config);
        $riseNoFan = $noFan->residentState->bodyTemp - $bodyBefore;

        // With the fan on in the resident's room (living room).
        $withFan = $this->makeHeatWorld($config);
        $withFan->getRoom('living_room')->temperature = $config->bodyHeatThreshold + 2.0;
        $withFan->objects['electric_fan']->room = 'living_room';
        $withFan->objects['electric_fan']->on   = true;
        (new ResidentController())->applyHeatToResident($withFan, $config);
        $riseWithFan = $withFan->residentState->bodyTemp - $bodyBefore;

        $this->assertEqualsWithDelta(
            $config->bodyTempRisePerTurn - $config->fanBodyRiseReduction,
            $riseWithFan,
            self::EPSILON,
            'the fan must reduce the rise by fanBodyRiseReduction'
        );
        $this->assertLessThan(
            $riseNoFan,
            $riseWithFan,
            'the fan turn must rise strictly less than the no-fan turn'
        );
    }

    /**
     * The fan only helps in the resident's own room: a fan on in another room
     * leaves the full rise (Req 3.2).
     */
    public function testFanInAnotherRoomDoesNotReduceTheRise(): void
    {
        $config = new EpisodeConfig();
        $world  = $this->makeHeatWorld($config);

        $world->getRoom('living_room')->temperature = $config->bodyHeatThreshold + 2.0;
        // Fan on, but in the bedroom (not the resident's room).
        $world->objects['electric_fan']->room = 'bedroom';
        $world->objects['electric_fan']->on   = true;

        $bodyBefore = $world->residentState->bodyTemp;

        (new ResidentController())->applyHeatToResident($world, $config);

        $this->assertEqualsWithDelta(
            $bodyBefore + $config->bodyTempRisePerTurn,
            $world->residentState->bodyTemp,
            self::EPSILON,
            'a fan in another room must not reduce the rise'
        );
    }

    // ------------------------------------------------------------------
    // Cool room: recovery toward the starting floor (Req 3.4).
    // ------------------------------------------------------------------

    /**
     * When the resident's room is at or below bodyHeatThreshold, body_temp
     * recovers toward the starting body temperature by bodyTempRecoveryPerTurn
     * (Req 3.4).
     */
    public function testCoolRoomRecoversBodyTempTowardTheStartingFloor(): void
    {
        $config = new EpisodeConfig();
        $world  = $this->makeHeatWorld($config);

        // Room at the threshold (not above it), so the cool branch runs.
        $world->getRoom('living_room')->temperature = $config->bodyHeatThreshold;
        // Raise body temperature well above the floor so recovery is visible
        // and cannot be clamped away.
        $world->residentState->bodyTemp = $config->residentInitBodyTemp + 1.5;

        $bodyBefore  = $world->residentState->bodyTemp;
        $waterBefore = $world->residentState->water;

        (new ResidentController())->applyHeatToResident($world, $config);

        $this->assertEqualsWithDelta(
            $bodyBefore - $config->bodyTempRecoveryPerTurn,
            $world->residentState->bodyTemp,
            self::EPSILON,
            'body_temp must recover by bodyTempRecoveryPerTurn in a cool room'
        );
        $this->assertSame(
            $waterBefore,
            $world->residentState->water,
            'water must not change on a cool turn (only a hand-over raises it)'
        );
    }

    /**
     * Recovery never drops body_temp below the resident's starting body
     * temperature (Req 3.4): a resident already at the floor stays there.
     */
    public function testCoolRoomNeverRecoversBelowTheStartingFloor(): void
    {
        $config = new EpisodeConfig();
        $world  = $this->makeHeatWorld($config);

        $world->getRoom('living_room')->temperature = $config->bodyHeatThreshold - 5.0;
        // Only a hair above the floor — less than one recovery step away.
        $world->residentState->bodyTemp =
            $config->residentInitBodyTemp + ($config->bodyTempRecoveryPerTurn / 2.0);

        (new ResidentController())->applyHeatToResident($world, $config);

        $this->assertEqualsWithDelta(
            $config->residentInitBodyTemp,
            $world->residentState->bodyTemp,
            self::EPSILON,
            'recovery must not drop body_temp below residentInitBodyTemp'
        );
    }
}
