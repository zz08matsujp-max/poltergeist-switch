/*
 * observe-flow.test.js — fixed examples for the Observe phase machine
 * (public-lab Requirements 8.1-8.4, 9.1-9.9, 10.3, 10.4, 11.1, 11.3, 13.11-13.13,
 * 14.9, 14.10, 14.15, 14.16, 14.20).
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

// A counting fake House_Renderer, so "stage unchanged / back to first load" can be seen.
const houseCalls = { setWorld: 0, reset: 0 };
global.window.createHouseRenderer = function () {
  return {
    mount: function () {}, start: function () {}, setThinking: function () {},
    setWorld: function () { houseCalls.setWorld += 1; },
    reset: function () { houseCalls.reset += 1; }
  };
};
// Instant recovery waits, an in-memory localStorage and a fake memory-ui.
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
    byId['sw-heat'].click();                 // the Lever only chooses: a gesture, no request
    assert.deepStrictEqual(order, ['gesture']);
    assert.strictEqual(ctl._state().phase, 'idle');
    byId['btn-start'].click();
    assert.strictEqual(order[0], 'gesture');
    assert.strictEqual(order[order.length - 2], 'gesture', 'Start runs the gesture first');
    assert.strictEqual(order[order.length - 1], 'createEpisode');
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
      createEpisode: { status: 200, body: view('e2', 'earthquake', [Object.assign({}, turn0, { world: world('earthquake') })]) },
      flipDisaster: { status: 200, body: view('e2', 'earthquake', [turn0]) },
      ask: new Promise(function () { /* never answers: the run stays in progress */ })
    });
    byId['sw-earthquake'].click();          // choose the disaster (the old run stays on screen)
    assert.ok(byId.log.children.length > 0, 'a Lever click clears nothing');
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
    assert.strictEqual(st.phase, 'running');
    assert.deepStrictEqual(marks(), ['ON', 'OFF']);
  });

  await check('a Lever and Start after the end also start clean', async function () {
    // Finish e2 quickly (Lever, then Start), then choose Heat wave from the ended phase.
    ctl.startNewRun();
    const t1 = Object.assign({}, turn1, { world: world('earthquake') });
    current = createFakeApi({
      createEpisode: { status: 200, body: view('e2', 'earthquake', [turn0]) },
      flipDisaster: { status: 200, body: view('e2', 'earthquake', [turn0]) },
      ask: { status: 200, body: {} },
      apply: { status: 200, body: view('e2', 'earthquake', [turn0, t1], true) },
      reflect: { status: 409, body: { error: 'not_reflectable' } }   // reflection "not stored"
    });
    byId['sw-earthquake'].click();
    byId['btn-start'].click();
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
    byId['btn-start'].click();
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
    ctl.startNewRun();
    current = createFakeApi({
      createEpisode: new Promise(function () { /* never answers: the page stays starting */ })
    });
    byId['sw-earthquake'].click();
    byId['btn-start'].click();
    byId['btn-start'].click();
    byId['sw-heat'].click();
    byId['sw-earthquake'].click();
    await settle();
    assert.strictEqual(current.count('createEpisode'), 1);
    assert.strictEqual(current.count('flipDisaster'), 0);
    assert.strictEqual(ctl._state().phase, 'starting');
    assert.deepStrictEqual(marks(), ['ON', 'OFF'], 'the chosen Lever stays ON');
  });

  // ── task 13.6: unfinished runs, All reset, daily limits, Notice_Resume ────
  function buttons(node, out) {
    out = out || [];
    if (typeof node.className === 'string' && /\bbtn\b/.test(node.className)) { out.push(node); }
    node.children.forEach(function (c) { buttons(c, out); });
    return out;
  }
  function noticeButton(label) {
    const found = buttons(byId.notice).filter(function (b) { return b.textContent === label; });
    assert.strictEqual(found.length, 1, 'one "' + label + '" button in the notice');
    return found[0];
  }
  function okView(id, turns, ended) { return { status: 200, body: view(id, 'earthquake', turns, ended) }; }

  // Leaves the page `unfinished`: three failed asks on turn 1 of episode `id`.
  async function toUnfinished(id) {
    ctl.startNewRun();
    current = createFakeApi({
      createEpisode: okView(id, [turn0]), flipDisaster: okView(id, [turn0]),
      getEpisode: okView(id, [turn0]), ask: { status: 502, body: null }
    });
    byId['sw-earthquake'].click();
    byId['btn-start'].click();
    await settle();
    assert.strictEqual(ctl._state().phase, 'unfinished');
    assert.strictEqual(current.count('ask'), 3, 'three tries');
  }
  function continuingApi(id) {
    return createFakeApi({
      getEpisode: okView(id, [turn0, Object.assign({}, turn1, { world: world('earthquake') })], true),
      reflect: { status: 409, body: { error: 'not_reflectable' } }
    });
  }

  await check('after the tries are used up the notice offers Try again and never says Paused', async function () {
    await toUnfinished('u1');
    assert.ok(byId.notice.textContent.indexOf('A turn could not be completed.') >= 0);
    assert.ok(!/paused/i.test(byId.notice.textContent), 'no "Paused"');
    assert.deepStrictEqual(buttons(byId.notice).map(function (b) { return b.textContent; }), ['Try again']);
    assert.strictEqual(byId.notice.hidden, false);
  });

  await check('Try again continues the same episode without a create', async function () {
    const gestures = order.filter(function (o) { return o === 'gesture'; }).length;
    current = continuingApi('u1');
    noticeButton('Try again').click();
    assert.strictEqual(order.filter(function (o) { return o === 'gesture'; }).length, gestures + 1, 'First_Gesture');
    await settle();
    assert.strictEqual(current.count('createEpisode'), 0);
    assert.strictEqual(current.count('flipDisaster'), 0);
    assert.deepStrictEqual(current.callsOf('getEpisode')[0].args, ['u1']);
    assert.strictEqual(ctl._state().episodeId, 'u1');
    assert.strictEqual(ctl._state().phase, 'ended');
    assert.strictEqual(byId.notice.hidden, true);
  });

  await check('a failed Try again keeps the page unfinished with the same notice', async function () {
    await toUnfinished('u2');
    current = createFakeApi({ getEpisode: { status: 502, body: null } });
    noticeButton('Try again').click();
    await settle();
    assert.strictEqual(ctl._state().phase, 'unfinished');
    assert.ok(byId.notice.textContent.indexOf('A turn could not be completed.') >= 0);
    assert.strictEqual(current.count('createEpisode'), 0);
  });

  await check('a Lever is ignored and Start acts as Try again while unfinished, with no create', async function () {
    await toUnfinished('u3');
    current = continuingApi('u3');
    byId['sw-heat'].click();
    await settle();
    assert.strictEqual(current.calls.length, 0, 'the Lever sent nothing');
    assert.strictEqual(ctl._state().phase, 'unfinished');
    assert.deepStrictEqual(marks(), ['ON', 'OFF'], 'the chosen Lever stays ON');
    byId['btn-start'].click();
    await settle();
    assert.strictEqual(current.count('createEpisode'), 0);
    assert.strictEqual(current.count('flipDisaster'), 0);
    assert.deepStrictEqual(current.callsOf('getEpisode')[0].args, ['u3']);
    assert.strictEqual(ctl._state().episodeId, 'u3');
    assert.strictEqual(ctl._state().phase, 'ended');

    await toUnfinished('u4');
    current = continuingApi('u4');
    byId['btn-start'].click();
    await settle();
    assert.strictEqual(current.count('createEpisode'), 0);
    assert.deepStrictEqual(current.callsOf('getEpisode')[0].args, ['u4']);
    assert.strictEqual(ctl._state().episodeId, 'u4');
    assert.strictEqual(ctl._state().phase, 'ended');
  });

  // A finished heat wave run whose reflection is still awaited (Levers still ON).
  async function finishedRunWithOpenReflection(id) {
    ctl.startNewRun();
    const t1 = Object.assign({}, turn1, { world: world('heat_wave') });
    current = createFakeApi({
      createEpisode: { status: 200, body: view(id, 'heat_wave', [turn0]) },
      flipDisaster: { status: 200, body: view(id, 'heat_wave', [turn0]) },
      ask: { status: 200, body: {} },
      apply: { status: 200, body: view(id, 'heat_wave', [turn0, t1], true) },
      reflect: new Promise(function () {})
    });
    byId['sw-heat'].click();
    byId['btn-start'].click();
    await settle();
    assert.strictEqual(ctl._state().phase, 'ended');
    assert.deepStrictEqual(marks(), ['OFF', 'ON']);
  }
  function pressResetAndConfirm() {
    byId['btn-reset-memory'].click();
    assert.strictEqual(typeof confirmReset, 'function', 'the confirm panel was built');
    confirmReset();
  }

  await check('All reset sets both Levers OFF and returns stage, meters, log and End_Card to first load', async function () {
    await finishedRunWithOpenReflection('r1');
    assert.ok(byId.log.children.length > 0);
    assert.ok(byId['endcard-host'].children.length > 0);
    assert.notStrictEqual(byId['meter-temp']._subs['.val'].textContent, '\u2014');
    const resets = houseCalls.reset;
    current = createFakeApi({
      resetMemory: { status: 200, body: { generation: 2 } },
      memoryStatus: { status: 200, body: {} }
    });
    pressResetAndConfirm();
    await settle();
    assert.strictEqual(current.count('resetMemory'), 1);
    assert.deepStrictEqual(marks(), ['OFF', 'OFF']);
    assert.strictEqual(pressed('sw-heat'), 'false');
    assert.strictEqual(houseCalls.reset, resets + 1, 'stage reset');
    assert.strictEqual(byId.log.children.length, 0);
    assert.strictEqual(byId['endcard-host'].children.length, 0);
    assert.strictEqual(byId['meter-temp']._subs['.val'].textContent, '\u2014');
    assert.strictEqual(byId.notice.hidden, true);
    const st = ctl._state();
    assert.strictEqual(st.phase, 'idle');
    assert.strictEqual(st.episodeId, null);
  });

  await check('after All reset the next run starts normally', async function () {
    current = createFakeApi({
      createEpisode: okView('r2', [turn0]), flipDisaster: okView('r2', [turn0]),
      ask: new Promise(function () {})
    });
    byId['sw-earthquake'].click();
    byId['btn-start'].click();
    await settle();
    assert.strictEqual(current.count('createEpisode'), 1);
    assert.strictEqual(ctl._state().episodeId, 'r2');
    assert.strictEqual(ctl._state().phase, 'running');
    assert.strictEqual(byId['btn-reset-memory'].disabled, true, 'running');
  });

  await check('the All_Reset_Button is disabled while a run starts or turns run, and enabled otherwise', async function () {
    ctl.startNewRun();
    assert.strictEqual(byId['btn-reset-memory'].disabled, false, 'idle');
    current = createFakeApi({
      createEpisode: okView('d1', [turn0]), flipDisaster: okView('d1', [turn0]),
      ask: new Promise(function () {})
    });
    byId['sw-earthquake'].click();
    assert.strictEqual(byId['btn-reset-memory'].disabled, false, 'a Lever alone does not disable it');
    byId['btn-start'].click();
    assert.strictEqual(byId['btn-reset-memory'].disabled, true, 'starting');
    await settle();
    assert.strictEqual(ctl._state().phase, 'running');
    assert.strictEqual(byId['btn-reset-memory'].disabled, true, 'running');
    // Even if the click got through, confirming does not call the server.
    pressResetAndConfirm();
    await settle();
    assert.strictEqual(current.count('resetMemory'), 0);
    assert.strictEqual(ctl._state().phase, 'running');
  });

  await check('All reset answered 409 shows the message and changes nothing else', async function () {
    await finishedRunWithOpenReflection('r3');
    byId['sw-heat'].setAttribute('aria-pressed', 'true');
    const logRows = byId.log.children.length;
    const resets = houseCalls.reset;
    current = createFakeApi({
      resetMemory: { status: 409, body: { error: 'run_in_progress' } },
      memoryStatus: { status: 200, body: {} }
    });
    pressResetAndConfirm();
    await settle();
    assert.strictEqual(
      byId.notice.textContent,
      'All reset is available when no run is in progress. A stopped run is released within 5 minutes.');
    assert.strictEqual(byId.notice.hidden, false);
    assert.deepStrictEqual(marks(), ['OFF', 'ON'], 'Levers unchanged');
    assert.strictEqual(byId.log.children.length, logRows);
    assert.ok(byId['endcard-host'].children.length > 0);
    assert.strictEqual(houseCalls.reset, resets, 'stage unchanged');
    assert.strictEqual(ctl._state().phase, 'ended');
  });

  await check('a 429 on create keeps both Levers OFF and the stage unchanged, with the right message', async function () {
    const cases = [
      ['daily_device_limit', 'You have run the lab many times today. It opens again tomorrow (UTC).'],
      ['daily_ip_limit', 'You have run the lab many times today. It opens again tomorrow (UTC).'],
      ['daily_site_limit', 'The lab has had a busy day. It opens again tomorrow (UTC).']
    ];
    const controls = ['sw-earthquake', 'sw-heat'];
    for (let i = 0; i < cases.length; i++) {
      for (let j = 0; j < controls.length; j++) {
        ctl.startNewRun();
        const worlds = houseCalls.setWorld;
        const resets = houseCalls.reset;
        current = createFakeApi({ createEpisode: { status: 429, body: { error: cases[i][0] } } });
        byId[controls[j]].click();      // choose the disaster
        byId['btn-start'].click();      // then Start
        await settle();
        assert.strictEqual(current.count('createEpisode'), 1);
        assert.strictEqual(current.count('flipDisaster'), 0);
        assert.strictEqual(byId.notice.textContent, cases[i][1], cases[i][0] + ' via ' + controls[j]);
        assert.deepStrictEqual(marks(), ['OFF', 'OFF']);
        assert.strictEqual(houseCalls.setWorld, worlds, 'stage unchanged');
        assert.strictEqual(houseCalls.reset, resets);
        assert.strictEqual(ctl._state().phase, 'idle');
        assert.strictEqual(ctl._state().episodeId, null);
      }
    }
  });

  await check('the Device_Busy_Notice shows Notice_Resume, which resumes the episode named in the 409 body', async function () {
    ctl.startNewRun();
    byId['sw-earthquake'].click();
    current = createFakeApi({
      createEpisode: { status: 409, body: { error: 'device_already_running', episodeId: 'run1' } },
      getEpisode: okView('run1', [turn0]),
      ask: new Promise(function () {})
    });
    byId['btn-start'].click();
    await settle();
    assert.ok(byId.notice.textContent.indexOf('A running episode already exists on this device.') >= 0);
    assert.deepStrictEqual(buttons(byId.notice).map(function (b) { return b.textContent; }),
      ['Resume', 'Watch a past episode']);
    const gestures = order.filter(function (o) { return o === 'gesture'; }).length;
    noticeButton('Resume').click();
    assert.strictEqual(order.filter(function (o) { return o === 'gesture'; }).length, gestures + 1, 'First_Gesture');
    await settle();
    assert.strictEqual(current.count('createEpisode'), 1, 'no second create');
    assert.deepStrictEqual(current.callsOf('getEpisode')[0].args, ['run1']);
    assert.deepStrictEqual(current.callsOf('ask')[0].args, ['run1', 1], 'the running episode continues');
    assert.strictEqual(ctl._state().episodeId, 'run1');
    assert.strictEqual(ctl._state().phase, 'running');
  });

  await check('Notice_Resume falls back to the id kept in localStorage when the 409 has none', async function () {
    ctl.startNewRun();
    byId['sw-earthquake'].click();
    storage['ps:lastEpisodeId'] = 'stored9';
    current = createFakeApi({
      createEpisode: { status: 409, body: { error: 'device_already_running' } },
      getEpisode: okView('stored9', [turn0]),
      ask: new Promise(function () {})
    });
    byId['btn-start'].click();
    await settle();
    noticeButton('Resume').click();
    await settle();
    assert.deepStrictEqual(current.callsOf('getEpisode')[0].args, ['stored9']);
    assert.strictEqual(ctl._state().episodeId, 'stored9');
    assert.strictEqual(ctl._state().phase, 'running');
  });

  console.log(passed + ' examples passed.');
})().catch(function (e) { console.error(e); process.exit(1); });
