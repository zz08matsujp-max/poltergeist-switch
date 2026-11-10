'use strict';
// Feature: public-lab, Property 19: Toggling Voices mid-line
// Validates: Requirements 11.14, 11.15
//
// Voices off at time t stops the voice within 200 ms and the bubble still
// closes at startedAt + readingTime (or at t if that has already passed).
// Voices on at time t starts no reading for the current line and the bubble
// closes at startedAt + readingTime; the next line may use voice.
// Seeded, virtual time, at least 100 cases per direction.

const assert = require('assert');
const path = require('path');
const { mulberry32, int, pick, bool } = require('./helpers/rng');
const { createFakeClock } = require('./helpers/fake-clock');
const { createFakeVoices } = require('./helpers/fake-voices');
const { createFakeHouse } = require('./helpers/fake-house');
const { createSpeechSequencer, readingTime, bindVoicesButton } =
  require(path.join(__dirname, '..', '..', 'public', 'js', 'speech-sequencer.js'));

const CASES = 150;
const GLYPHS = ['a', 'b', 'k', ' ', 'é', 'あ', 'ん', '😀', '.', '!'];

function randomText(rng) {
  const n = int(rng, 1, 260);
  let s = '';
  for (let i = 0; i < n; i++) { s += pick(rng, GLYPHS); }
  return s.trim() === '' ? 'x' + s : s;
}

function randomLines(rng) {
  const ids = ['amber', 'jade', 'man'];
  const n = int(rng, 1, 3);
  const lines = [];
  for (let i = 0; i < n; i++) { lines.push({ id: ids[i], text: randomText(rng) }); }
  return lines;
}

function setup(voicesOn) {
  const clock = createFakeClock();
  const house = createFakeHouse(clock);
  const voices = createFakeVoices(clock, { on: voicesOn, gestured: true });
  const sequencer = createSpeechSequencer({ house: house, voices: voices, timers: clock, now: clock.now });
  return { clock, house, voices, sequencer };
}

// Same order as bindVoicesButton's click: setOn first, then voicesChanged.
function toggle(env, on) {
  env.voices.setOn(on);
  env.sequencer.voicesChanged(on);
}

function closeTimes(house, id) {
  return house.calls.filter((c) => c.id === id && !c.text).map((c) => c.at);
}

function run(name, fn) {
  for (let seed = 1; seed <= CASES; seed++) {
    try { fn(mulberry32(seed * 7919 + (name === 'off' ? 1 : 2)), seed); }
    catch (e) { e.message = name + ' seed ' + seed + ': ' + e.message; throw e; }
  }
}

// Voices off mid-line (voice mode -> timer mode).
run('off', function (rng) {
  const env = setup(true);
  const lines = randomLines(rng);
  const first = lines[0];
  const rt = readingTime(first.text);
  env.sequencer.play(lines);
  assert.strictEqual(env.voices.reading().length, 1, 'first line is read by voice');

  // The voice never reports the end, so the line is open until the 12 s cap.
  const t = int(rng, 0, 11999);
  env.clock.advance(t);
  assert.strictEqual(closeTimes(env.house, first.id).length, 0, 'still open mid-line');

  toggle(env, false);
  // Voice stopped within 200 ms (immediately in the fake).
  assert.strictEqual(env.voices.reading().length, 0, 'voice stopped');
  const stop = env.voices.calls.filter((c) => c.type === 'done' && c.reason === 'cancelled')[0];
  assert.ok(stop && stop.at - t <= 200, 'stopped within 200 ms');

  // Bubble open until startedAt (0) + readingTime, or closed at once if that
  // moment has already passed.
  const expected = Math.max(t, rt);
  if (expected > t) {
    env.clock.advance(expected - t - 1);
    assert.strictEqual(closeTimes(env.house, first.id).length, 0, 'bubble open just before rt');
    env.clock.advance(1);
  }
  const closes = closeTimes(env.house, first.id);
  assert.strictEqual(closes.length, 1, 'bubble closed once');
  assert.strictEqual(closes[0], expected, 'bubble closes at startedAt + readingTime');
  assert.ok(env.house.openCount() <= 1, 'one bubble at most');
  // Voice is off: the following lines use timers, no voice starts.
  env.clock.advance(60000);
  assert.strictEqual(env.voices.spoken().length, 1, 'no later line used voice');
});

// Voices on mid-line (timer mode stays timer mode; next line may use voice).
run('on', function (rng) {
  const env = setup(false);
  const lines = randomLines(rng);
  const first = lines[0];
  const rt = readingTime(first.text);
  env.sequencer.play(lines);
  assert.strictEqual(env.voices.spoken().length, 0, 'no voice while off');

  const t = int(rng, 0, rt - 1);
  env.clock.advance(t);
  toggle(env, true);
  if (bool(rng, 0.3)) { env.voices.gesture(); }

  assert.strictEqual(env.voices.reading().length, 0, 'current line is not read by voice');
  assert.strictEqual(env.voices.spoken().length, 0, 'no speak call for the current line');
  if (rt - t > 1) {
    env.clock.advance(rt - t - 1);
    assert.strictEqual(closeTimes(env.house, first.id).length, 0, 'bubble open just before rt');
  }
  env.clock.advance(1);
  const closes = closeTimes(env.house, first.id);
  assert.strictEqual(closes.length, 1, 'bubble closed once');
  assert.strictEqual(closes[0], rt, 'bubble closes at startedAt + readingTime');
  assert.strictEqual(env.voices.spoken().length, lines.length > 1 ? 1 : 0,
    'the next line may use voice');
  if (lines.length > 1) {
    assert.strictEqual(env.voices.spoken()[0].id, lines[1].id, 'voice used for the next line');
    assert.strictEqual(env.voices.spoken()[0].at, rt, 'next line starts as the first closes');
  }
});

// The real button wiring does the same thing.
(function () {
  const env = setup(true);
  const attrs = {};
  let handler = null;
  const button = {
    textContent: '',
    setAttribute: (k, v) => { attrs[k] = v; },
    addEventListener: (ev, fn) => { if (ev === 'click') { handler = fn; } }
  };
  bindVoicesButton(button, env.voices, env.sequencer);
  const text = 'Hello there, friend.';
  env.sequencer.play([{ id: 'amber', text: text }]);
  env.clock.advance(100);
  handler();
  assert.strictEqual(button.textContent, 'Voices: off');
  assert.strictEqual(env.voices.reading().length, 0);
  env.clock.advance(readingTime(text) - 101);
  assert.strictEqual(closeTimes(env.house, 'amber').length, 0);
  env.clock.advance(1);
  assert.deepStrictEqual(closeTimes(env.house, 'amber'), [readingTime(text)]);
})();

console.log('speech-voices-toggle: ok (' + CASES + ' cases x 2)');
