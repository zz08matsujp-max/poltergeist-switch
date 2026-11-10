'use strict';
// Feature: public-lab, Property 20: No speech before the First_Gesture
// Validates: Requirements 11.3 (also 11.1, 11.4)
// Real public/js/voices.js against a fake speechSynthesis, seeded cases.
const assert = require('assert');
const { mulberry32, int, pick, bool } = require('./helpers/rng');

const IDS = ['amber', 'jade', 'man'];
const LINES = ['Hello', 'Are you all right?', 'Mm.', '  padded  ', '大丈夫ですか', 'Oh no'];
const CASES = 150;

function makeEnv(hasEngine) {
  const env = { spoken: [], cancels: 0, utterances: [] };
  function Utterance(text) { this.text = text; }
  delete global.window;
  global.window = {};
  if (hasEngine) {
    env.synth = {
      getVoices: function () { return []; },
      addEventListener: function () {},
      speak: function (u) { env.utterances.push(u); if (u.text.trim() !== '') { env.spoken.push(u); } },
      cancel: function () { env.cancels++; }
    };
    global.window.speechSynthesis = env.synth;
    global.window.SpeechSynthesisUtterance = Utterance;
  }
  // Fresh module state each time: voices.js reads window at createVoices time.
  return env;
}

const { createVoices } = require('../../public/js/voices.js');

for (let seed = 1; seed <= CASES; seed++) {
  const rng = mulberry32(seed);
  const hasEngine = bool(rng, 0.8);
  const env = makeEnv(hasEngine);
  const optOff = bool(rng, 0.25);
  const v = createVoices(optOff ? { enabled: false } : {});

  // Label/setting independent of the engine; default ON.
  assert.strictEqual(v.isOn(), !optOff, 'setting follows options only (seed ' + seed + ')');
  assert.strictEqual(v.available, hasEngine);

  // Before gesture: any number of speak attempts (setting on or off) reach no engine.
  const calls = [];
  const attempts = int(rng, 1, 8);
  for (let i = 0; i < attempts; i++) {
    if (bool(rng, 0.3)) { v.setOn(bool(rng, 0.7)); }
    const r = v.speak(pick(rng, IDS), pick(rng, LINES), function (why) { calls.push(why); });
    assert.strictEqual(r, false, 'speak is false before gesture (seed ' + seed + ')');
  }
  assert.strictEqual(env.utterances.length, 0, 'no utterance before gesture (seed ' + seed + ')');
  assert.strictEqual(calls.length, 0, 'onDone not called when speak returns false');

  // Setting is independent of engine and gesture.
  v.setOn(true);
  assert.strictEqual(v.isOn(), true);
  v.setOn(false);
  assert.strictEqual(v.isOn(), false);
  assert.strictEqual(v.speak('amber', 'Hello', function () { calls.push('x'); }), false, 'off => false');
  v.setOn(true);

  v.gesture();
  v.gesture(); // idempotent
  const primed = env.utterances.length;
  assert.ok(primed <= 1, 'priming is at most one silent utterance');

  if (!hasEngine) {
    assert.strictEqual(v.speak('amber', 'Hello', function () { calls.push('x'); }), false, 'no engine => false');
    assert.strictEqual(v.isOn(), true, 'label unaffected by missing engine');
    assert.strictEqual(calls.length, 0);
    continue;
  }

  // Setting off after gesture still blocks speech.
  v.setOn(false);
  assert.strictEqual(v.speak('jade', 'Hello', function () { calls.push('x'); }), false);
  assert.strictEqual(env.utterances.length, primed);
  v.setOn(true);

  // After gesture speech is allowed; onDone fires exactly once per accepted line.
  const n = int(rng, 1, 5);
  const done = [];
  for (let i = 0; i < n; i++) {
    const idx = i;
    done[idx] = [];
    const ok = v.speak(pick(rng, IDS), pick(rng, LINES), function (why) { done[idx].push(why); });
    assert.strictEqual(ok, true, 'speech allowed after gesture (seed ' + seed + ')');
  }
  assert.strictEqual(env.spoken.length, n);
  // Blank / unknown id rejected without touching the engine.
  assert.strictEqual(v.speak('amber', '   ', function () {}), false);
  assert.strictEqual(v.speak('nobody', 'Hi', function () {}), false);
  assert.strictEqual(env.spoken.length, n);

  // Finish each utterance some way; extra events must not repeat onDone.
  const spoken = env.spoken.slice();
  spoken.forEach(function (u, i) {
    const how = int(rng, 0, 2);
    if (how === 0) { u.onend(); } else if (how === 1) { u.onerror(); } else { /* left for cancel */ }
    if (bool(rng, 0.5)) { u.onend(); u.onerror(); }
  });
  v.cancel();
  v.cancel();
  done.forEach(function (d, i) {
    assert.strictEqual(d.length, 1, 'onDone exactly once (seed ' + seed + ', line ' + i + '): ' + d.join(','));
    assert.ok(['end', 'error', 'cancelled'].indexOf(d[0]) >= 0);
  });
}

console.log('voices-gesture: ' + CASES + ' cases passed');
