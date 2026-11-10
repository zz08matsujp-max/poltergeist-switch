'use strict';
// Feature: public-lab, task 20.2 (keep-alive, 150 ms gap, watchdog)
// Validates: Requirements 17.5, 17.6, 17.7, 17.8, 17.9, 17.10, 14.23
// Real public/js/voices.js, a fake speechSynthesis and a virtual clock.
const assert = require('assert');
const { createFakeClock } = require('./helpers/fake-clock');

function setup() {
  const clock = createFakeClock();
  const env = { log: [], clock: clock, docHandlers: {}, winHandlers: {} };
  function Utterance(text) { this.text = text; }
  env.synth = {
    speaking: false,
    pending: false,
    paused: false,
    getVoices: function () { return []; },
    addEventListener: function () {},
    speak: function (u) {
      if (u.text.trim() === '') { return; }
      env.log.push({ type: 'speak', t: clock.now(), text: u.text, u: u });
    },
    cancel: function () { env.log.push({ type: 'cancel', t: clock.now() }); },
    resume: function () { env.synth.paused = false; env.log.push({ type: 'resume', t: clock.now() }); }
  };
  env.doc = {
    visibilityState: 'visible',
    addEventListener: function (n, f) { env.docHandlers[n] = f; }
  };
  env.win = { addEventListener: function (n, f) { env.winHandlers[n] = f; } };
  global.window = { speechSynthesis: env.synth, SpeechSynthesisUtterance: Utterance };
  const { createVoices } = require('../../public/js/voices.js');
  env.v = createVoices({ timers: clock, now: clock.now, document: env.doc, window: env.win });
  env.v.gesture();
  env.count = function (type) { return env.log.filter(function (e) { return e.type === type; }).length; };
  env.speaks = function () { return env.log.filter(function (e) { return e.type === 'speak'; }); };
  return env;
}

// ---- a paused engine is resumed before speak ----
(function () {
  const env = setup();
  env.synth.paused = true;
  env.v.speak('amber', 'Hello.', function () {});
  const types = env.log.map(function (e) { return e.type; });
  assert.deepStrictEqual(types, ['resume', 'speak']);
})();

// ---- visibilitychange to visible and window focus call resume() ----
(function () {
  const env = setup();
  assert.strictEqual(typeof env.docHandlers.visibilitychange, 'function');
  assert.strictEqual(typeof env.winHandlers.focus, 'function');
  env.doc.visibilityState = 'hidden';
  env.docHandlers.visibilitychange();
  assert.strictEqual(env.count('resume'), 0, 'hidden does not resume');
  env.doc.visibilityState = 'visible';
  env.docHandlers.visibilitychange();
  assert.strictEqual(env.count('resume'), 1);
  env.winHandlers.focus();
  assert.strictEqual(env.count('resume'), 2);
})();

// ---- the 5000 ms check resumes a paused engine while a chunk is read ----
(function () {
  const env = setup();
  const done = [];
  const text = 'a'.repeat(30) + ' ' + 'b'.repeat(100) + ' ' + 'c'.repeat(18) + '.'; // ~150 chars, T ~ 19.6 s
  env.synth.speaking = true;
  env.v.speak('amber', text, function (w) { done.push(w); });
  assert.strictEqual(env.count('resume'), 0);
  env.synth.paused = true;
  env.clock.advance(4999);
  assert.strictEqual(env.count('resume'), 0);
  env.clock.advance(2);
  assert.strictEqual(env.count('resume'), 1);
  env.synth.paused = true;
  env.clock.advance(5000);
  assert.strictEqual(env.count('resume'), 2);
  env.synth.paused = false;
  env.clock.advance(5000);
  assert.strictEqual(env.count('resume'), 2, 'not paused: nothing to resume');
  // The chunk ends: the check stops.
  env.synth.paused = true;
  env.speaks()[0].u.onend();
  assert.deepStrictEqual(done, ['end']);
  env.clock.advance(60000);
  assert.strictEqual(env.count('resume'), 2);
  assert.strictEqual(env.clock.pending(), 0, 'no timers left');
})();

// ---- no speak within 150 ms after our own cancel() ----
(function () {
  const env = setup();
  const a = [];
  env.synth.speaking = true;
  env.v.speak('jade', 'Before.', function (w) { a.push(w); });
  env.v.cancel();
  assert.deepStrictEqual(a, ['cancelled']);
  assert.strictEqual(env.count('cancel'), 1);
  env.v.speak('jade', 'After.', function () {});
  assert.strictEqual(env.speaks().length, 1, 'held back');
  env.clock.advance(149);
  assert.strictEqual(env.speaks().length, 1);
  env.clock.advance(1);
  assert.strictEqual(env.speaks().length, 2);
  assert.strictEqual(env.speaks()[1].text, 'After.');
  assert.strictEqual(env.speaks()[1].t, 150);
})();

// ---- cancel() on an idle engine starts no gap ----
(function () {
  const env = setup();
  env.v.cancel();
  env.v.speak('jade', 'Right away.', function () {});
  assert.strictEqual(env.speaks().length, 1);
  assert.strictEqual(env.speaks()[0].t, 0);
})();

// ---- a newer cancel() abandons a deferred speak ----
(function () {
  const env = setup();
  env.synth.speaking = true;
  env.v.speak('jade', 'One.', function () {});
  env.v.cancel();
  env.v.speak('jade', 'Two.', function () {});
  env.clock.advance(50);
  env.v.cancel();
  env.clock.advance(1000);
  assert.strictEqual(env.speaks().length, 1, 'cancelled line is never spoken');
})();

const T = function (chars, rate) { return 2000 + 120 * chars / rate; };
const FIRST = 'The first chunk here.';
const SECOND = 'The second chunk here.';

// ---- speaking === false: the chunk is passed at T, no cancel, next chunk goes on ----
(function () {
  const env = setup();
  const done = [];
  env.synth.speaking = false;
  env.v.speak('amber', FIRST + ' ' + SECOND, function (w) { done.push(w); });
  assert.deepStrictEqual(env.speaks().map(function (e) { return e.text; }), [FIRST, SECOND]);
  const t1 = T(FIRST.length, 1.02);
  env.clock.advance(t1 - 1);
  assert.deepStrictEqual(done, []);
  env.clock.advance(1.5);
  assert.strictEqual(env.count('cancel'), 0, 'idle engine is not cancelled');
  assert.deepStrictEqual(done, [], 'the line continues with the next chunk');
  // The next chunk now has its own timer; its end finishes the line once.
  env.speaks()[1].u.onend();
  assert.deepStrictEqual(done, ['end']);
  // A late end from the passed chunk changes nothing.
  env.speaks()[0].u.onend();
  assert.deepStrictEqual(done, ['end']);
  assert.strictEqual(env.speaks().length, 2);
})();

// ---- speaking === false on the last chunk: the line ends at T ----
(function () {
  const env = setup();
  const done = [];
  env.v.speak('man', 'Only one.', function (w) { done.push(w); });
  env.clock.advance(T('Only one.'.length, 0.84) + 1);
  assert.deepStrictEqual(done, ['end']);
})();

// ---- speaking === true: cancelled at 2T, the rest is spoken, the line continues ----
(function () {
  const env = setup();
  const done = [];
  env.synth.speaking = true;
  env.v.speak('amber', FIRST + ' ' + SECOND, function (w) { done.push(w); });
  const t1 = T(FIRST.length, 1.02);
  env.clock.advance(t1 + 1);
  assert.strictEqual(env.count('cancel'), 0, 'not cancelled at T while still speaking');
  env.clock.advance(t1 - 3);
  assert.strictEqual(env.count('cancel'), 0, 'not cancelled before 2T');
  env.clock.advance(2);
  assert.strictEqual(env.count('cancel'), 1, 'cancelled at 2T');
  const cancelAt = env.log.filter(function (e) { return e.type === 'cancel'; })[0].t;
  assert.ok(Math.abs(cancelAt - 2 * t1) < 0.01, 'cancel time ' + cancelAt + ' vs ' + 2 * t1);
  assert.deepStrictEqual(done, [], 'reported neither error nor end');
  assert.strictEqual(env.speaks().length, 2, 'not re-spoken during the 150 ms gap');
  env.clock.advance(150);
  const speaks = env.speaks();
  assert.strictEqual(speaks.length, 3);
  assert.strictEqual(speaks[2].text, SECOND);
  assert.ok(Math.abs(speaks[2].t - (cancelAt + 150)) < 0.01);
  // Events from the dropped utterances are stale and ignored.
  speaks[0].u.onerror();
  speaks[1].u.onend();
  assert.deepStrictEqual(done, []);
  // The re-issued utterance finishes the line.
  speaks[2].u.onend();
  assert.deepStrictEqual(done, ['end']);
})();

console.log('voices-keepalive: passed');
