/*
 * replay-started-with.test.js — headless (Node) rendering check for the
 * Replay screen's per-robot "started with N memories" count (robot-memory
 * Requirement 7.6).
 *
 * There is no Node/build step on the server and no JS test runner in the repo;
 * the screens spec verifies the browser modules with a post-deployment headless
 * pass and lightweight checks of the pure JS logic. Task 13.1 exposed the pure
 * counting surface on window.replayController / module.exports
 * (countSnapshotFor) precisely so this headless check can assert it without a
 * DOM: requiring public/js/replay.js in Node does NOT touch the DOM, because
 * the controller only calls init() when `document` exists.
 *
 * This file has no external dependencies — it uses only Node's built-in
 * `assert` and runs with `node tests/js/replay-started-with.test.js`. It makes
 * no network call and names the robots only Amber (robot_a) and Jade (robot_b).
 *
 * The property under test (Requirement 7.6): for a stored episode view, the
 * per-robot started-with count that the Replay controller derives from the
 * stored situation text equals the length of that robot's stored
 * Memory_Snapshot (the number of memory bodies the robot started the episode
 * with). The count is read out of the Memories_Block embedded verbatim in the
 * robot's situation text, so we build each robot's situation text exactly as
 * SituationTextGenerator does and assert the controller recovers the snapshot
 * length.
 */
'use strict';

const assert = require('assert');
const path = require('path');

const controller = require(path.join(__dirname, '..', '..', 'public', 'js', 'replay.js'));

// The fixed Appendix A heading SituationTextGenerator::MEMORIES_HEADING renders
// verbatim (must match the constant in public/js/replay.js and the PHP side).
const MEMORIES_HEADING =
  'Your memories (what you wrote down after an earlier disaster in this house):';

// Build a robot's Memories_Block exactly as SituationTextGenerator::
// buildMemoriesBlock does: the heading, a blank line, then each verbatim body
// wrapped in its own `"""` block, oldest first, blank line between blocks.
// Returns '' for an empty snapshot (no block at all), matching the PHP null.
function buildMemoriesBlock(bodies) {
  if (!bodies || bodies.length === 0) { return ''; }
  const blocks = bodies.map(function (body) {
    return '"""\n' + body + '\n"""';
  });
  return MEMORIES_HEADING + '\n\n' + blocks.join('\n\n');
}

// Build a plausible situation text for a robot, with the Memories_Block (when
// the snapshot is non-empty) sitting between the fixed house section and the
// recent-events / "Right now:" section — exactly where SituationTextGenerator
// inserts it. The exact body/house wording does not matter to the count; what
// matters is that the block form and the surrounding blank lines match.
function buildSituationText(bodies) {
  const body = 'You are a home robot.';
  const house =
    'This house has six rooms.\n' +
    'In the kitchen there are: a table, a kettle.';
  const rightNow =
    'Right now: It is afternoon. You are in the kitchen. ' +
    'The other robot is in the hall.';
  const question = 'What are you thinking right now, and what do you do?';

  const block = buildMemoriesBlock(bodies);
  const sections = [body, house];
  if (block !== '') { sections.push(block); }
  sections.push(rightNow);
  sections.push(question);
  return sections.join('\n\n');
}

// Build a stored episode view (the shape public/replay.php loads) in which both
// robots carry the given snapshots on every turn's situation text. The snapshot
// is frozen for the whole episode, so every turn carries the same block.
function buildView(snapshotA, snapshotB, turnCount) {
  const turns = [];
  for (let n = 0; n <= turnCount; n++) {
    turns.push({
      turnNumber: n,
      world: {},
      robots: {
        robot_a: { situationText: buildSituationText(snapshotA) },
        robot_b: { situationText: buildSituationText(snapshotB) }
      }
    });
  }
  return { ended: true, turns: turns };
}

let passed = 0;
function check(name, fn) {
  fn();
  passed += 1;
  console.log('  ok - ' + name);
}

console.log('replay started-with count (robot-memory Req 7.6)');

// Core property: per-robot started-with count === stored snapshot length, over
// a spread of snapshot sizes for both robots (0 and the 1..18 cap range).
check('count equals snapshot length for both robots across sizes', function () {
  const sizes = [0, 1, 2, 3, 6, 7, 12, 18];
  for (const a of sizes) {
    for (const b of sizes) {
      const snapA = Array.from({ length: a }, (_, i) => 'Amber memory ' + (i + 1));
      const snapB = Array.from({ length: b }, (_, i) => 'Jade memory ' + (i + 1));
      const view = buildView(snapA, snapB, 3);

      const countA = controller.countSnapshotFor(view.turns, 'robot_a');
      const countB = controller.countSnapshotFor(view.turns, 'robot_b');

      assert.strictEqual(countA, snapA.length,
        'Amber: count ' + countA + ' != snapshot length ' + snapA.length);
      assert.strictEqual(countB, snapB.length,
        'Jade: count ' + countB + ' != snapshot length ' + snapB.length);
    }
  }
});

// A robot that started with no memories has no Memories_Block and counts 0.
check('empty snapshot counts zero', function () {
  const view = buildView([], [], 2);
  assert.strictEqual(controller.countSnapshotFor(view.turns, 'robot_a'), 0);
  assert.strictEqual(controller.countSnapshotFor(view.turns, 'robot_b'), 0);
});

// Memory bodies that themselves contain blank lines and `"""`-looking text must
// not inflate or deflate the count: the count is still the snapshot length.
// (The body is wrapped in its own `"""` fences, so inner blank lines are part
// of the verbatim body, and a triple-quote that is not a lone-line fence is
// body text.)
check('multiline memory bodies do not change the count', function () {
  const snap = [
    'The dishes fell and broke.\n\nI stayed near the old man.',
    'Water came under the door.',
    'Line with a "quote" inside it.'
  ];
  const view = buildView(snap, [], 2);
  assert.strictEqual(controller.countSnapshotFor(view.turns, 'robot_a'), snap.length);
});

// The two robots are independent: different snapshot sizes are read per robot.
check('per-robot counts are independent', function () {
  const snapA = ['a1', 'a2', 'a3', 'a4'];
  const snapB = ['b1'];
  const view = buildView(snapA, snapB, 1);
  assert.strictEqual(controller.countSnapshotFor(view.turns, 'robot_a'), 4);
  assert.strictEqual(controller.countSnapshotFor(view.turns, 'robot_b'), 1);
});

console.log(passed + ' checks passed.');
