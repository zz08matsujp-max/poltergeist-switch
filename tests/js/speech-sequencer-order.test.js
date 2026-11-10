'use strict';
// Feature: public-lab, Property 18: The sequencer shows one line at a time, in order, for the right time
// Validates: Requirements 11.5, 11.6, 11.7, 11.8, 11.9, 11.10, 11.12 (and 11.11 for the caption strip).
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const { mulberry32, int, pick, bool } = require('./helpers/rng');
const { createFakeClock } = require('./helpers/fake-clock');
const { createFakeHouse } = require('./helpers/fake-house');
const { createFakeVoices } = require('./helpers/fake-voices');
global.window = { matchMedia: function () { return { matches: true }; } }; // reduced motion for playTurn
const { createSpeechSequencer } = require('../../public/js/speech-sequencer.js');
const { createActOut } = require('../../public/js/act-out.js');

const CASES = 150;
const IDS = ['amber', 'jade', 'man'];
const ALPHABET = ['a', 'b', 'c', ' ', '.', 'あ', 'い', '家', '😀', 'é'];

// Independent statement of Req 11.7 (code points, not UTF-16 units).
function expectedCap(text) { return 4000 + 100 * Array.from(text).length; }
function expectedReading(text) { return Math.min(12000, 2000 + 60 * Array.from(text).length); }
const flush = async () => { for (let i = 0; i < 5; i++) { await Promise.resolve(); } };

function genText(rng) {
  const n = bool(rng, 0.2) ? int(rng, 150, 260) : int(rng, 1, 60);
  let s = 'x'; // never blank
  for (let i = 1; i < n; i++) { s += pick(rng, ALPHABET); }
  return s;
}

// A recording house that checks "at most one bubble open" on every call and
// remembers every method the sequencer touched.
function recordingHouse(clock) {
  const inner = createFakeHouse(clock);
  const events = [];   // { at, id, text } for every showBubble
  const touched = new Set();
  const open = new Set();
  let maxOpen = 0;
  const house = new Proxy(inner, {
    get(target, prop) {
      if (prop === 'showBubble') {
        return function (id, text, seconds) {
          touched.add('showBubble');
          events.push({ at: clock.now(), id: id, text: text, seconds: seconds });
          if (text) { open.add(id); } else { open.delete(id); }
          maxOpen = Math.max(maxOpen, open.size);
          return target.showBubble(id, text, seconds);
        };
      }
      const v = target[prop];
      if (typeof v === 'function') {
        return function () { touched.add(String(prop)); return v.apply(target, arguments); };
      }
      return v;
    }
  });
  return { house: house, events: events, touched: touched, maxOpen: function () { return maxOpen; }, inner: inner };
}

// Plan each line: 'timer' (speak refuses), 'voice' (ends after d ms) or 'cap' (never ends).
function planLines(rng) {
  const n = int(rng, 0, 5);
  const lines = [];
  for (let i = 0; i < n; i++) {
    const kind = pick(rng, ['timer', 'timer', 'voice', 'voice', 'cap']);
    const text = genText(rng);
    lines.push({
      id: pick(rng, IDS), text: text, kind: kind,
      // The voice reports before the safety cap (Req 11.9).
      d: int(rng, 0, expectedCap(text) - 1), reason: bool(rng) ? 'end' : 'error'
    });
  }
  return lines;
}

function buildVoices(clock, lines) {
  const fake = createFakeVoices(clock);
  const queue = lines.slice();
  const wrapper = Object.assign({}, fake, {
    speak: function (id, text, onDone) {
      const plan = queue.shift();
      if (!plan || plan.kind === 'timer') { return false; }
      const ok = fake.speak(id, text, onDone);
      if (ok && plan.kind === 'voice') {
        clock.setTimeout(function () { fake.finish(plan.reason); }, plan.d);
      }
      return ok;
    }
  });
  return wrapper;
}

function expectedDuration(l) {
  if (l.kind === 'timer') { return expectedReading(l.text); }
  if (l.kind === 'voice') {
    // Req 11.7: the later of the voice's end and the Reading_Time; an error keeps the Reading_Time.
    return l.reason === 'end' ? Math.max(l.d, expectedReading(l.text)) : expectedReading(l.text);
  }
  return expectedCap(l.text); // Req 11.9: neither end nor error
}

(async function () {
  // ── Property over the sequencer's play promise ───────────────────────────
  for (let c = 0; c < CASES; c++) {
    const rng = mulberry32(0x18000 + c);
    const lines = planLines(rng);
    const clock = createFakeClock(int(rng, 0, 5000));
    const rec = recordingHouse(clock);
    const voices = buildVoices(clock, lines);
    const seq = createSpeechSequencer({ house: rec.house, voices: voices, timers: clock, now: clock.now });
    const t0 = clock.now();

    let resolved = false;
    const p = seq.play(lines.map((l) => ({ id: l.id, text: l.text }))).then(() => { resolved = true; });

    if (lines.length === 0) {
      // Empty list adds 0 ms: resolves with no clock movement, no bubbles, no timers.
      await p;
      assert.strictEqual(resolved, true, 'case ' + c + ': empty list resolves at once');
      assert.strictEqual(clock.now(), t0);
      assert.strictEqual(rec.events.length, 0);
      assert.strictEqual(clock.pending(), 0);
      continue;
    }

    const total = lines.reduce((s, l) => s + expectedDuration(l), 0);
    if (total > 0) {
      clock.advance(total - 1);
      await flush();
      assert.strictEqual(resolved, false, 'case ' + c + ': not resolved before the last bubble closes');
      clock.advance(1);
    }
    await p;
    assert.strictEqual(resolved, true, 'case ' + c);

    // At most one bubble open at any instant (checked on every call).
    assert.ok(rec.maxOpen() <= 1, 'case ' + c + ': more than one bubble open');
    assert.strictEqual(rec.inner.openCount(), 0, 'case ' + c + ': nothing left open');

    // Lines in order, each opened then closed, close exactly at the expected time.
    assert.strictEqual(rec.events.length, lines.length * 2, 'case ' + c + ': one open and one close per line');
    let start = t0;
    for (let i = 0; i < lines.length; i++) {
      const open = rec.events[2 * i];
      const close = rec.events[2 * i + 1];
      const l = lines[i];
      assert.strictEqual(open.id, l.id, 'case ' + c + ' line ' + i + ': id/order');
      assert.strictEqual(open.text, l.text, 'case ' + c + ' line ' + i + ': text/order');
      assert.ok(open.at - start >= 0 && open.at - start <= 100, 'case ' + c + ' line ' + i + ': opens within 100 ms of the previous close');
      assert.strictEqual(open.at, start, 'case ' + c + ' line ' + i + ': opens at once');
      assert.strictEqual(close.id, l.id);
      assert.strictEqual(close.text, '', 'case ' + c + ': a close is showBubble(id, "")');
      assert.strictEqual(close.at - open.at, expectedDuration(l),
        'case ' + c + ' line ' + i + ' (' + l.kind + '): open for ' + expectedDuration(l) + ' ms');
      if (l.kind === 'timer') {
        assert.strictEqual(open.seconds * 1000, expectedReading(l.text), 'timer bubble lifetime = readingTime');
      }
      start = close.at;
    }
    // Voices were only cancelled for the safety-cap lines.
    const caps = lines.filter((l) => l.kind === 'cap').length;
    assert.strictEqual(voices.calls.filter((x) => x.type === 'cancel').length, caps, 'case ' + c + ': cancel only at the cap');

    // Req 11.11: the sequencer only talks to the house through showBubble, the
    // same call that feeds the caption strip.
    assert.deepStrictEqual(Array.from(rec.touched), ['showBubble']);
  }

  // ── Caption strip is fed by the same showBubble (Req 11.11) ──────────────
  {
    const src = fs.readFileSync(path.join(__dirname, '../../public/js/house-renderer.js'), 'utf8');
    const i = src.indexOf('showBubble: function');
    assert.ok(i > 0, 'house-renderer exposes showBubble');
    const body = src.slice(i, i + 2500);
    assert.ok(/renderCaption\(\)/.test(body), 'renderer showBubble feeds the caption strip');
    const seqSrc = fs.readFileSync(path.join(__dirname, '../../public/js/speech-sequencer.js'), 'utf8');
    assert.ok(!/renderCaption|caption/i.test(seqSrc.replace(/\/\*[\s\S]*?\*\//, '')), 'sequencer has no separate caption path');
  }

  // ── playTurn through act-out (reduced motion = fast, real 60 ms animation) ─
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  for (let c = 0; c < 8; c++) {
    const rng = mulberry32(0x18a00 + c);
    const wa = Array.from({ length: int(rng, 0, 2) }, () => genText(rng));
    const wb = Array.from({ length: int(rng, 0, 2) }, () => genText(rng));
    const man = bool(rng) ? genText(rng) : '';
    const turn = {
      robots: {
        robot_a: { reading: { operation: 'speak', words: wa } },
        robot_b: { reading: { operation: 'speak', words: wb } }
      },
      residentLine: man
    };
    const expected = wa.concat(wb, man ? [man] : []);

    const clock = createFakeClock();
    const rec = recordingHouse(clock);
    const log = [];
    const realSetWorld = rec.inner.setWorld;
    rec.inner.setWorld = function (w) { log.push('world@' + clock.now()); return realSetWorld(w); };
    const voices = createFakeVoices(clock, { startsReading: false }); // timer mode
    const seq = createSpeechSequencer({ house: rec.house, voices: voices, timers: clock, now: clock.now });
    const actOut = createActOut(rec.house, voices, seq);

    let resolved = false;
    const p = actOut.playTurn(turn, { w: c }).then(() => { resolved = true; });
    await sleep(150); // the animation (60 ms) has ended
    assert.strictEqual(log.length, 1, 'case ' + c + ': animation ended and snapped to the world');
    if (expected.length === 0) {
      assert.strictEqual(resolved, true, 'case ' + c + ': no lines, resolves as soon as the animation ends');
    } else {
      assert.strictEqual(resolved, false, 'case ' + c + ': waits for the last bubble');
      const total = expected.reduce((s, t) => s + expectedReading(t), 0);
      clock.advance(total - 1);
      await sleep(5);
      assert.strictEqual(resolved, false, 'case ' + c + ': still open 1 ms before the last close');
      clock.advance(1);
    }
    await p;
    assert.strictEqual(resolved, true);
    assert.ok(rec.maxOpen() <= 1);
    assert.strictEqual(rec.inner.openCount(), 0);
    const opened = rec.events.filter((e) => e.text).map((e) => e.text);
    assert.deepStrictEqual(opened, expected, 'case ' + c + ': a1, a2, b1, b2, man order');
  }

  console.log('speech-sequencer-order: ok (' + CASES + ' sequencer cases, 8 playTurn cases)');
})().catch((e) => { console.error(e); process.exit(1); });
