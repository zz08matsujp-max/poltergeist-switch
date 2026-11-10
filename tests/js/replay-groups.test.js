/*
 * replay-groups.test.js — headless (Node) check of the Replay list groups
 * (public-lab Requirements 6.9, 14.7).
 *
 * renderList(body) reads { featured: [...], yours: [...] } (an object, not an
 * array) and builds a "Featured" section and a "Your runs" section, with an
 * empty-state line under "Your runs", and shows a Featured episode only once.
 *
 * No external dependencies: Node's built-in `assert` and a tiny fake DOM
 * installed before replay.js is required. Makes no network call.
 */
'use strict';

const assert = require('assert');
const path = require('path');

// ── minimal fake DOM ─────────────────────────────────────────────────────────
function makeNode(tag) {
  const node = {
    tagName: String(tag).toUpperCase(),
    className: '',
    hidden: false,
    type: '',
    children: [],
    attrs: {},
    listeners: {},
    _text: '',
    appendChild(child) { this.children.push(child); return child; },
    setAttribute(k, v) { this.attrs[k] = String(v); },
    getAttribute(k) { return this.attrs[k]; },
    addEventListener(name, fn) { this.listeners[name] = fn; },
    style: {}
  };
  Object.defineProperty(node, 'textContent', {
    get() { return this._text; },
    set(v) { this._text = String(v); if (v === '') { this.children = []; } }
  });
  return node;
}

const byId = {};
let initFn = null;
global.document = {
  readyState: 'loading',
  addEventListener(name, fn) { if (name === 'DOMContentLoaded') { initFn = fn; } },
  createElement: makeNode,
  getElementById(id) {
    if (!byId[id]) { byId[id] = makeNode('div'); }
    return byId[id];
  }
};
global.window = { location: { search: '' } };

const controller = require(path.join(__dirname, '..', '..', 'public', 'js', 'replay.js'));
assert.strictEqual(typeof initFn, 'function', 'replay.js should register its init');
initFn(); // looks up the DOM handles; no api client is defined, so no fetch happens

const listEl = byId['episode-list'];

// ── helpers ──────────────────────────────────────────────────────────────────
function item(id) {
  return { id: id, createdDate: '2026-11-08T19:03:13+09:00', turns: 5, startingSituation: 'Afternoon. ' + id };
}
function sections() { return listEl.children; }
function headingOf(section) { return section.children[0].textContent; }
function itemsOf(section) {
  return section.children.filter(function (c) { return c.className === 'item'; });
}
function ids(section) {
  return itemsOf(section).map(function (c) { return c.getAttribute('data-episode-id'); });
}
function emptyLineOf(section) {
  return section.children.filter(function (c) { return c.className === 'list-empty'; });
}

let passed = 0;
function check(name, fn) {
  fn();
  passed += 1;
  console.log('  ok - ' + name);
}

console.log('replay list groups (public-lab Req 6.9, 14.7)');

check('shows the headings "Featured" and "Your runs", in that order', function () {
  controller.renderList({ featured: [item('f1')], yours: [item('y1')] });
  assert.strictEqual(sections().length, 2);
  assert.strictEqual(headingOf(sections()[0]), 'Featured');
  assert.strictEqual(headingOf(sections()[1]), 'Your runs');
  assert.deepStrictEqual(ids(sections()[0]), ['f1']);
  assert.deepStrictEqual(ids(sections()[1]), ['y1']);
  assert.strictEqual(emptyLineOf(sections()[1]).length, 0);
});

check('shows an empty-state line under "Your runs" when yours is empty', function () {
  controller.renderList({ featured: [item('f1')], yours: [] });
  const yours = sections()[1];
  assert.strictEqual(headingOf(yours), 'Your runs');
  assert.strictEqual(itemsOf(yours).length, 0);
  const lines = emptyLineOf(yours);
  assert.strictEqual(lines.length, 1);
  assert.ok(lines[0].textContent.length > 0, 'empty-state line should have text');
});

check('takes an object response, not an array', function () {
  // The new shape is an object; the old array shape yields no items in either group.
  controller.renderList({ featured: [item('f1'), item('f2')], yours: [item('y1')] });
  assert.deepStrictEqual(ids(sections()[0]), ['f1', 'f2']);
  assert.deepStrictEqual(ids(sections()[1]), ['y1']);

  controller.renderList([item('a1'), item('a2')]);
  assert.strictEqual(sections().length, 2);
  assert.strictEqual(itemsOf(sections()[0]).length, 0);
  assert.strictEqual(itemsOf(sections()[1]).length, 0);
});

check('a Featured episode appears only once', function () {
  controller.renderList({ featured: [item('f1'), item('f2')], yours: [item('f1'), item('y1')] });
  assert.deepStrictEqual(ids(sections()[0]), ['f1', 'f2']);
  assert.deepStrictEqual(ids(sections()[1]), ['y1']);

  const all = ids(sections()[0]).concat(ids(sections()[1]));
  assert.strictEqual(new Set(all).size, all.length, 'no episode id repeats across groups');
});

check('re-rendering replaces the previous groups', function () {
  controller.renderList({ featured: [item('f1')], yours: [item('y1')] });
  controller.renderList({ featured: [], yours: [] });
  assert.strictEqual(sections().length, 2);
  assert.strictEqual(itemsOf(sections()[0]).length, 0);
  assert.strictEqual(emptyLineOf(sections()[1]).length, 1);
});

console.log(passed + ' checks passed.');
