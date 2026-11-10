'use strict';
// A fake house controller for the speech tests. It records
// showBubble(id, text, seconds) with the virtual time and tracks which bubbles
// are open, so "never two at once" is a direct assertion. No dependencies.
//
// A bubble is open from showBubble(id, text, seconds) until the later of:
//   - an empty/falsy showBubble(id, '') for that id (closes it now), or
//   - another showBubble for that id (replaces it), or
//   - `seconds` (default 4, like house-renderer.js) of virtual time passing.
// Expiry is evaluated lazily from the clock, so it needs no timers.

function createFakeHouse(clock) {
  const calls = [];   // { at, id, text, seconds }
  const bubbles = {}; // id -> { text, openedAt, until } (until in ms)

  function now() { return clock && typeof clock.now === 'function' ? clock.now() : 0; }

  function showBubble(id, text, seconds) {
    const t = now();
    calls.push({ at: t, id: id, text: text, seconds: seconds });
    if (!text) { delete bubbles[id]; return; }
    bubbles[id] = { text: String(text), openedAt: t, until: t + (seconds || 4) * 1000 };
  }

  // The bubbles open right now: [{ id, text, openedAt, until }].
  function openBubbles() {
    const t = now();
    return Object.keys(bubbles)
      .filter(function (id) { return bubbles[id].until > t; })
      .map(function (id) {
        return { id: id, text: bubbles[id].text, openedAt: bubbles[id].openedAt, until: bubbles[id].until };
      });
  }

  // Only the bubble calls matter to the speech tests; the other controller
  // methods Act_Out uses are harmless no-ops.
  return {
    showBubble: showBubble,
    moveCharacter: function () {},
    setPose: function () {},
    setHeld: function () {},
    setWorld: function () {},
    // test aids
    calls: calls,
    openBubbles: openBubbles,
    openCount: function () { return openBubbles().length; }
  };
}

module.exports = { createFakeHouse: createFakeHouse };
