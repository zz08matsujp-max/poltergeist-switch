// Film a recorded episode with the chosen voices: the page's speech synthesis is replaced by a stand-in that holds each line
// for the length of its Kokoro audio (virtual clock), and logs when each line starts so the audio can be laid on the same frames.
const { chromium } = require('playwright');
const { spawn } = require('child_process');
const fs = require('fs');
const EP = process.argv[2] || '8', OUT = process.argv[3] || 'video/replay_P3-3_silent.mp4', TMP = OUT.replace(/\.mp4$/, '.part.mp4');
const LINES = JSON.parse(fs.readFileSync('tts/lines.json', 'utf8'));
const DUR = {}; for (const [k, v] of Object.entries(LINES)) DUR[k.split('\t')[1]] = v.secs;
(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1760, height: 990 }, deviceScaleFactor: 1920 / 1760 });
  const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
  await p.clock.install({ time: new Date('2026-11-02T05:14:00Z') });
  await p.addInitScript(DUR => {
    window.__speechLog = [];
    class U { constructor(text) { this.text = text; this.onend = null; this.onerror = null; this.voice = null; this.lang = ''; this.pitch = 1; this.rate = 1; this.volume = 1; } }
    const synth = { speaking: false, getVoices() { return []; }, cancel() {}, pause() {}, resume() {}, addEventListener() {}, removeEventListener() {}, onvoiceschanged: null,
      speak(u) { const t = (u.text || '').trim(); if (!t) { setTimeout(() => u.onend && u.onend({}), 0); return; }
        const d = DUR[u.text]; window.__speechLog.push({ text: u.text, t: performance.now(), d: d === undefined ? null : d });
        setTimeout(() => u.onend && u.onend({}), Math.round((d === undefined ? 2 : d) * 1000)); } };
    Object.defineProperty(window, 'speechSynthesis', { value: synth, configurable: true });
    window.SpeechSynthesisUtterance = U;
  }, DUR);
  await p.goto('http://127.0.0.1:8765/look.php?t=test', { waitUntil: 'networkidle' });
  await p.evaluate(() => document.fonts.ready);
  // while filming: no product names on screen (maintainer's decision, 2026-11-02) and a footnote that names the voices
  await p.evaluate((t) => { const a = document.getElementById('about'); if (a) a.style.display = 'none'; const n = document.querySelector('.note'); if (n) n.textContent = t; }, 'Look sample v0.5. Recorded replies from the turn-2 pilot (November 2, 2026); the highlighted words are what each action was read from. In this video the spoken lines are voiced with Kokoro-82M (Apache-2.0); thoughts stay silent.');
  await p.selectOption('#ep', EP); await p.evaluate(() => { document.activeElement.blur(); window.scrollTo(0, 0); });
  const now = await p.evaluate(() => Date.now()); await p.clock.pauseAt(now + 50);
  const t0 = await p.evaluate(() => performance.now());
  const ff = spawn('ffmpeg', ['-y', '-loglevel', 'error', '-f', 'image2pipe', '-framerate', '30', '-c:v', 'mjpeg', '-i', '-',
    '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p', TMP]);
  const done = new Promise(r => ff.on('close', r));
  const shot = async () => { const buf = await p.screenshot({ type: 'jpeg', quality: 92 }); if (!ff.stdin.write(buf)) await new Promise(r => ff.stdin.once('drain', r)); };
  let n = 0, endAt = -1;
  for (; n < 45; n++) { await p.clock.runFor(1000 / 30); await shot(); }
  await p.evaluate(() => { document.getElementById('rplay').click(); window.scrollTo(0, 0); });
  for (; n < 9000; n++) {
    await p.clock.runFor(1000 / 30); await shot();
    if (endAt < 0 && n % 15 === 0 && await p.evaluate(() => !document.getElementById('endcard').hidden)) { endAt = n; await p.evaluate(() => window.scrollTo(0, 0)); }
    if (endAt >= 0 && n - endAt >= 150) break;
  }
  ff.stdin.end(); const code = await done;
  const log = await p.evaluate(() => window.__speechLog);
  fs.writeFileSync(OUT.replace(/\.mp4$/, '.speech.json'), JSON.stringify({ t0, frames: n + 1, log }, null, 1));
  console.log('frames', n + 1, 'seconds', ((n + 1) / 30).toFixed(1), 'ffmpeg exit', code, 'lines', log.length, 'missing', log.filter(l => l.d === null).map(l => l.text.slice(0, 40)), 'errors', errs);
  if (code === 0) fs.renameSync(TMP, OUT);
  await b.close();
})();
