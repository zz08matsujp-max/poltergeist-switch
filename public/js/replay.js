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

    // Heat wave only: the list item carries `disaster` for a heat-wave episode
    // and nothing for an earthquake one (heat-wave Req 10.4).
    if (item.disaster === 'heat_wave') {
      var tag = document.createElement('div');
      tag.className = 'disaster-tag';
      tag.textContent = 'Heat wave';
      node.appendChild(tag);
    }

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

  // Format the created date for display. The server sends an ISO-8601 string
  // WITH an offset (e.g. "2026-11-08T19:03:13+09:00"), so new Date() resolves
  // the correct instant; we show it in the VIEWER's local time with the zone
  // name (e.g. "GMT+1") so the time reads correctly in any zone (task 20.3).
  // Fall back to the raw string if it does not parse; empty → "Unknown date".
  function formatWhen(createdDate) {
    if (!createdDate) { return 'Unknown date'; }
    try {
      var d = new Date(createdDate);
      if (!isNaN(d.getTime())) {
        return d.toLocaleString(undefined, { timeZoneName: 'short' });
      }
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
   * startPlayback(view) — begin turn-by-turn playback of a loaded, ended view
   * (Req 9.1, 9.12).
   *
   * Sorts the view's turns by turnNumber ascending and keeps them in
   * `sortedTurns`; `currentTurnIndex` walks that array. Playback starts on the
   * first turn (turn 0, the calm setup frame): the house is snapped to that
   * turn's recorded world and both reply panels are filled, so the loaded
   * episode shows its opening frame before Play or a Step is pressed. From
   * there Play advances automatically through the later turns, and Step back /
   * Step forward move one turn at a time within the bounds (Req 9.8–9.11).
   *
   * It drives the same House_Renderer and Act_Out as Observe (Req 9.12) and
   * makes NO AI call: it only reads the already-loaded `view`.
   */
  function startPlayback(view) {
    currentView = view;

    // Sort the turns ascending by turnNumber; this is the playback order and
    // the array the Step controls index into (Req 9.1).
    sortedTurns = (view && Array.isArray(view.turns)) ? view.turns.slice() : [];
    sortedTurns.sort(function (a, b) {
      return ((a && a.turnNumber) || 0) - ((b && b.turnNumber) || 0);
    });

    // Start on the first turn (turn 0, the calm frame). Not playing yet.
    currentTurnIndex = 0;
    playing = false;

    // Reveal the controls (Req 9.1) and hide any stale "told" panel.
    if (el.controls) { el.controls.hidden = false; }
    if (el.toldHost) { el.toldHost.hidden = true; }

    // Heat wave only: label the disaster on the Replay screen (heat-wave
    // Req 10.4). An earthquake episode leaves the label hidden and empty.
    if (el.disasterLabel) {
      var isHeat = !!(view && view.disaster === 'heat_wave');
      el.disasterLabel.textContent = isHeat ? 'Heat wave' : '';
      el.disasterLabel.hidden = !isHeat;
    }

    // Show, per robot, how many memories it started this episode with
    // (robot-memory Requirement 7.6): the length of its stored Memory_Snapshot,
    // read from the loaded view.
    renderStartedWith(view);

    // Render the opening frame: snap the house to turn 0's world and fill the
    // reply panels with the highlighted replies for that turn. No acting-out
    // yet — the first beat is a still frame until Play or Step is pressed.
    renderTurn(currentTurnIndex, false);
  }

  // The loaded episode view, the turns sorted ascending, the current index, and
  // the playing guard. Owned here so the controls can drive playback without
  // re-reading the query.
  var currentView = null;
  var sortedTurns = [];
  var currentTurnIndex = 0;
  var playing = false; // true while Play is auto-advancing; guards double-runs.

  // ── rendering one turn ──────────────────────────────────────────────────────

  // The turn at a given index, or null when out of range.
  function turnAt(index) {
    if (index < 0 || index >= sortedTurns.length) { return null; }
    return sortedTurns[index] || null;
  }

  /**
   * updateTurnIndicator() — refresh the "Turn N of M" counter next to the
   * controls (Req 9.1, 9.8-9.11).
   *
   * M = the number of turns TAKEN = the applied turns EXCLUDING turn 0 (the
   * calm setup frame). The loaded view's `turns` include turn 0, so M counts
   * only sortedTurns whose turnNumber > 0 — matching the End_Card's "turns
   * taken" and the list count.
   *
   * N = the current turn's position among those counted turns. When the current
   * turn is a robot turn (turnNumber > 0) N is that turnNumber, so it reads
   * "Turn 3 of 13". When the current turn is turn 0 (the opening calm frame)
   * there is no turn taken yet, so the counter reads "Setup \u00b7 M turns" to
   * make the still opening frame read sensibly.
   */
  function updateTurnIndicator() {
    if (!el.turnIndicator) { return; }

    // M: applied turns excluding turn 0.
    var total = 0;
    for (var i = 0; i < sortedTurns.length; i++) {
      var t = sortedTurns[i];
      if (t && Number(t.turnNumber) > 0) { total += 1; }
    }

    var current = turnAt(currentTurnIndex);
    var currentNumber = current ? Number(current.turnNumber) : 0;
    if (!isFinite(currentNumber) || currentNumber < 0) { currentNumber = 0; }

    var turnsLabel = total + (total === 1 ? ' turn' : ' turns');
    if (currentNumber > 0) {
      el.turnIndicator.textContent = 'Turn ' + currentNumber + ' of ' + total;
    } else {
      // Turn 0: the calm opening frame, before any turn is taken.
      el.turnIndicator.textContent = 'Setup \u00b7 ' + turnsLabel;
    }
  }

  /**
   * renderTurn(index, actOutTurn) — show the turn at `index`.
   *
   * Always: snap the house to that turn's recorded `world` (so characters sit
   * where the record puts them) and fill both reply panels with the
   * evidence-highlighted reply (or "(no reply)"). When `actOutTurn` is true it
   * also acts the turn out once with the shared Act_Out (Req 9.12) and returns
   * the playTurn promise so the Play loop can chain on it; Act_Out itself ends
   * every turn in the same recorded world, so the still snap and the acted-out
   * end agree. When `actOutTurn` is false it only paints the still frame and
   * returns a resolved promise.
   */
  function renderTurn(index, actOutTurn) {
    var turn = turnAt(index);
    if (!turn) { return Promise.resolve(); }

    // Update the "Turn N of M" counter whenever the current turn changes.
    updateTurnIndicator();

    // Fill the reply panels with the highlighted replies for this turn.
    renderReplies(turn);

    // Opening/step still frame: snap the house to the recorded world directly.
    if (!actOutTurn || !actOut) {
      if (house && turn.world) {
        try { house.setWorld(turn.world); } catch (e) { /* ignore */ }
      }
      return Promise.resolve();
    }

    // Acted-out frame: the shared Act_Out animates the turn and ends it in the
    // same recorded world (Req 9.12). It resolves when the acting-out finishes.
    try {
      return actOut.playTurn(turn, turn.world) || Promise.resolve();
    } catch (e) {
      // If acting-out throws, fall back to the still snap so the turn still
      // shows and the loop can continue.
      if (house && turn.world) {
        try { house.setWorld(turn.world); } catch (e2) { /* ignore */ }
      }
      return Promise.resolve();
    }
  }

  // ── started-with memory counts (robot-memory Req 7.6) ───────────────────────

  // The Appendix A Memories_Block heading, inserted into each turn's situation
  // text when the robot's Memory_Snapshot for the episode is non-empty. It is
  // the fixed line SituationTextGenerator::MEMORIES_HEADING renders verbatim.
  var MEMORIES_HEADING =
    'Your memories (what you wrote down after an earlier disaster in this house):';

  /**
   * renderStartedWith(view) — show, for each robot, how many memories it
   * started the loaded episode with (Req 7.6). The count is the length of that
   * robot's stored Memory_Snapshot, read from the episode view: the per-turn
   * situation text already carries the verbatim Memories_Block (one `"""` block
   * per remembered memory), so the started-with count is the number of memory
   * blocks in that robot's Memories_Block. A robot that started with no
   * memories has no Memories_Block and shows a count of zero.
   *
   * Names the robots only Amber (robot_a) and Jade (robot_b) (Req 7.7). Reads
   * only the already-loaded view — no AI call (Req 8.6).
   */
  function renderStartedWith(view) {
    var turns = (view && Array.isArray(view.turns)) ? view.turns : [];
    fillStartedWith(el.startedAmber, countSnapshotFor(turns, 'robot_a'));
    fillStartedWith(el.startedJade, countSnapshotFor(turns, 'robot_b'));
  }

  // Fill one robot's started-with line as plain text (never innerHTML). The
  // robot is identified by which reply panel the line sits in (its <h3> already
  // names Amber / Jade, Req 7.7); the line itself names no AI company or model.
  function fillStartedWith(host, count) {
    if (!host) { return; }
    var n = (isFinite(count) && count >= 0) ? Math.floor(count) : 0;
    var noun = (n === 1) ? 'memory' : 'memories';
    host.textContent = 'Started this episode with ' + n + ' ' + noun + '.';
    host.hidden = false;
  }

  /**
   * countSnapshotFor(turns, robotId) — the length of a robot's stored
   * Memory_Snapshot for the episode, derived from the situation text the robot
   * received (Req 7.6). Returns the number of `"""`-delimited memory blocks in
   * that robot's Memories_Block, or 0 when no turn carries a Memories_Block
   * (the robot started with no memories). The snapshot is frozen for the whole
   * episode, so any turn's situation text yields the same count; the first turn
   * that has a non-empty block is used.
   */
  function countSnapshotFor(turns, robotId) {
    for (var i = 0; i < turns.length; i++) {
      var turn = turns[i];
      var layers = turn && turn.robots && turn.robots[robotId];
      var text = layers && layers.situationText;
      if (typeof text !== 'string' || text === '') { continue; }
      var headingAt = text.indexOf(MEMORIES_HEADING);
      if (headingAt < 0) {
        // This turn carried no Memories_Block. The snapshot is frozen for the
        // whole episode, so no later turn can carry one either; the robot
        // started with no memories.
        return 0;
      }
      return countMemoryBlocks(text, headingAt + MEMORIES_HEADING.length);
    }
    return 0;
  }

  /**
   * countMemoryBlocks(text, from) — count the `"""`-delimited memory blocks of
   * the Memories_Block that begins at `from` (just after the heading). The
   * block's form is a heading, a blank line, then each verbatim body wrapped as
   * a line `"""`, the body, a line `"""`, with blank lines between blocks
   * (SituationTextGenerator::buildMemoriesBlock). The block ends at the first
   * blank line that is NOT inside or between `"""` wrappers — i.e. where the
   * recent-events / "Right now:" section begins. Counting the opening `"""`
   * delimiters (every other `"""` line, starting with the first) gives the
   * number of memories, which equals the stored Memory_Snapshot length.
   */
  function countMemoryBlocks(text, from) {
    var lines = text.slice(from).split('\n');
    var fences = 0;      // running count of `"""` fence lines seen
    var memories = 0;    // completed memory blocks (a pair of fences)
    var inside = false;  // true while between a block's opening and closing fence
    for (var i = 0; i < lines.length; i++) {
      var line = lines[i];
      if (line === '"""') {
        fences += 1;
        inside = !inside;              // toggle: opening ⇄ closing fence
        if (!inside) { memories += 1; } // a closing fence completes one block
        continue;
      }
      // While inside a `"""` block, every line (including blank ones) is part
      // of the verbatim body, so never stop here.
      if (inside) { continue; }
      // Outside a block: a non-blank line that is not a fence is the start of
      // the recent-events / "Right now:" section, so the Memories_Block has
      // ended. Blank lines outside a block are the separators between blocks
      // (and the blank line before the first block), so they are skipped.
      if (line !== '') { break; }
    }
    return memories;
  }

  // ── reply panels + evidence highlighting (Req 9.2–9.6) ──────────────────────

  // Fill both reply panels for a turn. The CSS media query places the panels
  // beside the stage at >= 768px and below it under 768px (Req 9.2, 9.3); this
  // only fills the text and its evidence highlights.
  function renderReplies(turn) {
    var robots = (turn && turn.robots) || {};
    fillReply(el.replyAmberText, robots.robot_a);
    fillReply(el.replyJadeText, robots.robot_b);
  }

  // Fill one reply panel from a robot's layers. A no-reply robot (or a layer
  // with no reply text) shows "(no reply)"; otherwise the reply is rendered as
  // safe text nodes with the Evidence_Quotes wrapped in highlight spans.
  function fillReply(host, layers) {
    if (!host) { return; }
    host.textContent = '';
    if (!layers || layers.noReply || !layers.reply) {
      host.appendChild(document.createTextNode('(no reply)'));
      return;
    }
    var frag = buildHighlightedReply(String(layers.reply), layers.reading || {});
    host.appendChild(frag);
  }

  /**
   * buildHighlightedReply(reply, reading) — return a DocumentFragment of the
   * reply text with the Evidence_Quotes wrapped in highlight spans (Req
   * 9.4–9.6).
   *
   * - The ACTION's quote (`reading.actionQuote`) is wrapped in a span with
   *   class `evidence-action` (solid underline, Req 9.4).
   * - Each word PASSED ON with a quote (`reading.reported[].quote`) is wrapped
   *   in a span with class `evidence-words` (dotted underline, Req 9.5).
   * - With no quote (action quote null/empty and no reported quotes) the reply
   *   is rendered as plain text, no span (Req 9.6).
   *
   * XSS: the reply is untrusted model text, so it is never put through
   * innerHTML. The non-highlighted stretches are emitted as text nodes and the
   * highlighted stretches as spans whose textContent is the exact substring —
   * all content goes in as text, never as markup.
   *
   * Not-found / overlap handling: each quote is located as an EXACT substring
   * of the reply (first occurrence). A quote that is not found is skipped (no
   * corruption). Overlapping quotes are resolved by sorting the found ranges by
   * start, and, where two ranges overlap, the earlier range wins and the later
   * overlapping one is dropped; the action quote is seeded first so it wins
   * ties against a words quote starting at the same offset.
   */
  function buildHighlightedReply(reply, reading) {
    var frag = document.createDocumentFragment();

    // Collect the candidate quotes. The action quote is added FIRST so, on an
    // exact tie of start offset, it is preferred over a words quote (Req 9.4
    // over 9.5).
    var ranges = [];
    var actionQuote = reading && reading.actionQuote;
    if (actionQuote) {
      addRange(ranges, reply, String(actionQuote), 'evidence-action');
    }
    var reported = (reading && reading.reported) || [];
    if (Array.isArray(reported)) {
      for (var i = 0; i < reported.length; i++) {
        var q = reported[i] && reported[i].quote;
        if (q) { addRange(ranges, reply, String(q), 'evidence-words'); }
      }
    }

    // No Evidence_Quote found: render the plain reply as a single text node
    // (Req 9.6).
    if (ranges.length === 0) {
      frag.appendChild(document.createTextNode(reply));
      return frag;
    }

    // Order the ranges, dropping any that overlap one already kept (the earlier
    // start wins; the action quote, seeded first, wins an exact-start tie).
    ranges.sort(function (a, b) {
      if (a.start !== b.start) { return a.start - b.start; }
      return a.order - b.order;
    });
    var kept = [];
    var lastEnd = 0;
    for (var j = 0; j < ranges.length; j++) {
      if (ranges[j].start >= lastEnd) {
        kept.push(ranges[j]);
        lastEnd = ranges[j].end;
      }
      // else: overlaps a kept range → skip it.
    }

    // Walk the reply, emitting plain text between kept ranges and a highlight
    // span for each kept range. All text goes in via text nodes / textContent,
    // never innerHTML, so the model text cannot inject markup.
    var cursor = 0;
    for (var k = 0; k < kept.length; k++) {
      var r = kept[k];
      if (r.start > cursor) {
        frag.appendChild(document.createTextNode(reply.slice(cursor, r.start)));
      }
      var span = document.createElement('span');
      span.className = r.className;
      span.textContent = reply.slice(r.start, r.end);
      frag.appendChild(span);
      cursor = r.end;
    }
    if (cursor < reply.length) {
      frag.appendChild(document.createTextNode(reply.slice(cursor)));
    }
    return frag;
  }

  // Record the first exact-substring occurrence of `quote` within `reply` as a
  // range { start, end, className, order }. A quote not found as an exact
  // substring is skipped (so a mismatch never corrupts the text). `order`
  // preserves insertion order so the action quote (added first) wins ties.
  function addRange(ranges, reply, quote, className) {
    if (!quote) { return; }
    var start = reply.indexOf(quote);
    if (start < 0) { return; }
    ranges.push({
      start: start,
      end: start + quote.length,
      className: className,
      order: ranges.length
    });
  }

  // ── playback controls (Req 9.1, 9.8–9.11) ───────────────────────────────────

  /**
   * play() — begin/resume acting out from the current turn and advance
   * automatically through the later turns, stopping at the last turn (Req 9.1).
   *
   * Guarded by `playing` so a second Play press while already playing does not
   * start a second loop. Each turn is acted out via renderTurn(..., true); when
   * its playTurn promise resolves, the loop advances to the next turn unless
   * Pause cleared `playing` or the last turn has been reached.
   */
  function play() {
    if (playing) { return; }
    if (!sortedTurns.length) { return; }
    playing = true;
    if (el.toldHost) { el.toldHost.hidden = true; }

    function step() {
      if (!playing) { return; }                   // paused between turns.
      renderTurn(currentTurnIndex, true).then(function () {
        if (!playing) { return; }                 // paused during this turn.
        if (currentTurnIndex >= sortedTurns.length - 1) {
          // Last turn finished acting out — stop advancing (Req 9.11 bound).
          playing = false;
          return;
        }
        currentTurnIndex += 1;
        step();
      });
    }
    step();
  }

  /**
   * pausePlayback() — stop advancing after the current turn's acting-out
   * finishes (Req 9.1). Clearing `playing` makes the Play loop's `.then`
   * not advance; the in-flight turn is allowed to finish acting out and end in
   * its recorded world, mirroring Observe's loop discipline (no mid-turn cut).
   */
  function pausePlayback() {
    playing = false;
  }

  /**
   * stepBack() — move to the previous turn unless on the first turn, where it
   * stays (Req 9.8, 9.9). A step pauses any auto-play, then renders and acts
   * out exactly that one turn.
   */
  function stepBack() {
    playing = false;
    if (el.toldHost) { el.toldHost.hidden = true; }
    if (currentTurnIndex > 0) {
      currentTurnIndex -= 1;             // Req 9.8: move to the previous turn.
    }
    // Req 9.9: on the first turn, stay put (index unchanged).
    renderTurn(currentTurnIndex, true);
  }

  /**
   * stepForward() — move to the next turn unless on the last turn, where it
   * stays (Req 9.10, 9.11). A step pauses any auto-play, then renders and acts
   * out exactly that one turn.
   */
  function stepForward() {
    playing = false;
    if (el.toldHost) { el.toldHost.hidden = true; }
    if (currentTurnIndex < sortedTurns.length - 1) {
      currentTurnIndex += 1;             // Req 9.10: move to the next turn.
    }
    // Req 9.11: on the last turn, stay put (index unchanged).
    renderTurn(currentTurnIndex, true);
  }

  /**
   * showWhatTold() — open the stored situation text each robot received for the
   * current turn (Req 9.7, reading `RobotFourLayers.situationText`).
   *
   * The design asks for "the text that robot received for that turn"; with two
   * reply panels and no per-robot selector in the markup, this shows BOTH
   * robots' situation texts for the current turn, each labelled Amber / Jade.
   * It is rendered as plain text (textContent) — the situation text is stored
   * model-facing prompt text, so it is never put through innerHTML. Toggles the
   * panel closed if it is already open for this turn.
   */
  function showWhatTold() {
    if (!el.toldHost) { return; }

    // Toggle: a second press closes the panel.
    if (!el.toldHost.hidden) {
      el.toldHost.hidden = true;
      return;
    }

    var turn = turnAt(currentTurnIndex);
    var robots = (turn && turn.robots) || {};
    el.toldHost.textContent = '';
    el.toldHost.className = 'message';

    appendToldBlock('Amber', robots.robot_a);
    appendToldBlock('Jade', robots.robot_b);

    if (!el.toldHost.childNodes.length) {
      el.toldHost.appendChild(
        document.createTextNode('No stored situation text for this turn.'));
    }
    el.toldHost.hidden = false;
  }

  // Append one labelled "what it was told" block for a robot's layers. The
  // label names only Amber / Jade (Req 12.8); the situation text is added as a
  // text node (no innerHTML, Req 9.7).
  function appendToldBlock(label, layers) {
    if (!el.toldHost) { return; }
    var situation = layers && layers.situationText;
    if (!situation) { return; }
    var heading = document.createElement('div');
    heading.textContent = label;
    el.toldHost.appendChild(heading);
    var body = document.createElement('div');
    body.style.whiteSpace = 'pre-wrap';
    body.style.marginBottom = '10px';
    body.textContent = String(situation);
    el.toldHost.appendChild(body);
  }

  /**
   * chooseAnother() — leave playback and return to the Episode_List (Req 8.2).
   *
   * Stops any auto-play, hides the playback region and its controls, and
   * re-fetches/shows the list via showList() (which only calls the read-only
   * GET /api/episodes?ended=1&limit=20 — NO AI call, Req 8.6). The direct-load
   * path uses ?episode=<id> in the URL, so we also clear that query via
   * history.replaceState (when available) so a refresh shows the list rather
   * than reloading the episode. Falling back to showList() alone is enough when
   * history is unavailable.
   */
  function chooseAnother() {
    playing = false;
    // Drop the ?episode=<id> query so a refresh shows the list (optional, Req
    // 8.2). Guarded: when history is unavailable we still return to the list.
    try {
      if (typeof window !== 'undefined' && window.history &&
          typeof window.history.replaceState === 'function' && window.location) {
        var base = (window.location.pathname || 'replay.php');
        window.history.replaceState(null, '', base);
      }
    } catch (e) { /* ignore — showList() below still returns to the list */ }
    clearNotice();
    showList();
  }

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
    if (el.btnChoose) { el.btnChoose.addEventListener('click', chooseAnother); }
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
    el.startedAmber = document.getElementById('started-amber');
    el.startedJade = document.getElementById('started-jade');
    el.controls = document.getElementById('controls');
    el.btnPlay = document.getElementById('btn-play');
    el.btnPause = document.getElementById('btn-pause');
    el.btnStepBack = document.getElementById('btn-stepback');
    el.btnStepForward = document.getElementById('btn-stepforward');
    el.btnTold = document.getElementById('btn-told');
    el.btnChoose = document.getElementById('btn-choose');
    el.turnIndicator = document.getElementById('turn-indicator');
    el.disasterLabel = document.getElementById('disaster-label');
    el.stageCol = document.getElementById('stage-col');
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
    // Started-with memory counts (robot-memory Req 7.6), exposed so a headless
    // check can assert the per-robot count equals the stored snapshot length.
    renderStartedWith: renderStartedWith,
    countSnapshotFor: countSnapshotFor,
    // Playback seam task 10.2 replaces to drive turn-by-turn playback.
    startPlayback: startPlayback,
    play: play,
    pausePlayback: pausePlayback,
    stepBack: stepBack,
    stepForward: stepForward,
    showWhatTold: showWhatTold,
    chooseAnother: chooseAnother,
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
