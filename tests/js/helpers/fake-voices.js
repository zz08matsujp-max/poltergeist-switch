'use strict';
// The fake Voices_Module for the speech tests. Same interface as
// public/js/voices.js: speak(id, line, onDone) -> boolean, setOn/isOn,
// gesture, cancel, available. No dependencies.
//
//   const voices = createFakeVoices(clock, { startsReading: true, neverFinish: false });
//
// Options:
//   available      false = no engine (speak returns false)        default true
//   on             initial setting                                 default true
//   gestured       true = pretend gesture() already ran            default true
//   startsReading  false = speak() refuses to start (returns false) default true
//   neverFinish    true = the engine never reports the end:
//                  finish() does nothing; only cancel() ends a line (default false)
//
// `calls` records every call with the virtual time. `finish('end'|'error')`
// completes the oldest line still reading. onDone fires exactly once per line:
// 'end', 'error' or 'cancelled'.

function createFakeVoices(clock, options) {
  const opts = options || {};
  const available = opts.available !== false;
  const startsReading = opts.startsReading !== false;
  const neverFinish = opts.neverFinish === true;
  let on = opts.on !== false;
  let gestured = opts.gestured !== false;

  const calls = [];   // { type, at, ... }
  const active = [];  // lines reading now: { id, line, startedAt, onDone, done }

  function at() { return clock && typeof clock.now === 'function' ? clock.now() : 0; }

  function complete(entry, reason) {
    if (entry.done) { return; }
    entry.done = true;
    const i = active.indexOf(entry);
    if (i >= 0) { active.splice(i, 1); }
    calls.push({ type: 'done', at: at(), id: entry.id, line: entry.line, reason: reason });
    if (typeof entry.onDone === 'function') { entry.onDone(reason); }
  }

  function speak(id, line, onDone) {
    const text = typeof line === 'string' ? line.trim() : '';
    const ok = available && on && gestured && startsReading && !!id && text !== '';
    calls.push({ type: 'speak', at: at(), id: id, line: line, started: ok });
    if (!ok) { return false; }
    active.push({ id: id, line: line, startedAt: at(), onDone: onDone, done: false });
    return true;
  }

  function finish(reason) {
    if (neverFinish || active.length === 0) { return false; }
    complete(active[0], reason === 'error' ? 'error' : 'end');
    return true;
  }

  function cancel() {
    calls.push({ type: 'cancel', at: at() });
    active.slice().forEach(function (e) { complete(e, 'cancelled'); });
  }

  function setOn(v) {
    on = v === true;
    calls.push({ type: 'setOn', at: at(), value: on });
    // Like the real module: turning off stops anything being read.
    if (!on) { active.slice().forEach(function (e) { complete(e, 'cancelled'); }); }
  }

  function gesture() {
    gestured = true;
    calls.push({ type: 'gesture', at: at() });
  }

  return {
    speak: speak,
    setOn: setOn,
    isOn: function () { return on; },
    gesture: gesture,
    cancel: cancel,
    available: available,
    // test aids
    finish: finish,
    calls: calls,
    reading: function () { return active.map(function (e) { return { id: e.id, line: e.line, startedAt: e.startedAt }; }); },
    spoken: function () { return calls.filter(function (c) { return c.type === 'speak' && c.started; }); }
  };
}

module.exports = { createFakeVoices: createFakeVoices };
