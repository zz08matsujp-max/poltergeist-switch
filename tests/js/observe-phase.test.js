/*
 * observe-phase.test.js — the fake API helper and the exposed Observe phase.
 * Run with `node tests/js/observe-phase.test.js`. No network.
 */
'use strict';

const assert = require('assert');
const path = require('path');
const { createFakeApi } = require('./helpers/fake-api.js');

(async function () {
  console.log('observe phase / fake api');

  // fake-api: queue, repeat of the last entry, function script, rejection, recording.
  const api = createFakeApi({
    createEpisode: [{ status: 409, body: { error: 'x' } }, { status: 200, body: { episodeId: 'e1' } }],
    getEpisode: function (i, args) { return { status: 200, body: { id: args[0], i: i } }; },
    ask: new Error('network')
  });
  assert.deepStrictEqual(await api.createEpisode('heat_wave'), { status: 409, body: { error: 'x' } });
  assert.strictEqual((await api.createEpisode()).status, 200);
  assert.strictEqual((await api.createEpisode()).status, 200, 'last entry repeats');
  assert.deepStrictEqual((await api.getEpisode('e9')).body, { id: 'e9', i: 0 });
  await assert.rejects(api.ask('e1', 1), /network/);
  assert.deepStrictEqual((await api.apply('e1', 1)), { status: 200, body: null }, 'unscripted default');
  assert.strictEqual(api.count('createEpisode'), 3);
  assert.deepStrictEqual(api.calls[0], { method: 'createEpisode', args: ['heat_wave'], index: 0 });
  console.log('  ok - fake api scripts and records calls');

  // observe.js exposes the phase, starting at idle.
  global.window = {};
  global.document = {
    readyState: 'loading',
    addEventListener: function () {},
    getElementById: function () { return null; }
  };
  require(path.join(__dirname, '..', '..', 'public', 'js', 'observe.js'));
  const st = global.window.observeController._state();
  assert.strictEqual(st.phase, 'idle');
  assert.ok(['idle', 'starting', 'staged', 'running', 'unfinished', 'ended'].indexOf(st.phase) >= 0);
  console.log('  ok - _state().phase starts at idle');
})().catch(function (e) { console.error(e); process.exit(1); });
