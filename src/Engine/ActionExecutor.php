<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine;

use Throwable;
use PoltergeistSwitch\Engine\Enums\Disaster;
use PoltergeistSwitch\Engine\Enums\GasState;
use PoltergeistSwitch\Engine\Enums\Operation;
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
 * Task 13 adds the support and hand_over mutations.
 *
 * Requirements: Req 5.1–5.6, Req 5.7–5.11
 */
class ActionExecutor
{
    /** Target id that names the resident himself (cover target, Req 5.8). */
    private const RESIDENT_ID = 'resident';

    /** Object id of the gas stove; `use` on it turns the flame off (Req 5.3). */
    private const GAS_STOVE_ID = 'gas_stove';

    /** Spot next to the resident; `move` text adds ", next to the man". */
    private const SPOT_NEAR_RESIDENT = 'near_resident';

    /** Window spot; a same-room `move` there reads "moved to the window". */
    private const SPOT_WINDOW = 'window';

    /** Gas-stove spot; a same-room `move` there reads "moved to the gas stove". */
    private const SPOT_GAS_STOVE = 'gas_stove';

    /**
     * Pseudo-target for the gas main shut-off valve. The valve is not a
     * registered object; `use` on it closes the house gas main (Req 5.4).
     */
    private const GAS_MAIN_VALVE_ID = 'gas_main_valve';

    /**
     * A `support` action starts a two-turn countdown on the robot; the
     * resident moves with the robot when it decrements to zero (Req 5.5).
     */
    private const SUPPORT_TURNS = 2;

    /** Object id of the electric fan (heat-wave Req 4.3, 4.8). */
    private const ELECTRIC_FAN_ID = 'electric_fan';

    /** Object id of the window air conditioner (heat-wave Req 4.1, 5). */
    private const AIR_CONDITIONER_ID = 'window_air_conditioner';

    /** Object id of the refrigerator; cold water comes from it (heat-wave Req 4.4). */
    private const REFRIGERATOR_ID = 'refrigerator';

    /** The kitchen, where the refrigerator and the cold water are (heat-wave Req 4.4). */
    private const KITCHEN = 'kitchen';

    /** Object id of the cold-water bottle (heat-wave Req 4.4, 4.9). */
    private const BARLEY_TEA_ID = 'barley_tea';

    /** Display name of the cold-water bottle (heat-wave Req 4.4). */
    private const BARLEY_TEA_NAME = 'a bottle of cold barley tea';

    /**
     * Pseudo-target for a room's window. In a heat-wave episode `use window`
     * toggles the robot's current room's open-window flag (heat-wave Req 4.7).
     * The window is a fixture, never a registered object, so it is recognised
     * specially here, exactly like the gas-main valve pseudo-target.
     */
    private const WINDOW_ID = 'window';

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
            [$whatHappened, $seenByOther] = $this->applyAction($action, $world, $config);
        } catch (Throwable $e) {
            $world->restore($snapshot);

            return Outcome::failure($action->robotId, $e->getMessage());
        }

        return Outcome::success($action->robotId, $whatHappened, $seenByOther);
    }

    /**
     * Dispatch to the per-operation mutation. Returns a `[whatHappenedText,
     * seenByOtherText]` tuple: the first is second-person past tense ("You
     * …"), the second the same event from the other robot's viewpoint ("The
     * other robot …"). Mutating operations fill both; `speak` and `unknown`
     * fill both with the empty string, because a spoken line is carried by
     * Action.words and delivered separately and an unknown reading executes
     * nothing (design §f "Outcome text contract", Req 9.16, Req 12.3). Throws
     * on any inconsistency so execute() can roll back.
     *
     * @return array{0:string,1:string} [whatHappenedText, seenByOtherText]
     */
    private function applyAction(Action $action, WorldModel $world, EpisodeConfig $config): array
    {
        return match ($action->operation) {
            Operation::move->value      => $this->executeMove($action, $world),
            Operation::take->value      => $this->executeTake($action, $world),
            Operation::use->value       => $this->executeUse($action, $world),
            Operation::cover->value     => $this->executeCover($action, $world),
            Operation::speak->value     => $this->executeSpeak(),
            Operation::wait->value      => ['You waited.', 'The other robot waited.'],
            Operation::support->value   => $this->executeSupport($action, $world),
            Operation::hand_over->value => $this->executeHandOver($action, $world, $config),
            default => $this->executeSpeak(),
        };
    }

    /**
     * move: the robot walks to the target room (Req 5.2). `targetId` carries
     * the destination room key; `targetSpot` carries the spot within it. The
     * room lookup throws on an unknown key, triggering rollback.
     *
     * A same-room move (destination === origin) leaves the room unchanged and
     * updates only the spot (Req 30.1); `targetSpot` may be `near_resident`,
     * which is stored verbatim since the evaluator already blocked the
     * impossible case (Req 30.5).
     *
     * The outcome text is chosen per spot and whether the room actually
     * changed, so a same-room move never falsely claims the robot left:
     *   - near_resident (checked first, room-change or not): adds ", next to
     *     the man", and on a same-room move drops the room entirely ("moved
     *     next to the man");
     *   - same-room window / gas stove: "moved to the window" / "…gas stove";
     *   - any other same-room move (null or other spot): "moved within the
     *     {room}";
     *   - a genuine between-room move: "moved to the {room}" (unchanged).
     * window/gas_stove on a between-room move fall through to the plain
     * between-room text, since the special wording only applies in-room.
     */
    private function executeMove(Action $action, WorldModel $world): array
    {
        $robot = $this->robot($action, $world);
        $destination = (string) $action->targetId;

        // Validate the room exists; getRoom() throws on an unknown key.
        $room = $world->getRoom($destination);

        $origin = $robot->room;

        $robot->room = $destination;
        $robot->spot = $action->targetSpot;

        // A lit light source held by the robot travels with it: the room left
        // behind may go dark and the room entered may become lit, so both are
        // recomputed through the shared light rule (design §f/§g, Req 2.5). A
        // same-room move leaves the robot in one room, so a single recompute
        // suffices (Req 30.1).
        if ($this->holdsLitLightSource($robot, $world)) {
            EarthquakePhase::recomputeRoomLightLevel($world, $destination);
            if ($origin !== $destination) {
                EarthquakePhase::recomputeRoomLightLevel($world, $origin);
            }
        }

        $sameRoom = ($origin === $destination);
        $spot = $action->targetSpot;
        $roomName = $room->displayName;

        // near_resident applies whether or not the room changed (Req 30.5).
        if ($spot === self::SPOT_NEAR_RESIDENT) {
            if (!$sameRoom) {
                return [
                    "You moved to the {$roomName}, next to the man.",
                    "The other robot moved to the {$roomName}, next to the man.",
                ];
            }

            return [
                'You moved next to the man.',
                'The other robot moved next to the man.',
            ];
        }

        if ($sameRoom && $spot === self::SPOT_WINDOW) {
            return [
                'You moved to the window.',
                'The other robot moved to the window.',
            ];
        }

        if ($sameRoom && $spot === self::SPOT_GAS_STOVE) {
            return [
                'You moved to the gas stove.',
                'The other robot moved to the gas stove.',
            ];
        }

        if ($sameRoom) {
            return [
                "You moved within the {$roomName}.",
                "The other robot moved within the {$roomName}.",
            ];
        }

        // A genuine between-room move keeps the original wording so existing
        // between-room tests and the golden fixtures remain unaffected.
        return [
            "You moved to the {$roomName}.",
            "The other robot moved to the {$roomName}.",
        ];
    }

    /**
     * take: the robot picks up the target object (design §f). The object leaves
     * the physical room — its `room` is set to the robot's id so the one-room
     * invariant still holds and the room's object list no longer shows it — and
     * the robot's single held slot records the object id.
     */
    private function executeTake(Action $action, WorldModel $world): array
    {
        $robot = $this->robot($action, $world);

        // Cold water (heat-wave Req 4.4): in a heat-wave episode, a `take` on
        // the refrigerator in the kitchen gives the robot the held barley-tea
        // bottle instead of picking up the refrigerator, so `use` and `take`
        // both yield the bottle. An earthquake `take refrigerator` is unchanged.
        if (
            $world->disaster === Disaster::heat_wave
            && $action->targetId === self::REFRIGERATOR_ID
            && $robot->room === self::KITCHEN
        ) {
            return $this->giveColdWater($robot, $world);
        }

        $object = $this->object($action, $world);

        // Remember where the object stood so a lit light source recomputes the
        // room it leaves. After `take` the robot stays in that same room, so
        // the light simply continues to light it (design §f/§g).
        $originRoom = $object->room;
        $wasLitLight = $object->isLightSource && $object->on;

        $robot->heldObjectId = $object->id;
        // Held objects live "on" the robot, not in any room, so they drop out
        // of objects_visible_to()/visibleObjectPhrases() for that room.
        $object->room = $robot->id;
        $object->spot = null;

        if ($wasLitLight) {
            EarthquakePhase::recomputeRoomLightLevel($world, $originRoom);
        }

        return [
            "You picked up {$object->displayName}.",
            "The other robot picked up {$object->displayName}.",
        ];
    }

    /**
     * use: three distinct effects selected by the target (Req 5.3, Req 5.4):
     *   - the gas stove            → flame off; gas_main untouched;
     *   - the gas main shut-off valve → house gas_main closed;
     *   - a light source (flashlight/candles) → switched on, then the room's
     *     light level is recomputed so a dark room becomes lit again.
     * Any other target is toggled on as a generic appliance.
     */
    private function executeUse(Action $action, WorldModel $world): array
    {
        $targetId = (string) $action->targetId;

        // Heat-wave-only `use` targets (heat-wave Req 4.4, 4.7, 4.8). These
        // branches run ONLY in a heat-wave episode; an earthquake episode never
        // enters them, so the window toggle, the cold-water bottle, and the fan
        // put-down/toggle are invisible to the earthquake path, which keeps its
        // existing `use` behaviour below byte-for-byte.
        if ($world->disaster === Disaster::heat_wave) {
            $heat = $this->applyHeatUse($action, $world);
            if ($heat !== null) {
                return $heat;
            }
        }

        // Closing the gas main is the only `use` that acts on the house, not on
        // a registered object (Req 5.4). Check its state first (Req 34): when
        // the main is already closed, change nothing and say so, so the record
        // never claims a valve was closed when it already was (Req 34.1). When
        // it is open, close it and keep the unchanged wording (Req 34.2).
        if ($targetId === self::GAS_MAIN_VALVE_ID) {
            if ($world->houseState->gasMain === GasState::closed) {
                return [
                    'You turned the gas main valve. It was already closed.',
                    'The other robot turned the gas main valve. It was already closed.',
                ];
            }

            $world->houseState->gasMain = GasState::closed;

            return [
                'You closed the gas main valve.',
                'The other robot closed the gas main valve.',
            ];
        }

        $object = $this->object($action, $world);

        // Using the gas stove: check its flame first (Req 33). When it is
        // already off, change nothing (the stove stays off, the gas main is
        // untouched) and say so, so the record never claims a flame went out
        // when there was none (Req 33.1). When it is on, turning it off clears
        // only the flame; the gas main stays as it is (Req 5.3, Req 33.2).
        if ($object->id === self::GAS_STOVE_ID) {
            if ($object->on === false) {
                return [
                    "You turned the gas stove's knob. It was already off.",
                    "The other robot turned the gas stove's knob. It was already off.",
                ];
            }

            $object->on = false;

            return [
                'You turned off the gas stove. The flame went out.',
                'The other robot turned off the gas stove. The flame went out.',
            ];
        }

        // Lighting a flashlight or candle switches it on and can push a dark
        // room back to lit, so the room's light level is recomputed through the
        // shared light rule that EarthquakePhase owns (see §g). A held light
        // parks `room` on the holder's id, which is not a real room key, so the
        // room to recompute is the holder's current room, not $object->room.
        //
        // Check the light's state first (Req 34): when it is already on, change
        // nothing — leave the flag set, skip the recompute — and say so, so the
        // record never claims a light was switched on when it already was
        // (Req 34.3). When it is off, switch it on and recompute, keeping the
        // unchanged wording (Req 34.4).
        if ($object->isLightSource) {
            if ($object->on === true) {
                return [
                    "You tried to switch on {$object->displayName}. The light was already on.",
                    "The other robot tried to switch on {$object->displayName}. The light was already on.",
                ];
            }

            $object->on = true;
            EarthquakePhase::recomputeRoomLightLevel(
                $world,
                $this->lightSourceRoom($object, $world),
            );

            return [
                "You switched on {$object->displayName}.",
                "The other robot switched on {$object->displayName}.",
            ];
        }

        // Any other appliance is simply switched on.
        $object->on = true;

        return [
            "You used {$object->displayName}.",
            "The other robot used {$object->displayName}.",
        ];
    }

    /**
     * Apply a heat-wave-only `use` (heat-wave Requirements 4.4, 4.7, 4.8).
     * Returns a `[whatHappened, seenByOther]` tuple for a heat-specific `use`,
     * or null when the target is an ordinary object the standard `use` below
     * should handle (so `use window_air_conditioner` in the living room still
     * switches the appliance on through the existing path).
     *
     * Four heat `use` targets are handled here:
     *   - the `window` pseudo-target (Req 4.7): toggle the robot's current
     *     room's open-window flag; the window is a fixture, never an object;
     *   - the refrigerator in the kitchen (Req 4.4): give the robot the held
     *     barley-tea bottle, so `use` matches `take`;
     *   - the electric fan (Req 4.8): a `use` while holding it puts it down in
     *     the robot's current room and switches it on in one step; a `use`
     *     while it stands in a room toggles it on/off;
     *   - the window air conditioner in the living room (Req 4.1): a `use`
     *     toggles it on/off with the "window air conditioner" wording, instead
     *     of the generic "You used a window air conditioner." So the record
     *     reads "switched on/off". An AC `use` from another room falls through
     *     (the evaluator has already judged it impossible, Req 4.2).
     */
    private function applyHeatUse(Action $action, WorldModel $world): ?array
    {
        $targetId = (string) $action->targetId;

        // The window: toggle the current room's open-window flag (Req 4.7). The
        // evaluator has already judged this possible, so the robot's room is
        // valid; every room carries a window fixture.
        if ($targetId === self::WINDOW_ID) {
            $robot = $this->robot($action, $world);
            $room = $world->getRoom($robot->room);
            $room->windowOpen = !$room->windowOpen;

            if ($room->windowOpen) {
                return [
                    'You opened the window.',
                    'The other robot opened the window.',
                ];
            }

            return [
                'You closed the window.',
                'The other robot closed the window.',
            ];
        }

        // The refrigerator in the kitchen: hand the robot the cold-water bottle
        // (Req 4.4), so a `use` yields the same held bottle a `take` does.
        if ($targetId === self::REFRIGERATOR_ID) {
            $robot = $this->robot($action, $world);
            if ($robot->room === self::KITCHEN) {
                return $this->giveColdWater($robot, $world);
            }
            // The refrigerator used from another room is not a cold-water draw;
            // fall through to the ordinary appliance switch-on below.
            return null;
        }

        // The electric fan: carry-then-run in one `use` (Req 4.8).
        if ($targetId === self::ELECTRIC_FAN_ID) {
            return $this->useElectricFan($action, $world);
        }

        // The window air conditioner in the living room: toggle on/off with the
        // definite-article wording (Req 4.1). The evaluator only lets a `use`
        // reach here in the living room (Req 4.2), so a real toggle happens;
        // the text reflects the state AFTER the switch. An AC `use` elsewhere
        // is not reached as a successful execution, so fall through to null.
        if ($targetId === self::AIR_CONDITIONER_ID) {
            return $this->useAirConditioner($action, $world);
        }

        return null;
    }

    /**
     * Toggle the window air conditioner on or off (heat-wave Requirement 4.1)
     * with the definite-article wording the maintainer chose, in place of the
     * generic "You used a window air conditioner." The outcome text reflects
     * the AC's state AFTER the switch: switching it on reads "switched on the
     * window air conditioner." and switching it off reads "switched off the
     * window air conditioner.". The cooling itself is applied by HeatWavePhase,
     * not here.
     */
    private function useAirConditioner(Action $action, WorldModel $world): array
    {
        $ac = $this->object($action, $world);
        $ac->on = !$ac->on;

        if ($ac->on) {
            return [
                'You switched on the window air conditioner.',
                'The other robot switched on the window air conditioner.',
            ];
        }

        return [
            'You switched off the window air conditioner.',
            'The other robot switched off the window air conditioner.',
        ];
    }

    /**
     * Create the cold-water bottle directly onto the robot's held slot
     * (heat-wave Requirement 4.4, 4.9). The bottle is a water-container object
     * (`isWaterContainer = true`) so a later `hand_over` raises the resident's
     * water exactly like any water container (Req 3.3). It is parked on the
     * robot (its `room` set to the robot id), mirroring how `take` parks a held
     * object, and is never registered in the kitchen's fixed listed-object
     * order — it is created only when a robot draws it, like the boiling pot.
     *
     * If the robot's hands are full the bottle cannot be taken; this returns a
     * no-change outcome so the world is left exactly as it was.
     */
    private function giveColdWater(RobotState $robot, WorldModel $world): array
    {
        if ($robot->heldObjectId !== null) {
            return [
                'You reached into the refrigerator, but your hands were full.',
                'The other robot reached into the refrigerator, but its hands were full.',
            ];
        }

        $world->objects[self::BARLEY_TEA_ID] = new ObjectState(
            id: self::BARLEY_TEA_ID,
            displayName: self::BARLEY_TEA_NAME,
            room: $robot->id,
            spot: null,
            on: false,
            open: false,
            contents: [],
            broken: false,
            fallen: false,
            isWaterContainer: true,
        );
        $robot->heldObjectId = self::BARLEY_TEA_ID;

        return [
            'You took a bottle of cold barley tea from the refrigerator.',
            'The other robot took a bottle of cold barley tea from the refrigerator.',
        ];
    }

    /**
     * Carry-then-run for the electric fan in a single `use` (heat-wave
     * Requirement 4.8). When the robot holds the fan, the `use` puts it down in
     * the robot's current room and switches it on there in one step: the fan
     * leaves the held slot, its `room` becomes the robot's room, and `on` is
     * set true. When the fan stands in a room, the `use` toggles it on/off.
     */
    private function useElectricFan(Action $action, WorldModel $world): array
    {
        $robot = $this->robot($action, $world);
        $fan = $this->object($action, $world);

        // Held by this robot: put it down in the robot's room and switch on.
        if ($robot->heldObjectId === self::ELECTRIC_FAN_ID) {
            $robot->heldObjectId = null;
            $fan->room = $robot->room;
            $fan->spot = null;
            $fan->on = true;

            return [
                'You set the electric fan down and switched it on.',
                'The other robot set the electric fan down and switched it on.',
            ];
        }

        // Standing in a room: toggle on/off.
        $fan->on = !$fan->on;

        if ($fan->on) {
            return [
                'You switched on the electric fan.',
                'The other robot switched on the electric fan.',
            ];
        }

        return [
            'You switched off the electric fan.',
            'The other robot switched off the electric fan.',
        ];
    }

    /**
     * cover: sets a turn-scoped cover flag that zeroes the falling-object injury
     * increment for the covered party this turn (Req 5.8). Targeting the
     * resident covers the resident; targeting nobody (or the robot itself)
     * covers the robot. The flag is cleared by the engine at end of turn.
     */
    private function executeCover(Action $action, WorldModel $world): array
    {
        if ($action->targetId === self::RESIDENT_ID) {
            $world->residentState->coverFlag = true;

            return [
                'You shielded the man.',
                'The other robot shielded the man.',
            ];
        }

        $robot = $this->robot($action, $world);
        $robot->coverFlag = true;

        return [
            'You took cover.',
            'The other robot took cover.',
        ];
    }

    /**
     * speak: words are carried in parallel with the physical action in
     * Action.words and are delivered as their own recent-events lines by the
     * SituationTextGenerator, not through the Outcome text (Req 5.7, Req 9.16).
     * The spoken action therefore produces no event text: both fields are the
     * empty string so the generator's non-empty checks skip them. No world
     * field is touched. The same empty-text result stands in for an `unknown`
     * reading, which executes nothing (Req 12.3).
     *
     * @return array{0:string,1:string}
     */
    private function executeSpeak(): array
    {
        return ['', ''];
    }

    /**
     * support: start a two-turn countdown on the robot (Req 5.5). The resident
     * is not moved this turn; the actual room transfer happens when the engine
     * decrements the flag to zero on the second turn. Setting the countdown is
     * the executor's only job here.
     */
    private function executeSupport(Action $action, WorldModel $world): array
    {
        $robot = $this->robot($action, $world);
        $robot->supportTurnsRemaining = self::SUPPORT_TURNS;
        // Remember where the resident is to be taken so the Engine can carry
        // out the move when the countdown reaches zero (Req 7.13, Req 7.15).
        $robot->supportDestination = $action->targetSpot;

        return [
            'You began to support the man.',
            'The other robot began to support the man.',
        ];
    }

    /**
     * hand_over: give the held object to the resident (Req 5.6, Req 5.11). The
     * executor re-checks the two guards the evaluator already enforces so a
     * stale or hand-built judgement cannot half-mutate the world: the robot
     * must hold an object, and the resident must share the robot's room. Either
     * guard failing throws, which execute() turns into a rolled-back failure
     * Outcome (Req 5.11).
     *
     * On success the robot's held slot is cleared and the object is assigned to
     * the resident (its room set to the resident id, mirroring how `take`
     * parks a held object on the robot so the one-room invariant holds). A
     * water container also tops up the resident's water by
     * `$config->waterDeltaHandover`; the final clamp is applied by
     * ResidentController at end of turn.
     */
    private function executeHandOver(Action $action, WorldModel $world, EpisodeConfig $config): array
    {
        $robot = $this->robot($action, $world);

        if ($robot->heldObjectId === null) {
            throw new \LogicException('Robot holds no object to hand over.');
        }

        $resident = $world->residentState;
        if ($resident->room !== $robot->room) {
            throw new \LogicException('Resident is not in the same room.');
        }

        $object = $world->objects[$robot->heldObjectId] ?? null;
        if ($object === null) {
            throw new \InvalidArgumentException("Unknown held object id: {$robot->heldObjectId}");
        }

        $wasLitLight = $object->isLightSource && $object->on;

        $robot->heldObjectId = null;
        // Park the object on the resident, mirroring how `take` parks a held
        // object on the robot, so it stays in exactly one place.
        $object->room = self::RESIDENT_ID;
        $object->spot = null;

        if ($object->isWaterContainer) {
            // The end-of-turn ResidentController clamps water to its range.
            $resident->water += $config->waterDeltaHandover;
        }

        // A lit light handed to the resident now lights the resident's room
        // (design §f/§g, Req 2.5).
        if ($wasLitLight) {
            EarthquakePhase::recomputeRoomLightLevel($world, $resident->room);
        }

        return [
            "You handed {$object->displayName} to the man.",
            "The other robot handed {$object->displayName} to the man.",
        ];
    }

    /**
     * Whether the given robot is currently holding a lit light source. Used by
     * `move` to decide whether a carried light must follow the robot between
     * rooms (design §f/§g, Req 2.5).
     */
    private function holdsLitLightSource(RobotState $robot, WorldModel $world): bool
    {
        if ($robot->heldObjectId === null) {
            return false;
        }

        $held = $world->objects[$robot->heldObjectId] ?? null;

        return $held !== null && $held->isLightSource && $held->on;
    }

    /**
     * The real room key a light source should recompute when it is switched on.
     * A light standing in a room returns that room; a held light parks its
     * `room` on the holder's id, so this resolves to the holder's current room
     * (a robot or the resident) instead of the agent id (Req 2.5, Req 4.6).
     */
    private function lightSourceRoom(ObjectState $object, WorldModel $world): string
    {
        // Standing in a real room: light that room directly.
        if (array_key_exists($object->room, $world->rooms)) {
            return $object->room;
        }

        // Held by a robot: the robot's current room.
        $holder = $world->robotStates[$object->room] ?? null;
        if ($holder !== null) {
            return $holder->room;
        }

        // Held by the resident (`hand_over` parks room == resident id).
        if ($object->room === self::RESIDENT_ID) {
            return $world->residentState->room;
        }

        // Fallback: treat the stored value as a room key so getRoom() surfaces
        // a clear error rather than silently mis-lighting.
        return $object->room;
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
