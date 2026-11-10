'use strict';
/*
 * observe-static.test.js — the Observe screen has no Pause, Step, Resume or
 * Heavy rain, only two Levers, and Replay's own controls are unchanged.
 * Validates: Requirements 10.1, 10.2, 10.5, 10.6, 14.10
 */
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..', '..');
const indexSrc = fs.readFileSync(path.join(ROOT, 'public', 'index.php'), 'utf8');
const observeSrc = fs.readFileSync(path.join(ROOT, 'public', 'js', 'observe.js'), 'utf8');
const replaySrc = fs.readFileSync(path.join(ROOT, 'public', 'replay.php'), 'utf8');

function check(name, fn) { fn(); console.log('  ok - ' + name); }

check('removed names are gone from index.php and observe.js', function () {
  ['btn-pause', 'btn-step', 'btn-resume', 'sw-rain', 'Heavy rain', 'coming later', 'stepOnce'].forEach(function (w) {
    assert.ok(indexSrc.indexOf(w) === -1, 'index.php has no ' + w);
    assert.ok(observeSrc.indexOf(w) === -1, 'observe.js has no ' + w);
  });
});
check('two Levers only: Earthquake and Heat wave', function () {
  const ids = (indexSrc.match(/<button[^>]*class="sw"[^>]*>/g) || []).map(function (t) { return /id="([^"]+)"/.exec(t)[1]; });
  assert.deepStrictEqual(ids, ['sw-earthquake', 'sw-heat']);
});
check('Start and Voices exist', function () {
  assert.ok(/id="btn-start"/.test(indexSrc));
  assert.ok(/id="btn-voices"/.test(indexSrc));
});
check('Replay keeps its own Play, Pause, Step back and Step forward', function () {
  ['Play', 'Pause', 'Step back', 'Step forward'].forEach(function (w) {
    assert.ok(replaySrc.indexOf(w) !== -1, 'replay.php has ' + w);
  });
});
