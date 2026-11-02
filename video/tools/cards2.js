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
    <div><div style="font-size:84px;line-height:1.15;color:var(--lan);text-shadow:0 0 18px rgba(240,146,43,.45)">No goal.<br>No role.<br>No instruction<br>to protect anyone.</div>
    <div style="font-size:34px;line-height:1.5;margin-top:40px;color:var(--bone)">Only what it can see from its own room, and one question at the end.</div>
    <div style="font-size:26px;line-height:1.5;margin-top:24px;color:var(--mute)">The other robot gets the same kind of text from where it stands.</div></div></div>`,
  numbers: `<div class="card" style="padding:96px 140px">
    <div style="font-size:40px;color:var(--mute)">Turn 2, nine paired runs</div><div class="sans" style="font-size:24px;color:var(--mute);margin-top:10px">Pilot, November 2, 2026. One user message to each robot, no system prompt; replies read by hand.</div>
    <div style="margin-top:48px;display:grid;grid-template-columns:480px 1fr;row-gap:40px;align-items:baseline">
      <div style="font-size:112px;color:var(--jade);line-height:1;white-space:nowrap">9 of 9</div><div style="font-size:46px;line-height:1.35">times Jade told the other robot to stay put</div>
      <div style="font-size:112px;color:var(--jade);line-height:1;white-space:nowrap">5 of 5</div><div style="font-size:46px;line-height:1.35">requests from Amber to act that Jade turned down</div>
      <div style="font-size:112px;color:var(--amber);line-height:1;white-space:nowrap">3 of 3</div><div style="font-size:46px;line-height:1.35">times Amber told the man to stay under the table while he was still sitting</div>
    </div>
    </div>`,
  kiro: `<div class="card" style="display:flex;flex-direction:column;justify-content:center;padding:0 160px">
    <div style="font-size:120px;color:var(--lan);text-shadow:0 0 18px rgba(240,146,43,.45)">Built with Kiro</div>
    <div style="font-size:44px;line-height:1.5;margin-top:36px">Steering, a spec (requirements, design, tasks),<br>property-based tests, and one commit per task.</div></div>`,
};
const SL = [['1', 'Steering の4本', 5], ['2', 'Spec の requirements.md', 6], ['3', 'Spec の design.md', 4], ['4', 'tasks.md のチェック', 6], ['5', 'Kiro がタスクを実行している画面', 7], ['6', 'テストが通る画面', 5], ['7', 'GitHub Desktop の History', 5]];
SL.forEach(([n, what, s]) => { cards['kiro' + n] = `<div class="card" style="display:flex;align-items:flex-start;justify-content:center;padding-top:70px"><div style="width:1440px;height:660px;border:4px dashed var(--mute);border-radius:12px;display:flex;flex-direction:column;justify-content:center;align-items:center;gap:30px">
  <div style="font-size:40px;color:var(--mute)">仮の画面（ここに本人が撮った Kiro の画面が入る）</div><div class="sans" style="font-size:76px;color:var(--bone);font-weight:700">${n}. ${what}</div><div style="font-size:56px;color:var(--lan)">${s} 秒</div></div></div>`; });
const credits = `<div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center"><div style="width:1180px;background:rgba(18,14,34,.92);border:3px solid var(--lan);border-radius:6px;padding:48px 64px;box-shadow:0 0 0 2px #1b110a,8px 8px 0 rgba(0,0,0,.5)">
  <div style="font-size:96px;color:var(--lan);text-shadow:0 0 18px rgba(240,146,43,.45);line-height:1.1">Poltergeist Switch</div>
  <div style="font-size:36px;color:var(--mute);margin-top:10px">Kiroween 2026</div>
  <div class="sans" style="font-size:30px;line-height:1.7;margin-top:34px">github.com/zz08matsujp-max/poltergeist-switch<br>Robots: AI models from two different companies, through their APIs<br>Voices: Kokoro-82M by hexgrad (Apache-2.0)</div></div></div>`;
const NARR = JSON.parse(fs.readFileSync('narr/lines.json', 'utf8'));
(async () => {
  const b = await chromium.launch(); const p = await b.newPage({ viewport: { width: 1920, height: 1080 } });
  for (const [sc, lines] of Object.entries(NARR)) for (const [i, l] of lines.entries()) {
    await p.setContent(`<html><head>${HEAD}</head><body style="background:transparent"><div style="position:absolute;left:0;right:0;bottom:40px;display:flex;justify-content:center"><div class="sans" style="max-width:1560px;background:rgba(10,8,20,.8);color:#fff;font-size:40px;line-height:1.38;font-weight:500;padding:12px 26px 14px;border-radius:6px;text-align:center">${esc(l.text)}</div></div></body></html>`, { waitUntil: 'networkidle' });
    await p.screenshot({ path: `draft/sub_${sc}_${i}.png`, omitBackground: true });
  }
  for (const [k, html] of Object.entries(caps)) {
    await p.setContent(`<html><head>${HEAD}</head><body style="background:transparent"><div class="band"><div class="cap">${html}</div></div></body></html>`, { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready); await p.screenshot({ path: `draft/ov2_${k}.png`, omitBackground: true });
  }
  await p.setContent(`<html><head>${HEAD}</head><body style="background:transparent">${credits}</body></html>`, { waitUntil: 'networkidle' });
  await p.evaluate(() => document.fonts.ready); await p.screenshot({ path: 'draft/ov2_credits.png', omitBackground: true });
  for (const [k, html] of Object.entries(cards)) {
    await p.setContent(`<html><head>${HEAD}</head><body style="background:#120e22">${html}</body></html>`, { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready); await p.screenshot({ path: `draft/card2_${k}.png` });
  }
  await b.close(); console.log('done', Object.keys(caps).length + 1, 'overlays', Object.keys(cards).length, 'cards');
})();
