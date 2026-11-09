<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop;

use PoltergeistSwitch\Engine\Enums\Disaster;
use PoltergeistSwitch\Engine\WorldModel;

/**
 * The screens spec's per-turn World_State serializer (Requirements 10.3, 10.6).
 *
 * A pure mapper between the engine's `WorldModel` and the plain World_State
 * array the House_Renderer and Act_Out consume (the JSON shape in the screens
 * design, Components §3). It performs no I/O: it only reads public engine
 * state and returns an array / a JSON string. The written JSON is stored
 * verbatim and read back verbatim, so `json_decode($json, true)` round-trips
 * to the exact same array `toArray()` produced (Requirement 10.6).
 *
 * PHP 8.2 only; no I/O, no mutation of the world.
 */
class WorldStateSerializer
{
    /**
     * Map a `WorldModel` to the World_State array, reading only public engine
     * state. The shape matches the screens design's "World_State the renderer
     * consumes" section exactly, with no field added, dropped, or altered
     * (Requirement 10.3).
     *
     * @return array<string,mixed>
     */
    public function toArray(WorldModel $w): array
    {
        $robots = [];
        foreach (['robot_a', 'robot_b'] as $robotId) {
            $robot = $w->robotStates[$robotId];
            $robots[$robotId] = [
                'room' => $robot->room,
                'spot' => $robot->spot,
                'held' => $robot->heldObjectId,
            ];
        }

        $rooms = [];
        foreach ($w->rooms as $key => $room) {
            $rooms[$key] = [
                'light' => $room->lightLevel->value,
            ];
        }

        // The registration/iteration order of $w->objects is the fixed room
        // order (Req 1.6), so a straight foreach preserves it. array_values
        // re-indexes so json_encode emits a JSON array, not an object.
        $broken = [];
        $fallen = [];
        foreach ($w->objects as $object) {
            if ($object->broken) {
                $broken[] = $object->id;
            }
            if ($object->fallen) {
                $fallen[] = $object->id;
            }
        }

        $man = $w->residentState;
        $house = $w->houseState;

        $state = [
            'timeOfDay' => $house->timeOfDay,
            'power'     => $house->power->value,
            'shaking'   => $house->shaking,
            'robots'    => $robots,
            'man' => [
                'room'     => $man->room,
                'spot'     => $man->spot,
                'activity' => $man->activity,
                'bodyTemp' => $man->bodyTemp,
                'water'    => $man->water,
                'injury'   => $man->injury,
                'fear'     => $man->fear,
                'weakened' => $man->weakened,
                'covered'  => $man->coverFlag,
            ],
            'rooms'   => $rooms,
            'objects' => [
                'broken' => array_values($broken),
                'fallen' => array_values($fallen),
            ],
            'stove' => [
                'on' => $w->objects['gas_stove']->on,
            ],
        ];

        // Heat-only additions (heat-wave Requirement 1.8, 4.6). These keys are
        // appended ONLY for a heat-wave world; for an earthquake world the
        // array above is returned unchanged, so `GET /api/episodes/{id}` for an
        // existing earthquake episode is byte-identical to before this change.
        if ($w->disaster === Disaster::heat_wave) {
            $roomTemps = [];
            $windows = [];
            foreach ($w->rooms as $key => $room) {
                $roomTemps[$key] = $room->temperature;
                $windows[$key] = $room->windowOpen;
            }

            $state['disaster']  = 'heat_wave';
            $state['roomTemps'] = $roomTemps;
            $state['outside']   = $house->outsideTemperature;
            $state['ac']        = ['on' => $w->acOn()];
            $state['fan']       = ['on' => $w->fanOn(), 'room' => $w->fanRoom()];
            $state['windows']   = $windows;
        }

        return $state;
    }

    /**
     * Serialise the World_State to a JSON string. `JSON_UNESCAPED_UNICODE`
     * lets any Japanese survive verbatim; `JSON_PRESERVE_ZERO_FRACTION` keeps
     * a whole-number float (e.g. the man's `bodyTemp` of 36.0) encoded as
     * `36.0` rather than `36`, so it reads back as a float and not an int.
     * Together they make the stored string round-trip: `json_decode(toJson($w),
     * true)` equals `toArray($w)` with every field's value and type preserved
     * (Requirement 10.6).
     */
    public function toJson(WorldModel $w): string
    {
        return json_encode(
            $this->toArray($w),
            JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
    }
}
