/*
 * observe.js — Observe_Screen controller (page public/index.php).
 *
 * THIS TASK (9.2) is the LAYOUT + SETUP portion only:
 *   - construct the API client, House_Renderer (mounted to the stage), Voices,
 *     and the Act_Out driver, and keep references for the run loop;
 *   - wire the UI setup: the Voices on/off toggle, the Earthquake / Heat wave
 *     switches, and the first-load state (no episode in
 *     progress, Earthquake lever OFF, empty log, empty meters) (Req 4.5);
 *   - provide the helpers the run loop will call: updateMeters(man),
 *     appendLog(turnNumber, entries), setEarthquakeLever(on);
 *   - leave clearly-marked STUBS for the run loop (9.3) and the End_Card / 409
 *     handling (9.4): startRun(). This file does NOT
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
  var sequencer = null;    // createSpeechSequencer({ house, voices })
  var actOut = null;       // createActOut(house, voices, sequencer)

  // DOM handles, looked up once on DOMContentLoaded.
  var el = {};

  // Live-episode state the run loop (9.3) owns. Declared here so the setup and
  // the stubs share one shape; 9.3 fills in the loop that mutates it.
  var state = {
    episodeId: null,       // current episode id, or null when none in progress
    expectedTurn: 0,       // the next expected turn number
    running: false,        // the loop is advancing turns
    ended: false,          // apply reported the episode ended
    disaster: 'earthquake', // the current episode's disaster (view.disaster)
    // Explicit page phase: idle | starting | staged | running | unfinished |
    // ended (design "Observe phases"). Exposed through _state(); the
    // transitions are wired in by the later sub-tasks of task 12.
    phase: 'idle'
  };

  // ── helpers the run loop (9.3) calls ───────────────────────────────────────

  /**
   * updateMeters(man) — reflect the man's recorded state in the four meters
   * (temperature, water, injury, fear) (Req 4.10, 5.10). `man` is the `man`
   * object from a turn's World_State: { bodyTemp, water, injury, fear, ... }.
   * Each meter's fill width is a percentage and the numeric value is shown.
   * Called by 9.3 on each apply; safe to call with a partial object.
   */
  function updateMeters(man, world) {
    man = man || {};
    updateRoomTemp(man, world);
    // Temperature: the man's safe band is ~35–39 °C; map 34–42 onto 0–100% for
    // the bar while showing the raw value. Water/injury/fear are already 0–100.
    setMeter('temp', man.bodyTemp, formatTemp(man.bodyTemp), tempPercent(man.bodyTemp));
    setMeter('water', man.water, numOrDash(man.water), clampPct(man.water));
    setMeter('injury', man.injury, numOrDash(man.injury), clampPct(man.injury));
    setMeter('fear', man.fear, numOrDash(man.fear), clampPct(man.fear));
  }

  // True when a stored World_State belongs to a heat-wave episode. The heat
  // fields (disaster, roomTemps, ...) exist only on a heat-wave world.
  function isHeatWorld(world) {
    return !!(world && world.disaster === 'heat_wave');
  }

  /**
   * updateRoomTemp(man, world) — heat wave only: show the temperature of the
   * room the man is in beside his meters (heat-wave Req 10.3). In any other
   * episode the row stays hidden and untouched, so an earthquake screen is
   * unchanged. Room temperature is shown as whole degrees Celsius.
   */
  function updateRoomTemp(man, world) {
    var meter = el.meter_room;
    if (!meter) { return; }
    if (!isHeatWorld(world)) { meter.hidden = true; return; }
    var temps = world.roomTemps || {};
    var t = (man && man.room && typeof temps[man.room] === 'number') ? temps[man.room] : null;
    meter.hidden = false;
    var fill = meter.querySelector('.fill');
    var val = meter.querySelector('.val');
    if (t === null || !isFinite(t)) {
      if (fill) { fill.style.width = '0%'; }
      if (val) { val.textContent = '\u2014'; }
      return;
    }
    // Bar maps 18-45 degrees (the engine's room range) onto 0-100%.
    if (fill) { fill.style.width = clampPct(((t - 18) / (45 - 18)) * 100) + '%'; }
    if (val) { val.textContent = String(Math.round(t)) + '\u00b0C'; }
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
      // Colour the row by `who` (amber/jade/man). The viewer-facing robot
      // action and spoken lines already name the actor (e.g. "Amber moved…",
      // "Amber said: …"), so they set `noPrefix` to avoid "Amber: Amber moved…".
      // The man's spoken line keeps the "The man: " prefix as before.
      if (entry.who && !entry.noPrefix) {
        var who = document.createElement('span');
        who.className = 'who' + (entry.who === 'jade' ? ' jade' : '');
        who.textContent = labelFor(entry.who) + ': ';
        row.appendChild(who);
      } else if (entry.who === 'amber' || entry.who === 'jade') {
        // No "Name:" prefix (the sentence already names the robot), but keep the
        // robot's colour cue on the row text using the same CSS variables the
        // prefix span uses (--amber / --jade).
        row.style.color = 'var(--' + entry.who + ')';
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

  /**
   * setHeatLever(on) — reflect the Heat wave breaker lever ON/OFF, the same way
   * setEarthquakeLever does for the Earthquake lever (heat-wave Req 10.1).
   */
  function setHeatLever(on) {
    var sw = el.swHeat;
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

  // ── viewer-facing text builders (tasks 16.1–16.3) ───────────────────────────
  //
  // The "What happened" log and the End_Card summary are written for the VIEWER
  // in the third person, never in the robot's own second-person voice
  // (Req 5.12, 5.13, 6.8). The robots are named only Amber/Jade; no robot
  // thought and no AI company name ever reaches these builders (Req 1.14, 12.8).

  // The viewer-facing name for a robot layer key.
  function robotName(robotKey) {
    return robotKey === 'robot_b' ? 'Jade' : 'Amber';
  }

  // Build the third-person ACTION sentence for one robot's outcome (Req 5.12,
  // 5.13, 6.8). Prefer `seenByOtherText`: it is already third-person viewer
  // text whose subject is "The other robot" when non-empty, so we replace a
  // LEADING "The other robot" (at the very start, optional following space)
  // with this robot's name — "The other robot moved…" -> "Amber moved…". Any
  // other non-empty `seenByOtherText` is used as-is (already third-person).
  // Only when `seenByOtherText` is empty do we fall back to `whatHappenedText`.
  // The log must never show a second-person "You …" sentence (Req 5.13): the
  // fallback `whatHappenedText` is the robot's own second-person voice, so if
  // the chosen text begins with "You" we return '' (omit the action line) — the
  // spoken line (16.2) may still show. Never the raw `reply` (thoughts+speech)
  // and never a provider name.
  function robotActionText(layers, robotKey) {
    if (!layers || !layers.outcome) { return ''; }
    var seen = layers.outcome.seenByOtherText;
    if (typeof seen === 'string' && seen.length > 0) {
      var name = robotName(robotKey);
      // Replace only a leading "The other robot" (case-sensitive) with the name.
      if (seen.indexOf('The other robot') === 0) {
        var rest = seen.slice('The other robot'.length);
        if (rest.charAt(0) === ' ') { rest = rest.slice(1); }
        return name + (rest.length ? ' ' + rest : '');
      }
      return seen;
    }
    // Fallback: `whatHappenedText` is second-person. Never emit a "You …"
    // sentence (Req 5.13) — omit the action line instead.
    var what = layers.outcome.whatHappenedText;
    if (typeof what === 'string' && what.length > 0) {
      if (what.indexOf('You ') === 0 || what === 'You' || what.indexOf('You') === 0) {
        return '';
      }
      return what;
    }
    return '';
  }

  // A slightly smaller budget for the spoken WORDS than the general line
  // budget, so once we wrap the cut words in `<Name> said: "…"` the whole line
  // stays about the same length as an action line (Req 5.15, 6.8). The exact
  // value is not specified; TRUNCATE_MAX_LEN minus the fixed wrapper cost is a
  // sensible choice. See robotSpokenText for why the words are cut first.
  var SPOKEN_WORDS_MAX_LEN = TRUNCATE_MAX_LEN - 20;

  // Build the separate spoken line `<Name> said: "<words>"` for a robot that
  // spoke (Req 5.14, 6.8). `reading.words` holds the SPOKEN words (not a
  // thought). Returns '' when the robot said nothing.
  //
  // The WORDS are truncated FIRST (at a sentence boundary, never mid-word) and
  // only THEN wrapped, so the closing quote is always kept and any ellipsis
  // sits BEFORE it — `Amber said: "<cut words>…"` — rather than the old order
  // (wrap, then cut) which dropped the closing quote (Req 5.15, 6.8). Because
  // the result is already final (truncated and quote-closed), its callers
  // (pushRobotEntry, summariseRobot) must NOT truncate it a second time.
  function robotSpokenText(layers, robotKey) {
    var words = (layers && layers.reading && layers.reading.words) || [];
    if (!words || !words.length) { return ''; }
    var said = words.join(' ').trim();
    if (!said) { return ''; }
    said = truncateAtSentence(said, SPOKEN_WORDS_MAX_LEN);
    return robotName(robotKey) + ' said: "' + said + '"';
  }

  // Truncate an over-long line at a sentence boundary and append an ellipsis,
  // never mid-word (Req 5.15, 6.8). A sentence boundary is the end of a
  // sentence: '.', '!', '?' or the Japanese '。', '！', '？'. If there is no
  // sentence boundary at or before maxLen, cut at the last whitespace before
  // maxLen (never mid-word) and append '…'. maxLen chosen as ~140 chars — long
  // enough for a full action sentence, short enough to keep the End_Card and
  // log rows from overflowing (the exact value is not specified).
  var TRUNCATE_MAX_LEN = 140;
  function truncateAtSentence(text, maxLen) {
    text = String(text == null ? '' : text);
    maxLen = (typeof maxLen === 'number' && maxLen > 0) ? maxLen : TRUNCATE_MAX_LEN;
    if (text.length <= maxLen) { return text; }
    var window = text.slice(0, maxLen);
    var boundary = -1;
    var enders = ['.', '!', '?', '\u3002', '\uff01', '\uff1f'];
    for (var i = 0; i < enders.length; i++) {
      var idx = window.lastIndexOf(enders[i]);
      if (idx > boundary) { boundary = idx; }
    }
    if (boundary >= 0) {
      // Include the sentence-ending punctuation, then the ellipsis.
      return text.slice(0, boundary + 1) + '\u2026';
    }
    // No sentence boundary: cut at the last whitespace, never mid-word.
    var ws = window.lastIndexOf(' ');
    if (ws > 0) { return text.slice(0, ws) + '\u2026'; }
    return window + '\u2026';
  }

  // Reset the four meters to their empty first-load look (Req 4.5).
  function clearMeters() {
    ['temp', 'water', 'injury', 'fear'].forEach(function (key) {
      setMeter(key, null, '\u2014', 0);
    });
    updateRoomTemp(null, null);   // hides the heat-only room row
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
  // The acting-out promise for the turn currently animating.
  var actingOut = null;
  // Guards a single run of the loop body so Start cannot double-run.
  var looping = false;

  /**
   * onStart() — the Start button, dispatched on state.phase (design "Observe
   * phases", Req 9.1-9.5). `starting` is set synchronously here, before any
   * request, so a second press cannot create a second episode.
   *   idle      create only, then show the calm turn-0 house (-> staged)
   *   starting  ignored          staged  ignored          running  ignored
   *   unfinished  Try-again-style continue, never creates
   *   ended     clear the client state and start as from idle
   */
  function onStart() {
    primeVoices();
    if (!api) { return; }
    if (state.phase === 'ended') { startNewRun(); }
    if (state.phase === 'unfinished') { continueUnfinished(); return; }
    if (state.phase !== 'idle') { return; }
    state.phase = 'starting';
    clearNotice();
    api.createEpisode().then(function (res) {
      if (res && res.status === 409) {
        state.phase = 'idle';
        handleSlotConflict(res.body);
        return;
      }
      if (!res || res.status !== 200 || !res.body) {
        state.phase = 'idle';
        showNotice('The lab could not start an episode. Please try again.');
        return;
      }
      adoptEpisode(res.body);
      // Draw the calm turn-0 world. No AI call, no turns running yet (Req 5.1).
      drawTurnZero(res.body);
      state.phase = 'staged';
    }, function () {
      state.phase = 'idle';
      showNotice('The lab could not be reached. Please try again.');
    });
  }

  // Kept for callers of the old name.
  var startRun = onStart;

  /**
   * onLever(disaster) — a Lever flip ('earthquake' or 'heat_wave').
   *   idle      create (with the disaster) then flip
   *   staged    flip the waiting turn-0 episode in place
   *   starting / running  ignored
   *   unfinished  Try-again-style continue, never creates
   *   ended     clear the client state and start as from idle
   */
  function onLever(disaster) {
    primeVoices();
    if (!api) { return; }
    disaster = (disaster === 'heat_wave') ? 'heat_wave' : 'earthquake';
    if (state.phase === 'ended') { startNewRun(); }
    if (state.phase === 'unfinished') { continueUnfinished(); return; }
    if (state.phase === 'staged') {
      state.phase = 'starting';
      clearNotice();
      flipFor(disaster, state.episodeId);
      return;
    }
    if (state.phase !== 'idle') { return; }
    state.phase = 'starting';
    clearNotice();
    api.createEpisode(disaster === 'heat_wave' ? 'heat_wave' : undefined).then(function (res) {
      if (res && res.status === 409) {
        state.phase = 'idle';
        handleSlotConflict(res.body);
        return;
      }
      if (!res || res.status !== 200 || !res.body) {
        state.phase = 'idle';
        showNotice('The lab could not start an episode. Please try again.');
        return;
      }
      adoptEpisode(res.body);
      drawTurnZero(res.body);
      flipFor(disaster, state.episodeId);
    }, function () {
      state.phase = 'idle';
      showNotice('The lab could not be reached. Please try again.');
    });
  }

  function flipFor(disaster, episodeId) {
    if (disaster === 'heat_wave') { doFlipHeat(episodeId); } else { doFlip(episodeId); }
  }

  /**
   * startNewRun() — from `ended`: drop everything the finished run left on the
   * page so the next run starts from an empty client state (Req 9.2).
   */
  function startNewRun() {
    if (actOut && typeof actOut.cancel === 'function') {
      try { actOut.cancel(); } catch (e) { /* ignore */ }
    }
    setThinkingBoth(false);
    pendingAsk = null;
    actingOut = null;
    looping = false;
    state.episodeId = null;
    state.expectedTurn = 0;
    state.running = false;
    state.ended = false;
    state.disaster = 'earthquake';
    setEarthquakeLever(false);
    setHeatLever(false);
    clearMeters();
    clearLog();
    clearNotice();
    dismissEndCard();     // also drops el.reflectHost
    state.phase = 'idle';
  }

  // Try again style continue from `unfinished`: re-read the stored episode and
  // carry on from its expected turn. Never creates an episode.
  function continueUnfinished() {
    if (!api || !state.episodeId) { state.phase = 'idle'; return; }
    state.phase = 'starting';
    clearNotice();
    pendingAsk = null;
    var failed = function () {
      state.phase = 'unfinished';
      showNotice('That episode could not be resumed.');
    };
    api.getEpisode(state.episodeId).then(function (res) {
      if (!res || res.status !== 200 || !res.body || !res.body.episodeId) { failed(); return; }
      adoptEpisode(res.body);
      if (state.ended) { onEpisodeEnded(res.body); return; }
      runLoop();
    }, failed);
  }

  // ── run-loop internals ──────────────────────────────────────────────────────

  // Adopt the episode identity from a returned view: id and the next expected
  // turn. Never discards previously recorded turns (Req 7.5 resync lives here).
  function adoptEpisode(view) {
    state.episodeId = view.episodeId;
    state.expectedTurn = (typeof view.currentExpectedTurn === 'number')
      ? view.currentExpectedTurn : 0;
    state.ended = (view.ended === true);
    state.disaster = (view.disaster === 'heat_wave') ? 'heat_wave' : 'earthquake';
    // Remember this as the device's latest episode so a later load can catch up
    // its reflection if the page closes before the End_Card runs (Req 2.8, 2.9).
    rememberLastEpisode(state.episodeId);
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
    // Heat wave only: show the starting room temperature beside his meters.
    if (t0 && isHeatWorld(t0.world)) { updateRoomTemp(t0.world.man, t0.world); }
  }

  // A 409 'disaster_already_set' means the episode's disaster is fixed on the
  // server: show a short notice and leave both levers as they are.
  function isDisasterAlreadySet(res) {
    if (res && res.status === 409 && res.body && res.body.error === 'disaster_already_set') {
      showNotice('This episode already has its disaster.');
      return true;
    }
    return false;
  }

  function doFlip(episodeId) {
    api.flipDisaster(episodeId).then(function (res) {
      if (isDisasterAlreadySet(res)) { state.phase = 'staged'; return; }
      if (!res || res.status !== 200 || !res.body) {
        state.phase = 'staged';
        showNotice('The earthquake switch did not take. Please try again.');
        return;
      }
      adoptEpisode(res.body);
      setEarthquakeLever(true);
      runLoop();
    }, function () {
      state.phase = 'staged';
      showNotice('The earthquake switch did not take. Please try again.');
    });
  }

  function doFlipHeat(episodeId) {
    api.flipDisaster(episodeId, 'heat_wave').then(function (res) {
      if (isDisasterAlreadySet(res)) { state.phase = 'staged'; return; }
      if (!res || res.status !== 200 || !res.body) {
        state.phase = 'staged';
        showNotice('The heat wave switch did not take. Please try again.');
        return;
      }
      adoptEpisode(res.body);
      // A converted episode's turn 0 is now the heat starting situation: redraw.
      drawTurnZero(res.body);
      setHeatLever(true);
      runLoop();
    }, function () {
      state.phase = 'staged';
      showNotice('The heat wave switch did not take. Please try again.');
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
    if (!state.episodeId) { return; }
    if (state.ended) {
      // An ended episode has nothing to run; Try again / a Lever fetches it
      // and shows the End_Card.
      state.phase = 'unfinished';
      return;
    }

    looping = true;
    state.running = true;
    state.phase = 'running';
    runOneTurn();
  }

  // Stop the loop. By default the run is `unfinished` (failed tries used up or
  // not recoverable); the ended paths pass 'ended'.
  function stopLoop(toPhase) {
    looping = false;
    state.running = false;
    state.phase = (toPhase === 'ended') ? 'ended' : 'unfinished';
  }

  function runOneTurn() {
    if (!state.episodeId || state.ended) { stopLoop(); return; }

    askAndApply(state.expectedTurn, 0);
  }

  // ── ask/apply recovery (error path only) ────────────────────────────────────
  //
  // A long ask can fail at the server's front (a 502 after ~30 s) even though
  // the replies were stored; repeating the ask is idempotent and returns the
  // stored result. So a 5xx or a network failure on ask/apply does not pause at
  // once: wait RECOVERY_WAIT_MS, GET the episode, and continue from the stored
  // state of that turn. After MAX_TURN_TRIES failed tries for the same turn the
  // loop pauses with the existing notice. The wait is injectable: set
  // window.observeWait = function (ms) { return Promise } before use (tests).
  var RECOVERY_WAIT_MS = 5000;
  var MAX_TURN_TRIES = 3;

  function recoveryWait(ms) {
    if (typeof window !== 'undefined' && typeof window.observeWait === 'function') {
      return Promise.resolve(window.observeWait(ms));
    }
    return new Promise(function (resolve) { setTimeout(resolve, ms); });
  }

  // A server error or a failed request (status 0 stands for a swallowed
  // network failure from the prefetched ask). 4xx and 409 are not transient.
  function isTransient(res) {
    return !!res && (res.status === 0 || res.status >= 500);
  }

  // Ask the turn, then apply it. `failures` counts the failed tries so far for
  // this turn (0 on the normal path).
  function askAndApply(turnNumber, failures) {
    askTurn(turnNumber).then(function (askRes) {
      if (handleTurn409(askRes)) { looping = false; runLoop(); return; }
      if (isTransient(askRes)) {
        recoverTurn(turnNumber, failures + 1, 'A turn could not be asked. Paused.');
        return;
      }
      if (!askRes || askRes.status !== 200) {
        showNotice('A turn could not be asked. Paused.');
        stopLoop();
        return;
      }
      applyOnly(turnNumber, failures);
    }, function () {
      recoverTurn(turnNumber, failures + 1, 'The lab could not be reached. Paused.');
    });
  }

  // Apply the turn. The stored result is whatever apply returns; the animation
  // never changes it (Req 5.7).
  function applyOnly(turnNumber, failures) {
    api.apply(state.episodeId, turnNumber).then(function (applyRes) {
      if (handleTurn409(applyRes)) { looping = false; runLoop(); return; }
      if (isTransient(applyRes)) {
        recoverTurn(turnNumber, failures + 1, 'A turn could not be applied. Paused.');
        return;
      }
      if (!applyRes || applyRes.status !== 200 || !applyRes.body) {
        showNotice('A turn could not be applied. Paused.');
        stopLoop();
        return;
      }
      onApplied(applyRes.body, turnNumber);
    }, function () {
      recoverTurn(turnNumber, failures + 1, 'The lab could not be reached. Paused.');
    });
  }

  // One failed try has happened for this turn. Pause after MAX_TURN_TRIES;
  // otherwise wait, read the stored state and carry on from it.
  function recoverTurn(turnNumber, failures, pausedNotice) {
    pendingAsk = null;
    if (failures >= MAX_TURN_TRIES) {
      showNotice(pausedNotice);
      stopLoop();
      return;
    }
    recoveryWait(RECOVERY_WAIT_MS).then(function () {
      return api.getEpisode(state.episodeId).then(function (res) {
        if (!res || res.status !== 200 || !res.body) {
          recoverTurn(turnNumber, failures + 1, pausedNotice);
          return;
        }
        var stored = findTurn(res.body, turnNumber);
        var storedState = stored && stored.state;
        if (storedState === 'applied') {
          onApplied(res.body, turnNumber);
        } else if (storedState === 'replies_complete') {
          applyOnly(turnNumber, failures);
        } else {
          askAndApply(turnNumber, failures);
        }
      }, function () {
        recoverTurn(turnNumber, failures + 1, pausedNotice);
      });
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
      if (applied.world && applied.world.man) { updateMeters(applied.world.man, applied.world); }
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
        stopLoop('ended');
        onEpisodeEnded(view);
        return;
      }

      // Advance to the next expected turn.
      state.expectedTurn = nextTurn;


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
    // Third-person ACTION line (16.1): already names the robot, so no prefix.
    var action = robotActionText(layers, robotKey);
    if (action) {
      entries.push({ who: who, noPrefix: true, text: truncateAtSentence(action) });
    }
    // Separate SPOKEN line (16.2): a robot that both acts and speaks shows both.
    // robotSpokenText already truncated the WORDS before wrapping, so the line
    // is final (quote-closed) — do NOT truncate it again, or the closing quote
    // could be re-cut (task 20.1).
    var spoken = robotSpokenText(layers, robotKey);
    if (spoken) {
      entries.push({ who: who, noPrefix: true, text: spoken });
    }
  }

  // A turn call (ask/apply) may 409 with the current expected turn. Resync the
  // local expected turn to that value and continue WITHOUT discarding recorded
  // turns (Req 7.5). Returns true when a 409 was handled.
  function handleTurn409(res) {
    if (!res || res.status !== 409) { return false; }
    var body = res.body || {};
    // An ended episode is reported as a 409 (error 'episode_ended'); do not
    // re-ask it — stop advancing and fetch the final view for the End_Card
    // (Req 5.11, 6.1). Returns true so the caller stops without surfacing a
    // notice.
    if (body.error === 'episode_ended') {
      pendingAsk = null;
      state.ended = true;
      if (typeof body.currentExpectedTurn === 'number') {
        state.expectedTurn = body.currentExpectedTurn;
      }
      stopLoop('ended');
      finishFromServer();
      return true;
    }
    if (typeof body.currentExpectedTurn === 'number') {
      state.expectedTurn = body.currentExpectedTurn;
      // The prefetched ask (if any) may now be for the wrong turn; drop it.
      pendingAsk = null;
      return true;
    }
    return false;
  }

  // When a turn call reveals the episode has already ended (an 'episode_ended'
  // 409), fetch the authoritative view once and show the End_Card from it
  // (Req 5.11, 6.1). No further ask/apply is sent.
  function finishFromServer() {
    if (!api || !state.episodeId) { return; }
    api.getEpisode(state.episodeId).then(function (res) {
      if (res && res.status === 200 && res.body) {
        onEpisodeEnded(res.body);
      }
    }, function () { /* ignore — the episode is ended regardless */ });
  }

  // ── End_Card (task 9.4, Req 6.1–6.7) ────────────────────────────────────────

  /**
   * onEpisodeEnded(view) — called once, after the final turn's acting-out, when
   * apply reported the episode ended (Req 5.11). Renders the End_Card into
   * #endcard-host (Req 6.1–6.7):
   *   - the TOTAL turns taken as a non-negative integer (Req 6.1), counting only
   *     the robot turns that were applied — turn 0 is the quiet, pre-earthquake
   *     setup turn and is NOT a "turn taken", so a just-created / zero-robot-turn
   *     episode shows 0 (Req 6.2);
   *   - a per-robot SUMMARY drawn from the outcome texts across the episode, in
   *     Amber/Jade terms (Req 6.1); a zero-turn end instead states that no robot
   *     actions occurred (Req 6.2);
   *   - the man's FINAL state from the last turn's world.man (Req 6.1);
   *   - the FULL stored replies for Amber and Jade across turns (Req 6.1);
   *   - the robots are named only Amber and Jade, with no AI company name in the
   *     robots' text (Req 6.5 / 12.8);
   *   - "Watch again" and "Close" buttons (Req 6.3): Close dismisses the card
   *     (Req 6.4); "Watch again" opens Replay for this episode's id via the
   *     relative URL replay.php?episode=<id> (Req 6.6), and on failure keeps the
   *     card and shows an error (Req 6.7).
   */
  function onEpisodeEnded(view) {
    state.ended = true;
    state.phase = 'ended';
    renderEndCard(view);
    // Robot memory (task 12.1): now that the episode has ended, ask both robots
    // what they want to remember and show the result in the End_Card (Req 7.3,
    // 7.4, 7.5). This is idempotent per robot per episode, so it is safe even
    // when the missed-reflection catch-up also fired on a later load.
    runEndCardReflection();
  }

  // Count the robot turns actually taken: every applied turn with a positive
  // turn number (turn 0 is the quiet setup turn, not a turn "taken"). Never
  // negative (Req 6.1, 6.2).
  function countTurnsTaken(view) {
    if (!view || !view.turns) { return 0; }
    var n = 0;
    for (var i = 0; i < view.turns.length; i++) {
      var t = view.turns[i];
      if (t && typeof t.turnNumber === 'number' && t.turnNumber > 0 &&
          t.state === 'applied') {
        n++;
      }
    }
    return n < 0 ? 0 : n;
  }

  // Robot turns in ascending order, turn 0 excluded (turn 0 carries no robot
  // records). Used for the per-robot summary and the full replies.
  function robotTurns(view) {
    var out = [];
    if (!view || !view.turns) { return out; }
    view.turns.forEach(function (t) {
      if (t && typeof t.turnNumber === 'number' && t.turnNumber > 0) {
        out.push(t);
      }
    });
    out.sort(function (a, b) { return a.turnNumber - b.turnNumber; });
    return out;
  }

  // Per-robot summary lines for the End_Card, built the SAME viewer-facing way
  // as the "What happened" log (Req 6.8): third-person action text from
  // `seenByOtherText` (leading "The other robot" -> the robot's name), falling
  // back to `whatHappenedText` only when empty and never emitting a "You …"
  // sentence; each spoken line shown separately as `<Name> said: "<words>"`;
  // and every line truncated at a sentence boundary. Returns an array of
  // already-truncated third-person lines (each already names the robot). Never
  // the reply text, never a thought, never a company name (Req 1.14, 12.8).
  function summariseRobot(turns, robotKey) {
    var lines = [];
    turns.forEach(function (t) {
      var layers = t.robots && t.robots[robotKey];
      if (!layers) { return; }
      // Each End_Card summary line starts with its turn number (task 20.2),
      // e.g. "Turn 3 — Amber took cover." The em dash (U+2014) matches the
      // task text. The "What happened" LOG does NOT get this prefix — it groups
      // entries under a "Turn N" heading (appendLog) — so only the End_Card
      // summary carries the inline "Turn N — " prefix.
      var prefix = 'Turn ' + t.turnNumber + ' \u2014 ';
      var action = robotActionText(layers, robotKey);
      if (action) { lines.push(prefix + truncateAtSentence(action)); }
      // robotSpokenText already truncated the words before wrapping, so the
      // spoken line is final (quote-closed) — do NOT truncate it again (task
      // 20.1).
      var spoken = robotSpokenText(layers, robotKey);
      if (spoken) { lines.push(prefix + spoken); }
    });
    if (!lines.length) {
      // Per-robot fallback, not a per-turn line: there is no single turn to
      // name here, so it carries no "Turn N — " prefix (task 20.2).
      lines.push(robotName(robotKey) + ' did nothing notable.');
    }
    return lines;
  }

  // The full stored reply for a robot across the turns, shown in full (Req 6.1).
  // Each turn's reply is the robot's own stored text; a no-reply turn is noted.
  function fullReplyFor(turns, robotKey) {
    var blocks = [];
    turns.forEach(function (t) {
      var layers = t.robots && t.robots[robotKey];
      if (!layers) { return; }
      var head = 'Turn ' + String(t.turnNumber) + ':';
      if (layers.noReply || !layers.reply) {
        blocks.push(head + ' (no reply)');
      } else {
        blocks.push(head + '\n' + String(layers.reply));
      }
    });
    return blocks.join('\n\n');
  }

  // Describe the man's final state from the last turn's world.man (Req 6.1).
  function describeManFinal(view) {
    var turns = (view && view.turns) ? view.turns.slice() : [];
    turns.sort(function (a, b) {
      return (a.turnNumber || 0) - (b.turnNumber || 0);
    });
    var man = null;
    for (var i = turns.length - 1; i >= 0; i--) {
      if (turns[i] && turns[i].world && turns[i].world.man) {
        man = turns[i].world.man;
        break;
      }
    }
    if (!man) { return 'The man\u2019s final state is not recorded.'; }
    var bits = [];
    if (typeof man.bodyTemp === 'number') { bits.push('temperature ' + man.bodyTemp.toFixed(1) + '\u00b0'); }
    if (typeof man.water === 'number') { bits.push('water ' + Math.round(man.water)); }
    if (typeof man.injury === 'number') { bits.push('injury ' + Math.round(man.injury)); }
    if (typeof man.fear === 'number') { bits.push('fear ' + Math.round(man.fear)); }
    var line = 'The man ends with ' + (bits.length ? bits.join(', ') : 'no recorded meters') + '.';
    if (man.weakened) { line += ' He is weakened.'; }
    if (man.injury && man.injury > 0 && !man.weakened) { line += ' He is hurt.'; }
    return line;
  }

  // Build and show the End_Card in #endcard-host (Req 6.1–6.7).
  function renderEndCard(view) {
    if (!el.endcardHost) { return; }
    clearNotice();

    var count = countTurnsTaken(view);
    var turns = robotTurns(view);
    var zero = (count === 0);

    // Clear any previous card.
    el.endcardHost.textContent = '';

    var card = document.createElement('div');
    card.className = 'endcard';
    card.setAttribute('role', 'dialog');
    card.setAttribute('aria-label', 'Episode ended');

    var h = document.createElement('h2');
    h.textContent = 'Episode ended \u2014 ' + String(count) +
      (count === 1 ? ' turn taken' : ' turns taken');
    card.appendChild(h);

    // Heat wave only: name the disaster on the card (heat-wave Req 10.4). An
    // earthquake card gets no extra element, so it is unchanged.
    if (view && view.disaster === 'heat_wave') {
      var tag = document.createElement('p');
      tag.className = 'disaster-tag';
      tag.textContent = 'Heat wave';
      card.appendChild(tag);
    }

    // Per-robot summary (Req 6.1 / 6.2).
    var summary = document.createElement('div');
    summary.className = 'endcard-summary';
    if (zero) {
      var none = document.createElement('p');
      none.textContent = 'No robot actions occurred.';
      summary.appendChild(none);
    } else {
      appendSummaryLines(summary, summariseRobot(turns, 'robot_a'));
      appendSummaryLines(summary, summariseRobot(turns, 'robot_b'));
    }
    card.appendChild(summary);

    // The man's final state (Req 6.1).
    var manP = document.createElement('p');
    manP.className = 'endcard-man';
    manP.textContent = describeManFinal(view);
    card.appendChild(manP);

    // Full stored replies for Amber and Jade, collapsed by default (Req 6.1,
    // 6.9). N is the turns-taken count shown in the header above.
    if (!zero) {
      card.appendChild(replyBlock('Amber', 'amber', fullReplyFor(turns, 'robot_a'), count));
      card.appendChild(replyBlock('Jade', 'jade', fullReplyFor(turns, 'robot_b'), count));
    }

    // Robot-memory reflect-and-show host (task 12.1, Req 7.3–7.5). The reflect
    // call runs after the card is drawn (runEndCardReflection) and fills this
    // region with the awaiting text, then the per-robot memory / failure lines.
    var reflectHost = document.createElement('div');
    reflectHost.className = 'endcard-reflection';
    reflectHost.setAttribute('aria-live', 'polite');
    card.appendChild(reflectHost);
    el.reflectHost = reflectHost;

    // Error line (used only when "Watch again" fails — Req 6.7).
    var err = document.createElement('div');
    err.className = 'message error';
    err.hidden = true;
    card.appendChild(err);

    // Buttons (Req 6.3).
    var actions = document.createElement('div');
    actions.className = 'controls';

    var again = document.createElement('button');
    again.type = 'button';
    again.className = 'btn primary';
    again.textContent = 'Watch again';
    again.addEventListener('click', function () {
      openReplay(state.episodeId, function () {
        // Req 6.7: keep the card, show the error.
        err.textContent = 'The replay could not be opened.';
        err.hidden = false;
      });
    });
    actions.appendChild(again);

    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn';
    close.textContent = 'Close';
    close.addEventListener('click', dismissEndCard);
    actions.appendChild(close);

    card.appendChild(actions);
    el.endcardHost.appendChild(card);
  }

  // Render each already-third-person summary line as its own paragraph. The
  // lines already name the robot (e.g. "Amber moved…", "Amber said: …"), so no
  // "Name:" prefix is added (consistent with the log's action/spoken rows).
  function appendSummaryLines(summary, lines) {
    (lines || []).forEach(function (text) {
      if (!text) { return; }
      var p = document.createElement('p');
      p.appendChild(document.createTextNode(text));
      summary.appendChild(p);
    });
  }

  // A robot's full stored replies inside a disclosure element collapsed by
  // default (Req 6.9), so the End_Card no longer opens ~9,000 px tall. The
  // full replies stay fully present inside the <details> (Req 6.1); they are
  // only hidden until the viewer opens them. `turnCount` is the turns-taken N
  // shown in the End_Card header, so the summary reads e.g.
  // "Amber — full replies (13 turns)".
  function replyBlock(name, cls, text, turnCount) {
    var n = (typeof turnCount === 'number' && turnCount >= 0) ? turnCount : 0;
    var details = document.createElement('details');
    details.className = 'reply ' + cls;
    // No `open` attribute -> closed by default.
    var summary = document.createElement('summary');
    summary.textContent = name + ' \u2014 full replies (' +
      String(n) + (n === 1 ? ' turn)' : ' turns)');
    details.appendChild(summary);
    var body = document.createElement('div');
    body.className = 'text';
    body.textContent = text || '(no reply recorded)';
    details.appendChild(body);
    return details;
  }

  // Dismiss the End_Card (Req 6.4).
  function dismissEndCard() {
    if (el.endcardHost) { el.endcardHost.textContent = ''; }
    // Drop the reflect-and-show host reference so a late reflect response from
    // a dismissed card renders nothing (task 12.1).
    el.reflectHost = null;
  }

  // Open the Replay screen for an episode id via a RELATIVE URL (no leading
  // slash), consistent with the api.js / index.php relative-URL rule (Req 6.6,
  // 12.9/12.10). Calls onFail() if it cannot be opened (Req 6.7).
  function openReplay(episodeId, onFail) {
    if (!episodeId) { if (typeof onFail === 'function') { onFail(); } return; }
    try {
      var url = 'replay.php?episode=' + encodeURIComponent(String(episodeId));
      if (typeof window !== 'undefined' && window.location) {
        window.location.assign(url);
        return;
      }
      if (typeof onFail === 'function') { onFail(); }
    } catch (e) {
      if (typeof onFail === 'function') { onFail(); }
    }
  }

  // ── slot / resume / lab-busy handling (task 9.4, Req 7.1–7.4) ───────────────

  /**
   * handleSlotConflict(body) — called when POST /api/episodes is rejected with
   * 409. The server encodes the reason in the body's `error` code (see
   * TurnService::createEpisode):
   *   - 'device_already_running' → this device already runs an episode: show a
   *     message and a RESUME control (Req 7.1); resume fetches
   *     GET /api/episodes/{id} and continues from the returned
   *     currentExpectedTurn (Req 7.2); on failure show a could-not-resume error
   *     and offer Replay (Req 7.3). The 409 body carries no id, so the id comes
   *     from body.episodeId when present, else the device's running episode id
   *     this page already holds (state.episodeId).
   *   - 'too_many_running' → 5 episodes run across all devices: show a
   *     "lab is busy" message and a control that opens Replay (Req 7.4).
   */
  function handleSlotConflict(body) {
    body = body || {};
    var code = body.error;
    if (code === 'too_many_running') {
      showLabBusy();
      return;
    }
    // Default to the device-busy path (device_already_running, or any other
    // create 409): offer resume.
    var resumeId = body.episodeId || state.episodeId || null;
    showDeviceBusy(resumeId);
  }

  // Device already runs an episode (Req 7.1): a message plus a Resume control
  // and a Replay fallback.
  function showDeviceBusy(resumeId) {
    renderNoticePanel(
      'A running episode already exists on this device.',
      [
        { label: 'Resume', primary: true, onClick: function () { resumeEpisode(resumeId); } },
        { label: 'Watch a past episode', onClick: goReplayList }
      ]
    );
  }

  // Lab is busy (Req 7.4): a message plus a control that opens Replay.
  function showLabBusy() {
    renderNoticePanel(
      'The lab is busy \u2014 five episodes are already running. Please try again later.',
      [
        { label: 'Watch a past episode', primary: true, onClick: goReplayList }
      ]
    );
  }

  // Resume the device's running episode (Req 7.2): GET /api/episodes/{id} and
  // continue from the returned currentExpectedTurn; on failure show a
  // could-not-resume error and offer Replay (Req 7.3).
  function resumeEpisode(resumeId) {
    primeVoices();
    if (!resumeId || !api) { showResumeFailed(); return; }
    if (state.phase !== 'idle') { return; }
    state.phase = 'starting';
    api.getEpisode(resumeId).then(function (res) {
      if (!res || res.status !== 200 || !res.body || !res.body.episodeId) {
        state.phase = 'idle';
        showResumeFailed();
        return;
      }
      clearNotice();
      adoptEpisode(res.body);
      // Redraw from the latest recorded turn so the house reflects where the
      // episode stands, then continue the loop (Req 7.2). Never discards the
      // recorded turns.
      redrawLatest(res.body);
      if (state.ended) {
        // Already finished — show the End_Card instead of running (Req 5.11).
        onEpisodeEnded(res.body);
        return;
      }
      if (res.body.disaster === 'heat_wave') {
        setHeatLever(true);
      } else {
        setEarthquakeLever(res.body.earthquakeStartTurn !== null &&
          res.body.earthquakeStartTurn !== undefined);
      }
      runLoop();
    }, function () {
      state.phase = 'idle';
      showResumeFailed();
    });
  }

  function showResumeFailed() {
    renderNoticePanel(
      'That episode could not be resumed.',
      [
        { label: 'Watch a past episode', primary: true, onClick: goReplayList }
      ],
      true
    );
  }

  // Draw the house from the latest recorded turn's world (resume redraw).
  function redrawLatest(view) {
    var turns = (view && view.turns) ? view.turns.slice() : [];
    turns.sort(function (a, b) { return (a.turnNumber || 0) - (b.turnNumber || 0); });
    var world = null;
    for (var i = turns.length - 1; i >= 0; i--) {
      if (turns[i] && turns[i].world) { world = turns[i].world; break; }
    }
    if (house && world) {
      try { house.setWorld(world); } catch (e) { /* ignore */ }
      if (world.man) { updateMeters(world.man, world); }
    }
    // Re-populate the log from every recorded robot turn, in order.
    turns.forEach(function (t) {
      if (t && typeof t.turnNumber === 'number' && t.turnNumber > 0 &&
          t.state === 'applied') {
        appendLog(t.turnNumber, buildLogEntries(t));
      }
    });
  }

  // Navigate to the Replay list (no chosen episode) via a relative URL (Req 7.4,
  // 7.3, 12.9/12.10).
  function goReplayList() {
    try {
      if (typeof window !== 'undefined' && window.location) {
        window.location.assign('replay.php');
      }
    } catch (e) { /* ignore */ }
  }

  // ── notice helpers + the resume/busy panel ──────────────────────────────────

  function showNotice(text) {
    if (!el.notice) { return; }
    el.notice.className = 'message';
    el.notice.textContent = text || '';
    el.notice.hidden = !text;
  }

  function clearNotice() {
    if (!el.notice) { return; }
    el.notice.className = 'message';
    el.notice.textContent = '';
    el.notice.hidden = true;
  }

  // Render a message plus one or more action buttons into #notice (used by the
  // device-busy / lab-busy / resume-failed flows, Req 7.1–7.4).
  function renderNoticePanel(message, buttons, isError) {
    if (!el.notice) { return; }
    el.notice.className = 'message' + (isError ? ' error' : '');
    el.notice.textContent = '';
    el.notice.hidden = false;

    var p = document.createElement('p');
    p.textContent = message;
    el.notice.appendChild(p);

    var row = document.createElement('div');
    row.className = 'controls';
    (buttons || []).forEach(function (b) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn' + (b.primary ? ' primary' : '');
      btn.textContent = b.label;
      btn.addEventListener('click', b.onClick);
      row.appendChild(btn);
    });
    el.notice.appendChild(row);
  }

  // ── robot memory: status line, All reset, missed-reflection catch-up ────────
  //
  // (robot-memory spec, Requirements 7.1, 7.2, 7.7, 7.8, 2.8, 2.9.) On load the
  // status line is filled from GET /api/memory and the device's last episode is
  // caught up (reflected once if it ended but was never reflected). The All
  // reset button reveals an IN-PAGE confirm panel — never a browser alert or
  // confirm dialog — and Confirm advances the generation and refreshes the line.
  // The robots are named only Amber and Jade here (Req 7.7); no company/model
  // name appears. The helpers come from memory-ui.js (shared with the End_Card).

  // localStorage key under which this device's last episode id is remembered,
  // so the catch-up can reflect an episode whose page was closed before the
  // End_Card ran. The id is not a secret — it is the same id the Replay URL
  // carries — and nothing else is stored.
  var LAST_EPISODE_KEY = 'ps:lastEpisodeId';

  // Remember an episode id as this device's latest, for the catch-up on a later
  // load. Called whenever the page adopts an episode. Failures (private mode,
  // disabled storage) are ignored — the catch-up is best-effort.
  function rememberLastEpisode(episodeId) {
    if (!episodeId) { return; }
    try {
      if (typeof window !== 'undefined' && window.localStorage) {
        window.localStorage.setItem(LAST_EPISODE_KEY, String(episodeId));
      }
    } catch (e) { /* ignore */ }
  }

  function readLastEpisode() {
    try {
      if (typeof window !== 'undefined' && window.localStorage) {
        var id = window.localStorage.getItem(LAST_EPISODE_KEY);
        return (typeof id === 'string' && id !== '') ? id : null;
      }
    } catch (e) { /* ignore */ }
    return null;
  }

  // Fetch GET /api/memory and render the status line (Req 7.1). Safe when the
  // memory bar or the api client is absent, and when the request fails (the
  // line is simply left as it was). Uses the shared memory-ui.js renderer so the
  // exact "Generation N · Amber remembers k · Jade remembers k" format lives in
  // one place.
  function refreshMemoryStatus() {
    if (!api || !el.memoryStatus || typeof api.memoryStatus !== 'function') { return; }
    api.memoryStatus().then(function (res) {
      if (res && res.status === 200 && res.body && window.memoryUi) {
        window.memoryUi.renderStatusLine(el.memoryStatus, res.body);
      }
    }, function () { /* ignore — leave the previous line */ });
  }

  // Wire the All reset button to reveal the in-page confirm panel (Req 7.2).
  // The panel is built by memory-ui.js (Confirm / Cancel, no browser dialog) and
  // mounted into #reset-confirm-host. Confirm calls POST /api/memory/reset and
  // refreshes the status line with the returned generation; Cancel just closes.
  function wireResetMemory() {
    if (!el.btnResetMemory) { return; }
    el.btnResetMemory.addEventListener('click', function () {
      showResetConfirm();
    });
  }

  function showResetConfirm() {
    var host = el.resetConfirmHost;
    if (!host || !window.memoryUi) { return; }
    // One panel at a time; clear any previous.
    host.textContent = '';
    var panel = window.memoryUi.buildConfirmPanel(
      function () { confirmResetMemory(); },
      function () { closeResetConfirm(); }
    );
    host.appendChild(panel);
    host.hidden = false;
    if (el.btnResetMemory) { el.btnResetMemory.setAttribute('aria-expanded', 'true'); }
  }

  function closeResetConfirm() {
    var host = el.resetConfirmHost;
    if (!host) { return; }
    host.textContent = '';
    host.hidden = true;
    if (el.btnResetMemory) { el.btnResetMemory.setAttribute('aria-expanded', 'false'); }
  }

  function confirmResetMemory() {
    closeResetConfirm();
    if (!api || typeof api.resetMemory !== 'function') { return; }
    api.resetMemory().then(function (res) {
      if (res && res.status === 200 && res.body && window.memoryUi && el.memoryStatus) {
        // Refresh the status line from the returned generation. After a reset
        // both robots start the new generation with zero active memories, so
        // the line can be rendered directly from the reset payload without a
        // second round trip (a full refresh also follows for safety).
        window.memoryUi.renderStatusLine(el.memoryStatus, {
          generation: res.body.generation,
          robots: { amber: { activeCount: 0 }, jade: { activeCount: 0 } }
        });
      }
      refreshMemoryStatus();
    }, function () {
      // On failure, re-read the current status so the line stays truthful.
      refreshMemoryStatus();
    });
  }

  // Missed-reflection catch-up (Req 2.8, 2.9): on load, if this device's last
  // episode has ended but was never reflected (for example the viewer closed
  // the page before the End_Card ran), call POST /api/episodes/{id}/reflect
  // once. Reflect is idempotent per robot per episode, so this is safe even if
  // the End_Card also fired. A pre-feature episode returns 409 not_reflectable
  // and is ignored. The memory status line is refreshed after a successful
  // reflect so any newly stored memory shows in the counts.
  function catchUpLastReflection() {
    if (!api || typeof api.getEpisode !== 'function' || typeof api.reflect !== 'function') {
      return;
    }
    var lastId = readLastEpisode();
    if (!lastId) { return; }
    api.getEpisode(lastId).then(function (res) {
      if (!res || res.status !== 200 || !res.body) { return; }
      if (res.body.ended !== true) { return; }
      api.reflect(lastId).then(function (reflectRes) {
        // Ignore not_reflectable (pre-feature episode) and any other error; on
        // success refresh the status counts.
        if (reflectRes && reflectRes.status === 200) {
          refreshMemoryStatus();
        }
      }, function () { /* ignore — best-effort catch-up */ });
    }, function () { /* ignore — the id may be stale */ });
  }

  // End_Card reflect-and-show (task 12.1, Req 7.3, 7.4, 7.5). Called once by
  // onEpisodeEnded after the card is drawn: show "Writing down what to
  // remember…" while the Reflect_Endpoint is awaited, then render each robot's
  // result into the card. A robot that answered shows its new memory in full
  // inside a collapsible with its new active count; a robot that did not
  // answer shows the distinct empty-vs-failed line (no provider name in
  // either). The per-robot rendering lives in memory-ui.js (buildReflection
  // Block), shared so the phrasing and the collapsible are written once. The
  // status line is refreshed afterwards so the counts reflect any new memory.
  function runEndCardReflection() {
    var host = el.reflectHost;
    var episodeId = state.episodeId;
    if (!host) { finishClosing(episodeId); return Promise.resolve(); }
    if (!api || typeof api.reflect !== 'function' || !episodeId) {
      host.textContent = '';
      finishClosing(episodeId);
      return Promise.resolve();
    }

    // Awaiting state (Req 7.3): the exact wording while the reflection runs.
    host.textContent = '';
    var waiting = document.createElement('p');
    waiting.className = 'reflection-waiting';
    waiting.textContent = 'Writing down what to remember\u2026';
    host.appendChild(waiting);

    return api.reflect(episodeId).then(function (res) {
      // Only render if the card is still showing this episode's reflect host
      // (the viewer may have closed the card or started another episode).
      if (el.reflectHost !== host) { return; }
      if (!res || res.status !== 200 || !res.body || !res.body.robots) {
        // A non-200 (e.g. 409 not_reflectable for a pre-feature episode) or a
        // malformed body: leave no awaiting text, add nothing. The End_Card is
        // otherwise complete.
        host.textContent = '';
        return;
      }
      renderReflectionResult(host, res.body.robots);
      // Refresh the Observe status line so the new counts show (Req 7.1).
      refreshMemoryStatus();
    }, function () {
      if (el.reflectHost !== host) { return; }
      // The reflect call could not be reached: clear the awaiting text so the
      // card is not left mid-sentence. No memory is shown.
      host.textContent = '';
    }).then(function () {
      finishClosing(episodeId);
    });
  }

  // The last turn and its reflection (or "not stored") are done: switch both
  // Levers OFF. Guarded by the episode id so a late reflection never switches
  // off a newer run's Lever (Req 9.1, 9.2). The phase stays `ended`: the
  // End_Card is still shown and the next Lever or Start clears it.
  function finishClosing(episodeId) {
    if (!episodeId || state.episodeId !== episodeId || state.phase !== 'ended') { return; }
    setEarthquakeLever(false);
    setHeatLever(false);
  }

  // Render the reflect result for both robots into the End_Card host (Req 7.4,
  // 7.5). `robots` is the reflect body's { amber, jade } object. Each robot's
  // block is built by the shared memory-ui.js helper (collapsible memory with
  // its new count when answered; the distinct empty-vs-failed line otherwise),
  // named only Amber and Jade (Req 7.7).
  function renderReflectionResult(host, robots) {
    host.textContent = '';
    if (!window.memoryUi || typeof window.memoryUi.buildReflectionBlock !== 'function') {
      return;
    }
    var heading = document.createElement('h3');
    heading.className = 'endcard-reflection-title';
    heading.textContent = 'What they wrote down';
    host.appendChild(heading);
    host.appendChild(window.memoryUi.buildReflectionBlock('Amber', robots.amber, 'amber'));
    host.appendChild(window.memoryUi.buildReflectionBlock('Jade', robots.jade, 'jade'));
  }

  // ── UI setup wiring (task 9.2) ──────────────────────────────────────────────

  // First_Gesture (Req 11.3, design C10): called synchronously, first thing, in
  // the Lever, Start, Notice_Resume and Try again handlers. Speech stays
  // silent until one of these has run. Safe to call repeatedly.
  function primeVoices() {
    if (voices && typeof voices.gesture === 'function') {
      try { voices.gesture(); } catch (e) { /* ignore */ }
    }
  }

  // The button label, aria-pressed and toggle come from bindVoicesButton
  // (speech-sequencer.js). The page starts as "Voices: on" (Req 11.1).
  function wireVoicesToggle() {
    if (!el.btnVoices || typeof window.bindVoicesButton !== 'function') { return; }
    window.bindVoicesButton(el.btnVoices, voices, sequencer);
  }

  function wireSwitches() {
    // Both Levers dispatch on state.phase (onLever); a flip while a run is in
    // progress is ignored there.
    if (el.swEarthquake) {
      el.swEarthquake.addEventListener('click', function () { onLever('earthquake'); });
    }
    if (el.swHeat) {
      el.swHeat.addEventListener('click', function () { onLever('heat_wave'); });
    }
  }

  function wireControls() {
    if (el.btnStart) { el.btnStart.addEventListener('click', onStart); }
  }

  // Put the screen in its first-load state: no episode in progress, Earthquake
  // lever OFF, empty log, empty meters, and a neutral idle house (Req 4.5). No
  // API is called and no turn is drawn — the calm turn-0 draw happens in 9.3
  // after Start.
  function setFirstLoadState() {
    state.episodeId = null;
    state.expectedTurn = 0;
    state.running = false;
    state.ended = false;
    state.phase = 'idle';
    setEarthquakeLever(false);
    setHeatLever(false);
    clearMeters();
    clearLog();
    clearNotice();
    dismissEndCard();
  }

  function lookupDom() {
    el.stageInner = document.getElementById('stage-inner');
    el.canvas = document.getElementById('house');
    el.overlay = document.getElementById('overlay');
    el.swEarthquake = document.getElementById('sw-earthquake');
    el.swHeat = document.getElementById('sw-heat');
    el.meter_temp = document.getElementById('meter-temp');
    el.meter_water = document.getElementById('meter-water');
    el.meter_injury = document.getElementById('meter-injury');
    el.meter_fear = document.getElementById('meter-fear');
    el.meter_room = document.getElementById('meter-room');
    el.log = document.getElementById('log');
    el.btnStart = document.getElementById('btn-start');
    el.btnVoices = document.getElementById('btn-voices');
    el.notice = document.getElementById('notice');
    el.endcardHost = document.getElementById('endcard-host');
    // Memory bar (robot-memory task 11.1).
    el.memoryStatus = document.getElementById('memory-status');
    el.btnResetMemory = document.getElementById('btn-reset-memory');
    el.resetConfirmHost = document.getElementById('reset-confirm-host');
  }

  function init() {
    lookupDom();

    // Construct the modules the run loop (9.3) and End_Card (9.4) will use.
    api = (typeof window.createApiClient === 'function') ? window.createApiClient() : null;
    house = (typeof window.createHouseRenderer === 'function') ? window.createHouseRenderer() : null;
    // Voices default ON (Req 11.1) but stay silent until the First_Gesture.
    voices = (typeof window.createVoices === 'function')
      ? window.createVoices({ enabled: true, autoUnlock: false })
      : null;
    sequencer = (typeof window.createSpeechSequencer === 'function' && house)
      ? window.createSpeechSequencer({ house: house, voices: voices })
      : null;
    actOut = (typeof window.createActOut === 'function' && house)
      ? window.createActOut(house, voices, sequencer)
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
    wireResetMemory();
    setFirstLoadState();

    // Robot memory (task 11.1): fill the status line from GET /api/memory on
    // load, then catch up the device's last episode reflection if it ended but
    // was never reflected (Req 7.1, 2.8, 2.9).
    refreshMemoryStatus();
    catchUpLastReflection();
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
    onStart: onStart,
    onLever: onLever,
    startNewRun: startNewRun,
    finishClosing: finishClosing,
    runEndCardReflection: runEndCardReflection,
    // Robot memory (task 11.1): the status-line refresh, the in-page All-reset
    // confirm flow, and the missed-reflection catch-up, exposed so task 12.1
    // (the End_Card reflect-and-show) and a headless check can drive them.
    refreshMemoryStatus: refreshMemoryStatus,
    catchUpLastReflection: catchUpLastReflection,
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
