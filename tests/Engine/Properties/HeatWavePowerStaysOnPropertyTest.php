<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\Enums\Disaster;
use PoltergeistSwitch\Engine\Enums\PowerState;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\HeatWavePhase;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\WorldModel;

/**
 * Property-based test for heat-wave spec Requirement 2.5 (Eris).
 *
 * Property 3 — Power never fails in a heat wave.
 *
 * Requirement 2.5 says that while an episode's disaster is heat wave the
 * engine keeps the power on and applies no outage rule. HeatWavePhase is the
 * component that advances a heat-wave turn (room drift, AC cooling, the
 * open-window bonus, the clamp); none of those steps touches the power, and
 * the phase runs no outage rule. This property pins that promise across
 * randomized heat-wave turn sequences: whatever the room temperatures, the
 * air-conditioner on/off state, the per-room open/closed windows, and however
 * many turns are advanced, the house power is `PowerState::on` on every turn.
 *
 * Each case builds a fresh heat-wave world (reset with Disaster::heat_wave),
 * randomizes the starting conditions — every room's temperature across the
 * configured clamp range and a little beyond it, the air conditioner on or
 * off, and each room's window open or closed — then advances the world
 * through a random number of turns via HeatWavePhase::advanceTurn, asserting
 * after reset and after every single turn that houseState->power is on. The
 * power starting on is checked too, so a regression that set it to outage at
 * heat-wave reset would be caught as well.
 *
 * The phase is exercised directly (no Engine, no network, no fakes), the way
 * the other engine property tests drive a single pure component. This Eris
 * release (giorgiosironi/eris ^1.3) sets the iteration count with `limitTo()`;
 * `limitTo(100)` makes the "at least 100 cases" explicit.
 *
 * Validates: Requirements 2.5
 */
class HeatWavePowerStaysOnPropertyTest extends TestCase
{
    use TestTrait;

    /** The six rooms of the house, in fixed order, for the per-room generators. */
    private const ROOM_KEYS = [
        'living_room',
        'kitchen',
        'bathroom',
        'bedroom',
        'entrance',
        'storeroom',
    ];

    /**
     * Property 3: power never fails in a heat wave.
     *
     * Validates: Requirements 2.5
     */
    public function testPowerStaysOnAcrossHeatWaveTurns(): void
    {
        $config = new EpisodeConfig();

        // Room temperatures are drawn a little outside the clamp range too, so
        // the drift/AC/clamp code runs over its whole domain while the power
        // stays pinned on.
        $tempLow = (int) floor($config->roomTempMin) - 5;   // below the clamp floor
        $tempHigh = (int) ceil($config->roomTempMax) + 5;    // above the clamp ceiling

        $this->limitTo(100)
            ->forAll(
                Generators::int(),                                   // world seed
                Generators::choose(1, 25),                            // number of turns to advance
                Generators::bool(),                                   // air conditioner on/off
                // One temperature per room (whole °C across and beyond the range).
                Generators::tuple(...array_fill(0, count(self::ROOM_KEYS), Generators::choose($tempLow, $tempHigh))),
                // One open/closed window flag per room.
                Generators::tuple(...array_fill(0, count(self::ROOM_KEYS), Generators::bool())),
            )
            ->then(function (
                int $seedValue,
                int $turns,
                bool $acOn,
                array $roomTemps,
                array $windowFlags
            ) use ($config): void {
                $world = new WorldModel($config);
                $world->reset(Seed::fromInt($seedValue), null, Disaster::heat_wave);

                // Sanity: this is genuinely a heat-wave world, so the heat
                // branch under test is the one that runs.
                $this->assertTrue(
                    $world->disasterIs(Disaster::heat_wave),
                    "The world under test must be a heat wave (seed {$seedValue})"
                );

                // Randomize the starting conditions the phase reads.
                foreach (self::ROOM_KEYS as $i => $roomKey) {
                    $room = $world->getRoom($roomKey);
                    $room->temperature = (float) $roomTemps[$i];
                    $room->windowOpen = (bool) $windowFlags[$i];
                }
                $world->objects['window_air_conditioner']->on = $acOn;

                // The power must start on at heat-wave reset (Req 2.5).
                $this->assertSame(
                    PowerState::on,
                    $world->houseState->power,
                    "Power must start on in a heat wave (seed {$seedValue})"
                );

                $phase = new HeatWavePhase();

                for ($turn = 1; $turn <= $turns; $turn++) {
                    $phase->advanceTurn($world, $world->seed, $turn);

                    // No outage rule ran: the power is still on after the turn
                    // (Req 2.5).
                    $this->assertSame(
                        PowerState::on,
                        $world->houseState->power,
                        "Power must stay on in a heat wave "
                            . "(seed {$seedValue}, turn {$turn} of {$turns})"
                    );
                }
            });
    }
}
