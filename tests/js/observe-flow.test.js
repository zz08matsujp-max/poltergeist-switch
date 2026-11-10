/*
 * observe-flow.test.js — fixed examples for the Observe phase machine
 * (public-lab Requirements 9.1, 9.2, 9.3, 9.5, 11.1, 11.3, 14.9, 14.16).
 * Run with `node tests/js/observe-flow.test.js`. Real public/js/observe.js,
 * a scripted fake API client and a fake Voices module. No network.
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
const order = [];                       // gesture / API call order
const voices = createFakeVoices(null, { gestured: false });
const realGesture = voices.gesture;
voices.gesture = function () { order.push('gesture'); return realGesture(); };

let voicesOptions = null;
let current = createFakeApi();          // the scripted fake API in use
const METHODS = ['createEpisode', 'flipDisaster', 'getEpisode', 'ask', 'apply', 'reflect', 'memoryStatus', 'resetMemory', 'listEnded'];
const proxy = {};
METHODS.forEach(function (m) {
  proxy[m] = function () {
    if (m !== 'memoryStatus') { order.push(m); }
    return current[m].apply(current, arguments);
  };
});
global.window.createApiClient = function () { return proxy; };
global.window.createVoices = function (opts) { voicesOptions = opts; return voices; };

function world(disaster) {
  return {
    disaster: disaster, roomTemps: { living: 31 },
    man: { room: 'living', bodyTemp: 37.5, water: 60, injury: 0, fear: 20 }
  };
}
function view(id, disaster, turns, ended) {
  return { episodeId: id, disaster: disaster, currentExpectedTurn: 1, ended: ended === true, turns: turns };
}
const turn0 = { turnNumber: 0, state: 'applied', world: world('heat_wave') };
const turn1 = { turnNumber: 1, state: 'applied', world: world('heat_wave'), residentLine: 'It is hot.', robots: {} };

require(path.join(ROOT, 'public', 'js', 'observe.js'));
doc._ready.forEach(function (fn) { fn(); });
const ctl = global.window.observeController;

async function settle() { for (let i = 0; i < 12; i++) { await new Promise(function (r) { setImmediate(r); }); } }
function pressed(id) { return byId[id].getAttribute('aria-pressed'); }
function marks() { return [byId['sw-earthquake']._subs['.mark'].textContent, byId['sw-heat']._subs['.mark'].textContent]; }

let passed = 0;
async function check(name, fn) { await fn(); passed += 1; console.log('  ok - ' + name); }

(async function () {
  console.log('observe flow examples');

  await check('Voices label starts "Voices: on" and nothing is spoken before the First_Gesture', async function () {
    const html = fs.readFileSync(path.join(ROOT, 'public', 'index.php'), 'utf8');
    assert.ok(/id="btn-voices"[^>]*aria-pressed="true"[^>]*>Voices: on</.test(html) ||
      /<button[^>]*aria-pressed="true"[^>]*id="btn-voices"[^>]*>Voices: on</.test(html));
    assert.deepStrictEqual(voicesOptions, { enabled: true, autoUnlock: false });
    assert.strictEqual(voices.isOn(), true);
    assert.strictEqual(voices.speak('amber', 'Hello', function () {}), false, 'refused before the gesture');
    assert.strictEqual(voices.spoken().length, 0);
    assert.strictEqual(ctl._state().phase, 'idle');
  });

  // Run 1: Heat wave Lever from idle, through the last turn; reflection held open.
  let releaseReflect;
  const reflectGate = new Promise(function (r) { releaseReflect = r; });
  current = createFakeApi({
    createEpisode: { status: 200, body: view('e1', 'heat_wave', [turn0]) },
    flipDisaster: { status: 200, body: view('e1', 'heat_wave', [turn0]) },
    ask: { status: 200, body: {} },
    apply: { status: 200, body: view('e1', 'heat_wave', [turn0, turn1], true) },
    reflect: function () { return reflectGate; }
  });

  await check('the First_Gesture runs synchronously before the first request', async function () {
    byId['sw-heat'].click();
    assert.strictEqual(order[0], 'gesture');
    assert.strictEqual(order[1], 'createEpisode');
    assert.strictEqual(ctl._state().phase, 'starting', 'starting is set at click time');
    assert.strictEqual(voices.spoken().length, 0);
    await settle();
  });

  await check('both Levers stay ON until the reflection is stored, then both go OFF', async function () {
    assert.strictEqual(ctl._state().phase, 'ended');
    assert.strictEqual(current.count('reflect'), 1);
    assert.deepStrictEqual(marks(), ['OFF', 'ON'], 'heat Lever ON while the reflection is awaited');
    assert.ok(byId['endcard-host'].children.length > 0, 'End_Card is shown');
    releaseReflect({ status: 200, body: { robots: {} } });
    await settle();
    assert.deepStrictEqual(marks(), ['OFF', 'OFF']);
    assert.strictEqual(pressed('sw-heat'), 'false');
    assert.strictEqual(pressed('sw-earthquake'), 'false');
    assert.strictEqual(ctl._state().phase, 'ended', 'phase stays ended with the card shown');
  });

  await check('Start after the end creates a new episode with empty client state and no End_Card', async function () {
    assert.ok(byId.log.children.length > 0, 'the finished run left log rows');
    current = createFakeApi({
      createEpisode: { status: 200, body: view('e2', 'earthquake', [Object.assign({}, turn0, { world: world('earthquake') })]) }
    });
    byId['btn-start'].click();
    const st = ctl._state();
    assert.strictEqual(st.phase, 'starting');
    assert.strictEqual(st.episodeId, null, 'old episode id dropped at once');
    assert.strictEqual(byId['endcard-host'].children.length, 0, 'End_Card removed');
    assert.strictEqual(byId.log.children.length, 0, 'log emptied');
    assert.strictEqual(byId['meter-temp']._subs['.val'].textContent, '\u2014', 'meters emptied');
    await settle();
    assert.strictEqual(current.count('createEpisode'), 1);
    assert.strictEqual(st.episodeId, 'e2');
    assert.strictEqual(st.phase, 'staged');
    assert.strictEqual(current.count('ask'), 0, 'Start alone runs no turn');
    assert.deepStrictEqual(marks(), ['OFF', 'OFF']);
  });

  await check('a Lever flip after the end also starts clean', async function () {
    // Finish e2 quickly, then flip the Earthquake Lever from the ended phase.
    const t1 = Object.assign({}, turn1, { world: world('earthquake') });
    current = createFakeApi({
      flipDisaster: { status: 200, body: view('e2', 'earthquake', [turn0]) },
      ask: { status: 200, body: {} },
      apply: { status: 200, body: view('e2', 'earthquake', [turn0, t1], true) },
      reflect: { status: 409, body: { error: 'not_reflectable' } }   // reflection "not stored"
    });
    byId['sw-earthquake'].click();
    await settle();
    const st = ctl._state();
    assert.strictEqual(st.phase, 'ended');
    assert.deepStrictEqual(marks(), ['OFF', 'OFF'], 'Levers OFF even when nothing was stored');

    current = createFakeApi({
      createEpisode: { status: 200, body: view('e3', 'heat_wave', [turn0]) },
      flipDisaster: { status: 200, body: view('e3', 'heat_wave', [turn0]) },
      ask: new Promise(function () { /* never answers: the run stays in progress */ })
    });
    byId['sw-heat'].click();
    assert.strictEqual(st.episodeId, null);
    assert.strictEqual(byId['endcard-host'].children.length, 0);
    assert.strictEqual(byId.log.children.length, 0);
    await settle();
    assert.strictEqual(current.count('createEpisode'), 1);
    assert.strictEqual(st.episodeId, 'e3');
    assert.strictEqual(st.phase, 'running');
    assert.deepStrictEqual(marks(), ['OFF', 'ON']);
  });

  await check('a second Lever flip or Start press while a run is in progress is ignored', async function () {
    const before = current.calls.length;
    byId['sw-heat'].click();
    byId['sw-earthquake'].click();
    byId['btn-start'].click();
    await settle();
    assert.strictEqual(current.calls.length, before, 'no API call was made');
    assert.strictEqual(ctl._state().phase, 'running');
    assert.strictEqual(ctl._state().episodeId, 'e3');
    assert.deepStrictEqual(marks(), ['OFF', 'ON']);
  });

  await check('a second press while starting creates only one episode', async function () {
    // Back to a clean page: a fresh Start from idle after forcing the phase.
    ctl._state().phase = 'idle';
    current = createFakeApi({
      createEpisode: { status: 200, body: view('e4', 'earthquake', [turn0]) }
    });
    byId['btn-start'].click();
    byId['btn-start'].click();
    byId['sw-heat'].click();
    byId['sw-earthquake'].click();
    await settle();
    assert.strictEqual(current.count('createEpisode'), 1);
    assert.strictEqual(current.count('flipDisaster'), 0);
    assert.strictEqual(ctl._state().phase, 'staged');
  });

  console.log(passed + ' examples passed.');
})().catch(function (e) { console.error(e); process.exit(1); });
