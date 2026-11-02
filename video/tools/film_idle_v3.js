// Film the ordinary evening (no switch, no replay) in the same frame as the replay: 1760x990 -> 1920x1080, 30 fps.
const { chromium } = require('playwright');
const { spawn } = require('child_process');
const fs = require('fs');
const SECS = +(process.argv[2] || 16), OUT = process.argv[3] || 'draft/idle.mp4', TMP = OUT.replace(/\.mp4$/, '.part.mp4');
(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1760, height: 990 }, deviceScaleFactor: 1920 / 1760 });
  const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
  await p.clock.install({ time: new Date('2026-11-02T05:14:00Z') });
  await p.goto('http://127.0.0.1:8765/look.php?t=test', { waitUntil: 'networkidle' });
  await p.evaluate(() => document.fonts.ready);
  // while filming: no product names on screen (maintainer's decision, 2026-11-02)
  await p.evaluate((t) => { const a = document.getElementById('about'); if (a) a.style.display = 'none'; const n = document.querySelector('.note'); if (n) n.textContent = t; }, 'Look sample v0.5. Recorded replies from the turn-2 pilot (November 2, 2026); the highlighted words are what each action was read from. In this video the spoken lines are voiced with Kokoro-82M (Apache-2.0); thoughts stay silent.');
  await p.evaluate(() => { document.activeElement.blur(); window.scrollTo(0, 0); });
  const now = await p.evaluate(() => Date.now()); await p.clock.pauseAt(now + 1500);
  const ff = spawn('ffmpeg', ['-y', '-loglevel', 'error', '-f', 'image2pipe', '-framerate', '30', '-c:v', 'mjpeg', '-i', '-',
    '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p', TMP]);
  const done = new Promise(r => ff.on('close', r));
  for (let n = 0; n < SECS * 30; n++) { await p.clock.runFor(1000 / 30); const buf = await p.screenshot({ type: 'jpeg', quality: 92 }); if (!ff.stdin.write(buf)) await new Promise(r => ff.stdin.once('drain', r)); }
  ff.stdin.end(); const code = await done; console.log('idle', SECS, 's exit', code, 'errors', errs);
  if (code === 0) fs.renameSync(TMP, OUT); await b.close();
})();
