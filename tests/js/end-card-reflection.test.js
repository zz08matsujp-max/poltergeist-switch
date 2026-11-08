/*
 * end-card-reflection.test.js — headless (Node) rendering check for the
 * Observe End_Card reflect-and-show (robot-memory Requirements 7.4 and 7.5).
 *
 * There is no Node/build step on the server and no JS test runner in the repo;
 * the screens spec verifies the browser modules with a post-deployment headless
 * pass and lightweight checks of the pure JS logic. Task 12.1 put the per-robot
 * End_Card rendering in public/js/memory-ui.js (buildReflectionBlock, built on
 * describeReflection) precisely so this headless check can assert it without a
 * real browser. memory-ui.js is already require-safe in Node: it only touches
 * `window`/`module` through guards at load time, and the DOM is used only
 * inside buildReflectionBlock at call time, so this file supplies a tiny
 * `document`/`window` shim before requiring the module.
 *
 * This file has no external dependencies — it uses only Node's built-in
 * `assert` and runs with `node tests/js/end-card-reflection.test.js`. It makes
 * no network call and names the robots only Amber (robot_a) and Jade
 * (robot_b); no AI company or model name appears anywhere.
 *
 * What is asserted:
 *   (a) an answered robot yields a collapsible <details> holding the full
 *       memory text and its new active count (Requirement 7.4);
 *   (b) a not-answered robot yields the two DISTINCT phrasings — empty_answer
 *       ("… chose not to write anything down.") vs no_reply / timeout
 *       ("… reflection did not come back.") (Requirement 7.5);
 *   (c) no provider/model name appears in any output (Requirement 7.7); and
 *   (d) the awaiting text "Writing down what to remember…" (Requirement 7.3)
 *       appears literally in public/js/observe.js.
 */
'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

// ── Minimal DOM shim ────────────────────────────────────────────────────────
// buildReflectionBlock uses document.createElement and the element API
// (className, setAttribute, textContent, appendChild, type, addEventListener).
// We model just enough to build the tree and read its text back. textContent
// on a parent returns the concatenation of its descendants' text, matching the
// browser, so a <details> reports its summary + body text together.

function makeElement(tag) {
  const children = [];
  let ownText = '';
  const attrs = {};
  const el = {
    tagName: String(tag).toUpperCase(),
    className: '',
    type: '',
    children: children,
    attrs: attrs,
    appendChild: function (child) { children.push(child); return child; },
    setAttribute: function (k, v) { attrs[k] = v; },
    getAttribute: function (k) { return Object.prototype.hasOwnProperty.call(attrs, k) ? attrs[k] : null; },
    addEventListener: function () { /* no-op in the shim */ }
  };
  Object.defineProperty(el, 'textContent', {
    get: function () {
      if (children.length === 0) { return ownText; }
      return children.map(function (c) { return c.textContent; }).join('');
    },
    set: function (v) { ownText = String(v); children.length = 0; }
  });
  return el;
}

global.document = { createElement: makeElement };
global.window = {};

const memoryUi = require(path.join(__dirname, '..', '..', 'public', 'js', 'memory-ui.js'));

// Collect every text fragment produced in a built element's subtree, plus its
// attribute values, so a leak check can scan the whole thing.
function collectText(el) {
  let parts = [el.textContent];
  Object.keys(el.attrs || {}).forEach(function (k) { parts.push(String(el.attrs[k])); });
  (el.children || []).forEach(function (c) { parts = parts.concat(collectText(c)); });
  return parts;
}

// Return the first descendant (including self) whose tag matches.
function findByTag(el, tag) {
  if (el.tagName === tag.toUpperCase()) { return el; }
  const kids = el.children || [];
  for (let i = 0; i < kids.length; i++) {
    const found = findByTag(kids[i], tag);
    if (found) { return found; }
  }
  return null;
}

// The provider / model names that must never appear in End_Card output
// (Requirement 7.7 and the steering rules). Checked case-insensitively.
const FORBIDDEN = ['claude', 'gpt', 'anthropic', 'openai', 'chatgpt', 'haiku', 'luna'];

function assertNoProviderLeak(where, el) {
  const haystack = collectText(el).join(' ').toLowerCase();
  FORBIDDEN.forEach(function (word) {
    assert.ok(haystack.indexOf(word) === -1,
      where + ': output must not contain provider/model name "' + word + '"');
  });
}

let passed = 0;
function check(name, fn) {
  fn();
  passed += 1;
  console.log('  ok - ' + name);
}

console.log('End_Card reflection rendering (robot-memory Req 7.4, 7.5)');

// (a) An answered robot yields a collapsible <details> with the full memory and
//     its new active count (Requirement 7.4).
check('answered robot renders a collapsible details with full memory and count', function () {
  const memory = 'The dishes fell and broke.\n\nI stayed near the old man.';
  const robot = { answered: true, memory: memory, activeCount: 3, reason: null };
  const block = memoryUi.buildReflectionBlock('Amber', robot, 'amber');

  // The block is a collapsible <details> (not a plain line).
  assert.strictEqual(block.tagName, 'DETAILS', 'answered block should be a <details>');
  assert.ok(/\breflection\b/.test(block.className), 'details should carry the reflection class');

  // It has a <summary> naming the robot and its NEW active count.
  const summary = findByTag(block, 'summary');
  assert.ok(summary, 'answered block should have a <summary>');
  assert.ok(summary.textContent.indexOf('Amber') !== -1, 'summary names the robot');
  assert.ok(summary.textContent.indexOf('3') !== -1, 'summary shows the new active count');

  // The full memory text is present verbatim somewhere in the block body.
  assert.ok(block.textContent.indexOf(memory) !== -1, 'the full memory text is shown');

  // A count of 1 reads in the singular ("thing"), plural otherwise.
  const one = memoryUi.buildReflectionBlock('Jade', { answered: true, memory: 'x', activeCount: 1 }, 'jade');
  const oneSummary = findByTag(one, 'summary');
  assert.ok(/1 thing\b/.test(oneSummary.textContent), 'count 1 is singular');
  assert.ok(!/1 things\b/.test(oneSummary.textContent), 'count 1 is not plural');
  const many = memoryUi.buildReflectionBlock('Jade', { answered: true, memory: 'x', activeCount: 2 }, 'jade');
  assert.ok(/2 things\b/.test(findByTag(many, 'summary').textContent), 'count 2 is plural');

  assertNoProviderLeak('answered details', block);
});

// (b) The two DISTINCT phrasings for a robot that did not answer (Requirement 7.5):
//     empty_answer vs no_reply / timeout.
check('empty_answer and failed-call produce two distinct phrasings', function () {
  const empty = memoryUi.buildReflectionBlock('Jade', { answered: false, reason: 'empty_answer' }, 'jade');
  const noReply = memoryUi.buildReflectionBlock('Jade', { answered: false, reason: 'no_reply' }, 'jade');
  const timeout = memoryUi.buildReflectionBlock('Jade', { answered: false, reason: 'timeout' }, 'jade');

  // Each is a plain, non-collapsible line (a <p>), not a <details>.
  assert.strictEqual(empty.tagName, 'P', 'empty_answer block is a plain line');
  assert.strictEqual(noReply.tagName, 'P', 'no_reply block is a plain line');
  assert.strictEqual(timeout.tagName, 'P', 'timeout block is a plain line');

  const emptyText = empty.textContent;
  const noReplyText = noReply.textContent;
  const timeoutText = timeout.textContent;

  // The empty-answer phrasing: the robot chose to keep nothing.
  assert.ok(emptyText.indexOf('Jade') !== -1, 'empty phrasing names the robot');
  assert.ok(/chose not to write anything down\./.test(emptyText),
    'empty_answer uses the "chose not to write anything down" phrasing');

  // The failed-call phrasing: the reflection did not come back. Same text for
  // no_reply and timeout, and DISTINCT from the empty-answer phrasing.
  assert.ok(/reflection did not come back\./.test(noReplyText),
    'no_reply uses the "reflection did not come back" phrasing');
  assert.strictEqual(noReplyText, timeoutText,
    'no_reply and timeout share the failed-call phrasing');
  assert.notStrictEqual(emptyText, noReplyText,
    'the empty-answer and failed-call phrasings must be distinct');

  // (c) no provider / model name in any of the three.
  assertNoProviderLeak('empty_answer line', empty);
  assertNoProviderLeak('no_reply line', noReply);
  assertNoProviderLeak('timeout line', timeout);
});

// An unknown / missing reason falls back to the failed-call phrasing (so the
// card is never left mid-sentence), still naming only the robot.
check('missing reason falls back to the failed-call phrasing', function () {
  const block = memoryUi.buildReflectionBlock('Amber', { answered: false }, 'amber');
  assert.ok(/reflection did not come back\./.test(block.textContent),
    'a missing reason reads as a failed call');
  assertNoProviderLeak('missing-reason line', block);
});

// (d) The awaiting text (Requirement 7.3) is the exact literal in observe.js.
check('observe.js carries the exact awaiting text', function () {
  const src = fs.readFileSync(
    path.join(__dirname, '..', '..', 'public', 'js', 'observe.js'), 'utf8');
  // The source uses the escaped ellipsis \u2026; match either the escape or the
  // literal character so the check does not depend on how the file stores it.
  const hasEscaped = src.indexOf('Writing down what to remember\\u2026') !== -1;
  const hasLiteral = src.indexOf('Writing down what to remember\u2026') !== -1;
  assert.ok(hasEscaped || hasLiteral,
    'observe.js should contain the awaiting text "Writing down what to remember…"');
});

console.log(passed + ' checks passed.');
