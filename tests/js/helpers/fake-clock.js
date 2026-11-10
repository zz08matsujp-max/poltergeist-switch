'use strict';
// A virtual clock for the speech tests. No real waiting, no dependencies.
//
//   const clock = createFakeClock();
//   createSpeechSequencer({ timers: clock, now: clock.now });
//   clock.advance(500);
//
// `setTimeout`, `clearTimeout` and `now` work on virtual milliseconds.
// `advance(ms)` runs due timers in time order (ties in creation order), and a
// timer created by a callback runs in the same `advance` if it falls inside
// the window. `now()` equals the timer's own due time while its callback runs.

function createFakeClock(startMs) {
  let t = typeof startMs === 'number' ? startMs : 0;
  let seq = 0;
  let timers = []; // { id, at, order, fn }

  function now() { return t; }

  function setTimeout(fn, ms) {
    const delay = Math.max(0, Number(ms) || 0);
    const id = ++seq;
    timers.push({ id: id, at: t + delay, order: id, fn: fn });
    return id;
  }

  function clearTimeout(id) {
    timers = timers.filter(function (x) { return x.id !== id; });
  }

  function nextDue(limit) {
    let best = null;
    for (const x of timers) {
      if (x.at > limit) { continue; }
      if (best === null || x.at < best.at || (x.at === best.at && x.order < best.order)) { best = x; }
    }
    return best;
  }

  function advance(ms) {
    const target = t + Math.max(0, Number(ms) || 0);
    for (;;) {
      const x = nextDue(target);
      if (x === null) { break; }
      timers = timers.filter(function (y) { return y.id !== x.id; });
      if (x.at > t) { t = x.at; }
      x.fn();
    }
    t = target;
  }

  return {
    setTimeout: setTimeout,
    clearTimeout: clearTimeout,
    now: now,
    advance: advance,
    pending: function () { return timers.length; }
  };
}

module.exports = { createFakeClock: createFakeClock };
