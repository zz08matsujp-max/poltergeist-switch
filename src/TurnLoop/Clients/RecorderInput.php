<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop\Clients;

use InvalidArgumentException;
use PoltergeistSwitch\Engine\Enums\Disaster;
use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\WorldModel;

/**
 * The ids-only input the recorder is given for one robot (Requirement 3.4).
 *
 * It carries *only* the identifiers the recorder needs to map the reply's words
 * to ids: the operation names, the room keys, the robot's current room key, the
 * ids of the objects in that robot's current room, the id of the object the
 * robot is currently holding (when one is held), the fixed spot names, and the
 * literal `resident`. It carries nothing else — no other room's objects, no
 * danger words, no hints, no formats, no examples (robot-input steering: the
 * recorder only maps words to ids). The current room key lets a same-room spot
 * move name the correct room instead of defaulting to another one.
 */
readonly class RecorderInput
{
    /**
     * The kitchen room key, where the heat-wave cold-water bottle lives
     * (heat-wave Requirement 9.1). Kept as a method for PHP 8.2 compatibility.
     */
    public const KITCHEN_ROOM = 'kitchen';

    /**
     * The object id of the cold-water bottle the heat-wave recorder input adds
     * to the kitchen so the recorder can name it (heat-wave Requirement 9.1).
     * It mirrors ActionExecutor's BARLEY_TEA_ID. The bottle is never registered
     * in the kitchen's fixed listed order, so the earthquake recorder input
     * never sees it (heat-wave Requirement 9.2).
     */
    public const BARLEY_TEA_ID = 'barley_tea';

    /**
     * The fixed spot names the recorder may map to (Requirement 3.4). Kept as a
     * class method rather than a typed constant for PHP 8.2 compatibility.
     *
     * @return string[]
     */
    public static function spotNames(): array
    {
        return ['window', 'gas_stove', 'under_table', 'beside_futon', 'near_resident'];
    }

    /**
     * @param string[] $operationNames the Operation enum values except 'unknown'
     * @param string[] $roomKeys       the six room keys
     * @param string   $currentRoom    the robot's current room key
     * @param string[] $roomObjectIds  ids of objects in the robot's current room
     * @param ?string  $heldObjectId   the held object's id, or null
     * @param string[] $spotNames      ['window','gas_stove','under_table','beside_futon','near_resident']
     * @param string   $residentId     'resident'
     */
    public function __construct(
        public array $operationNames,
        public array $roomKeys,
        public string $currentRoom,
        public array $roomObjectIds,
        public ?string $heldObjectId,
        public array $spotNames,
        public string $residentId,
    ) {}

    /**
     * Build the recorder input for one robot from the live engine WorldModel.
     *
     * The object ids are taken by a straight filter on the full object registry
     * by the robot's current room, preserving the fixed room order — NOT by
     * `objects_visible_to()`, which returns [] for a dark room. The recorder
     * must always be given the mechanical id list regardless of light, because
     * its only job is to map the reply's words to ids (robot-input steering).
     * Nothing from any other room is carried.
     *
     * Heat-wave only (heat-wave Requirement 9.1): when the disaster is heat wave
     * and the robot is in the kitchen, the `barley_tea` bottle id is appended to
     * the kitchen's object list so the recorder can name the cold water. The
     * bottle is created only onto a robot's held slot when drawn and is never
     * registered in the kitchen's fixed listed order, so without this it would
     * never appear in the recorder input. The append leaves the real kitchen
     * objects' order untouched, and (because the bottle keeps its own id when
     * held) the held bottle is reported as the `held` id on the next turn, so
     * the recorder maps the hand-over mechanically. The earthquake recorder
     * input is byte-identical to today — this branch is skipped entirely for an
     * earthquake world (heat-wave Requirement 9.2).
     *
     * @throws InvalidArgumentException when the robot id is unknown
     */
    public static function forRobot(WorldModel $world, string $robotId): self
    {
        if (!array_key_exists($robotId, $world->robotStates)) {
            throw new InvalidArgumentException("Unknown robot id: {$robotId}");
        }

        $robot = $world->robotStates[$robotId];
        $roomKey = $robot->room;

        // Objects in the robot's current room only, in fixed room order.
        $roomObjectIds = [];
        foreach ($world->objects as $object) {
            if ($object->room === $roomKey) {
                $roomObjectIds[] = $object->id;
            }
        }

        // Heat-wave only: list the cold-water bottle in the kitchen so the
        // recorder can name it (heat-wave Requirement 9.1). The earthquake path
        // never enters this branch, so its recorder input is unchanged
        // (heat-wave Requirement 9.2). The id is appended, keeping the real
        // kitchen objects' fixed order intact, and only when it is not already
        // present (a held bottle that was put back would be a real object).
        if (
            $world->disaster === Disaster::heat_wave
            && $roomKey === self::KITCHEN_ROOM
            && !in_array(self::BARLEY_TEA_ID, $roomObjectIds, true)
        ) {
            $roomObjectIds[] = self::BARLEY_TEA_ID;
        }

        // The eight operation names the robot may take, excluding 'unknown'.
        $operationNames = [];
        foreach (Operation::cases() as $case) {
            if ($case !== Operation::unknown) {
                $operationNames[] = $case->value;
            }
        }

        return new self(
            operationNames: $operationNames,
            roomKeys: array_keys($world->rooms),
            currentRoom: $roomKey,
            roomObjectIds: $roomObjectIds,
            heldObjectId: $robot->heldObjectId,
            spotNames: self::spotNames(),
            residentId: 'resident',
        );
    }
}
