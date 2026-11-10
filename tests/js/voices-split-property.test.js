'use strict';
// Feature: public-lab, Property 23: Speech splitting preserves the line and respects the limits
// Validates: Requirements 17.1, 17.2, 17.3
//
// Seeded property test over random texts: sentence ends, ellipses, commas,
// semicolons, long words and Japanese text.
const assert = require('assert');
const rng = require('./helpers/rng');
const { splitForSpeech } = require('../../public/js/voices.js');

const CASES = 300;
const LIMIT = 180;
const WORDS = ['the', 'dishes', 'fell', 'and', 'broke', 'I', 'am', 'here', 'please', 'wait', 'okay', 'house', 'shaking', 'Grandpa', 'is', '3.5', 'm', 'away'];
const JA = ['地震です', '大丈夫ですか', '落ち着いてください', 'ここにいます', '動かないで'];
const ENDS = ['.', '!', '?', '\u2026'];

function word(r) {
  const k = rng.int(r, 0, 20);
  if (k === 0) { return 'x'.repeat(rng.int(r, 181, 400)); }
  if (k <= 3) { return rng.pick(r, JA); }
  return rng.pick(r, WORDS);
}

function randomText(r) {
  const sentences = rng.int(r, 0, 5);
  let text = rng.bool(r, 0.2) ? '  ' : '';
  for (let s = 0; s < sentences; s++) {
    const n = rng.int(r, 1, rng.bool(r, 0.3) ? 60 : 12);
    const parts = [];
    for (let i = 0; i < n; i++) {
      let w = word(r);
      if (i < n - 1 && rng.bool(r, 0.1)) { w += rng.pick(r, [',', ';']); }
      parts.push(w);
    }
    text += parts.join(' ');
    if (rng.bool(r, 0.85)) { text += rng.pick(r, ENDS); }
    text += rng.bool(r, 0.9) ? ' ' : '  ';
  }
  return text;
}

const strip = function (s) { return s.replace(/\s+/g, ''); };
const SENTENCE_END_INSIDE = /[.!?\u2026](?=\s)/;

for (let c = 0; c < CASES; c++) {
  const seed = 0x23000 + c;
  const r = rng.mulberry32(seed);
  const text = randomText(r);
  const ctx = 'seed ' + seed + ' text ' + JSON.stringify(text);
  const chunks = splitForSpeech(text);

  // Non-blank chunks.
  chunks.forEach(function (ch) { assert.ok(ch.trim() !== '', 'blank chunk, ' + ctx); });

  // In order, rejoining to the trimmed text (cut whitespace ignored).
  assert.strictEqual(strip(chunks.join(' ')), strip(text.trim()), 'rejoin, ' + ctx);

  chunks.forEach(function (ch) {
    // Length limit unless a single word.
    if (ch.length > LIMIT) {
      assert.ok(!/\s/.test(ch), 'long chunk with spaces, ' + ctx);
    }
    // A sentence end inside a chunk only at its end.
    assert.ok(!SENTENCE_END_INSIDE.test(ch), 'sentence end inside chunk, ' + ctx);
  });

  // Short text with no inner sentence end gives exactly one chunk.
  const trimmed = text.trim();
  if (trimmed !== '' && trimmed.length <= LIMIT && !SENTENCE_END_INSIDE.test(trimmed)) {
    assert.strictEqual(chunks.length, 1, 'one chunk expected, ' + ctx);
    assert.strictEqual(chunks[0], trimmed, 'single chunk equals text, ' + ctx);
  }
}

// Guarantee the single-chunk branch is exercised.
['No end mark here', 'Short one.', '地震です', 'a, b; c'].forEach(function (t) {
  assert.deepStrictEqual(splitForSpeech(t), [t]);
});

console.log('voices-split-property: ' + CASES + ' cases ok');
