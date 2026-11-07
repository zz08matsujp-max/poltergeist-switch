<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine;

use Throwable;
use PoltergeistSwitch\Engine\Enums\GasState;
use PoltergeistSwitch\Engine\Enums\LightLevel;
use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\Enums\PowerState;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\Values\Judgement;
use PoltergeistSwitch\Engine\Values\Outcome;

/**
 * Applies a robot's action to the WorldModel once the RulesEvaluator has
 * judged it. The executor owns no persistent state: every call snapshots the
 * world, mutates it, and — on any Throwable — restores the snapshot so the
 * world is left exactly as it was (design §f, Req 5.10).
 *
 * Task 12 covers move, take, use, speak, and cover plus the rollback pattern.
 * The support and hand_over mutations are added in task 13; this class routes
 * them to a stub for now so an unknown-but-possible action does not silently
 * corrupt the world.
 *
 * Requirements: Req 5.1–5.4, Req 5.7–5.10
 */
class ActionExecutor
{
    /** Target id that names the resident himself (cover target, Req 5.8). */
    private const RESIDENT_ID = 'resident';

    /** Object id of the gas stove; `use` on it turns the flame off (Req 5.3). */
    private const GAS_STOVE_ID = 'gas_stove';

    /**
     * Pseudo-target for the gas main shut-off valve. The valve is not a
     * registered object; `use` on it closes the house gas main (Req 5.4).
     */
    private const GAS_MAIN_VALVE_ID = 'gas_main_valve';

    /**
     * Execute one judged action against the world.
     *
     * An impossible judgement never touches the world (Req 5.9): the executor
     * returns a failure Outcome carrying the judgement's reason. For a possible
     * action the executor snapshots the world first, applies the mutation, and
     * rolls the snapshot back if anything throws, returning a failure Outcome
     * (Req 5.10). Otherwise it returns a success Outcome.
     */
    public function execute(
        Action $action,
        Judgement $judgement,
        WorldModel $world,
        EpisodeConfig $config,
    ): Outcome {
        // An impossible action leaves the world untouched (Req 5.9).
        if ($judgement->possible === false) {
            return Outcome::failure(
                $action->robotId,
                $judgement->reason ?? 'not_possible',
            );
        }

        // Snapshot before mutating so any failure can be rolled back whole
        // (design §f, Req 5.10). PHP value semantics make this cheap for the
        // small object counts involved.
        $snapshot = $world->snapshot();

        try {
            $whatHappened = $this->applyAction($action, $world, $config);
        } catch (Throwable $e) {
            $world->restore($snapshot);

            return Outcome::failure($action->robotId, $e->getMessage());
        }

        return Outcome::success($action->robotId, $whatHappened);
    }

    /**
     * Dispatch to the per-operation mutation. Returns the "what happened" text
     * describing the change; throws on any inconsistency so execute() can roll
     * back. cover, wait, and speak never mutate the physical world.
     */
    private function applyAction(Action $action, WorldModel $world, EpisodeConfig $config): string
    {
        return match ($action->operation) {
            Operation::move->value  => $this->executeMove($action, $world),
            Operation::take->value  => $this->executeTake($action, $world),
            Operation::use->value   => $this->executeUse($action, $world),
            Operation::cover->value => $this->executeCover($action, $world),
            Operation::speak->value => $this->executeSpeak($action),
            Operation::wait->value  => 'The robot waits.',
            // support and hand_over gain their mutations in task 13.
            Operation::support->value, Operation::hand_over->value =>
                $this->executeUnimplemented($action),
            default => $this->executeSpeak($action),
        };
    }

    /**
     * move: the robot walks to the target room (Req 5.2). `targetId` carries
     * the destination room key; `targetSpot` carries the spot within it. The
     * room lookup throws on an unknown key, triggering rollback.
     */
    private function executeMove(Action $action, WorldModel $world): string
    {
        $robot = $this->robot($action, $world);
        $destination = (string) $action->targetId;

        // Validate the room exists; getRoom() throws on an unknown key.
        $room = $world->getRoom($destination);

        $robot->room = $destination;
        $robot->spot = $action->targetSpot;

        return "The robot moves to the {$room->displayName}.";
    }

    /**
     * take: the robot picks up the target object (design §f). The object leaves
     * the physical room — its `room` is set to the robot's id so the one-room
     * invariant still holds and the room's object list no longer shows it — and
     * the robot's single held slot records the object id.
     */
    private function executeTake(Action $action, WorldModel $world): string
    {
        $robot = $this->robot($action, $world);
        $object = $this->object($action, $world);

        $robot->heldObjectId = $object->id;
        // Held objects live "on" the robot, not in any room, so they drop out
        // of objects_visible_to()/visibleObjectPhrases() for that room.
        $object->room = $robot->id;
        $object->spot = null;

        return "The robot takes {$object->displayName}.";
    }

    /**
     * use: three distinct effects selected by the target (Req 5.3, Req 5.4):
     *   - the gas stove            → flame off; gas_main untouched;
     *   - the gas main shut-off valve → house gas_main closed;
     *   - a light source (flashlight/candles) → switched on, then the room's
     *     light level is recomputed so a dark room becomes lit again.
     * Any other target is toggled on as a generic appliance.
     */
    private function executeUse(Action $action, WorldModel $world): string
    {
        $targetId = (string) $action->targetId;

        // Closing the gas main is the only `use` that acts on the house, not on
        // a registered object (Req 5.4).
        if ($targetId === self::GAS_MAIN_VALVE_ID) {
            $world->houseState->gasMain = GasState::closed;

            return 'The robot closes the gas main valve.';
        }

        $object = $this->object($action, $world);

        // Turning the gas stove off clears only the flame; the gas main stays
        // as it is (Req 5.3).
        if ($object->id === self::GAS_STOVE_ID) {
            $object->on = false;

            return 'The robot turns off the gas stove.';
        }

        // Lighting a flashlight or candle switches it on and can push a dark
        // room back to lit, so the room's light level is recomputed.
        if ($object->isLightSource) {
            $object->on = true;
            $this->recomputeLightLevel($world, $object->room);

            return "The robot switches on {$object->displayName}.";
        }

        // Any other appliance is simply switched on.
        $object->on = true;

        return "The robot uses {$object->displayName}.";
    }

    /**
     * cover: sets a turn-scoped cover flag that zeroes the falling-object injury
     * increment for the covered party this turn (Req 5.8). Targeting the
     * resident covers the resident; targeting nobody (or the robot itself)
     * covers the robot. The flag is cleared by the engine at end of turn.
     */
    private function executeCover(Action $action, WorldModel $world): string
    {
        if ($action->targetId === self::RESIDENT_ID) {
            $world->residentState->coverFlag = true;

            return 'The robot shields the man.';
        }

        $robot = $this->robot($action, $world);
        $robot->coverFlag = true;

        return 'The robot takes cover.';
    }

    /**
     * speak: words are carried in parallel with the physical action and never
     * change the world (Req 5.7). The spoken lines are returned in the Outcome
     * text so the engine can record them on the turn; no world field is touched.
     */
    private function executeSpeak(Action $action): string
    {
        if ($action->words === []) {
            return 'The robot says nothing.';
        }

        return 'The robot says: ' . implode(' ', $action->words);
    }

    /**
     * support and hand_over are not yet implemented (task 13). Executing one is
     * a programming error at this stage, so throw to trigger rollback rather
     * than silently leave the world half-changed.
     */
    private function executeUnimplemented(Action $action): string
    {
        throw new \LogicException(
            "Operation '{$action->operation}' is not implemented by ActionExecutor yet."
        );
    }

    /**
     * Recompute a room's light level after a light source changes (mirrors the
     * rule EarthquakePhase::updateLightLevels will own in task 14): a room is
     * dark only while the power is out, the time of day is not afternoon, and
     * no lit flashlight or lit candle stands in the room; otherwise it is lit.
     */
    private function recomputeLightLevel(WorldModel $world, string $roomKey): void
    {
        $room = $world->getRoom($roomKey);

        $powerOut = $world->houseState->power === PowerState::outage;
        $isAfternoon = $world->houseState->timeOfDay === 'afternoon';

        if (!$powerOut || $isAfternoon) {
            $room->lightLevel = LightLevel::normal;

            return;
        }

        $room->lightLevel = $this->roomHasLitLight($world, $roomKey)
            ? LightLevel::normal
            : LightLevel::dark;
    }

    /**
     * Whether the given room contains a lit light source (flashlight or candle).
     */
    private function roomHasLitLight(WorldModel $world, string $roomKey): bool
    {
        foreach ($world->objects as $object) {
            if ($object->room === $roomKey && $object->isLightSource && $object->on) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the acting robot or throw (triggers rollback) when unknown.
     */
    private function robot(Action $action, WorldModel $world): RobotState
    {
        $robot = $world->robotStates[$action->robotId] ?? null;
        if ($robot === null) {
            throw new \InvalidArgumentException("Unknown robot id: {$action->robotId}");
        }

        return $robot;
    }

    /**
     * Resolve the target object or throw (triggers rollback) when unknown.
     */
    private function object(Action $action, WorldModel $world): ObjectState
    {
        $object = $world->objects[$action->targetId] ?? null;
        if ($object === null) {
            throw new \InvalidArgumentException("Unknown object id: {$action->targetId}");
        }

        return $object;
    }
}
