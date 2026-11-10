'use strict';
/*
 * no-stale-references.test.js — scans public/ and tests/js/ for leftovers of
 * the old Observe screen and the old call shapes.
 * This file and observe-static.test.js are excluded: both name the removed
 * words on purpose (this one to search for them, the other to assert absence).
 * Replay's own Pause button (btn-pause in replay.php / replay.js) is allowed.
 * Validates: Requirements 9.9, 10.1, 10.5, 14.13
 */
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const ROOT = path.join(__dirname, '..', '..');
const EXCLUDED = new Set(['tests/js/no-stale-references.test.js', 'tests/js/observe-static.test.js']);
const EXT = /\.(php|js|css|html)$/;

function walk(dir, out) {
  fs.readdirSync(dir, { withFileTypes: true }).forEach(function (e) {
    const full = path.join(dir, e.name);
    if (e.isDirectory()) { walk(full, out); } else if (EXT.test(e.name)) { out.push(full); }
  });
  return out;
}
const files = walk(path.join(ROOT, 'public'), []).concat(walk(path.join(ROOT, 'tests', 'js'), []))
  .map(function (f) { return { rel: path.relative(ROOT, f).split(path.sep).join('/'), full: f }; })
  .filter(function (f) { return !EXCLUDED.has(f.rel); })
  .map(function (f) { return { rel: f.rel, lines: fs.readFileSync(f.full, 'utf8').split('\n') }; });

function find(test, only) {
  const hits = [];
  files.forEach(function (f) {
    if (only && !only(f.rel)) { return; }
    f.lines.forEach(function (line, i) {
      if (test(line, f.rel)) { hits.push(f.rel + ':' + (i + 1) + ': ' + line.trim()); }
    });
  });
  return hits;
}
function check(name, fn) { fn(); console.log('  ok - ' + name); }
function none(hits, what) { assert.deepStrictEqual(hits, [], what + ':\n' + hits.join('\n')); }

check('the scan sees files', function () { assert.ok(files.length > 10); });

check('no "Paused." text in public/', function () {
  none(find(function (l) { return l.indexOf('Paused.') !== -1; }, function (r) { return r.indexOf('public/') === 0; }), 'Paused.');
});
check('no sw-rain or stepOnce anywhere', function () {
  none(find(function (l) { return l.indexOf('sw-rain') !== -1 || l.indexOf('stepOnce') !== -1; }), 'removed names');
});
check('btn-pause only in Replay', function () {
  none(find(function (l) { return l.indexOf('btn-pause') !== -1; }, function (r) {
    return r === 'public/index.php' || r === 'public/js/observe.js';
  }), 'btn-pause in Observe');
});
check('Array.isArray(result.body) is only used to reject the list', function () {
  none(find(function (l) {
    return l.indexOf('Array.isArray(result.body)') !== -1 && !/\|\|\s*Array\.isArray\(result\.body\)/.test(l);
  }), 'list accepted as an array');
});
check('autoUnlock never defaults to true', function () {
  none(find(function (l) { return /autoUnlock\s*[:=]\s*true/.test(l) || /autoUnlock\s*(!==|===)\s*false/.test(l); }), 'autoUnlock true');
  none(find(function (l) {
    return /createVoices\(\s*\{/.test(l) && !/autoUnlock:\s*false/.test(l);
  }, function (r) { return r.indexOf('public/') === 0; }), 'createVoices without autoUnlock: false');
});
check('createActOut is never called with two arguments', function () {
  none(find(function (l) {
    if (/function\s+createActOut/.test(l) || /^\s*(\/\/|\*|\/\*)/.test(l)) { return false; }
    const m = /createActOut\(([^()]*)\)/.exec(l);
    return !!m && m[1].split(',').length === 2;
  }), 'two-argument createActOut');
});
