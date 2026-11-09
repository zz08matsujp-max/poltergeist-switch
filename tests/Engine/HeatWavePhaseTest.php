<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\Enums\Disaster;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\HeatWavePhase;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\SituationSpec;
use PoltergeistSwitch\Engine\WorldModel;

/**
 * Example tests for the heat-wave room-temperature rules (task 4.2).
 *
 * Covers the heat start state set in WorldModel::reset() and the per-turn
 * room-temperature rule driven by HeatWavePhase::advanceTurn():
 *   - heat setup: every room starts at heatRoomInitialTemp and the outside
 *     temperature is EpisodeConfig::outsideTemperature (Req 2.1);
 *   - directional drift toward outside by roomTempDriftPerTurn, capped so it
 *     never overshoots (Req 2.2);
 *   - AC cooling one turn, floored at acTargetTemp (Req 2.3);
 *   - the open-window drift bonus added to the step toward outside (Req 2.4);
 *   - the clamp to [roomTempMin, roomTempMax] (Req 2.6).
 *
 * The phase is pure and RNG-free; a fixed Seed is passed only for symmetry
 * with EarthquakePhase.
 *
 * Requirements: 2.1, 2.2, 2.3, 2.4, 2.6
 */
class HeatWavePhaseTest extends TestCase
{
    private const EPSILON = 1.0e-9;

    private Seed $seed;

    protected function setUp(): void
    {
        $this->seed = Seed::fromInt(1);
    }

    /**
     * A heat-wave world reset with an explicit afternoon SituationSpec. Robots
     * are kept out of the bathroom so any manActivity is valid; the resident
     * sits at the low table, which is the heat-wave starting pose.
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
    // Heat setup (Req 2.1).
    // ------------------------------------------------------------------

    /**
     * A heat-wave episode starts every room at heatRoomInitialTemp and records
     * the outside temperature from EpisodeConfig (Req 2.1).
     */
    public function testHeatSetupInitialisesEveryRoomAndOutsideTemperature(): void
    {
        $config = new EpisodeConfig();
        $world  = $this->makeHeatWorld($config);

        $this->assertSame(Disaster::heat_wave, $world->disaster);
        $this->assertSame(
            $config->outsideTemperature,
            $world->houseState->outsideTemperature,
            'The outside temperature must come from EpisodeConfig'
        );

        foreach ($world->rooms as $room) {
            $this->assertSame(
                $config->heatRoomInitialTemp,
                $room->temperature,
                "Room {$room->key} must start at heatRoomInitialTemp"
            );
        }
    }

    /**
     * An earthquake episode never enters the heat start-state path: every room
     * keeps the fixed 22.0 °C and the outside temperature stays at its "not a
     * heat wave" default of 0.0 (Req 2.1, protecting the earthquake).
     */
    public function testEarthquakeKeepsExistingRoomTemperatureAndNoOutside(): void
    {
        $world = new WorldModel();
        $world->reset(
            Seed::fromInt(1),
            new SituationSpec('afternoon', 'living_room', 'bedroom', 'sitting_at_table', false)
        );

        $this->assertSame(Disaster::earthquake, $world->disaster);
        $this->assertSame(0.0, $world->houseState->outsideTemperature);
        foreach ($world->rooms as $room) {
            $this->assertSame(22.0, $room->temperature);
        }
    }

    // ------------------------------------------------------------------
    // Directional drift toward outside, capped (Req 2.2).
    // ------------------------------------------------------------------

    /**
     * A room cooler than outside warms toward it by roomTempDriftPerTurn, and
     * a room warmer than outside cools toward it by the same step — the drift
     * is directional (Req 2.2). The air conditioner is off and no window is
     * open, so the only movement is the plain drift.
     */
    public function testRoomsDriftTowardOutsideByTheConfiguredStep(): void
    {
        $config = new EpisodeConfig();
        $world  = $this->makeHeatWorld($config);
        $phase  = new HeatWavePhase();

        // Start the bedroom below and the entrance above the outside temp.
        $world->rooms['bedroom']->temperature  = 30.0;  // cooler than 36.0 outside
        $world->rooms['entrance']->temperature = 40.0;   // warmer than 36.0 outside

        $phase->advanceTurn($world, $this->seed, 1);

        $this->assertEqualsWithDelta(
            30.0 + $config->roomTempDriftPerTurn,
            $world->rooms['bedroom']->temperature,
            self::EPSILON,
            'A cooler room warms toward outside by the drift step'
        );
        $this->assertEqualsWithDelta(
            40.0 - $config->roomTempDriftPerTurn,
            $world->rooms['entrance']->temperature,
            self::EPSILON,
            'A warmer room cools toward outside by the drift step'
        );
    }

    /**
     * The drift never overshoots the outside temperature: a room within one
     * step of outside lands exactly on outside, not past it (Req 2.2).
     */
    public function testDriftIsCappedAndNeverOvershootsOutside(): void
    {
        $config = new EpisodeConfig();
        $world  = $this->makeHeatWorld($config);
        $phase  = new HeatWavePhase();

        $outside = $config->outsideTemperature;

        // Less than one drift step away from outside, from below and above.
        $world->rooms['bedroom']->temperature  = $outside - ($config->roomTempDriftPerTurn / 2.0);
        $world->rooms['entrance']->temperature = $outside + ($config->roomTempDriftPerTurn / 2.0);

        $phase->advanceTurn($world, $this->seed, 1);

        $this->assertEqualsWithDelta(
            $outside,
            $world->rooms['bedroom']->temperature,
            self::EPSILON,
            'A room just below outside lands exactly on outside, not past it'
        );
        $this->assertEqualsWithDelta(
            $outside,
            $world->rooms['entrance']->temperature,
            self::EPSILON,
            'A room just above outside lands exactly on outside, not past it'
        );
    }

    // ------------------------------------------------------------------
    // AC cooling one turn, floored at acTargetTemp (Req 2.3).
    // ------------------------------------------------------------------

    /**
     * With the air conditioner on, the living room is lowered by acCoolPerTurn
     * (applied after the drift) but never below acTargetTemp (Req 2.3). Here
     * the living room starts above outside so the drift already cools it, and
     * the AC removes a further acCoolPerTurn.
     */
    public function testAirConditionerCoolsLivingRoomByOneStep(): void
    {
        $config = new EpisodeConfig();
        $world  = $this->makeHeatWorld($config);
        $phase  = new HeatWavePhase();

        // Living room warmer than outside and well above the AC target so no
        // floor or overshoot interferes; the AC is on.
        $world->rooms['living_room']->temperature = 40.0;
        $world->objects['window_air_conditioner']->on = true;

        $phase->advanceTurn($world, $this->seed, 1);

        // drift toward 36.0: 40.0 - roomTempDriftPerTurn, then -acCoolPerTurn.
        $expected = 40.0 - $config->roomTempDriftPerTurn - $config->acCoolPerTurn;

        $this->assertEqualsWithDelta(
            $expected,
            $world->rooms['living_room']->temperature,
            self::EPSILON,
            'The AC removes acCoolPerTurn from the living room after the drift'
        );
    }

    /**
     * The air conditioner never cools the living room below acTargetTemp: a
     * living room already at the target stays at the target even with the AC
     * on (Req 2.3).
     */
    public function testAirConditionerNeverCoolsBelowTargetTemp(): void
    {
        $config = new EpisodeConfig();
        $world  = $this->makeHeatWorld($config);
        $phase  = new HeatWavePhase();

        // Sit the living room exactly on the AC target. The drift warms it
        // toward outside (36.0) by one step; the AC then pulls it down but
        // must not go below the target.
        $world->rooms['living_room']->temperature = $config->acTargetTemp;
        $world->objects['window_air_conditioner']->on = true;

        $phase->advanceTurn($world, $this->seed, 1);

        $this->assertEqualsWithDelta(
            $config->acTargetTemp,
            $world->rooms['living_room']->temperature,
            self::EPSILON,
            'The AC floors the living room at acTargetTemp'
        );
    }

    /**
     * With the air conditioner off the living room only drifts — no cooling is
     * applied — so the AC branch is gated on the switch being on (Req 2.3).
     */
    public function testAirConditionerOffAppliesNoCooling(): void
    {
        $config = new EpisodeConfig();
        $world  = $this->makeHeatWorld($config);
        $phase  = new HeatWavePhase();

        $world->rooms['living_room']->temperature = 40.0;
        $this->assertFalse($world->objects['window_air_conditioner']->on);

        $phase->advanceTurn($world, $this->seed, 1);

        $this->assertEqualsWithDelta(
            40.0 - $config->roomTempDriftPerTurn,
            $world->rooms['living_room']->temperature,
            self::EPSILON,
            'With the AC off the living room only drifts toward outside'
        );
    }

    // ------------------------------------------------------------------
    // Open-window drift bonus (Req 2.4).
    // ------------------------------------------------------------------

    /**
     * An open window adds openWindowDriftBonus to the step toward outside, so
     * an open room moves farther in one turn than a closed room with the same
     * starting temperature (Req 2.4).
     */
    public function testOpenWindowAddsDriftBonusToTheStep(): void
    {
        $config = new EpisodeConfig();
        $world  = $this->makeHeatWorld($config);
        $phase  = new HeatWavePhase();

        // Two rooms equally below outside: the bedroom window open, the
        // entrance window closed.
        $start = 30.0;
        $world->rooms['bedroom']->temperature  = $start;
        $world->rooms['entrance']->temperature = $start;
        $world->rooms['bedroom']->windowOpen   = true;
        $world->rooms['entrance']->windowOpen  = false;

        $phase->advanceTurn($world, $this->seed, 1);

        $this->assertEqualsWithDelta(
            $start + $config->roomTempDriftPerTurn + $config->openWindowDriftBonus,
            $world->rooms['bedroom']->temperature,
            self::EPSILON,
            'An open window adds the bonus to the step toward outside'
        );
        $this->assertEqualsWithDelta(
            $start + $config->roomTempDriftPerTurn,
            $world->rooms['entrance']->temperature,
            self::EPSILON,
            'A closed room moves by the plain drift step only'
        );
    }

    // ------------------------------------------------------------------
    // Clamp to [roomTempMin, roomTempMax] (Req 2.6).
    // ------------------------------------------------------------------

    /**
     * A room temperature below roomTempMin is clamped up to the floor, and a
     * room above roomTempMax is clamped down to the ceiling, after the update
     * (Req 2.6). A high outside temperature and config overrides force the
     * updated value outside the range so the clamp is observable.
     */
    public function testRoomTemperaturesAreClampedToConfiguredRange(): void
    {
        // A wide drift and a high outside temperature push a hot room past the
        // ceiling in one turn; a far-below-floor room past the floor.
        $config = new EpisodeConfig(
            outsideTemperature: 60.0,
            roomTempDriftPerTurn: 20.0,
            roomTempMin: 18.0,
            roomTempMax: 45.0,
        );
        $world = $this->makeHeatWorld($config);
        $phase = new HeatWavePhase();

        // Already above the ceiling: drift warms it further toward 60.0, then
        // the clamp pulls it back to the max.
        $world->rooms['entrance']->temperature = 44.0;

        $phase->advanceTurn($world, $this->seed, 1);

        $this->assertSame(
            $config->roomTempMax,
            $world->rooms['entrance']->temperature,
            'A room above the ceiling is clamped down to roomTempMax'
        );

        // Now drive a room well below the floor toward a cold outside and check
        // the lower clamp. A fresh world keeps the test independent.
        $coldConfig = new EpisodeConfig(
            outsideTemperature: 5.0,
            roomTempDriftPerTurn: 20.0,
            roomTempMin: 18.0,
            roomTempMax: 45.0,
        );
        $coldWorld = $this->makeHeatWorld($coldConfig);
        $coldWorld->rooms['entrance']->temperature = 19.0; // just above the floor

        $phase->advanceTurn($coldWorld, $this->seed, 1);

        $this->assertSame(
            $coldConfig->roomTempMin,
            $coldWorld->rooms['entrance']->temperature,
            'A room driven below the floor is clamped up to roomTempMin'
        );
    }
}
