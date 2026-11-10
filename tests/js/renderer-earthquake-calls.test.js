'use strict';
// Protection pin (public-lab Requirements 1.5, 12.5, 14.12): the earthquake
// drawing calls of public/js/house-renderer.js for a fixed set of worlds must
// equal tests/fixtures/renderer_earthquake_calls.json, captured before any
// change. The fixture is never regenerated or edited.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const { makeCanvas, installDocument } = require('./helpers/fake-canvas');

const ROOT = path.join(__dirname, '..', '..');

const WORLDS = [
  { name: 'calm_night', world: { timeOfDay: 'night', power: 'on', robots: { robot_a: { room: 'living_room' }, robot_b: { room: 'kitchen' } }, man: { room: 'bedroom', activity: 'asleep_in_futon' } } },
  { name: 'shaking_night', world: { timeOfDay: 'night', power: 'on', shaking: true, robots: { robot_a: { room: 'living_room', spot: 'window' }, robot_b: { room: 'living_room' } }, man: { room: 'living_room', activity: 'sitting_at_table', fear: 40 } } },
  { name: 'power_out_flashlight', world: { timeOfDay: 'night', power: 'off', rooms: { living_room: { light: 'dark' }, kitchen: { light: 'dark' } }, robots: { robot_a: { room: 'living_room', held: 'flashlight' }, robot_b: { room: 'entrance' } }, man: { room: 'living_room', spot: 'under_table', covered: true, fear: 80 } } },
  { name: 'broken_and_fallen', world: { timeOfDay: 'morning', power: 'off', objects: { broken: ['dish_cupboard'], fallen: ['tv'] }, stove: { on: true }, robots: { robot_a: { room: 'kitchen', held: 'cup' }, robot_b: { room: 'kitchen' } }, man: { room: 'kitchen', activity: 'cooking', fear: 65 } } },
  { name: 'afternoon_power_out', world: { timeOfDay: 'afternoon', power: 'off', robots: { robot_a: { room: 'bathroom' }, robot_b: { room: 'storeroom' } }, man: { room: 'bathroom', activity: 'bathing' } } },
  { name: 'empty_world', world: {} },
];

function drawAll() {
  const out = {};
  const savedRandom = Math.random;
  Math.random = function () { return 0.5; };
  global.positionMap = require(path.join(ROOT, 'public', 'js', 'position-map.js'));
  const { createHouseRenderer } = require(path.join(ROOT, 'public', 'js', 'house-renderer.js'));
  WORLDS.forEach(function (w) {
    const log = [];
    const remove = installDocument(log);
    const renderer = createHouseRenderer();
    renderer.mount(makeCanvas(log));
    log.length = 0;
    renderer.setWorld(w.world);
    remove();
    out[w.name] = log;
  });
  Math.random = savedRandom;
  return out;
}

module.exports = { drawAll: drawAll };

if (require.main === module) {
  const fixture = JSON.parse(fs.readFileSync(path.join(ROOT, 'tests', 'fixtures', 'renderer_earthquake_calls.json'), 'utf8'));
  const live = drawAll();
  assert.deepStrictEqual(Object.keys(live), Object.keys(fixture), 'same set of worlds');
  Object.keys(fixture).forEach(function (name) {
    assert.ok(fixture[name].length > 100, name + ' fixture has drawing calls');
    assert.deepStrictEqual(live[name], fixture[name], 'call log differs for world ' + name);
  });
  console.log('renderer-earthquake-calls: ok (' + Object.keys(fixture).length + ' worlds)');
}
