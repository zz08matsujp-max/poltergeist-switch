/*
 * observe-levers.test.js — the Levers choose the disaster and Start starts the
 * run (public-lab Requirements 15.1-15.8, 14.9, 14.16, 14.21).
 * Run with `node tests/js/observe-levers.test.js`. Real public/js/observe.js,
 * a scripted fake API client and a fake Voices module. No network. Recovery
 * waits are injected as instant, so no clock is needed.
 */
'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const { createFakeApi } = require('./helpers/fake-api.js');
const { createFakeVoices } = require('./helpers/fake-voices.js');

const ROOT = path.join(__dirname, '..', '..');

// ── DOM shim ────────────────────────────────────────────────────────────────
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
    if (id.indexOf('log-turn-') === 0) { return null; }   // let appendLog create its groups
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
const METHODS = ['createEpisode', 'flipDisaster', 'getEpisode', 'ask', 'apply', 'reflect', 'memoryStatus', 'resetMemory', 'listEnded'];
const proxy = {};
METHODS.forEach(function (m) { proxy[m] = function () { return current[m].apply(current, arguments); }; });
global.window.createApiClient = function () { return proxy; };
global.window.createVoices = function () { return voices; };
global.window.createHouseRenderer = function () {
  return { mount: function () {}, start: function () {}, setThinking: function () {}, setWorld: function () {}, reset: function () {} };
};
global.window.observeWait = function () { return Promise.resolve(); };
global.window.localStorage = { getItem: function () { return null; }, setItem: function () {} };
let confirmReset = null;
global.window.memoryUi = {
  renderStatusLine: function () {},
  buildConfirmPanel: function (onConfirm) { confirmReset = onConfirm; return makeElement(); }
};

function world(disaster) {
  return { disaster: disaster, roomTemps: { living: 31 }, man: { room: 'living', bodyTemp: 37.5, water: 60, injury: 0, fear: 20 } };
}
function view(id, disaster, turns, ended) {
  return { episodeId: id, disaster: disaster, currentExpectedTurn: 1, ended: ended === true, turns: turns };
}
const turn0 = { turnNumber: 0, state: 'applied', world: world('earthquake') };
const turn1 = { turnNumber: 1, state: 'applied', world: world('earthquake'), residentLine: 'Oh.', robots: {} };
const HINT = 'Turn on Earthquake or Heat wave, then press Start.';

require(path.join(ROOT, 'public', 'js', 'observe.js'));
doc._ready.forEach(function (fn) { fn(); });
const ctl = global.window.observeController;

async function settle() { for (let i = 0; i < 12; i++) { await new Promise(function (r) { setImmediate(r); }); } }
function marks() { return [byId['sw-earthquake']._subs['.mark'].textContent, byId['sw-heat']._subs['.mark'].textContent]; }
function pressedBoth() { return [byId['sw-earthquake'].getAttribute('aria-pressed'), byId['sw-heat'].getAttribute('aria-pressed')]; }
const EQ = 'sw-earthquake';
const HEAT = 'sw-heat';
const NEVER = function () { return new Promise(function () { /* never answers */ }); };
function runApi(id, disaster, extra) {
  return createFakeApi(Object.assign({
    createEpisode: { status: 200, body: view(id, disaster, [turn0]) },
    flipDisaster: { status: 200, body: view(id, disaster, [turn0]) },
    ask: NEVER()
  }, extra || {}));
}

let passed = 0;
async function check(name, fn) { await fn(); passed += 1; console.log('  ok - ' + name); }

(async function () {
  console.log('observe Levers and Start');

  await check('a Lever click sends no request and toggles ON and OFF', async function () {
    current = createFakeApi();
    byId[EQ].click();
    assert.deepStrictEqual(marks(), ['ON', 'OFF']);
    assert.deepStrictEqual(pressedBoth(), ['true', 'false']);
    assert.ok(byId[EQ].classList.contains('on'));
    byId[EQ].click();
    assert.deepStrictEqual(marks(), ['OFF', 'OFF']);
    assert.deepStrictEqual(pressedBoth(), ['false', 'false']);
    assert.ok(!byId[EQ].classList.contains('on'));
    byId[HEAT].click();
    assert.deepStrictEqual(marks(), ['OFF', 'ON']);
    byId[HEAT].click();
    assert.deepStrictEqual(marks(), ['OFF', 'OFF']);
    await settle();
    assert.strictEqual(current.calls.length, 0, 'no API call');
    assert.strictEqual(ctl._state().phase, 'idle');
  });

  await check('at most one Lever is ON', async function () {
    byId[EQ].click();
    byId[HEAT].click();
    assert.deepStrictEqual(marks(), ['OFF', 'ON']);
    assert.deepStrictEqual(pressedBoth(), ['false', 'true']);
    byId[EQ].click();
    assert.deepStrictEqual(marks(), ['ON', 'OFF']);
    byId[EQ].click();
    assert.deepStrictEqual(marks(), ['OFF', 'OFF']);
    assert.strictEqual(current.calls.length, 0, 'no API call');
  });

  await check('Start with no Lever ON shows the hint and sends nothing', async function () {
    byId['btn-start'].click();
    await settle();
    assert.strictEqual(byId.notice.textContent, HINT);
    assert.strictEqual(byId.notice.hidden, false);
    assert.strictEqual(current.calls.length, 0, 'fake-api recorded no call');
    assert.strictEqual(ctl._state().phase, 'idle');
  });

  await check('Start with Earthquake ON creates an earthquake episode and runs it', async function () {
    current = runApi('q1', 'earthquake');
    byId[EQ].click();
    byId['btn-start'].click();
    assert.strictEqual(ctl._state().phase, 'starting', 'starting is set at once');
    await settle();
    assert.strictEqual(current.count('createEpisode'), 1);
    assert.notStrictEqual(current.callsOf('createEpisode')[0].args[0], 'heat_wave');
    assert.strictEqual(current.count('flipDisaster'), 1);
    assert.deepStrictEqual(current.callsOf('flipDisaster')[0].args, ['q1']);
    assert.strictEqual(current.count('ask'), 1, 'the first turn was asked');
    assert.strictEqual(ctl._state().phase, 'running');
    assert.deepStrictEqual(marks(), ['ON', 'OFF']);
  });

  await check('Levers and Start are ignored while running, and the chosen Lever stays ON', async function () {
    const before = current.calls.length;
    byId[HEAT].click();
    byId[EQ].click();
    byId['btn-start'].click();
    await settle();
    assert.strictEqual(current.calls.length, before);
    assert.strictEqual(ctl._state().phase, 'running');
    assert.deepStrictEqual(marks(), ['ON', 'OFF']);
  });

  await check('Start with Heat wave ON creates a heat-wave episode and runs it', async function () {
    ctl.startNewRun();
    current = runApi('h1', 'heat_wave');
    byId[HEAT].click();
    byId['btn-start'].click();
    await settle();
    assert.deepStrictEqual(current.callsOf('createEpisode')[0].args, ['heat_wave']);
    assert.deepStrictEqual(current.callsOf('flipDisaster')[0].args, ['h1', 'heat_wave']);
    assert.strictEqual(current.count('ask'), 1);
    assert.strictEqual(ctl._state().phase, 'running');
    assert.deepStrictEqual(marks(), ['OFF', 'ON']);
  });

  await check('Levers and Start are ignored while starting, and the chosen Lever stays ON', async function () {
    ctl.startNewRun();
    current = createFakeApi({ createEpisode: NEVER() });
    byId[HEAT].click();
    byId['btn-start'].click();
    assert.strictEqual(ctl._state().phase, 'starting');
    byId['btn-start'].click();
    byId[EQ].click();
    byId[HEAT].click();
    await settle();
    assert.strictEqual(current.calls.length, 1, 'one createEpisode and nothing else');
    assert.strictEqual(ctl._state().phase, 'starting');
    assert.deepStrictEqual(marks(), ['OFF', 'ON']);
  });

  await check('both Levers are OFF after the end and its reflection', async function () {
    ctl.startNewRun();
    let release;
    const gate = new Promise(function (r) { release = r; });
    current = runApi('x1', 'earthquake', {
      ask: { status: 200, body: {} },
      apply: { status: 200, body: view('x1', 'earthquake', [turn0, turn1], true) },
      reflect: function () { return gate; }
    });
    byId[EQ].click();
    byId['btn-start'].click();
    await settle();
    assert.strictEqual(ctl._state().phase, 'ended');
    assert.deepStrictEqual(marks(), ['ON', 'OFF'], 'still ON while the reflection is awaited');
    release({ status: 200, body: { robots: {} } });
    await settle();
    assert.deepStrictEqual(marks(), ['OFF', 'OFF']);
    assert.deepStrictEqual(pressedBoth(), ['false', 'false']);
    assert.strictEqual(ctl._state().phase, 'ended');
  });

  await check('after the end, a Lever then Start begins a new run', async function () {
    current = runApi('x2', 'heat_wave');
    byId[HEAT].click();
    assert.deepStrictEqual(marks(), ['OFF', 'ON']);
    assert.strictEqual(current.calls.length, 0, 'the Lever alone sends nothing');
    byId['btn-start'].click();
    await settle();
    assert.strictEqual(current.count('createEpisode'), 1);
    assert.deepStrictEqual(current.callsOf('createEpisode')[0].args, ['heat_wave']);
    assert.strictEqual(ctl._state().episodeId, 'x2');
    assert.strictEqual(ctl._state().phase, 'running');
  });

  await check('Start with no Lever ON after the end shows the hint and keeps the End_Card', async function () {
    ctl.startNewRun();
    current = runApi('x3', 'earthquake', {
      ask: { status: 200, body: {} },
      apply: { status: 200, body: view('x3', 'earthquake', [turn0, turn1], true) },
      reflect: { status: 409, body: { error: 'not_reflectable' } }
    });
    byId[EQ].click();
    byId['btn-start'].click();
    await settle();
    assert.strictEqual(ctl._state().phase, 'ended');
    assert.deepStrictEqual(marks(), ['OFF', 'OFF']);
    current = createFakeApi();
    byId.notice.textContent = '';
    byId['btn-start'].click();
    await settle();
    assert.strictEqual(byId.notice.textContent, HINT);
    assert.strictEqual(current.calls.length, 0);
    assert.ok(byId['endcard-host'].children.length > 0, 'End_Card still shown');
    assert.strictEqual(ctl._state().phase, 'ended');
  });

  await check('both Levers are OFF after All reset', async function () {
    ctl.startNewRun();
    byId[HEAT].click();
    assert.deepStrictEqual(marks(), ['OFF', 'ON']);
    current = createFakeApi({
      resetMemory: { status: 200, body: { generation: 2 } },
      memoryStatus: { status: 200, body: {} }
    });
    byId['btn-reset-memory'].click();
    assert.strictEqual(typeof confirmReset, 'function');
    confirmReset();
    await settle();
    assert.strictEqual(current.count('resetMemory'), 1);
    assert.deepStrictEqual(marks(), ['OFF', 'OFF']);
    assert.deepStrictEqual(pressedBoth(), ['false', 'false']);
    assert.strictEqual(ctl._state().phase, 'idle');
  });

  async function toUnfinished(id) {
    ctl.startNewRun();
    current = createFakeApi({
      createEpisode: { status: 200, body: view(id, 'earthquake', [turn0]) },
      flipDisaster: { status: 200, body: view(id, 'earthquake', [turn0]) },
      getEpisode: { status: 200, body: view(id, 'earthquake', [turn0]) },
      ask: { status: 502, body: null }
    });
    byId[EQ].click();
    byId['btn-start'].click();
    await settle();
    assert.strictEqual(ctl._state().phase, 'unfinished');
  }

  await check('Start on an unfinished episode acts as Try again and Levers are ignored', async function () {
    await toUnfinished('u1');
    current = createFakeApi({
      getEpisode: { status: 200, body: view('u1', 'earthquake', [turn0, turn1], true) },
      reflect: { status: 409, body: { error: 'not_reflectable' } }
    });
    byId[HEAT].click();
    byId[EQ].click();
    await settle();
    assert.strictEqual(current.calls.length, 0, 'Levers sent nothing');
    assert.strictEqual(ctl._state().phase, 'unfinished');
    assert.deepStrictEqual(marks(), ['ON', 'OFF'], 'the chosen Lever stays ON');
    byId['btn-start'].click();
    await settle();
    assert.strictEqual(current.count('createEpisode'), 0, 'no new episode');
    assert.strictEqual(current.count('flipDisaster'), 0);
    assert.deepStrictEqual(current.callsOf('getEpisode')[0].args, ['u1']);
    assert.strictEqual(ctl._state().episodeId, 'u1');
    assert.strictEqual(ctl._state().phase, 'ended');
  });

  console.log(passed + ' examples passed.');
})().catch(function (e) { console.error(e); process.exit(1); });
