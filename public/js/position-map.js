/*
 * position-map.js — Position_Map pure function (Screens spec, Requirement 2).
 *
 * A single pure, dependency-free function mapping an engine Room_Key, a Spot,
 * a Man_Activity, and the man's current position to a canvas position for a
 * character on the 480x236 house cross-section.
 *
 * Contract (design.md Components §2):
 *   positionMap(roomKey, spot, manActivity, manPos)
 *     -> { ok: true, x, y }  with 0 <= x <= 479 and 0 <= y <= 235
 *      | { ok: false, reason: 'unrecognised' }
 *
 * Requirements covered: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.7, 2.8, 2.9, 2.10, 1.7.
 *
 * EVERY coordinate below is transcribed verbatim from the design's Position_Map
 * tables (design.md Components §2) and the room-band table (§1). No coordinate
 * is invented here; each value matches a documented cell.
 *
 * No build step on the server: this file is plain browser JS. It attaches
 * `positionMap` to the browser global when a window exists, and also exports it
 * via CommonJS when a module system exists (so a future headless check can
 * require it). It depends on nothing.
 */
(function () {
  'use strict';

  // Canvas area bounds (design: 480x236). Returned coordinates are clamped here.
  var MAX_X = 479;
  var MAX_Y = 235;

  // Foot line for standing characters (design: FY = 196).
  var FY = 196;

  // Room drawn x0..x1 bands (design §1 room-band table). Used for Req 2.8/2.10.
  var ROOM_BANDS = {
    entrance:    { x0: 27,  x1: 83 },
    bedroom:     { x0: 86,  x1: 158 },
    living_room: { x0: 161, x1: 257 },
    kitchen:     { x0: 260, x1: 344 },
    bathroom:    { x0: 347, x1: 399 },
    storeroom:   { x0: 402, x1: 454 }
  };

  // Per-room default standing place, no spot (design §2 "default standing" table).
  var ROOM_DEFAULT = {
    entrance:    { x: 55,  y: 196 },
    bedroom:     { x: 122, y: 196 },
    living_room: { x: 209, y: 196 },
    kitchen:     { x: 302, y: 196 },
    bathroom:    { x: 373, y: 196 },
    storeroom:   { x: 428, y: 196 }
  };

  // The `window` spot resolves per-room (design §2 "fixed position" table);
  // valid only in the five rooms with a window fixture (storeroom has none).
  var WINDOW_BY_ROOM = {
    entrance:    { x: 55,  y: 124 },
    bedroom:     { x: 122, y: 124 },
    living_room: { x: 214, y: 124 },
    kitchen:     { x: 302, y: 124 },
    bathroom:    { x: 373, y: 124 }
  };

  // Fixed spots tied to a single room (design §2 "fixed position" table).
  // Each entry records the room it belongs to and its fixed (x, y).
  var FIXED_SPOTS = {
    gas_stove:    { room: 'kitchen',     x: 294, y: 196 },
    under_table:  { room: 'living_room', x: 201, y: 196 },
    beside_futon: { room: 'bedroom',     x: 118, y: 196 },
    low_table:    { room: 'living_room', x: 201, y: 196 },
    futon:        { room: 'bedroom',     x: 106, y: 196 },
    bathtub:      { room: 'bathroom',    x: 368, y: 196 }
  };

  // Man_Activity drawn positions (design §2 "Man_Activity" table).
  // `standing` has no fixed point: it resolves to the room's default standing
  // place, so it is handled in code rather than listed here.
  var ACTIVITY_POS = {
    sitting_at_table: { x: 201, y: 196 },
    asleep_in_futon:  { x: 106, y: 196 },
    bathing:          { x: 368, y: 196 },
    cooking:          { x: 290, y: 196 }
  };

  // The recognised Man_Activity set (includes `standing`, handled specially).
  var KNOWN_ACTIVITIES = {
    sitting_at_table: true,
    asleep_in_futon: true,
    bathing: true,
    cooking: true,
    standing: true
  };

  // Fixed dx used to place a character next to the man (Requirement 2.4).
  var NEAR_RESIDENT_DX = 14;

  function clamp(value, min, max) {
    if (value < min) { return min; }
    if (value > max) { return max; }
    return value;
  }

  function ok(x, y) {
    return { ok: true, x: clamp(x, 0, MAX_X), y: clamp(y, 0, MAX_Y) };
  }

  function unrecognised() {
    return { ok: false, reason: 'unrecognised' };
  }

  /**
   * positionMap — see header contract.
   *
   * @param {string} roomKey      one of the six Room_Keys
   * @param {?string} spot         a Spot name, or null for the room default
   * @param {?string} manActivity  a Man_Activity, or null
   * @param {?{x:number,y:number}} manPos  the man's current position, or null
   * @returns {{ok:true,x:number,y:number}|{ok:false,reason:string}}
   */
  function positionMap(roomKey, spot, manActivity, manPos) {
    // Unrecognised Man_Activity is an error (Requirement 2.9), even if the
    // room is valid. `standing` and null/absent activities are acceptable.
    if (manActivity !== null && manActivity !== undefined &&
        !KNOWN_ACTIVITIES[manActivity]) {
      return unrecognised();
    }

    // Unrecognised Room_Key is an error (Requirement 2.9).
    if (!ROOM_BANDS.hasOwnProperty(roomKey)) {
      return unrecognised();
    }

    var band = ROOM_BANDS[roomKey];
    var def = ROOM_DEFAULT[roomKey];

    // near_resident / resident: next to the man, not a fixed point (Req 2.4).
    // When manPos is absent, fall back to the room's default standing place so
    // the result is always in-bounds and the character is never omitted
    // (Requirement 2.9 never out-of-area; Requirement 1.7 never omitted).
    if (spot === 'near_resident' || spot === 'resident') {
      if (manPos && typeof manPos.x === 'number' && typeof manPos.y === 'number') {
        return ok(manPos.x + NEAR_RESIDENT_DX, manPos.y);
      }
      return ok(def.x, def.y);
    }

    // No spot: the room's default standing place (Requirement 2.7).
    if (spot === null || spot === undefined) {
      return ok(def.x, def.y);
    }

    // window: valid only in the five rooms that have a window fixture.
    // A window spot in a room without one (e.g. storeroom) is an unknown spot
    // for a known room and falls through to that room's default (Req 1.7).
    if (spot === 'window') {
      if (WINDOW_BY_ROOM.hasOwnProperty(roomKey)) {
        var w = WINDOW_BY_ROOM[roomKey];
        return ok(w.x, w.y);
      }
      return ok(def.x, def.y);
    }

    // Fixed spots tied to a specific room. If the given room matches the spot's
    // room, use the fixed position. If not, the spot is unknown for this room
    // and falls through to the room default (Requirement 1.7).
    if (FIXED_SPOTS.hasOwnProperty(spot)) {
      var fixed = FIXED_SPOTS[spot];
      if (fixed.room === roomKey) {
        return ok(fixed.x, fixed.y);
      }
      return ok(def.x, def.y);
    }

    // Unknown spot with a known Room_Key is NOT an error: resolve to the room's
    // default standing place and return ok (Requirement 1.7).
    return ok(def.x, def.y);
  }

  // Expose as a browser global (guarded) and via CommonJS (guarded), so the
  // same file works in the browser with no build step and can be required by a
  // future headless check.
  if (typeof window !== 'undefined') {
    window.positionMap = positionMap;
  }
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = positionMap;
  }
})();
