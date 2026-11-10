'use strict';
// Task 10.11: speech timing fix (Requirements 11.7, 11.9, 11.14, 14.11).
// Fake clock, fake voices, fake house. Voice mode closes at the later of the
// voice's end and the Reading_Time; an error keeps the Reading_Time; a voice
// that reports neither is cancelled at 4000 ms + 100 ms per code point.
const assert = require('assert');
const path = require('path');
const { createFakeClock } = require('./helpers/fake-clock');
const { createFakeVoices } = require('./helpers/fake-voices');
const { createFakeHouse } = require('./helpers/fake-house');
const { createSpeechSequencer, readingTime } =
  require(path.join(__dirname, '..', '..', 'public', 'js', 'speech-sequencer.js'));

function setup(voiceOptions) {
  const clock = createFakeClock();
  const house = createFakeHouse(clock);
  const voices = createFakeVoices(clock, voiceOptions || {});
  const sequencer = createSpeechSequencer({ house: house, voices: voices, timers: clock, now: clock.now });
  return { clock, house, voices, sequencer };
}
function closes(env, id) { return env.house.calls.filter((c) => c.id === id && !c.text).map((c) => c.at); }
function opens(env, id) { return env.house.calls.filter((c) => c.id === id && c.text).map((c) => c.at); }

// 1. A voice that errors at once keeps the bubble for the Reading_Time; the next line starts after it.
{
  const env = setup();
  const a = 'Hello there, are you all right?';
  const rt = readingTime(a);
  env.sequencer.play([{ id: 'amber', text: a }, { id: 'jade', text: 'Yes.' }]);
  env.voices.finish('error'); // fails at time 0
  env.clock.advance(rt - 1);
  assert.deepStrictEqual(closes(env, 'amber'), [], 'bubble still open just before the Reading_Time');
  assert.deepStrictEqual(opens(env, 'jade'), [], 'next line not started yet');
  env.clock.advance(1);
  assert.deepStrictEqual(closes(env, 'amber'), [rt]);
  assert.deepStrictEqual(opens(env, 'jade'), [rt], 'next line starts when the first closes');
}

// 2. A voice that ends at once keeps the bubble for the Reading_Time.
{
  const env = setup();
  const a = 'Careful with the stove.';
  const rt = readingTime(a);
  env.sequencer.play([{ id: 'amber', text: a }]);
  env.voices.finish('end');
  env.clock.advance(rt - 1);
  assert.deepStrictEqual(closes(env, 'amber'), []);
  env.clock.advance(1);
  assert.deepStrictEqual(closes(env, 'amber'), [rt]);
}

// 3. A voice that ends after 20 s on a 300-character line keeps the bubble 20 s and is not cancelled.
{
  const env = setup();
  const a = 'x'.repeat(300);
  env.sequencer.play([{ id: 'jade', text: a }]);
  env.clock.advance(20000);
  assert.deepStrictEqual(closes(env, 'jade'), [], 'not closed at the Reading_Time (12 s) or before');
  env.voices.finish('end');
  assert.deepStrictEqual(closes(env, 'jade'), [20000]);
  assert.strictEqual(env.voices.calls.filter((c) => c.type === 'cancel').length, 0, 'voice not cancelled');
}

// 4. A voice that never ends on a 300-character line closes at 4000 + 100 x 300 ms and is cancelled.
{
  const env = setup({ neverFinish: true });
  const a = 'x'.repeat(300);
  const cap = 4000 + 100 * 300;
  env.sequencer.play([{ id: 'jade', text: a }, { id: 'man', text: 'Oh.' }]);
  env.clock.advance(cap - 1);
  assert.deepStrictEqual(closes(env, 'jade'), []);
  assert.strictEqual(env.voices.calls.filter((c) => c.type === 'cancel').length, 0);
  env.clock.advance(1);
  assert.deepStrictEqual(closes(env, 'jade'), [cap]);
  assert.strictEqual(env.voices.calls.filter((c) => c.type === 'cancel').length, 1, 'voice cancelled');
  assert.deepStrictEqual(opens(env, 'man'), [cap], 'next line starts at the close');
}

console.log('speech-timing-fix.test.js ok');
