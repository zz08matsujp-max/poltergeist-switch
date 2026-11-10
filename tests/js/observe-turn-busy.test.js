/*
 * observe-turn-busy.test.js — task 21.6 (public-lab Req 18.13, 18.14).
 * Headless (Node) test: a 409 turn_busy answer to ask or apply is "still
 * thinking", not a failure. Observe waits 2000 ms, reads the episode once,
 * continues from the stored turn state, shows no notice and counts no failed
 * try. After 15 consecutive Turn_Busy answers for one turn the 16th counts one
 * failed try. Run with `node tests/js/observe-turn-busy.test.js`. No network
 * call; the waits are injected through window.observeWait.
 */
'use strict';

const assert = require('assert');
const path = require('path');
const { createFakeApi } = require('./helpers/fake-api');

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

async function settleMany() {
  for (let i = 0; i < 60; i++) { await new Promise(function (r) { setImmediate(r); }); }
}

function appliedView() {
  return { episodeId: 'e1', disaster: 'earthquake', currentExpectedTurn: 2, ended: false,
    turns: [{ turnNumber: 1, state: 'applied', robots: {} }] };
}
function storedView(turnState) {
  const turns = turnState ? [{ turnNumber: 1, state: turnState, robots: {} }] : [];
  return { episodeId: 'e1', disaster: 'earthquake', currentExpectedTurn: 1, ended: false, turns: turns };
}

const OK = function (body) { return { status: 200, body: body }; };
const BUSY = function () { return { status: 409, body: { error: 'turn_busy', currentExpectedTurn: 1, turnState: 'waiting' } }; };
const BAD_GATEWAY = function () { return { status: 502, body: null }; };
const NEVER = function () { return new Promise(function () { /* pending */ }); };

// Load a fresh observe.js on a fake-api built from `script`, start a run, settle.
async function run(script) {
  const byId = {};
  const doc = {
    readyState: 'loading', _ready: [],
    addEventListener: function (ev, fn) { if (ev === 'DOMContentLoaded') { doc._ready.push(fn); } },
    createElement: function () { return makeElement(); },
    createTextNode: function (t) { const n = makeElement(); n.textContent = t; return n; },
    getElementById: function (id) { if (!byId[id]) { byId[id] = makeElement(); } return byId[id]; }
  };
  global.document = doc;
  global.window = {};

  const log = [];
  const waits = [];
  const base = Object.assign({
    createEpisode: OK(storedView(null)),
    flipDisaster: OK(storedView(null))
  }, script);
  const api = createFakeApi(base);
  ['ask', 'apply', 'getEpisode'].forEach(function (m) {
    const orig = api[m];
    api[m] = function (id, turn) {
      log.push(m === 'getEpisode' ? 'get' : m + turn);
      return orig.apply(api, arguments);
    };
  });

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
  global.window.createApiClient = function () { return api; };
  global.window.observeWait = function (ms) { waits.push(ms); log.push('wait' + ms); return Promise.resolve(); };

  delete require.cache[require.resolve(OBSERVE)];
  require(OBSERVE);
  doc._ready.forEach(function (fn) { fn(); });
  byId['sw-earthquake'].click();
  byId['btn-start'].click();
  await settleMany();
  return { log: log, waits: waits, notice: byId.notice, api: api };
}

(async function () {
  console.log('turn_busy on Observe');

  // ask busy, stored replies_complete: wait 2000, one GET, apply without asking again.
  let askN = 0;
  let r = await run({
    ask: function (i, args) { return args[1] === 1 ? (askN++ === 0 ? BUSY() : OK({})) : NEVER(); },
    apply: function () { return OK(appliedView()); },
    getEpisode: OK(storedView('replies_complete'))
  });
  assert.deepStrictEqual(r.log, ['ask1', 'wait2000', 'get', 'apply1', 'ask2'], 'ask busy then replies_complete');
  assert.deepStrictEqual(r.waits, [2000], 'waits exactly 2000 ms');
  assert.strictEqual(r.api.count('getEpisode'), 1, 'one getEpisode');
  assert.strictEqual(r.notice.textContent, '', 'no notice');
  assert.strictEqual(r.notice.children.length, 0, 'no notice children');
  console.log('  ok - ask busy, stored replies_complete');

  // ask busy, stored waiting: ask again.
  askN = 0;
  r = await run({
    ask: function (i, args) { return args[1] === 1 ? (askN++ === 0 ? BUSY() : OK({})) : NEVER(); },
    apply: function () { return OK(appliedView()); },
    getEpisode: OK(storedView('waiting'))
  });
  assert.deepStrictEqual(r.log, ['ask1', 'wait2000', 'get', 'ask1', 'apply1', 'ask2'], 'ask busy then waiting');
  assert.deepStrictEqual(r.waits, [2000], 'waits exactly 2000 ms');
  assert.strictEqual(r.notice.textContent, '', 'no notice');
  console.log('  ok - ask busy, stored waiting');

  // ask busy, stored applied: carry on without apply.
  r = await run({
    ask: function (i, args) { return args[1] === 1 ? BUSY() : NEVER(); },
    apply: function () { throw new Error('apply must not be called'); },
    getEpisode: OK(appliedView())
  });
  assert.deepStrictEqual(r.log, ['ask1', 'wait2000', 'get', 'ask2'], 'ask busy then applied');
  assert.deepStrictEqual(r.waits, [2000], 'waits exactly 2000 ms');
  console.log('  ok - ask busy, stored applied');

  // apply busy, stored replies_complete: apply again.
  let applyN = 0;
  r = await run({
    ask: function (i, args) { return args[1] === 1 ? OK({}) : NEVER(); },
    apply: function () { return applyN++ === 0 ? BUSY() : OK(appliedView()); },
    getEpisode: OK(storedView('replies_complete'))
  });
  assert.deepStrictEqual(r.log, ['ask1', 'apply1', 'wait2000', 'get', 'apply1', 'ask2'], 'apply busy then replies_complete');
  assert.deepStrictEqual(r.waits, [2000], 'waits exactly 2000 ms');
  assert.strictEqual(r.api.count('getEpisode'), 1, 'one getEpisode');
  assert.strictEqual(r.notice.textContent, '', 'no notice');
  console.log('  ok - apply busy, stored replies_complete');

  // apply busy, stored applied: no second apply.
  r = await run({
    ask: function (i, args) { return args[1] === 1 ? OK({}) : NEVER(); },
    apply: BUSY(),
    getEpisode: OK(appliedView())
  });
  assert.deepStrictEqual(r.log, ['ask1', 'apply1', 'wait2000', 'get', 'ask2'], 'apply busy then applied');
  console.log('  ok - apply busy, stored applied');

  // Busy answers never count as failed tries: many in a row (up to 15) do not pause.
  askN = 0;
  r = await run({
    ask: function (i, args) { return args[1] === 1 ? (askN++ < 15 ? BUSY() : OK({})) : NEVER(); },
    apply: function () { return OK(appliedView()); },
    getEpisode: OK(storedView('waiting'))
  });
  assert.strictEqual(r.waits.length, 15, '15 busy answers give 15 waits');
  assert.ok(r.waits.every(function (ms) { return ms === 2000; }), 'every wait is 2000 ms');
  assert.strictEqual(r.api.count('ask'), 17, '15 busy + 1 ok + prefetch of turn 2');
  assert.strictEqual(r.notice.textContent, '', 'no notice after 15 busy answers');
  assert.strictEqual(r.notice.children.length, 0, 'no notice children');
  console.log('  ok - 15 consecutive busy answers keep waiting');

  // The 16th consecutive busy answer counts one failed try (a 5000 ms recovery
  // wait); two further 5xx answers then reach the third failed try and pause.
  r = await run({
    ask: function (i, args) {
      if (args[1] !== 1) { return NEVER(); }
      return i < 16 ? BUSY() : BAD_GATEWAY();
    },
    apply: function () { throw new Error('apply must not be called'); },
    getEpisode: OK(storedView('waiting'))
  });
  assert.deepStrictEqual(r.waits.slice(0, 15), new Array(15).fill(2000), 'first 15 busy answers wait 2000 ms');
  assert.strictEqual(r.waits[15], 5000, 'the 16th busy answer goes through the failed-try recovery');
  assert.strictEqual(r.api.count('ask'), 18, '16 busy + 2 bad gateway = 18 asks, so the 16th counted exactly one try');
  assert.strictEqual(r.notice.hidden, false, 'paused after the third failed try');
  assert.strictEqual(r.notice.children[0].textContent, 'A turn could not be completed.', 'existing Unfinished notice');
  console.log('  ok - 16th busy answer counts one failed try');
})().catch(function (e) { console.error(e); process.exit(1); });
