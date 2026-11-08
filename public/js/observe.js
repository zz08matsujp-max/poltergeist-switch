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

  // ── run-loop / End_Card STUBS (filled by tasks 9.3 and 9.4) ────────────────
  // These are intentionally minimal no-ops so the controls are wired now and
  // 9.3 / 9.4 can flesh them out without changing index.php. They must NOT call
  // any API or create an episode in task 9.2.

  /**
   * startRun() — STUB for task 9.3. Will POST /api/episodes, show the calm
   * turn-0 world from turn 0's world_json (no AI call), and begin the turn loop
   * when the earthquake is flipped. Also where task 9.4's 409 slot/resume
   * handling plugs in. Task 9.2 leaves it a no-op.
   */
  function startRun() {
    // TODO(9.3): create the episode and draw the calm turn 0; handle the 409
    // slot/resume and lab-busy cases (9.4). No API call in 9.2.
  }

  /**
   * pause() — STUB for task 9.3. Will stop advancing after the current turn
   * finishes acting out (Req 5.8).
   */
  function pause() {
    // TODO(9.3): stop advancing; leave stored results unchanged.
    state.paused = true;
  }

  /**
   * step() — STUB for task 9.3. While paused, will play exactly one more turn
   * then stop (Req 5.9).
   */
  function step() {
    // TODO(9.3): play exactly one more turn, then stop.
  }

  /**
   * resume() — STUB for task 9.3. Will continue advancing from the current
   * expected turn (Req 5.8).
   */
  function resume() {
    // TODO(9.3): continue the loop from state.expectedTurn.
    state.paused = false;
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
        // TODO(9.3): raise the lever, flip the earthquake (POST .../disaster),
        // creating the episode first if none exists, then run the turns.
        // Task 9.2 wires nothing destructive here.
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
    if (el.notice) { el.notice.hidden = true; el.notice.textContent = ''; }
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
