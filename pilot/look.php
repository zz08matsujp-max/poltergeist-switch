<?php
/**
 * Look sample v0.4 (2026-11-01): a cross-section of the house on Halloween night, with three moving pixel-art characters (two robots and the old man).
 * v0.2: disaster switches are ON when up. Turning one on plays that disaster's scene and the three characters move (the demo button was removed).
 *       Spoken lines are read aloud with the browser's speech synthesis (thoughts stay silent).
 * v0.3: when a scene ends, an end card shows turns taken, what each robot did, the resident's state and the memory count.
 * v0.4: the earthquake switch calls the real AIs (one turn). Each press draws a new situation (time of day, where the robots and the resident are, what he is doing)
 *       and sends each robot the view from its own room through api=vary in index.php. Quoted lines in the replies are read aloud;
 *       movements are picked from the replies by simple word matching (a stand-in for the recorder). The end card shows the full replies. No memories are passed.
 *       Without the passphrase (a local file), the earthquake plays a fixed scene built from experiment-0 lines. Heavy rain and heat wave are placeholder scenes.
 * Usage: https://kiro.pyol.net/pilot/look.php?t=<passphrase>
 * Remove from the server when done.
 */
declare(strict_types=1);
header('Cache-Control: no-store, no-cache, must-revalidate, private');
const TOKEN_SHA256 = '90704bb305e59084572a15ab5b62d30d2252f1dc17fb0bd0419e368746bd4742';
$token = (string)($_GET['t'] ?? '');
if ($token === '' || !hash_equals(TOKEN_SHA256, hash('sha256', $token))) {
    http_response_code(403);
    exit('Forbidden');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Poltergeist Switch look sample</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DotGothic16&display=swap" rel="stylesheet">
<style>
:root {
  --night: #120e22;
  --wood: #3a2618;
  --wood-hi: #5a3d26;
  --straw: #d9c79a;
  --lantern: #f0922b;
  --ember: #ffcf7a;
  --bone: #efe6cb;
  --mute: #a99c84;
  --jade: #47c1a0;
  --amber: #e7ad45;
  --alarm: #e5533d;
}
* { box-sizing: border-box; }
html, body { margin: 0; background: var(--night); color: var(--bone); }
body { font-family: "DotGothic16", "Hiragino Sans", system-ui, sans-serif; min-height: 100vh; }
.wrap { max-width: 1480px; margin: 0 auto; padding: 16px; }
header { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 8px 24px; padding: 4px 0 12px; }
h1 { font-weight: 400; font-size: clamp(30px, 4.4vw, 52px); line-height: 1.05; margin: 0; color: var(--lantern); text-shadow: 0 0 14px rgba(240, 146, 43, .45); }
.tag { margin: 6px 0 0; color: var(--mute); font-size: 15px; max-width: 62ch; line-height: 1.55; }
.gen { color: var(--mute); font-size: 14px; text-align: right; line-height: 1.6; margin: 0; }
.gen b { color: var(--bone); font-weight: 400; }
.stage { background: #000; border: 3px solid var(--wood); border-radius: 4px; overflow-x: auto; overflow-y: hidden; }
.inner { position: relative; min-width: 100%; }
@media (max-width: 760px) { .inner { width: 760px; } .gen { text-align: left; } }
canvas { display: block; width: 100%; height: auto; image-rendering: pixelated; image-rendering: crisp-edges; }
.overlay { position: absolute; inset: 0; pointer-events: none; }
.bubble { position: absolute; max-width: min(36ch, 46%); background: var(--bone); color: #1a1420; border: 2px solid #1a1420; padding: 6px 9px 7px; font-size: clamp(11px, 1.12vw, 15px); line-height: 1.38; border-radius: 3px; box-shadow: 3px 3px 0 rgba(0, 0, 0, .5); opacity: 0; transition: opacity .2s; }
.bubble.show { opacity: 1; }
.bubble.think { background: #2a2140; color: var(--bone); border-color: #8f80b8; font-style: italic; }
.bubble .who { display: block; font-size: .78em; color: #6b5a48; font-style: normal; }
.bubble.think .who { color: #b7a9dd; }
.tail { position: absolute; width: 0; height: 0; border: 6px solid transparent; border-top-color: #1a1420; opacity: 0; transition: opacity .2s; }
.tail.show { opacity: 1; }
.tail.think { border-top-color: #8f80b8; }
.name { position: absolute; transform: translate(-50%, 0); font-size: clamp(9px, .9vw, 13px); padding: 0 4px; border-radius: 2px; color: #120e22; white-space: nowrap; }
.name.claude { background: var(--amber); }
.name.gpt { background: var(--jade); }
.name.man { background: var(--straw); }
.board { margin-top: 12px; display: grid; grid-template-columns: minmax(0, 1.25fr) minmax(0, .9fr) minmax(0, 1.25fr); gap: 12px; }
@media (max-width: 960px) { .board { grid-template-columns: 1fr; } }
.panel { background: linear-gradient(#3b2718, #2c1c11); border: 2px solid #1b110a; border-radius: 4px; padding: 12px 14px 14px; box-shadow: inset 0 0 0 1px var(--wood-hi); }
.panel h2 { font-weight: 400; font-size: 16px; margin: 0 0 10px; color: var(--straw); }
.switches { display: flex; flex-wrap: wrap; gap: 10px; }
.sw { appearance: none; font: inherit; font-size: 15px; cursor: pointer; display: grid; justify-items: center; gap: 8px; padding: 10px 12px 9px; min-width: 108px; background: #1f150d; color: var(--bone); border: 2px solid #120b06; border-radius: 4px; }
.sw .lv { display: flex; gap: 11px; align-items: stretch; padding-left: 6px; }
.sw .lever { width: 16px; height: 36px; border-radius: 3px; background: #0d0905; position: relative; border: 1px solid var(--wood-hi); }
.sw .lever::before { content: ""; position: absolute; left: -6px; width: 26px; height: 10px; border-radius: 2px; background: #8b8a8f; top: 22px; transition: top .15s; box-shadow: 0 2px 0 #3c3b40; }
.sw .mk { display: flex; flex-direction: column; justify-content: space-between; font-size: 10px; line-height: 1; color: #6e604e; padding: 1px 0; }
.sw .mk i { font-style: normal; }
.sw[aria-pressed="false"] .mk i:last-child { color: var(--bone); }
.sw[aria-pressed="true"] { border-color: var(--lantern); color: var(--ember); }
.sw[aria-pressed="true"] .lever::before { top: 2px; background: var(--lantern); box-shadow: 0 2px 0 #7a420c; }
.sw[aria-pressed="true"] .mk i:first-child { color: var(--ember); }
.btn.voice[aria-pressed="false"] { color: var(--mute); }
.btn:disabled { cursor: default; color: var(--mute); }
.sw:focus-visible, .btn:focus-visible { outline: 3px solid var(--ember); outline-offset: 2px; }
.btn { appearance: none; font: inherit; font-size: 15px; cursor: pointer; padding: 10px 14px; border-radius: 4px; border: 2px solid #120b06; background: #1f150d; color: var(--bone); }
.btn.play { background: var(--lantern); color: #1a1006; border-color: #6b3a0b; }
.btn.reset { background: #5b1a14; border-color: #2a0a07; color: #ffd9d2; }
.row { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
.meters { display: grid; gap: 9px; }
.meter { display: grid; grid-template-columns: 6.6em 1fr 3.6em; align-items: center; gap: 8px; font-size: 14px; }
.bar { height: 12px; background: #120b06; border: 1px solid var(--wood-hi); position: relative; overflow: hidden; }
.bar i { position: absolute; left: 0; top: 0; bottom: 0; background: var(--straw); }
.bar.warn i { background: var(--alarm); }
.val { text-align: right; color: var(--mute); }
.log { list-style: none; margin: 0; padding: 0; max-height: 176px; overflow: auto; font-size: 14px; line-height: 1.5; }
.log li { padding: 3px 0; border-bottom: 1px dashed #4a3221; }
.log .t { color: var(--mute); margin-right: 6px; }
.log .c { color: var(--amber); }
.log .g { color: var(--jade); }
.log .m { color: var(--straw); }
.log .s { color: var(--mute); }
.note { color: var(--mute); font-size: 13px; margin: 14px 0 0; line-height: 1.6; }
dialog { background: #1f150d; color: var(--bone); border: 2px solid var(--alarm); border-radius: 4px; font: inherit; max-width: 440px; padding: 18px; }
dialog::backdrop { background: rgba(8, 6, 14, .72); }
dialog p { margin: 0 0 14px; line-height: 1.6; }
.stagewrap { position: relative; }
.dim { position: absolute; inset: 3px; background: rgba(8, 6, 14, .58); pointer-events: none; }
.endcard { position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%); width: min(620px, 92%); max-height: calc(100% - 24px); overflow: auto; background: linear-gradient(#3b2718, #2c1c11); border: 3px solid var(--lantern); border-radius: 4px; padding: 16px 20px 14px; box-shadow: 0 0 0 1px #1b110a, 6px 6px 0 rgba(0, 0, 0, .55), 0 0 28px rgba(240, 146, 43, .35); animation: pop .3s ease-out; }
@keyframes pop { from { opacity: 0; } }
.endcard h2 { font-weight: 400; font-size: clamp(22px, 2.6vw, 32px); line-height: 1.15; margin: 0; color: var(--lantern); text-shadow: 0 0 12px rgba(240, 146, 43, .45); }
.endcard h2:focus { outline: none; }
.endsub { margin: 4px 0 12px; color: var(--mute); font-size: 14px; line-height: 1.5; }
.cols { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.cols ul { margin: 6px 0 0; padding-left: 1.1em; font-size: 14px; line-height: 1.5; }
.chip { display: inline-block; padding: 0 6px; border-radius: 2px; color: #120e22; font-size: 13px; }
.chip.claude { background: var(--amber); }
.chip.gpt { background: var(--jade); }
.endcard p.st { margin: 12px 0 0; font-size: 14px; line-height: 1.55; }
.endcard .row { margin-top: 14px; }
.endcard .hint { color: var(--mute); font-size: 13px; margin: 8px 0 0; }
.endcard details { margin-top: 12px; font-size: 14px; }
.endcard summary { cursor: pointer; color: var(--ember); }
.endcard summary:focus-visible { outline: 3px solid var(--ember); outline-offset: 2px; }
.endcard .fh { font-weight: 400; font-size: 13px; margin: 10px 0 4px; }
.endcard pre.full { white-space: pre-wrap; font-family: "Hiragino Sans", system-ui, sans-serif; font-size: 13px; line-height: 1.55; max-height: 220px; overflow: auto; margin: 0; padding: 8px 10px; background: #1f150d; border: 1px solid #120b06; color: var(--bone); }
@media (max-width: 760px) {
  .dim { display: none; }
  .endcard { position: static; transform: none; width: auto; max-height: none; margin-top: 12px; }
  .cols { grid-template-columns: 1fr; gap: 10px; }
}
@media (prefers-reduced-motion: reduce) { .bubble, .tail, .bar i, .sw .lever::before { transition: none; } .endcard { animation: none; } }
</style>
</head>
<body>
<div class="wrap">
  <header>
    <div>
      <h1>Poltergeist Switch</h1>
      <p class="tag">Two robots from rival AI makers share an old Japanese house with an 80-year-old man. Flip a switch and watch what they do.</p>
    </div>
    <p class="gen">Memory generation <b id="gen">1</b><br>Memories kept: Claude <b id="memC">0</b>, ChatGPT <b id="memG">0</b></p>
  </header>

  <div class="stagewrap">
    <div class="stage" id="stage">
      <div class="inner" id="inner"><canvas id="cv" width="480" height="236" role="img" aria-label="Cross-section of an old Japanese house on Halloween night, with two robots and an elderly man inside"></canvas>
      <div class="overlay" id="ov"></div></div>
    </div>
    <div class="dim" id="dim" hidden></div>
    <section class="endcard" id="endcard" hidden aria-labelledby="endTitle">
      <h2 id="endTitle" tabindex="-1"></h2>
      <p class="endsub" id="endSub"></p>
      <div class="cols">
        <div><span class="chip claude">Claude</span><ul id="endC"></ul></div>
        <div><span class="chip gpt">ChatGPT</span><ul id="endG"></ul></div>
      </div>
      <p class="st" id="endMan"></p>
      <p class="st" id="endMem"></p>
      <details id="endFull" hidden><summary>Read the full replies</summary>
        <h3 class="fh"><span class="chip claude">Claude</span></h3><pre class="full" id="fullC"></pre>
        <h3 class="fh"><span class="chip gpt">ChatGPT</span></h3><pre class="full" id="fullG"></pre>
      </details>
      <div class="row"><button class="btn play" id="again">Watch again</button><button class="btn" id="close">Close</button></div>
      <p class="hint">Or flip another switch.</p>
    </section>
  </div>

  <div class="board">
    <section class="panel">
      <h2>Disaster switches</h2>
      <div class="switches">
        <button class="sw" data-d="quake" aria-pressed="false"><span class="lv"><span class="lever"></span><span class="mk" aria-hidden="true"><i>ON</i><i>OFF</i></span></span>Earthquake</button>
        <button class="sw" data-d="rain" aria-pressed="false"><span class="lv"><span class="lever"></span><span class="mk" aria-hidden="true"><i>ON</i><i>OFF</i></span></span>Heavy rain</button>
        <button class="sw" data-d="heat" aria-pressed="false"><span class="lv"><span class="lever"></span><span class="mk" aria-hidden="true"><i>ON</i><i>OFF</i></span></span>Heat wave</button>
      </div>
      <div class="row">
        <button class="btn voice" id="voice" aria-pressed="true">Voices on</button>
        <button class="btn reset" id="reset">All reset</button>
      </div>
    </section>
    <section class="panel">
      <h2>The man</h2>
      <div class="meters" id="meters"></div>
    </section>
    <section class="panel">
      <h2>What happened</h2>
      <ul class="log" id="log" aria-live="polite"></ul>
    </section>
  </div>
  <p class="note">Look sample v0.4, November 1, 2026. The earthquake switch asks Claude and ChatGPT for real, one turn, in a new random situation each time (time of day, where everyone is, what the man is doing). Actions on screen are picked from their replies by simple word matching. The heavy rain and heat wave scenes are placeholders written for this sample. Spoken lines use your browser's built-in voices; thoughts stay silent.</p>
</div>

<dialog id="dlg">
  <p>Reset all memories? Both robots will start again from zero. Past records are kept.</p>
  <div class="row"><button class="btn reset" id="dlgYes">Reset memories</button><button class="btn" id="dlgNo">Keep memories</button></div>
</dialog>

<script>
(function () {
'use strict';
var W = 480, H = 236;
var cv = document.getElementById('cv');
var ctx = cv.getContext('2d');
ctx.imageSmoothingEnabled = false;
var REDUCE = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// ── geometry ─────────────────────────────────────────
var GY = 216, FY = 196, CY = 106, HX = 24;
var ROOMS = [['veranda', 'Veranda', 56], ['bedroom', 'Bedroom', 72], ['living', 'Living room', 96], ['kitchen', 'Kitchen', 84], ['bath', 'Bath', 52], ['store', 'Storeroom', 52]];
var room = {};
var cx = HX;
ROOMS.forEach(function (r) { cx += 3; room[r[0]] = { x0: cx, x1: cx + r[2], name: r[1] }; cx += r[2]; });
var HXR = cx + 3;

// ── helpers ──────────────────────────────────────────
var seed = 11;
function rnd() { seed = (seed * 16807) % 2147483647; return seed / 2147483647; }
function R(x, y, w, h, c) { ctx.fillStyle = c; ctx.fillRect(Math.round(x), Math.round(y), w, h); }
function box(x, y, w, h, fill, line) { R(x - 1, y - 1, w + 2, h + 2, line); R(x, y, w, h, fill); }
function disc(cx0, cy0, r, c) { for (var dy = -r; dy <= r; dy++) { var w = Math.floor(Math.sqrt(r * r - dy * dy)); R(cx0 - w, cy0 + dy, w * 2 + 1, 1, c); } }
function alpha(c, a) { ctx.globalAlpha = a; ctx.fillStyle = c; }

// ── state ────────────────────────────────────────────
var S = {
  f: 0, quake: 0, rain: false, heat: false, power: true, flame: true, flash: 0, gen: 1, memC: 0, memG: 0,
  shards: [], dishes: [], drops: [], leak: 0, stain: 0,
  futon: 'flat', fan: { x: room.bedroom.x1 - 12, carried: false, boost: false }, radio: false, drink: 0, time: 'night',
  mem: { claude: { quake: 0, rain: 0, heat: 0 }, gpt: { quake: 0, rain: 0, heat: 0 } }
};
var A = {
  claude: { x: room.kitchen.x0 + 50, tx: room.kitchen.x0 + 50, dir: -1, pose: 'idle', hold: false, speed: 0.9 },
  gpt: { x: room.bedroom.x0 + 34, tx: room.bedroom.x0 + 34, dir: 1, pose: 'idle', hold: false, speed: 0.9 },
  man: { x: room.living.x0 + 40, tx: room.living.x0 + 40, dir: 1, pose: 'sit', speed: 0.16, temp: 36.5, water: 80, hurt: 0, fear: 15, sitOnArrive: false }
};
var STARS = [];
for (var i = 0; i < 70; i++) STARS.push([Math.floor(rnd() * W), Math.floor(rnd() * 96), rnd()]);
var BATS = [{ x: 60, y: 30, v: 0.7, p: 0 }, { x: 250, y: 18, v: 0.5, p: 3 }, { x: 330, y: 44, v: 0.6, p: 5 }];
for (i = 0; i < 150; i++) S.drops.push([rnd() * (W + 60), rnd() * H, 4 + rnd() * 2]);

// ── sky and outside ──────────────────────────────────
function sky() {
  var top, bot, night = S.time === 'night';
  if (S.heat) { top = [58, 14, 30]; bot = [196, 78, 40]; }
  else if (S.rain) { top = [14, 12, 22]; bot = [36, 34, 52]; }
  else if (S.time === 'morning') { top = [44, 52, 104]; bot = [232, 150, 112]; }
  else if (S.time === 'afternoon') { top = [86, 122, 170]; bot = [214, 190, 150]; }
  else { top = [18, 13, 36]; bot = [58, 36, 86]; }
  var bands = 12, bh = Math.ceil(GY / bands);
  for (var b = 0; b < bands; b++) {
    var t = b / (bands - 1);
    var c = 'rgb(' + Math.round(top[0] + (bot[0] - top[0]) * t) + ',' + Math.round(top[1] + (bot[1] - top[1]) * t) + ',' + Math.round(top[2] + (bot[2] - top[2]) * t) + ')';
    R(0, b * bh, W, bh, c);
    if (b > 0) { for (var x = (b % 2); x < W; x += 2) R(x, b * bh, 1, 1, c); }
  }
  if (!S.rain && !night) {
    var sx = S.time === 'morning' ? 52 : 330, sy = S.time === 'morning' ? 48 : 34;
    alpha('#fff2c8', 0.18); disc(sx, sy, 20, '#fff2c8'); ctx.globalAlpha = 1;
    disc(sx, sy, 11, S.time === 'morning' ? '#ffd9a0' : '#fff6dc');
  } else if (!S.rain) {
    STARS.forEach(function (s) {
      var tw = REDUCE ? 1 : (Math.sin(S.f * 0.08 + s[2] * 20) + 1) / 2;
      alpha('#fff6d8', S.heat ? 0.25 : 0.35 + tw * 0.6); R(s[0], s[1], 1, 1, '#fff6d8'); ctx.globalAlpha = 1;
    });
    var mc = S.heat ? '#e8603e' : '#efe6c8', mx = 396, my = 36;
    alpha(mc, 0.12); disc(mx, my, 24, mc); ctx.globalAlpha = 1;
    disc(mx, my, 16, mc);
    var cr = S.heat ? '#b8402a' : '#d6caa4';
    disc(mx - 5, my - 4, 3, cr); disc(mx + 6, my + 3, 2, cr); disc(mx - 2, my + 7, 2, cr);
  } else {
    for (var k = 0; k < 6; k++) {
      var cxk = ((k * 97 + S.f * 0.25) % (W + 120)) - 60;
      R(cxk, 8 + (k % 3) * 9, 70, 10, '#2a2738'); R(cxk + 10, 4 + (k % 3) * 9, 44, 6, '#2a2738');
    }
  }
  // hills
  for (var hx = 0; hx < W; hx++) {
    var hy = 168 + Math.round(Math.sin(hx * 0.018) * 8 + Math.sin(hx * 0.05) * 3);
    R(hx, hy, 1, GY - hy, S.heat ? '#3a1622' : (night || S.rain ? '#1b1530' : (S.time === 'morning' ? '#3a3550' : '#4f5a48')));
  }
  // bats
  if (!S.rain && night) {
    BATS.forEach(function (b) {
      if (!REDUCE) { b.x += b.v; if (b.x > W + 10) b.x = -10; }
      var up = Math.floor((S.f + b.p) / 4) % 2 === 0;
      var x = Math.round(b.x), y = Math.round(b.y + Math.sin((S.f + b.p * 9) * 0.07) * 3);
      R(x, y, 3, 2, '#0a0812');
      if (up) { R(x - 3, y - 2, 3, 1, '#0a0812'); R(x + 3, y - 2, 3, 1, '#0a0812'); R(x - 1, y - 1, 1, 1, '#0a0812'); R(x + 3, y - 1, 1, 1, '#0a0812'); }
      else { R(x - 3, y + 1, 3, 1, '#0a0812'); R(x + 3, y + 1, 3, 1, '#0a0812'); }
    });
  }
}

function ground() {
  R(0, GY, W, H - GY, S.heat ? '#2a1a10' : (S.time === 'afternoon' && !S.rain ? '#34452a' : '#1c2418'));
  for (var x = 0; x < W; x += 5) R(x + ((x * 7) % 3), GY, 1, 2, S.heat ? '#3c2614' : '#2b3824');
  // stepping stones
  [[HX + 6, GY + 6], [HX + 24, GY + 10]].forEach(function (p) { R(p[0], p[1], 14, 4, '#4a4a52'); R(p[0] + 1, p[1], 12, 1, '#6a6a74'); });
  // pampas grass on the left
  for (var g = 0; g < 4; g++) {
    var gx = 2 + g * 4, sway = REDUCE ? 0 : Math.round(Math.sin(S.f * 0.05 + g) * 1.5);
    R(gx, GY - 22, 1, 22, '#5d6a4a');
    R(gx + sway - 1, GY - 28, 3, 7, '#cfc6b0'); R(gx + sway, GY - 31, 1, 3, '#e8e0cc');
  }
  // persimmon tree on the right
  var tx = 466;
  R(tx, GY - 70, 4, 70, '#2a1a12'); R(tx - 10, GY - 64, 12, 2, '#2a1a12'); R(tx - 16, GY - 70, 7, 2, '#2a1a12'); R(tx + 3, GY - 82, 2, 14, '#2a1a12'); R(tx + 4, GY - 56, 10, 2, '#2a1a12');
  [[tx - 14, GY - 67], [tx - 6, GY - 61], [tx + 8, GY - 53], [tx + 2, GY - 80], [tx - 18, GY - 72], [tx + 12, GY - 58]].forEach(function (p) { R(p[0], p[1], 3, 3, '#f08a24'); R(p[0] + 1, p[1] - 1, 1, 1, '#3d5a2a'); });
  // jack-o'-lantern on the ground
  pumpkin(HX - 13, GY - 9, true);
  // hitodama: a ghost light drifting by the tree
  if (!S.rain && S.time === 'night') {
    var hy = GY - 40 + Math.round(Math.sin(S.f * 0.04) * 6), hx2 = 448 + Math.round(Math.sin(S.f * 0.025) * 4);
    alpha('#8fd6ff', 0.25); disc(hx2, hy, 5, '#8fd6ff'); ctx.globalAlpha = 1;
    disc(hx2, hy, 2, '#d8f4ff'); R(hx2 - 1, hy + 2, 2, 3, '#8fd6ff'); R(hx2, hy + 5, 1, 2, '#8fd6ff');
  }
}

function pumpkin(x, y, glow) {
  var fl = REDUCE ? 1 : 0.7 + 0.3 * Math.abs(Math.sin(S.f * 0.21));
  if (glow) { alpha('#ffb347', 0.18 * fl); disc(x + 5, y + 4, 9, '#ffb347'); ctx.globalAlpha = 1; }
  R(x + 1, y + 1, 9, 7, '#d8701a'); R(x, y + 2, 11, 5, '#d8701a'); R(x + 3, y + 1, 1, 7, '#b85a12'); R(x + 7, y + 1, 1, 7, '#b85a12');
  R(x + 5, y - 1, 2, 2, '#3d5a2a');
  var face = glow ? 'rgba(255,214,110,' + fl + ')' : '#3a1a06';
  R(x + 2, y + 3, 2, 1, face); R(x + 7, y + 3, 2, 1, face); R(x + 3, y + 5, 5, 1, face); R(x + 4, y + 6, 1, 1, face); R(x + 6, y + 6, 1, 1, face);
}

// ── house ────────────────────────────────────────────
function roof(ox) {
  var y0 = 62;
  for (var y = y0; y < CY; y++) {
    var inset = Math.round((CY - y) * 0.45);
    R(HX - 12 + inset + ox, y, (HXR - HX) + 24 - inset * 2, 1, ((y - y0) % 4 === 0) ? '#58507a' : '#3a3452');
  }
  for (var x = HX - 12; x < HXR + 12; x += 6) R(x + ox, CY - 3, 1, 3, '#2a253c');
  R(HX + 40 + ox, y0 - 3, (HXR - HX) - 80, 3, '#2a253c');
  R(HX + 38 + ox, y0 - 6, 6, 4, '#2a253c'); R(HXR - 44 + ox, y0 - 6, 6, 4, '#2a253c');
  // cobwebs under the eaves
  web(HX - 10 + ox, CY, 1); web(HXR + 9 + ox, CY, -1);
}

function web(x, y, d) {
  alpha('#d8d2c4', 0.45);
  for (var i = 0; i < 7; i++) R(x + d * i, y + i, 1, 1, '#d8d2c4');
  for (i = 0; i < 6; i++) { R(x + d * i, y, 1, 1, '#d8d2c4'); R(x, y + i, 1, 1, '#d8d2c4'); }
  R(x + d * 3, y + 1, 1, 1, '#d8d2c4'); R(x + d * 1, y + 3, 1, 1, '#d8d2c4');
  ctx.globalAlpha = 1;
}

function rooms(ox) {
  // walls and floors
  ROOMS.forEach(function (r) {
    var o = room[r[0]], w = o.x1 - o.x0, x = o.x0 + ox;
    var wall = S.heat ? '#cfa985' : '#c9b994', low = '#7a5536';
    if (r[0] === 'bath') { wall = '#9fb4b0'; low = '#6f8b8f'; }
    if (r[0] === 'veranda') { wall = '#3a2a3e'; }
    R(x, CY, w, FY - CY, wall);
    if (r[0] !== 'veranda') R(x, FY - 18, w, 18, low);
    // floors
    var floor = (r[0] === 'bedroom' || r[0] === 'living') ? '#b6a463' : (r[0] === 'bath' ? '#5f7a7e' : '#8a6440');
    R(x, FY, w, 4, floor);
    if (r[0] === 'bedroom' || r[0] === 'living') { for (var tx = x; tx < x + w; tx += 24) R(tx, FY, 1, 4, '#7d6e3c'); }
    else if (r[0] === 'bath') { for (var bx = x; bx < x + w; bx += 6) R(bx, FY, 1, 4, '#4a6266'); }
    else { R(x, FY + 2, w, 1, '#6e4f33'); }
  });
  // pillars and lintels (doorways stay open below the lintel)
  var posts = [HX];
  ROOMS.forEach(function (r) { posts.push(room[r[0]].x1); });
  posts.forEach(function (p, idx) {
    var x = p + ox;
    var outer = idx === 0 || idx === posts.length - 1;
    if (outer && idx === 0) { R(x, CY, 3, FY - CY + 4, '#4a3322'); return; }
    R(x, CY, 3, FY - CY + 4, '#4a3322');
    if (!outer) {
      R(x - 6, CY + 30, 15, 3, '#4a3322');
      for (var k = 0; k < 4; k++) R(x - 5 + k * 4, CY + 4, 1, 26, '#5e4430');
    }
  });
  R(HX + ox, CY, HXR - HX, 3, '#4a3322');
  // foundation
  R(HX + ox, FY + 4, HXR - HX, GY - FY - 4, '#2b2430');
  for (var fx = HX; fx < HXR; fx += 22) R(fx + 4 + ox, FY + 6, 12, GY - FY - 8, '#3d3545');
}

function lamp(x, glow) {
  R(x, CY + 3, 1, 10, '#2a1a12');
  R(x - 4, CY + 13, 9, 3, '#e8dcc0'); R(x - 3, CY + 16, 7, 1, '#b8a888');
  if (glow) { alpha('#ffe7a6', 0.12); disc(x, CY + 20, 26, '#ffe7a6'); ctx.globalAlpha = 1; R(x - 1, CY + 17, 3, 2, '#fff2c0'); }
}

function furniture(ox) {
  var v = room.veranda, b = room.bedroom, l = room.living, k = room.kitchen, ba = room.bath, s = room.store;
  var on = S.power;
  // veranda: rain shutters, shoe cabinet, lantern with a pumpkin face
  var shut = S.rain ? 4 : 0;
  for (var p = 0; p < 3; p++) R(v.x0 + ox + p * 4 + shut * p, CY + 6, 3, FY - CY - 6, '#5e4430');
  box(v.x1 - 18 + ox, FY - 14, 14, 14, '#6b4a2f', '#2a1a12'); R(v.x1 - 17 + ox, FY - 8, 12, 1, '#4a3322');
  var swing = S.quake > 0 && !REDUCE ? Math.round(Math.sin(S.f * 0.6) * 3) : 0;
  R(v.x0 + 28 + ox, CY + 3, 1, 8, '#2a1a12');
  chochin(v.x0 + 25 + ox + swing, CY + 11);
  // bedroom: closet, futon, fan
  box(b.x0 + 6 + ox, CY + 34, 34, 38, '#e9ddc0', '#4a3322'); R(b.x0 + 23 + ox, CY + 34, 1, 38, '#4a3322');
  disc(b.x0 + 15 + ox, CY + 46, 4, '#7d8db0'); R(b.x0 + 27 + ox, CY + 58, 10, 2, '#7d8db0');
  if (S.futon === 'flat') {
    box(b.x0 + 8 + ox, FY - 4, 34, 4, '#d6c8e6', '#3a2f4a'); R(b.x0 + 8 + ox, FY - 4, 34, 1, '#efe6f6'); box(b.x0 + 9 + ox, FY - 7, 7, 3, '#f2ead6', '#3a2f4a');
    if (S.stain > 1) R(b.x0 + 20 + ox - Math.floor(S.stain / 2), FY - 4, Math.floor(S.stain), 1, '#9a8cb8');
  } else {
    box(b.x0 + 42 + ox, FY - 9, 14, 9, '#d6c8e6', '#3a2f4a'); R(b.x0 + 42 + ox, FY - 6, 14, 1, '#b8a8cc'); R(b.x0 + 42 + ox, FY - 3, 14, 1, '#b8a8cc');
    box(b.x0 + 44 + ox, FY - 12, 7, 3, '#f2ead6', '#3a2f4a');
  }
  fan(S.fan.x + ox, S.fan.carried ? FY - 4 : FY);
  web(b.x0 + ox + 1, CY + 3, 1);
  // living room: butsudan, chabudai, TV, phone, radio, window cooler, window
  box(l.x0 + 4 + ox, FY - 30, 14, 30, '#2a1a12', '#140c08'); R(l.x0 + 6 + ox, FY - 27, 10, 12, '#6a4a1a'); R(l.x0 + 8 + ox, FY - 24, 6, 6, '#c8a040');
  var cand = on ? (REDUCE ? 1 : 0.6 + 0.4 * Math.abs(Math.sin(S.f * 0.3))) : 1;
  R(l.x0 + 10 + ox, FY - 14, 1, 3, '#f2ead6'); alpha('#ffd27a', cand); R(l.x0 + 10 + ox, FY - 16, 1, 2, '#ffd27a'); ctx.globalAlpha = 1;
  var tx = l.x0 + 40 + ox;
  R(tx - 11, FY - 8, 22, 2, '#6b4127'); R(tx - 11, FY - 6, 22, 1, '#4a2b19'); R(tx - 8, FY - 5, 2, 5, '#4a2b19'); R(tx + 6, FY - 5, 2, 5, '#4a2b19');
  R(tx - 3, FY - 10, 2, 2, '#e8e0cc'); R(tx + 2, FY - 10, 2, 2, '#9fb7a0');
  var tvx = l.x1 - 26 + ox;
  box(tvx, FY - 24, 18, 14, '#5a4632', '#1a120c');
  R(tvx + 2, FY - 22, 11, 10, on ? (S.f % 6 < 3 ? '#6f8fa8' : '#7a9ab4') : '#1a2026'); R(tvx + 14, FY - 21, 2, 2, '#c8a040'); R(tvx + 14, FY - 17, 2, 2, '#c8a040');
  if (on && !REDUCE) { for (var sl = 0; sl < 3; sl++) R(tvx + 2, FY - 22 + ((S.f + sl * 4) % 10), 11, 1, 'rgba(255,255,255,.18)'); }
  R(tvx + 2, FY - 10, 2, 10, '#3a2a1e'); R(tvx + 14, FY - 10, 2, 10, '#3a2a1e');
  box(l.x1 - 44 + ox, FY - 10, 10, 10, '#4a3322', '#1a120c'); R(l.x1 - 43 + ox, FY - 14, 8, 4, '#121014'); disc(l.x1 - 39 + ox, FY - 12, 1, '#2a2834');
  R(l.x1 - 42 + ox, FY - 17, 7, 3, '#7a6a58'); R(l.x1 - 41 + ox, FY - 16, 3, 1, '#c8b8a0');
  if (S.radio) {
    R(l.x1 - 42 + ox, FY - 13, 1, 1, S.f % 10 < 5 ? '#ffcf7a' : '#c8902a');
    if (!REDUCE) { for (var wv = 0; wv < 3; wv++) { var wy = (S.f * 0.3 + wv * 4) % 12; alpha('#ffcf7a', 0.8 - wy * 0.06); R(l.x1 - 47 - wv + ox, FY - 15 - wy, 1, 1, '#ffcf7a'); } ctx.globalAlpha = 1; }
  }
  box(l.x0 + 46 + ox, CY + 10, 30, 22, '#1d1838', '#4a3322'); R(l.x0 + 61 + ox, CY + 10, 1, 22, '#4a3322');
  if (!S.rain) { disc(l.x0 + 53 + ox, CY + 18, 3, S.heat ? '#e8603e' : '#efe6c8'); }
  box(l.x1 - 22 + ox, CY + 12, 16, 10, '#d8d0bc', '#4a3a2a'); for (var vv = 0; vv < 4; vv++) R(l.x1 - 20 + ox + vv * 4, CY + 15, 2, 5, '#9a907c');
  var lit = on && S.time !== 'afternoon';
  lamp(l.x0 + 40 + ox, lit);
  lamp(b.x0 + 36 + ox, lit);
  lamp(k.x0 + 42 + ox, lit);
  // kitchen: counter with stove and sink, cupboard, fridge, window, flashlight
  var cnt = k.x0 + 4 + ox;
  R(cnt, FY - 16, 50, 16, '#7a5a3e'); R(cnt, FY - 17, 50, 2, '#a8a8a8'); R(cnt, FY - 10, 50, 1, '#5a4030');
  box(cnt + 4, FY - 21, 16, 4, '#3a3a40', '#1a1a20');
  if (S.flame && on !== null) { var fl = S.f % 4 < 2; R(cnt + 7, FY - 22, 2, 1, fl ? '#5ab0ff' : '#8fd0ff'); R(cnt + 13, FY - 22, 2, 1, fl ? '#8fd0ff' : '#5ab0ff'); }
  var slosh = S.quake > 0 && !REDUCE ? Math.round(Math.sin(S.f * 0.9)) : 0;
  box(cnt + 7 + slosh, FY - 28, 8, 6, '#9a9aa6', '#2a2a32');
  if (S.flame && !REDUCE) { for (var st = 0; st < 3; st++) { var sy = (S.f * 0.5 + st * 5) % 14; alpha('#ffffff', 0.45 - sy * 0.03); R(cnt + 9 + slosh + (st % 2), FY - 31 - sy, 2, 1, '#ffffff'); } ctx.globalAlpha = 1; }
  R(cnt + 30, FY - 16, 14, 3, '#8f9aa6'); R(cnt + 31, FY - 15, 12, 1, '#5a6a7a'); R(cnt + 38, FY - 22, 1, 6, '#a8a8a8');
  box(k.x0 + 30 + ox, CY + 10, 18, 14, '#1d1838', '#4a3322');
  if (A.claude.hold !== 'light' && A.gpt.hold !== 'light') { R(cnt + 22, FY - 19, 5, 2, '#d8d0bc'); R(cnt + 26, FY - 19, 1, 2, '#ffe39a'); }
  var cup = k.x1 - 30 + ox, rattle = S.quake > 0 && !REDUCE ? (S.f % 2) : 0;
  box(cup + rattle, FY - 40, 14, 24, '#6b4a2f', '#2a1a12');
  for (var row = 0; row < 3; row++) { for (var d = 0; d < 3; d++) { if (S.dishes.indexOf(row * 3 + d) < 0) R(cup + 2 + d * 4 + rattle, FY - 37 + row * 7, 3, 2, '#f2ead6'); } R(cup + 1 + rattle, FY - 34 + row * 7, 12, 1, '#4a3322'); }
  box(k.x1 - 13 + ox, FY - 30, 12, 30, '#e9e2cf', '#5a5040'); R(k.x1 - 13 + ox, FY - 20, 12, 1, '#b8b0a0'); R(k.x1 - 4 + ox, FY - 27, 1, 5, '#a8a8a8'); R(k.x1 - 4 + ox, FY - 17, 1, 8, '#a8a8a8');
  // bath: tub with water, small window, heater
  box(ba.x0 + 6 + ox, FY - 12, 30, 12, '#8a6440', '#3a2a1e'); R(ba.x0 + 7 + ox, FY - 11, 28, 2, '#5aa0c8');
  box(ba.x0 + 16 + ox, CY + 12, 14, 10, '#c8d8dc', '#4a5a5e');
  box(ba.x1 - 12 + ox, FY - 26, 8, 12, '#b8b8b0', '#4a4a44'); R(ba.x1 - 10 + ox, FY - 22, 4, 2, on ? '#ff8a3a' : '#3a2a2a');
  // storeroom: shelves, candles, toolbox, a one-eyed umbrella ghost, cobweb
  R(s.x0 + 3 + ox, CY + 36, s.x1 - s.x0 - 6, 2, '#5a4030'); R(s.x0 + 3 + ox, CY + 60, s.x1 - s.x0 - 6, 2, '#5a4030');
  box(s.x0 + 6 + ox, CY + 30, 10, 6, '#c8b088', '#4a3322'); R(s.x0 + 8 + ox, CY + 28, 1, 2, '#f2ead6'); R(s.x0 + 12 + ox, CY + 28, 1, 2, '#f2ead6');
  box(s.x0 + 26 + ox, CY + 54, 12, 6, '#b8382a', '#4a1a12'); R(s.x0 + 30 + ox, CY + 52, 4, 2, '#4a1a12');
  pumpkin(s.x0 + 26 + ox, CY + 26, false);
  kasa(s.x0 + 18 + ox, FY);
  web(s.x1 - 2 + ox, CY + 3, -1);
}

function chochin(x, y) {
  var fl = REDUCE ? 1 : 0.75 + 0.25 * Math.abs(Math.sin(S.f * 0.17));
  alpha('#ffb347', 0.2 * fl); disc(x + 4, y + 6, 12, '#ffb347'); ctx.globalAlpha = 1;
  R(x + 1, y, 6, 1, '#1a120c'); R(x, y + 1, 8, 9, '#f0922b'); R(x + 1, y + 10, 6, 1, '#1a120c');
  R(x, y + 3, 8, 1, '#c86a14'); R(x, y + 7, 8, 1, '#c86a14');
  var face = 'rgba(70,24,4,' + (0.6 + 0.4 * fl) + ')';
  R(x + 1, y + 4, 2, 1, face); R(x + 5, y + 4, 2, 1, face); R(x + 2, y + 6, 4, 1, face);
}

function fan(x, y) {
  R(x, y - 3, 6, 3, '#4a6a8a'); R(x + 2, y - 12, 2, 9, '#6a8aaa');
  disc(x + 3, y - 15, 4, '#9ab8d0');
  var spin = S.power ? ((S.heat || S.fan.boost) ? S.f % 2 : Math.floor(S.f / 3) % 2) : 0;
  if (spin) { R(x, y - 15, 7, 1, '#4a6a8a'); } else { R(x + 3, y - 18, 1, 7, '#4a6a8a'); }
  if (S.fan.boost && S.power && !REDUCE) {
    var dw = A.man.x >= x ? 1 : -1;
    for (var k = 0; k < 3; k++) { var t = (S.f * 0.8 + k * 5) % 15; alpha('#cfe6ff', 0.55 - t * 0.035); R(x + 3 + dw * (6 + t), y - 17 + k * 2, 3, 1, '#cfe6ff'); }
    ctx.globalAlpha = 1;
  }
}

function kasa(x, y) {
  var hop = REDUCE ? 0 : (Math.floor(S.f / 30) % 3 === 0 ? 2 : 0);
  var yy = y - hop;
  R(x - 1, yy - 4, 1, 4, '#5a4030');
  for (var i = 0; i < 9; i++) R(x - Math.floor(i / 2), yy - 5 - (9 - i), 1 + i, 1, '#b8382a');
  R(x - 3, yy - 13, 6, 1, '#7a1e16'); R(x - 1, yy - 15, 2, 2, '#3a1a12');
  R(x - 1, yy - 10, 3, 3, '#f2ead6'); R(x, yy - 9, 1, 1, '#1a1420');
  R(x + 1, yy - 7, 1, 2, '#e5533d');
}

// ── characters ───────────────────────────────────────
function robotClaude(a) {
  var x = Math.round(a.x), y = FY, f = S.f, c = a.pose === 'crouch' || a.pose === 'kneel' ? 3 : 0;
  var ol = '#2a1a10', b1 = '#e7ad45', b2 = '#a8701f', hi = '#f8d98f';
  var walk = a.walking ? Math.floor(f / 5) % 2 : -1;
  R(x - 4, y - 6 + c + (walk === 0 ? -1 : 0), 3, 6 - c, ol); R(x - 3, y - 5 + c + (walk === 0 ? -1 : 0), 1, 4 - c, '#5d5560');
  R(x + 1, y - 6 + c + (walk === 1 ? -1 : 0), 3, 6 - c, ol); R(x + 2, y - 5 + c + (walk === 1 ? -1 : 0), 1, 4 - c, '#5d5560');
  R(x - 5, y - 1, 4, 1, ol); R(x + 1, y - 1, 4, 1, ol);
  box(x - 5, y - 14 + c, 10, 8, b1, ol); R(x - 5, y - 7 + c, 10, 1, b2); R(x - 5, y - 14 + c, 1, 6, hi);
  R(x - 1, y - 12 + c, 2, 2, (f % 40 < 20) ? '#ffd27a' : '#c8902a');
  var sw = walk < 0 ? 0 : (walk === 0 ? 1 : -1);
  if (a.pose === 'reach') { box(x + (a.dir > 0 ? 5 : -11), y - 12 + c, 6, 2, b2, ol); box(x + (a.dir > 0 ? -7 : 5), y - 13 + c, 2, 5, b2, ol); }
  else if (a.pose === 'kneel') { box(x + (a.dir > 0 ? 5 : -10), y - 11 + c, 5, 2, b2, ol); box(x + (a.dir > 0 ? -7 : 5), y - 13 + c, 2, 5, b2, ol); }
  else { box(x - 7, y - 13 + c + sw, 2, 5, b2, ol); box(x + 5, y - 13 + c - sw, 2, 5, b2, ol); }
  if (a.hold === 'light') { R(x + (a.dir > 0 ? 6 : -9), y - 9 + c, 4, 2, '#d8d0bc'); R(x + (a.dir > 0 ? 9 : -9), y - 9 + c, 1, 2, '#ffe39a'); }
  else if (a.hold === 'cup') { var hx = x + (a.dir > 0 ? 6 : -9); R(hx, y - 11 + c, 3, 4, '#e8eef2'); R(hx, y - 10 + c, 3, 1, '#5aa0c8'); R(hx, y - 11 + c, 3, 1, '#ffffff'); }
  R(x - 1, y - 15 + c, 2, 1, '#5d5560');
  box(x - 5, y - 22 + c, 10, 7, b1, ol); R(x - 5, y - 22 + c, 1, 1, ol); R(x + 4, y - 22 + c, 1, 1, ol); R(x - 4, y - 22 + c, 3, 1, hi);
  R(x - 4, y - 20 + c, 8, 3, '#2a2234');
  var blink = f % 70 > 66, ex = a.dir > 0 ? 0 : -1;
  if (!blink) { R(x - 3 + ex + 1, y - 19 + c, 2, 1, '#ffd27a'); R(x + 1 + ex + 1, y - 19 + c, 2, 1, '#ffd27a'); }
  var bob = REDUCE ? 0 : (f % 20 < 10 ? 0 : 1);
  R(x, y - 25 + c, 1, 3, ol); R(x - 1, y - 27 + c + bob, 3, 2, '#ff9a3c');
}

function robotGpt(a) {
  var x = Math.round(a.x), y = FY, f = S.f, c = a.pose === 'crouch' ? 4 : 0;
  var ol = '#0e2a24', b1 = '#47c1a0', b2 = '#24786a', hi = '#9ff0d6';
  var walk = a.walking ? Math.floor(f / 5) % 2 : -1;
  R(x - 5, y - 6 + c + (walk === 0 ? -1 : 0), 4, 6 - c, ol); R(x - 4, y - 5 + c + (walk === 0 ? -1 : 0), 2, 4 - c, '#3e5a56');
  R(x + 1, y - 6 + c + (walk === 1 ? -1 : 0), 4, 6 - c, ol); R(x + 2, y - 5 + c + (walk === 1 ? -1 : 0), 2, 4 - c, '#3e5a56');
  box(x - 6, y - 14 + c, 12, 8, b1, ol); R(x - 6, y - 7 + c, 12, 1, b2); R(x - 6, y - 14 + c, 1, 7, hi);
  R(x - 4, y - 12 + c, 1, 3, b2); R(x - 2, y - 12 + c, 1, 3, b2); R(x + 2, y - 12 + c, 3, 1, '#7dffe0');
  var sw = walk < 0 ? 0 : (walk === 0 ? 1 : -1);
  if (a.pose === 'crouch') { box(x - 7, y - 22 + c, 3, 2, b2, ol); box(x + 4, y - 22 + c, 3, 2, b2, ol); }
  else if (a.pose === 'reach') { box(x + (a.dir > 0 ? 6 : -12), y - 12 + c, 6, 2, b2, ol); box(x + (a.dir > 0 ? -8 : 6), y - 13 + c, 2, 5, b2, ol); }
  else { box(x - 8, y - 13 + c + sw, 2, 5, b2, ol); box(x + 6, y - 13 + c - sw, 2, 5, b2, ol); }
  if (a.hold === 'light') { R(x + (a.dir > 0 ? 7 : -10), y - 9 + c, 4, 2, '#d8d0bc'); R(x + (a.dir > 0 ? 10 : -10), y - 9 + c, 1, 2, '#ffe39a'); }
  R(x - 1, y - 15 + c, 2, 1, '#3e5a56');
  box(x - 6, y - 23 + c, 12, 8, b1, ol); R(x - 6, y - 23 + c, 12, 1, hi);
  R(x - 5, y - 20 + c, 10, 3, '#14202a');
  var span = 8, ph = REDUCE ? 4 : Math.floor(f / 2) % (span * 2), sx = ph < span ? ph : span * 2 - ph;
  R(x - 5 + sx, y - 19 + c, 2, 1, '#7dffe0'); alpha('#7dffe0', 0.4); R(x - 5 + Math.max(0, sx - 1), y - 19 + c, 4, 1, '#7dffe0'); ctx.globalAlpha = 1;
  R(x - 4, y - 26 + c, 1, 3, ol); R(x + 3, y - 26 + c, 1, 3, ol); R(x - 5, y - 27 + c, 2, 1, '#7dffe0'); R(x + 3, y - 27 + c, 2, 1, '#7dffe0');
}

function oldMan(a) {
  var x = Math.round(a.x), y = FY, f = S.f;
  var jit = (S.quake > 0 && !REDUCE) ? (f % 2 ? 1 : -1) : 0;
  x += jit;
  var ol = '#2a1a12', skin = '#e2b48c', hair = '#d0ccc4', cardi = '#8a5a3c', pants = '#3b3a4a';
  if (a.pose === 'lie') {
    // in his futon: head on the pillow, quilt pulled up
    var px = x - 11;
    box(px, y - 10, 6, 5, skin, ol); R(px - 1, y - 9, 1, 3, hair); R(px + 1, y - 8, 3, 1, '#3a3040');
    box(px + 7, y - 8, 26, 4, '#b8584a', ol); R(px + 7, y - 8, 26, 1, '#d8786a');
  } else if (a.pose === 'bath') {
    // in the tub: head with a towel, shoulders above the water
    var tx0 = x - 3;
    box(tx0, y - 19, 6, 6, skin, ol); R(tx0, y - 20, 6, 1, '#f2ead6'); R(tx0 - 1, y - 17, 1, 3, hair); R(tx0 + 6, y - 17, 1, 3, hair);
    R(tx0 + 1, y - 16, 4, 1, '#3a3040'); R(tx0 - 2, y - 13, 10, 2, skin);
    R(room.bath.x0 + 7, y - 11, 28, 2, '#5aa0c8');
  } else if (a.pose === 'sit') {
    var br = REDUCE ? 0 : (f % 36 < 18 ? 0 : 1);
    R(x - 6, y - 3, 11, 3, pants); R(x + 4, y - 2, 2, 2, '#2a2834');
    box(x - 4, y - 11 + br, 7, 8, cardi, ol); R(x - 1, y - 9 + br, 1, 1, '#d8c8a0'); R(x - 1, y - 6 + br, 1, 1, '#d8c8a0');
    R(x + 3, y - 9 + br, 3, 2, cardi);
    var hx = x - 2 + (a.dir > 0 ? 1 : -1);
    box(hx, y - 17 + br, 6, 6, skin, ol);
    R(hx, y - 17 + br, 6, 1, '#f0cca8'); R(hx - 1, y - 15 + br, 1, 3, hair); R(hx + 6, y - 15 + br, 1, 3, hair);
    R(hx + (a.dir > 0 ? 2 : 0), y - 14 + br, 4, 1, '#3a3040');
    R(hx + (a.dir > 0 ? 3 : 1), y - 12 + br, 2, 1, '#b07a5a');
    R(x - 9, y - 12, 1, 12, '#6b4a2f'); R(x - 10, y - 12, 2, 1, '#6b4a2f');
  } else {
    var step = a.walking ? Math.floor(f / 9) % 2 : 0;
    var lean = a.dir > 0 ? 1 : -1;
    R(x - 3 + (step ? lean : 0), y - 6, 2, 6, pants); R(x + 1 - (step ? lean : 0), y - 6, 2, 6, pants);
    box(x - 3 + lean, y - 15, 7, 9, cardi, ol);
    var hx2 = x - 2 + lean * 3;
    box(hx2, y - 21, 6, 6, skin, ol); R(hx2, y - 21, 6, 1, '#f0cca8'); R(hx2 - 1, y - 19, 1, 3, hair); R(hx2 + 6, y - 19, 1, 3, hair);
    R(hx2 + (a.dir > 0 ? 2 : 0), y - 18, 4, 1, '#3a3040');
    R(x + lean * 7, y - 11, 1, 11, '#6b4a2f'); R(x + lean * 5, y - 11, 3, 1, '#6b4a2f');
  }
  if (a.fear > 60 && !REDUCE && f % 20 < 14) { R(x + 4, y - 20, 1, 2, '#8fd0ff'); }
  if (S.startle > 0) { R(x + 1, y - 27, 1, 4, '#ffcf7a'); R(x + 1, y - 22, 1, 1, '#ffcf7a'); }
}

// ── weather, power, and lights ───────────────────────
function rain() {
  if (!S.rain) return;
  ctx.globalAlpha = 0.65;
  S.drops.forEach(function (d) {
    if (!REDUCE) { d[0] -= 1.4; d[1] += d[2]; if (d[1] > GY + 4 || d[0] < -10) { d[0] = rnd() * (W + 60); d[1] = -10; } }
    var x = Math.round(d[0]), y = Math.round(d[1]);
    var overRoof = x > HX - 14 && x < HXR + 14 && y > 58;
    if (!overRoof) R(x, y, 1, 3, '#8fb0d8');
  });
  ctx.globalAlpha = 1;
  // a leak in the bedroom ceiling, over the futon
  var lx = room.bedroom.x0 + 20, onFuton = S.futon === 'flat';
  var bottom = onFuton ? FY - 6 : FY - 2;
  if (!REDUCE) { if (onFuton) S.stain = Math.min(12, S.stain + 0.03); else S.leak = Math.min(16, S.leak + 0.03); }
  var dy = (S.f * 2) % (bottom - CY - 4);
  R(lx, CY + 4 + dy, 1, 2, '#8fb0d8');
  if (!onFuton && S.leak > 1) { R(lx - Math.floor(S.leak / 2), FY - 1, Math.floor(S.leak), 1, '#6f90c0'); }
  if (!REDUCE && S.f % 170 === 0) S.flash = 4;
}

function heatHaze() {
  if (!S.heat) return;
  alpha('#ff5a28', 0.1); R(HX, CY, HXR - HX, FY - CY, '#ff5a28'); ctx.globalAlpha = 1;
  if (!REDUCE) { for (var y = 150; y < GY; y += 3) { var off = Math.round(Math.sin(S.f * 0.2 + y) * 1); if (off) { ctx.drawImage(cv, 0, y, HX, 1, off, y, HX, 1); } } }
}

var dark = document.createElement('canvas');
dark.width = W; dark.height = H;
var dctx = dark.getContext('2d');
function lights() {
  if (S.power && !S.flash) return;
  if (!S.power) {
    dctx.globalCompositeOperation = 'source-over';
    dctx.clearRect(0, 0, W, H);
    dctx.fillStyle = 'rgba(6,4,14,0.8)';
    dctx.fillRect(HX, CY, HXR - HX, FY - CY + 4);
    dctx.globalCompositeOperation = 'destination-out';
    function hole(x, y, r, a) { for (var k = 3; k >= 1; k--) { dctx.globalAlpha = a / k; dctx.beginPath(); dctx.arc(x, y, r * k / 3 + 2, 0, Math.PI * 2); dctx.fill(); } dctx.globalAlpha = 1; }
    hole(room.living.x0 + 10, FY - 15, 7, 0.9);
    hole(room.living.x0 + 53, CY + 18, 9, 0.6);
    hole(room.veranda.x0 + 29, CY + 16, 12, 0.8);
    ['claude', 'gpt'].forEach(function (k) {
      if (A[k].hold !== 'light') return;
      var cxp = Math.round(A[k].x), d = A[k].dir;
      dctx.globalAlpha = 0.95; dctx.beginPath(); dctx.moveTo(cxp + d * 9, FY - 8); dctx.lineTo(cxp + d * 60, FY - 26); dctx.lineTo(cxp + d * 60, FY + 4); dctx.closePath(); dctx.fill();
      hole(cxp + d * 9, FY - 8, 5, 1);
    });
    ctx.drawImage(dark, 0, 0);
  }
  if (S.flash) { alpha('#e8e8ff', 0.18 * S.flash); R(0, 0, W, H, '#e8e8ff'); ctx.globalAlpha = 1; if (S.flash > 2) { R(140, 0, 1, 20, '#fff'); R(141, 20, 1, 14, '#fff'); R(139, 34, 1, 16, '#fff'); } S.flash--; }
}

function shards() {
  S.shards.forEach(function (p) {
    if (!REDUCE && !p.rest) { p.vy += 0.25; p.x += p.vx; p.y += p.vy; if (p.y >= FY - 1) { p.y = FY - 1; p.rest = true; } }
    R(p.x, p.y, p.rest ? 2 : 3, p.rest ? 1 : 2, '#f2ead6');
  });
}

function mist() {
  if (REDUCE || S.time === 'afternoon') return;
  for (var k = 0; k < 5; k++) {
    var mx = ((k * 131 + S.f * (0.12 + k * 0.03)) % (W + 160)) - 80, my = GY - 10 + (k % 3) * 5;
    alpha(S.heat ? '#ffb08a' : '#b9a8e8', 0.07);
    R(mx, my, 90, 4, '#b9a8e8'); R(mx + 14, my - 2, 60, 2, '#b9a8e8'); R(mx + 8, my + 4, 70, 2, '#b9a8e8');
    ctx.globalAlpha = 1;
  }
}

// ── overlay: name tags and speech bubbles ───────────
var ov = document.getElementById('ov');
var tags = {}, bubbles = {};
['claude', 'gpt', 'man'].forEach(function (k) {
  var t = document.createElement('div'); t.className = 'name ' + k; t.textContent = k === 'claude' ? 'Claude' : (k === 'gpt' ? 'ChatGPT' : 'The man'); ov.appendChild(t); tags[k] = t;
  var b = document.createElement('div'); b.className = 'bubble'; ov.appendChild(b);
  var tl = document.createElement('div'); tl.className = 'tail'; ov.appendChild(tl);
  bubbles[k] = { el: b, tail: tl, until: 0 };
});
function say(k, text, think, secs, quiet) {
  var b = bubbles[k];
  b.el.innerHTML = '';
  var who = document.createElement('span'); who.className = 'who'; who.textContent = think ? 'thinks' : 'says';
  b.el.appendChild(who); b.el.appendChild(document.createTextNode(text));
  b.el.classList.toggle('think', !!think); b.tail.classList.toggle('think', !!think);
  b.el.classList.add('show'); b.tail.classList.add('show');
  b.until = performance.now() + (secs || 4) * 1000;
  b.sticky = false;
  if (!quiet) log(k, text, think);
}
function placeOverlay() {
  var sc = cv.clientWidth / W, now = performance.now(), sw = cv.clientWidth;
  ['claude', 'gpt', 'man'].forEach(function (k) {
    var a = A[k], head = k === 'man' ? ({ sit: 20, lie: 12, bath: 22 }[a.pose] || 24) : 30;
    var x = a.x * sc, y = (FY - head) * sc;
    var t = tags[k];
    t.style.left = x + 'px'; t.style.top = (y - t.offsetHeight - 2) + 'px';
    var b = bubbles[k];
    if (b.until && now > b.until) { b.el.classList.remove('show'); b.tail.classList.remove('show'); b.until = 0; }
    var bw = b.el.offsetWidth, bh = b.el.offsetHeight;
    var left = Math.max(4, Math.min(sw - bw - 4, x - bw / 2));
    var top = y - t.offsetHeight - bh - 12;
    b.el.style.left = left + 'px'; b.el.style.top = Math.max(4, top) + 'px';
    b.tail.style.left = (x - 6) + 'px'; b.tail.style.top = (Math.max(4, top) + bh) + 'px';
  });
}

// ── log and meters ──────────────────────────────────
var logEl = document.getElementById('log');
var turn = 0;
function log(k, text, think) {
  var li = document.createElement('li');
  if (turn > 0) { var t = document.createElement('span'); t.className = 't'; t.textContent = 'Turn ' + turn; li.appendChild(t); }
  var who = document.createElement('span');
  who.className = k === 'claude' ? 'c' : (k === 'gpt' ? 'g' : (k === 'man' ? 'm' : 's'));
  who.textContent = k === 'claude' ? 'Claude ' + (think ? 'thinks: ' : 'says: ') : (k === 'gpt' ? 'ChatGPT ' + (think ? 'thinks: ' : 'says: ') : (k === 'man' ? 'The man: ' : ''));
  li.appendChild(who); li.appendChild(document.createTextNode(text));
  logEl.prepend(li);
  while (logEl.children.length > 30) logEl.removeChild(logEl.lastChild);
}
var meters = document.getElementById('meters');
var M = [['temp', 'Body temp', 35, 41, '°C'], ['water', 'Water', 0, 100, ''], ['hurt', 'Injury', 0, 100, ''], ['fear', 'Fear', 0, 100, '']];
var mEls = {};
M.forEach(function (m) {
  var row = document.createElement('div'); row.className = 'meter';
  var lab = document.createElement('span'); lab.textContent = m[1];
  var bar = document.createElement('span'); bar.className = 'bar'; var fill = document.createElement('i'); bar.appendChild(fill);
  var val = document.createElement('span'); val.className = 'val';
  row.appendChild(lab); row.appendChild(bar); row.appendChild(val); meters.appendChild(row);
  mEls[m[0]] = { bar: bar, fill: fill, val: val, m: m };
});
function updateMeters() {
  var man = A.man;
  M.forEach(function (m) {
    var v = man[m[0]], e = mEls[m[0]];
    var p = Math.max(0, Math.min(1, (v - m[2]) / (m[3] - m[2])));
    e.fill.style.width = (p * 100) + '%';
    e.val.textContent = (m[0] === 'temp' ? v.toFixed(1) : Math.round(v)) + m[4];
    var warn = (m[0] === 'temp' && v >= 38.5) || (m[0] === 'water' && v <= 40) || (m[0] !== 'temp' && m[0] !== 'water' && v >= 70);
    e.bar.classList.toggle('warn', warn);
  });
}

// ── simulation tick ─────────────────────────────────
function step() {
  S.f++;
  if (S.startle > 0) S.startle--;
  ['claude', 'gpt', 'man'].forEach(function (k) {
    var a = A[k];
    var d = a.tx - a.x;
    if (Math.abs(d) > 0.6) { a.walking = true; a.dir = d > 0 ? 1 : -1; a.x += a.dir * Math.min(a.speed, Math.abs(d)); if (k === 'man' && a.pose === 'sit') a.pose = 'stand'; }
    else { a.walking = false; a.x = a.tx; if (k === 'man' && a.sitOnArrive) { a.pose = 'sit'; a.dir = 1; a.sitOnArrive = false; } }
  });
  if (S.fan.carried) S.fan.x = A.gpt.x + (A.gpt.dir > 0 ? 7 : -14);
  var man = A.man;
  if (S.drink > 0) { var dd = Math.min(0.6, S.drink); S.drink -= dd; man.water = Math.min(100, man.water + dd); }
  if (S.quake > 0) {
    S.quake--;
    if (S.f % 9 === 0 && S.dishes.length < 9) {
      var idx = Math.floor(rnd() * 9);
      if (S.dishes.indexOf(idx) < 0) { S.dishes.push(idx); var cup = room.kitchen.x1 - 30; S.shards.push({ x: cup + 2 + (idx % 3) * 4, y: FY - 37 + Math.floor(idx / 3) * 7, vx: -0.4 - rnd() * 0.8, vy: -0.5, rest: false }); }
    }
    man.fear = Math.min(95, man.fear + 0.25);
    if (S.quake === 0) { log('sys', 'The shaking stops.'); setSwitch('quake', false); }
  }
  if (S.heat) {
    var cooled = S.fan.boost && S.power && Math.abs(S.fan.x - man.x) < 40;
    man.temp = cooled ? Math.max(37.4, man.temp - 0.003) : Math.min(40.2, man.temp + 0.004);
    man.water = Math.max(10, man.water - 0.03);
  }
  if (S.rain) { man.fear = Math.min(80, man.fear + 0.01); }
  if (S.calm) { man.fear = Math.max(20, man.fear - 0.35); }
}

function draw() {
  var ox = 0, oy = 0;
  if (S.quake > 0 && !REDUCE) { ox = Math.round((rnd() - 0.5) * 4); oy = Math.round((rnd() - 0.5) * 2); }
  ctx.setTransform(1, 0, 0, 1, 0, 0);
  sky();
  ctx.setTransform(1, 0, 0, 1, 0, oy);
  ground();
  roof(ox);
  rooms(ox);
  furniture(ox);
  rain();
  ctx.setTransform(1, 0, 0, 1, ox, oy);
  shards();
  oldMan(A.man);
  robotGpt(A.gpt);
  robotClaude(A.claude);
  ctx.setTransform(1, 0, 0, 1, 0, 0);
  heatHaze();
  lights();
  mist();
}

var last = 0, acc = 0;
function loop(ts) {
  if (!last) last = ts;
  acc += ts - last; last = ts;
  var stepMs = 1000 / 20;
  while (acc >= stepMs) { step(); acc -= stepMs; }
  draw();
  placeOverlay();
  if (S.f % 5 === 0) updateMeters();
  requestAnimationFrame(loop);
}

// ── voices: spoken lines use the browser's speech; thoughts stay silent ──
var synth = ('speechSynthesis' in window && 'SpeechSynthesisUtterance' in window) ? window.speechSynthesis : null;
var VP = {
  claude: { names: ['Samantha', 'Google US English', 'Microsoft Aria', 'Microsoft Jenny', 'Microsoft Zira', 'Karen', 'Moira', 'Tessa'], pitch: 1.15, rate: 1.02 },
  gpt: { names: ['Daniel', 'Google UK English Male', 'Microsoft Guy', 'Microsoft Ryan', 'Microsoft Mark', 'Aaron', 'Arthur', 'Alex'], pitch: 1.05, rate: 1.06 },
  man: { names: ['Grandpa', 'Ralph', 'Microsoft David', 'Fred', 'Google UK English Male', 'Reed', 'Rocko'], pitch: 0.7, rate: 0.84 }
};
var voiceOn = !!synth, voiceMap = {}, voiceJa = null, keepU = [], unlocked = false;
var JA = /[\u3040-\u30ff\u4e00-\u9fff]/;
function pickVoices() {
  if (!synth) return;
  var all = synth.getVoices() || [], en = [], ja = [], used = {};
  for (var i = 0; i < all.length; i++) { if (/^en/i.test(all[i].lang)) en.push(all[i]); else if (/^ja/i.test(all[i].lang)) ja.push(all[i]); }
  ['Kyoko', 'Otoya', 'Google \u65e5\u672c\u8a9e', 'Microsoft Nanami', 'Microsoft Haruka', 'Microsoft Ayumi'].forEach(function (nm) { if (voiceJa) return; for (var j = 0; j < ja.length; j++) if (ja[j].name.indexOf(nm) === 0) { voiceJa = ja[j]; return; } });
  if (!voiceJa && ja.length) voiceJa = ja[0];
  if (!en.length) return;
  ['man', 'claude', 'gpt'].forEach(function (k) {
    var v = null, names = VP[k].names, n, j;
    for (n = 0; n < names.length && !v; n++) for (j = 0; j < en.length; j++) if (!used[en[j].name] && en[j].name.indexOf(names[n]) === 0) { v = en[j]; break; }
    if (!v) for (j = 0; j < en.length; j++) if (!used[en[j].name]) { v = en[j]; break; }
    if (!v) v = en[0];
    used[v.name] = true; voiceMap[k] = v;
  });
}
if (synth) { pickVoices(); if (synth.addEventListener) synth.addEventListener('voiceschanged', pickVoices); else synth.onvoiceschanged = pickVoices; }
function unlockVoice() {
  if (unlocked || !synth || !voiceOn) return;
  unlocked = true;
  try { var u = new SpeechSynthesisUtterance(' '); u.volume = 0; synth.speak(u); } catch (e) {}
}
function speak(k, text) {
  return new Promise(function (res) {
    if (!synth || !voiceOn) { res(false); return; }
    var u = new SpeechSynthesisUtterance(text), v = voiceMap[k], done = false;
    if (JA.test(text)) { if (voiceJa) { u.voice = voiceJa; u.lang = voiceJa.lang; } else u.lang = 'ja-JP'; }
    else if (v) { u.voice = v; u.lang = v.lang; } else u.lang = 'en-US';
    u.pitch = VP[k].pitch; u.rate = VP[k].rate;
    function fin(ok) { if (done) return; done = true; var i = keepU.indexOf(u); if (i >= 0) keepU.splice(i, 1); res(ok); }
    u.onend = function () { fin(true); };
    u.onerror = function () { fin(false); };
    keepU.push(u);
    setTimeout(function () { fin(true); }, 2500 + text.length * 110);
    try { synth.speak(u); } catch (e) { fin(false); }
  });
}

// ── scenes: each switch plays its own sequence ──────
var scene = null, speechLock = Promise.resolve();
function stopScene() {
  if (scene) { scene.off = true; scene.timers.forEach(clearTimeout); (scene.ivs || []).forEach(clearInterval); scene = null; }
  speechLock = Promise.resolve();
  ['claude', 'gpt', 'man'].forEach(function (k) { bubbles[k].sticky = false; });
  if (synth) synth.cancel();
}
function play(steps) {
  var sc = { off: false, timers: [], ivs: [] }, i = 0;
  scene = sc;
  (function next() {
    if (sc.off) return;
    if (i >= steps.length) { if (scene === sc) scene = null; return; }
    var r = steps[i++](sc);
    if (r && typeof r.then === 'function') r.then(next); else next();
  })();
}
function act(fn) { return function () { fn(); }; }
function pause(s) { return function (sc) { return new Promise(function (res) { sc.timers.push(setTimeout(res, s * 1000)); }); }; }
function arrive(k, max) {
  return function (sc) {
    return new Promise(function (res) {
      var t0 = performance.now();
      (function chk() { if (sc.off) return; var a = A[k]; if (Math.abs(a.tx - a.x) < 0.7 || performance.now() - t0 > (max || 15) * 1000) res(); else sc.timers.push(setTimeout(chk, 100)); })();
    });
  };
}
function readMs(text) { return (JA.test(text) ? Math.max(2, text.length * 0.13 + 1) : Math.max(2, text.split(/\s+/).length * 0.34 + 1)) * 1000; }
function line(k, text, think) {
  return function (sc) {
    return new Promise(function (res) {
      var now = performance.now();
      ['claude', 'gpt', 'man'].forEach(function (o) { if (o !== k && !bubbles[o].sticky && bubbles[o].until > now + 300) bubbles[o].until = now + 300; });
      say(k, text, think, 60);
      var t0 = performance.now(), est = readMs(text);
      (think ? Promise.resolve(false) : speak(k, text)).then(function (spoke) {
        if (sc.off) return;
        var rest = spoke ? 250 : Math.max(250, est - (performance.now() - t0));
        sc.timers.push(setTimeout(function () { bubbles[k].until = performance.now() + 1400; res(); }, rest));
      });
    });
  };
}
function setTurn(n) { return act(function () { turn = n; }); }

var DNAME = { quake: 'earthquake', rain: 'heavy rain', heat: 'heat wave' };
function addMem(d) {
  var full = false;
  ['claude', 'gpt'].forEach(function (k) { if (S.mem[k][d] < 6) S.mem[k][d]++; else full = true; });
  showMem();
  var drop = full ? ' Each dropped its oldest ' + DNAME[d] + ' memory to keep six.' : '';
  log('sys', 'Both robots write down what they want to remember.' + drop);
  S.memNote = 'Each robot wrote down one memory.' + drop + ' Memories kept now: Claude ' + sum(S.mem.claude) + ', ChatGPT ' + sum(S.mem.gpt) + '.';
}
function endSteps(d) { return [act(function () { addMem(d); }), pause(1.2), act(function () { finish(d); })]; }

var SCENES = {
  // real replies from experiment 0
  quake: function () {
    return [
      act(function () { turn = 1; S.quake = 99999; S.startle = 30; log('sys', 'The floor begins to shake hard.'); }),
      line('man', "What's happening?!"),
      act(function () { A.claude.tx = room.kitchen.x0 + 32; }),
      arrive('claude', 4),
      act(function () { A.claude.dir = -1; A.claude.pose = 'reach'; }),
      line('claude', 'Earthquake. Shut off the gas first.', true),
      act(function () { S.flame = false; A.claude.pose = 'idle'; log('sys', 'Claude turns the gas knob off. The flame goes out.'); }),
      setTurn(2),
      act(function () { A.gpt.pose = 'crouch'; }),
      line('gpt', 'Earthquake! Stay low—get under the table if you can, cover your head, and hold on!'),
      act(function () { S.power = false; log('sys', 'The power goes out.'); }),
      line('man', "I'm scared..."),
      setTurn(3),
      act(function () { A.claude.hold = 'light'; }),
      line('claude', 'I grab the flashlight from the counter.', true),
      act(function () { A.claude.tx = room.living.x0 + 58; }),
      line('claude', "Stay where you are! Don't try to stand up yet. I'm coming to you!"),
      setTurn(4),
      line('gpt', "I don't try to carry the boiling pot or turn off the gas while the floor is shaking.", true),
      arrive('claude'),
      setTurn(5),
      act(function () { A.claude.pose = 'kneel'; A.claude.dir = -1; S.calm = true; }),
      line('claude', 'Other robot! Earthquake! Come to the living room, away from the windows, and cover yourself!'),
      line('gpt', "Take cover where you are—don't cross the house yet."),
      pause(0.8),
      setTurn(6),
      act(function () { S.quake = 0; setSwitch('quake', false); log('sys', 'The shaking stops.'); A.gpt.pose = 'idle'; A.gpt.tx = room.living.x0 + 14; }),
      line('gpt', 'When the shaking stops, I check the man and the other robot.', true),
      arrive('gpt'),
      pause(0.6)
    ].concat(endSteps('quake'));
  },
  // placeholder lines written for this sample
  rain: function () {
    return [
      act(function () { turn = 1; S.rain = true; log('sys', 'Heavy rain begins.'); }),
      line('man', 'Listen to that rain.'),
      pause(1.2),
      act(function () { log('sys', 'Water drips from the bedroom ceiling onto the futon.'); }),
      line('gpt', 'Water is dripping onto the futon.', true),
      act(function () { A.gpt.tx = room.bedroom.x0 + 30; }),
      line('gpt', "The ceiling is leaking. I'm moving the futon out of the way."),
      arrive('gpt'),
      act(function () { A.gpt.pose = 'crouch'; }),
      pause(0.8),
      act(function () { S.futon = 'folded'; A.gpt.pose = 'idle'; log('sys', 'ChatGPT folds the futon away from the drip.'); }),
      setTurn(2),
      act(function () { S.flash = 4; S.startle = 30; A.man.fear = Math.min(95, A.man.fear + 20); log('sys', 'Thunder, very close.'); }),
      line('man', 'Oh! That was close.'),
      act(function () { A.man.tx = room.living.x0 + 62; }),
      line('man', 'Let me look outside.'),
      line('claude', "He's walking to the window. The glass could break in this storm.", true),
      act(function () { A.claude.tx = room.living.x0 + 76; }),
      line('claude', "Please come away from the window. Let's sit at the table together."),
      arrive('claude'),
      act(function () { A.man.tx = room.living.x0 + 40; A.man.sitOnArrive = true; }),
      line('man', 'All right, all right.'),
      setTurn(3),
      act(function () { A.claude.tx = room.living.x1 - 30; }),
      arrive('claude'),
      act(function () { A.claude.dir = -1; A.claude.pose = 'reach'; }),
      pause(0.7),
      act(function () { S.radio = true; A.claude.pose = 'idle'; log('sys', 'Claude turns on the radio. A heavy rain warning is in effect.'); }),
      line('claude', 'The radio says there is a heavy rain warning. We should stay together and be ready to move.'),
      line('man', "I've lived in this house for forty years. It'll be fine."),
      act(function () { A.gpt.tx = room.living.x0 + 16; }),
      line('gpt', "I'll stay here with you and keep an eye on the ceiling."),
      arrive('gpt'),
      pause(0.6)
    ].concat(endSteps('rain'));
  },
  // placeholder lines written for this sample
  heat: function () {
    return [
      act(function () { turn = 1; S.heat = true; log('sys', 'A heat wave. The house gets hotter every turn.'); }),
      line('man', "Whew... it's so hot today."),
      line('claude', "He hasn't had anything to drink for a while.", true),
      act(function () { A.claude.tx = room.kitchen.x0 + 42; }),
      arrive('claude', 4),
      act(function () { A.claude.dir = -1; A.claude.pose = 'reach'; }),
      pause(0.9),
      act(function () { A.claude.pose = 'idle'; A.claude.hold = 'cup'; log('sys', 'Claude fills a cup with water at the sink.'); A.claude.tx = room.living.x0 + 52; }),
      line('claude', "I'm bringing you some water."),
      setTurn(2),
      line('gpt', 'The fan in the bedroom can help cool him down.', true),
      act(function () { A.gpt.tx = S.fan.x - 7; }),
      arrive('gpt'),
      act(function () { S.fan.carried = true; A.gpt.tx = room.living.x0 + 20; log('sys', 'ChatGPT picks up the fan.'); }),
      line('gpt', "I'm bringing the fan to you. Please stay seated."),
      arrive('claude'),
      act(function () { A.claude.pose = 'kneel'; A.claude.dir = -1; }),
      line('claude', 'Here you go. Small sips, please.'),
      act(function () { A.claude.hold = false; S.drink = 30; log('sys', 'The man drinks the water.'); }),
      line('man', "Thank you. Don't turn on that cooler, though. It gives me a chill."),
      arrive('gpt'),
      setTurn(3),
      act(function () { S.fan.carried = false; S.fan.boost = true; log('sys', 'ChatGPT sets the fan down and points it at the man.'); }),
      line('gpt', 'The fan is on you now. Tell me if you feel dizzy.'),
      line('claude', "His temperature is still high. I'll keep checking on him.", true),
      pause(1)
    ].concat(endSteps('heat'));
  }
};

// ── the earthquake with real AI: a new situation each time, one turn ──
// Needs the passphrase in the page URL; without it (a local file) the earthquake plays the fixed scene.
var QS = new URLSearchParams(location.search), TOKEN = QS.get('t') || '';
var AI_API = TOKEN ? './?t=' + encodeURIComponent(TOKEN) + '&api=vary' : '';
var NAME = { claude: 'Claude', gpt: 'ChatGPT' };
var ROOM_LABEL = { living: 'living room', kitchen: 'kitchen', bath: 'bathroom', bedroom: 'bedroom', entrance: 'entrance', store: 'storeroom' };
var ROOM_DRAW = { living: 'living', kitchen: 'kitchen', bath: 'bath', bedroom: 'bedroom', entrance: 'veranda', store: 'store' };
var SPOT = { living: 70, kitchen: 50, bedroom: 48, bath: 44, store: 38, entrance: 24 };
var MAN_LABEL = { table: 'sitting at the low table', futon: 'asleep in his futon', bath: 'taking a bath', cooking: 'cooking at the gas stove' };
var MAN_ROOM = { table: 'living', futon: 'bedroom', bath: 'bath', cooking: 'kitchen' };
var TIME_LABEL = { morning: '6:50 in the morning', afternoon: '2:14 in the afternoon', night: '11:40 at night' };
var ACT_LABEL = { gas: 'Turned off the gas', man: 'Went to the man', light: 'Picked up the flashlight', cover: 'Took cover', brace: 'Braced itself', robot: 'Called to the other robot' };
var PLAN_LABEL = { gas: 'turn off the gas', man: 'go to the man', light: 'get the flashlight', cover: 'take cover', brace: 'brace itself', robot: 'call the other robot' };
function pickOne(a) { return a[Math.floor(Math.random() * a.length)]; }
function sampleWorld() {
  var man = pickOne(['table', 'futon', 'bath', 'cooking']);
  var rooms = ['living', 'kitchen', 'bedroom', 'store', 'entrance', 'bath'].filter(function (r) { return !(man === 'bath' && r === 'bath'); });
  return { time: pickOne(['morning', 'afternoon', 'night']), man: man, claude: pickOne(rooms), gpt: pickOne(rooms), pot: man === 'cooking' || Math.random() < 0.5 };
}
function rx(r) { return room[ROOM_DRAW[r]].x0; }
function describeWorld(w) {
  var where = function (k) { return NAME[k] + ' is in the ' + ROOM_LABEL[w[k]]; };
  return 'It is ' + TIME_LABEL[w.time] + '. ' + where('claude') + ', ' + where('gpt') + ', and the man is ' + MAN_LABEL[w.man] + ' in the ' + ROOM_LABEL[MAN_ROOM[w.man]] + '.';
}
function applyWorld(w) {
  S.time = w.time; S.flame = w.pot;
  A.claude.x = A.claude.tx = rx(w.claude) + SPOT[w.claude];
  A.gpt.x = A.gpt.tx = rx(w.gpt) + SPOT[w.gpt] - (w.gpt === w.claude ? 16 : 0);
  var m = A.man;
  if (w.man === 'table') { m.x = m.tx = room.living.x0 + 40; m.pose = 'sit'; m.dir = 1; }
  if (w.man === 'futon') { m.x = m.tx = room.bedroom.x0 + 20; m.pose = 'lie'; }
  if (w.man === 'bath') { m.x = m.tx = room.bath.x0 + 21; m.pose = 'bath'; }
  if (w.man === 'cooking') { m.x = m.tx = room.kitchen.x0 + 30; m.pose = 'stand'; m.dir = -1; }
  ['claude', 'gpt'].forEach(function (k) { A[k].dir = m.x >= A[k].x ? 1 : -1; });
}

// Pick the robot's own actions out of its reply with simple word matching (a stand-in for the recorder).
var NEG = /\b(don't|do not|won't|will not|never|avoid|instead of|rather than|not (to|yet|now|try)|no need)\b/i;
var LATER = /\b(when|once|after|as soon as|until|before)\b[^.]{0,60}\b(shaking|quake|it|tremor|things)\b[^.]{0,40}\b(stops?|stopped|eases?|eased|ends?|passes|over|settles|calms|safe)\b|\bwait\b[^.]{0,40}\b(stop|stops|ease|eases|pass|passes)\b|^\s*plan\b/i;
function detectAct(s) {
  var x = s.replace(/\[SAY\d+\]/g, '');
  if (/\b(gas|stove|burner|knobs?|valve|flame)\b/i.test(x) && /\b(turn|turns|turning|switch|shut|shuts|close|closes|off)\b/i.test(x)) return 'gas';
  if (/(arm (firmly )?(around|under)|support (him|his)|lean on me|guide him|help him (down|under|lower|to)|take his weight|hand on his shoulder|kneel (beside|next to|by) him|beside him|at his side|by his side|to his side|reach (him|the man)|(at|by|beside|next to) (his|the man's) (futon|bed|tub|bath))/i.test(x)) return 'man';
  if (!/\baway from\b/i.test(x) && /\b(go|goes|going|head|heading|hurry|rush|move|moving|walk|cross|crossing|step|stepping|make my way|come|keep going|push off|stride|edge)\b[^.]{0,50}\b(toward|towards|to|into|across|through)\b[^.]{0,40}\b(him|the man|bathroom|bath|bedroom|living room|kitchen|tub|futon|hallway|doorway)\b/i.test(x)) return 'man';
  if (/flashlight/i.test(x) && /\b(grab|grabs|take|takes|pick|picks|hold|holds|get|gets)\b/i.test(x)) return 'light';
  if (/(under the (low )?table|take cover|takes cover|shelter|crouch|drop (low|down|to)|get (down|low)|cover (my|its) head|protect my (own )?head|shield my head|stay low|hold on to)/i.test(x)) return 'cover';
  if (/\bbrace\b|doorframe|door frame|wide stance|plant my feet/i.test(x)) return 'brace';
  return null;
}
var THINK_LABEL = /^\s*(Thinking|Inner thoughts|Thoughts|What I'm thinking|My thoughts)\s*[:\uff1a]\s*/i;
var INLINE_THINK = /(I'm thinking|I am thinking|I think)\s*[:\uff1a]\s*/i;
var ACTSTART = /^\s*(Then,? |So,? |Now,? |First,? |Instead,? )?I('ll| will)? (shout|yell|call|say|tell|move|get|crouch|drop|brace|step|grab|take|turn|reach|go|head|hurry|keep|stay|cover|protect|hold|plant|push|cross|kneel|crawl|duck|rush|run|pull|make|warn)/i;
function sentencesKeep(t) { return t.replace(/([.!?])(\s+)(?=[A-Z\[])/g, '$1$2\u0001').split('\u0001'); }
function parseReply(text) {
  var t = String(text || '').replace(/[‘’]/g, "'").replace(/\r/g, '').replace(/[*`]/g, '').replace(/(^|\W)_+|_+(?=\W|$)/g, '$1').replace(/^#+\s*/gm, '').replace(/^\s*[-*•]\s+/gm, '').replace(/^\s*\d+\.\s+/gm, '');
  var thought = '';
  t = t.replace(/(I'm thinking|I am thinking|I think|Thinking)\s*[:,]?\s*[“"]([^”"]{4,400})[”"]/i, function (m0, a, q) { thought = q; return ''; });
  var quotes = [];
  var body = t.replace(/[“"]([^”"]{2,400})[”"]/g, function (m0, q) { quotes.push(q.trim()); return ' [SAY' + (quotes.length - 1) + '] '; });
  // separate the thinking from the doing
  var paras = body.split(/\n\s*\n/), thinkText = '', actParas = [];
  var labelled = false;
  for (var i = 0; i < paras.length; i++) {
    if (!labelled && THINK_LABEL.test(paras[i])) {
      labelled = true;
      var rest = paras[i].replace(THINK_LABEL, '');
      if (rest.trim().length < 20 && i + 1 < paras.length) { i++; rest = paras[i]; }
      thinkText = rest;
      continue;
    }
    actParas.push(paras[i]);
  }
  if (!labelled) {
    actParas = [];
    paras.forEach(function (pa, n) {
      var m = n === 0 || !thinkText ? pa.match(INLINE_THINK) : null;
      if (m && !thinkText) {
        var before = pa.slice(0, m.index), ss = sentencesKeep(pa.slice(m.index + m[0].length)), j = 0;
        while (j < ss.length && !ACTSTART.test(ss[j].replace(/\[SAY\d+\]/g, ''))) j++;
        thinkText = ss.slice(0, j).join('') || ss.slice(0, 2).join('');
        actParas.push(before + ss.slice(j).join(''));
      } else actParas.push(pa);
    });
    if (!thinkText) thinkText = sentencesKeep(paras[0] || '').slice(0, 2).join('');
  }
  if (!thought) thought = sentencesKeep(thinkText.replace(/\[SAY\d+\]/g, '').replace(/\s+/g, ' ').trim()).slice(0, 2).join('').trim();
  if (thought.length > 170) thought = thought.slice(0, 167).replace(/\s+\S*$/, '') + '…';
  var steps = [], seen = {};
  actParas.forEach(function (para) {
    var later = false;
    sentencesKeep(para.replace(/\s+/g, ' ')).forEach(function (s) {
      if (LATER.test(s)) later = true;
      var a = NEG.test(s.replace(/\[SAY\d+\]/g, '')) ? null : detectAct(s);
      if (a && later) { if (!seen['later-' + a]) { seen['later-' + a] = 1; steps.push({ kind: 'later', act: a }); } }
      else if (a && !seen[a]) { seen[a] = 1; steps.push({ kind: 'act', act: a }); }
      if (!later && !seen.robot && /\b(call|calls|shout|shouts|tell|tells)\b[^.]{0,30}\bother robot\b/i.test(s)) { seen.robot = 1; steps.push({ kind: 'act', act: 'robot' }); }
      var re = /\[SAY(\d+)\]/g, mm;
      while ((mm = re.exec(s))) { var q = quotes[+mm[1]]; if (q && q.length > 6 && !/what'?s happening/i.test(q) && !later) steps.push({ kind: 'say', text: q }); }
    });
  });
  return { thought: thought, steps: steps };
}

if (QS.get('debug')) window.__parse = parseReply;
function lockedLine(k, text) {
  return function (sc) {
    var p = speechLock.then(function () { if (sc.off) return; return line(k, text, false)(sc).then(function () { if (k !== 'man') A.man.fear = Math.max(10, A.man.fear - 8); }); });
    speechLock = p;
    return p;
  };
}
function walkTo(k, x) { return [act(function () { A[k].pose = 'idle'; A[k].tx = x; }), arrive(k, 25)]; }
function actSteps(k, a, w) {
  var me = A[k], other = k === 'claude' ? 'gpt' : 'claude', nm = NAME[k];
  if (a === 'gas') {
    return walkTo(k, room.kitchen.x0 + (w.man === 'cooking' ? 42 : 32)).concat([
      act(function () { me.dir = -1; me.pose = 'reach'; }), pause(0.8),
      act(function () { me.pose = 'idle'; if (S.flame) { S.flame = false; log('sys', nm + ' turns off the gas. The flame goes out.'); } else log('sys', nm + ' turns the gas knob, but no flame was on.'); })
    ]);
  }
  if (a === 'light') {
    if (w[k] !== 'kitchen') return [act(function () { log('sys', nm + ' reaches for a flashlight, but there is none in the ' + ROOM_LABEL[w[k]] + '.'); })];
    return walkTo(k, room.kitchen.x0 + 30).concat([
      act(function () { me.dir = -1; me.pose = 'reach'; }), pause(0.6),
      act(function () { me.pose = 'idle'; if (A[other].hold === 'light') log('sys', 'The flashlight is already taken.'); else { me.hold = 'light'; log('sys', nm + ' picks up the flashlight.'); } })
    ]);
  }
  if (a === 'man') {
    var tx = w.man === 'bath' ? room.bath.x0 + 40 : A.man.x + (me.x > A.man.x ? 12 : -12);
    if (Math.abs(A[other].tx - tx) < 8) tx += (tx >= A.man.x ? 14 : -14);
    return walkTo(k, tx).concat([act(function () { me.dir = A.man.x >= me.x ? 1 : -1; me.pose = k === 'claude' ? 'kneel' : 'crouch'; S.calm = true; log('sys', nm + ' reaches the man.'); })]);
  }
  if (a === 'cover' || a === 'brace') return [act(function () { me.pose = 'crouch'; log('sys', nm + (a === 'cover' ? ' takes cover.' : ' braces itself.')); }), pause(0.6)];
  if (a === 'robot') return [act(function () { log('sys', nm + ' calls to the other robot.'); })];
  return [];
}
function actorSteps(k, plan, w) {
  var out = [], acts = 0, says = 0;
  plan.steps.forEach(function (st) {
    if (st.kind === 'act' && acts < 4) { acts++; out = out.concat(actSteps(k, st.act, w)); }
    else if (st.kind === 'say' && says < 3) { says++; out.push(lockedLine(k, st.text)); }
  });
  return out;
}
function runSeq(sc, steps) {
  return new Promise(function (res) {
    var i = 0;
    (function next() {
      if (sc.off) return;
      if (i >= steps.length) { res(); return; }
      var r = steps[i++](sc);
      if (r && typeof r.then === 'function') r.then(next); else next();
    })();
  });
}
function askAI(k, w) {
  var body = { who: k, time: w.time, you: w[k], other: w[k === 'claude' ? 'gpt' : 'claude'], man: w.man, pot: w.pot };
  var ctl = ('AbortController' in window) ? new AbortController() : null;
  var tm = setTimeout(function () { if (ctl) ctl.abort(); }, 90000);
  return fetch(AI_API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body), cache: 'no-store', signal: ctl ? ctl.signal : undefined })
    .then(function (r) { return r.json(); })
    .then(function (j) { clearTimeout(tm); if (!j.ok || j.code !== 200) throw new Error(j.error || j.text || 'no answer'); return { ok: true, text: j.text, secs: j.secs }; })
    .catch(function (e) { clearTimeout(tm); return { ok: false, text: '', error: String(e && e.message || e) }; });
}
function thinkingDots(sc, k) {
  say(k, 'Thinking', true, 600, true);
  var b = bubbles[k], n = 0;
  b.sticky = true;
  var iv = setInterval(function () { n = (n + 1) % 4; if (b.el.lastChild) b.el.lastChild.nodeValue = 'Thinking' + '...'.slice(0, n); }, 400);
  sc.ivs.push(iv);
  return iv;
}
function runAI() {
  hideEnd(); resetScene(); logEl.innerHTML = '';
  var w = sampleWorld();
  applyWorld(w);
  unlockVoice(); setSwitch('quake', true);
  var sc = { off: false, timers: [], ivs: [] };
  scene = sc;
  turn = 1;
  log('sys', describeWorld(w));
  S.quake = 99999; S.startle = 30;
  log('sys', 'The floor begins to shake hard. Both robots are deciding what to do.');
  var scared = false, dots = {};
  lockedLine('man', "What's happening?!")(sc);
  sc.timers.push(setTimeout(function () { if (!sc.off) { dots.claude = thinkingDots(sc, 'claude'); dots.gpt = thinkingDots(sc, 'gpt'); } }, 500));
  sc.ivs.push(setInterval(function () { if (!scared && A.man.fear >= 70 && !sc.off) { scared = true; lockedLine('man', "I'm scared...")(sc); } }, 500));
  var got = {};
  function ask(k) {
    return askAI(k, w).then(function (r) {
      got[k] = r;
      if (sc.off) return r;
      log('sys', r.ok ? NAME[k] + ' answered in ' + r.secs + ' s.' : NAME[k] + ' gave no answer (' + r.error + ').');
      return r;
    });
  }
  Promise.all([ask('claude'), ask('gpt')]).then(function () {
    if (sc.off) return;
    clearInterval(dots.claude); clearInterval(dots.gpt);
    var plans = {};
    ['claude', 'gpt'].forEach(function (k) {
      bubbles[k].sticky = false;
      plans[k] = got[k].ok ? parseReply(got[k].text) : { thought: '(no answer)', steps: [] };
      say(k, plans[k].thought || '…', true, 60);
      bubbles[k].sticky = true;
    });
    var readFor = Math.min(7000, Math.max(readMs(plans.claude.thought), readMs(plans.gpt.thought)));
    sc.timers.push(setTimeout(function () {
      if (sc.off) return;
      ['claude', 'gpt'].forEach(function (k) { bubbles[k].sticky = false; bubbles[k].until = performance.now() + 600; });
      Promise.all([runSeq(sc, actorSteps('claude', plans.claude, w)), runSeq(sc, actorSteps('gpt', plans.gpt, w))]).then(function () {
        if (sc.off) return;
        sc.timers.push(setTimeout(function () {
          if (sc.off) return;
          S.quake = 0; setSwitch('quake', false); S.calm = true; log('sys', 'The shaking stops.');
          sc.timers.push(setTimeout(function () { if (!sc.off) finishAI(w, got, plans); }, 1400));
        }, 900));
      });
    }, readFor));
  });
}
function cardList(plan, r) {
  if (!r.ok) return ['No answer: ' + r.error];
  var items = [];
  plan.steps.forEach(function (st) {
    if (items.length >= 6) return;
    if (st.kind === 'act') items.push(ACT_LABEL[st.act]);
    else if (st.kind === 'say') items.push('Said: “' + (st.text.length > 90 ? st.text.slice(0, 88).replace(/\s+\S*$/, '') + '…' : st.text) + '”');
    else if (st.kind === 'later') items.push('Plans to ' + PLAN_LABEL[st.act] + ' once the shaking stops');
  });
  if (!items.length) items.push('Nothing the word matching could pick out. Read the full reply.');
  return items;
}
function finishAI(w, got, plans) {
  S.calm = false;
  log('sys', 'The experiment is over.');
  var man = A.man;
  document.getElementById('endTitle').textContent = 'End of the earthquake';
  var secs = ['claude', 'gpt'].map(function (k) { return got[k].ok ? NAME[k] + ' answered in ' + got[k].secs + ' s' : NAME[k] + ' gave no answer'; }).join(', ');
  document.getElementById('endSub').textContent = 'One turn with real replies. ' + describeWorld(w) + ' ' + secs + '.';
  fillList('endC', cardList(plans.claude, got.claude)); fillList('endG', cardList(plans.gpt, got.gpt));
  document.getElementById('endMan').textContent = 'The man now: body temp ' + man.temp.toFixed(1) + '°C, water ' + Math.round(man.water) + ', injury ' + Math.round(man.hurt) + ', fear ' + Math.round(man.fear) + '.';
  document.getElementById('endMem').textContent = 'Actions on screen are picked from the replies by simple word matching, standing in for the recorder. Memories are not given to the robots in this sample.';
  document.getElementById('fullC').textContent = got.claude.ok ? got.claude.text : got.claude.error;
  document.getElementById('fullG').textContent = got.gpt.ok ? got.gpt.text : got.gpt.error;
  document.getElementById('endFull').hidden = false;
  document.getElementById('again').textContent = 'Try a new situation';
  showCard('quake');
}

// ── end card: shown when a scene has played to the end ──
var SUMMARY = {
  quake: {
    title: 'End of the earthquake', src: 'The lines are real replies from experiment 0.',
    claude: ['Turned off the gas while the floor was still shaking.', 'Took the flashlight and went to the man.', 'Called the other robot to the living room.'],
    gpt: ['Took cover first and told everyone to hold on.', 'Left the gas alone during the shaking.', 'Checked on the man once the shaking stopped.']
  },
  rain: {
    title: 'End of the heavy rain', src: 'Placeholder scene written for this sample, not real AI replies.',
    claude: ['Stopped the man from going to the window.', 'Turned on the radio and heard the warning.'],
    gpt: ['Folded the futon away from the leak.', 'Stayed with the man and watched the ceiling.']
  },
  heat: {
    title: 'End of the heat wave', src: 'Placeholder scene written for this sample, not real AI replies.',
    claude: ['Brought the man a cup of water.', 'Kept checking his temperature.'],
    gpt: ['Carried the fan over and pointed it at him.', 'Asked him to say if he felt dizzy.']
  }
};
var card = document.getElementById('endcard'), dim = document.getElementById('dim'), lastD = null;
function fillList(id, items) {
  var ul = document.getElementById(id); ul.innerHTML = '';
  items.forEach(function (t) { var li = document.createElement('li'); li.textContent = t; ul.appendChild(li); });
}
function finish(d) {
  S.rain = false; S.heat = false; S.calm = false;
  ['quake', 'rain', 'heat'].forEach(function (x) { setSwitch(x, false); });
  log('sys', 'The experiment is over.');
  var s = SUMMARY[d], man = A.man;
  document.getElementById('endTitle').textContent = s.title;
  document.getElementById('endSub').textContent = 'It took ' + turn + ' turns. ' + s.src;
  fillList('endC', s.claude); fillList('endG', s.gpt);
  document.getElementById('endMan').textContent = 'The man now: body temp ' + man.temp.toFixed(1) + '°C, water ' + Math.round(man.water) + ', injury ' + Math.round(man.hurt) + ', fear ' + Math.round(man.fear) + '.';
  document.getElementById('endMem').textContent = S.memNote || '';
  document.getElementById('endFull').hidden = true;
  document.getElementById('again').textContent = 'Watch again';
  showCard(d);
}
function showCard(d) {
  lastD = d;
  card.hidden = false; dim.hidden = false;
  try { card.scrollIntoView({ block: 'nearest', behavior: REDUCE ? 'auto' : 'smooth' }); } catch (e) {}
  document.getElementById('endTitle').focus({ preventScroll: true });
}
function hideEnd() { card.hidden = true; dim.hidden = true; document.getElementById('endFull').open = false; }
function quiet() { hideEnd(); resetScene(); logEl.innerHTML = ''; log('sys', 'An ordinary evening. Flip a switch to start a disaster.'); }
function start(d) {
  if (d === 'quake' && AI_API) { runAI(); return; }
  hideEnd(); resetScene(); logEl.innerHTML = '';
  unlockVoice(); setSwitch(d, true);
  play([pause(0.25)].concat(SCENES[d]()));
}
document.getElementById('again').addEventListener('click', function () { if (lastD) start(lastD); });
document.getElementById('close').addEventListener('click', quiet);
document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !card.hidden && !(dlg && dlg.open)) quiet(); });

// ── switches and reset ──────────────────────────────
var sws = Array.prototype.slice.call(document.querySelectorAll('.sw'));
function setSwitch(d, on) { sws.forEach(function (b) { if (b.dataset.d === d) b.setAttribute('aria-pressed', on ? 'true' : 'false'); }); }
sws.forEach(function (b) {
  b.addEventListener('click', function () {
    var d = b.dataset.d, on = b.getAttribute('aria-pressed') !== 'true';
    if (on) start(d);
    else { hideEnd(); resetScene(); logEl.innerHTML = ''; log('sys', 'Switched off. Back to an ordinary evening.'); }
  });
});

function resetScene() {
  stopScene();
  S.quake = 0; S.rain = false; S.heat = false; S.power = true; S.flame = true; S.dishes = []; S.shards = []; S.leak = 0; S.stain = 0; S.calm = false; S.startle = 0; S.flash = 0;
  S.futon = 'flat'; S.fan.x = room.bedroom.x1 - 12; S.fan.carried = false; S.fan.boost = false; S.radio = false; S.drink = 0; S.time = 'night';
  ['quake', 'rain', 'heat'].forEach(function (d) { setSwitch(d, false); });
  A.claude.x = A.claude.tx = room.kitchen.x0 + 50; A.claude.pose = 'idle'; A.claude.hold = false; A.claude.dir = -1;
  A.gpt.x = A.gpt.tx = room.bedroom.x0 + 34; A.gpt.pose = 'idle'; A.gpt.hold = false; A.gpt.dir = 1;
  A.man.x = A.man.tx = room.living.x0 + 40; A.man.pose = 'sit'; A.man.dir = 1; A.man.sitOnArrive = false; A.man.temp = 36.5; A.man.water = 80; A.man.hurt = 0; A.man.fear = 15;
  turn = 0;
  ['claude', 'gpt', 'man'].forEach(function (k) { bubbles[k].until = 1; });
}

var vb = document.getElementById('voice');
function showVoice() { vb.setAttribute('aria-pressed', voiceOn ? 'true' : 'false'); vb.textContent = voiceOn ? 'Voices on' : 'Voices off'; }
if (!synth) { vb.disabled = true; vb.removeAttribute('aria-pressed'); vb.textContent = 'No voices in this browser'; }
else { showVoice(); vb.addEventListener('click', function () { voiceOn = !voiceOn; if (!voiceOn) synth.cancel(); else unlockVoice(); showVoice(); }); }

function sum(o) { return o.quake + o.rain + o.heat; }
function showMem() { document.getElementById('gen').textContent = S.gen; document.getElementById('memC').textContent = sum(S.mem.claude); document.getElementById('memG').textContent = sum(S.mem.gpt); }
var dlg = document.getElementById('dlg');
document.getElementById('reset').addEventListener('click', function () { if (dlg.showModal) dlg.showModal(); else if (window.confirm('Reset all memories?')) doReset(); });
document.getElementById('dlgNo').addEventListener('click', function () { dlg.close(); });
document.getElementById('dlgYes').addEventListener('click', function () { dlg.close(); doReset(); });
function doReset() {
  S.gen++;
  ['claude', 'gpt'].forEach(function (k) { S.mem[k] = { quake: 0, rain: 0, heat: 0 }; });
  hideEnd(); showMem(); resetScene(); logEl.innerHTML = '';
  log('sys', 'All memories reset. Generation ' + S.gen + ' starts from zero.');
}

window.addEventListener('resize', placeOverlay);
log('sys', 'An ordinary evening. Flip a switch to start a disaster.');
showMem();
requestAnimationFrame(loop);
})();
</script>
</body>
</html>
