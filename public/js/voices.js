/*
 * voices.js — the Voices module (Screens spec, Requirements 3.11, 3.12, 3.13,
 * 3.4, 3.10; design "5. Voices module").
 *
 * Reads a turn's SPOKEN lines aloud with the browser's Web Speech API
 * (`window.speechSynthesis` + `SpeechSynthesisUtterance`), ported from the
 * pilot's speech code (pilot/look.php). Act_Out drives it: for each spoken line
 * it calls `voices.speak(renderId, line)` where renderId is `amber` / `jade` /
 * `man` (see act-out.js `speakAloud` / `showSpoken`). This module only ever
 * receives SPOKEN lines — Act_Out already filters thoughts out — so there is no
 * path here that could read a thought aloud (Req 3.13).
 *
 * Per-character voice, pitch, and rate (Req 3.11), matching the design:
 *   - amber: pitch 1.15, rate 1.02 (English preference list from the pilot)
 *   - jade:  pitch 1.05, rate 1.06
 *   - man:   pitch 0.70, rate 0.84 (older / slower)
 * A distinct English `SpeechSynthesisVoice` is chosen per character when the
 * browser offers enough; otherwise pitch/rate alone keep them distinguishable.
 *
 * A Japanese line (Hiragana / Katakana / CJK present) is read with a Japanese
 * voice when one is available, else the utterance falls back to `lang='ja-JP'`
 * (Req 3.12). Non-Japanese lines use the character's English voice, or
 * `lang='en-US'` as a last resort.
 *
 * Setting and gesture (Req 11.1, 11.3, 11.4, 11.14): the on/off setting
 * (`setOn/isOn`) defaults ON and is independent of the engine. `speak(id, line,
 * onDone)` returns a boolean: false (and touches no speechSynthesis) when the
 * setting is off, there is no engine, `gesture()` has not run, or the line is
 * blank. When true, `onDone` fires exactly once with 'end' | 'error' |
 * 'cancelled'. There is no "any click" unlock: controllers call `gesture()`
 * from a real click. `cancel()` stops reading at once and reports 'cancelled'
 * for every active or queued line. `setEnabled/isEnabled/on/off/unlock/stop`
 * remain as aliases. Lines queue FIFO in call order.
 *
 * `getVoices()` is often empty until the async `voiceschanged` event fires, so
 * voices are cached, re-resolved on `voiceschanged`, and resolved lazily at
 * speak time: a line spoken before voices load still works (lang-only
 * fallback). Every Web Speech call is guarded, so with no speechSynthesis the
 * module loads and every method is a safe no-op — it never throws.
 *
 * No build step on the server: plain browser JS. It attaches `createVoices`
 * (and `Voices`) to the browser global when a window exists, and exports via
 * CommonJS when a module system exists (so a headless check can require it),
 * matching position-map.js, house-renderer.js, and act-out.js.
 */
(function () {
  'use strict';

  // Per-character profiles (Req 3.11). Keys are the renderIds Act_Out passes
  // (amber / jade / man). `names` is an ordered English voice preference list
  // ported from the pilot; the first available, not-yet-used match wins so the
  // three characters get distinct voices when the browser offers enough.
  var PROFILES = {
    amber: {
      names: ['Samantha', 'Google US English', 'Microsoft Aria', 'Microsoft Jenny', 'Microsoft Zira', 'Karen', 'Moira', 'Tessa'],
      pitch: 1.15, rate: 1.02
    },
    jade: {
      names: ['Daniel', 'Google UK English Male', 'Microsoft Guy', 'Microsoft Ryan', 'Microsoft Mark', 'Aaron', 'Arthur', 'Alex'],
      pitch: 1.05, rate: 1.06
    },
    man: {
      names: ['Grandpa', 'Ralph', 'Microsoft David', 'Fred', 'Google UK English Male', 'Reed', 'Rocko'],
      pitch: 0.70, rate: 0.84
    }
  };

  // Resolve per-character voices in this order so the older man gets first pick
  // of his list before the robots claim shared English voices (as in the pilot).
  var RESOLVE_ORDER = ['man', 'amber', 'jade'];

  // Japanese detection: any Hiragana, Katakana, or common CJK ideograph.
  var JA = /[\u3040-\u30ff\u4e00-\u9fff]/;

  // Preferred Japanese voice names, best first (ported from the pilot).
  var JA_NAMES = ['Kyoko', 'Otoya', 'Google \u65e5\u672c\u8a9e', 'Microsoft Nanami', 'Microsoft Haruka', 'Microsoft Ayumi'];

  function hasSpeech() {
    return typeof window !== 'undefined' &&
      'speechSynthesis' in window &&
      typeof window.SpeechSynthesisUtterance !== 'undefined' &&
      window.speechSynthesis;
  }

  var CHUNK_MAX = 180;

  // Split a line into Spoken_Chunks (Req 17.1-17.3): cut after . ! ? or an
  // ellipsis followed by whitespace or the end; cut any chunk over 180
  // characters after ", " or "; " (packed greedily), else at the last space
  // before 180 (a hard cut at 180 when there is no space). Blanks are dropped.
  function splitForSpeech(text) {
    if (typeof text !== 'string') { return []; }
    var sentences = [], re = /[\s\S]*?[.!?\u2026](?=\s|$)/g, m, last = 0, t;
    while ((m = re.exec(text)) !== null) {
      if (m[0].length === 0) { re.lastIndex++; continue; }
      t = m[0].trim(); if (t) { sentences.push(t); }
      last = re.lastIndex;
    }
    t = text.slice(last).trim(); if (t) { sentences.push(t); }

    var out = [];
    sentences.forEach(function (sen) {
      if (sen.length <= CHUNK_MAX) { out.push(sen); return; }
      var parts = [], pre = /[\s\S]*?[,;](?=\s)/g, pm, pl = 0;
      while ((pm = pre.exec(sen)) !== null) {
        if (pm[0].trim()) { parts.push(pm[0].trim()); }
        pl = pre.lastIndex;
      }
      if (sen.slice(pl).trim()) { parts.push(sen.slice(pl).trim()); }
      var cur = '';
      parts.forEach(function (part) {
        if (cur && cur.length + 1 + part.length <= CHUNK_MAX) { cur += ' ' + part; return; }
        if (cur) { out.push(cur); cur = ''; }
        while (part.length > CHUNK_MAX) {
          var cut = part.lastIndexOf(' ', CHUNK_MAX);
          if (cut <= 0) { cut = CHUNK_MAX; }
          var head = part.slice(0, cut).trim();
          if (head) { out.push(head); }
          part = part.slice(cut).trim();
        }
        cur = part;
      });
      if (cur) { out.push(cur); }
    });
    return out;
  }

  function createVoices(options) {
    var opts = options || {};
    var synth = hasSpeech() ? window.speechSynthesis : null;

    // The setting defaults ON (Req 11.1) and is independent of the engine;
    // speaking additionally needs an engine and a prior gesture().
    var enabled = opts.enabled !== false;

    var voiceMap = {};      // renderId -> chosen English SpeechSynthesisVoice
    var voiceJa = null;     // chosen Japanese SpeechSynthesisVoice
    var voicesLoaded = false;
    var gestured = false;

    // Timers, clock and listener targets are injectable for tests.
    var tm = opts.timers || {};
    function setT(fn, ms) { return (tm.setTimeout || setTimeout)(fn, ms); }
    function clearT(id) { return (tm.clearTimeout || clearTimeout)(id); }
    var now = typeof opts.now === 'function' ? opts.now : Date.now;
    var docT = opts.document || (typeof document !== 'undefined' ? document : null);
    var winT = opts.window || (typeof window !== 'undefined' ? window : null);

    // Resolve the per-character and Japanese voices from the current voice list.
    // Safe to call repeatedly (on voiceschanged); non-throwing.
    function pickVoices() {
      if (!synth) { return; }
      var all;
      try { all = synth.getVoices() || []; } catch (e) { all = []; }
      var en = [], ja = [], i;
      for (i = 0; i < all.length; i++) {
        var lang = all[i] && all[i].lang ? String(all[i].lang) : '';
        if (/^en/i.test(lang)) { en.push(all[i]); }
        else if (/^ja/i.test(lang)) { ja.push(all[i]); }
      }

      // Japanese voice: first preferred match, else the first ja-* available.
      voiceJa = null;
      for (var n = 0; n < JA_NAMES.length && !voiceJa; n++) {
        for (var j = 0; j < ja.length; j++) {
          if (ja[j].name && ja[j].name.indexOf(JA_NAMES[n]) === 0) { voiceJa = ja[j]; break; }
        }
      }
      if (!voiceJa && ja.length) { voiceJa = ja[0]; }

      // English voices: give each character a distinct voice where possible.
      voiceMap = {};
      if (en.length) {
        var used = {};
        RESOLVE_ORDER.forEach(function (k) {
          var names = PROFILES[k].names, v = null, p, q;
          for (p = 0; p < names.length && !v; p++) {
            for (q = 0; q < en.length; q++) {
              if (!used[en[q].name] && en[q].name && en[q].name.indexOf(names[p]) === 0) { v = en[q]; break; }
            }
          }
          if (!v) { for (q = 0; q < en.length; q++) { if (!used[en[q].name]) { v = en[q]; break; } } }
          if (!v) { v = en[0]; }
          if (v && v.name) { used[v.name] = true; }
          voiceMap[k] = v;
        });
      }

      if (all.length) { voicesLoaded = true; }
    }

    // Prime voices now and re-resolve when the browser loads them later.
    if (synth) {
      pickVoices();
      try {
        if (synth.addEventListener) { synth.addEventListener('voiceschanged', pickVoices); }
        else { synth.onvoiceschanged = pickVoices; }
      } catch (e) { /* ignore */ }

      // Chrome stalls backgrounded speech: resume when the page is shown or
      // focused again (Req 17.7). Installed once, only with an engine.
      try {
        if (docT && docT.addEventListener) {
          docT.addEventListener('visibilitychange', function () {
            if (docT.visibilityState === 'visible') { resumeNow(); }
          });
        }
        if (winT && winT.addEventListener) {
          winT.addEventListener('focus', function () { resumeNow(); });
        }
      } catch (e) { /* ignore */ }
    }

    // gesture(): call from a real user gesture (a click on Start or Voices).
    // Primes speechSynthesis (browser autoplay rule) and allows speak(). There
    // is no "any click" listener; only controllers call this (Req 11.3).
    function gesture() {
      if (!synth) { return; }
      var first = !gestured;
      gestured = true;
      if (!first) { return; }
      if (!voicesLoaded) { pickVoices(); }
      try {
        var u = new window.SpeechSynthesisUtterance(' ');
        u.volume = 0;
        synth.speak(u);
      } catch (e) { /* best-effort priming */ }
    }

    // ---- Chunked speaking and keep-alive (Requirement 17) -----------------
    // `queue` holds the lines not yet done, in call order. Each entry is
    // { id, text, chunks:[{text, u, issued, finished}], index, onDone, done,
    //   wd, ka }. Every chunk is one utterance; all chunks of all lines are
    // handed to the engine in order (it queues FIFO), and the entry advances
    // `index` as chunks end. Only the head entry is watched (watchdog and
    // keep-alive tick), because only it can be read aloud.
    var queue = [];
    var lastCancelAt = -Infinity;
    var epoch = 0;          // bumped by cancel(); abandons deferred speaks
    var flushTimer = null;

    function complete(entry, reason) {
      if (entry.done) { return; }
      entry.done = true;
      clearEntryTimers(entry);
      var idx = queue.indexOf(entry);
      if (idx >= 0) { queue.splice(idx, 1); }
      if (typeof entry.onDone === 'function') {
        try { entry.onDone(reason); } catch (e) { /* caller errors never break us */ }
      }
    }

    function resumeIfPaused() {
      if (!synth) { return; }
      try { if (synth.paused) { synth.resume(); } } catch (e) { /* ignore */ }
    }

    function resumeNow() {
      if (!synth) { return; }
      try { synth.resume(); } catch (e) { /* ignore */ }
    }

    function clearEntryTimers(entry) {
      if (entry.wd !== null && entry.wd !== undefined) { clearT(entry.wd); entry.wd = null; }
      if (entry.ka !== null && entry.ka !== undefined) { clearT(entry.ka); entry.ka = null; }
    }

    // Build one utterance for a chunk with the line's voice, lang, pitch, rate.
    function makeUtterance(entry, chunk) {
      var u = new window.SpeechSynthesisUtterance(chunk.text);
      if (JA.test(entry.text)) {
        if (voiceJa) { u.voice = voiceJa; u.lang = voiceJa.lang; }
        else { u.lang = 'ja-JP'; }
      } else {
        var v = voiceMap[entry.id];
        if (v) { u.voice = v; u.lang = v.lang; }
        else { u.lang = 'en-US'; }
      }
      u.pitch = entry.profile.pitch;
      u.rate = entry.profile.rate;
      // Handlers only count while this utterance is the chunk's live one.
      u.onend = function () { chunkEvent(entry, chunk, u, 'end'); };
      u.onerror = function () { chunkEvent(entry, chunk, u, 'error'); };
      chunk.u = u;   // kept referenced (some engines drop unheld utterances)
      return u;
    }

    // Hand every not-yet-issued chunk to the engine, in order. Never within
    // 150 ms of our own cancel(): defer for the remainder, abandon if a newer
    // cancel() ran meanwhile.
    function flush() {
      if (!synth || flushTimer !== null) { return; }
      var wait = 150 - (now() - lastCancelAt);
      if (wait > 0) {
        var myEpoch = epoch;
        flushTimer = setT(function () {
          flushTimer = null;
          if (myEpoch !== epoch) { return; }
          flush();
        }, wait);
        return;
      }
      for (var i = 0; i < queue.length; i++) {
        var entry = queue[i];
        for (var c = entry.index; c < entry.chunks.length; c++) {
          var chunk = entry.chunks[c];
          if (chunk.issued || chunk.finished) { continue; }
          if (!chunk.u) {
            try { makeUtterance(entry, chunk); }
            catch (e) { chunkEvent(entry, chunk, null, 'error', true); return; }
          }
          resumeIfPaused();
          chunk.issued = true;
          try { synth.speak(chunk.u); }
          catch (e) { chunkEvent(entry, chunk, chunk.u, 'error'); return; }
        }
      }
    }

    // A chunk reached an end: 'end' goes on, 'error' ends the line (remaining
    // chunks are dropped). `force` skips the stale-utterance check.
    function chunkEvent(entry, chunk, u, how, force) {
      if (entry.done || chunk.finished) { return; }
      if (!force && chunk.u !== u) { return; }
      chunk.finished = true;
      chunk.u = null;
      if (entry === queue[0]) { clearEntryTimers(entry); }
      if (how === 'error') { complete(entry, 'error'); arm(); return; }
      advance(entry);
    }

    function advance(entry) {
      entry.index++;
      if (entry.index >= entry.chunks.length) { complete(entry, 'end'); }
      else if (entry === queue[0]) { clearEntryTimers(entry); }
      arm();
    }

    // Start the watchdog and keep-alive tick for the head entry's current chunk.
    function arm() {
      var entry = queue[0];
      if (!entry || entry.done) { return; }
      if (entry.wd !== null || entry.ka !== null) { return; }
      var chunk = entry.chunks[entry.index];
      if (!chunk || chunk.finished) { return; }
      var T = 2000 + 120 * chunk.text.length / (entry.profile.rate || 1);

      entry.ka = setT(function tick() {
        if (entry.done || entry !== queue[0] || entry.chunks[entry.index] !== chunk || chunk.finished) { return; }
        resumeIfPaused();
        entry.ka = setT(tick, 5000);
      }, 5000);

      entry.wd = setT(function () {
        entry.wd = null;
        if (entry.done || chunk.finished) { return; }
        var speaking = false;
        try { speaking = synth.speaking === true; } catch (e) { speaking = false; }
        if (!speaking) {
          // The engine says it is idle: treat the chunk as ended.
          chunk.finished = true; chunk.u = null;
          clearEntryTimers(entry);
          advance(entry);
          return;
        }
        // Still speaking: wait until 2T from the chunk's start, then cancel it.
        entry.wd = setT(function () {
          entry.wd = null;
          if (entry.done || chunk.finished) { return; }
          chunk.finished = true; chunk.u = null;
          clearEntryTimers(entry);
          // synth.cancel() drops every queued utterance: mark them not issued
          // so flush() re-issues the rest after the 150 ms gap. The line
          // continues; this is not reported as an error.
          queue.forEach(function (e) {
            e.chunks.forEach(function (ch) {
              if (ch !== chunk && !ch.finished && ch.issued) { ch.issued = false; ch.u = null; }
            });
          });
          lastCancelAt = now();
          try { synth.cancel(); } catch (e) { /* ignore */ }
          advance(entry);
          flush();
        }, T);
      }, T);
    }

    // Speak one SPOKEN line (Req 3.11, 3.12, 11.3, 11.4, 11.14). Returns true
    // when the line was accepted; then onDone fires exactly once with 'end' |
    // 'error' | 'cancelled'. Returns false (onDone NOT called, speechSynthesis
    // untouched) when the setting is off, there is no engine, gesture() has not
    // run, or the line is blank. Lines queue FIFO.
    function speak(id, line, onDone) {
      if (!enabled || !synth || !gestured) { return false; }
      if (!id || typeof line !== 'string') { return false; }
      var text = line.trim();
      if (!text) { return false; }
      var profile = PROFILES[id];
      if (!profile) { return false; }

      if (!voicesLoaded) { pickVoices(); }

      var parts = splitForSpeech(text);
      if (!parts.length) { return false; }
      var entry = {
        id: id, text: text, profile: profile, onDone: onDone, done: false,
        index: 0, wd: null, ka: null,
        chunks: parts.map(function (t) { return { text: t, u: null, issued: false, finished: false }; })
      };
      try { makeUtterance(entry, entry.chunks[0]); }
      catch (e) { return false; }
      for (var k = 1; k < entry.chunks.length; k++) {
        try { makeUtterance(entry, entry.chunks[k]); } catch (e) { return false; }
      }

      queue.push(entry);
      flush();
      arm();
      return true;
    }

    // Stop reading now and report 'cancelled' for every active or queued line.
    // Entries are completed before synth.cancel() so the engine's own error
    // events cannot relabel them. Records the time so no speak follows within
    // 150 ms. Non-throwing.
    function cancel() {
      epoch++;
      if (flushTimer !== null) { clearT(flushTimer); flushTimer = null; }
      var busy = queue.length > 0;
      queue.slice().forEach(function (e) {
        e.chunks.forEach(function (ch) { ch.finished = true; ch.u = null; });
        complete(e, 'cancelled');
      });
      if (!synth) { return; }
      // The 150 ms gap only matters when something was being read or queued;
      // a cancel on an idle engine starts none.
      try { if (synth.speaking || synth.pending) { busy = true; } } catch (e) { /* ignore */ }
      if (busy) { lastCancelAt = now(); }
      try { synth.cancel(); } catch (e) { /* ignore */ }
    }

    // The setting (Req 11.1), independent of the engine. Turning it off stops
    // anything being read.
    function setOn(v) {
      enabled = (v === true);
      if (!enabled) { cancel(); }
    }

    function isOn() { return enabled; }

    return {
      speak: speak,
      setOn: setOn,
      isOn: isOn,
      gesture: gesture,
      cancel: cancel,
      // aliases kept for existing callers
      setEnabled: setOn,
      isEnabled: isOn,
      on: function () { setOn(true); },
      off: function () { setOn(false); },
      unlock: gesture,
      stop: cancel,
      // whether a real speech engine is present
      available: !!synth,
      splitForSpeech: splitForSpeech
    };
  }

  // Class-style wrapper for callers that prefer `new`, mirroring the other
  // browser modules.
  function Voices(options) {
    var impl = createVoices(options);
    var self = this;
    Object.keys(impl).forEach(function (k) { self[k] = impl[k]; });
  }

  createVoices.splitForSpeech = splitForSpeech;
  Voices.splitForSpeech = splitForSpeech;

  if (typeof window !== 'undefined') {
    window.createVoices = createVoices;
    window.Voices = Voices;
  }
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = { createVoices: createVoices, Voices: Voices, splitForSpeech: splitForSpeech };
  }
})();
