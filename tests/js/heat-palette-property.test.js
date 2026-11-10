'use strict';
// Feature: public-lab, Property 22: Heat palette only in heat worlds
// Validates: Requirements 12.1, 12.2, 12.3, 12.5, 12.6
//
// Seeded property test over random worlds. An earthquake world is drawn exactly
// as recorded and carries no Heat_Palette colour and no heat sun; a heat-wave
// world has the heat sky, hills, ground, ground texture and sun; a heat world
// followed by an earthquake world draws the same calls as the earthquake alone.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const { makeCanvas, installDocument } = require('./helpers/fake-canvas');
const rng = require('./helpers/rng');

const ROOT = path.join(__dirname, '..', '..');
const CASES = 120;
const ROOMS = ['entrance', 'bedroom', 'living_room', 'kitchen', 'bathroom', 'storeroom'];
const TIMES = ['night', 'morning', 'afternoon'];

const fixture = JSON.parse(fs.readFileSync(path.join(ROOT, 'tests', 'fixtures', 'renderer_earthquake_calls.json'), 'utf8'));
const fixtureWorlds = require('./renderer-earthquake-calls.test.js');

global.positionMap = require(path.join(ROOT, 'public', 'js', 'position-map.js'));
const { createHouseRenderer } = require(path.join(ROOT, 'public', 'js', 'house-renderer.js'));

// Heat_Palette colours that never appear in an earthquake drawing. '#2a1a10'
// is also Amber's outline, so the ground is checked by its fillRect instead.
const HEAT_ONLY = ['#3a1622', '#3c2614', '#ffc83a', '#ffd84a', '#ffe46a', 'rgb(58,14,30)', 'rgb(196,78,40)'];

function randomWorld(r, heat) {
  const w = {
    timeOfDay: rng.pick(r, TIMES),
    power: rng.pick(r, ['on', 'off']),
    robots: {
      robot_a: { room: rng.pick(r, ROOMS) },
      robot_b: { room: rng.pick(r, ROOMS) },
    },
    man: { room: rng.pick(r, ROOMS), fear: rng.int(r, 0, 100) },
  };
  // A heat wave has no shaking; shaking draws advance the renderer's own PRNG,
  // which is animation state and not part of the palette.
  if (!heat && rng.bool(r, 0.3)) { w.shaking = true; }
  if (rng.bool(r, 0.3)) { w.stove = { on: true }; }
  if (rng.bool(r, 0.4)) { w.objects = { broken: rng.subset(r, ['dish_cupboard', 'tv']), fallen: rng.subset(r, ['tv', 'shelf']) }; }
  if (rng.bool(r, 0.4)) { w.rooms = { living_room: { light: rng.pick(r, ['normal', 'dim', 'dark']) } }; }
  if (heat) {
    w.disaster = 'heat_wave';
    w.ac = { on: rng.bool(r) };
    w.fan = { on: rng.bool(r), room: rng.pick(r, ROOMS) };
    w.windows = {};
    ROOMS.forEach(function (k) { w.windows[k] = rng.bool(r); });
    w.outside = rng.int(r, 28, 40);
  } else if (rng.bool(r, 0.3)) {
    w.disaster = 'earthquake';
  }
  return w;
}

// Draw the worlds in order in ONE renderer; return the call log of the last one.
function drawSequence(worlds) {
  const log = [];
  const remove = installDocument(log);
  const saved = Math.random;
  Math.random = function () { return 0.5; };
  try {
    const renderer = createHouseRenderer();
    renderer.mount(makeCanvas(log));
    let last = null;
    worlds.forEach(function (w) {
      log.length = 0;
      renderer.setWorld(w);
      last = log.slice();
    });
    return last;
  } finally {
    Math.random = saved;
    remove();
  }
}

function hasRun(log, run) {
  for (let i = 0; i + run.length <= log.length; i++) {
    let ok = true;
    for (let j = 0; j < run.length; j++) { if (log[i + j] !== run[j]) { ok = false; break; } }
    if (ok) { return true; }
  }
  return false;
}

function assertNoHeatPalette(log, label) {
  HEAT_ONLY.forEach(function (c) {
    assert.ok(!log.some(function (e) { return e.indexOf(c) !== -1; }), label + ': contains ' + c);
  });
  assert.ok(!hasRun(log, ['fillStyle=#2a1a10', 'fillRect(0,216,480,20)']), label + ': heat ground');
  assert.ok(!hasRun(log, ['globalAlpha=0.16', 'fillStyle=#ffc83a']), label + ': heat sun glow');
}

function assertHeatPalette(log, label) {
  assert.ok(hasRun(log, ['fillStyle=rgb(58,14,30)', 'fillRect(0,0,480,18)']), label + ': sky top');
  assert.ok(hasRun(log, ['fillStyle=rgb(196,78,40)', 'fillRect(0,198,480,18)']), label + ': sky bottom');
  assert.ok(log.indexOf('fillStyle=#3a1622') !== -1, label + ': hills');
  assert.ok(hasRun(log, ['fillStyle=#2a1a10', 'fillRect(0,216,480,20)']), label + ': ground');
  assert.ok(hasRun(log, ['fillStyle=#3c2614', 'fillRect(0,216,1,2)']), label + ': ground texture');
  // Sun at (330, 34): topmost row of each disc is a 1 px rect at y = 34 - r.
  assert.ok(hasRun(log, ['globalAlpha=0.16', 'fillStyle=#ffc83a', 'fillStyle=#ffc83a', 'fillRect(330,2,1,1)']), label + ': outer glow');
  assert.ok(hasRun(log, ['globalAlpha=0.32', 'fillStyle=#ffd84a', 'fillStyle=#ffd84a', 'fillRect(330,13,1,1)']), label + ': inner glow');
  assert.ok(hasRun(log, ['fillStyle=#ffe46a', 'fillRect(330,21,1,1)']), label + ': core');
  // No generic sun, from any time of day.
  ['#fff2c8', '#fff6dc', '#ffd9a0'].forEach(function (c) {
    assert.ok(log.indexOf('fillStyle=' + c) === -1, label + ': generic sun ' + c);
  });
}

// 1. The recorded worlds still draw exactly as recorded, even after a heat world.
const recorded = fixtureWorlds.drawAll();
Object.keys(fixture).forEach(function (name) {
  assert.deepStrictEqual(recorded[name], fixture[name], 'recorded call log differs for ' + name);
  assertNoHeatPalette(fixture[name], 'fixture ' + name);
});

// 2. Properties over random worlds.
for (let i = 0; i < CASES; i++) {
  const r = rng.mulberry32(0x22000 + i);
  const quake = randomWorld(r, false);
  const heat = randomWorld(r, true);
  const label = 'case ' + i;

  const quakeAlone = drawSequence([quake]);
  assert.ok(quakeAlone.length > 100, label + ': earthquake draws');
  assertNoHeatPalette(quakeAlone, label + ' earthquake');

  const heatAlone = drawSequence([heat]);
  assertHeatPalette(heatAlone, label + ' heat');

  // Heat world replaced by an earthquake world: same calls as the earthquake alone.
  const after = drawSequence([heat, quake]);
  assert.deepStrictEqual(after, quakeAlone, label + ': heat then earthquake');
  // And an earthquake world before a heat world changes nothing about the heat drawing.
  assert.deepStrictEqual(drawSequence([quake, heat]), heatAlone, label + ': earthquake then heat');
}

console.log('heat-palette-property: ok (' + CASES + ' cases)');
