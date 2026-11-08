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
 * On/off (Req 3.4, 3.10): when Voices are ON, lines are spoken in the order
 * `speak()` is called (speechSynthesis queues FIFO, so ordering is preserved
 * without cancelling prior utterances within a turn). When OFF, `speak()` is a
 * no-op and nothing is read aloud. DEFAULT is OFF — the design did not state an
 * explicit default, and the Observe/Replay "Voices on/off" control turns it on;
 * starting OFF respects the browser autoplay rule and avoids speech before the
 * first user gesture. (Flagged as an interpretation in the task report.)
 *
 * Playback is unlocked on the first user gesture (browser autoplay rule): an
 * `unlock()` method primes speechSynthesis, and a one-time click/keydown/touch
 * listener is auto-attached so later `speak()` calls are audible. Controllers
 * may also call `unlock()` on their first play gesture.
 *
 * `cancel()` clears the queue so a controller can interrupt between TURNS; it is
 * never called automatically within a turn's ordered lines.
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

  function createVoices(options) {
    var opts = options || {};
    var synth = hasSpeech() ? window.speechSynthesis : null;

    // Default OFF (see header note). When there is no speech engine at all,
    // enabled stays false and every speak() is naturally a no-op.
    var enabled = (opts.enabled === true) && !!synth;

    var voiceMap = {};      // renderId -> chosen English SpeechSynthesisVoice
    var voiceJa = null;     // chosen Japanese SpeechSynthesisVoice
    var voicesLoaded = false;
    var unlocked = false;
    var keepU = [];         // hold references so GC doesn't drop live utterances

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
    }

    // Prime speechSynthesis on a user gesture so later speak() calls are
    // audible (browser autoplay rule). Idempotent and guarded.
    function unlock() {
      if (unlocked || !synth) { return; }
      unlocked = true;
      // Re-resolve in case voices loaded between construction and first gesture.
      if (!voicesLoaded) { pickVoices(); }
      try {
        var u = new window.SpeechSynthesisUtterance(' ');
        u.volume = 0;
        synth.speak(u);
      } catch (e) { /* best-effort priming */ }
    }

    // Auto-attach a one-time gesture listener so a user click/keydown/touch
    // unlocks playback even if no controller calls unlock() explicitly.
    if (synth && typeof window !== 'undefined' && window.addEventListener && opts.autoUnlock !== false) {
      var onGesture = function () {
        unlock();
        try {
          window.removeEventListener('click', onGesture, true);
          window.removeEventListener('keydown', onGesture, true);
          window.removeEventListener('touchstart', onGesture, true);
        } catch (e) { /* ignore */ }
      };
      try {
        window.addEventListener('click', onGesture, true);
        window.addEventListener('keydown', onGesture, true);
        window.addEventListener('touchstart', onGesture, true);
      } catch (e) { /* ignore */ }
    }

    // Speak one SPOKEN line for a character (Req 3.4, 3.10, 3.11, 3.12). A
    // no-op when Voices are off or there is no speech engine. Does NOT cancel
    // prior utterances, so lines queue FIFO and are spoken in call order.
    function speak(id, line) {
      if (!enabled || !synth) { return; }
      if (!id || typeof line !== 'string') { return; }
      var text = line.trim();
      if (!text) { return; }
      var profile = PROFILES[id];
      if (!profile) { return; }

      // Voices may have loaded since construction; resolve lazily so an early
      // line still gets the right voice once available.
      if (!voicesLoaded) { pickVoices(); }

      var u;
      try { u = new window.SpeechSynthesisUtterance(text); }
      catch (e) { return; }

      if (JA.test(text)) {
        // Japanese line: a Japanese voice when available, else ja-JP (Req 3.12).
        if (voiceJa) { u.voice = voiceJa; u.lang = voiceJa.lang; }
        else { u.lang = 'ja-JP'; }
      } else {
        var v = voiceMap[id];
        if (v) { u.voice = v; u.lang = v.lang; }
        else { u.lang = 'en-US'; }
      }
      u.pitch = profile.pitch;
      u.rate = profile.rate;

      // Hold a reference until the utterance ends (some engines drop unheld
      // utterances to GC mid-speech); release on end/error or a safety timeout.
      keepU.push(u);
      var done = false;
      function fin() {
        if (done) { return; }
        done = true;
        var idx = keepU.indexOf(u);
        if (idx >= 0) { keepU.splice(idx, 1); }
      }
      u.onend = fin;
      u.onerror = fin;
      try { setTimeout(fin, 2500 + text.length * 110); } catch (e) { /* ignore */ }

      try { synth.speak(u); } catch (e) { fin(); }
    }

    // Clear the queue (controllers call this to interrupt between TURNS; it is
    // never called automatically within a turn's ordered lines). Non-throwing.
    function cancel() {
      keepU = [];
      if (!synth) { return; }
      try { synth.cancel(); } catch (e) { /* ignore */ }
    }

    function setEnabled(on) {
      enabled = (on === true) && !!synth;
      if (!enabled) { cancel(); }
    }

    function isEnabled() { return enabled; }

    return {
      speak: speak,
      setEnabled: setEnabled,
      isEnabled: isEnabled,
      on: function () { setEnabled(true); },
      off: function () { setEnabled(false); },
      unlock: unlock,
      cancel: cancel,
      stop: cancel,
      // test/diagnostic aid: whether a real speech engine is present.
      available: !!synth
    };
  }

  // Class-style wrapper for callers that prefer `new`, mirroring the other
  // browser modules.
  function Voices(options) {
    var impl = createVoices(options);
    var self = this;
    Object.keys(impl).forEach(function (k) { self[k] = impl[k]; });
  }

  if (typeof window !== 'undefined') {
    window.createVoices = createVoices;
    window.Voices = Voices;
  }
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = { createVoices: createVoices, Voices: Voices };
  }
})();
