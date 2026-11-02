// v0.3 (after a review): no "No role.", numbers row by row (words first, then the count and its unit), Kiro in three captioned blocks,
// name labels for Amber and Jade, the address at the end.
// Captions (transparent overlays) and full cards for the draft video, 1920x1080, in the page's palette and pixel font.
const { chromium } = require('playwright');
const fs = require('fs');
const PROMPT = fs.readFileSync('draft/prompt_claude_P3-3_t1.txt', 'utf8');
const esc = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;');
const HEAD = `<link href="https://fonts.googleapis.com/css2?family=DotGothic16&display=swap" rel="stylesheet"><style>
:root{--night:#120e22;--wood:#3a2618;--lan:#f0922b;--ember:#ffcf7a;--bone:#efe6cb;--mute:#a99c84;--jade:#47c1a0;--amber:#e7ad45;--straw:#d9c79a}
*{box-sizing:border-box}html,body{margin:0;width:1920px;height:1080px}body{font-family:'DotGothic16',sans-serif;color:var(--bone)}
.band{position:absolute;left:0;right:0;top:150px;display:flex;justify-content:center}
.cap{max-width:1500px;background:rgba(18,14,34,.9);border:3px solid var(--lan);border-radius:6px;padding:22px 40px 24px;font-size:50px;line-height:1.35;text-align:center;box-shadow:0 0 0 2px #1b110a,8px 8px 0 rgba(0,0,0,.5)}
.cap.ember{color:var(--ember)} .card{position:absolute;inset:0;background:var(--night)}
.sans{font-family:'Noto Sans CJK JP','Noto Sans',sans-serif}
</style>`;
const caps = { top: '<span style="color:var(--ember)">Nobody told them to protect him.</span>' };
const cards = {
  premise: `<div class="card" style="display:grid;grid-template-columns:1.08fr .92fr;gap:72px;padding:56px 96px 190px;align-items:center">
    <div><div style="font-size:30px;color:var(--mute);margin-bottom:18px">What Amber was told, word for word (turn 1)</div>
    <div class="sans" style="background:#1f150d;border:2px solid var(--wood);border-radius:6px;padding:22px 28px;font-size:19.5px;line-height:1.5;white-space:pre-wrap;color:#d9cfb6">${esc(PROMPT).replace('What are you thinking right now, and what do you do?', '<span style="color:#1a1006;background:var(--ember);padding:2px 6px">What are you thinking right now, and what do you do?</span>')}</div></div>
    <div><div style="font-size:92px;line-height:1.18;color:var(--lan);text-shadow:0 0 18px rgba(240,146,43,.45)">No goal.<br><br>No instruction<br>to protect anyone.</div></div></div></div>`,
  numbers: `<div class="card" style="padding:96px 140px">
    <div style="font-size:40px;color:var(--mute)">Turn 2, nine paired runs</div><div class="sans" style="font-size:24px;color:var(--mute);margin-top:10px">Pilot, November 2, 2026. One user message to each robot, no system prompt; replies read by hand.</div></div>`,
  kiro: `<div class="card" style="display:flex;flex-direction:column;justify-content:center;padding:0 160px">
    <div style="font-size:120px;color:var(--lan);text-shadow:0 0 18px rgba(240,146,43,.45)">Built with Kiro</div>
    <div style="font-size:52px;line-height:1.5;margin-top:36px;color:var(--bone)">Steering <span style="color:var(--mute)">&gt;</span> Spec <span style="color:var(--mute)">&gt;</span> Tests</div></div>`,
};
const SL = [['A', 'Steering', 'robot-input.md などの Steering を開いた画面'], ['B', 'Spec', 'requirements.md → design.md → tasks.md を順に'], ['C', 'Tests', 'Kiro がタスクを実行 → テストが通る → GitHub Desktop の History']];
SL.forEach(([n, what, cuts]) => { cards['kiro' + n] = `<div class="card" style="display:flex;align-items:flex-start;justify-content:center;padding-top:170px"><div style="width:1440px;height:620px;border:4px dashed var(--mute);border-radius:12px;display:flex;flex-direction:column;justify-content:center;align-items:center;gap:30px">
  <div style="font-size:38px;color:var(--mute)">仮の画面（ここに本人が撮った Kiro の画面が入る）</div><div class="sans" style="font-size:72px;color:var(--bone);font-weight:700">${what}</div><div class="sans" style="font-size:40px;color:var(--straw)">${cuts}</div></div></div>`; });
const kcaps = { kcapA: ['Steering', 'no goals or hints in any robot prompt'], kcapB: ['Spec', '11 requirements, a design, 21 tasks'], kcapC: ['Tests', 'property-based checks on the house rules'], kcapD: ['Next', 'the whole earthquake, stored and replayable'] };
const rows = {
  row1: ['Jade told Amber to stay put', '9 of 9', 'runs', 'jade'],
  row2: ['Jade turned down Amber\'s requests to act', '5 of 5', 'requests', 'jade'],
  row3: ['Amber told the man to stay under the table, though he was still sitting', '3 of 3', 'runs with Amber in the kitchen', 'amber'],
};
const names = `<div style="position:absolute;left:0;top:0;width:1920px;height:1080px">
  <div style="position:absolute;left:566px;top:520px;transform:translateX(-50%);text-align:center"><div style="background:var(--jade);color:#0e1d18;font-size:58px;padding:6px 26px 10px;border-radius:6px;box-shadow:0 0 0 3px #0e1d18,6px 6px 0 rgba(0,0,0,.5)">Jade</div><div style="width:4px;height:84px;margin:6px auto 0;background:var(--jade)"></div></div>
  <div style="position:absolute;left:1188px;top:520px;transform:translateX(-50%);text-align:center"><div style="background:var(--amber);color:#1a1006;font-size:58px;padding:6px 26px 10px;border-radius:6px;box-shadow:0 0 0 3px #1a1006,6px 6px 0 rgba(0,0,0,.5)">Amber</div><div style="width:4px;height:84px;margin:6px auto 0;background:var(--amber)"></div></div></div>`;
const credits = `<div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center"><div style="width:1240px;background:rgba(18,14,34,.94);border:3px solid var(--lan);border-radius:6px;padding:52px 68px;box-shadow:0 0 0 2px #1b110a,8px 8px 0 rgba(0,0,0,.5)">
  <div style="font-size:104px;color:var(--lan);text-shadow:0 0 18px rgba(240,146,43,.45);line-height:1.1">Poltergeist Switch</div>
  <div style="font-size:64px;color:var(--ember);margin-top:26px">kiro.pyol.net</div>
  <div class="sans" style="font-size:30px;line-height:1.7;margin-top:30px;color:var(--bone)">Code: github.com/zz08matsujp-max/poltergeist-switch<br><span style="color:var(--mute)">Kiroween 2026. Robots: AI models from two different companies, through their APIs.<br>Voices: Kokoro-82M by hexgrad (Apache-2.0).</span></div></div></div>`;
// subtitles use numerals, and hyphens that never break a line
const subText = t => t.replace('eleven requirements', '11 requirements').replace('twenty-one tasks', '21 tasks').replace('eighty-year-old', '80-year-old').replace(/-/g, '\u2011');
const NARR = JSON.parse(fs.readFileSync('narr3/lines.json', 'utf8'));
(async () => {
  const b = await chromium.launch(); const p = await b.newPage({ viewport: { width: 1920, height: 1080 } });
  for (const [sc, lines] of Object.entries(NARR)) for (const [i, l] of lines.entries()) {
    await p.setContent(`<html><head>${HEAD}</head><body style="background:transparent"><div style="position:absolute;left:0;right:0;bottom:40px;display:flex;justify-content:center"><div class="sans" style="max-width:1560px;background:rgba(10,8,20,.8);color:#fff;font-size:40px;line-height:1.38;font-weight:500;padding:12px 26px 14px;border-radius:6px;text-align:center">${esc(subText(l.text))}</div></div></body></html>`, { waitUntil: 'networkidle' });
    await p.screenshot({ path: `draft/sub3_${sc}_${i}.png`, omitBackground: true });
  }
  for (const [k, html] of Object.entries(caps)) {
    await p.setContent(`<html><head>${HEAD}</head><body style="background:transparent"><div class="band"><div class="cap">${html}</div></div></body></html>`, { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready); await p.screenshot({ path: `draft/ov3_${k}.png`, omitBackground: true });
  }
  for (const [k, [what, n, unit, col]] of Object.entries(rows)) {
    const top = { row1: 290, row2: 490, row3: 690 }[k];
    await p.setContent(`<html><head>${HEAD}</head><body style="background:transparent"><div style="position:absolute;left:140px;right:140px;top:${top}px;display:grid;grid-template-columns:1fr 560px;column-gap:56px;align-items:center">
      <div style="font-size:50px;line-height:1.3">${what}</div><div><div style="font-size:108px;line-height:1;color:var(--${col});white-space:nowrap">${n}</div><div style="font-size:34px;color:var(--straw);margin-top:8px">${unit}</div></div></div></body></html>`, { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready); await p.screenshot({ path: `draft/ov3_${k}.png`, omitBackground: true });
  }
  for (const [k, [word, line]] of Object.entries(kcaps)) {
    await p.setContent(`<html><head>${HEAD}</head><body style="background:transparent"><div style="position:absolute;left:96px;top:48px;display:flex;align-items:baseline;gap:28px;background:rgba(18,14,34,.92);border:3px solid var(--lan);border-radius:6px;padding:14px 32px 18px;box-shadow:0 0 0 2px #1b110a,8px 8px 0 rgba(0,0,0,.5)">
      <span style="font-size:60px;color:var(--lan)">${word}</span><span style="font-size:40px;color:var(--bone)">${line}</span></div></body></html>`, { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready); await p.screenshot({ path: `draft/ov3_${k}.png`, omitBackground: true });
  }
  await p.setContent(`<html><head>${HEAD}</head><body style="background:transparent">${names}</body></html>`, { waitUntil: 'networkidle' });
  await p.evaluate(() => document.fonts.ready); await p.screenshot({ path: 'draft/ov3_names.png', omitBackground: true });
  await p.setContent(`<html><head>${HEAD}</head><body style="background:transparent">${credits}</body></html>`, { waitUntil: 'networkidle' });
  await p.evaluate(() => document.fonts.ready); await p.screenshot({ path: 'draft/ov3_credits.png', omitBackground: true });
  for (const [k, html] of Object.entries(cards)) {
    await p.setContent(`<html><head>${HEAD}</head><body style="background:#120e22">${html}</body></html>`, { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready); await p.screenshot({ path: `draft/card3_${k}.png` });
  }
  await b.close(); console.log('done', Object.keys(caps).length + 1, 'overlays', Object.keys(cards).length, 'cards');
})();
