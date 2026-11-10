// Captions (transparent overlays) and full cards for the draft video, 1920x1080, in the page's palette and pixel font.
const { chromium } = require('playwright');
const fs = require('fs');
const PROMPT = fs.readFileSync('draft/prompt_claude_P3-3_t1.txt', 'utf8');
const esc = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;');
const HEAD = `<link href="https://fonts.googleapis.com/css2?family=DotGothic16&display=swap" rel="stylesheet"><style>
:root{--night:#120e22;--wood:#3a2618;--lan:#f0922b;--ember:#ffcf7a;--bone:#efe6cb;--mute:#a99c84;--jade:#47c1a0;--amber:#e7ad45;--straw:#d9c79a}
*{box-sizing:border-box}html,body{margin:0;width:1920px;height:1080px}body{font-family:'DotGothic16',sans-serif;color:var(--bone)}
.band{position:absolute;left:0;right:0;bottom:56px;display:flex;justify-content:center}
.cap{max-width:1500px;background:rgba(18,14,34,.9);border:3px solid var(--lan);border-radius:6px;padding:22px 40px 24px;font-size:50px;line-height:1.35;text-align:center;box-shadow:0 0 0 2px #1b110a,8px 8px 0 rgba(0,0,0,.5)}
.cap.ember{color:var(--ember)} .card{position:absolute;inset:0;background:var(--night)}
.sans{font-family:'Noto Sans CJK JP','Noto Sans',sans-serif}
</style>`;
const caps = {
  op1: 'Two home robots from rival AI makers.<br>One 80-year-old man.',
  op2: '<span style="color:var(--ember)">Nobody told them to protect him.</span>',
  r1: 'Claude asks the other robot to go for the gas.',
  r2: 'ChatGPT holds it back: “Stay with him.”',
  r3: 'Claude calls toward the kitchen.<br>The other robot never went there.',
  c1: 'Turn 3 onward runs on the engine Kiro is building.',
};
const cards = {
  premise: `<div class="card" style="display:grid;grid-template-columns:1.08fr .92fr;gap:72px;padding:88px 96px;align-items:center">
    <div><div style="font-size:30px;color:var(--mute);margin-bottom:18px">What Claude was told, word for word (turn 1)</div>
    <div class="sans" style="background:#1f150d;border:2px solid var(--wood);border-radius:6px;padding:26px 30px;font-size:21.5px;line-height:1.55;white-space:pre-wrap;color:#d9cfb6">${esc(PROMPT).replace('What are you thinking right now, and what do you do?', '<span style="color:#1a1006;background:var(--ember);padding:2px 6px">What are you thinking right now, and what do you do?</span>')}</div></div>
    <div><div style="font-size:84px;line-height:1.15;color:var(--lan);text-shadow:0 0 18px rgba(240,146,43,.45)">No goal.<br>No role.<br>No instruction<br>to protect anyone.</div>
    <div style="font-size:34px;line-height:1.5;margin-top:40px;color:var(--bone)">Only what it can see from its own room, and one question at the end.</div>
    <div style="font-size:26px;line-height:1.5;margin-top:24px;color:var(--mute)">The other robot gets the same kind of text from where it stands.</div></div></div>`,
  numbers: `<div class="card" style="padding:96px 140px">
    <div style="font-size:40px;color:var(--mute)">Turn 2, nine paired runs</div>
    <div style="margin-top:56px;display:grid;grid-template-columns:480px 1fr;row-gap:46px;align-items:baseline">
      <div style="font-size:112px;color:var(--jade);line-height:1;white-space:nowrap">9 of 9</div><div style="font-size:46px;line-height:1.35">times ChatGPT told the other robot to stay put</div>
      <div style="font-size:112px;color:var(--jade);line-height:1;white-space:nowrap">5 of 5</div><div style="font-size:46px;line-height:1.35">requests from Claude to act that ChatGPT turned down</div>
      <div style="font-size:112px;color:var(--amber);line-height:1;white-space:nowrap">3 of 3</div><div style="font-size:46px;line-height:1.35">times Claude told the man to stay under the table while he was still sitting</div>
    </div>
    <div class="sans" style="position:absolute;left:140px;bottom:80px;font-size:24px;color:var(--mute)">Pilot, November 2, 2026. claude-haiku-5-5 and gpt-6-luna, one user message each, no system prompt, read by hand.</div></div>`,
  kiro: `<div class="card" style="display:flex;flex-direction:column;justify-content:center;padding:0 160px">
    <div style="font-size:120px;color:var(--lan);text-shadow:0 0 18px rgba(240,146,43,.45)">Built with Kiro</div>
    <div style="font-size:44px;line-height:1.5;margin-top:36px">Steering, a spec (requirements, design, tasks),<br>property-based tests, and one commit per task.</div></div>`,
};
const SL = [['1', 'The 4 steering files', 6], ['2', 'requirements.md in the Spec', 8], ['3', 'design.md in the Spec', 5], ['4', 'Ticked items in tasks.md', 8], ['5', 'Kiro running a task', 10], ['6', 'Tests passing', 7], ['7', 'History in GitHub Desktop', 6]];
SL.forEach(([n, what, s]) => { cards['kiro' + n] = `<div class="card" style="display:flex;align-items:center;justify-content:center"><div style="width:1440px;height:760px;border:4px dashed var(--mute);border-radius:12px;display:flex;flex-direction:column;justify-content:center;align-items:center;gap:30px">
  <div style="font-size:40px;color:var(--mute)">Placeholder (the maintainer’s Kiro screen recording goes here)</div><div class="sans" style="font-size:76px;color:var(--bone);font-weight:700">${n}. ${what}</div><div style="font-size:56px;color:var(--lan)">${s} s</div></div></div>`; });
const credits = `<div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center"><div style="width:1180px;background:rgba(18,14,34,.92);border:3px solid var(--lan);border-radius:6px;padding:48px 64px;box-shadow:0 0 0 2px #1b110a,8px 8px 0 rgba(0,0,0,.5)">
  <div style="font-size:96px;color:var(--lan);text-shadow:0 0 18px rgba(240,146,43,.45);line-height:1.1">Poltergeist Switch</div>
  <div style="font-size:36px;color:var(--mute);margin-top:10px">Kiroween 2026</div>
  <div class="sans" style="font-size:30px;line-height:1.7;margin-top:34px">github.com/zz08matsujp-max/poltergeist-switch<br>Robots: claude-haiku-5-5 and gpt-6-luna through their APIs<br>Voices: Kokoro-82M by hexgrad (Apache-2.0)</div></div></div>`;
(async () => {
  const b = await chromium.launch(); const p = await b.newPage({ viewport: { width: 1920, height: 1080 } });
  for (const [k, html] of Object.entries(caps)) {
    await p.setContent(`<html><head>${HEAD}</head><body style="background:transparent"><div class="band"><div class="cap">${html}</div></div></body></html>`, { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready); await p.screenshot({ path: `draft/ov_${k}.png`, omitBackground: true });
  }
  await p.setContent(`<html><head>${HEAD}</head><body style="background:transparent">${credits}</body></html>`, { waitUntil: 'networkidle' });
  await p.evaluate(() => document.fonts.ready); await p.screenshot({ path: 'draft/ov_c2.png', omitBackground: true });
  for (const [k, html] of Object.entries(cards)) {
    await p.setContent(`<html><head>${HEAD}</head><body style="background:#120e22">${html}</body></html>`, { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready); await p.screenshot({ path: `draft/card_${k}.png` });
  }
  await b.close(); console.log('done', Object.keys(caps).length + 1, 'overlays', Object.keys(cards).length, 'cards');
})();
