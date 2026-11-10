'use strict';
// Feature: public-lab, task 20.2 (chunked speech)
// Validates: Requirements 17.1, 17.2, 17.3, 17.4
// Real public/js/voices.js against a fake speechSynthesis; no real timers.
const assert = require('assert');
const { createFakeClock } = require('./helpers/fake-clock');

function setup() {
  const clock = createFakeClock();
  const env = { spoken: [], cancels: 0 };
  function Utterance(text) { this.text = text; }
  env.synth = {
    speaking: false,
    pending: false,
    paused: false,
    getVoices: function () {
      return [
        { name: 'Samantha', lang: 'en-US' },
        { name: 'Daniel', lang: 'en-GB' },
        { name: 'Ralph', lang: 'en-US' }
      ];
    },
    addEventListener: function () {},
    speak: function (u) { if (u.text.trim() !== '') { env.spoken.push(u); } },
    cancel: function () { env.cancels++; },
    resume: function () {}
  };
  global.window = { speechSynthesis: env.synth, SpeechSynthesisUtterance: Utterance };
  const { createVoices } = require('../../public/js/voices.js');
  env.createVoices = createVoices;
  env.v = createVoices({
    timers: clock,
    now: clock.now,
    document: { addEventListener: function () {}, visibilityState: 'visible' },
    window: { addEventListener: function () {} }
  });
  env.v.gesture();
  env.clock = clock;
  return env;
}

const { splitForSpeech } = require('../../public/js/voices.js');

// ---- splitting examples (17.1-17.3) ----
assert.deepStrictEqual(splitForSpeech('Hello there. Are you OK? Yes!'), ['Hello there.', 'Are you OK?', 'Yes!']);
assert.deepStrictEqual(splitForSpeech('Wait\u2026 what is that'), ['Wait\u2026', 'what is that']);
assert.deepStrictEqual(splitForSpeech('It is 3.5 meters long'), ['It is 3.5 meters long']);
assert.deepStrictEqual(splitForSpeech('  no end mark  '), ['no end mark']);
assert.deepStrictEqual(splitForSpeech('   '), []);
assert.deepStrictEqual(splitForSpeech(42), []);

// A 300-character sentence is cut after ", " (packed greedily, none over 180).
const seg = 'word '.repeat(20).trim(); // 99 chars
const commaText = seg + ', ' + seg + ', ' + seg + '.';
assert.ok(commaText.length > 290 && commaText.length < 320);
const commaChunks = splitForSpeech(commaText);
assert.deepStrictEqual(commaChunks, [seg + ',', seg + ',', seg + '.']);
const semiChunks = splitForSpeech(seg + '; ' + seg + '; ' + seg + '.');
assert.deepStrictEqual(semiChunks, [seg + ';', seg + ';', seg + '.']);

// No separator: cut at the last space before 180.
const noSep = 'abcdefghi '.repeat(40).trim(); // 399 chars, spaces at 9, 19, ...
const spaceChunks = splitForSpeech(noSep);
assert.strictEqual(spaceChunks[0].length, 179);
spaceChunks.forEach(function (c) { assert.ok(c.length <= 180); });
assert.strictEqual(spaceChunks.join(' '), noSep);
// No space at all: hard cut at 180.
assert.deepStrictEqual(splitForSpeech('x'.repeat(400)).map(function (c) { return c.length; }), [180, 180, 40]);

// ---- chunks are spoken in order with the line's voice, pitch and rate ----
(function () {
  const env = setup();
  const done = [];
  assert.strictEqual(env.v.speak('amber', 'First part. Second part! Third part?', function (w) { done.push(w); }), true);
  assert.deepStrictEqual(env.spoken.map(function (u) { return u.text; }), ['First part.', 'Second part!', 'Third part?']);
  env.spoken.forEach(function (u) {
    assert.strictEqual(u.voice.name, 'Samantha');
    assert.strictEqual(u.lang, 'en-US');
    assert.strictEqual(u.pitch, 1.15);
    assert.strictEqual(u.rate, 1.02);
  });
  // Ending chunks one by one: 'end' only after the last, exactly once.
  env.spoken[0].onend();
  env.spoken[1].onend();
  assert.deepStrictEqual(done, []);
  env.spoken[2].onend();
  env.spoken[2].onend();
  env.spoken[2].onerror();
  assert.deepStrictEqual(done, ['end']);
})();

// ---- the man's profile and a Japanese line ----
(function () {
  const env = setup();
  env.v.speak('man', 'Slow down.', function () {});
  assert.strictEqual(env.spoken[0].pitch, 0.70);
  assert.strictEqual(env.spoken[0].rate, 0.84);
  assert.strictEqual(env.spoken[0].voice.name, 'Ralph');
  env.v.speak('jade', '\u5927\u4e08\u592b\u3067\u3059\u304b\u3002', function () {});
  assert.strictEqual(env.spoken[1].lang, 'ja-JP');
})();

// ---- an error in a chunk reports 'error' for the line ----
(function () {
  const env = setup();
  const done = [];
  env.v.speak('jade', 'One. Two. Three.', function (w) { done.push(w); });
  env.spoken[0].onend();
  env.spoken[1].onerror();
  assert.deepStrictEqual(done, ['error']);
  env.spoken[2].onend();
  assert.deepStrictEqual(done, ['error'], 'later chunk events do not report again');
})();

// ---- cancel() reports 'cancelled' once for every active or queued line ----
(function () {
  const env = setup();
  const a = [], b = [];
  env.v.speak('amber', 'Alpha one. Alpha two.', function (w) { a.push(w); });
  env.v.speak('man', 'Beta.', function (w) { b.push(w); });
  env.spoken[0].onend();
  env.v.cancel();
  assert.deepStrictEqual(a, ['cancelled']);
  assert.deepStrictEqual(b, ['cancelled']);
  env.spoken.forEach(function (u) { if (u.onend) { u.onend(); } if (u.onerror) { u.onerror(); } });
  assert.deepStrictEqual(a, ['cancelled']);
  assert.deepStrictEqual(b, ['cancelled']);
})();

console.log('voices-chunks: passed');
