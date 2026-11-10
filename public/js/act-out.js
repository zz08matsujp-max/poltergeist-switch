/*
 * act-out.js — the Act_Out driver (Screens spec, Requirement 3; also 9.12,
 * 1.7, 12.6).
 *
 * Acts out ONE turn for BOTH public screens (Observe and Replay) using the one
 * shared House_Renderer. It reads the turn's `RobotFourLayers.reading` for each
 * robot plus the man's `residentLine`, animates the single physical Operation
 * each robot took, shows the spoken lines in the right bubbles, and then —
 * always, however the turn was acted out — ends the picture in the turn's
 * recorded World_State by calling `houseRenderer.setWorld(worldState)` (Req
 * 3.8, 3.9).
 *
 * The animation TARGET comes from the pure Position_Map (window.positionMap),
 * exactly as the renderer resolves placement, so an unknown spot with a known
 * room animates toward the room default rather than erroring (Req 3.1, 1.7).
 *
 * This module drives the renderer only through its public controller interface
 * (moveCharacter / setPose / setHeld / showBubble / setWorld). It never reads
 * engine internals and never draws. Spoken lines are not shown here: the
 * speech sequencer passed as the third argument shows and reads them one at a
 * time (public-lab Req 11); `voices` is kept only for signature compatibility.
 *
 * No build step on the server: plain browser JS. It attaches `createActOut`
 * (and `ActOut`) to the browser global when a window exists, and exports via
 * CommonJS when a module system exists (so a headless check can require it),
 * matching position-map.js and house-renderer.js.
 */
(function () {
  'use strict';

  // ── logical canvas size (must match house-renderer.js) ───────────────────
  var W = 480;
  var H = 236;
  var FY = 196; // foot line for standing characters

  // Renderer-boundary ids. The stored data speaks robot_a/robot_b; the renderer
  // takes amber/jade/man. Translate here so the on-screen names stay Amber and
  // Jade (Req 12.8) and the renderer boundary is honoured.
  var RENDER_ID = { robot_a: 'amber', robot_b: 'jade' };

  // ── timing constants (Req 3.2, 3.5) ──────────────────────────────────────
  // A normal walk / action should complete before the turn ends; an impossible
  // attempt lasts between 0.5 s and 2.0 s and stops before completing; a bubble
  // stays up for at least 2.0 s or until the acting-out ends, whichever is
  // longer.
  var ATTEMPT_MIN_MS = 500;   // Req 3.2 lower bound
  var ATTEMPT_MAX_MS = 2000;  // Req 3.2 upper bound
  var ATTEMPT_MS = 1200;      // the attempt we actually play (within 0.5–2.0 s)
  var NORMAL_ACTION_MS = 2200; // a normal physical action's animation window
  var BUBBLE_MIN_MS = 2000;   // Req 3.5 minimum bubble lifetime
  // Reduced motion collapses everything to a single short jump (< 100 ms).
  var REDUCED_MS = 60;

  // linesOfTurn comes from speech-sequencer.js (window global in the browser,
  // CommonJS under node).
  function linesOfTurn(turn) {
    var fn = (typeof window !== 'undefined' && typeof window.linesOfTurn === 'function')
      ? window.linesOfTurn
      : null;
    if (!fn && typeof require === 'function') {
      try { fn = require('./speech-sequencer.js').linesOfTurn; } catch (e) { fn = null; }
    }
    return fn ? fn(turn) : [];
  }

  function perfNow() {
    return (typeof performance !== 'undefined' && performance.now)
      ? performance.now()
      : Date.now();
  }

  function prefersReducedMotion() {
    return (typeof window !== 'undefined' && window.matchMedia)
      ? window.matchMedia('(prefers-reduced-motion: reduce)').matches
      : false;
  }

  function clamp(v, lo, hi) {
    if (v < lo) { return lo; }
    if (v > hi) { return hi; }
    return v;
  }

  // Resolve a (roomKey, spot) to a canvas target the SAME way the renderer
  // does: through the pure Position_Map. An unknown spot with a known room
  // falls through to the room default inside positionMap, and an unrecognised
  // room is retried as a bare room default; anything still unresolved falls
  // back to the canvas centre so a character is never omitted (Req 1.7).
  function resolveTarget(roomKey, spot, manPos) {
    var pm = (typeof window !== 'undefined' && window.positionMap)
      ? window.positionMap
      : (typeof positionMap !== 'undefined' ? positionMap : null);
    if (!pm || !roomKey) { return null; }
    var r = pm(roomKey, spot || null, null, manPos || null);
    if (r && r.ok) { return { x: r.x, y: r.y }; }
    var d = pm(roomKey, null, null, null);
    if (d && d.ok) { return { x: d.x, y: d.y }; }
    return null;
  }

  // The man's current canvas position from the recorded world, used as the
  // anchor for near_resident / support / cover targets.
  function manPosFromWorld(worldState) {
    var man = (worldState && worldState.man) || {};
    var t = resolveTarget(man.room || 'living_room', man.spot || null, null);
    return t || { x: Math.floor(W / 2), y: FY };
  }

  // Pull one robot's reading out of a turn, tolerating either the serialized
  // shape (`turn.robots.robot_a.reading` with words at `reading.words`) or a
  // nested `reading.action` shape. Returns a normalised view or null.
  function readingOf(turn, robotKey) {
    if (!turn || !turn.robots) { return null; }
    var layers = turn.robots[robotKey];
    if (!layers) { return null; }
    var reading = layers.reading || {};
    // words/operation/target may sit directly on `reading` (serialized API
    // shape) or under `reading.action`; read whichever is present.
    var act = reading.action || reading;
    var words = act.words || reading.words || [];
    var judgement = layers.judgement || {};
    return {
      operation: act.operation || reading.operation || null,
      targetId: act.targetId || reading.targetId || null,
      targetSpot: act.targetSpot || reading.targetSpot || null,
      words: Array.isArray(words) ? words : [],
      unknown: !!(reading.unknown || layers.noReply),
      noReply: !!layers.noReply,
      possible: judgement.possible === true,
      hasJudgement: Object.prototype.hasOwnProperty.call(layers, 'judgement')
    };
  }

  function createActOut(houseRenderer, voices, sequencer) {
    if (!houseRenderer) { throw new Error('createActOut needs a houseRenderer'); }

    // Everything cancelable lives here so cancel() can clear it and still end
    // in the recorded world.
    var timers = [];
    var activeToken = 0;          // increments per playTurn; stale callbacks exit
    var activeResolve = null;     // resolver of the in-flight playTurn promise
    var activeWorld = null;       // the recorded world the in-flight turn ends in

    function clearTimers() {
      for (var i = 0; i < timers.length; i++) {
        try { clearTimeout(timers[i]); } catch (e) { /* ignore */ }
      }
      timers = [];
    }

    function later(fn, ms) {
      var id = setTimeout(fn, ms);
      timers.push(id);
      return id;
    }

    // Animate one robot's single physical Operation toward its Position_Map
    // target. Returns the ms this animation should occupy (so the turn length
    // can cover it). Impossible → a short attempt that stops before completing
    // (Req 3.2). Unknown / no-reply → idle (Req 3.3). Nothing here changes the
    // final world; setWorld at the end is authoritative (Req 3.7, 3.8).
    function animateRobot(robotKey, reading, worldState, reduced) {
      var renderId = RENDER_ID[robotKey];
      if (!renderId || !reading) { return 0; }

      // Unknown reading (incl. a no-reply robot): keep that robot idle (Req 3.3).
      if (reading.unknown || reading.operation === 'unknown' || !reading.operation) {
        return 0;
      }

      var op = reading.operation;

      // speak / wait: no motion (the bubbles carry the speech).
      if (op === 'speak' || op === 'wait') { return 0; }

      var manPos = manPosFromWorld(worldState);
      var target = null;

      if (op === 'support' || op === 'cover') {
        // Help or shield the man: move to the man (the authoritative final
        // positions still come from the recorded world at the end).
        target = reading.targetId || reading.targetSpot
          ? resolveTarget(
              (worldState && worldState.man && worldState.man.room) || 'living_room',
              reading.targetSpot || null, manPos)
          : manPos;
        if (op === 'cover') {
          // crouch near the man / self while shielding.
          try { houseRenderer.setPose(renderId, 'crouch'); } catch (e) { /* ignore */ }
        }
      } else {
        // move / take / hand_over / use: resolve the stated target. targetId is
        // treated as a spot name for the Position_Map (fixed spots like
        // gas_stove / low_table / futon are spot names); the room comes from the
        // robot's recorded room so an unknown spot falls to that room's default.
        var robots = (worldState && worldState.robots) || {};
        var rob = robots[robotKey] || {};
        var roomKey = rob.room || 'living_room';
        var spot = reading.targetSpot || reading.targetId || null;
        target = resolveTarget(roomKey, spot, manPos);
      }

      // Impossible action: a short attempt that stops BEFORE completion
      // (Req 3.2). Move only part way toward the target, then the end-of-turn
      // setWorld snaps everyone to the recorded world.
      if (reading.hasJudgement && !reading.possible) {
        if (target) {
          var cur = currentX(renderId);
          var partial = cur + (target.x - cur) * 0.4; // stop ~40% in
          try {
            houseRenderer.moveCharacter(renderId, { x: clamp(partial, 0, W - 1), y: target.y });
          } catch (e) { /* ignore */ }
        }
        // the attempt window stays within 0.5–2.0 s (Req 3.2).
        return reduced ? 0 : clamp(ATTEMPT_MS, ATTEMPT_MIN_MS, ATTEMPT_MAX_MS);
      }

      // Possible action: walk to the target. `take` shows the object held once
      // arrived; `hand_over` drops the held object; `use` / `support` / `cover`
      // reflect their final state through the recorded world at the end.
      if (target) {
        try { houseRenderer.moveCharacter(renderId, target); } catch (e) { /* ignore */ }
      }

      if (op === 'take') {
        // show the taken object held (reflected authoritatively by setWorld at
        // the end; this mid-turn cue keeps the motion legible).
        scheduleHeld(renderId, reading.targetId || 'object', reduced);
      } else if (op === 'hand_over') {
        // drop the held object once near the other robot.
        scheduleHeld(renderId, null, reduced);
      }

      return reduced ? 0 : NORMAL_ACTION_MS;
    }

    // Read a character's current canvas x from the renderer, if exposed.
    function currentX(renderId) {
      try {
        if (typeof houseRenderer._state === 'function') {
          var st = houseRenderer._state();
          if (st && st.A && st.A[renderId]) { return st.A[renderId].x; }
        }
      } catch (e) { /* ignore */ }
      return Math.floor(W / 2);
    }

    // Set/clear a held object, immediately under reduced motion, otherwise part
    // way through the walk. Guarded by the active token so a cancel stops it.
    function scheduleHeld(renderId, objectId, reduced) {
      var token = activeToken;
      var apply = function () {
        if (token !== activeToken) { return; }
        try { houseRenderer.setHeld(renderId, objectId); } catch (e) { /* ignore */ }
      };
      if (reduced) { apply(); } else { later(apply, Math.floor(NORMAL_ACTION_MS * 0.6)); }
    }

    // Finish the in-flight turn: ALWAYS snap to the recorded world (Req 3.8,
    // 3.9), clear any crouch/attempt poses by virtue of setWorld resetting the
    // robots to idle, then resolve the promise so the run loop can advance.
    function finishActive() {
      var resolve = activeResolve;
      var world = activeWorld;
      activeResolve = null;
      activeWorld = null;
      clearTimers();
      if (world !== null && world !== undefined) {
        try { houseRenderer.setWorld(world); } catch (e) { /* ignore */ }
      }
      if (typeof resolve === 'function') { resolve(); }
    }

    var api = {
      /**
       * playTurn(turn, worldState) — act out one turn, then end in worldState.
       *
       * @param {object} turn       a TurnView: { robots: { robot_a, robot_b },
       *                            residentLine }, each robot carrying
       *                            { reading: { operation, targetId, targetSpot,
       *                              words|action.words, unknown }, noReply,
       *                              judgement: { possible } }.
       * @param {object} worldState the turn's recorded World_State (the shape
       *                            setWorld consumes). The picture ALWAYS ends
       *                            here, even for impossible/unknown actions and
       *                            on cancel (Req 3.8, 3.9).
       * @returns {Promise} resolves once the acting-out has finished and the
       *                    recorded world has been applied, so the caller's run
       *                    loop advances only when acting-out is done.
       */
      playTurn: function (turn, worldState) {
        // A new turn supersedes any in-flight one: finish the old one into its
        // recorded world first (keeps every turn ending in its world) before
        // starting fresh.
        if (activeResolve) { finishActive(); }

        var reduced = prefersReducedMotion();
        activeToken++;
        activeWorld = (worldState === undefined) ? null : worldState;

        // Speech is the sequencer's job: one line at a time, bubble visible
        // while read (Req 11.1, 11.5). It runs alongside the animation.
        var speech = Promise.resolve();
        if (sequencer && typeof sequencer.play === 'function') {
          try {
            speech = Promise.resolve(sequencer.play(linesOfTurn(turn)));
          } catch (e) { speech = Promise.resolve(); }
        }

        var animation = new Promise(function (resolve) {
          activeResolve = resolve;

          // Reduced motion: no walking/attempt frames — jump straight to the
          // recorded world within 100 ms (Req 12.6). Bubbles still show.
          if (reduced) {
            later(finishActive, REDUCED_MS);
            return;
          }

          // Animate both robots' single physical actions. The turn length is
          // the longest of the two animations (a walk, a short impossible
          // attempt, or idle), floored so there is always a beat.
          var aDur = animateRobot('robot_a', readingOf(turn, 'robot_a'), worldState, false);
          var bDur = animateRobot('robot_b', readingOf(turn, 'robot_b'), worldState, false);
          var actMs = Math.max(aDur, bDur, 300);

          // End the turn once the acting-out window has elapsed, ALWAYS snapping
          // to the recorded world (Req 3.8, 3.9).
          later(finishActive, actMs);
        });

        // Resolve after both the animation (ended in the recorded world) and
        // the speech are done (Req 11.5, 11.16).
        return Promise.all([animation, speech]).then(function () {});
      },

      /**
       * cancel() — stop the in-flight acting-out at once. It still ends in the
       * recorded world (Req 3.9): the current turn is snapped to its recorded
       * World_State and its promise resolved, so a caller awaiting playTurn is
       * never left hanging and the picture is never left mid-animation.
       */
      cancel: function () {
        if (activeResolve) { finishActive(); }
        else { clearTimers(); }
        if (sequencer && typeof sequencer.cancel === 'function') {
          try { sequencer.cancel(); } catch (e) { /* ignore */ }
        }
      }
    };

    return api;
  }

  // Class-style wrapper for callers that prefer `new`, mirroring house-renderer.
  function ActOut(houseRenderer, voices, sequencer) {
    var impl = createActOut(houseRenderer, voices, sequencer);
    var self = this;
    Object.keys(impl).forEach(function (k) { self[k] = impl[k]; });
  }

  if (typeof window !== 'undefined') {
    window.createActOut = createActOut;
    window.ActOut = ActOut;
  }
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = { createActOut: createActOut, ActOut: ActOut };
  }
})();
