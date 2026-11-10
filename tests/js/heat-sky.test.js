'use strict';
// Heat-wave sky examples (public-lab Requirements 12.1, 12.2, 12.3, 12.5, 12.6,
// 14.12): exact Heat_Palette colours and sun discs in a heat-wave world, none of
// them in an earthquake world, the earthquake call log pinned to
// tests/fixtures/renderer_earthquake_calls.json (never regenerated or edited),
// and a heat world replaced by an earthquake world draws like earthquake alone.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const { makeCanvas, installDocument } = require('./helpers/fake-canvas');
const { drawAll } = require('./renderer-earthquake-calls.test.js');

const ROOT = path.join(__dirname, '..', '..');

const HEAT_WORLD = {
  disaster: 'heat_wave', timeOfDay: 'afternoon', power: 'on',
  ac: { on: false }, fan: { on: true, room: 'living_room' }, windows: { kitchen: true }, outside: 38,
  robots: { robot_a: { room: 'living_room' }, robot_b: { room: 'kitchen' } },
  man: { room: 'living_room', activity: 'sitting_at_table' },
};
const QUAKE_WORLD = {
  timeOfDay: 'night', power: 'on', shaking: true,
  robots: { robot_a: { room: 'living_room' }, robot_b: { room: 'kitchen' } },
  man: { room: 'living_room', activity: 'sitting_at_table', fear: 40 },
};

const HEAT_COLOURS = ['#3a1622', '#2a1a10', '#3c2614', '#ffc83a', '#ffd84a', '#ffe46a', 'rgb(58,14,30)', 'rgb(196,78,40)'];

function withRenderer(fn) {
  const savedRandom = Math.random;
  Math.random = function () { return 0.5; };
  global.positionMap = require(path.join(ROOT, 'public', 'js', 'position-map.js'));
  const { createHouseRenderer } = require(path.join(ROOT, 'public', 'js', 'house-renderer.js'));
  const log = [];
  const remove = installDocument(log);
  try {
    const renderer = createHouseRenderer();
    renderer.mount(makeCanvas(log));
    log.length = 0;
    fn(renderer, log);
  } finally {
    remove();
    Math.random = savedRandom;
  }
  return log;
}

function drawWorlds(worlds) {
  return withRenderer(function (renderer, log) {
    worlds.forEach(function (w, i) {
      if (i === worlds.length - 1) { log.length = 0; }
      renderer.setWorld(w);
    });
  });
}

function fills(log, colour) {
  return log.filter(function (e) { return e === 'fillStyle=' + colour; }).length;
}

// The log a disc of radius r at (cx, cy) in colour c produces (R per row).
function discCalls(cx, cy, r, c) {
  const out = [];
  for (let dy = -r; dy <= r; dy++) {
    const w = Math.floor(Math.sqrt(r * r - dy * dy));
    out.push('fillStyle=' + c, 'fillRect(' + (cx - w) + ',' + (cy + dy) + ',' + (w * 2 + 1) + ',1)');
  }
  return out;
}

function indexOfSeq(log, seq) {
  for (let i = 0; i + seq.length <= log.length; i++) {
    let ok = true;
    for (let j = 0; j < seq.length; j++) { if (log[i + j] !== seq[j]) { ok = false; break; } }
    if (ok) { return i; }
  }
  return -1;
}

// ── heat world ──
const heat = drawWorlds([HEAT_WORLD]);

// 12.1: sky gradient end colours (first and last of the 12 bands).
assert.ok(heat.includes('fillStyle=rgb(58,14,30)'), 'top sky colour rgb(58,14,30)');
assert.ok(heat.includes('fillStyle=rgb(196,78,40)'), 'bottom sky colour rgb(196,78,40)');
assert.ok(heat.indexOf('fillStyle=rgb(58,14,30)') < heat.indexOf('fillStyle=rgb(196,78,40)'), 'top band before bottom band');

// 12.2: hills, ground and ground texture.
assert.ok(fills(heat, '#3a1622') > 100, 'hills drawn in #3a1622');
assert.ok(heat.includes('fillStyle=#2a1a10'), 'ground #2a1a10');
assert.ok(fills(heat, '#3c2614') > 10, 'ground texture #3c2614');
['#1b1530', '#4f5a48', '#34452a', '#1c2418', '#2b3824'].forEach(function (c) {
  assert.ok(!heat.includes('fillStyle=' + c), 'no earthquake colour ' + c + ' in a heat world');
});
assert.ok(!heat.includes('fillStyle=#fff6d8'), 'no stars in a heat world');
assert.ok(!heat.includes('fillStyle=#0a0812'), 'no bats in a heat world');
assert.ok(!heat.includes('fillStyle=#fff2c8'), 'no generic sun in a heat world');

// 12.3: the three sun discs at (330, 34).
const sun = [].concat(
  ['globalAlpha=0.16', 'fillStyle=#ffc83a'], discCalls(330, 34, 32, '#ffc83a'), ['globalAlpha=1'],
  ['globalAlpha=0.32', 'fillStyle=#ffd84a'], discCalls(330, 34, 21, '#ffd84a'), ['globalAlpha=1'],
  discCalls(330, 34, 13, '#ffe46a')
);
assert.ok(indexOfSeq(heat, sun) >= 0, 'sun discs #ffc83a a0.16 r32, #ffd84a a0.32 r21, #ffe46a r13 at (330,34)');
assert.strictEqual(fills(heat, '#ffc83a'), 2 * 32 + 1 + 1, 'only the one glow disc in #ffc83a');

// ── earthquake world ──
const quake = drawWorlds([QUAKE_WORLD]);
// #2a1a10 is also the robot Amber's outline colour in every world, so the
// ground is identified by its full-width fill (fillRect starting at x = 0).
function groundFilledWith(log, colour) {
  return log.some(function (e, i) {
    return e === 'fillStyle=' + colour && /^fillRect\(0,/.test(log[i + 1] || '');
  });
}
assert.ok(groundFilledWith(heat, '#2a1a10'), 'heat ground is a full-width #2a1a10 fill');
assert.ok(!groundFilledWith(quake, '#2a1a10'), 'earthquake ground is not #2a1a10');
HEAT_COLOURS.filter(function (c) { return c !== '#2a1a10'; }).forEach(function (c) {
  assert.ok(!quake.some(function (e) { return e.indexOf(c) !== -1; }), 'earthquake world has no ' + c);
});
assert.ok(indexOfSeq(quake, discCalls(330, 34, 13, '#ffe46a')) < 0, 'no heat sun in an earthquake world');

// 12.5 / 14.12: the earthquake call log equals the pinned fixture.
const fixture = JSON.parse(fs.readFileSync(path.join(ROOT, 'tests', 'fixtures', 'renderer_earthquake_calls.json'), 'utf8'));
const live = drawAll();
assert.deepStrictEqual(Object.keys(live), Object.keys(fixture), 'same set of fixture worlds');
Object.keys(fixture).forEach(function (name) {
  assert.deepStrictEqual(live[name], fixture[name], 'earthquake call log differs from fixture for ' + name);
});

// 12.6: heat then earthquake equals earthquake alone.
const after = drawWorlds([HEAT_WORLD, QUAKE_WORLD]);
assert.deepStrictEqual(after, quake, 'earthquake after heat draws exactly as earthquake alone');

console.log('heat-sky: ok');
