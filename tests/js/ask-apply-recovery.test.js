/*
 * ask-apply-recovery.test.js — headless (Node) test for the Observe page's
 * recovery from a failed ask/apply (5xx or network error): wait, GET the
 * episode, continue from the stored turn state, pause after 3 failed tries.
 * Run with `node tests/js/ask-apply-recovery.test.js`. No network call; the
 * 5-second wait is injected through window.observeWait.
 */
'use strict';

const assert = require('assert');
const path = require('path');

const OBSERVE = path.join(__dirname, '..', '..', 'public', 'js', 'observe.js');

function makeElement() {
  const attrs = {};
  const classes = new Set();
  const listeners = {};
  const el = {
    children: [], attrs: attrs, hidden: false, disabled: false, style: {}, _subs: {},
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
    },
    textContent: ''
  };
  return el;
}

function settle() {
  return new Promise(function (r) { setImmediate(r); });
}

async function settleMany() {
  for (let i = 0; i < 30; i++) { await settle(); }
}

function appliedView(disaster) {
  return {
    episodeId: 'e1', disaster: disaster, currentExpectedTurn: 2, ended: false,
    turns: [{ turnNumber: 1, state: 'applied', robots: {} }]
  };
}

function storedView(disaster, turnState) {
  const turns = turnState ? [{ turnNumber: 1, state: turnState, robots: {} }] : [];
  return { episodeId: 'e1', disaster: disaster, currentExpectedTurn: 1, ended: false, turns: turns };
}

const OK = function (body) { return { status: 200, body: body }; };
const BAD_GATEWAY = function () { return { status: 502, body: null }; };

/**
 * Load a fresh observe.js with a scripted api. `script` has ask/apply/get
 * functions (called with the 0-based call index for that method) returning a
 * response, or throwing/rejecting for a network failure.
 */
async function run(disaster, script) {
  const byId = {};
  const doc = {
    readyState: 'loading',
    _ready: [],
    addEventListener: function (ev, fn) { if (ev === 'DOMContentLoaded') { doc._ready.push(fn); } },
    createElement: function () { return makeElement(); },
    createTextNode: function (t) { const n = makeElement(); n.textContent = t; return n; },
    getElementById: function (id) { if (!byId[id]) { byId[id] = makeElement(); } return byId[id]; }
  };
  global.document = doc;
  global.window = {};

  const log = [];
  const waits = [];
  const counts = { ask: 0, apply: 0, get: 0 };
  const start = storedView(disaster, null);
  const fakeApi = {
    createEpisode: function () { return Promise.resolve(OK(start)); },
    flipDisaster: function () { return Promise.resolve(OK(start)); },
    getEpisode: function () {
      log.push('get');
      return Promise.resolve().then(function () { return script.get(counts.get++); });
    },
    ask: function (id, turn) {
      log.push('ask' + turn);
      return Promise.resolve().then(function () { return script.ask(counts.ask++, turn); });
    },
    apply: function (id, turn) {
      log.push('apply' + turn);
      return Promise.resolve().then(function () { return script.apply(counts.apply++, turn); });
    }
  };

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
  global.window.createApiClient = function () { return fakeApi; };
  global.window.observeWait = function (ms) { waits.push(ms); log.push('wait'); return Promise.resolve(); };

  delete require.cache[require.resolve(OBSERVE)];
  require(OBSERVE);
  doc._ready.forEach(function (fn) { fn(); });

  byId[disaster === 'heat_wave' ? 'sw-heat' : 'sw-earthquake'].click();
  await settleMany();

  return { log: log, waits: waits, notice: byId.notice, counts: counts };
}

// After turn 1 is applied the loop prefetches turn 2's ask; it never resolves.
const NEVER = function () { return new Promise(function () { /* pending */ }); };

(async function () {
  console.log('ask/apply recovery');

  for (const disaster of ['earthquake', 'heat_wave']) {
    // Normal path: no GET, no wait.
    let r = await run(disaster, {
      ask: function (i, turn) { return turn === 1 ? OK({}) : NEVER(); },
      apply: function () { return OK(appliedView(disaster)); },
      get: function () { throw new Error('GET must not be used on the normal path'); }
    });
    assert.deepStrictEqual(r.log, ['ask1', 'apply1', 'ask2'], disaster + ': normal path unchanged');
    assert.deepStrictEqual(r.waits, [], disaster + ': no wait on the normal path');
    assert.strictEqual(r.notice.textContent, '', disaster + ': no notice');
    console.log('  ok - normal path (' + disaster + ')');
  }

  // 5xx on ask, stored replies_complete: apply without asking again.
  let r = await run('earthquake', {
    ask: function (i, turn) { return turn === 1 ? BAD_GATEWAY() : NEVER(); },
    apply: function () { return OK(appliedView('earthquake')); },
    get: function () { return OK(storedView('earthquake', 'replies_complete')); }
  });
  assert.deepStrictEqual(r.log, ['ask1', 'wait', 'get', 'apply1', 'ask2'], '5xx then replies_complete continues');
  assert.deepStrictEqual(r.waits, [5000], 'waits 5 seconds');
  assert.strictEqual(r.notice.textContent, '', 'no notice');
  console.log('  ok - 5xx then stored replies_complete continues');

  // 5xx on ask, stored already applied: carry on without apply.
  r = await run('earthquake', {
    ask: function (i, turn) { return turn === 1 ? BAD_GATEWAY() : NEVER(); },
    apply: function () { throw new Error('apply must not be called'); },
    get: function () { return OK(appliedView('earthquake')); }
  });
  assert.deepStrictEqual(r.log, ['ask1', 'wait', 'get', 'ask2'], '5xx then applied carries on');
  console.log('  ok - 5xx then stored applied carries on');

  // 5xx on ask, still waiting: ask again.
  r = await run('earthquake', {
    ask: function (i, turn) {
      if (turn !== 1) { return NEVER(); }
      return i === 0 ? BAD_GATEWAY() : OK({});
    },
    apply: function () { return OK(appliedView('earthquake')); },
    get: function () { return OK(storedView('earthquake', 'waiting')); }
  });
  assert.deepStrictEqual(r.log, ['ask1', 'wait', 'get', 'ask1', 'apply1', 'ask2'], 'still waiting asks again');
  console.log('  ok - still waiting asks again');

  // Three failed tries for the same turn pause with the existing notice.
  r = await run('earthquake', {
    ask: function () { return BAD_GATEWAY(); },
    apply: function () { throw new Error('apply must not be called'); },
    get: function () { return OK(storedView('earthquake', 'waiting')); }
  });
  assert.deepStrictEqual(
    r.log,
    ['ask1', 'wait', 'get', 'ask1', 'wait', 'get', 'ask1'],
    'three asks, two waits, then pause'
  );
  assert.strictEqual(r.notice.textContent, 'A turn could not be asked. Paused.', 'existing notice shown');
  assert.strictEqual(r.notice.hidden, false, 'notice visible');
  console.log('  ok - 3 failures pause with the notice');

  // Network failure on ask is treated the same as a 5xx.
  r = await run('earthquake', {
    ask: function (i, turn) {
      if (turn !== 1) { return NEVER(); }
      if (i === 0) { throw new Error('network down'); }
      return OK({});
    },
    apply: function () { return OK(appliedView('earthquake')); },
    get: function () { return OK(storedView('earthquake', 'waiting')); }
  });
  assert.deepStrictEqual(r.log, ['ask1', 'wait', 'get', 'ask1', 'apply1', 'ask2'], 'network failure recovers');
  console.log('  ok - network failure on ask recovers');

  // Three network failures pause with the existing unreachable notice.
  r = await run('earthquake', {
    ask: function () { throw new Error('network down'); },
    apply: function () { throw new Error('apply must not be called'); },
    get: function () { return OK(storedView('earthquake', 'waiting')); }
  });
  assert.strictEqual(r.counts.ask, 3, 'three asks');
  assert.strictEqual(r.notice.textContent, 'The lab could not be reached. Paused.', 'unreachable notice');
  console.log('  ok - 3 network failures pause');

  // 5xx on apply, stored applied: no second apply.
  r = await run('earthquake', {
    ask: function (i, turn) { return turn === 1 ? OK({}) : NEVER(); },
    apply: function () { return BAD_GATEWAY(); },
    get: function () { return OK(appliedView('earthquake')); }
  });
  assert.deepStrictEqual(r.log, ['ask1', 'apply1', 'wait', 'get', 'ask2'], '5xx on apply then applied carries on');
  console.log('  ok - 5xx on apply then stored applied carries on');

  // Network failure on apply, stored replies_complete: apply again.
  r = await run('earthquake', {
    ask: function (i, turn) { return turn === 1 ? OK({}) : NEVER(); },
    apply: function (i) {
      if (i === 0) { throw new Error('network down'); }
      return OK(appliedView('earthquake'));
    },
    get: function () { return OK(storedView('earthquake', 'replies_complete')); }
  });
  assert.deepStrictEqual(r.log, ['ask1', 'apply1', 'wait', 'get', 'apply1', 'ask2'], 'network failure on apply recovers');
  console.log('  ok - network failure on apply recovers');

  // A failing GET counts as a failed try; three in all pause.
  r = await run('earthquake', {
    ask: function () { return BAD_GATEWAY(); },
    apply: function () { throw new Error('apply must not be called'); },
    get: function () { return BAD_GATEWAY(); }
  });
  assert.deepStrictEqual(r.log, ['ask1', 'wait', 'get', 'wait', 'get'], 'failed GETs count as tries');
  assert.strictEqual(r.notice.textContent, 'A turn could not be asked. Paused.', 'paused with the notice');
  console.log('  ok - failed GET counts as a try');
})().catch(function (e) { console.error(e); process.exit(1); });
