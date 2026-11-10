/*
 * disaster-already-set.test.js — headless (Node) test for the Observe notice
 * shown when the server refuses a disaster flip with 409 'disaster_already_set'.
 * Run with `node tests/js/disaster-already-set.test.js`. No network call.
 */
'use strict';

const assert = require('assert');
const path = require('path');

const JS = path.join(__dirname, '..', '..', 'public', 'js');

function makeElement() {
  const attrs = {};
  const classes = new Set();
  const listeners = {};
  const el = {
    children: [], attrs: attrs, hidden: false, disabled: false, style: {}, _subs: {},
    appendChild: function (c) { el.children.push(c); return c; },
    setAttribute: function (k, v) { attrs[k] = String(v); },
    getAttribute: function (k) { return Object.prototype.hasOwnProperty.call(attrs, k) ? attrs[k] : null; },
    addEventListener: function (ev, fn) { (listeners[ev] = listeners[ev] || []).push(fn); },
    click: function () { (listeners.click || []).forEach(function (fn) { fn(); }); },
    querySelector: function (sel) { return el._subs[sel] || null; },
    classList: {
      add: function (c) { classes.add(c); },
      remove: function (c) { classes.delete(c); },
      contains: function (c) { return classes.has(c); }
    },
    textContent: ''
  };
  return el;
}

const byId = {};
const doc = {
  readyState: 'loading',
  _ready: [],
  addEventListener: function (ev, fn) { if (ev === 'DOMContentLoaded') { doc._ready.push(fn); } },
  createElement: function () { return makeElement(); },
  createTextNode: function (t) { const n = makeElement(); n.textContent = t; return n; },
  getElementById: function (id) { if (!byId[id]) { byId[id] = makeElement(); } return byId[id]; }
};
global.document = doc;
global.window = {};

const calls = [];
const view = { episodeId: 'e1', disaster: 'heat_wave', currentExpectedTurn: 1, ended: false, turns: [] };
const fakeApi = {
  createEpisode: function () { calls.push('create'); return Promise.resolve({ status: 200, body: view }); },
  flipDisaster: function () {
    calls.push('flip');
    return Promise.resolve({ status: 409, body: { error: 'disaster_already_set', currentExpectedTurn: 2, turnState: 'waiting' } });
  },
  getEpisode: function () { return Promise.resolve({ status: 200, body: view }); },
  ask: function () { calls.push('ask'); return new Promise(function () { /* never resolves */ }); }
};

['sw-earthquake', 'sw-heat'].forEach(function (id) {
  const b = doc.getElementById(id);
  b._subs['.mark'] = makeElement();
  b.setAttribute('aria-pressed', 'false');
});
['meter-temp', 'meter-water', 'meter-injury', 'meter-fear', 'meter-room'].forEach(function (id) {
  const m = doc.getElementById(id);
  m._subs['.fill'] = makeElement();
  m._subs['.val'] = makeElement();
});
global.window.createApiClient = function () { return fakeApi; };

require(path.join(JS, 'observe.js'));
doc._ready.forEach(function (fn) { fn(); });

function settle() { return new Promise(function (r) { setImmediate(r); }); }

(async function () {
  console.log('disaster_already_set notice');
  for (const id of ['sw-heat', 'sw-earthquake']) {
    calls.length = 0;
    byId.notice.textContent = '';
    byId[id].click();
    await settle();
    await settle();
    assert.ok(calls.indexOf('flip') !== -1, id + ': the flip was sent');
    assert.strictEqual(byId.notice.textContent, 'This episode already has its disaster.', id + ': notice shown');
    assert.strictEqual(byId.notice.hidden, false, id + ': notice visible');
    assert.strictEqual(byId['sw-heat'].getAttribute('aria-pressed'), 'false', id + ': heat lever unchanged');
    assert.strictEqual(byId['sw-earthquake'].getAttribute('aria-pressed'), 'false', id + ': earthquake lever unchanged');
    assert.ok(calls.indexOf('ask') === -1, id + ': no turn asked');
    console.log('  ok - ' + id);
  }
})().catch(function (e) { console.error(e); process.exit(1); });
