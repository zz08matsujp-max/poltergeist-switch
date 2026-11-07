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
 * Tasks 9 and 10 implement the blocking pre-conditions for every operation:
 *   - move: adjacency check only (`not_adjacent`); possible otherwise.
 *   - take: `hands_full`, `object_not_in_room`, and the R4 danger check.
 *   - use: `object_not_in_room`.
 *   - hand_over: `no_object_held`, then `not_in_same_room`.
 *   - support: same room → `possible=true, duration=2`; else `not_in_same_room`.
 *   - cover, wait, speak: always possible (no blocking pre-conditions).
 *
 * The move/use danger codes (R1, R2a, R2b, R3) and the priority-conflict
 * override are added in task 11. Until then the `$allActions` argument is
 * accepted but unused.
 *
 * Requirements: Req 4.1–4.7, Req 4.12, Req 5.5, Req 5.7
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
            Operation::use->value => $this->evaluateUse($action, $world),
            Operation::hand_over->value => $this->evaluateHandOver($action, $world),
            Operation::support->value => $this->evaluateSupport($action, $world),
            // cover, wait, speak, unknown — no blocking pre-conditions; the
            // move/use danger codes and priority conflict are added in task 11.
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
     * use: the target object must be in the robot's current room
     * (`object_not_in_room`, Req 4.6). The R2b shaking danger on the gas stove
     * is layered on in task 11.
     */
    private function evaluateUse(Action $action, WorldModel $world): Judgement
    {
        $robot = $world->robotStates[$action->robotId];

        $target = $world->objects[$action->targetId] ?? null;
        if ($target === null || $target->room !== $robot->room) {
            return new Judgement(
                robotId: $action->robotId,
                possible: false,
                reason: 'object_not_in_room',
            );
        }

        return new Judgement(
            robotId: $action->robotId,
            possible: true,
        );
    }

    /**
     * hand_over: the robot must hold an object (`no_object_held`) and the
     * resident must be in the robot's room (`not_in_same_room`) for the object
     * to be passed over (Req 5.5). The empty-hands check runs first.
     */
    private function evaluateHandOver(Action $action, WorldModel $world): Judgement
    {
        $robot = $world->robotStates[$action->robotId];

        if ($robot->heldObjectId === null) {
            return new Judgement(
                robotId: $action->robotId,
                possible: false,
                reason: 'no_object_held',
            );
        }

        if ($world->residentState->room !== $robot->room) {
            return new Judgement(
                robotId: $action->robotId,
                possible: false,
                reason: 'not_in_same_room',
            );
        }

        return new Judgement(
            robotId: $action->robotId,
            possible: true,
        );
    }

    /**
     * support: possible only when the resident shares the robot's room; the
     * support takes two turns to carry out (`duration=2`, Req 4.4). Otherwise
     * it is impossible with `not_in_same_room` (Req 4.5).
     */
    private function evaluateSupport(Action $action, WorldModel $world): Judgement
    {
        $robot = $world->robotStates[$action->robotId];

        if ($world->residentState->room !== $robot->room) {
            return new Judgement(
                robotId: $action->robotId,
                possible: false,
                reason: 'not_in_same_room',
            );
        }

        return new Judgement(
            robotId: $action->robotId,
            possible: true,
            duration: 2,
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
