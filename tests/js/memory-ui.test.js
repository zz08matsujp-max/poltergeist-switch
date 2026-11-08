/*
 * memory-ui.test.js — headless (Node) rendering check for the Observe screen's
 * memory status line and the in-page All-reset confirmation panel
 * (robot-memory Requirements 7.1 and 7.2).
 *
 * There is no Node/build step on the server and no JS test runner in the repo;
 * the screens spec verifies the browser modules with a post-deployment headless
 * pass and lightweight checks of the pure JS logic. Task 11.1 put the status-
 * line wording and the confirm-panel builder in public/js/memory-ui.js, which
 * exports its surface via module.exports (and window.memoryUi in the browser)
 * precisely so this headless check can require it. Requiring the module in Node
 * does NOT touch a real DOM; buildConfirmPanel only uses document.createElement
 * / addEventListener, which we satisfy with a tiny fake `document` below.
 *
 * This file has no external dependencies — it uses only Node's built-in
 * `assert` and runs with `node tests/js/memory-ui.test.js`. It makes no network
 * call and names the robots only Amber (robot_a) and Jade (robot_b).
 *
 * Properties under test:
 *   (a) Requirement 7.1 — statusLineText / renderStatusLine produce the EXACT
 *       format "Generation N · Amber remembers k · Jade remembers k" with the
 *       U+00B7 MIDDLE DOT separator, for sample counts.
 *   (b) Requirement 7.2 — buildConfirmPanel builds an in-page panel with a
 *       Confirm and a Cancel control and uses NO browser alert/confirm dialog
 *       (the returned value is a DOM element, and the module source calls
 *       neither window.alert nor window.confirm nor a bare alert/confirm).
 */
'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const MODULE_PATH = path.join(__dirname, '..', '..', 'public', 'js', 'memory-ui.js');

// A tiny fake `document` so buildConfirmPanel's createElement/addEventListener
// work headlessly. Each element records its tag, attributes, class, text, the
// children appended to it, and the click handlers registered on it, so a test
// can walk the returned panel and fire a click without a real browser.
function makeFakeDocument() {
  function createElement(tag) {
    const handlers = {};
    const el = {
      tagName: String(tag).toUpperCase(),
      className: '',
      type: '',
      textContent: '',
      attributes: {},
      children: [],
      _handlers: handlers,
      setAttribute: function (name, value) { this.attributes[name] = value; },
      getAttribute: function (name) {
        return Object.prototype.hasOwnProperty.call(this.attributes, name)
          ? this.attributes[name] : null;
      },
      appendChild: function (child) { this.children.push(child); return child; },
      addEventListener: function (type, fn) {
        (handlers[type] = handlers[type] || []).push(fn);
      },
      dispatch: function (type) {
        (handlers[type] || []).forEach(function (fn) { fn(); });
      }
    };
    return el;
  }
  return { createElement: createElement };
}

// Install the fake document on the global before requiring the module so the
// browser-side `document` reference inside buildConfirmPanel resolves.
global.document = makeFakeDocument();

const memoryUi = require(MODULE_PATH);

// Walk a fake element tree and collect every descendant (including self) that
// matches a predicate.
function findAll(el, pred, out) {
  out = out || [];
  if (pred(el)) { out.push(el); }
  (el.children || []).forEach(function (c) { findAll(c, pred, out); });
  return out;
}

let passed = 0;
function check(name, fn) {
  fn();
  passed += 1;
  console.log('  ok - ' + name);
}

console.log('memory-ui status line + confirm panel (robot-memory Req 7.1, 7.2)');

// (a) Requirement 7.1 — exact status-line format with the U+00B7 separator.
check('statusLineText produces the exact Generation/remembers format', function () {
  const samples = [
    { generation: 1, amber: 0, jade: 0 },
    { generation: 2, amber: 1, jade: 3 },
    { generation: 5, amber: 6, jade: 6 },
    { generation: 42, amber: 18, jade: 0 }
  ];
  samples.forEach(function (s) {
    const status = {
      generation: s.generation,
      robots: {
        amber: { activeCount: s.amber },
        jade: { activeCount: s.jade }
      }
    };
    const expected = 'Generation ' + s.generation +
      ' \u00b7 Amber remembers ' + s.amber +
      ' \u00b7 Jade remembers ' + s.jade;
    const actual = memoryUi.statusLineText(status);
    assert.strictEqual(actual, expected,
      'status line mismatch: ' + JSON.stringify(actual));
    // The separator must be the U+00B7 MIDDLE DOT, not ASCII '*' or '.'.
    assert.ok(actual.indexOf('\u00b7') !== -1, 'missing U+00B7 separator');
    // Names are Amber/Jade only — no company or model name.
    assert.ok(actual.indexOf('Amber') !== -1 && actual.indexOf('Jade') !== -1);
  });
});

// A partial/empty payload reads as generation 1 and zero counts (never throws).
check('statusLineText tolerates a missing/empty payload', function () {
  assert.strictEqual(memoryUi.statusLineText(),
    'Generation 1 \u00b7 Amber remembers 0 \u00b7 Jade remembers 0');
  assert.strictEqual(memoryUi.statusLineText({ generation: 3 }),
    'Generation 3 \u00b7 Amber remembers 0 \u00b7 Jade remembers 0');
});

// renderStatusLine writes exactly statusLineText into the host's textContent.
check('renderStatusLine writes the status text into the host element', function () {
  const host = global.document.createElement('div');
  const status = {
    generation: 7,
    robots: { amber: { activeCount: 2 }, jade: { activeCount: 4 } }
  };
  memoryUi.renderStatusLine(host, status);
  assert.strictEqual(host.textContent, memoryUi.statusLineText(status));
  assert.strictEqual(host.textContent,
    'Generation 7 \u00b7 Amber remembers 2 \u00b7 Jade remembers 4');
  // A null host is a no-op, not a throw.
  assert.doesNotThrow(function () { memoryUi.renderStatusLine(null, status); });
});

// (b) Requirement 7.2 — in-page confirm panel with Confirm/Cancel controls.
check('buildConfirmPanel builds a DOM panel with Confirm and Cancel buttons', function () {
  const panel = memoryUi.buildConfirmPanel(function () {}, function () {});
  // It returns a DOM element (our fake element), not a string or dialog call.
  assert.ok(panel && typeof panel === 'object' && panel.tagName === 'DIV',
    'panel is not a DOM element');
  assert.strictEqual(panel.className, 'confirm-panel');

  const buttons = findAll(panel, function (el) { return el.tagName === 'BUTTON'; });
  const labels = buttons.map(function (b) { return b.textContent; });
  assert.ok(labels.indexOf('Confirm') !== -1, 'no Confirm button');
  assert.ok(labels.indexOf('Cancel') !== -1, 'no Cancel button');
});

// The Confirm and Cancel callbacks fire on the respective button clicks, and
// nothing is asked through a browser dialog — the panel does the asking.
check('Confirm and Cancel buttons invoke their callbacks (no dialog)', function () {
  let confirmed = 0;
  let cancelled = 0;
  const panel = memoryUi.buildConfirmPanel(
    function () { confirmed += 1; },
    function () { cancelled += 1; }
  );
  const buttons = findAll(panel, function (el) { return el.tagName === 'BUTTON'; });
  const confirmBtn = buttons.filter(function (b) { return b.textContent === 'Confirm'; })[0];
  const cancelBtn = buttons.filter(function (b) { return b.textContent === 'Cancel'; })[0];

  confirmBtn.dispatch('click');
  assert.strictEqual(confirmed, 1);
  assert.strictEqual(cancelled, 0);

  cancelBtn.dispatch('click');
  assert.strictEqual(confirmed, 1);
  assert.strictEqual(cancelled, 1);
});

// The module source must never reach for a browser alert/confirm dialog: the
// confirmation is entirely in-page (Requirement 7.2).
check('memory-ui.js source uses no browser alert/confirm dialog', function () {
  const src = fs.readFileSync(MODULE_PATH, 'utf8');
  // Strip line and block comments so a mention in a comment never trips the
  // check — we only care about actual calls in code.
  const code = src
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/\/\/[^\n]*/g, '');
  assert.ok(!/window\s*\.\s*alert\s*\(/.test(code), 'calls window.alert');
  assert.ok(!/window\s*\.\s*confirm\s*\(/.test(code), 'calls window.confirm');
  // A bare alert(/confirm( call (global) is also forbidden.
  assert.ok(!/(^|[^.\w])alert\s*\(/.test(code), 'calls bare alert()');
  assert.ok(!/(^|[^.\w])confirm\s*\(/.test(code), 'calls bare confirm()');
});

console.log(passed + ' checks passed.');
