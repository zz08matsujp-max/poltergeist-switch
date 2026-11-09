<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\ActionExecutor;
use PoltergeistSwitch\Engine\Enums\Disaster;
use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\RulesEvaluator;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\WorldModel;

/**
 * Property-based test for heat-wave spec Requirement 4.2 (Eris).
 *
 * Tag: Feature: heat-wave, Property 10: The air conditioner works only in the
 * living room.
 *
 * Property 10 — The air conditioner works only in the living room.
 *
 * In a heat-wave episode a `use` on the window air conditioner is possible and
 * turns it on WHEN AND ONLY WHEN the air conditioner is in the living room
 * (Requirement 4.1), and a `use` on it from any other room is `possible=false`
 * with a reason and leaves the world unchanged (Requirement 4.2). This
 * property pins that biconditional across randomized worlds: for any seed, any
 * robot room, and the air conditioner placed in any of the six rooms, after
 * evaluating and executing a `use` on the air conditioner:
 *   - if the air conditioner is in the living room AND the robot is there too,
 *     the use is possible and the air conditioner ends on;
 *   - if the air conditioner is in any other room, the use is impossible with
 *     the reason `ac_only_in_living_room` and the air conditioner stays off
 *     (the world is unchanged).
 *
 * The evaluator and executor are driven directly in the Engine's
 * evaluate→execute order (no network, no fakes), so the evaluator's verdict
 * actually gates the executor. This Eris release (giorgiosironi/eris ^1.3)
 * sets the iteration count with `limitTo()`; `limitTo(100)` makes the "at
 * least 100 cases" explicit.
 *
 * Validates: Requirements 4.2
 */
class AcOnlyInLivingRoomPropertyTest extends TestCase
{
    use TestTrait;

    /** The six rooms of the house, in fixed order. */
    private const ROOM_KEYS = [
        'living_room',
        'kitchen',
        'bathroom',
        'bedroom',
        'entrance',
        'storeroom',
    ];

    private const LIVING_ROOM = 'living_room';

    /**
     * Property 10: the air conditioner works only in the living room.
     *
     * Validates: Requirements 4.2
     */
    public function testAirConditionerWorksOnlyInTheLivingRoom(): void
    {
        $config = new EpisodeConfig();
        $evaluator = new RulesEvaluator();
        $executor = new ActionExecutor();
        $roomCount = count(self::ROOM_KEYS);

        $this->limitTo(100)
            ->forAll(
                Generators::int(),                       // world seed
                Generators::choose(0, $roomCount - 1),    // room the AC is placed in
                Generators::choose(0, $roomCount - 1),    // room robot_a stands in
            )
            ->then(function (int $seedValue, int $acRoomIndex, int $robotRoomIndex) use (
                $config,
                $evaluator,
                $executor
            ): void {
                $acRoom = self::ROOM_KEYS[$acRoomIndex];
                $robotRoom = self::ROOM_KEYS[$robotRoomIndex];

                $world = new WorldModel($config);
                $world->reset(Seed::fromInt($seedValue), null, Disaster::heat_wave);

                $this->assertTrue(
                    $world->disasterIs(Disaster::heat_wave),
                    "The world under test must be a heat wave (seed {$seedValue})"
                );

                // Place the air conditioner and the robot as the case asks.
                $world->objects['window_air_conditioner']->room = $acRoom;
                $world->objects['window_air_conditioner']->on = false;
                $world->robotStates['robot_a']->room = $robotRoom;

                $action = new Action(
                    robotId: 'robot_a',
                    operation: Operation::use->value,
                    targetId: 'window_air_conditioner',
                );

                $judgement = $evaluator->evaluate($action, $world, $config, [$action]);
                $outcome = $executor->execute($action, $judgement, $world, $config);

                $where = "AC in {$acRoom}, robot in {$robotRoom}, seed {$seedValue}";

                if ($acRoom !== self::LIVING_ROOM) {
                    // Outside the living room the use is always impossible with
                    // the reason, and the AC is never switched on (Req 4.2).
                    $this->assertFalse(
                        $judgement->possible,
                        "AC use outside the living room must be impossible ({$where})"
                    );
                    $this->assertSame(
                        'ac_only_in_living_room',
                        $judgement->reason,
                        "AC use outside the living room must give the reason ({$where})"
                    );
                    $this->assertFalse(
                        $outcome->success,
                        "an impossible AC use must not succeed ({$where})"
                    );
                    $this->assertFalse(
                        $world->acOn(),
                        "the AC must stay off outside the living room ({$where})"
                    );

                    return;
                }

                // In the living room the living-room rule never blocks the use.
                // It is possible exactly when the robot is also in the living
                // room (the object is present to it); when possible, it ends on.
                if ($robotRoom === self::LIVING_ROOM) {
                    $this->assertTrue(
                        $judgement->possible,
                        "AC use in the living room by a robot there must be possible ({$where})"
                    );
                    $this->assertTrue(
                        $outcome->success,
                        "AC use in the living room by a robot there must succeed ({$where})"
                    );
                    $this->assertTrue(
                        $world->acOn(),
                        "the AC must be on after a successful use ({$where})"
                    );
                } else {
                    // The AC is in the living room but the robot is elsewhere:
                    // the use is impossible for the ordinary reason (the object
                    // is not present), never the living-room reason, and the AC
                    // stays off.
                    $this->assertFalse(
                        $judgement->possible,
                        "AC use by a robot not in the AC's room must be impossible ({$where})"
                    );
                    $this->assertNotSame(
                        'ac_only_in_living_room',
                        $judgement->reason,
                        "the living-room reason must not fire when the AC is in the living room ({$where})"
                    );
                    $this->assertFalse(
                        $world->acOn(),
                        "the AC must stay off when the use is impossible ({$where})"
                    );
                }
            });
    }
}
