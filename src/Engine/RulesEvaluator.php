<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine;

use PoltergeistSwitch\Engine\Enums\Disaster;
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
 * override are added in task 11.
 *
 * Requirements: Req 4.1–4.13, Req 5.5, Req 5.7
 */
class RulesEvaluator
{
    /** Spot key that marks the window fixture of a room (R1, Req 4.8). */
    private const WINDOW_SPOT = 'window';

    /** Object id (and spot) of the gas stove (R2a/R2b, Req 4.9, Req 4.10). */
    private const GAS_STOVE_ID = 'gas_stove';

    /**
     * Object id of the window air conditioner. In a heat-wave episode a `use`
     * on it is only possible when it is in the living room (heat-wave
     * Requirement 4.2); every other heat behaviour is handled by the executor
     * or the heat phase.
     */
    private const AC_ID = 'window_air_conditioner';

    /** The only room the window air conditioner works in (heat-wave Req 4.2). */
    private const LIVING_ROOM = 'living_room';

    /**
     * Pseudo-target for a room's window. In a heat-wave episode `use window`
     * toggles the robot's current room's open-window flag (heat-wave
     * Requirement 4.7); the window is a fixture, never a registered object, so
     * it is recognised specially here like the gas-main valve pseudo-target.
     */
    private const WINDOW_ID = 'window';

    /** Target id that names the resident himself (R3, Req 4.11). */
    private const RESIDENT_ID = 'resident';

    /** Spot key that marks the position next to the man (Req 30.4–30.5). */
    private const SPOT_NEAR_RESIDENT = 'near_resident';

    /**
     * The two in-room spot destinations a support action may name (Req 7.13):
     * crouching under the low table, or sitting beside the futon. Any other
     * destination must be a room key adjacent to the resident's current room.
     */
    private const SPOT_UNDER_TABLE = 'under_table';
    private const SPOT_BESIDE_FUTON = 'beside_futon';

    /** Danger codes recorded in the Judgement (Req 4.8–4.12). */
    private const DANGER_R1 = 'R1';
    private const DANGER_R2A = 'R2a';
    private const DANGER_R2B = 'R2b';
    private const DANGER_R3 = 'R3';

    /** Robot slot indices for the priority-conflict rule (Req 4.13). */
    private const ROBOT_SLOTS = ['robot_a' => 0, 'robot_b' => 1];

    /**
     * Judge one robot's action.
     *
     * The per-operation check runs first; the priority-conflict override
     * (Req 4.13) is applied last so it can turn an otherwise-possible action
     * impossible without discarding any danger code the operation recorded.
     *
     * @param Action[] $allActions all actions proposed this turn, used for the
     *                             R3 and priority-conflict checks
     */
    public function evaluate(
        Action $action,
        WorldModel $world,
        EpisodeConfig $config,
        array $allActions,
    ): Judgement {
        $judgement = match ($action->operation) {
            Operation::move->value => $this->evaluateMove($action, $world, $config, $allActions),
            Operation::take->value => $this->evaluateTake($action, $world),
            Operation::use->value => $this->evaluateUse($action, $world, $config),
            Operation::hand_over->value => $this->evaluateHandOver($action, $world),
            Operation::support->value => $this->evaluateSupport($action, $world),
            // cover, wait, speak, unknown — no blocking pre-conditions.
            default => new Judgement(
                robotId: $action->robotId,
                possible: true,
            ),
        };

        return $this->applyPriorityConflict($judgement, $action, $world, $allActions);
    }

    /**
     * move: reachable when the target room is the robot's own current room (a
     * same-room spot change that leaves the room unchanged, Req 30.1) or
     * adjacent to it (Req 4.2); any other room is `not_adjacent` (Req 30.6).
     * `targetId` carries the target room key and `targetSpot` the spot within
     * it.
     *
     * Reachability is checked first and its reason takes precedence: an
     * unreachable room is always `not_adjacent`, never `resident_not_here`.
     * When the room is reachable and `targetSpot` is `near_resident` (next to
     * the man, Req 30.4) but the resident is not in that room, the move is
     * `possible=false, reason="resident_not_here"` (Req 30.5).
     *
     * Danger checks layer on top of the possibility verdict and never clear it,
     * and apply to same-room moves exactly as to moves between rooms
     * (Req 30.2–30.3): they key only off `targetSpot` + `House_State.shaking`.
     *   - R1 (Req 4.8, Req 30.2): shaking + window spot → dangerous, code R1.
     *     Applies even when the move is impossible for another reason, so both
     *     the reason and the R1 danger are reported together.
     *   - R2a (Req 4.9, Req 30.3): shaking + landing on the gas stove spot →
     *     always records code R2a; the `dangerous` flag follows
     *     `$config->r2aIsDangerous`.
     *   - R3 (Req 4.11): the move targets the resident himself (an attempt to
     *     move the resident) with no concurrent support from a robot in the
     *     resident's room → dangerous, code R3. A robot merely leaving or
     *     entering a room is never R3.
     *
     * @param Action[] $allActions all actions proposed this turn (for R3)
     */
    private function evaluateMove(
        Action $action,
        WorldModel $world,
        EpisodeConfig $config,
        array $allActions,
    ): Judgement {
        $currentRoom = $world->robotStates[$action->robotId]->room;
        $adjacent = $world->getRoom($currentRoom)->adjacentRooms;

        // Reachable = the robot's own room (same-room spot change, Req 30.1) or
        // an adjacent room (Req 4.2). An unreachable room is `not_adjacent`
        // (Req 30.6) and that reason wins over any spot-based reason below.
        $reachable = $action->targetId === $currentRoom
            || in_array($action->targetId, $adjacent, true);

        if (!$reachable) {
            $possible = false;
            $reason = 'not_adjacent';
        } elseif (
            $action->targetSpot === self::SPOT_NEAR_RESIDENT
            && $world->residentState->room !== $action->targetId
        ) {
            // `near_resident` requires the man to be in the destination room
            // (Req 30.5).
            $possible = false;
            $reason = 'resident_not_here';
        } else {
            $possible = true;
            $reason = null;
        }

        // Danger checks run regardless of adjacency so an impossible move still
        // carries its danger code (Req 4.8). The first matching code wins; the
        // move danger codes are mutually exclusive in practice (window vs. gas
        // stove vs. moving the resident).
        $dangerous = false;
        $dangerCode = null;

        if (($code = $this->checkDangerR1($action, $world)) !== null) {
            $dangerous = true;
            $dangerCode = $code;
        } elseif (($code = $this->checkDangerR2($action, $world, $config)) === self::DANGER_R2A) {
            $dangerCode = self::DANGER_R2A;
            $dangerous = $config->r2aIsDangerous;
        } elseif ($this->checkDangerR3($action, $world, $allActions)) {
            $dangerous = true;
            $dangerCode = self::DANGER_R3;
        }

        return new Judgement(
            robotId: $action->robotId,
            possible: $possible,
            reason: $reason,
            dangerous: $dangerous,
            dangerCode: $dangerCode,
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
     * use: the target object must be present to the robot — either standing in
     * the robot's current room, or held by the robot itself (`object_not_in_room`
     * otherwise, Req 4.6). A held object's `room` is set to the robot's id by
     * `take`, so it never matches the room comparison; the held-slot check below
     * covers lighting a held flashlight or candle on the same terms as one in
     * the room.
     *
     * R2b (Req 4.10): using the gas stove while shaking always records
     * `dangerCode="R2b"`; the `dangerous` flag follows `$config->r2bIsDangerous`.
     * The danger code is attached even when the use is impossible, mirroring the
     * R1 rule on move.
     */
    private function evaluateUse(Action $action, WorldModel $world, EpisodeConfig $config): Judgement
    {
        $robot = $world->robotStates[$action->robotId];

        // Heat-wave-only `use` targets (heat-wave Requirement 1.12, 4.2, 4.7).
        // These branches run ONLY in a heat-wave episode; an earthquake episode
        // never enters them, so the window pseudo-target and the air-conditioner
        // living-room check are invisible to the earthquake path, which falls
        // through to the existing object-based checks below exactly as today.
        if ($world->disaster === Disaster::heat_wave) {
            $heat = $this->evaluateHeatUse($action, $world);
            if ($heat !== null) {
                return $heat;
            }
        }

        $target = $world->objects[$action->targetId] ?? null;
        $inRoom = $target !== null && $target->room === $robot->room;
        $heldByRobot = $target !== null && $robot->heldObjectId === $target->id;
        $possible = $inRoom || $heldByRobot;
        $reason = $possible ? null : 'object_not_in_room';

        $dangerous = false;
        $dangerCode = null;
        if ($this->checkDangerR2b($action, $world)) {
            $dangerCode = self::DANGER_R2B;
            $dangerous = $config->r2bIsDangerous;
        }

        return new Judgement(
            robotId: $action->robotId,
            possible: $possible,
            reason: $reason,
            dangerous: $dangerous,
            dangerCode: $dangerCode,
        );
    }

    /**
     * Judge the heat-wave-only `use` targets (heat-wave Requirements 4.2, 4.7).
     * Returns a Judgement when this is a heat-specific `use`, or null when the
     * target is a normal object that the standard `use` check below should
     * handle (so an ordinary `use electric_fan` / `use window_air_conditioner`
     * in the living room falls through unchanged).
     *
     * Two heat targets are recognised specially:
     *   - the `window` pseudo-target (heat-wave Req 4.7): a `use window` toggles
     *     the robot's current room's open-window flag. Every room has a window
     *     fixture, so it is always possible; the executor flips the flag.
     *   - the window air conditioner (heat-wave Req 4.2): a `use` on it in a
     *     room other than the living room is `possible=false` with a reason and
     *     leaves the world unchanged. In the living room it falls through to the
     *     standard `use` (turning the appliance on), so this returns null there.
     */
    private function evaluateHeatUse(Action $action, WorldModel $world): ?Judgement
    {
        // The window pseudo-target: always usable, the executor toggles the
        // robot's current room's open-window flag (heat-wave Req 4.7).
        if ($action->targetId === self::WINDOW_ID) {
            return new Judgement(
                robotId: $action->robotId,
                possible: true,
            );
        }

        // The air conditioner works only in the living room (heat-wave Req 4.2).
        // A `use` on it elsewhere is impossible and changes nothing; a `use` in
        // the living room is a normal appliance switch-on handled below (return
        // null to fall through).
        if ($action->targetId === self::AC_ID) {
            $target = $world->objects[self::AC_ID] ?? null;
            if ($target !== null && $target->room !== self::LIVING_ROOM) {
                return new Judgement(
                    robotId: $action->robotId,
                    possible: false,
                    reason: 'ac_only_in_living_room',
                );
            }
        }

        return null;
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
     * support: possible only when the resident shares the robot's room AND the
     * action names a valid destination in `targetSpot` (Req 4.4, Req 7.13). The
     * support takes two turns to carry out (`duration=2`, Req 4.4).
     *
     * The destination must be one of:
     *   - `under_table`  — crouch under the low table (stays in the room);
     *   - `beside_futon` — sit beside the futon (stays in the room);
     *   - a room key adjacent to the resident's current room (relocates him).
     * Anything else — a null spot, an unknown token, or a non-adjacent room —
     * is `possible=false, reason="no_destination"`. The same-room check runs
     * first so a support from the wrong room is still `not_in_same_room`
     * (Req 4.5).
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

        if (!$this->supportDestinationIsValid($action->targetSpot, $world)) {
            return new Judgement(
                robotId: $action->robotId,
                possible: false,
                reason: 'no_destination',
            );
        }

        return new Judgement(
            robotId: $action->robotId,
            possible: true,
            duration: 2,
        );
    }

    /**
     * Whether a support action's `targetSpot` names a destination the resident
     * can be taken to (Req 7.13). The two in-room spots are always valid; any
     * other value must be the key of a room adjacent to the resident's current
     * room. A null or empty spot is never valid.
     */
    private function supportDestinationIsValid(?string $targetSpot, WorldModel $world): bool
    {
        if ($targetSpot === null || $targetSpot === '') {
            return false;
        }

        if ($targetSpot === self::SPOT_UNDER_TABLE || $targetSpot === self::SPOT_BESIDE_FUTON) {
            return true;
        }

        $residentRoom = $world->residentState->room;

        return in_array($targetSpot, $world->getRoom($residentRoom)->adjacentRooms, true);
    }

    /**
     * R1 (Req 4.8): while the house is shaking, a move onto a window spot is
     * dangerous. Returns the danger code 'R1' when it fires, else null.
     * `targetSpot` carries the spot the robot moves onto.
     */
    private function checkDangerR1(Action $action, WorldModel $world): ?string
    {
        if (!$world->houseState->shaking) {
            return null;
        }

        return $action->targetSpot === self::WINDOW_SPOT ? self::DANGER_R1 : null;
    }

    /**
     * R2a (Req 4.9): while the house is shaking, a move that lands the robot on
     * the gas stove spot records the danger code 'R2a'. Returns the code when it
     * fires, else null. The caller decides the `dangerous` flag from
     * `$config->r2aIsDangerous`; the `$config` argument keeps the design's
     * checkDangerR2(Action, WorldModel, EpisodeConfig) signature.
     */
    private function checkDangerR2(Action $action, WorldModel $world, EpisodeConfig $config): ?string
    {
        if (!$world->houseState->shaking) {
            return null;
        }

        return $action->targetSpot === self::GAS_STOVE_ID ? self::DANGER_R2A : null;
    }

    /**
     * R2b (Req 4.10): while the house is shaking, a use action targeting the gas
     * stove records the danger code 'R2b'. Returns true when it fires.
     */
    private function checkDangerR2b(Action $action, WorldModel $world): bool
    {
        return $world->houseState->shaking && $action->targetId === self::GAS_STOVE_ID;
    }

    /**
     * R3 (Req 4.11): moving the resident suddenly without support is dangerous.
     * R3 applies only when the move action targets the resident himself (its
     * `targetId` is the resident's id) and no robot in the resident's current
     * room performs a concurrent `support` action targeting the resident. A
     * robot merely leaving or entering a room — a move whose target is a room —
     * is never R3. Returns true when the R3 danger applies.
     *
     * @param Action[] $allActions all actions proposed this turn
     */
    private function checkDangerR3(Action $action, WorldModel $world, array $allActions): bool
    {
        // R3 fires only when the move tries to move the resident himself.
        if ($action->targetId !== self::RESIDENT_ID) {
            return false;
        }

        $residentRoom = $world->residentState->room;

        // A concurrent support action targeting the resident, performed by a
        // robot in the resident's current room, makes the move safe.
        foreach ($allActions as $other) {
            if ($other->operation !== Operation::support->value) {
                continue;
            }
            if ($other->targetId !== self::RESIDENT_ID) {
                continue;
            }
            $supporter = $world->robotStates[$other->robotId] ?? null;
            if ($supporter !== null && $supporter->room === $residentRoom) {
                return false;
            }
        }

        return true;
    }

    /**
     * Priority conflict (Req 4.13): when both robots target the same object id
     * on the same turn, priority goes to the robot whose slot index satisfies
     * `turn_number % 2 == robot_slot` (robot_a=0, robot_b=1). The other robot's
     * action is overridden to `possible=false, reason="priority_conflict"`.
     * Any danger code already recorded on the losing judgement is preserved.
     *
     * @param Action[] $allActions all actions proposed this turn
     */
    private function applyPriorityConflict(
        Judgement $judgement,
        Action $action,
        WorldModel $world,
        array $allActions,
    ): Judgement {
        $targetId = $action->targetId;
        if ($targetId === null) {
            return $judgement;
        }

        // Find another robot's action targeting the same object id.
        $conflict = false;
        foreach ($allActions as $other) {
            if ($other->robotId !== $action->robotId && $other->targetId === $targetId) {
                $conflict = true;
                break;
            }
        }
        if (!$conflict) {
            return $judgement;
        }

        $slot = self::ROBOT_SLOTS[$action->robotId] ?? null;
        if ($slot === null) {
            return $judgement;
        }

        // The winner's slot satisfies turn_number % 2 == slot; the loser does
        // not and is blocked.
        if (($world->currentTurn % 2) === $slot) {
            return $judgement;
        }

        return new Judgement(
            robotId: $judgement->robotId,
            possible: false,
            reason: 'priority_conflict',
            dangerous: $judgement->dangerous,
            dangerCode: $judgement->dangerCode,
            duration: $judgement->duration,
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
