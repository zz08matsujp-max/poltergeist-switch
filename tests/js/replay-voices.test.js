'use strict';
// Voices and the sequencer on the Replay screen (public-lab Req 11.1, 11.3,
// 11.16, 14.11): the button starts "Voices: on" and exists in list and playback
// modes; nothing is spoken before the First_Gesture; Pause, Step back, Step
// forward and Play cancel any reading and bubble timer, close the open bubble
// and start no further line until the new position's Line_Sequence begins; the
// picture still ends in the recorded world.
//
// Headless: a small fake DOM, the real replay.js / act-out.js / speech-sequencer.js,
// a fake house and fake Voices on a virtual clock. Act_Out runs with reduced
// motion, so each turn's world is applied within about 100 ms of real time.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const { createFakeClock } = require('./helpers/fake-clock');
const { createFakeHouse } = require('./helpers/fake-house');
const { createFakeVoices } = require('./helpers/fake-voices');

const ROOT = path.join(__dirname, '..', '..');
const REPLAY_JS = path.join(ROOT, 'public', 'js', 'replay.js');

// ── fake DOM ─────────────────────────────────────────────────────────────────
function makeNode(tag) {
  const node = {
    tagName: String(tag).toUpperCase(), className: '', hidden: false, type: '',
    children: [], childNodes: null, attrs: {}, listeners: {}, _text: '', style: {},
    appendChild(child) { this.children.push(child); return child; },
    setAttribute(k, v) { this.attrs[k] = String(v); },
    getAttribute(k) { return this.attrs[k]; },
    addEventListener(name, fn) { (this.listeners[name] = this.listeners[name] || []).push(fn); },
    click() { (this.listeners.click || []).forEach(function (fn) { fn(); }); }
  };
  node.childNodes = node.children;
  Object.defineProperty(node, 'textContent', {
    get() { return this._text; },
    set(v) { this._text = String(v); this.children.length = 0; }
  });
  return node;
}

let byId = {};
let initFn = null;
global.document = {
  readyState: 'loading',
  addEventListener(name, fn) { if (name === 'DOMContentLoaded') { initFn = fn; } },
  createElement: makeNode,
  createTextNode(t) { const n = makeNode('#text'); n._text = String(t); return n; },
  createDocumentFragment() { return makeNode('#fragment'); },
  getElementById(id) { if (!byId[id]) { byId[id] = makeNode('div'); } return byId[id]; }
};
global.window = {
  location: { search: '', pathname: 'replay.php' },
  matchMedia: function () { return { matches: true }; } // reduced motion
};
require(path.join(ROOT, 'public', 'js', 'position-map.js'));
require(path.join(ROOT, 'public', 'js', 'speech-sequencer.js'));
require(path.join(ROOT, 'public', 'js', 'act-out.js'));
const realCreateSequencer = global.window.createSpeechSequencer;

// ── an ended episode: turn n says "Wn" for Amber, with world {w: n} ──────────
function makeView() {
  const turns = [];
  for (let n = 0; n <= 3; n++) {
    turns.push({
      turnNumber: n,
      world: { w: n },
      residentLine: '',
      robots: {
        robot_a: {
          reply: 'I say W' + n,
          reading: { operation: n === 0 ? 'wait' : 'speak', words: n === 0 ? [] : ['W' + n] },
          situationText: 'text'
        },
        robot_b: { reply: 'ok', reading: { operation: 'wait', words: [] }, situationText: 'text' }
      }
    });
  }
  return { ended: true, disaster: 'earthquake', turns: turns };
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function boot(search) {
  byId = {};
  initFn = null;
  const clock = createFakeClock();
  const house = createFakeHouse(clock);
  const worlds = [];
  house.setWorld = function (w) { worlds.push(w); };
  house.mount = function () {};
  house.start = function () {};
  const voices = createFakeVoices(clock, { gestured: false }); // silent until gesture()
  const w = global.window;
  w.location.search = search;
  w.createApiClient = function () {
    return {
      listEnded: function () { return Promise.resolve({ status: 200, body: { featured: [], yours: [] } }); },
      getEpisode: function () { return Promise.resolve({ status: 200, body: makeView() }); }
    };
  };
  w.createHouseRenderer = function () { return house; };
  w.createVoices = function () { return voices; };
  w.createSpeechSequencer = function (o) {
    return realCreateSequencer({ house: o.house, voices: o.voices, timers: clock, now: clock.now });
  };
  delete require.cache[require.resolve(REPLAY_JS)];
  const controller = require(REPLAY_JS);
  assert.strictEqual(typeof initFn, 'function');
  initFn();
  await sleep(20);
  return {
    controller, clock, house, voices, worlds,
    click: function (id) { byId[id].click(); },
    btn: function () { return byId['btn-voices']; }
  };
}

const spokenTexts = (voices, from) =>
  voices.calls.slice(from).filter((c) => c.type === 'speak' && c.started).map((c) => c.line);
const bubbleTexts = (house, from) =>
  house.calls.slice(from).filter((c) => c.text).map((c) => c.text);

(async function () {
  // The page: button in the header with the right label, sequencer loaded first.
  {
    const html = fs.readFileSync(path.join(ROOT, 'public', 'replay.php'), 'utf8');
    const header = html.slice(html.indexOf('<header>'), html.indexOf('</header>'));
    assert.ok(/id="btn-voices"[^>]*aria-pressed="true">Voices: on<\/button>/.test(header),
      'Voices button sits in the header, labelled "Voices: on"');
    assert.ok(html.indexOf('src="js/speech-sequencer.js"') > 0 &&
      html.indexOf('src="js/speech-sequencer.js"') < html.indexOf('src="js/act-out.js"'),
      'speech-sequencer.js loads before act-out.js');
  }

  // Label "Voices: on" in list mode and in playback mode.
  {
    const list = await boot('');
    assert.strictEqual(list.btn().textContent, 'Voices: on');
    assert.strictEqual(list.btn().getAttribute('aria-pressed'), 'true');
    assert.strictEqual(list.btn().hidden, false);
    assert.strictEqual(byId['episode-list'].hidden, false, 'list mode');

    const play = await boot('?episode=e1');
    assert.strictEqual(play.btn().textContent, 'Voices: on');
    assert.strictEqual(play.btn().getAttribute('aria-pressed'), 'true');
    assert.strictEqual(play.btn().hidden, false);
    assert.strictEqual(byId['replay-main'].hidden, false, 'playback mode');
    // Loading and the still opening frame start no speech and no gesture.
    assert.strictEqual(play.voices.calls.length, 0);
  }

  // No speech before the First_Gesture; the click primes speech first.
  {
    const e = await boot('?episode=e1');
    e.controller.stepForward(); // not a click: no gesture
    await sleep(150);
    assert.ok(e.voices.calls.some((c) => c.type === 'speak'), 'line was offered to Voices');
    assert.deepStrictEqual(e.voices.spoken(), [], 'nothing spoken before the First_Gesture');
    assert.ok(!e.voices.calls.some((c) => c.type === 'gesture'));
    e.click('btn-play');
    const gestureAt = e.voices.calls.findIndex((c) => c.type === 'gesture');
    assert.ok(gestureAt >= 0, 'Play click is a gesture');
    await sleep(150);
    const firstSpoken = e.voices.calls.findIndex((c) => c.type === 'speak' && c.started);
    assert.ok(firstSpoken > gestureAt, 'speech starts only after the gesture');
  }

  // Each control is a gesture.
  for (const id of ['btn-play', 'btn-pause', 'btn-stepback', 'btn-stepforward']) {
    const e = await boot('?episode=e1');
    e.click(id);
    assert.strictEqual(e.voices.calls.filter((c) => c.type === 'gesture').length, 1, id);
  }

  // Pause / Step back / Step forward / Play while a line is being read.
  const cases = [
    { id: 'btn-pause', expectSpoken: [], expectWorld: { w: 2 } },
    { id: 'btn-stepback', expectSpoken: ['W1'], expectWorld: { w: 1 } },
    { id: 'btn-stepforward', expectSpoken: ['W3'], expectWorld: { w: 3 } },
    { id: 'btn-play', expectSpoken: ['W2'], expectWorld: { w: 2 } }
  ];
  for (const c of cases) {
    const e = await boot('?episode=e1');
    e.click('btn-stepforward');
    e.click('btn-stepforward');
    await sleep(150);
    assert.deepStrictEqual(e.voices.reading().map((r) => r.line), ['W2'], c.id + ': reading W2');
    assert.strictEqual(e.house.openCount(), 1, c.id + ': bubble open');

    const vMark = e.voices.calls.length;
    const hMark = e.house.calls.length;
    const wMark = e.worlds.length;
    e.click(c.id);

    // Immediately: the old reading is cancelled first and its bubble closed.
    const after = e.voices.calls.slice(vMark).filter((x) => x.type !== 'gesture');
    assert.strictEqual(after[0].type, 'cancel', c.id + ': the cancel comes first');
    assert.ok(after.some((x) => x.type === 'done' && x.line === 'W2' && x.reason === 'cancelled'),
      c.id + ': the old reading was cancelled');
    const closeOld = e.house.calls.slice(hMark).find((x) => x.id === 'amber' && !x.text);
    assert.ok(closeOld, c.id + ': old bubble closed');

    // Only the new position's Line_Sequence may start (Act_Out starts it with
    // the new turn); nothing else, and the old line never resumes.
    assert.deepStrictEqual(spokenTexts(e.voices, vMark), c.expectSpoken, c.id + ': new sequence only');
    assert.deepStrictEqual(bubbleTexts(e.house, hMark), c.expectSpoken, c.id + ': bubbles match');
    assert.strictEqual(e.house.openCount(), c.expectSpoken.length, c.id + ': one bubble for the new line only');
    if (c.expectSpoken.length === 0) {
      assert.strictEqual(e.clock.pending(), 0, c.id + ': no bubble timer left');
      e.clock.advance(60000);
      assert.deepStrictEqual(spokenTexts(e.voices, vMark), [], c.id + ': nothing resumes');
      assert.strictEqual(e.house.openCount(), 0);
    }
    await sleep(150);

    // The picture ends in the recorded world: turn 2's world was applied before
    // the cut, and the last world is the new position's recorded world.
    assert.deepStrictEqual(e.worlds.slice(0, wMark).pop(), { w: 2 }, c.id + ': turn 2 ended in its world');
    assert.deepStrictEqual(e.worlds[e.worlds.length - 1], c.expectWorld, c.id + ': ends in recorded world');
  }

  console.log('replay-voices: ok');
  process.exit(0); // replay.js leaves a 10 s list timeout pending
})().catch((e) => { console.error(e); process.exit(1); });
