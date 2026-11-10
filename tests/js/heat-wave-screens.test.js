/*
 * heat-wave-screens.test.js — headless (Node) example tests for the heat-wave
 * screens (heat-wave spec Requirements 10.1 - 10.6).
 *
 * Same style as the other tests in this folder: no dependencies, Node's
 * built-in `assert`, a tiny DOM shim, run with
 * `node tests/js/heat-wave-screens.test.js`. It makes no network call (fetch is
 * a recorder) and names the robots only Amber and Jade.
 *
 * What is asserted:
 *   10.1  the Heat wave lever is enabled and wired (index.php), and a click on Heat wave creates a
 *         heat-wave episode through the API client;
 *   10.2  the world's heat fields drive heat drawing only when `disaster` is
 *         heat wave, and the fan is drawn in the room it is in;
 *   10.5  an earthquake world (no `disaster`, or `earthquake`) runs no heat
 *         drawing and is drawn exactly as a world without heat fields;
 *   10.3  the resident's room temperature shows beside his meters in a heat
 *         world and stays hidden otherwise;
 *   10.4  the Replay list item, the Replay screen label and the End_Card say
 *         "Heat wave" for heat-wave episodes only;
 *   10.6  the robots are named only Amber / Jade, and no AI company or model
 *         name appears in what these screens render.
 */
'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..', '..');
const JS = path.join(ROOT, 'public', 'js');

// ── Minimal DOM shim ────────────────────────────────────────────────────────
function makeElement(tag) {
  const attrs = {};
  const classes = new Set();
  const listeners = {};
  const el = {
    tagName: String(tag || 'div').toUpperCase(),
    children: [],
    attrs: attrs,
    hidden: false,
    disabled: false,
    type: '',
    style: {},
    _text: '',
    _subs: {},          // selector -> child element (for querySelector)
    listeners: listeners,
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
  Object.defineProperty(el, 'className', {
    get: function () { return Array.from(classes).join(' '); },
    set: function (v) { classes.clear(); String(v).split(/\s+/).filter(Boolean).forEach(function (c) { classes.add(c); }); }
  });
  Object.defineProperty(el, 'textContent', {
    get: function () {
      return el._text + el.children.map(function (c) { return c.textContent; }).join('');
    },
    set: function (v) { el._text = String(v); el.children.length = 0; }
  });
  Object.defineProperty(el, 'innerHTML', {
    get: function () { return el.textContent; },
    set: function (v) { el._text = String(v); el.children.length = 0; }
  });
  return el;
}

// A canvas whose 2d context records every fill colour it is asked to paint
// with and every call it receives, so two drawings can be compared exactly.
function makeCanvas(log) {
  const cv = makeElement('canvas');
  cv.width = 0; cv.height = 0;
  const target = {};
  const ctx = new Proxy(target, {
    get: function (t, name) {
      if (name in t) { return t[name]; }
      return function () { log.push(String(name) + ':' + t.fillStyle + ':' + t.globalAlpha + ':' + Array.prototype.slice.call(arguments).join(',')); return undefined; };
    },
    set: function (t, name, value) { t[name] = value; return true; }
  });
  cv.getContext = function () { return ctx; };
  return cv;
}

function installGlobals() {
  const byId = {};
  const doc = {
    readyState: 'loading',
    _ready: [],
    addEventListener: function (ev, fn) { if (ev === 'DOMContentLoaded') { doc._ready.push(fn); } },
    createElement: function (tag) {
      return tag === 'canvas' ? makeCanvas([]) : makeElement(tag);
    },
    createTextNode: function (t) { const n = makeElement('#text'); n.textContent = t; return n; },
    getElementById: function (id) {
      if (!byId[id]) { byId[id] = makeElement('div'); }
      return byId[id];
    }
  };
  global.document = doc;
  global.window = {};
  return { byId: byId, doc: doc };
}

function fire(doc) { doc._ready.forEach(function (fn) { fn(); }); doc._ready = []; }

let passed = 0;
function check(name, fn) { fn(); passed += 1; console.log('  ok - ' + name); }

// Provider / model names that must never reach robot-facing text (Req 10.6).
const FORBIDDEN = ['claude', 'gpt', 'anthropic', 'openai', 'chatgpt', 'haiku', 'luna'];
function assertNoProviderName(where, text) {
  const hay = String(text).toLowerCase();
  FORBIDDEN.forEach(function (w) {
    assert.ok(hay.indexOf(w) === -1, where + ' must not contain "' + w + '"');
  });
}

function allText(el) {
  let parts = [el.textContent];
  Object.keys(el.attrs || {}).forEach(function (k) { parts.push(String(el.attrs[k])); });
  (el.children || []).forEach(function (c) { parts = parts.concat(allText(c)); });
  return parts.join(' ');
}

function findByClass(el, cls) {
  if (el.classList && el.classList.contains(cls)) { return el; }
  for (const c of (el.children || [])) {
    const f = findByClass(c, cls);
    if (f) { return f; }
  }
  return null;
}

console.log('heat-wave screens (heat-wave Req 10.1 - 10.6)');

// ── 10.1 static markup ──────────────────────────────────────────────────────
const indexSrc = fs.readFileSync(path.join(ROOT, 'public', 'index.php'), 'utf8');

function buttonTag(src, id) {
  const m = new RegExp('<button[^>]*id="' + id + '"[^>]*>[\\s\\S]*?</button>').exec(src);
  assert.ok(m, 'button #' + id + ' exists');
  return m[0];
}

check('Heat wave lever is enabled (10.1)', function () {
  const heat = buttonTag(indexSrc, 'sw-heat');
  assert.ok(!/\bdisabled\b/.test(heat), 'Heat wave button is not disabled');
  assert.ok(/Heat wave/.test(heat), 'it is labelled Heat wave');
  assert.ok(!/coming later/.test(heat), 'it is not marked "coming later"');
  assert.ok(/aria-pressed="false"/.test(heat), 'it starts OFF');


  const eq = buttonTag(indexSrc, 'sw-earthquake');
  assert.ok(!/\bdisabled\b/.test(eq), 'Earthquake stays enabled');
});

check('the room-temperature row exists in index.php and starts hidden (10.3)', function () {
  assert.ok(/id="meter-room"[^>]*\bhidden\b/.test(indexSrc), '#meter-room is hidden by default');
});

check('replay.php carries a hidden disaster label (10.4)', function () {
  const src = fs.readFileSync(path.join(ROOT, 'public', 'replay.php'), 'utf8');
  assert.ok(/id="disaster-label"[^>]*\bhidden\b/.test(src), '#disaster-label is hidden by default');
});

// ── 10.1 API client: heat wave create / convert, earthquake URLs unchanged ──
check('api client adds ?disaster=heat_wave only for the heat wave (10.1)', function () {
  installGlobals();
  const urls = [];
  global.fetch = function (url, opts) {
    urls.push({ url: String(url), method: opts && opts.method });
    return Promise.resolve({ status: 200, text: function () { return Promise.resolve('{}'); } });
  };
  const apiMod = require(path.join(JS, 'api.js'));
  const client = apiMod.createApiClient();
  client.createEpisode();
  client.createEpisode('heat_wave');
  client.flipDisaster('abc');
  client.flipDisaster('abc', 'heat_wave');
  assert.strictEqual(urls.length, 4);
  assert.ok(!/disaster=/.test(urls[0].url), 'earthquake create has no disaster param');
  assert.ok(/disaster=heat_wave/.test(urls[1].url), 'heat create carries disaster=heat_wave');
  const flipPath = /episodes(\/|%2F)abc(\/|%2F)disaster/;
  assert.ok(flipPath.test(urls[2].url) && !/disaster=/.test(urls[2].url),
    'earthquake flip is unchanged: ' + urls[2].url);
  assert.ok(flipPath.test(urls[3].url) && /disaster=heat_wave/.test(urls[3].url),
    'heat flip carries disaster=heat_wave');
});

// ── Observe: lever click, room temperature, End_Card ────────────────────────
const g = installGlobals();
const apiCalls = [];
const heatView = { episodeId: 'e1', disaster: 'heat_wave', currentExpectedTurn: 0, ended: false, turns: [] };
const fakeApi = {
  createEpisode: function (d) { apiCalls.push(['create', d]); return Promise.resolve({ status: 200, body: heatView }); },
  flipDisaster: function (id, d) { apiCalls.push(['flip', id, d]); return Promise.resolve({ status: 200, body: Object.assign({}, heatView, { ended: true }) }); },
  getEpisode: function () { return Promise.resolve({ status: 200, body: fakeApi.endedView }); },
  ask: function () { return new Promise(function () { /* never resolves */ }); }
};
g.doc.getElementById('sw-heat');   // make sure the element exists before init
['sw-earthquake', 'sw-heat'].forEach(function (id) {
  g.byId[id] = g.byId[id] || makeElement('button');
  g.byId[id]._subs['.mark'] = makeElement('span');
  g.byId[id].setAttribute('aria-pressed', 'false');
});
['meter-temp', 'meter-water', 'meter-injury', 'meter-fear', 'meter-room'].forEach(function (id) {
  const m = g.doc.getElementById(id);
  m._subs['.fill'] = makeElement('span');
  m._subs['.val'] = makeElement('span');
});
g.byId['meter-room'].hidden = true;
global.window.createApiClient = function () { return fakeApi; };

const observe = require(path.join(JS, 'observe.js'));
fire(g.doc);

function roomRow() { return g.byId['meter-room']; }
function manIn(room) { return { room: room, bodyTemp: 37.2, water: 80, injury: 0, fear: 5 }; }

apiCalls.length = 0;
g.byId['sw-heat'].click();
const clickDone = new Promise(function (r) { setImmediate(r); }).then(function () {
  check('clicking Heat wave creates a heat-wave episode and fixes the heat (10.1)', function () {
    assert.deepStrictEqual(apiCalls[0], ['create', 'heat_wave'], 'creates a heat-wave episode');
    assert.deepStrictEqual(apiCalls[1], ['flip', 'e1', 'heat_wave'], 'then fixes the heat');
    assert.strictEqual(apiCalls.length, 2, 'no second episode is created');
    assert.strictEqual(g.byId['sw-heat'].getAttribute('aria-pressed'), 'true', 'the lever is up');
    assert.strictEqual(g.byId['sw-heat'].querySelector('.mark').textContent, 'ON');
    assert.strictEqual(observe._state().disaster, 'heat_wave');
  });
});


check('room temperature is hidden for an earthquake world (10.3)', function () {
  roomRow().hidden = false;
  observe.updateMeters(manIn('kitchen'), { man: manIn('kitchen') });
  assert.strictEqual(roomRow().hidden, true, 'hidden with no disaster field');
  roomRow().hidden = false;
  observe.updateMeters(manIn('kitchen'), { disaster: 'earthquake', man: manIn('kitchen') });
  assert.strictEqual(roomRow().hidden, true, 'hidden for disaster = earthquake');
  assert.strictEqual(roomRow().querySelector('.val').textContent, '', 'never filled');
});

check('room temperature shows the temperature of the room he is in (10.3)', function () {
  const world = {
    disaster: 'heat_wave',
    roomTemps: { kitchen: 33.6, living_room: 29.2, bedroom: 31 },
    man: manIn('kitchen')
  };
  observe.updateMeters(world.man, world);
  assert.strictEqual(roomRow().hidden, false, 'row is shown in a heat world');
  assert.strictEqual(roomRow().querySelector('.val').textContent, '34\u00b0C');
  world.man = manIn('living_room');
  observe.updateMeters(world.man, world);
  assert.strictEqual(roomRow().querySelector('.val').textContent, '29\u00b0C', 'follows him to the next room');
  // other meters are still filled as before
  assert.ok(/37\.2/.test(g.byId['meter-temp'].querySelector('.val').textContent));
});

check('a room with no recorded temperature shows a dash, not a number (10.3)', function () {
  const world = { disaster: 'heat_wave', roomTemps: {}, man: manIn('hall') };
  observe.updateMeters(world.man, world);
  assert.strictEqual(roomRow().querySelector('.val').textContent, '\u2014');
});

// End_Card: reach the real onEpisodeEnded through the device-busy Resume button.
function endCardFor(view) {
  fakeApi.endedView = view;
  g.byId['endcard-host'].children.length = 0;
  observe._state().episodeId = null;
  observe._state().running = false;
  observe._state().ended = false;
  observe._state().phase = 'idle';
  fakeApi.createEpisode = function () {
    return Promise.resolve({ status: 409, body: { error: 'device_already_running', episodeId: view.episodeId } });
  };
  observe.startRun();
  return new Promise(function (r) { setImmediate(r); }).then(function () {
    const notice = g.byId.notice;
    const resume = (function find(e) {
      if (e.tagName === 'BUTTON' && /Resume/.test(e.textContent)) { return e; }
      for (const c of e.children) { const f = find(c); if (f) { return f; } }
      return null;
    })(notice);
    assert.ok(resume, 'Resume button offered');
    resume.click();
    return new Promise(function (r) { setImmediate(r); });
  }).then(function () {
    return g.byId['endcard-host'];
  });
}

function endedView(extra) {
  return Object.assign({
    episodeId: 'e2', ended: true, currentExpectedTurn: 2,
    turns: [
      { turnNumber: 0, state: 'applied', world: { man: { bodyTemp: 37.9, water: 60, injury: 0, fear: 10 } } },
      { turnNumber: 1, state: 'applied',
        world: { man: { bodyTemp: 38.4, water: 55, injury: 0, fear: 12 } },
        robots: {
          robot_a: { reply: 'I open the window.', seenByOtherText: 'The other robot opened the window.', whatHappenedText: 'The window was opened.' },
          robot_b: { reply: 'I bring water.', seenByOtherText: 'The other robot brought water.', whatHappenedText: 'Water was brought.' }
        } }
    ]
  }, extra || {});
}

const pending = [];

pending.push(clickDone.then(function () {
  return endCardFor(endedView({ disaster: 'heat_wave' }));
}).then(function (host) {
  check('End_Card shows "Heat wave" for a heat-wave episode (10.4)', function () {
    const tag = findByClass(host, 'disaster-tag');
    assert.ok(tag, 'a disaster tag is on the card');
    assert.strictEqual(tag.textContent, 'Heat wave');
    assertNoProviderName('heat End_Card', allText(host));
    assert.ok(/Amber/.test(allText(host)) && /Jade/.test(allText(host)), 'robots are named Amber and Jade');
  });
  return endCardFor(endedView({ disaster: 'earthquake' }));
}).then(function (host) {
  check('End_Card for an earthquake episode has no disaster tag (10.4)', function () {
    assert.strictEqual(findByClass(host, 'disaster-tag'), null);
    assert.ok(!/Heat wave/.test(allText(host)));
  });
  return endCardFor(endedView({}));
}).then(function (host) {
  check('End_Card for an episode with no disaster field has no tag (10.4)', function () {
    assert.strictEqual(findByClass(host, 'disaster-tag'), null);
    assertNoProviderName('earthquake End_Card', allText(host));
  });
}));

// ── Replay: list item and screen label ──────────────────────────────────────
pending.push(Promise.all(pending.slice()).then(function () {
  const r = installGlobals();
  const listed = [
    { id: 'h1', createdDate: '2026-11-09T10:00:00+09:00', turns: 6, disaster: 'heat_wave', startingSituation: 'Afternoon. Amber is in the kitchen.' },
    { id: 'q1', createdDate: '2026-11-08T10:00:00+09:00', turns: 5, startingSituation: 'Night. Jade is in the hall.' }
  ];
  global.window.createApiClient = function () {
    return { listEnded: function () { return Promise.resolve({ status: 200, body: { featured: listed, yours: [] } }); } };
  };
  delete require.cache[require.resolve(path.join(JS, 'replay.js'))];
  const replay = require(path.join(JS, 'replay.js'));
  r.byId['disaster-label'] = makeElement('span');
  r.byId['disaster-label'].hidden = true;
  fire(r.doc);   // no ?episode -> shows the list
  return new Promise(function (res) { setImmediate(res); }).then(function () {
    check('Replay list marks "Heat wave" on heat-wave episodes only (10.4)', function () {
      // children[0] is the Featured section: heading first, then the items.
      const items = r.byId['episode-list'].children[0].children.slice(1);
      assert.strictEqual(items.length, 2);
      const heatTag = findByClass(items[0], 'disaster-tag');
      assert.ok(heatTag && heatTag.textContent === 'Heat wave', 'heat item is tagged');
      assert.strictEqual(findByClass(items[1], 'disaster-tag'), null, 'earthquake item is not');
      assert.ok(!/Heat wave/.test(allText(items[1])));
      assertNoProviderName('Replay list', allText(r.byId['episode-list']));
    });
    check('Replay screen label shows "Heat wave" for a heat-wave episode only (10.4)', function () {
      const label = r.byId['disaster-label'];
      const base = { ended: true, turns: [{ turnNumber: 0, world: {}, robots: {} }] };
      replay.startPlayback(Object.assign({ disaster: 'heat_wave' }, base));
      assert.strictEqual(label.hidden, false);
      assert.strictEqual(label.textContent, 'Heat wave');
      replay.startPlayback(Object.assign({ disaster: 'earthquake' }, base));
      assert.strictEqual(label.hidden, true);
      assert.strictEqual(label.textContent, '');
      replay.startPlayback(base);
      assert.strictEqual(label.hidden, true, 'no disaster field -> hidden');
    });
  });
}));

// ── House renderer: heat drawing gated on `disaster` (10.2, 10.5) ──────────
// Runs after the Observe / Replay checks: it swaps the global document shim.
function renderWorld(world) {
  installGlobals();
  const log = [];
  const cv = makeCanvas(log);
  delete require.cache[require.resolve(path.join(JS, 'house-renderer.js'))];
  delete require.cache[require.resolve(path.join(JS, 'position-map.js'))];
  require(path.join(JS, 'position-map.js'));
  const hr = require(path.join(JS, 'house-renderer.js'));
  const house = hr.createHouseRenderer();
  house.mount(cv, null);
  log.length = 0;
  house.setWorld(world);
  return { house: house, log: log.slice() };
}

const quake = {
  timeOfDay: 'night', power: true, shaking: false,
  man: { room: 'bedroom' }, robots: { robot_a: { room: 'kitchen' }, robot_b: { room: 'hall' } },
  rooms: {}, objects: { broken: [], fallen: [] }
};
const heat = {
  timeOfDay: 'afternoon', power: true, shaking: false, disaster: 'heat_wave',
  man: { room: 'living_room' }, robots: { robot_a: { room: 'kitchen' }, robot_b: { room: 'hall' } },
  rooms: {}, objects: { broken: [], fallen: [] },
  roomTemps: { kitchen: 33, living_room: 31 }, outside: 38,
  ac: { on: true }, fan: { on: true, room: 'kitchen' },
  windows: { living_room: true, kitchen: false }
};

function houseChecks() {
check('heat fields drive heat drawing when disaster is heat wave (10.2)', function () {
  const r = renderWorld(heat);
  const S = r.house._state().S;
  assert.ok(S.heat, 'heat scene state is set');
  assert.strictEqual(S.heat.ac, true);
  assert.strictEqual(S.heat.fan.on, true);
  assert.strictEqual(S.heat.fan.room, 'kitchen', 'the fan is placed in the room it is in');
  assert.strictEqual(S.heat.windows.living_room, true);
  assert.strictEqual(S.heat.outside, 38);
  assert.strictEqual(S.timeOfDay, 'afternoon');
  // The heat sky paints its sun colour; nothing else in the scene uses it.
  assert.ok(r.log.some(function (l) { return l.indexOf('#ffe46a') !== -1; }), 'heat sky is painted');
});

check('the fan moves to the room the world puts it in (10.2)', function () {
  const a = renderWorld(heat);
  const b = renderWorld(Object.assign({}, heat, { fan: { on: false, room: 'bedroom' } }));
  assert.strictEqual(b.house._state().S.heat.fan.room, 'bedroom');
  assert.strictEqual(b.house._state().S.heat.fan.on, false);
  assert.notDeepStrictEqual(a.log, b.log, 'a different fan room / state is drawn differently');
});

check('an earthquake world runs no heat drawing (10.5)', function () {
  const none = renderWorld(quake);
  const explicit = renderWorld(Object.assign({}, quake, { disaster: 'earthquake' }));
  assert.strictEqual(none.house._state().S.heat, null);
  assert.strictEqual(explicit.house._state().S.heat, null);
  assert.ok(!none.log.some(function (l) { return l.indexOf('#ffe46a') !== -1; }), 'no heat sky');
  assert.deepStrictEqual(explicit.log, none.log, 'drawn exactly as a world without a disaster field');
});

check('heat fields on a non-heat world are ignored (10.5)', function () {
  const stray = renderWorld(Object.assign({}, quake, {
    ac: { on: true }, fan: { on: true, room: 'kitchen' }, windows: { kitchen: true }, roomTemps: { kitchen: 40 }
  }));
  const plain = renderWorld(quake);
  assert.strictEqual(stray.house._state().S.heat, null);
  assert.deepStrictEqual(stray.log, plain.log);
});

check('switching from a heat world back to an earthquake world clears the heat (10.5)', function () {
  const r = renderWorld(heat);
  r.house.setWorld(quake);
  assert.strictEqual(r.house._state().S.heat, null);
});

// ── 10.6 names ──────────────────────────────────────────────────────────────
check('the heat-wave markup names the robots only Amber / Jade (10.6)', function () {
  // index.php carries a separate viewer-facing line that discloses which models
  // run the lab; that line is not robot-facing text and is not part of this
  // change. The heat-wave additions themselves must carry no company or model
  // name, and the screens still name the robots Amber and Jade.
  const heatBits = [
    buttonTag(indexSrc, 'sw-heat'),
    /<div[^>]*id="meter-room"[\s\S]*?<\/div>/.exec(indexSrc)[0]
  ];
  const replaySrc = fs.readFileSync(path.join(ROOT, 'public', 'replay.php'), 'utf8');
  heatBits.push(/<span[^>]*id="disaster-label"[^>]*><\/span>/.exec(replaySrc)[0]);
  heatBits.forEach(function (b) { assertNoProviderName('heat markup', b); });
  assert.ok(/Amber/.test(indexSrc) && /Jade/.test(indexSrc));
  // heat-wave JS strings: the labels the screens add
  ['observe.js', 'replay.js', 'house-renderer.js'].forEach(function (f) {
    const src = fs.readFileSync(path.join(JS, f), 'utf8');
    const heatLines = src.split('\n').filter(function (l) { return /heat/i.test(l); }).join('\n');
    assertNoProviderName(f + ' heat-wave code', heatLines);
  });
});
}

Promise.all(pending).then(function () {
  houseChecks();
  console.log(passed + ' checks passed.');
}, function (e) {
  console.error(e);
  process.exit(1);
});
