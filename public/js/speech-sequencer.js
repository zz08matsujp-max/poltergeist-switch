/*
 * speech-sequencer.js — one Spoken_Line at a time, in order, with its bubble
 * visible while it is being read (public-lab spec, Requirements 11.1 to 11.15).
 *
 * Shared by Observe and Replay. Pure browser JS, no build step. Attaches
 * `createSpeechSequencer`, `readingTime`, `linesOfTurn` and `bindVoicesButton`
 * to `window` and exports them with CommonJS when a module system exists
 * (same shape as voices.js).
 *
 * Per line: mode 'voice' (voices.speak() returned true; the bubble stays until
 * onDone or startedAt + 12000 ms, then voices.cancel()) or mode 'timer' (the
 * bubble stays for readingTime(text)). After a line closes the next starts at
 * once. Thoughts are never part of a line list. Speech is best effort: every
 * call into voices / house is guarded.
 */
(function () {
  'use strict';

  var HARD_CAP_MS = 12000;

  function codePoints(text) {
    return Array.from(String(text)).length;
  }

  // Req 11.7: min(12000, 2000 + 60 * code points).
  function readingTime(text) {
    return Math.min(HARD_CAP_MS, 2000 + 60 * codePoints(text));
  }

  function isLine(s) {
    return typeof s === 'string' && s.trim() !== '';
  }

  function wordsOf(turn, key) {
    var robots = turn && turn.robots;
    var layers = robots && robots[key];
    if (!layers) { return []; }
    var reading = layers.reading || {};
    var act = reading.action || reading;
    var words = act.words || reading.words || [];
    return Array.isArray(words) ? words : [];
  }

  // Req 11.1, 11.5: robot_a words, robot_b words, then the man's line; each
  // element is its own line; blanks dropped; thoughts never included.
  function linesOfTurn(turn) {
    var out = [];
    var i, w;
    w = wordsOf(turn, 'robot_a');
    for (i = 0; i < w.length; i++) { if (isLine(w[i])) { out.push({ id: 'amber', text: w[i] }); } }
    w = wordsOf(turn, 'robot_b');
    for (i = 0; i < w.length; i++) { if (isLine(w[i])) { out.push({ id: 'jade', text: w[i] }); } }
    if (turn && isLine(turn.residentLine)) { out.push({ id: 'man', text: turn.residentLine }); }
    return out;
  }

  function createSpeechSequencer(options) {
    var opts = options || {};
    var house = opts.house || null;
    var voices = opts.voices || null;
    var timers = opts.timers || {
      setTimeout: function (fn, ms) { return setTimeout(fn, ms); },
      clearTimeout: function (id) { clearTimeout(id); }
    };
    var now = typeof opts.now === 'function' ? opts.now : function () { return Date.now(); };

    var token = 0;          // bumped by cancel() and by a new play()
    var current = null;     // state of the line being read, or null
    var resolvePlay = null; // pending play() promise resolver

    function showBubble(id, text, seconds) {
      if (!house || typeof house.showBubble !== 'function') { return; }
      try { house.showBubble(id, text, seconds); } catch (e) { /* ignore */ }
    }

    function voicesCancel() {
      if (!voices || typeof voices.cancel !== 'function') { return; }
      try { voices.cancel(); } catch (e) { /* ignore */ }
    }

    function clearLineTimers(line) {
      if (line.timer !== null) { timers.clearTimeout(line.timer); line.timer = null; }
      if (line.capTimer !== null) { timers.clearTimeout(line.capTimer); line.capTimer = null; }
    }

    function settle() {
      var r = resolvePlay;
      resolvePlay = null;
      if (r) { r(); }
    }

    // Close the open line's bubble and move on (or finish the play).
    function closeLine(line, next) {
      if (line.closed) { return; }
      line.closed = true;
      clearLineTimers(line);
      if (current === line) { current = null; }
      showBubble(line.id, '');
      if (line.token === token) { next(); }
    }

    // Switch a line to timer mode for the rest of its Reading_Time, counted
    // from its original start (Req 11.14). Idempotent.
    function toTimerMode(line) {
      if (line.closed || line.mode === 'timer') { return; }
      line.mode = 'timer';
      if (line.capTimer !== null) { timers.clearTimeout(line.capTimer); line.capTimer = null; }
      var remaining = Math.max(0, readingTime(line.text) - (now() - line.startedAt));
      if (remaining <= 0) { closeLine(line, line.next); return; }
      line.timer = timers.setTimeout(function () {
        line.timer = null;
        closeLine(line, line.next);
      }, remaining);
    }

    function startLine(lines, index, myToken, done) {
      if (myToken !== token) { return; }
      if (index >= lines.length) { done(); return; }
      var item = lines[index];
      var next = function () { startLine(lines, index + 1, myToken, done); };
      var line = {
        id: item.id, text: item.text, token: myToken, mode: 'timer', startedAt: now(),
        timer: null, capTimer: null, closed: false, next: next
      };
      current = line;

      var started = false;
      var starting = true;
      var earlyDone = null;
      if (voices && typeof voices.speak === 'function') {
        try {
          started = voices.speak(item.id, item.text, function (reason) {
            if (starting) { earlyDone = reason; return; }
            onVoiceDone(line, reason);
          }) === true;
        } catch (e) { started = false; }
      }
      starting = false;

      if (started) {
        line.mode = 'voice';
        showBubble(item.id, item.text, HARD_CAP_MS / 1000);
        line.capTimer = timers.setTimeout(function () {
          line.capTimer = null;
          if (line.closed || line.mode !== 'voice') { return; }
          line.mode = 'capped'; // ignore the 'cancelled' report of our own cancel
          voicesCancel();
          closeLine(line, next);
        }, HARD_CAP_MS);
        if (earlyDone !== null) { onVoiceDone(line, earlyDone); }
      } else {
        var ms = readingTime(item.text);
        showBubble(item.id, item.text, ms / 1000);
        line.timer = timers.setTimeout(function () {
          line.timer = null;
          closeLine(line, next);
        }, ms);
      }
    }

    function onVoiceDone(line, reason) {
      if (line.closed || line.mode !== 'voice') { return; }
      if (reason === 'cancelled') { toTimerMode(line); return; }
      closeLine(line, line.next);
    }

    return {
      // Resolves when the last line has closed, or on cancel().
      play: function (lines) {
        // A new play replaces an unfinished one.
        if (current || resolvePlay) { this.cancel(); }
        var list = Array.isArray(lines) ? lines.filter(function (l) { return l && isLine(l.text); }) : [];
        if (list.length === 0) { return Promise.resolve(); }
        var myToken = ++token;
        return new Promise(function (resolve) {
          resolvePlay = resolve;
          startLine(list, 0, myToken, function () {
            if (myToken === token) { settle(); }
          });
        });
      },

      // Close the bubble, stop voices, clear timers, resolve the pending play.
      cancel: function () {
        token++;
        var line = current;
        current = null;
        if (line) {
          line.closed = true;
          clearLineTimers(line);
          showBubble(line.id, '');
        }
        voicesCancel();
        settle();
      },

      // Toggle hook (Req 11.14, 11.15).
      voicesChanged: function (on) {
        if (on === true) { return; } // the current line keeps its mode
        var line = current;
        if (!line || line.closed || line.mode !== 'voice') { return; }
        voicesCancel();
        toTimerMode(line);
      }
    };
  }

  // Keep the button label and aria-pressed in step with the setting, and
  // toggle on click (Req 11.8 to 11.10). The update is synchronous.
  function bindVoicesButton(button, voices, sequencer) {
    function isOn() {
      if (!voices) { return false; }
      if (typeof voices.isOn === 'function') { return voices.isOn() === true; }
      if (typeof voices.isEnabled === 'function') { return voices.isEnabled() === true; }
      return false;
    }
    function render() {
      var on = isOn();
      button.textContent = 'Voices: ' + (on ? 'on' : 'off');
      button.setAttribute('aria-pressed', on ? 'true' : 'false');
    }
    function toggle() {
      var next = !isOn();
      // Priming must happen synchronously inside the click.
      if (next && voices && typeof voices.gesture === 'function') {
        try { voices.gesture(); } catch (e) { /* ignore */ }
      }
      try {
        if (voices && typeof voices.setOn === 'function') { voices.setOn(next); }
        else if (voices && typeof voices.setEnabled === 'function') { voices.setEnabled(next); }
      } catch (e) { /* ignore */ }
      render();
      if (sequencer && typeof sequencer.voicesChanged === 'function') {
        try { sequencer.voicesChanged(isOn()); } catch (e) { /* ignore */ }
      }
    }
    button.addEventListener('click', toggle);
    render();
    return { render: render, toggle: toggle };
  }

  var api = {
    readingTime: readingTime,
    linesOfTurn: linesOfTurn,
    createSpeechSequencer: createSpeechSequencer,
    bindVoicesButton: bindVoicesButton
  };

  if (typeof window !== 'undefined') {
    window.readingTime = readingTime;
    window.linesOfTurn = linesOfTurn;
    window.createSpeechSequencer = createSpeechSequencer;
    window.bindVoicesButton = bindVoicesButton;
  }
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api;
  }
})();
