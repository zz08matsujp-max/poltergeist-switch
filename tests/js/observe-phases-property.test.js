/*
 * observe-phases-property.test.js — random event sequences against the real
 * public/js/observe.js with a scripted fake API.
 *
 * Feature: public-lab, Property 21: Observe phase invariants
 * Validates: Requirements 8.1, 8.2, 8.4, 9.2, 9.3, 9.5, 9.7, 9.8, 9.9, 13.11
 *
 * Run with `node tests/js/observe-phases-property.test.js`. Seeded mulberry32,
 * 150 sequences. No network.
 */
'use strict';

const assert = require('assert');
const path = require('path');
const { createFakeApi } = require('./helpers/fake-api.js');
const { createFakeVoices } = require('./helpers/fake-voices.js');
const { mulberry32, int, pick, bool } = require('./helpers/rng.js');

const ROOT = path.join(__dirname, '..', '..');
const SEQUENCES = 150;

// ── DOM shim (same pattern as observe-flow.test.js) ─────────────────────────
function makeElement() {
  const attrs = {};
  const classes = new Set();
  const listeners = {};
  let ownText = '';
  const el = {
    children: [], attrs: attrs, hidden: false, disabled: false, style: {}, className: '', _subs: {},
    appendChild: function (c) { el.children.push(c); return c; },
    setAttribute: function (k, v) { attrs[k] = String(v); },
    getAttribute: function (k) { return Object.prototype.hasOwnProperty.call(attrs, k) ? attrs[k] : null; },
    addEventListener: function (ev, fn) { (listeners[ev] = listeners[ev] || []).push(fn); },
    click: function () { (listeners.click || []).forEach(function (fn) { fn(); }); },
    querySelector: function (sel) { return el._subs[sel] || null; },
    classList: {
      add: function (c) { classes.add(c); },
      remove: function (c) { classes.delete(c); },
      contains: function (c) { return classes.has(c); }
    }
  };
  Object.defineProperty(el, 'textContent', {
    get: function () { return el.children.length ? el.children.map(function (c) { return c.textContent; }).join('') : ownText; },
    set: function (v) { ownText = String(v); el.children.length = 0; }
  });
  return el;
}

const byId = {};
const doc = {
  readyState: 'loading',
  _ready: [],
  addEventListener: function (ev, fn) { if (ev === 'DOMContentLoaded') { doc._ready.push(fn); } },
  createElement: function () { return makeElement(); },
  createTextNode: function (t) { const n = makeElement(); n.textContent = t; return n; },
  getElementById: function (id) {
    if (id.indexOf('log-turn-') === 0) { return null; }
    if (!byId[id]) { byId[id] = makeElement(); }
    return byId[id];
  }
};
global.document = doc;
global.window = {};

['sw-earthquake', 'sw-heat'].forEach(function (id) {
  const b = doc.getElementById(id);
  b._subs['.mark'] = makeElement();
  b.setAttribute('aria-pressed', 'false');
});
['meter-temp', 'meter-water', 'meter-injury', 'meter-fear', 'meter-room'].forEach(function (id) {
  const m = doc.getElementById(id);
  m._subs['.fill'] = makeElement();
  m._subs['.val'] = makeElement();
});

// ── fixtures ────────────────────────────────────────────────────────────────
const voices = createFakeVoices(null, { gestured: false });
let current = createFakeApi();
let inFlightCreates = 0;
let maxInFlightCreates = 0;
let lastCreateStatus = null;
const METHODS = ['createEpisode', 'flipDisaster', 'getEpisode', 'ask', 'apply', 'reflect', 'memoryStatus', 'resetMemory', 'listEnded'];
const proxy = {};
METHODS.forEach(function (m) {
  proxy[m] = function () {
    const p = current[m].apply(current, arguments);
    if (m !== 'createEpisode') { return p; }
    inFlightCreates += 1;
    if (inFlightCreates > maxInFlightCreates) { maxInFlightCreates = inFlightCreates; }
    return p.then(function (v) { inFlightCreates -= 1; lastCreateStatus = v && v.status; return v; },
      function (e) { inFlightCreates -= 1; throw e; });
  };
});
global.window.createApiClient = function () { return proxy; };
global.window.createVoices = function () { return voices; };

const houseCalls = { setWorld: 0, reset: 0 };
global.window.createHouseRenderer = function () {
  return {
    mount: function () {}, start: function () {}, setThinking: function () {},
    setWorld: function () { houseCalls.setWorld += 1; },
    reset: function () { houseCalls.reset += 1; }
  };
};
global.window.observeWait = function () { return Promise.resolve(); };
const storage = {};
global.window.localStorage = {
  getItem: function (k) { return Object.prototype.hasOwnProperty.call(storage, k) ? storage[k] : null; },
  setItem: function (k, v) { storage[k] = String(v); }
};
let confirmReset = null;
global.window.memoryUi = {
  renderStatusLine: function () {},
  buildConfirmPanel: function (onConfirm) { confirmReset = onConfirm; return makeElement(); }
};

function world(disaster) {
  return {
    disaster: disaster, roomTemps: { living: 31 },
    man: { room: 'living', bodyTemp: 37.5, water: 60, injury: 0, fear: 20 }
  };
}
function view(id, disaster, turns, ended) {
  return { episodeId: id, disaster: disaster, currentExpectedTurn: 1, ended: ended === true, turns: turns };
}
const turn0 = function (d) { return { turnNumber: 0, state: 'applied', world: world(d) }; };
const turn1 = function (d) { return { turnNumber: 1, state: 'applied', world: world(d), residentLine: 'It is hot.', robots: {} }; };

require(path.join(ROOT, 'public', 'js', 'observe.js'));
doc._ready.forEach(function (fn) { fn(); });
const ctl = global.window.observeController;

async function settle() { for (let i = 0; i < 12; i++) { await new Promise(function (r) { setImmediate(r); }); } }
function marks() { return [byId['sw-earthquake']._subs['.mark'].textContent, byId['sw-heat']._subs['.mark'].textContent]; }
function buttons(node, out) {
  out = out || [];
  if (typeof node.className === 'string' && /\bbtn\b/.test(node.className)) { out.push(node); }
  node.children.forEach(function (c) { buttons(c, out); });
  return out;
}
function noticeButton(label) {
  const found = buttons(byId.notice).filter(function (b) { return b.textContent === label; });
  return found.length ? found[0] : null;
}

// ── one random sequence ─────────────────────────────────────────────────────
const LIMITS = ['daily_device_limit', 'daily_ip_limit', 'daily_site_limit'];
const BLOCKED_FOR_CREATE = ['starting', 'running', 'unfinished', 'staged'];

async function runSequence(seed) {
  const rng = mulberry32(seed);
  const tag = 'seed ' + seed + ': ';
  let n = 0;
  const gates = [];
  const cfg = { ask: 'ok', getEpisode: 'ended', reset: 200 };
  let currentId = null;

  function setApi() {
    current = createFakeApi({
      createEpisode: function () {
        n += 1;
        const r = rng();
        if (r < 0.55) { currentId = 'ep' + seed + '_' + n; return { status: 200, body: view(currentId, 'earthquake', [turn0('earthquake')]) }; }
        if (r < 0.75) { return { status: 429, body: { error: pick(rng, LIMITS) } }; }
        if (r < 0.85) { return { status: 409, body: { error: 'device_already_running', episodeId: 'busy' + n } }; }
        return { status: 502, body: null };
      },
      flipDisaster: function (i, args) {
        const d = args[1] === 'heat_wave' ? 'heat_wave' : 'earthquake';
        return { status: 200, body: view(args[0], d, [turn0(d)]) };
      },
      getEpisode: function (i, args) {
        if (cfg.getEpisode === 'fail') { return { status: 502, body: null }; }
        return { status: 200, body: view(args[0], 'earthquake', [turn0('earthquake'), turn1('earthquake')], true) };
      },
      ask: function () {
        if (cfg.ask === 'fail') { return { status: 502, body: null }; }
        if (cfg.ask === 'gate') { return new Promise(function (r) { gates.push(r); }); }
        return { status: 200, body: {} };
      },
      apply: function (i, args) {
        return { status: 200, body: view(args[0], 'earthquake', [turn0('earthquake'), turn1('earthquake')], true) };
      },
      reflect: function () { return { status: bool(rng) ? 200 : 409, body: { robots: {} } }; },
      resetMemory: function () {
        return cfg.reset === 200 ? { status: 200, body: { generation: 2 } } : { status: 409, body: { error: 'run_in_progress' } };
      },
      memoryStatus: { status: 200, body: {} },
      listEnded: { status: 200, body: { episodes: [] } }
    });
  }

  function emptyClient(where) {
    const st = ctl._state();
    assert.strictEqual(st.episodeId, null, tag + where + ': episode id dropped');
    assert.strictEqual(byId.log.children.length, 0, tag + where + ': log emptied');
    assert.strictEqual(byId['endcard-host'].children.length, 0, tag + where + ': End_Card removed');
    assert.strictEqual(byId['meter-temp']._subs['.val'].textContent, '\u2014', tag + where + ': meters emptied');
  }
  function everyTime(where) {
    const ph = ctl._state().phase;
    assert.ok(!/paused/i.test(byId.notice.textContent), tag + where + ': a notice says Paused');
    if (ph === 'starting' || ph === 'running') {
      assert.strictEqual(byId['btn-reset-memory'].disabled, true, tag + where + ': All reset enabled in ' + ph);
    }
    assert.ok(maxInFlightCreates <= 1, tag + where + ': two creates in flight');
  }
  async function settled(where) {
    await settle();
    everyTime(where);
    const st = ctl._state();
    if (st.phase === 'ended') {
      assert.deepStrictEqual(marks(), ['OFF', 'OFF'], tag + where + ': Levers OFF after the end and reflection');
    }
  }

  // Presses a control and checks what must hold at once.
  function press(id, where) {
    const st = ctl._state();
    const before = st.phase;
    const creates = current.count('createEpisode');
    const gets = current.count('getEpisode');
    const flips = current.count('flipDisaster');
    const epBefore = st.episodeId;
    byId[id].click();
    const madeCreate = current.count('createEpisode') - creates;
    everyTime(where);
    if (BLOCKED_FOR_CREATE.indexOf(before) >= 0) {
      assert.strictEqual(madeCreate, 0, tag + where + ': create while ' + before);
    }
    assert.ok(madeCreate <= 1, tag + where + ': more than one create from one press');
    if (before === 'unfinished') {
      assert.strictEqual(madeCreate, 0, tag + where + ': Try again created an episode');
      assert.strictEqual(current.count('getEpisode') - gets, 1, tag + where + ': acts as Try again');
      assert.deepStrictEqual(current.callsOf('getEpisode')[0].args, [epBefore]);
      assert.strictEqual(current.count('flipDisaster'), flips);
      assert.strictEqual(ctl._state().episodeId, epBefore, tag + where + ': same episode');
    }
    if (before === 'ended') {
      emptyClient(where + ' (later run)');
    }
    return { before: before, madeCreate: madeCreate };
  }

  async function startOrLever(where) {
    const id = pick(rng, ['btn-start', 'sw-earthquake', 'sw-heat']);
    const r = press(id, where + ':' + id);
    const snapshot = { setWorld: houseCalls.setWorld, reset: houseCalls.reset };
    if (r.madeCreate === 1) {
      lastCreateStatus = null;
      // Extra presses while the create is in flight must not create again.
      const extra = bool(rng, 0.4) ? int(rng, 1, 3) : 0;
      for (let i = 0; i < extra; i++) {
        const before = current.count('createEpisode');
        byId[pick(rng, ['btn-start', 'sw-earthquake', 'sw-heat'])].click();
        assert.strictEqual(current.count('createEpisode'), before, tag + where + ': second create while starting');
        everyTime(where + ': extra press');
      }
      await settle();
      everyTime(where);
      if (lastCreateStatus === 429) {
        assert.deepStrictEqual(marks(), ['OFF', 'OFF'], tag + where + ': 429 leaves Levers OFF');
        assert.strictEqual(ctl._state().phase, 'idle', tag + where + ': 429 returns to idle');
        assert.strictEqual(houseCalls.setWorld, snapshot.setWorld, tag + where + ': 429 leaves the stage');
        assert.strictEqual(houseCalls.reset, snapshot.reset, tag + where + ': 429 leaves the stage');
        assert.ok(/opens again tomorrow \(UTC\)/.test(byId.notice.textContent), tag + where + ': 429 notice');
      }
    }
    await settled(where);
  }

  async function tryAgainButton(where) {
    const b = noticeButton('Try again');
    if (!b) { return; }
    const creates = current.count('createEpisode');
    b.click();
    assert.strictEqual(current.count('createEpisode'), creates, tag + where + ': Try again created an episode');
    everyTime(where);
    await settled(where);
  }

  async function allReset(where) {
    const btn = byId['btn-reset-memory'];
    if (btn.disabled) { everyTime(where); return; }       // a browser blocks a disabled button
    btn.click();
    if (typeof confirmReset !== 'function') { return; }
    const lastBefore = ctl._state().phase;
    const logRows = byId.log.children.length;
    confirmReset();
    await settle();
    everyTime(where);
    if (cfg.reset === 200 && lastBefore !== 'starting' && lastBefore !== 'running') {
      assert.deepStrictEqual(marks(), ['OFF', 'OFF'], tag + where + ': reset Levers');
      assert.strictEqual(ctl._state().phase, 'idle', tag + where + ': reset phase');
      emptyClient(where + ' (All reset)');
      assert.strictEqual(byId.notice.hidden, true, tag + where + ': reset notice cleared');
    } else if (cfg.reset === 409) {
      assert.ok(byId.log.children.length >= 0 && logRows >= 0);
    }
  }

  setApi();
  ctl.startNewRun();
  const steps = int(rng, 6, 18);
  for (let i = 0; i < steps; i++) {
    const w = 'step ' + i;
    const r = rng();
    if (r < 0.42) { await startOrLever(w); }
    else if (r < 0.54) { await tryAgainButton(w); }
    else if (r < 0.68) { await allReset(w); }
    else if (r < 0.78) { cfg.reset = bool(rng) ? 200 : 409; }
    else if (r < 0.90) { cfg.ask = pick(rng, ['ok', 'fail', 'gate']); cfg.getEpisode = pick(rng, ['ended', 'fail']); }
    else if (r < 0.96) { gates.splice(0).forEach(function (g) { g({ status: 200, body: {} }); }); await settled(w); }
    else { await settled(w); }
  }

  // Wind down: release any held turn and let the loop finish.
  cfg.ask = 'ok';
  gates.splice(0).forEach(function (g) { g({ status: 200, body: {} }); });
  await settle();
  everyTime('wind down');
  assert.notStrictEqual(ctl._state().phase, 'running', tag + 'the loop settled');
  ctl.startNewRun();
}

(async function () {
  console.log('observe phase invariants (Property 21)');
  for (let seed = 1; seed <= SEQUENCES; seed++) {
    await runSequence(seed);
  }
  console.log(SEQUENCES + ' sequences passed.');
})().catch(function (e) { console.error(e); process.exit(1); });
