<?php

declare(strict_types=1);

/**
 * Debug_Page (turn-loop Requirement 10): a single plain-HTML page that drives
 * one earthquake episode end to end through the JSON API, one turn per advance
 * action, and shows the Four_Layers per robot per applied turn.
 *
 * This file holds no rules logic and makes no provider call. It only emits the
 * no-store header (Requirement 10.5) and a static HTML/JS page. All episode
 * work happens in the browser against api/index.php. The two robots are shown
 * as "Amber" (robot_a) and "Jade" (robot_b); neither AI provider is named
 * anywhere on the page (Requirements 10.7, 10.8).
 *
 * The API is reached through the query-string form api/index.php?route=... so
 * it works even when mod_rewrite is unavailable, and every fetch sends
 * credentials: 'same-origin' so the HttpOnly device cookie round-trips.
 */

// Requirement 10.5: the page itself must not be cached by the nginx cache.
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Turn-loop debug</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DotGothic16&display=swap" rel="stylesheet">
<style>
  :root {
    --bg: #14161b;
    --panel: #1e2129;
    --panel-2: #262a34;
    --line: #353a47;
    --text: #e6e8ee;
    --muted: #9aa1b2;
    --amber: #e0a94a;
    --jade: #4ac6a6;
    --mark: #6b4a12;
    --bad: #c05a5a;
    --ok: #4a8bd0;
  }
  * { box-sizing: border-box; }
  body {
    font-family: 'DotGothic16', monospace;
    margin: 0;
    background: var(--bg);
    color: var(--text);
    line-height: 1.6;
  }
  header {
    position: sticky;
    top: 0;
    z-index: 2;
    background: var(--panel);
    border-bottom: 1px solid var(--line);
    padding: 12px 16px;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
  }
  h1 { font-size: 16px; margin: 0 12px 0 0; }
  button {
    font-family: inherit;
    font-size: 13px;
    color: var(--text);
    background: var(--panel-2);
    border: 1px solid var(--line);
    border-radius: 4px;
    padding: 6px 12px;
    cursor: pointer;
  }
  button:hover:not(:disabled) { border-color: var(--text); }
  button:disabled { opacity: 0.4; cursor: default; }
  #status { color: var(--muted); font-size: 13px; }
  main { padding: 16px; max-width: 1200px; margin: 0 auto; }
  .turn {
    border: 1px solid var(--line);
    border-radius: 6px;
    margin-bottom: 20px;
    overflow: hidden;
  }
  .turn > h2 {
    font-size: 14px;
    margin: 0;
    padding: 8px 12px;
    background: var(--panel);
    border-bottom: 1px solid var(--line);
  }
  .resident { padding: 8px 12px; color: var(--muted); font-size: 13px; border-bottom: 1px solid var(--line); }
  .robots { display: grid; grid-template-columns: 1fr 1fr; gap: 1px; background: var(--line); }
  @media (max-width: 760px) { .robots { grid-template-columns: 1fr; } }
  .robot { background: var(--panel); padding: 12px; }
  .robot > h3 { font-size: 14px; margin: 0 0 8px; }
  .robot.amber > h3 { color: var(--amber); }
  .robot.jade > h3 { color: var(--jade); }
  .layer { margin: 0 0 12px; }
  .layer > .label {
    font-size: 11px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 3px;
  }
  .layer .body {
    background: var(--panel-2);
    border: 1px solid var(--line);
    border-radius: 4px;
    padding: 8px;
    font-size: 13px;
    white-space: pre-wrap;
    word-break: break-word;
  }
  .layer .body.empty { color: var(--muted); font-style: italic; }
  mark { background: var(--mark); color: #ffe6b0; border-radius: 2px; padding: 0 1px; }
  .kv { font-size: 12px; color: var(--muted); }
  .kv b { color: var(--text); font-weight: normal; }
  .tag {
    display: inline-block;
    font-size: 11px;
    border: 1px solid var(--line);
    border-radius: 3px;
    padding: 1px 6px;
    margin-right: 6px;
  }
  .tag.ok { color: var(--jade); border-color: var(--jade); }
  .tag.bad { color: var(--bad); border-color: var(--bad); }
  .tag.unknown { color: var(--muted); }
  .reported { margin: 4px 0 0; padding-left: 16px; font-size: 12px; color: var(--muted); }
</style>
</head>
<body>
<header>
  <h1>Turn-loop debug</h1>
  <button id="startBtn" type="button">Start episode</button>
  <button id="advanceBtn" type="button" disabled>Advance one turn</button>
  <span id="status">Ready.</span>
</header>
<main id="turns" aria-live="polite"></main>

<script>
(function () {
  'use strict';

  // ---- Fixed, non-provider robot labels (Requirement 10.8). ----
  var LABELS = { robot_a: 'Amber', robot_b: 'Jade' };
  var ORDER = ['robot_a', 'robot_b'];

  var state = {
    episodeId: null,
    ended: false,
    rendered: {} // turnNumber -> true, so each applied turn renders once
  };

  var startBtn = document.getElementById('startBtn');
  var advanceBtn = document.getElementById('advanceBtn');
  var statusEl = document.getElementById('status');
  var turnsEl = document.getElementById('turns');

  function setStatus(text) { statusEl.textContent = text; }

  function busy(on) {
    startBtn.disabled = on;
    advanceBtn.disabled = on || state.episodeId === null || state.ended;
  }

  // ---- API helpers. The ?route= form works without mod_rewrite; the device
  // cookie is HttpOnly, so credentials: 'same-origin' carries it. ----
  function apiUrl(route) {
    return '../api/index.php?route=' + encodeURIComponent(route);
  }

  function apiPost(route) {
    return fetch(apiUrl(route), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    }).then(readJson);
  }

  function readJson(resp) {
    return resp.json().then(function (body) {
      return { status: resp.status, body: body };
    }, function () {
      return { status: resp.status, body: null };
    });
  }

  // ---- DOM building helpers (textContent only; no untrusted HTML). ----
  function el(tag, className, text) {
    var n = document.createElement(tag);
    if (className) { n.className = className; }
    if (text !== undefined && text !== null) { n.textContent = text; }
    return n;
  }

  // Build a fragment of the reply with every evidence quote wrapped in <mark>,
  // inserting marks as real DOM nodes so the reply text is never treated as
  // HTML. Quotes are the EvidenceCheck-backed actionQuote and words[] entries.
  function highlighted(reply, quotes) {
    var frag = document.createDocumentFragment();
    reply = (typeof reply === 'string') ? reply : '';

    // Keep only non-empty quotes that actually occur in the reply, longest
    // first so a longer quote wins over a shorter overlapping one.
    var list = [];
    (quotes || []).forEach(function (q) {
      if (typeof q === 'string' && q.length > 0 && reply.indexOf(q) !== -1) {
        list.push(q);
      }
    });
    list.sort(function (a, b) { return b.length - a.length; });

    if (list.length === 0) {
      frag.appendChild(document.createTextNode(reply));
      return frag;
    }

    // Walk the reply left to right, at each position taking the earliest
    // occurrence of any quote; text before it is plain, the quote is marked.
    var i = 0;
    while (i < reply.length) {
      var bestPos = -1;
      var bestLen = 0;
      for (var k = 0; k < list.length; k++) {
        var pos = reply.indexOf(list[k], i);
        if (pos !== -1 && (bestPos === -1 || pos < bestPos ||
            (pos === bestPos && list[k].length > bestLen))) {
          bestPos = pos;
          bestLen = list[k].length;
        }
      }
      if (bestPos === -1) {
        frag.appendChild(document.createTextNode(reply.slice(i)));
        break;
      }
      if (bestPos > i) {
        frag.appendChild(document.createTextNode(reply.slice(i, bestPos)));
      }
      var m = document.createElement('mark');
      m.textContent = reply.slice(bestPos, bestPos + bestLen);
      frag.appendChild(m);
      i = bestPos + bestLen;
    }
    return frag;
  }

  function layer(label, buildBody) {
    var wrap = el('div', 'layer');
    wrap.appendChild(el('div', 'label', label));
    var body = el('div', 'body');
    buildBody(body);
    wrap.appendChild(body);
    return wrap;
  }

  // ---- Render one robot's Four_Layers (Requirements 10.2, 10.3, 10.6). ----
  function renderRobot(robotId, layers) {
    var box = el('div', 'robot ' + (robotId === 'robot_a' ? 'amber' : 'jade'));
    box.appendChild(el('h3', null, LABELS[robotId]));

    var reading = layers.reading || {};
    var judgement = layers.judgement || {};
    var outcome = layers.outcome || {};
    var noReply = layers.noReply === true;

    // Layer 1: situation text the robot was sent.
    box.appendChild(layer('Situation sent', function (body) {
      body.textContent = layers.situationText || '';
    }));

    // Layer 2: the robot's reply (absent for a no-reply robot).
    box.appendChild(layer('Reply', function (body) {
      if (noReply) {
        body.classList.add('empty');
        body.textContent = '(no reply)';
        return;
      }
      var quotes = [];
      if (typeof reading.actionQuote === 'string') { quotes.push(reading.actionQuote); }
      if (Array.isArray(reading.words)) {
        reading.words.forEach(function (w) { quotes.push(w); });
      }
      body.appendChild(highlighted(layers.reply || '', quotes));
    }));

    // Layer 3: the recorder's reading. For a no-reply robot the action is
    // unknown; evidence quotes above are highlighted inside the reply.
    box.appendChild(layer('Reading', function (body) {
      var op = noReply ? 'unknown' : (reading.operation || 'unknown');
      var opLine = el('div', 'kv');
      opLine.appendChild(el('b', null, 'operation: '));
      opLine.appendChild(document.createTextNode(op));
      body.appendChild(opLine);

      if (reading.targetId) {
        var t = el('div', 'kv');
        t.appendChild(el('b', null, 'target: '));
        t.appendChild(document.createTextNode(reading.targetId +
          (reading.targetSpot ? (' @ ' + reading.targetSpot) : '')));
        body.appendChild(t);
      } else if (reading.targetSpot) {
        var ts = el('div', 'kv');
        ts.appendChild(el('b', null, 'spot: '));
        ts.appendChild(document.createTextNode(reading.targetSpot));
        body.appendChild(ts);
      }

      if (Array.isArray(reading.words) && reading.words.length > 0) {
        var w = el('div', 'kv');
        w.appendChild(el('b', null, 'said: '));
        w.appendChild(document.createTextNode(reading.words.join(' / ')));
        body.appendChild(w);
      }

      if (Array.isArray(reading.reported) && reading.reported.length > 0) {
        var ul = el('ul', 'reported');
        reading.reported.forEach(function (r) {
          var li = document.createElement('li');
          li.textContent = (r && r.heardAs ? r.heardAs : '') +
            (r && r.ownForm ? (' (as: ' + r.ownForm + ')') : '');
          ul.appendChild(li);
        });
        body.appendChild(ul);
      }
    }));

    // Layer 4: the judgement and the outcome.
    box.appendChild(layer('Judgement', function (body) {
      var possible = el('span', 'tag ' + (judgement.possible ? 'ok' : 'bad'),
        judgement.possible ? 'possible' : 'not possible');
      body.appendChild(possible);
      if (judgement.dangerous) {
        body.appendChild(el('span', 'tag bad',
          'dangerous' + (judgement.dangerCode ? (': ' + judgement.dangerCode) : '')));
      }
      if (judgement.reason) {
        body.appendChild(el('div', 'kv', judgement.reason));
      }
    }));

    box.appendChild(layer('Outcome', function (body) {
      body.appendChild(el('span', 'tag ' + (outcome.success ? 'ok' : 'bad'),
        outcome.success ? 'success' : 'no effect'));
      if (outcome.whatHappenedText) {
        body.appendChild(el('div', 'kv', outcome.whatHappenedText));
      }
      if (outcome.seenByOtherText) {
        var seen = el('div', 'kv');
        seen.appendChild(el('b', null, 'seen by other: '));
        seen.appendChild(document.createTextNode(outcome.seenByOtherText));
        body.appendChild(seen);
      }
    }));

    return box;
  }

  function renderTurn(turn) {
    var card = el('div', 'turn');
    card.appendChild(el('h2', null, 'Turn ' + turn.turnNumber));
    if (turn.residentLine) {
      var r = el('div', 'resident');
      r.appendChild(el('b', null, 'Resident: '));
      r.appendChild(document.createTextNode(turn.residentLine));
      card.appendChild(r);
    }
    var robots = el('div', 'robots');
    var map = turn.robots || {};
    ORDER.forEach(function (robotId) {
      if (map[robotId]) {
        robots.appendChild(renderRobot(robotId, map[robotId]));
      }
    });
    card.appendChild(robots);
    turnsEl.appendChild(card);
  }

  // Render any applied turns we have not shown yet (Requirement 10.2).
  function renderNewAppliedTurns(view) {
    if (!view || !Array.isArray(view.turns)) { return; }
    view.turns.forEach(function (turn) {
      if (turn.state === 'applied' && !state.rendered[turn.turnNumber]) {
        state.rendered[turn.turnNumber] = true;
        renderTurn(turn);
      }
    });
  }

  function applyView(view) {
    if (!view) { return; }
    if (typeof view.episodeId === 'string') { state.episodeId = view.episodeId; }
    state.ended = view.ended === true;
    renderNewAppliedTurns(view);
  }

  // ---- Start: create an episode, then flip the earthquake switch. ----
  function start() {
    busy(true);
    setStatus('Creating episode...');
    turnsEl.textContent = '';
    state = { episodeId: null, ended: false, rendered: {} };

    apiPost('episodes').then(function (res) {
      if (res.status !== 200 || !res.body || !res.body.episodeId) {
        throw new Error('create failed (' + res.status + ')');
      }
      applyView(res.body);
      setStatus('Flipping earthquake switch...');
      return apiPost('episodes/' + state.episodeId + '/disaster');
    }).then(function (res) {
      if (res.status !== 200) { throw new Error('disaster failed (' + res.status + ')'); }
      applyView(res.body);
      setStatus('Episode ready. Click "Advance one turn".');
      busy(false);
    }).catch(function (err) {
      setStatus('Error: ' + err.message);
      busy(false);
    });
  }

  // ---- Advance one turn: ask (re-ask while a robot is missing), then apply. ----
  // One advance action plays exactly one turn; the user clicks again for the
  // next. We stop offering to advance once the episode has ended.
  function currentExpectedTurn(view) {
    return (view && typeof view.currentExpectedTurn === 'number')
      ? view.currentExpectedTurn : null;
  }

  // Inspect the ask response for the active turn: are both robots settled
  // (a reply or marked no-reply)? If not, we ask again before apply.
  function turnReady(view, turnNumber) {
    if (!view || !Array.isArray(view.turns)) { return false; }
    for (var i = 0; i < view.turns.length; i++) {
      var t = view.turns[i];
      if (t.turnNumber !== turnNumber) { continue; }
      return t.state === 'replies_complete' || t.state === 'applied';
    }
    return false;
  }

  function advance() {
    if (state.episodeId === null || state.ended) { return; }
    busy(true);

    var turnNumber = null;

    // Read the episode to learn the current expected turn, then ask/apply it.
    fetch(apiUrl('episodes/' + state.episodeId), {
      method: 'GET', credentials: 'same-origin', headers: { 'Accept': 'application/json' }
    }).then(readJson).then(function (res) {
      if (res.status !== 200 || !res.body) { throw new Error('read failed (' + res.status + ')'); }
      applyView(res.body);
      if (state.ended) { throw new Error('__ended__'); }
      turnNumber = currentExpectedTurn(res.body);
      if (turnNumber === null) { throw new Error('no expected turn'); }
      return askUntilReady(turnNumber, 4);
    }).then(function (view) {
      setStatus('Applying turn ' + turnNumber + '...');
      return apiPost('episodes/' + state.episodeId + '/turns/' + turnNumber + '/apply');
    }).then(function (res) {
      if (res.status !== 200) { throw new Error('apply failed (' + res.status + ')'); }
      applyView(res.body);
      if (state.ended) {
        setStatus('Episode ended. No more turns.');
        startBtn.disabled = false;
        advanceBtn.disabled = true;
      } else {
        setStatus('Turn ' + turnNumber + ' applied. Click to advance.');
        busy(false);
      }
    }).catch(function (err) {
      if (err && err.message === '__ended__') {
        setStatus('Episode ended. No more turns.');
        startBtn.disabled = false;
        advanceBtn.disabled = true;
        return;
      }
      setStatus('Error: ' + (err ? err.message : 'unknown'));
      busy(false);
    });
  }

  // Call ask; if the turn is still waiting (a robot missing), re-call ask up
  // to `tries` times before we give up and let apply run on what we have.
  function askUntilReady(turnNumber, tries) {
    setStatus('Asking robots for turn ' + turnNumber + '...');
    return apiPost('episodes/' + state.episodeId + '/turns/' + turnNumber + '/ask')
      .then(function (res) {
        if (res.status !== 200) { throw new Error('ask failed (' + res.status + ')'); }
        applyView(res.body);
        if (turnReady(res.body, turnNumber) || tries <= 1) {
          return res.body;
        }
        return askUntilReady(turnNumber, tries - 1);
      });
  }

  startBtn.addEventListener('click', start);
  advanceBtn.addEventListener('click', advance);
})();
</script>
</body>
</html>
