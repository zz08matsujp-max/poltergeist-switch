<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\Enums\LightLevel;
use PoltergeistSwitch\Engine\ObjectState;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\SituationSpec;
use PoltergeistSwitch\Engine\WorldModel;

/**
 * Unit tests for WorldModel.
 *
 * Task 8.2 covers objects_visible_to():
 *   - all objects in the robot's room, in fixed order;
 *   - empty array when the room is dark;
 *   - fixtures never appear;
 *   - moving an object out of a room removes it from that room's list.
 *
 * Requirements: Req 2.3–2.4, Req 1.12
 */
class WorldModelTest extends TestCase
{
    /**
     * A deterministic world placed with an explicit SituationSpec so the robot
     * positions are known: robot_a in kitchen, robot_b in bedroom, resident
     * sitting at the table (living room), no boiling pot.
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

    // ------------------------------------------------------------------
    // Returns all objects in the robot's room in the fixed room order.
    // ------------------------------------------------------------------

    public function testReturnsAllObjectsInRobotRoomInFixedOrder(): void
    {
        $world = $this->makeWorld(); // robot_a is in the kitchen

        $visible = $world->objects_visible_to('robot_a', 1);

        $ids = array_map(static fn (ObjectState $o): string => $o->id, $visible);

        // Fixed kitchen order from Req 1.6 / initObjects().
        $this->assertSame(
            ['gas_stove', 'refrigerator', 'sink', 'dish_cupboard', 'flashlight'],
            $ids,
            'Kitchen objects must come back in their fixed registration order'
        );
    }

    public function testReturnsObjectsForASecondRoom(): void
    {
        $world = $this->makeWorld(); // robot_b is in the bedroom

        $ids = array_map(
            static fn (ObjectState $o): string => $o->id,
            $world->objects_visible_to('robot_b', 1)
        );

        $this->assertSame(['futon', 'closet', 'electric_fan'], $ids);
    }

    // ------------------------------------------------------------------
    // Returns [] when the robot's room is dark.
    // ------------------------------------------------------------------

    public function testReturnsEmptyArrayWhenRoomIsDark(): void
    {
        $world = $this->makeWorld();

        // Darken the kitchen where robot_a stands.
        $world->getRoom('kitchen')->lightLevel = LightLevel::dark;

        $this->assertSame(
            [],
            $world->objects_visible_to('robot_a', 1),
            'A robot in a dark room sees no objects'
        );
    }

    // ------------------------------------------------------------------
    // Fixtures are never included in the returned list.
    // ------------------------------------------------------------------

    public function testFixturesAreNeverReturned(): void
    {
        $world = $this->makeWorld();

        $ids = array_map(
            static fn (ObjectState $o): string => $o->id,
            $world->objects_visible_to('robot_a', 1)
        );

        foreach (['counter', 'window', 'wall', 'doorway'] as $fixture) {
            $this->assertNotContains(
                $fixture,
                $ids,
                "Fixture '{$fixture}' must never appear in the visible object list"
            );
        }
    }

    // ------------------------------------------------------------------
    // After moving an object to another room it leaves the original list.
    // ------------------------------------------------------------------

    public function testMovedObjectNoLongerAppearsInOriginalRoom(): void
    {
        $world = $this->makeWorld(); // robot_a in kitchen

        // Sanity: the flashlight starts in the kitchen.
        $kitchenIds = array_map(
            static fn (ObjectState $o): string => $o->id,
            $world->objects_visible_to('robot_a', 1)
        );
        $this->assertContains('flashlight', $kitchenIds);

        // Move the flashlight to the bedroom (direct mutation, as the test note allows).
        $world->objects['flashlight']->room = 'bedroom';

        $kitchenIdsAfter = array_map(
            static fn (ObjectState $o): string => $o->id,
            $world->objects_visible_to('robot_a', 1)
        );
        $this->assertNotContains(
            'flashlight',
            $kitchenIdsAfter,
            'A moved object must drop out of its former room list'
        );

        // And it now shows up for robot_b in the bedroom.
        $bedroomIds = array_map(
            static fn (ObjectState $o): string => $o->id,
            $world->objects_visible_to('robot_b', 1)
        );
        $this->assertContains('flashlight', $bedroomIds);
    }
}
