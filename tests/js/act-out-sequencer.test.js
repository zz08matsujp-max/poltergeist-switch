'use strict';
// Act_Out hands speech to the sequencer (public-lab Req 11.5, 11.10, 11.11,
// 11.12, 11.16): no bubbles from Act_Out itself, the world snaps when the
// animation ends, playTurn resolves after both are done, cancel() cancels both.
const assert = require('assert');
const { createFakeClock } = require('./helpers/fake-clock');
const { createFakeHouse } = require('./helpers/fake-house');
const { createFakeVoices } = require('./helpers/fake-voices');
global.window = { matchMedia: function () { return { matches: true }; } }; // reduced motion
const { createActOut } = require('../../public/js/act-out.js');
const { createSpeechSequencer } = require('../../public/js/speech-sequencer.js');

const turn = {
  robots: {
    robot_a: { reading: { operation: 'speak', words: ['Hello'] } },
    robot_b: { reading: { operation: 'wait', words: [] } }
  },
  residentLine: 'Mm.'
};

function setup() {
  const clock = createFakeClock();
  const house = createFakeHouse(clock);
  const worlds = [];
  const realSetWorld = house.setWorld;
  house.setWorld = function (w) { worlds.push(w); return realSetWorld(w); };
  const voices = createFakeVoices(clock, { startsReading: false }); // timer mode
  const sequencer = createSpeechSequencer({ house, voices, timers: clock, now: clock.now });
  const actOut = createActOut(house, voices, sequencer);
  return { clock, house, worlds, actOut, sequencer };
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async function () {
  // Reduced motion: world within 100 ms; resolves only after the speech ends.
  {
    const s = setup();
    let resolved = false;
    const t0 = Date.now();
    const p = s.actOut.playTurn(turn, { w: 1 }).then(() => { resolved = true; });
    await sleep(100);
    assert.ok(s.worlds.length === 1 && Date.now() - t0 < 200, 'world applied quickly');
    assert.deepStrictEqual(s.house.calls.map((c) => c.id + ':' + c.text), ['amber:Hello']);
    assert.strictEqual(resolved, false, 'not resolved while a line is being read');
    s.clock.advance(60000);
    await p;
    assert.strictEqual(resolved, true);
    assert.deepStrictEqual(s.house.calls.map((c) => c.id + ':' + c.text),
      ['amber:Hello', 'amber:', 'man:Mm.', 'man:']);
  }
  // cancel(): sequencer cancelled, bubbles closed, promise resolves, world applied.
  {
    const s = setup();
    const p = s.actOut.playTurn(turn, { w: 2 });
    s.actOut.cancel();
    await p;
    assert.strictEqual(s.house.openCount(), 0);
    assert.deepStrictEqual(s.worlds, [{ w: 2 }]);
  }
  console.log('act-out-sequencer: ok');
})().catch((e) => { console.error(e); process.exit(1); });
