/*
 * speech-lines.test.js — linesOfTurn in public/js/speech-sequencer.js.
 *
 * Feature: public-lab, Property 17: Spoken lines never include a thought
 * Validates: Requirements 11.13, 11.5
 *
 * For generated turns (seeded, 300 cases): the lines are exactly
 * robot_a.reading.words, then robot_b.reading.words, then residentLine, with
 * blank entries dropped, and no other reply text (thought, raw reply, outcome,
 * judgement, ...) ever appears in a line.
 */
'use strict';

const assert = require('assert');
const { linesOfTurn } = require('../../public/js/speech-sequencer.js');
const { mulberry32, int, pick, bool } = require('./helpers/rng.js');

const CASES = 300;
const BLANKS = ['', ' ', '   ', '\t', '\n', ' \n '];
const WORDS = ['Are you all right?', 'Hold on.', 'I will help', 'Mr. Tanaka', 'ok', 'Watch out!', '大丈夫ですか'];

let counter = 0;
function distinct(kind) { counter += 1; return 'SECRET-' + kind + '-' + counter; }

function genWords(rng, tag) {
  const n = int(rng, 0, 4);
  const items = [];   // everything in the array
  const expected = []; // non-blank strings in order
  for (let i = 0; i < n; i++) {
    if (bool(rng, 0.3)) {
      items.push(pick(rng, BLANKS));
    } else {
      const s = pick(rng, WORDS) + ' ' + tag + i;
      items.push(s);
      expected.push(s);
    }
  }
  return { items: items, expected: expected };
}

function genRobot(rng, tag, secrets, nested) {
  const w = genWords(rng, tag);
  const reading = {
    operation: pick(rng, ['speak', 'move', 'cover', 'wait', 'unknown']),
    targetId: bool(rng) ? 'table' : null,
    thought: distinct('thought'),
    reply: distinct('reply')
  };
  secrets.push(reading.thought, reading.reply);
  if (nested) { reading.action = { operation: reading.operation, words: w.items }; }
  else { reading.words = w.items; }
  const layers = {
    reading: reading,
    thought: distinct('thought'),
    text: distinct('text'),
    rawReply: distinct('raw'),
    reasoning: distinct('reasoning'),
    judgement: { possible: bool(rng), note: distinct('judge') },
    outcome: { summary: distinct('outcome') }
  };
  secrets.push(layers.thought, layers.text, layers.rawReply, layers.reasoning,
    layers.judgement.note, layers.outcome.summary);
  return { layers: layers, expected: w.expected };
}

function genTurn(rng) {
  const secrets = [];
  const nested = bool(rng, 0.3);
  const a = genRobot(rng, 'a', secrets, nested);
  const b = genRobot(rng, 'b', secrets, nested);
  const turn = { n: int(rng, 1, 30), robots: { robot_a: a.layers, robot_b: b.layers } };
  const expected = a.expected.concat(b.expected);
  const r = rng();
  if (r < 0.6) { turn.residentLine = pick(rng, WORDS) + ' man'; expected.push(turn.residentLine); }
  else if (r < 0.8) { turn.residentLine = pick(rng, BLANKS); }
  else if (r < 0.9) { turn.residentLine = null; }
  turn.thought = distinct('turnthought');
  turn.manThought = distinct('manthought');
  secrets.push(turn.thought, turn.manThought);
  return { turn: turn, expected: expected, secrets: secrets };
}

const rng = mulberry32(20261117);
for (let i = 0; i < CASES; i++) {
  const g = genTurn(rng);
  const lines = linesOfTurn(g.turn);
  const texts = lines.map((l) => l.text);

  assert.deepStrictEqual(texts, g.expected, 'case ' + i + ': lines are a, b, man in order, blanks dropped');
  texts.forEach((t) => assert.ok(t.trim() !== '', 'case ' + i + ': no blank line'));
  g.secrets.forEach((s) => {
    texts.forEach((t) => assert.ok(!t.includes(s), 'case ' + i + ': leaked ' + s));
  });
  // Speaker ids follow the same order: amber, then jade, then man.
  const order = { amber: 0, jade: 1, man: 2 };
  for (let k = 1; k < lines.length; k++) {
    assert.ok(order[lines[k - 1].id] <= order[lines[k].id], 'case ' + i + ': speaker order');
  }
}

// Edge cases: missing pieces give no lines and do not throw.
assert.deepStrictEqual(linesOfTurn(null), []);
assert.deepStrictEqual(linesOfTurn({}), []);
assert.deepStrictEqual(linesOfTurn({ robots: { robot_a: { thought: 'x' } } }), []);

console.log('speech-lines: ' + CASES + ' cases passed');
