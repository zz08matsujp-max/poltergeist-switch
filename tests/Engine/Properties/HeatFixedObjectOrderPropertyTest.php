<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\ActionExecutor;
use PoltergeistSwitch\Engine\EarthquakePhase;
use PoltergeistSwitch\Engine\Engine;
use PoltergeistSwitch\Engine\Enums\Disaster;
use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\HeatWavePhase;
use PoltergeistSwitch\Engine\ResidentController;
use PoltergeistSwitch\Engine\RulesEvaluator;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\WorldModel;

/**
 * Property-based test for heat-wave spec Requirements 4.4, 4.5, 6.6 (Eris).
 *
 * Tag: Feature: heat-wave, Property 8: Objects keep their fixed listed order
 * in a heat wave.
 *
 * Property 8 — Objects keep their fixed listed order in a heat wave.
 *
 * In a heat-wave episode the objects a robot sees in a room are listed in the
 * same fixed order as the earthquake (Requirement 4.5), the cold-water bottle
 * is never added to any room's listed-object order even though a robot can
 * draw it from the refrigerator (Requirement 4.4), and the thermometer and the
 * window stay fixtures that are never listed (Requirement 6.6). This property
 * drives the full Engine over a heat-wave episode with randomized actions that
 * touch every heat target — opening and closing windows, drawing the bottle
 * from the refrigerator, taking and running the fan, turning the air
 * conditioner on, handing over — then, after every turn, asserts that each
 * room's `visibleObjectPhrases` is a subsequence of that room's canonical
 * fixed order (nothing reordered, nothing inserted), and that the cold-water
 * bottle, the window, and the thermometer never appear in any room's list.
 *
 * Drawing the bottle removes nothing from a room (it is created straight onto
 * the robot's held slot), and the heat objects that CAN move (the electric fan
 * carried between rooms) only ever drop out of or re-appear in a room; they
 * never reorder the fixed sequence. The subsequence check captures both: a
 * room's listed objects are always the canonical order with zero or more
 * entries missing, never a reordering and never an extra entry.
 *
 * The Engine is driven directly (no network, no fakes) the way the other
 * engine property tests run it. This Eris release (giorgiosironi/eris ^1.3)
 * sets the iteration count with `limitTo()`; `limitTo(100)` makes the "at
 * least 100 cases" explicit.
 *
 * Validates: Requirements 4.4, 4.5, 6.6
 */
class HeatFixedObjectOrderPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * The canonical fixed object order per room (heat-rules Req 1.6 /
     * heat-wave Req 4.5), as display phrases. This is the registration order
     * of WorldModel::initObjects(); a room's listed objects must always be a
     * subsequence of its row here.
     *
     * @var array<string,string[]>
     */
    private const CANONICAL_ORDER = [
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
            // The boiling pot is placed on the stove only when the drawn
            // situation has the resident cooking (potBoiling=true). It is
            // registered last, so it sits at the end of the kitchen order.
            'a pot',
        ],
        'bathroom' => [
            'a gas bath heater',
            'a bathtub',
        ],
        'bedroom' => [
            'a futon',
            'a closet',
            'an electric fan',
        ],
        'entrance' => [
            'rain shutters',
            'a shoe cabinet',
        ],
        'storeroom' => [
            'candles',
            'matches',
            'a toolbox',
        ],
    ];

    /** Phrases that must never appear in any room's listed-object order. */
    private const NEVER_LISTED = [
        'a bottle of cold barley tea',  // the cold-water bottle (Req 4.4)
        'window',                         // the window fixture (Req 6.6)
        'thermometer',                    // the thermometer fixture (Req 6.6)
    ];

    private const ROOM_KEYS = [
        'living_room',
        'kitchen',
        'bathroom',
        'bedroom',
        'entrance',
        'storeroom',
    ];

    /**
     * Property 8: objects keep their fixed listed order in a heat wave.
     *
     * Validates: Requirements 4.4, 4.5, 6.6
     */
    public function testObjectsKeepTheirFixedOrderInAHeatWave(): void
    {
        $config = new EpisodeConfig();

        $this->limitTo(100)
            ->forAll(
                Generators::int(),            // world seed
                Generators::choose(1, 20),    // number of turns to run
            )
            ->then(function (int $seedValue, int $turns) use ($config): void {
                $world = new WorldModel($config);
                $world->reset(Seed::fromInt($seedValue), null, Disaster::heat_wave);

                $this->assertTrue(
                    $world->disasterIs(Disaster::heat_wave),
                    "The world under test must be a heat wave (seed {$seedValue})"
                );

                $engine = new Engine(
                    $world,
                    $config,
                    new RulesEvaluator(),
                    new ActionExecutor(),
                    new EarthquakePhase(),
                    new ResidentController(),
                    new SituationTextGenerator(),
                    new HeatWavePhase(),
                );

                // Check the canonical order holds from turn 0, before any action.
                $this->assertFixedOrder($world, $seedValue, 0);

                for ($turn = 0; $turn < $turns; $turn++) {
                    $result = $engine->applyTurn($turn, $this->turnActions($seedValue, $turn));
                    if ($result->isError) {
                        break;
                    }

                    $this->assertFixedOrder($world, $seedValue, $turn);

                    if ($result->episodeEnded) {
                        break;
                    }
                }
            });
    }

    /**
     * Assert every room's listed objects are a subsequence of that room's
     * canonical fixed order, and that the bottle, the window, and the
     * thermometer never appear in any room.
     */
    private function assertFixedOrder(WorldModel $world, int $seedValue, int $turn): void
    {
        foreach (self::ROOM_KEYS as $roomKey) {
            $listed = $world->visibleObjectPhrases($roomKey);

            foreach (self::NEVER_LISTED as $banned) {
                $this->assertNotContains(
                    $banned,
                    $listed,
                    "'{$banned}' must never be listed in {$roomKey} "
                        . "(seed {$seedValue}, turn {$turn})"
                );
            }

            $this->assertTrue(
                $this->isSubsequence($listed, self::CANONICAL_ORDER[$roomKey]),
                "Objects in {$roomKey} must keep their fixed order "
                    . "(seed {$seedValue}, turn {$turn}); got: "
                    . implode(', ', $listed)
            );
        }
    }

    /**
     * Whether $candidate is a (not necessarily contiguous) subsequence of
     * $full: every element of $candidate appears in $full in the same relative
     * order, with nothing reordered and nothing present that is not in $full.
     *
     * @param string[] $candidate
     * @param string[] $full
     */
    private function isSubsequence(array $candidate, array $full): bool
    {
        $i = 0;
        foreach ($candidate as $phrase) {
            $found = false;
            while ($i < count($full)) {
                if ($full[$i] === $phrase) {
                    $found = true;
                    $i++;
                    break;
                }
                $i++;
            }
            if (!$found) {
                return false;
            }
        }

        return true;
    }

    /**
     * A fixed per-turn action pair that touches every heat target (window,
     * refrigerator/cold water, fan take+run, air conditioner, hand-over), a
     * pure function of seed and turn. Actions are issued regardless of whether
     * they are judged possible, so the object registry is exercised broadly.
     *
     * @return array<string,Action>
     */
    private function turnActions(int $seedValue, int $turn): array
    {
        return [
            'robot_a' => $this->turnAction('robot_a', $seedValue, $turn),
            'robot_b' => $this->turnAction('robot_b', $seedValue, $turn),
        ];
    }

    private function turnAction(string $robotId, int $seedValue, int $turn): Action
    {
        $offset = $robotId === 'robot_a' ? 0 : 4;
        $i = ($turn + $offset + (($seedValue % 8 + 8) % 8)) % 8;

        switch ($i) {
            case 0:
                return new Action($robotId, Operation::use->value, targetId: 'window');
            case 1:
                return new Action($robotId, Operation::use->value, targetId: 'window_air_conditioner');
            case 2:
                return new Action($robotId, Operation::take->value, targetId: 'electric_fan');
            case 3:
                return new Action($robotId, Operation::use->value, targetId: 'electric_fan');
            case 4:
                return new Action($robotId, Operation::take->value, targetId: 'refrigerator');
            case 5:
                return new Action($robotId, Operation::use->value, targetId: 'refrigerator');
            case 6:
                return new Action($robotId, Operation::hand_over->value, targetId: 'resident');
            default:
                return new Action($robotId, Operation::move->value, targetId: 'living_room');
        }
    }
}
