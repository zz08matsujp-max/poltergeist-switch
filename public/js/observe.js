/*
 * observe.js — Observe_Screen controller (page public/index.php).
 *
 * THIS TASK (9.2) is the LAYOUT + SETUP portion only:
 *   - construct the API client, House_Renderer (mounted to the stage), Voices,
 *     and the Act_Out driver, and keep references for the run loop;
 *   - wire the UI setup: the Voices on/off toggle, the inert disabled Heavy
 *     rain / Heat wave switches, and the first-load state (no episode in
 *     progress, Earthquake lever OFF, empty log, empty meters) (Req 4.5);
 *   - provide the helpers the run loop will call: updateMeters(man),
 *     appendLog(turnNumber, entries), setEarthquakeLever(on);
 *   - leave clearly-marked STUBS for the run loop (9.3) and the End_Card / 409
 *     handling (9.4): startRun(), pause(), step(), resume(). This file does NOT
 *     create an episode or call any API on load, and does NOT implement the
 *     ask/apply loop (that is 9.3).
 *
 * The two robots are named only Amber and Jade in any text this file produces
 * (Req 12.8). The provider/model names live only in the static page chrome in
 * index.php; nothing here names a company.
 *
 * No build step: plain browser JS. Depends on the window globals set by
 * position-map.js, house-renderer.js, act-out.js, voices.js and api.js, which
 * index.php loads before this file.
 */
(function () {
  'use strict';

  // ── module-level references the run loop (9.3) and End_Card (9.4) use ──────
  var api = null;          // createApiClient()
  var house = null;        // createHouseRenderer()
  var voices = null;       // createVoices()
  var actOut = null;       // createActOut(house, voices)

  // DOM handles, looked up once on DOMContentLoaded.
  var el = {};

  // Live-episode state the run loop (9.3) owns. Declared here so the setup and
  // the stubs share one shape; 9.3 fills in the loop that mutates it.
  var state = {
    episodeId: null,       // current episode id, or null when none in progress
    expectedTurn: 0,       // the next expected turn number
    running: false,        // the loop is advancing turns
    paused: false,         // Pause was pressed
    ended: false           // apply reported the episode ended
  };

  // ── helpers the run loop (9.3) calls ───────────────────────────────────────

  /**
   * updateMeters(man) — reflect the man's recorded state in the four meters
   * (temperature, water, injury, fear) (Req 4.10, 5.10). `man` is the `man`
   * object from a turn's World_State: { bodyTemp, water, injury, fear, ... }.
   * Each meter's fill width is a percentage and the numeric value is shown.
   * Called by 9.3 on each apply; safe to call with a partial object.
   */
  function updateMeters(man) {
    man = man || {};
    // Temperature: the man's safe band is ~35–39 °C; map 34–42 onto 0–100% for
    // the bar while showing the raw value. Water/injury/fear are already 0–100.
    setMeter('temp', man.bodyTemp, formatTemp(man.bodyTemp), tempPercent(man.bodyTemp));
    setMeter('water', man.water, numOrDash(man.water), clampPct(man.water));
    setMeter('injury', man.injury, numOrDash(man.injury), clampPct(man.injury));
    setMeter('fear', man.fear, numOrDash(man.fear), clampPct(man.fear));
  }

  /**
   * appendLog(turnNumber, entries) — add one turn's entries to the "What
   * happened" log, grouped under that turn and ordered first -> most recent
   * (Req 4.10, 5.10). A turn's group is created once and reused if more entries
   * arrive for it. `entries` is an array of { who, text } where `who` is
   * 'amber' | 'jade' | 'man' (or any label); Amber/Jade get their colour via
   * the .who / .who.jade classes. The log region is aria-live=polite so each
   * new entry is announced (Req 12.5).
   */
  function appendLog(turnNumber, entries) {
    if (!el.log) { return; }
    var groupId = 'log-turn-' + String(turnNumber);
    var group = document.getElementById(groupId);
    if (!group) {
      group = document.createElement('div');
      group.className = 'turn';
      group.id = groupId;
      var h = document.createElement('h3');
      h.textContent = 'Turn ' + String(turnNumber);
      group.appendChild(h);
      // Append so turns stay in first -> most-recent order.
      el.log.appendChild(group);
    }
    (entries || []).forEach(function (entry) {
      if (!entry) { return; }
      var row = document.createElement('div');
      row.className = 'entry';
      if (entry.who) {
        var who = document.createElement('span');
        who.className = 'who' + (entry.who === 'jade' ? ' jade' : '');
        who.textContent = labelFor(entry.who) + ': ';
        row.appendChild(who);
      }
      row.appendChild(document.createTextNode(entry.text || ''));
      group.appendChild(row);
    });
    // Keep the newest turn in view.
    try { el.log.scrollTop = el.log.scrollHeight; } catch (e) { /* ignore */ }
  }

  /**
   * setEarthquakeLever(on) — reflect the Earthquake breaker lever ON/OFF.
   * Lever-up = ON (the .on class raises the knob per the CSS), lever-down =
   * OFF (Req 4.3). First-load state is OFF (Req 4.5). The run loop (9.3) calls
   * this when it flips the earthquake; task 9.2 sets it OFF on load.
   */
  function setEarthquakeLever(on) {
    var sw = el.swEarthquake;
    if (!sw) { return; }
    if (on) {
      sw.classList.add('on');
      sw.setAttribute('aria-pressed', 'true');
    } else {
      sw.classList.remove('on');
      sw.setAttribute('aria-pressed', 'false');
    }
    var mark = sw.querySelector('.mark');
    if (mark) { mark.textContent = on ? 'ON' : 'OFF'; }
  }

  // ── small formatting helpers for the meters ────────────────────────────────

  function setMeter(key, raw, valueText, percent) {
    var meter = el['meter_' + key];
    if (!meter) { return; }
    var fill = meter.querySelector('.fill');
    var val = meter.querySelector('.val');
    if (fill) { fill.style.width = (raw === undefined || raw === null) ? '0%' : (percent + '%'); }
    if (val) { val.textContent = valueText; }
  }

  function numOrDash(v) {
    return (typeof v === 'number' && isFinite(v)) ? String(Math.round(v)) : '\u2014';
  }

  function formatTemp(v) {
    return (typeof v === 'number' && isFinite(v)) ? (v.toFixed(1) + '\u00b0') : '\u2014';
  }

  function clampPct(v) {
    if (typeof v !== 'number' || !isFinite(v)) { return 0; }
    if (v < 0) { return 0; }
    if (v > 100) { return 100; }
    return v;
  }

  // Map body temperature (~34–42 °C) onto 0–100% for the bar width only.
  function tempPercent(v) {
    if (typeof v !== 'number' || !isFinite(v)) { return 0; }
    var pct = ((v - 34) / (42 - 34)) * 100;
    return clampPct(pct);
  }

  function labelFor(who) {
    if (who === 'amber') { return 'Amber'; }
    if (who === 'jade') { return 'Jade'; }
    if (who === 'man') { return 'The man'; }
    return String(who);
  }

  // Reset the four meters to their empty first-load look (Req 4.5).
  function clearMeters() {
    ['temp', 'water', 'injury', 'fear'].forEach(function (key) {
      setMeter(key, null, '\u2014', 0);
    });
  }

  // Clear the log to empty (Req 4.5).
  function clearLog() {
    if (el.log) { el.log.textContent = ''; }
  }

  // ── the Observe run loop (task 9.3) ─────────────────────────────────────────
  //
  // The loop drives one earthquake episode turn by turn: for the current
  // expected turn it calls `ask`, then `apply`, then acts out the applied turn,
  // and advances to the next expected turn once the acting-out animation has
  // finished (Req 5.4). As soon as a turn is applied and the episode has NOT
  // ended, the NEXT expected turn's `ask` is sent immediately, overlapping the
  // reply wait with the current turn's animation (Req 5.5). The applied result
  // is whatever `apply` stored; the animation only plays it and never changes
  // it (Req 5.7).
  //
  // Pipelining is implemented with a one-slot cache, `pendingAsk`, keyed by turn
  // number: a Promise of the `ask` result for a turn that has been requested but
  // not yet applied. When the loop reaches a turn it reuses that promise instead
  // of asking again (both `ask` and `apply` are idempotent on the server).

  // The single in-flight prefetched ask: { turn, promise } or null.
  var pendingAsk = null;
  // The acting-out promise for the turn currently animating, so Pause can let it
  // finish before the loop stops (the apply already happened — Req 5.8).
  var actingOut = null;
  // Guards a single run of the loop body so Start/Resume/Step cannot double-run.
  var looping = false;
  // Set by Step: play exactly one more turn, then stop (Req 5.9).
  var stepOnce = false;

  /**
   * startRun() — the Start button. Create one episode (unless one is already in
   * progress), then show the calm turn-0 house from the returned view's turn-0
   * world with NO AI call and NO turns running (Req 4.6, 5.1). Start while an
   * episode is in progress creates no additional episode and leaves it
   * unchanged (Req 4.9).
   */
  function startRun() {
    // Req 4.9: an episode already in progress is left untouched; no new episode.
    if (state.episodeId || state.running) { return; }
    if (!api) { return; }
    clearNotice();
    api.createEpisode().then(function (res) {
      if (res && res.status === 409) {
        // Slot/resume/lab-busy conflict — task 9.4 fleshes this out.
        handleSlotConflict(res.body);
        return;
      }
      if (!res || res.status !== 200 || !res.body) {
        showNotice('The lab could not start an episode. Please try again.');
        return;
      }
      adoptEpisode(res.body);
      // Draw the calm turn-0 world. No AI call, no turns running yet (Req 5.1).
      drawTurnZero(res.body);
    }, function () {
      showNotice('The lab could not be reached. Please try again.');
    });
  }

  /**
   * pause() — stop advancing to new turns AFTER the current turn finishes acting
   * out, leaving the current turn's stored result and any already-sent next
   * `ask` unchanged (Req 5.8). It does not abort a turn mid-apply: the apply has
   * already happened (or will complete) and the loop simply stops before
   * starting the next turn's advance.
   */
  function pause() {
    state.paused = true;
  }

  /**
   * step() — while paused, play exactly one more turn, then stop advancing
   * (Req 5.9). Does nothing when not paused or when there is no live episode.
   */
  function step() {
    if (!state.paused) { return; }
    if (!state.episodeId || state.ended) { return; }
    stepOnce = true;
    runLoop();
  }

  /**
   * resume() — continue advancing from the current expected turn (Req 5.8).
   */
  function resume() {
    if (!state.paused) { return; }
    state.paused = false;
    runLoop();
  }

  // ── run-loop internals ──────────────────────────────────────────────────────

  // Adopt the episode identity from a returned view: id and the next expected
  // turn. Never discards previously recorded turns (Req 7.5 resync lives here).
  function adoptEpisode(view) {
    state.episodeId = view.episodeId;
    state.expectedTurn = (typeof view.currentExpectedTurn === 'number')
      ? view.currentExpectedTurn : 0;
    state.ended = (view.ended === true);
  }

  // Find a turn object by its turn number inside an episode view.
  function findTurn(view, turnNumber) {
    if (!view || !view.turns) { return null; }
    for (var i = 0; i < view.turns.length; i++) {
      if (view.turns[i] && view.turns[i].turnNumber === turnNumber) {
        return view.turns[i];
      }
    }
    return null;
  }

  // Draw the calm turn-0 world from a create/get view (Req 5.1).
  function drawTurnZero(view) {
    var t0 = findTurn(view, 0);
    if (house && t0 && t0.world) {
      try { house.setWorld(t0.world); } catch (e) { /* ignore */ }
    }
  }

  // Flip the earthquake and start the turns. Creates the episode first if none
  // is present (Req 4.7, 4.8, 5.2, 5.3). Raises the lever on a successful flip.
  function flipEarthquakeAndRun() {
    if (!api) { return; }
    if (state.running) { return; }   // already running the turns
    clearNotice();

    if (state.episodeId) {
      doFlip(state.episodeId);
      return;
    }
    // No episode yet: create one first, then flip (Req 4.8, 5.3).
    api.createEpisode().then(function (res) {
      if (res && res.status === 409) { handleSlotConflict(res.body); return; }
      if (!res || res.status !== 200 || !res.body) {
        showNotice('The lab could not start an episode. Please try again.');
        return;
      }
      adoptEpisode(res.body);
      drawTurnZero(res.body);
      doFlip(state.episodeId);
    }, function () {
      showNotice('The lab could not be reached. Please try again.');
    });
  }

  function doFlip(episodeId) {
    api.flipDisaster(episodeId).then(function (res) {
      if (!res || res.status !== 200 || !res.body) {
        showNotice('The earthquake switch did not take. Please try again.');
        return;
      }
      adoptEpisode(res.body);
      setEarthquakeLever(true);
      runLoop();
    }, function () {
      showNotice('The earthquake switch did not take. Please try again.');
    });
  }

  // Show the "thinking" indicator on both robots while their reply is awaited
  // (Req 5.6), and clear it when the reply has arrived.
  function setThinkingBoth(on) {
    if (!house || typeof house.setThinking !== 'function') { return; }
    try { house.setThinking('amber', on); } catch (e) { /* ignore */ }
    try { house.setThinking('jade', on); } catch (e) { /* ignore */ }
  }

  // Ask for a turn, reusing the prefetched ask when it matches (Req 5.5). The
  // returned promise resolves to { status, body }. The thinking indicator is on
  // while the reply is awaited and cleared when it resolves (Req 5.6).
  function askTurn(turnNumber) {
    if (pendingAsk && pendingAsk.turn === turnNumber) {
      var p = pendingAsk.promise;
      pendingAsk = null;
      return p;
    }
    setThinkingBoth(true);
    return api.ask(state.episodeId, turnNumber).then(function (res) {
      setThinkingBoth(false);
      return res;
    }, function (err) {
      setThinkingBoth(false);
      throw err;
    });
  }

  // Kick off the NEXT turn's ask immediately after an apply, so the reply wait
  // overlaps the current turn's acting-out (Req 5.5). Stores it in pendingAsk.
  function prefetchNextAsk(nextTurn) {
    if (pendingAsk && pendingAsk.turn === nextTurn) { return; }
    setThinkingBoth(true);
    var promise = api.ask(state.episodeId, nextTurn).then(function (res) {
      setThinkingBoth(false);
      return res;
    }, function (err) {
      setThinkingBoth(false);
      // Swallow here; the main loop will re-ask and surface any real error.
      return { status: 0, body: null, _error: err };
    });
    pendingAsk = { turn: nextTurn, promise: promise };
  }

  // The main loop. Runs one turn at a time. It is re-entrant-safe: `looping`
  // guards against a second concurrent run (Req: cancelable / no double-run).
  function runLoop() {
    if (looping) { return; }
    if (!state.episodeId || state.ended) { return; }
    if (state.paused && !stepOnce) { return; }

    looping = true;
    state.running = true;
    runOneTurn();
  }

  function stopLoop() {
    looping = false;
    state.running = false;
  }

  function runOneTurn() {
    if (!state.episodeId || state.ended) { stopLoop(); return; }
    // Pause takes effect between turns (after the previous turn's acting-out),
    // unless a single Step was requested (Req 5.8, 5.9).
    if (state.paused && !stepOnce) { stopLoop(); return; }

    var turnNumber = state.expectedTurn;

    askTurn(turnNumber).then(function (askRes) {
      if (handleTurn409(askRes)) { looping = false; runLoop(); return; }
      if (!askRes || askRes.status !== 200) {
        showNotice('A turn could not be asked. Paused.');
        stopLoop();
        return;
      }
      // Apply the turn. The stored result is whatever apply returns; the
      // animation never changes it (Req 5.7).
      return api.apply(state.episodeId, turnNumber).then(function (applyRes) {
        if (handleTurn409(applyRes)) { looping = false; runLoop(); return; }
        if (!applyRes || applyRes.status !== 200 || !applyRes.body) {
          showNotice('A turn could not be applied. Paused.');
          stopLoop();
          return;
        }
        onApplied(applyRes.body, turnNumber);
      });
    }, function () {
      showNotice('The lab could not be reached. Paused.');
      stopLoop();
    });
  }

  // Handle an applied turn: update meters + log, pipeline the next ask, then
  // act out the applied turn and advance when the animation finishes (Req 5.4,
  // 5.5, 5.10, 5.11).
  function onApplied(view, turnNumber) {
    var applied = findTurn(view, turnNumber);
    // Resync the expected turn from the authoritative view, then advance past
    // the turn we just applied.
    state.ended = (view.ended === true);

    // Meters + log from the applied turn (Req 5.10). Independent of animation.
    if (applied) {
      if (applied.world && applied.world.man) { updateMeters(applied.world.man); }
      appendLog(turnNumber, buildLogEntries(applied));
    }

    var episodeEnded = state.ended;

    // Pipeline the NEXT turn's ask immediately when the episode has not ended
    // (Req 5.5). Use the view's expected turn when present, else turnNumber+1.
    var nextTurn = (typeof view.currentExpectedTurn === 'number')
      ? view.currentExpectedTurn : (turnNumber + 1);
    if (!episodeEnded) { prefetchNextAsk(nextTurn); }

    // Act out the applied turn; advance once the acting-out finishes (Req 5.4).
    var animation = (actOut && applied)
      ? actOut.playTurn(applied, applied.world)
      : Promise.resolve();
    actingOut = animation;

    animation.then(function () {
      actingOut = null;

      if (episodeEnded) {
        // Stop advancing; send no further ask/apply (Req 5.11). Hand off to the
        // End_Card seam (task 9.4).
        pendingAsk = null;
        stopLoop();
        onEpisodeEnded(view);
        return;
      }

      // Advance to the next expected turn.
      state.expectedTurn = nextTurn;

      // Honour a single Step: play exactly one turn, then stop (Req 5.9).
      if (stepOnce) {
        stepOnce = false;
        stopLoop();
        return;
      }
      if (state.paused) { stopLoop(); return; }

      // Continue the loop with the next turn.
      runOneTurn();
    });
  }

  // Build the "What happened" log entries for one applied turn in Amber/Jade
  // terms (Req 5.10). Only the outcome's whatHappenedText and spoken words reach
  // the log — NEVER a robot thought, and NEVER an AI company name (Req 12.8).
  function buildLogEntries(turn) {
    var entries = [];
    pushRobotEntry(entries, turn, 'robot_a', 'amber');
    pushRobotEntry(entries, turn, 'robot_b', 'jade');
    if (turn.residentLine) {
      entries.push({ who: 'man', text: String(turn.residentLine) });
    }
    return entries;
  }

  function pushRobotEntry(entries, turn, robotKey, who) {
    var layers = turn.robots && turn.robots[robotKey];
    if (!layers) { return; }
    // Prefer the rules' outcome text (what actually happened); fall back to the
    // spoken words. Never the reply text or any thought.
    var text = '';
    if (layers.outcome && layers.outcome.whatHappenedText) {
      text = String(layers.outcome.whatHappenedText);
    } else {
      var words = (layers.reading && layers.reading.words) || [];
      if (words && words.length) { text = words.join(' '); }
    }
    if (text) { entries.push({ who: who, text: text }); }
  }

  // A turn call (ask/apply) may 409 with the current expected turn. Resync the
  // local expected turn to that value and continue WITHOUT discarding recorded
  // turns (Req 7.5). Returns true when a 409 was handled.
  function handleTurn409(res) {
    if (!res || res.status !== 409) { return false; }
    var body = res.body || {};
    if (typeof body.currentExpectedTurn === 'number') {
      state.expectedTurn = body.currentExpectedTurn;
      // The prefetched ask (if any) may now be for the wrong turn; drop it.
      pendingAsk = null;
      return true;
    }
    return false;
  }

  // ── seams for task 9.4 (End_Card + slot/resume/lab-busy UI) ─────────────────

  /**
   * onEpisodeEnded(view) — SEAM for task 9.4. Called once, after the final
   * turn's acting-out, when apply reported the episode ended (Req 5.11). Task
   * 9.4 shows the End_Card here; task 9.3 marks the ended state and stops.
   */
  function onEpisodeEnded(view) {
    state.ended = true;
    // TODO(9.4): show the End_Card with the turn count, per-robot summary, the
    // man's final state, and the full stored replies (Amber/Jade only).
  }

  /**
   * handleSlotConflict(body) — SEAM for task 9.4. Called when POST /api/episodes
   * is rejected with 409 (this device already runs an episode, or the lab is
   * busy). Task 9.4 builds the resume / "lab is busy" UI; task 9.3 shows a plain
   * notice so a 409 never crashes the loop (Req 7.1–7.4).
   */
  function handleSlotConflict(body) {
    // TODO(9.4): device-busy → resume control (GET /api/episodes/{id}); 5
    // running → "lab is busy" + open Replay. For now, a plain notice.
    showNotice('An episode is already in progress. Please try again shortly.');
  }

  // ── notice helpers (a plain seam 9.4 replaces with the resume/busy UI) ──────

  function showNotice(text) {
    if (!el.notice) { return; }
    el.notice.textContent = text || '';
    el.notice.hidden = !text;
  }

  function clearNotice() {
    if (!el.notice) { return; }
    el.notice.textContent = '';
    el.notice.hidden = true;
  }

  // ── UI setup wiring (task 9.2) ──────────────────────────────────────────────

  function wireVoicesToggle() {
    if (!el.btnVoices) { return; }
    el.btnVoices.addEventListener('click', function () {
      var nowOn = voices ? !voices.isEnabled() : false;
      if (voices) {
        voices.setEnabled(nowOn);
        // Unlock playback on this user gesture (browser autoplay rule).
        if (typeof voices.unlock === 'function') { voices.unlock(); }
      }
      el.btnVoices.setAttribute('aria-pressed', nowOn ? 'true' : 'false');
      el.btnVoices.textContent = 'Voices: ' + (nowOn ? 'on' : 'off');
    });
  }

  function wireSwitches() {
    // Earthquake: in 9.2 this only reflects the lever; 9.3 replaces/extends the
    // handler to flip the earthquake and run the turns. The disabled Heavy rain
    // and Heat wave switches do nothing (the `disabled` attribute already
    // rejects activation — Req 4.4), so no handler is attached to them.
    if (el.swEarthquake) {
      el.swEarthquake.addEventListener('click', function () {
        // Raise the Earthquake lever: flip the earthquake and run the turns,
        // creating the episode first if none exists (Req 4.7, 4.8, 5.2, 5.3).
        // The lever is raised by doFlip() only on a successful flip, so a failed
        // flip leaves the lever down. Ignore clicks once the earthquake is
        // already raised / running so the switch is a one-way lever.
        if (state.running || (el.swEarthquake.getAttribute('aria-pressed') === 'true')) {
          return;
        }
        flipEarthquakeAndRun();
      });
    }
  }

  function wireControls() {
    if (el.btnStart) { el.btnStart.addEventListener('click', startRun); }
    if (el.btnPause) { el.btnPause.addEventListener('click', pause); }
    if (el.btnStep) { el.btnStep.addEventListener('click', step); }
    if (el.btnResume) { el.btnResume.addEventListener('click', resume); }
  }

  // Put the screen in its first-load state: no episode in progress, Earthquake
  // lever OFF, empty log, empty meters, and a neutral idle house (Req 4.5). No
  // API is called and no turn is drawn — the calm turn-0 draw happens in 9.3
  // after Start.
  function setFirstLoadState() {
    state.episodeId = null;
    state.expectedTurn = 0;
    state.running = false;
    state.paused = false;
    state.ended = false;
    setEarthquakeLever(false);
    clearMeters();
    clearLog();
    clearNotice();
  }

  function lookupDom() {
    el.stageInner = document.getElementById('stage-inner');
    el.canvas = document.getElementById('house');
    el.overlay = document.getElementById('overlay');
    el.swEarthquake = document.getElementById('sw-earthquake');
    el.swRain = document.getElementById('sw-rain');
    el.swHeat = document.getElementById('sw-heat');
    el.meter_temp = document.getElementById('meter-temp');
    el.meter_water = document.getElementById('meter-water');
    el.meter_injury = document.getElementById('meter-injury');
    el.meter_fear = document.getElementById('meter-fear');
    el.log = document.getElementById('log');
    el.btnStart = document.getElementById('btn-start');
    el.btnPause = document.getElementById('btn-pause');
    el.btnStep = document.getElementById('btn-step');
    el.btnResume = document.getElementById('btn-resume');
    el.btnVoices = document.getElementById('btn-voices');
    el.notice = document.getElementById('notice');
    el.endcardHost = document.getElementById('endcard-host');
  }

  function init() {
    lookupDom();

    // Construct the modules the run loop (9.3) and End_Card (9.4) will use.
    api = (typeof window.createApiClient === 'function') ? window.createApiClient() : null;
    house = (typeof window.createHouseRenderer === 'function') ? window.createHouseRenderer() : null;
    // Voices default OFF; the toggle turns them on and unlocks on the gesture.
    voices = (typeof window.createVoices === 'function') ? window.createVoices({ enabled: false }) : null;
    actOut = (typeof window.createActOut === 'function' && house)
      ? window.createActOut(house, voices)
      : null;

    // Mount the renderer onto the stage canvas + overlay. Draw a neutral idle
    // (the renderer's default calm night scene); do NOT draw any turn here.
    if (house && el.canvas) {
      house.mount(el.canvas, el.overlay);
      if (typeof house.start === 'function') { house.start(); }
    }

    wireVoicesToggle();
    wireSwitches();
    wireControls();
    setFirstLoadState();
  }

  if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', init);
    } else {
      init();
    }
  }

  // Expose the setup surface so the run loop (9.3) and End_Card (9.4) can plug
  // in, and so a headless check can require this file. Nothing here calls an
  // API on load.
  var controller = {
    updateMeters: updateMeters,
    appendLog: appendLog,
    setEarthquakeLever: setEarthquakeLever,
    startRun: startRun,
    pause: pause,
    step: step,
    resume: resume,
    // Seams task 9.4 replaces to show the End_Card and the slot/resume/lab-busy
    // UI. They are plain functions here so a 409 or an episode end never crashes
    // the loop; 9.4 reassigns them via these setters.
    _setOnEpisodeEnded: function (fn) { if (typeof fn === 'function') { onEpisodeEnded = fn; } },
    _setHandleSlotConflict: function (fn) { if (typeof fn === 'function') { handleSlotConflict = fn; } },
    // References the run loop / End_Card need; populated on init().
    _modules: function () { return { api: api, house: house, voices: voices, actOut: actOut }; },
    _state: function () { return state; }
  };

  if (typeof window !== 'undefined') {
    window.observeController = controller;
  }
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = controller;
  }
})();
