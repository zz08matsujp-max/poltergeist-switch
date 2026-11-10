'use strict';
// Act_Out with the real speech sequencer (public-lab Req 11.3, 11.10, 11.12,
// 11.16, 14.11): playTurn resolves only after the animation and the speech are
// both done; a turn with no Spoken_Line adds no speech wait; cancel() closes the
// bubble and resolves the turn; reduced motion jumps within 100 ms; no speech
// before the First_Gesture. Speech runs on a virtual clock; the animation uses
// the real timers inside act-out.js.
const assert = require('assert');
const { createFakeClock } = require('./helpers/fake-clock');
const { createFakeHouse } = require('./helpers/fake-house');
const { createFakeVoices } = require('./helpers/fake-voices');
let reduced = false;
global.window = { matchMedia: function () { return { matches: reduced }; } };
const { createActOut } = require('../../public/js/act-out.js');
const { createSpeechSequencer, readingTime } = require('../../public/js/speech-sequencer.js');

const talk = {
  robots: {
    robot_a: { reading: { operation: 'speak', words: ['Hello'] } },
    robot_b: { reading: { operation: 'wait', words: [] } }
  },
  residentLine: ''
};
const silent = {
  robots: {
    robot_a: { reading: { operation: 'wait', words: [] } },
    robot_b: { reading: { operation: 'wait', words: [] } }
  },
  residentLine: ''
};

function setup(voiceOptions) {
  const clock = createFakeClock();
  const house = createFakeHouse(clock);
  const worlds = [];
  const realSetWorld = house.setWorld;
  house.setWorld = function (w) { worlds.push(w); return realSetWorld(w); };
  const voices = createFakeVoices(clock, voiceOptions || { startsReading: false });
  const sequencer = createSpeechSequencer({ house, voices, timers: clock, now: clock.now });
  const actOut = createActOut(house, voices, sequencer);
  return { clock, house, voices, worlds, actOut };
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async function () {
  // Normal motion: the animation ends first (~300 ms) but the turn stays
  // pending until the line has been read for its Reading_Time.
  {
    reduced = false;
    const s = setup();
    let resolved = false;
    const p = s.actOut.playTurn(talk, { w: 1 }).then(() => { resolved = true; });
    await sleep(450);
    assert.deepStrictEqual(s.worlds, [{ w: 1 }], 'animation ended and snapped to the world');
    assert.strictEqual(resolved, false, 'speech still being read');
    assert.strictEqual(s.house.openCount(), 1);
    s.clock.advance(readingTime('Hello') - 1);
    await sleep(20);
    assert.strictEqual(resolved, false, 'one ms before the reading time');
    s.clock.advance(1);
    await p;
    assert.strictEqual(resolved, true);
    assert.strictEqual(s.house.openCount(), 0);
  }
  // Speech shorter than the animation: still waits for the animation.
  {
    reduced = false;
    const s = setup({ startsReading: true });
    s.voices.gesture();
    let resolved = false;
    const p = s.actOut.playTurn(talk, { w: 2 }).then(() => { resolved = true; });
    s.voices.finish('end'); // the engine finishes at once
    await sleep(100);
    assert.strictEqual(resolved, false, 'animation still running');
    await p;
    assert.deepStrictEqual(s.worlds, [{ w: 2 }]);
  }
  // No Spoken_Line: no speech wait, no bubble, the virtual clock never moves.
  {
    reduced = false;
    const s = setup();
    await s.actOut.playTurn(silent, { w: 3 });
    assert.strictEqual(s.clock.now(), 0, 'waited 0 ms for speech');
    assert.strictEqual(s.house.calls.length, 0, 'no bubble was shown');
    assert.deepStrictEqual(s.worlds, [{ w: 3 }]);
  }
  // cancel(): bubble closed, pending turn resolved, world applied.
  {
    reduced = false;
    const s = setup();
    const p = s.actOut.playTurn(talk, { w: 4 });
    await sleep(10);
    assert.strictEqual(s.house.openCount(), 1);
    s.actOut.cancel();
    await p;
    assert.strictEqual(s.house.openCount(), 0, 'bubble closed');
    assert.deepStrictEqual(s.worlds, [{ w: 4 }]);
  }
  // Reduced motion: the recorded world within 100 ms, with a line pending.
  {
    reduced = true;
    const s = setup();
    const t0 = Date.now();
    const p = s.actOut.playTurn(talk, { w: 5 });
    await sleep(100);
    assert.deepStrictEqual(s.worlds, [{ w: 5 }]);
    assert.ok(Date.now() - t0 < 200, 'jumped quickly');
    s.actOut.cancel();
    await p;
  }
  // No speech before the First_Gesture; allowed after it.
  {
    reduced = true;
    const s = setup({ startsReading: true, gestured: false });
    let p = s.actOut.playTurn(talk, { w: 6 });
    await sleep(100);
    assert.strictEqual(s.voices.spoken().length, 0, 'no utterance before gesture()');
    assert.strictEqual(s.house.openCount(), 1, 'the line is still shown as text');
    s.clock.advance(readingTime('Hello'));
    await p;
    s.voices.gesture();
    p = s.actOut.playTurn(talk, { w: 7 });
    await sleep(100);
    assert.strictEqual(s.voices.spoken().length, 1, 'speech allowed after gesture()');
    s.actOut.cancel();
    await p;
  }
  console.log('act-out-speech: ok');
})().catch((e) => { console.error(e); process.exit(1); });
