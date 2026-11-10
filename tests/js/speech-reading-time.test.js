'use strict';
// Feature: public-lab, Property 16: Reading time
// Validates: Requirements 11.8
const assert = require('assert');
const { mulberry32, int, pick } = require('./helpers/rng');
const { readingTime } = require('../../public/js/speech-sequencer.js');

const PIECES = ['a', 'Z', ' ', '.', 'こんにちは', '地震だ', '😀', '🏠', '𠮷', '👨‍👩‍👧', 'é', '!'];

function makeText(rng, maxPieces) {
  let s = '';
  const n = int(rng, 0, maxPieces);
  for (let i = 0; i < n; i++) { s += pick(rng, PIECES); }
  return s;
}

const CASES = 300;
for (let c = 0; c < CASES; c++) {
  const rng = mulberry32(1600 + c);
  // Mix short texts and texts long enough to hit the cap (about 167+ code points).
  const text = makeText(rng, c % 3 === 0 ? 400 : 60);
  const cp = Array.from(text).length;
  const t = readingTime(text);

  assert.strictEqual(t, Math.min(12000, 2000 + 60 * cp), 'formula, case ' + c);
  assert.ok(t >= 2000, 'at least 2000, case ' + c);
  assert.ok(t <= 12000, 'at most 12000, case ' + c);

  // Non-decreasing as the text grows.
  const longer = text + makeText(rng, 20);
  assert.ok(readingTime(longer) >= t, 'non-decreasing, case ' + c);
  const chars = Array.from(longer);
  let prev = readingTime('');
  for (let k = 1; k <= chars.length; k += 7) {
    const cur = readingTime(chars.slice(0, k).join(''));
    assert.ok(cur >= prev, 'prefix monotonic, case ' + c);
    prev = cur;
  }
}

// Code points differ from UTF-16 length.
assert.strictEqual(readingTime('😀😀'), 2000 + 60 * 2);
assert.strictEqual(readingTime(''), 2000);
assert.strictEqual(readingTime('x'.repeat(1000)), 12000);

console.log('speech-reading-time: ' + CASES + ' cases passed');
