/*
 * observe-notice-placement.test.js — task 19.2 (public-lab Req 16.1-16.5, 14.22).
 * Static check of where #notice sits in public/index.php, plus headless checks
 * that a shown notice scrolls into view only when needed.
 * Run with `node tests/js/observe-notice-placement.test.js`. No network call.
 */
'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const ROOT = path.join(__dirname, '..', '..');
const JS = path.join(ROOT, 'public', 'js');

// ── static placement ────────────────────────────────────────────────────────
console.log('notice placement in index.php');
const html = fs.readFileSync(path.join(ROOT, 'public', 'index.php'), 'utf8');
const noticeTags = html.match(/<div[^>]*\bid="notice"[^>]*>/g) || [];
assert.strictEqual(noticeTags.length, 1, 'exactly one #notice element');
assert.ok(/aria-live="polite"/.test(noticeTags[0]), '#notice has aria-live="polite"');
assert.ok(/\bhidden\b/.test(noticeTags[0]), '#notice starts hidden');
const secStart = html.indexOf('<section class="panel" aria-label="Disaster switches">');
assert.ok(secStart !== -1, 'Breaker switches panel found');
const secEnd = html.indexOf('</section>', secStart);
const noticeAt = html.indexOf(noticeTags[0]);
assert.ok(noticeAt > secStart && noticeAt < secEnd, '#notice lies inside the Breaker switches panel');
assert.ok(html.slice(secStart, noticeAt).indexOf('<h2>Breaker switches</h2>') !== -1, 'heading precedes the notice');
// Right after div.switches: strip comments and whitespace between its close and the notice.
const switchesAt = html.indexOf('<div class="switches"', secStart);
assert.ok(switchesAt !== -1 && switchesAt < noticeAt, 'div.switches precedes #notice');
const between = html.slice(switchesAt, noticeAt).replace(/<!--[\s\S]*?-->/g, '');
const opens = (between.match(/<div\b/g) || []).length;
const closes = (between.match(/<\/div>/g) || []).length;
assert.strictEqual(opens, closes, 'div.switches is closed before #notice (a sibling, not a child)');
assert.ok(/<\/div>\s*$/.test(between), 'nothing but whitespace/comments between div.switches and #notice');
console.log('  ok');

// ── scrolling behaviour ─────────────────────────────────────────────────────
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

// Fresh page + fresh observe.js; `rect` is what the notice reports, `reduced` the media query.
function load(rect, reduced, withMatchMedia) {
  const byId = {};
  const doc = {
    readyState: 'loading', _ready: [],
    addEventListener: function (ev, fn) { if (ev === 'DOMContentLoaded') { doc._ready.push(fn); } },
    createElement: function () { return makeElement(); },
    createTextNode: function (t) { const n = makeElement(); n.textContent = t; return n; },
    getElementById: function (id) { if (!byId[id]) { byId[id] = makeElement(); } return byId[id]; }
  };
  global.document = doc;
  global.window = { innerHeight: 800 };
  if (withMatchMedia !== false) {
    global.window.matchMedia = function () { return { matches: !!reduced }; };
  }
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
  const scrolls = [];
  const n = doc.getElementById('notice');
  n.getBoundingClientRect = function () { return rect; };
  n.scrollIntoView = function (opts) { scrolls.push(opts); };
  global.window.createApiClient = function () {
    return {
      createEpisode: function () { return new Promise(function () {}); },
      flipDisaster: function () { return new Promise(function () {}); },
      getEpisode: function () { return new Promise(function () {}); },
      ask: function () { return new Promise(function () {}); }
    };
  };
  const file = path.join(JS, 'observe.js');
  delete require.cache[require.resolve(file)];
  require(file);
  doc._ready.forEach(function (fn) { fn(); });
  return { byId: byId, scrolls: scrolls, ctl: global.window.observeController };
}
// Start with no lever chosen shows a one-line notice through showNotice().
function showOne(page) { page.byId['btn-start'].click(); }

console.log('notice scrolling');
const offscreen = { top: 900, bottom: 940 };
const inside = { top: 100, bottom: 140 };

let p = load(offscreen, false);
assert.strictEqual(p.scrolls.length, 0, 'no scroll before any notice');
showOne(p);
assert.strictEqual(p.byId.notice.hidden, false, 'notice is visible');
assert.ok(p.byId.notice.textContent.length > 0, 'notice has text');
assert.strictEqual(p.scrolls.length, 1, 'off-screen notice scrolls once');
assert.strictEqual(p.scrolls[0].block, 'nearest', 'block: nearest');
assert.strictEqual(p.scrolls[0].behavior, 'smooth', 'behavior: smooth');
console.log('  ok - off-screen, smooth');

p = load({ top: -30, bottom: 10 }, false);
showOne(p);
assert.strictEqual(p.scrolls.length, 1, 'a notice cut off at the top scrolls once');
console.log('  ok - partly above the viewport');

p = load(offscreen, true);
showOne(p);
assert.strictEqual(p.scrolls.length, 1, 'reduced motion: scrolls once');
assert.strictEqual(p.scrolls[0].block, 'nearest', 'reduced motion: block nearest');
assert.strictEqual(p.scrolls[0].behavior, 'instant', 'reduced motion: behavior instant');
console.log('  ok - reduced motion, instant');

p = load(inside, false);
showOne(p);
assert.strictEqual(p.byId.notice.hidden, false, 'notice visible');
assert.strictEqual(p.scrolls.length, 0, 'a notice fully inside the viewport does not scroll');
console.log('  ok - inside viewport, no scroll');

p = load(offscreen, false, false);
showOne(p);
assert.strictEqual(p.scrolls.length, 1, 'no matchMedia: still scrolls');
assert.strictEqual(p.scrolls[0].behavior, 'smooth', 'no matchMedia: smooth');
console.log('  ok - matchMedia missing');

p = load(offscreen, false);
p.ctl.startNewRun();
assert.strictEqual(p.byId.notice.hidden, true, 'notice is empty and hidden');
assert.strictEqual(p.scrolls.length, 0, 'an empty notice does not scroll');
console.log('  ok - empty notice, no scroll');
