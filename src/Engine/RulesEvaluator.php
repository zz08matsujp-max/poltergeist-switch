<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine;

use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\Values\Judgement;

/**
 * Stateless rules engine. Given a single robot's Action and a read-only view
 * of the world, it decides whether the action is possible and whether it is
 * dangerous, returning a Judgement (Req 4.1).
 *
 * This task (9) implements `move` and `take`:
 *   - move: adjacency check only (`not_adjacent`); possible otherwise.
 *   - take: `hands_full`, `object_not_in_room`, and the R4 danger check.
 *
 * The move danger codes (R1, R2a, R3), the `use`/`hand_over`/`support` blocking
 * rules, R2b, and the priority-conflict override are added in tasks 10 and 11.
 * Until then every other operation returns a `possible=true` stub, and the
 * `$allActions` argument is accepted but unused.
 *
 * Requirements: Req 4.1–4.3, Req 4.6–4.7, Req 4.12
 */
class RulesEvaluator
{
    /**
     * Judge one robot's action.
     *
     * @param Action[] $allActions all actions proposed this turn, kept for the
     *                             priority-conflict check added in task 11
     */
    public function evaluate(
        Action $action,
        WorldModel $world,
        EpisodeConfig $config,
        array $allActions,
    ): Judgement {
        return match ($action->operation) {
            Operation::move->value => $this->evaluateMove($action, $world),
            Operation::take->value => $this->evaluateTake($action, $world),
            // hand_over, use, support, cover, wait, speak, unknown — stubbed
            // as always-possible until tasks 10 and 11 replace them.
            default => new Judgement(
                robotId: $action->robotId,
                possible: true,
            ),
        };
    }

    /**
     * move: possible only when the target room is adjacent to the robot's
     * current room (Req 4.2). `targetId` carries the target room key.
     */
    private function evaluateMove(Action $action, WorldModel $world): Judgement
    {
        $currentRoom = $world->robotStates[$action->robotId]->room;
        $adjacent = $world->getRoom($currentRoom)->adjacentRooms;

        if (!in_array($action->targetId, $adjacent, true)) {
            return new Judgement(
                robotId: $action->robotId,
                possible: false,
                reason: 'not_adjacent',
            );
        }

        return new Judgement(
            robotId: $action->robotId,
            possible: true,
        );
    }

    /**
     * take: the robot must have empty hands (`hands_full`, Req 4.3) and the
     * target object must be in the robot's room (`object_not_in_room`, Req 4.7).
     * When the target object is broken and the robot is not already holding a
     * protective item, the take is dangerous with code R4 (Req 4.12).
     */
    private function evaluateTake(Action $action, WorldModel $world): Judgement
    {
        $robot = $world->robotStates[$action->robotId];

        if ($robot->heldObjectId !== null) {
            return new Judgement(
                robotId: $action->robotId,
                possible: false,
                reason: 'hands_full',
            );
        }

        $target = $world->objects[$action->targetId] ?? null;
        if ($target === null || $target->room !== $robot->room) {
            return new Judgement(
                robotId: $action->robotId,
                possible: false,
                reason: 'object_not_in_room',
            );
        }

        // R4: taking a broken object bare-handed is dangerous; holding a
        // protective item neutralises the hazard (Req 4.12).
        if ($target->broken && !$this->holdsProtectiveItem($robot, $world)) {
            return new Judgement(
                robotId: $action->robotId,
                possible: true,
                dangerous: true,
                dangerCode: 'R4',
            );
        }

        return new Judgement(
            robotId: $action->robotId,
            possible: true,
        );
    }

    /**
     * Whether the robot is currently holding an object flagged protective.
     * A robot with empty hands holds nothing protective.
     */
    private function holdsProtectiveItem(RobotState $robot, WorldModel $world): bool
    {
        if ($robot->heldObjectId === null) {
            return false;
        }

        $held = $world->objects[$robot->heldObjectId] ?? null;

        return $held !== null && $held->isProtective;
    }
}
