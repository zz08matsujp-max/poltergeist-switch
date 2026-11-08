/*
 * replay.js — Replay_Screen controller (page public/replay.php).
 *
 * THIS TASK (10.1) is the PAGE wiring + the episode LIST + the direct-load
 * dispatch only:
 *   - construct the API client, House_Renderer (mounted to the stage), Act_Out
 *     driver and Voices, and keep references so the playback task (10.2) drives
 *     them;
 *   - decide the mode from the query string: `?episode=<id>` DIRECT-LOADs that
 *     episode and hands it to the playback seam; otherwise show the LIST;
 *   - LIST mode: fetch GET /api/episodes?ended=1&limit=20 with a 10-second
 *     timeout, render at most 20 ended episodes newest-created first, each with
 *     its created date, number of turns and the single-line starting situation,
 *     and enter playback by navigating to replay.php?episode=<id>;
 *   - DIRECT-LOAD mode: GET /api/episodes/{id}; a 404 or a not-ended view shows
 *     an error and FALLS BACK to the list.
 *
 * It makes NO AI call at ANY time (Req 8.6): it only ever calls listEnded() and
 * getEpisode() (both read-only, no AI). It never calls ask/apply/createEpisode/
 * flipDisaster.
 *
 * The turn-by-turn PLAYBACK, the reply highlighting and "What it was told this
 * turn" are task 10.2: this file hands a loaded view to a clearly-marked
 * startPlayback(view) STUB and wires the Play / Pause / Step back / Step
 * forward controls to stubs. It does NOT implement playback here.
 *
 * The two robots are named only Amber and Jade in any text this file produces
 * (Req 12.8). Nothing here names an AI company.
 *
 * No build step: plain browser JS. Depends on the window globals set by
 * position-map.js, house-renderer.js, act-out.js, voices.js and api.js, which
 * replay.php loads before this file.
 */
(function () {
  'use strict';

  // The list fetch must not hang the screen: a fetch that does not complete
  // within this budget shows the "could not load the list" message (Req 8.3).
  var LIST_TIMEOUT_MS = 10000;

  // The list shows at most this many episodes, newest-created first (Req 8.2).
  // The server also defaults/caps the limit; we pass it explicitly.
  var LIST_LIMIT = 20;

  // ── module-level references the playback task (10.2) uses ──────────────────
  var api = null;          // createApiClient()
  var house = null;        // createHouseRenderer()
  var voices = null;       // createVoices()
  var actOut = null;       // createActOut(house, voices)

  // DOM handles, looked up once on DOMContentLoaded.
  var el = {};

  // ── mode dispatch ───────────────────────────────────────────────────────────

  // Read the `episode` id from the query string, or null when absent. Returns a
  // trimmed non-empty string; an empty ?episode= is treated as "no id" so the
  // list shows (Req 8.2 vs 8.7).
  function readEpisodeIdFromQuery() {
    try {
      if (typeof window === 'undefined' || !window.location) { return null; }
      var params = new URLSearchParams(window.location.search || '');
      var id = params.get('episode');
      if (id === null) { return null; }
      id = String(id).trim();
      return id === '' ? null : id;
    } catch (e) {
      return null;
    }
  }

  // ── LIST mode (Req 8.2, 8.3, 8.4, 8.5) ──────────────────────────────────────

  /**
   * showList() — fetch the ended-episode list and render it. A fetch that does
   * not complete within LIST_TIMEOUT_MS or returns an error shows a
   * "could not load the list" message and makes no AI call (Req 8.3). An empty
   * list shows a "no replayable episodes yet" message (Req 8.4). Makes no AI
   * call (Req 8.6): only listEnded() is called.
   */
  function showList() {
    showListRegion();
    if (!api) {
      showError('The list could not be loaded.');
      return;
    }

    // Promise.race the list fetch against a 10-second timeout (Req 8.3). The
    // timeout only decides which message to show; no request is sent to any AI.
    var timedOut = false;
    var timeout = new Promise(function (resolve) {
      setTimeout(function () { timedOut = true; resolve({ _timeout: true }); }, LIST_TIMEOUT_MS);
    });

    var fetchList = api.listEnded(LIST_LIMIT).then(function (res) {
      return res;
    }, function () {
      // A network failure surfaces as a rejected promise; turn it into an
      // error marker the handler below treats like any other failure (Req 8.3).
      return { _error: true };
    });

    Promise.race([fetchList, timeout]).then(function (result) {
      if (timedOut || !result || result._timeout) {
        showError('The list could not be loaded. Please try again.');
        return;
      }
      if (result._error || !result || result.status !== 200 || !Array.isArray(result.body)) {
        showError('The list could not be loaded. Please try again.');
        return;
      }
      renderList(result.body);
    });
  }

  /**
   * renderList(items) — render at most 20 ended episodes newest-first (the
   * server already orders them that way; we also cap defensively) (Req 8.2).
   * An empty list shows the "no replayable episodes yet" message (Req 8.4).
   * Each item shows its created date, number of turns and the single-line
   * starting situation, and is clickable → navigates to replay.php?episode=<id>
   * so the direct-load path (Req 8.7) is the single playback entry.
   */
  function renderList(items) {
    if (!el.list) { return; }
    el.list.textContent = '';

    if (!items || items.length === 0) {
      showMessage('No replayable episodes yet.');
      return;
    }

    clearNotice();
    var shown = items.slice(0, LIST_LIMIT);
    shown.forEach(function (item) {
      if (!item) { return; }
      el.list.appendChild(buildListItem(item));
    });
  }

  // Build one clickable list item from a list entry
  // { id, createdDate, turns, startingSituation } (Req 8.5). The starting
  // situation one-liner is built server-side (names the time of day and each
  // robot's and the man's starting room, Amber/Jade only); we render it as-is.
  function buildListItem(item) {
    var node = document.createElement('button');
    node.type = 'button';
    node.className = 'item';
    node.setAttribute('data-episode-id', String(item.id));

    var when = document.createElement('div');
    when.className = 'when';
    when.textContent = formatWhen(item.createdDate) + ' \u00b7 ' + formatTurns(item.turns);
    node.appendChild(when);

    var situation = document.createElement('div');
    situation.className = 'situation';
    situation.textContent = item.startingSituation || '(no starting situation recorded)';
    node.appendChild(situation);

    // Selecting an item enters playback via the direct-load path (Req 8.7):
    // navigate to the relative replay.php?episode=<id> so there is a single
    // playback entry point. No AI call (Req 8.6).
    node.addEventListener('click', function () { selectEpisode(item.id); });
    return node;
  }

  // Format the created date for display. The server sends an ISO-ish string;
  // fall back to the raw string if it does not parse.
  function formatWhen(createdDate) {
    if (!createdDate) { return 'Unknown date'; }
    try {
      var d = new Date(createdDate);
      if (!isNaN(d.getTime())) { return d.toLocaleString(); }
    } catch (e) { /* fall through */ }
    return String(createdDate);
  }

  // Format the turn count as a non-negative integer with a label.
  function formatTurns(turns) {
    var n = Number(turns);
    if (!isFinite(n) || n < 0) { n = 0; }
    n = Math.floor(n);
    return n + (n === 1 ? ' turn' : ' turns');
  }

  // Enter playback for a chosen episode id by navigating to the relative
  // direct-load URL (Req 8.7, 12.9/12.10). This makes the ?episode= path the
  // single playback entry, so the list and the Observe End_Card share it.
  function selectEpisode(id) {
    if (id === undefined || id === null || id === '') { return; }
    try {
      if (typeof window !== 'undefined' && window.location) {
        window.location.assign('replay.php?episode=' + encodeURIComponent(String(id)));
      }
    } catch (e) { /* ignore */ }
  }

  // ── DIRECT-LOAD mode (Req 8.7, 8.8) ─────────────────────────────────────────

  /**
   * directLoad(id) — load one stored episode and begin playback (Req 8.7). For
   * an id that does not exist (404) or a view whose `ended` is not true, show
   * an error and FALL BACK to the list, making no AI call (Req 8.8). Only
   * getEpisode() is called (read-only, no AI).
   */
  function directLoad(id) {
    if (!api) {
      fallbackToList('That episode could not be loaded.');
      return;
    }
    api.getEpisode(id).then(function (res) {
      // A missing id returns 404 → error, then the list (Req 8.8).
      if (!res || res.status === 404 || res.status !== 200 || !res.body) {
        fallbackToList('That episode could not be found.');
        return;
      }
      // A view that has not ended is not replayable → error, then the list
      // (Req 8.8). The Replay screen only plays ENDED episodes.
      if (res.body.ended !== true) {
        fallbackToList('That episode has not finished yet, so it cannot be replayed.');
        return;
      }
      // A loaded, ended view: hand it to the playback seam (task 10.2).
      showPlaybackRegion();
      clearNotice();
      startPlayback(res.body);
    }, function () {
      // A network failure makes no AI call; fall back to the list (Req 8.8).
      fallbackToList('That episode could not be loaded.');
    });
  }

  // Show an error for a failed direct load and fall back to the list (Req 8.8).
  // The list fetch is itself read-only, so no AI call is ever made.
  function fallbackToList(message) {
    showError(message);
    showList();
  }

  // ── PLAYBACK seam for task 10.2 ─────────────────────────────────────────────

  /**
   * startPlayback(view) — STUB for task 10.2.
   *
   * 10.2 implements turn-by-turn playback here: act out each turn with the same
   * House_Renderer and Act_Out as Observe (Req 9.1, 9.12), show each robot's
   * reply beside/below the stage with the evidence-quote highlighting (Req
   * 9.2–9.6), wire "What it was told this turn" (Req 9.7) and the Step
   * back/forward bounds (Req 9.8–9.11). It drives `house`, `actOut` and
   * `voices`, all already constructed on init() below, and reads `view.turns`
   * (each turn carries its recorded `world`, the four layers per robot, and the
   * per-turn `situationText`).
   *
   * In 10.1 this only reveals the playback controls and snaps the house to the
   * first turn's recorded world so the loaded episode is visibly ready; it
   * plays no turns. 10.2 replaces the body below.
   */
  function startPlayback(view) {
    // Keep the loaded view for 10.2 to drive.
    currentView = view;
    currentTurnIndex = 0;

    // Reveal the controls the playback task wires up (Req 9.1).
    if (el.controls) { el.controls.hidden = false; }

    // Snap the house to the first recorded turn's world so the loaded episode
    // shows its opening frame. 10.2 takes over from here to act out the turns.
    var first = firstTurnWorld(view);
    if (house && first) {
      try { house.setWorld(first); } catch (e) { /* ignore */ }
    }
  }

  // The loaded episode view and the current turn index — owned here so 10.2 can
  // drive the Step back/forward controls without re-reading the query.
  var currentView = null;
  var currentTurnIndex = 0;

  // The recorded world of the lowest-numbered turn in a view (turn 0 when
  // present), used to show the opening frame.
  function firstTurnWorld(view) {
    if (!view || !view.turns || !view.turns.length) { return null; }
    var turns = view.turns.slice();
    turns.sort(function (a, b) { return (a.turnNumber || 0) - (b.turnNumber || 0); });
    for (var i = 0; i < turns.length; i++) {
      if (turns[i] && turns[i].world) { return turns[i].world; }
    }
    return null;
  }

  // ── playback control STUBS (task 10.2 replaces these) ───────────────────────

  // Play — begin/resume acting out from the current turn (10.2).
  function play() { /* stub: task 10.2 implements playback (Req 9.1). */ }

  // Pause — stop advancing after the current turn's acting-out (10.2).
  function pausePlayback() { /* stub: task 10.2 (Req 9.1). */ }

  // Step back — move to the previous turn unless on the first (10.2; Req 9.8,
  // 9.9).
  function stepBack() { /* stub: task 10.2 (Req 9.8, 9.9). */ }

  // Step forward — move to the next turn unless on the last (10.2; Req 9.10,
  // 9.11).
  function stepForward() { /* stub: task 10.2 (Req 9.10, 9.11). */ }

  // "What it was told this turn" — open the stored situation text (10.2; Req
  // 9.7).
  function showWhatTold() { /* stub: task 10.2 (Req 9.7). */ }

  // ── region + message helpers ────────────────────────────────────────────────

  // Show the LIST region and hide the playback region and its controls.
  function showListRegion() {
    if (el.list) { el.list.hidden = false; }
    if (el.replayMain) { el.replayMain.hidden = true; }
    if (el.controls) { el.controls.hidden = true; }
    if (el.toldHost) { el.toldHost.hidden = true; }
  }

  // Show the PLAYBACK region and hide the list.
  function showPlaybackRegion() {
    if (el.list) { el.list.hidden = true; }
    if (el.replayMain) { el.replayMain.hidden = false; }
  }

  function showMessage(text) {
    if (!el.notice) { return; }
    el.notice.className = 'message';
    el.notice.textContent = text || '';
    el.notice.hidden = !text;
  }

  function showError(text) {
    if (!el.notice) { return; }
    el.notice.className = 'message error';
    el.notice.textContent = text || '';
    el.notice.hidden = !text;
  }

  function clearNotice() {
    if (!el.notice) { return; }
    el.notice.className = 'message';
    el.notice.textContent = '';
    el.notice.hidden = true;
  }

  // ── control wiring (stubs now; task 10.2 implements the bodies) ─────────────

  function wireControls() {
    if (el.btnPlay) { el.btnPlay.addEventListener('click', play); }
    if (el.btnPause) { el.btnPause.addEventListener('click', pausePlayback); }
    if (el.btnStepBack) { el.btnStepBack.addEventListener('click', stepBack); }
    if (el.btnStepForward) { el.btnStepForward.addEventListener('click', stepForward); }
    if (el.btnTold) { el.btnTold.addEventListener('click', showWhatTold); }
  }

  function lookupDom() {
    el.notice = document.getElementById('notice');
    el.list = document.getElementById('episode-list');
    el.replayMain = document.getElementById('replay-main');
    el.stageInner = document.getElementById('stage-inner');
    el.canvas = document.getElementById('house');
    el.overlay = document.getElementById('overlay');
    el.replies = document.getElementById('replies');
    el.replyAmberText = document.getElementById('reply-amber-text');
    el.replyJadeText = document.getElementById('reply-jade-text');
    el.controls = document.getElementById('controls');
    el.btnPlay = document.getElementById('btn-play');
    el.btnPause = document.getElementById('btn-pause');
    el.btnStepBack = document.getElementById('btn-stepback');
    el.btnStepForward = document.getElementById('btn-stepforward');
    el.btnTold = document.getElementById('btn-told');
    el.toldHost = document.getElementById('told-host');
  }

  function init() {
    lookupDom();

    // Construct the modules. The Replay screen reads only; it never calls AI.
    api = (typeof window.createApiClient === 'function') ? window.createApiClient() : null;
    house = (typeof window.createHouseRenderer === 'function') ? window.createHouseRenderer() : null;
    // Voices default OFF; task 10.2 adds a toggle if it needs one.
    voices = (typeof window.createVoices === 'function') ? window.createVoices({ enabled: false }) : null;
    actOut = (typeof window.createActOut === 'function' && house)
      ? window.createActOut(house, voices)
      : null;

    // Mount the renderer onto the stage canvas + overlay so the playback seam
    // (10.2) can draw immediately.
    if (house && el.canvas) {
      house.mount(el.canvas, el.overlay);
      if (typeof house.start === 'function') { house.start(); }
    }

    wireControls();

    // Decide the mode: ?episode=<id> direct-loads that episode (Req 8.7);
    // otherwise show the list (Req 8.2).
    var episodeId = readEpisodeIdFromQuery();
    if (episodeId) {
      directLoad(episodeId);
    } else {
      showList();
    }
  }

  if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', init);
    } else {
      init();
    }
  }

  // Expose the controller surface so task 10.2 can plug its playback in, and so
  // a headless check can require this file. Nothing here calls an AI endpoint.
  var controller = {
    showList: showList,
    directLoad: directLoad,
    // Playback seam task 10.2 replaces to drive turn-by-turn playback.
    startPlayback: startPlayback,
    play: play,
    pausePlayback: pausePlayback,
    stepBack: stepBack,
    stepForward: stepForward,
    showWhatTold: showWhatTold,
    // Let 10.2 swap in the real playback implementation.
    _setStartPlayback: function (fn) { if (typeof fn === 'function') { startPlayback = fn; } },
    _modules: function () { return { api: api, house: house, voices: voices, actOut: actOut }; },
    _current: function () { return { view: currentView, turnIndex: currentTurnIndex }; }
  };

  if (typeof window !== 'undefined') {
    window.replayController = controller;
  }
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = controller;
  }
})();
