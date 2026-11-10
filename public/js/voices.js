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
    var active = [];        // lines handed to the engine and not yet done

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

    // Finish one active line exactly once and report why.
    function complete(entry, reason) {
      if (entry.done) { return; }
      entry.done = true;
      var idx = active.indexOf(entry);
      if (idx >= 0) { active.splice(idx, 1); }
      if (typeof entry.onDone === 'function') {
        try { entry.onDone(reason); } catch (e) { /* caller errors never break us */ }
      }
    }

    // Speak one SPOKEN line (Req 3.11, 3.12, 11.3, 11.4, 11.14). Returns true
    // when the line was handed to the engine; then onDone fires exactly once
    // with 'end' | 'error' | 'cancelled'. Returns false (onDone NOT called,
    // speechSynthesis untouched) when the setting is off, there is no engine,
    // gesture() has not run, or the line is blank. Lines queue FIFO.
    function speak(id, line, onDone) {
      if (!enabled || !synth || !gestured) { return false; }
      if (!id || typeof line !== 'string') { return false; }
      var text = line.trim();
      if (!text) { return false; }
      var profile = PROFILES[id];
      if (!profile) { return false; }

      if (!voicesLoaded) { pickVoices(); }

      var u;
      try { u = new window.SpeechSynthesisUtterance(text); }
      catch (e) { return false; }

      if (JA.test(text)) {
        if (voiceJa) { u.voice = voiceJa; u.lang = voiceJa.lang; }
        else { u.lang = 'ja-JP'; }
      } else {
        var v = voiceMap[id];
        if (v) { u.voice = v; u.lang = v.lang; }
        else { u.lang = 'en-US'; }
      }
      u.pitch = profile.pitch;
      u.rate = profile.rate;

      // The entry keeps the utterance referenced until it is done (some
      // engines drop unheld utterances to GC mid-speech).
      var entry = { u: u, onDone: onDone, done: false };
      u.onend = function () { complete(entry, 'end'); };
      u.onerror = function () { complete(entry, 'error'); };
      active.push(entry);

      try { synth.speak(u); }
      catch (e) { complete(entry, 'error'); }
      return true;
    }

    // Stop reading now and report 'cancelled' for every active or queued line.
    // Entries are completed before synth.cancel() so the engine's own error
    // events cannot relabel them. Non-throwing.
    function cancel() {
      active.slice().forEach(function (e) { complete(e, 'cancelled'); });
      if (!synth) { return; }
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
