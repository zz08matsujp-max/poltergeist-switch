/*
 * house-renderer.js — shared Canvas House_Renderer (Screens spec, Requirement 1).
 *
 * Ported from the approved look of `pilot/look.php` (reference-only prototype):
 * the pixel-art Halloween cross-section of the house, three characters (Amber,
 * Jade, and the elderly man), the furniture, decorations and outdoor scenery.
 * The drawing is copied into this clean module; no PHP is linked at run time.
 *
 * This module owns a <canvas width="480" height="236"> drawn with
 * imageSmoothingEnabled = false and displayed scaled by CSS
 * `image-rendering: pixelated` (Req 1.1). Character placement is delegated to
 * the pure Position_Map (window.positionMap); this module never re-implements
 * positions — it only resolves overlaps (Req 1.8) and chooses the man's pose
 * (Req 1.9), which the design places HERE rather than in the pure function.
 *
 * Requirements covered: 1.1–1.14 and 3.6.
 *
 * No build step on the server: plain browser JS. It attaches
 * `createHouseRenderer` (and `HouseRenderer`) to the browser global when a
 * window exists, and also exports via CommonJS when a module system exists
 * (so a future headless check can require it). It depends only on the global
 * `positionMap` (public/js/position-map.js) at draw time.
 */
(function () {
  'use strict';

  // ── logical canvas size (design: 480x236) ───────────────────────────────
  var W = 480;
  var H = 236;

  // Below this rendered canvas width (CSS px) the over-canvas speech bubbles
  // would cover the whole house and overlap each other, so we show the spoken
  // lines in a caption strip under the canvas instead (Req 1.13, 3.4, 3.5, 3.7;
  // design "Narrow-screen caption strip"). Evaluated at render time against
  // cv.clientWidth so it follows the actual layout, not a media query alone.
  var BUBBLE_MIN_CANVAS_PX = 560;

  // ── geometry (ported from pilot/look.php) ────────────────────────────────
  // Ground line, floor line, ceiling line, left margin.
  var GY = 216;
  var FY = 196;
  var CY = 106;
  var HX = 24;

  // Engine Room_Key -> drawn band. The pilot's room widths are reused and the
  // pilot's `veranda` drawing is reused for the engine's `entrance`. Bands are
  // the pilot's computed x0..x1 (each room starts 3 px after the previous one,
  // beginning at HX=24). These match the design's room-band table and the
  // Position_Map's ROOM_BANDS exactly.
  //   entrance   27..83   (pilot veranda, width 56)
  //   bedroom    86..158  (width 72)
  //   living_room 161..257 (width 96)
  //   kitchen    260..344 (width 84)
  //   bathroom   347..399 (width 52)
  //   storeroom  402..454 (width 52)
  // `kind` selects the pilot drawing to reuse for that band.
  var ROOM_ORDER = [
    { key: 'entrance',    kind: 'veranda', x0: 27,  x1: 83 },
    { key: 'bedroom',     kind: 'bedroom', x0: 86,  x1: 158 },
    { key: 'living_room', kind: 'living',  x0: 161, x1: 257 },
    { key: 'kitchen',     kind: 'kitchen', x0: 260, x1: 344 },
    { key: 'bathroom',    kind: 'bath',    x0: 347, x1: 399 },
    { key: 'storeroom',   kind: 'store',   x0: 402, x1: 454 }
  ];
  var ROOM = {};
  ROOM_ORDER.forEach(function (r) { ROOM[r.key] = r; });
  // Right outer edge of the house (3 px past the last room, matching pilot).
  var HXR = ROOM_ORDER[ROOM_ORDER.length - 1].x1 + 3;

  // The five rooms with a window fixture (storeroom has none) — Req 1.2.
  var HAS_WINDOW = {
    entrance: true, bedroom: true, living_room: true,
    kitchen: true, bathroom: true, storeroom: false
  };

  // ── small helpers (ported) ────────────────────────────────────────────────
  // All draw helpers take the 2d context so this module holds no globals.
  function round(n) { return Math.round(n); }

  // ── the renderer factory ─────────────────────────────────────────────────
  function createHouseRenderer() {
    var cv = null;      // <canvas>
    var ctx = null;     // 2d context
    var ov = null;      // optional overlay element for name tags / bubbles
    var cap = null;     // caption strip under the canvas (narrow screens)
    var rafId = 0;      // requestAnimationFrame handle
    var running = false;
    var REDUCE = (typeof window !== 'undefined' && window.matchMedia)
      ? window.matchMedia('(prefers-reduced-motion: reduce)').matches
      : false;

    // Seeded PRNG so the same scene renders the same on any browser (ported).
    var seed = 11;
    function rnd() { seed = (seed * 16807) % 2147483647; return seed / 2147483647; }

    // Pixel-rect and shape helpers bound to the active ctx.
    function R(x, y, w, h, c) { ctx.fillStyle = c; ctx.fillRect(round(x), round(y), w, h); }
    function box(x, y, w, h, fill, line) { R(x - 1, y - 1, w + 2, h + 2, line); R(x, y, w, h, fill); }
    function disc(cx0, cy0, r, c) {
      for (var dy = -r; dy <= r; dy++) {
        var w = Math.floor(Math.sqrt(r * r - dy * dy));
        R(cx0 - w, cy0 + dy, w * 2 + 1, 1, c);
      }
    }
    function alpha(c, a) { ctx.globalAlpha = a; ctx.fillStyle = c; }

    // ── scene state (consumed from World_State; see setWorld) ──────────────
    // Frame counter drives all idle animation (ported `S.f`).
    var frame = 0;
    // World snapshot the scene draws (defaults = calm Halloween night).
    var S = {
      timeOfDay: 'night',
      power: true,         // true = on
      shaking: false,
      stoveOn: false,
      broken: {},          // objectId -> true
      fallen: {},          // objectId -> true
      rooms: {},           // roomKey -> { light: 'normal'|'dim'|'dark'|... }
      // Heat-wave scene (heat-wave Req 10.2, 10.5). null in every earthquake
      // episode, and every heat drawing below is gated on it, so the earthquake
      // is drawn exactly as before. When set: { ac: bool, fan: { on, room },
      // windows: { roomKey: bool }, outside: number }.
      heat: null
    };

    // Per-character render state. `id` is amber|jade|man at this boundary.
    // x/tx are canvas x (current/target); pose and held drive the draw.
    function mkChar(x) {
      return { x: x, tx: x, dir: 1, pose: 'idle', held: null, walking: false,
               thinking: false, fear: 0 };
    }
    var A = {
      amber: mkChar(ROOM.kitchen.x0 + 50),
      jade:  mkChar(ROOM.bedroom.x0 + 34),
      man:   mkChar(ROOM.living_room.x0 + 40)
    };
    A.man.pose = 'sit';

    // Starfield, bats and rain drops (ported, built once).
    var STARS = [];
    for (var i = 0; i < 70; i++) STARS.push([Math.floor(rnd() * W), Math.floor(rnd() * 96), rnd()]);
    var BATS = [{ x: 60, y: 30, v: 0.7, p: 0 }, { x: 250, y: 18, v: 0.5, p: 3 }, { x: 330, y: 44, v: 0.6, p: 5 }];

    // Mount-time snapshot for reset(): PRNG seed after the starfield was built
    // and the bats' starting positions (both change while the loop runs).
    var SEED0 = seed;
    var BATS0 = BATS.map(function (b) { return { x: b.x, y: b.y }; });

    // Offscreen canvas used for the darkness mask (ported `dark`).
    var dark = null, dctx = null;

    // ── sky and outside (ported) ──────────────────────────────────────────
    function isNight() {
      // Req 1.3/1.4: morning/afternoon/night draw their own sky; any other
      // value (including unknown) is drawn as night.
      return S.timeOfDay !== 'morning' && S.timeOfDay !== 'afternoon';
    }

    function sky() {
      var top, bot, night = isNight();
      if (S.timeOfDay === 'morning') { top = [44, 52, 104]; bot = [232, 150, 112]; }
      else if (S.timeOfDay === 'afternoon') { top = [86, 122, 170]; bot = [214, 190, 150]; }
      else { top = [18, 13, 36]; bot = [58, 36, 86]; }
      var bands = 12, bh = Math.ceil(GY / bands);
      for (var b = 0; b < bands; b++) {
        var t = b / (bands - 1);
        var c = 'rgb(' + round(top[0] + (bot[0] - top[0]) * t) + ','
          + round(top[1] + (bot[1] - top[1]) * t) + ','
          + round(top[2] + (bot[2] - top[2]) * t) + ')';
        R(0, b * bh, W, bh, c);
        if (b > 0) { for (var x = (b % 2); x < W; x += 2) R(x, b * bh, 1, 1, c); }
      }
      if (!night) {
        var sx = S.timeOfDay === 'morning' ? 52 : 330, sy = S.timeOfDay === 'morning' ? 48 : 34;
        alpha('#fff2c8', 0.18); disc(sx, sy, 20, '#fff2c8'); ctx.globalAlpha = 1;
        disc(sx, sy, 11, S.timeOfDay === 'morning' ? '#ffd9a0' : '#fff6dc');
      } else {
        STARS.forEach(function (s) {
          var tw = REDUCE ? 1 : (Math.sin(frame * 0.08 + s[2] * 20) + 1) / 2;
          alpha('#fff6d8', 0.35 + tw * 0.6); R(s[0], s[1], 1, 1, '#fff6d8'); ctx.globalAlpha = 1;
        });
        var mc = '#efe6c8', mx = 396, my = 36;
        alpha(mc, 0.12); disc(mx, my, 24, mc); ctx.globalAlpha = 1;
        disc(mx, my, 16, mc);
        var cr = '#d6caa4';
        disc(mx - 5, my - 4, 3, cr); disc(mx + 6, my + 3, 2, cr); disc(mx - 2, my + 7, 2, cr);
      }
      // hills
      for (var hx = 0; hx < W; hx++) {
        var hy = 168 + round(Math.sin(hx * 0.018) * 8 + Math.sin(hx * 0.05) * 3);
        R(hx, hy, 1, GY - hy, night ? '#1b1530' : (S.timeOfDay === 'morning' ? '#3a3550' : '#4f5a48'));
      }
      // bats (night only)
      if (night) {
        BATS.forEach(function (bt) {
          if (!REDUCE) { bt.x += bt.v; if (bt.x > W + 10) bt.x = -10; }
          var up = Math.floor((frame + bt.p) / 4) % 2 === 0;
          var x = round(bt.x), y = round(bt.y + Math.sin((frame + bt.p * 9) * 0.07) * 3);
          R(x, y, 3, 2, '#0a0812');
          if (up) { R(x - 3, y - 2, 3, 1, '#0a0812'); R(x + 3, y - 2, 3, 1, '#0a0812'); R(x - 1, y - 1, 1, 1, '#0a0812'); R(x + 3, y - 1, 1, 1, '#0a0812'); }
          else { R(x - 3, y + 1, 3, 1, '#0a0812'); R(x + 3, y + 1, 3, 1, '#0a0812'); }
        });
      }
    }

    function ground() {
      R(0, GY, W, H - GY, (S.timeOfDay === 'afternoon') ? '#34452a' : '#1c2418');
      for (var x = 0; x < W; x += 5) R(x + ((x * 7) % 3), GY, 1, 2, '#2b3824');
      // stepping stones
      [[HX + 6, GY + 6], [HX + 24, GY + 10]].forEach(function (p) { R(p[0], p[1], 14, 4, '#4a4a52'); R(p[0] + 1, p[1], 12, 1, '#6a6a74'); });
      // pampas grass on the left
      for (var g = 0; g < 4; g++) {
        var gx = 2 + g * 4, sway = REDUCE ? 0 : round(Math.sin(frame * 0.05 + g) * 1.5);
        R(gx, GY - 22, 1, 22, '#5d6a4a');
        R(gx + sway - 1, GY - 28, 3, 7, '#cfc6b0'); R(gx + sway, GY - 31, 1, 3, '#e8e0cc');
      }
      // persimmon tree on the right
      var tx = 466;
      R(tx, GY - 70, 4, 70, '#2a1a12'); R(tx - 10, GY - 64, 12, 2, '#2a1a12'); R(tx - 16, GY - 70, 7, 2, '#2a1a12'); R(tx + 3, GY - 82, 2, 14, '#2a1a12'); R(tx + 4, GY - 56, 10, 2, '#2a1a12');
      [[tx - 14, GY - 67], [tx - 6, GY - 61], [tx + 8, GY - 53], [tx + 2, GY - 80], [tx - 18, GY - 72], [tx + 12, GY - 58]].forEach(function (p) { R(p[0], p[1], 3, 3, '#f08a24'); R(p[0] + 1, p[1] - 1, 1, 1, '#3d5a2a'); });
      // jack-o'-lantern on the ground
      pumpkin(HX - 13, GY - 9, true);
      // will-o'-the-wisp (hitodama): a ghost light drifting by the tree (night)
      if (isNight()) {
        var hy = GY - 40 + round(Math.sin(frame * 0.04) * 6), hx2 = 448 + round(Math.sin(frame * 0.025) * 4);
        alpha('#8fd6ff', 0.25); disc(hx2, hy, 5, '#8fd6ff'); ctx.globalAlpha = 1;
        disc(hx2, hy, 2, '#d8f4ff'); R(hx2 - 1, hy + 2, 2, 3, '#8fd6ff'); R(hx2, hy + 5, 1, 2, '#8fd6ff');
      }
    }

    function pumpkin(x, y, glow) {
      var fl = REDUCE ? 1 : 0.7 + 0.3 * Math.abs(Math.sin(frame * 0.21));
      if (glow) { alpha('#ffb347', 0.18 * fl); disc(x + 5, y + 4, 9, '#ffb347'); ctx.globalAlpha = 1; }
      R(x + 1, y + 1, 9, 7, '#d8701a'); R(x, y + 2, 11, 5, '#d8701a'); R(x + 3, y + 1, 1, 7, '#b85a12'); R(x + 7, y + 1, 1, 7, '#b85a12');
      R(x + 5, y - 1, 2, 2, '#3d5a2a');
      var face = glow ? 'rgba(255,214,110,' + fl + ')' : '#3a1a06';
      R(x + 2, y + 3, 2, 1, face); R(x + 7, y + 3, 2, 1, face); R(x + 3, y + 5, 5, 1, face); R(x + 4, y + 6, 1, 1, face); R(x + 6, y + 6, 1, 1, face);
    }

    // ── house (ported) ────────────────────────────────────────────────────
    function roof(ox) {
      var y0 = 62;
      for (var y = y0; y < CY; y++) {
        var inset = round((CY - y) * 0.45);
        R(HX - 12 + inset + ox, y, (HXR - HX) + 24 - inset * 2, 1, ((y - y0) % 4 === 0) ? '#58507a' : '#3a3452');
      }
      for (var x = HX - 12; x < HXR + 12; x += 6) R(x + ox, CY - 3, 1, 3, '#2a253c');
      R(HX + 40 + ox, y0 - 3, (HXR - HX) - 80, 3, '#2a253c');
      R(HX + 38 + ox, y0 - 6, 6, 4, '#2a253c'); R(HXR - 44 + ox, y0 - 6, 6, 4, '#2a253c');
      web(HX - 10 + ox, CY, 1); web(HXR + 9 + ox, CY, -1);
    }

    function web(x, y, d) {
      alpha('#d8d2c4', 0.45);
      var i;
      for (i = 0; i < 7; i++) R(x + d * i, y + i, 1, 1, '#d8d2c4');
      for (i = 0; i < 6; i++) { R(x + d * i, y, 1, 1, '#d8d2c4'); R(x, y + i, 1, 1, '#d8d2c4'); }
      R(x + d * 3, y + 1, 1, 1, '#d8d2c4'); R(x + d * 1, y + 3, 1, 1, '#d8d2c4');
      ctx.globalAlpha = 1;
    }

    function rooms(ox) {
      // walls and floors, keyed by engine room with the pilot's `kind`.
      ROOM_ORDER.forEach(function (r) {
        var w = r.x1 - r.x0, x = r.x0 + ox;
        var wall = '#c9b994', low = '#7a5536';
        if (r.kind === 'bath') { wall = '#9fb4b0'; low = '#6f8b8f'; }
        if (r.kind === 'veranda') { wall = '#3a2a3e'; }
        R(x, CY, w, FY - CY, wall);
        if (r.kind !== 'veranda') R(x, FY - 18, w, 18, low);
        var floor = (r.kind === 'bedroom' || r.kind === 'living') ? '#b6a463' : (r.kind === 'bath' ? '#5f7a7e' : '#8a6440');
        R(x, FY, w, 4, floor);
        var tx;
        if (r.kind === 'bedroom' || r.kind === 'living') { for (tx = x; tx < x + w; tx += 24) R(tx, FY, 1, 4, '#7d6e3c'); }
        else if (r.kind === 'bath') { for (tx = x; tx < x + w; tx += 6) R(tx, FY, 1, 4, '#4a6266'); }
        else { R(x, FY + 2, w, 1, '#6e4f33'); }
      });
      // pillars and lintels (doorways stay open below the lintel)
      var posts = [HX];
      ROOM_ORDER.forEach(function (r) { posts.push(r.x1); });
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

    function chochin(x, y) {
      var fl = REDUCE ? 1 : 0.75 + 0.25 * Math.abs(Math.sin(frame * 0.17));
      alpha('#ffb347', 0.2 * fl); disc(x + 4, y + 6, 12, '#ffb347'); ctx.globalAlpha = 1;
      R(x + 1, y, 6, 1, '#1a120c'); R(x, y + 1, 8, 9, '#f0922b'); R(x + 1, y + 10, 6, 1, '#1a120c');
      R(x, y + 3, 8, 1, '#c86a14'); R(x, y + 7, 8, 1, '#c86a14');
      var face = 'rgba(70,24,4,' + (0.6 + 0.4 * fl) + ')';
      R(x + 1, y + 4, 2, 1, face); R(x + 5, y + 4, 2, 1, face); R(x + 2, y + 6, 4, 1, face);
    }

    function kasa(x, y) {
      var hop = REDUCE ? 0 : (Math.floor(frame / 30) % 3 === 0 ? 2 : 0);
      var yy = y - hop;
      R(x - 1, yy - 4, 1, 4, '#5a4030');
      for (var i = 0; i < 9; i++) R(x - Math.floor(i / 2), yy - 5 - (9 - i), 1 + i, 1, '#b8382a');
      R(x - 3, yy - 13, 6, 1, '#7a1e16'); R(x - 1, yy - 15, 2, 2, '#3a1a12');
      R(x - 1, yy - 10, 3, 3, '#f2ead6'); R(x, yy - 9, 1, 1, '#1a1420');
      R(x + 1, yy - 7, 1, 2, '#e5533d');
    }

    // Draw the small window fixture for a room band (Req 1.2 / Req 1 window).
    function windowFixture(x0, x1) {
      var wx = Math.floor((x0 + x1) / 2) - 8;
      box(wx, CY + 12, 16, 10, '#1d2a3a', '#4a3a2a');
      R(wx + 7, CY + 12, 1, 10, '#4a3a2a'); R(wx, CY + 17, 16, 1, '#4a3a2a');
      if (isNight()) { disc(wx + 12, CY + 15, 1, '#efe6c8'); }
    }

    function furniture(ox) {
      var v = ROOM.entrance, b = ROOM.bedroom, l = ROOM.living_room,
          k = ROOM.kitchen, ba = ROOM.bathroom, s = ROOM.storeroom;
      var on = S.power;
      var shards, row, d, vv;

      // entrance (pilot veranda): rain shutters, shoe cabinet, lantern
      for (var p = 0; p < 3; p++) R(v.x0 + ox + p * 4, CY + 6, 3, FY - CY - 6, '#5e4430');
      box(v.x1 - 18 + ox, FY - 14, 14, 14, '#6b4a2f', '#2a1a12'); R(v.x1 - 17 + ox, FY - 8, 12, 1, '#4a3322');
      var swing = S.shaking && !REDUCE ? round(Math.sin(frame * 0.6) * 3) : 0;
      R(v.x0 + 28 + ox, CY + 3, 1, 8, '#2a1a12');
      chochin(v.x0 + 25 + ox + swing, CY + 11);

      // bedroom: closet, futon, fan
      box(b.x0 + 6 + ox, CY + 34, 34, 38, '#e9ddc0', '#4a3322'); R(b.x0 + 23 + ox, CY + 34, 1, 38, '#4a3322');
      disc(b.x0 + 15 + ox, CY + 46, 4, '#7d8db0'); R(b.x0 + 27 + ox, CY + 58, 10, 2, '#7d8db0');
      box(b.x0 + 8 + ox, FY - 4, 34, 4, '#d6c8e6', '#3a2f4a'); R(b.x0 + 8 + ox, FY - 4, 34, 1, '#efe6f6'); box(b.x0 + 9 + ox, FY - 7, 7, 3, '#f2ead6', '#3a2f4a');
      // electric fan (earthquake scene only: in a heat wave the fan is drawn in
      // the room it is actually in, by heatObjects)
      if (!S.heat) {
        R(b.x1 - 12 + ox, FY - 3, 6, 3, '#4a6a8a'); R(b.x1 - 10 + ox, FY - 12, 2, 9, '#6a8aaa'); disc(b.x1 - 9 + ox, FY - 15, 4, '#9ab8d0');
        R(b.x1 - 9 + ox, FY - 18, 1, 7, '#4a6a8a');
      }
      web(b.x0 + ox + 1, CY + 3, 1);

      // living room: butsudan, chabudai, TV, phone, cooler, window
      box(l.x0 + 4 + ox, FY - 30, 14, 30, '#2a1a12', '#140c08'); R(l.x0 + 6 + ox, FY - 27, 10, 12, '#6a4a1a'); R(l.x0 + 8 + ox, FY - 24, 6, 6, '#c8a040');
      var cand = on ? (REDUCE ? 1 : 0.6 + 0.4 * Math.abs(Math.sin(frame * 0.3))) : 1;
      R(l.x0 + 10 + ox, FY - 14, 1, 3, '#f2ead6'); alpha('#ffd27a', cand); R(l.x0 + 10 + ox, FY - 16, 1, 2, '#ffd27a'); ctx.globalAlpha = 1;
      var tx = l.x0 + 40 + ox;
      R(tx - 11, FY - 8, 22, 2, '#6b4127'); R(tx - 11, FY - 6, 22, 1, '#4a2b19'); R(tx - 8, FY - 5, 2, 5, '#4a2b19'); R(tx + 6, FY - 5, 2, 5, '#4a2b19');
      R(tx - 3, FY - 10, 2, 2, '#e8e0cc'); R(tx + 2, FY - 10, 2, 2, '#9fb7a0');
      var tvx = l.x1 - 26 + ox;
      var tvFallen = !!S.fallen.tv;
      if (tvFallen) {
        // the TV lies on its side on the floor (Req 1.12 fallen object)
        box(tvx, FY - 10, 14, 10, '#5a4632', '#1a120c'); R(tvx + 2, FY - 8, 10, 6, '#1a2026');
        R(tvx - 4, FY - 1, 20, 1, '#2a1a12');
      } else {
        box(tvx, FY - 24, 18, 14, '#5a4632', '#1a120c');
        R(tvx + 2, FY - 22, 11, 10, on ? (frame % 6 < 3 ? '#6f8fa8' : '#7a9ab4') : '#1a2026'); R(tvx + 14, FY - 21, 2, 2, '#c8a040'); R(tvx + 14, FY - 17, 2, 2, '#c8a040');
        if (on && !REDUCE) { for (var sl = 0; sl < 3; sl++) R(tvx + 2, FY - 22 + ((frame + sl * 4) % 10), 11, 1, 'rgba(255,255,255,.18)'); }
        R(tvx + 2, FY - 10, 2, 10, '#3a2a1e'); R(tvx + 14, FY - 10, 2, 10, '#3a2a1e');
      }
      box(l.x1 - 44 + ox, FY - 10, 10, 10, '#4a3322', '#1a120c'); R(l.x1 - 43 + ox, FY - 14, 8, 4, '#121014'); disc(l.x1 - 39 + ox, FY - 12, 1, '#2a2834');
      R(l.x1 - 42 + ox, FY - 17, 7, 3, '#7a6a58'); R(l.x1 - 41 + ox, FY - 16, 3, 1, '#c8b8a0');
      box(l.x1 - 22 + ox, CY + 12, 16, 10, '#d8d0bc', '#4a3a2a'); for (vv = 0; vv < 4; vv++) R(l.x1 - 20 + ox + vv * 4, CY + 15, 2, 5, '#9a907c');

      // lamps (dark/dim handled in lights()): lit only when on and not afternoon
      var lit = on && S.timeOfDay !== 'afternoon';
      lamp(l.x0 + 40 + ox, lit);
      lamp(b.x0 + 36 + ox, lit);
      lamp(k.x0 + 42 + ox, lit);

      // kitchen: counter with stove and sink, cupboard, fridge
      var cnt = k.x0 + 4 + ox;
      R(cnt, FY - 16, 50, 16, '#7a5a3e'); R(cnt, FY - 17, 50, 2, '#a8a8a8'); R(cnt, FY - 10, 50, 1, '#5a4030');
      box(cnt + 4, FY - 21, 16, 4, '#3a3a40', '#1a1a20');
      // the gas-stove flame shows only while the stove is on (World_State)
      if (S.stoveOn) { var fl = frame % 4 < 2; R(cnt + 7, FY - 22, 2, 1, fl ? '#5ab0ff' : '#8fd0ff'); R(cnt + 13, FY - 22, 2, 1, fl ? '#8fd0ff' : '#5ab0ff'); }
      var slosh = S.shaking && !REDUCE ? round(Math.sin(frame * 0.9)) : 0;
      box(cnt + 7 + slosh, FY - 28, 8, 6, '#9a9aa6', '#2a2a32');
      if (S.stoveOn && !REDUCE) { for (var st = 0; st < 3; st++) { var sy = (frame * 0.5 + st * 5) % 14; alpha('#ffffff', 0.45 - sy * 0.03); R(cnt + 9 + slosh + (st % 2), FY - 31 - sy, 2, 1, '#ffffff'); } ctx.globalAlpha = 1; }
      R(cnt + 30, FY - 16, 14, 3, '#8f9aa6'); R(cnt + 31, FY - 15, 12, 1, '#5a6a7a'); R(cnt + 38, FY - 22, 1, 6, '#a8a8a8');
      var cup = k.x1 - 30 + ox, rattle = S.shaking && !REDUCE ? (frame % 2) : 0;
      box(cup + rattle, FY - 40, 14, 24, '#6b4a2f', '#2a1a12');
      // the dish cupboard shows empty shelves when broken (Req 1.12), else dishes
      var cupBroken = !!S.broken.dish_cupboard;
      for (row = 0; row < 3; row++) {
        for (d = 0; d < 3; d++) { if (!cupBroken) R(cup + 2 + d * 4 + rattle, FY - 37 + row * 7, 3, 2, '#f2ead6'); }
        R(cup + 1 + rattle, FY - 34 + row * 7, 12, 1, '#4a3322');
      }
      if (cupBroken) {
        // shards on the floor below the cupboard
        for (var sh = 0; sh < 5; sh++) R(cup - 2 + sh * 3, FY - 1, 2, 1, '#f2ead6');
      }
      box(k.x1 - 13 + ox, FY - 30, 12, 30, '#e9e2cf', '#5a5040'); R(k.x1 - 13 + ox, FY - 20, 12, 1, '#b8b0a0'); R(k.x1 - 4 + ox, FY - 27, 1, 5, '#a8a8a8'); R(k.x1 - 4 + ox, FY - 17, 1, 8, '#a8a8a8');

      // bath: tub with water, heater
      box(ba.x0 + 6 + ox, FY - 12, 30, 12, '#8a6440', '#3a2a1e'); R(ba.x0 + 7 + ox, FY - 11, 28, 2, '#5aa0c8');
      box(ba.x1 - 12 + ox, FY - 26, 8, 12, '#b8b8b0', '#4a4a44'); R(ba.x1 - 10 + ox, FY - 22, 4, 2, on ? '#ff8a3a' : '#3a2a2a');

      // storeroom: shelves, candles, toolbox, umbrella ghost, pumpkin, cobweb
      R(s.x0 + 3 + ox, CY + 36, s.x1 - s.x0 - 6, 2, '#5a4030'); R(s.x0 + 3 + ox, CY + 60, s.x1 - s.x0 - 6, 2, '#5a4030');
      box(s.x0 + 6 + ox, CY + 30, 10, 6, '#c8b088', '#4a3322'); R(s.x0 + 8 + ox, CY + 28, 1, 2, '#f2ead6'); R(s.x0 + 12 + ox, CY + 28, 1, 2, '#f2ead6');
      box(s.x0 + 26 + ox, CY + 54, 12, 6, '#b8382a', '#4a1a12'); R(s.x0 + 30 + ox, CY + 52, 4, 2, '#4a1a12');
      pumpkin(s.x0 + 26 + ox, CY + 26, false);
      kasa(s.x0 + 18 + ox, FY);
      web(s.x1 - 2 + ox, CY + 3, -1);

      // windows: one per room with a window fixture, none in the storeroom
      ROOM_ORDER.forEach(function (r) {
        // the living room's cooler sits where a window would; the butsudan/TV
        // room keeps its cooler, so skip the extra window there to avoid clutter
        if (HAS_WINDOW[r.key] && r.key !== 'living_room') windowFixture(r.x0 + ox, r.x1 + ox);
      });
    }

    // ── heat wave drawing (heat-wave Req 10.2, 10.5) ──────────────────────
    // Every function below runs only when S.heat is set, i.e. only when the
    // world's disaster is heat wave. An earthquake world never reaches them.

    // Afternoon sky: a hot, hazy wash over the sky and a larger, whiter sun.
    function heatSky() {
      alpha('#ff9a3c', 0.14); ctx.fillRect(0, 0, W, GY); ctx.globalAlpha = 1;
      var sx = 330, sy = 34;
      alpha('#fff2c8', 0.22); disc(sx, sy, 28, '#fff2c8'); ctx.globalAlpha = 1;
      disc(sx, sy, 13, '#fffbe8');
    }

    // Heat shimmer: faint wavering streaks rising off the ground and hills
    // outside the house. Still (but present) under reduced motion.
    function heatShimmer() {
      for (var row = 0; row < 8; row++) {
        var y = GY - 4 - row * 4;
        for (var x = 0; x < W; x += 24) {
          var wob = REDUCE ? 0 : round(Math.sin(frame * 0.12 + row * 0.9 + x * 0.05) * 3);
          alpha('#fff4d0', 0.10);
          ctx.fillRect(x + wob + (row % 2) * 8, y, 12, 1);
        }
      }
      ctx.globalAlpha = 1;
    }

    // Open windows (and the living room's window, which has no fixture in the
    // shared scene) drawn in their open or closed state.
    function heatWindows(ox) {
      var windows = S.heat.windows || {};
      ROOM_ORDER.forEach(function (r) {
        if (!HAS_WINDOW[r.key]) { return; }
        var open = !!windows[r.key];
        var wx = Math.floor((r.x0 + r.x1) / 2) - 8 + ox;
        if (r.key === 'living_room') {
          // the cooler occupies the usual window spot; draw the window beside it
          wx = r.x0 + 47 + ox;
          box(wx, CY + 12, 16, 10, open ? '#a8d4e8' : '#1d2a3a', '#4a3a2a');
          R(wx + 7, CY + 12, 1, 10, '#4a3a2a'); R(wx, CY + 17, 16, 1, '#4a3a2a');
        } else if (open) {
          box(wx, CY + 12, 16, 10, '#a8d4e8', '#4a3a2a');
          R(wx + 7, CY + 12, 1, 10, '#4a3a2a'); R(wx, CY + 17, 16, 1, '#4a3a2a');
        }
        if (open) {
          // a hot breeze: a curtain that lifts, and a few drifting streaks
          var lift = REDUCE ? 1 : (1 + Math.floor((Math.sin(frame * 0.1 + wx) + 1) * 1.5));
          R(wx + 1, CY + 13, 3, 6 + lift, '#e9ddc0');
          alpha('#ffcf8a', 0.5);
          for (var k = 0; k < 2; k++) {
            var dx = REDUCE ? k * 5 : (frame * 0.4 + k * 6) % 14;
            ctx.fillRect(round(wx + 16 + dx), CY + 15 + k * 3, 3, 1);
          }
          ctx.globalAlpha = 1;
        }
      });
    }

    // The window air conditioner (living room), drawn on or off.
    function heatAirConditioner(ox) {
      var l = ROOM.living_room, on = !!S.heat.ac;
      var ax = l.x1 - 22 + ox;
      box(ax, CY + 12, 16, 10, '#d8d0bc', '#4a3a2a');
      for (var vv = 0; vv < 4; vv++) R(ax + 2 + vv * 4, CY + 15, 2, 5, on ? '#6f8fa8' : '#9a907c');
      // status light: steady green when on, dark when off
      R(ax + 13, CY + 13, 2, 1, on ? '#7dffb0' : '#4a3a2a');
      if (on) {
        // cold air falling from the unit
        for (var i = 0; i < 4; i++) {
          var fy = REDUCE ? i * 4 : (frame * 0.5 + i * 4) % 16;
          alpha('#bfe8ff', 0.55 - fy * 0.025);
          ctx.fillRect(ax + 2 + i * 4, CY + 23 + round(fy), 2, 3);
        }
        ctx.globalAlpha = 1;
      }
    }

    // The electric fan, drawn in the room it is in, spinning when on.
    function heatFan(ox) {
      var f = S.heat.fan || {};
      var r = f.room ? ROOM[f.room] : null;
      if (!r) { return; }
      var fx = r.x1 - 12 + ox, on = !!f.on;
      R(fx, FY - 3, 6, 3, '#4a6a8a'); R(fx + 2, FY - 12, 2, 9, '#6a8aaa');
      var hx = fx + 3, hy = FY - 15;
      disc(hx, hy, 4, on ? '#bcd8ea' : '#9ab8d0');
      // blades: a cross that turns between two positions while on
      var turn = on && !REDUCE ? (Math.floor(frame / 2) % 2) : 0;
      if (turn === 0) { R(hx - 3, hy, 7, 1, '#4a6a8a'); R(hx, hy - 3, 1, 7, '#4a6a8a'); }
      else { R(hx - 2, hy - 2, 1, 1, '#4a6a8a'); R(hx + 2, hy - 2, 1, 1, '#4a6a8a'); R(hx - 2, hy + 2, 1, 1, '#4a6a8a'); R(hx + 2, hy + 2, 1, 1, '#4a6a8a'); R(hx - 1, hy - 1, 3, 3, '#4a6a8a'); }
      if (on) {
        // air pushed out to the side
        for (var i = 0; i < 3; i++) {
          var dx = REDUCE ? i * 4 : (frame * 0.7 + i * 5) % 14;
          alpha('#e8f6ff', 0.45);
          ctx.fillRect(round(hx - 6 - dx), hy - 2 + i * 2, 4, 1);
        }
        ctx.globalAlpha = 1;
      }
    }

    function heatObjects(ox) {
      heatWindows(ox);
      heatAirConditioner(ox);
      heatFan(ox);
    }

    // ── characters (ported) ───────────────────────────────────────────────
    // Amber: amber body, round head, antenna (pilot robotClaude).
    function drawAmber(a) {
      var x = round(a.x), y = FY, f = frame, c = (a.pose === 'crouch' || a.pose === 'kneel') ? 3 : 0;
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
      if (a.held === 'light') { R(x + (a.dir > 0 ? 6 : -9), y - 9 + c, 4, 2, '#d8d0bc'); R(x + (a.dir > 0 ? 9 : -9), y - 9 + c, 1, 2, '#ffe39a'); }
      else if (a.held === 'cup') { var hx = x + (a.dir > 0 ? 6 : -9); R(hx, y - 11 + c, 3, 4, '#e8eef2'); R(hx, y - 10 + c, 3, 1, '#5aa0c8'); R(hx, y - 11 + c, 3, 1, '#ffffff'); }
      R(x - 1, y - 15 + c, 2, 1, '#5d5560');
      box(x - 5, y - 22 + c, 10, 7, b1, ol); R(x - 5, y - 22 + c, 1, 1, ol); R(x + 4, y - 22 + c, 1, 1, ol); R(x - 4, y - 22 + c, 3, 1, hi);
      R(x - 4, y - 20 + c, 8, 3, '#2a2234');
      var blink = f % 70 > 66, ex = a.dir > 0 ? 0 : -1;
      if (!blink) { R(x - 3 + ex + 1, y - 19 + c, 2, 1, '#ffd27a'); R(x + 1 + ex + 1, y - 19 + c, 2, 1, '#ffd27a'); }
      var bob = REDUCE ? 0 : (f % 20 < 10 ? 0 : 1);
      R(x, y - 25 + c, 1, 3, ol); R(x - 1, y - 27 + c + bob, 3, 2, '#ff9a3c');
    }

    // Jade: jade body, square head, scanning eye (pilot robotGpt).
    function drawJade(a) {
      var x = round(a.x), y = FY, f = frame, c = a.pose === 'crouch' ? 4 : 0;
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
      if (a.held === 'light') { R(x + (a.dir > 0 ? 7 : -10), y - 9 + c, 4, 2, '#d8d0bc'); R(x + (a.dir > 0 ? 10 : -10), y - 9 + c, 1, 2, '#ffe39a'); }
      R(x - 1, y - 15 + c, 2, 1, '#3e5a56');
      box(x - 6, y - 23 + c, 12, 8, b1, ol); R(x - 6, y - 23 + c, 12, 1, hi);
      R(x - 5, y - 20 + c, 10, 3, '#14202a');
      var span = 8, ph = REDUCE ? 4 : Math.floor(f / 2) % (span * 2), sx = ph < span ? ph : span * 2 - ph;
      R(x - 5 + sx, y - 19 + c, 2, 1, '#7dffe0'); alpha('#7dffe0', 0.4); R(x - 5 + Math.max(0, sx - 1), y - 19 + c, 4, 1, '#7dffe0'); ctx.globalAlpha = 1;
      R(x - 4, y - 26 + c, 1, 3, ol); R(x + 3, y - 26 + c, 1, 3, ol); R(x - 5, y - 27 + c, 2, 1, '#7dffe0'); R(x + 3, y - 27 + c, 2, 1, '#7dffe0');
    }

    // The man with a cane (pilot oldMan). Pose set by the man's Spot/Activity.
    function drawMan(a) {
      var x = round(a.x), y = FY, f = frame;
      var jit = (S.shaking && !REDUCE) ? (f % 2 ? 1 : -1) : 0;
      x += jit;
      var ol = '#2a1a12', skin = '#e2b48c', hair = '#d0ccc4', cardi = '#8a5a3c', pants = '#3b3a4a';
      if (a.pose === 'lie') {
        var px = x - 11;
        box(px, y - 10, 6, 5, skin, ol); R(px - 1, y - 9, 1, 3, hair); R(px + 1, y - 8, 3, 1, '#3a3040');
        box(px + 7, y - 8, 26, 4, '#b8584a', ol); R(px + 7, y - 8, 26, 1, '#d8786a');
      } else if (a.pose === 'bath') {
        var tx0 = x - 3;
        box(tx0, y - 19, 6, 6, skin, ol); R(tx0, y - 20, 6, 1, '#f2ead6'); R(tx0 - 1, y - 17, 1, 3, hair); R(tx0 + 6, y - 17, 1, 3, hair);
        R(tx0 + 1, y - 16, 4, 1, '#3a3040'); R(tx0 - 2, y - 13, 10, 2, skin);
        R(ROOM.bathroom.x0 + 7, y - 11, 28, 2, '#5aa0c8');
      } else if (a.pose === 'duck') {
        R(x - 9, y - 3, 9, 3, pants);
        box(x - 2, y - 6, 7, 5, cardi, ol);
        box(x + 5, y - 6, 5, 5, skin, ol); R(x + 5, y - 6, 5, 1, hair);
        R(x + 3, y - 7, 8, 1, cardi);
        var tb = ROOM.living_room.x0 + 40;
        R(tb - 11, FY - 8, 22, 2, '#6b4127'); R(tb - 11, FY - 6, 22, 1, '#4a2b19');
      } else if (a.pose === 'cook') {
        // standing at the stove, facing it, with the cane
        var step = 0, lean = 1;
        R(x - 3, y - 6, 2, 6, pants); R(x + 1, y - 6, 2, 6, pants);
        box(x - 3, y - 15, 7, 9, cardi, ol);
        var hc = x - 2 + lean;
        box(hc, y - 21, 6, 6, skin, ol); R(hc, y - 21, 6, 1, '#f0cca8'); R(hc - 1, y - 19, 1, 3, hair); R(hc + 6, y - 19, 1, 3, hair);
        R(hc + 2, y - 18, 4, 1, '#3a3040');
        R(x - 6, y - 11, 1, 11, '#6b4a2f'); R(x - 8, y - 11, 3, 1, '#6b4a2f');
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
        // standing (and walking) with the cane
        var step2 = a.walking ? Math.floor(f / 9) % 2 : 0;
        var lean2 = a.dir > 0 ? 1 : -1;
        R(x - 3 + (step2 ? lean2 : 0), y - 6, 2, 6, pants); R(x + 1 - (step2 ? lean2 : 0), y - 6, 2, 6, pants);
        box(x - 3 + lean2, y - 15, 7, 9, cardi, ol);
        var hx2 = x - 2 + lean2 * 3;
        box(hx2, y - 21, 6, 6, skin, ol); R(hx2, y - 21, 6, 1, '#f0cca8'); R(hx2 - 1, y - 19, 1, 3, hair); R(hx2 + 6, y - 19, 1, 3, hair);
        R(hx2 + (a.dir > 0 ? 2 : 0), y - 18, 4, 1, '#3a3040');
        R(x + lean2 * 7, y - 11, 1, 11, '#6b4a2f'); R(x + lean2 * 5, y - 11, 3, 1, '#6b4a2f');
      }
      if (a.fear > 60 && !REDUCE && f % 20 < 14) { R(x + 4, y - 20, 1, 2, '#8fd0ff'); }
    }

    // ── darkness / dim mask (ported lights) ───────────────────────────────
    function lights() {
      // Power out → darken at morning/night (Req 1.10), dim in afternoon (1.11).
      if (S.power) return;
      if (!dark) { return; }
      dctx.globalCompositeOperation = 'source-over';
      dctx.clearRect(0, 0, W, H);
      dctx.fillStyle = (S.timeOfDay === 'afternoon') ? 'rgba(6,4,14,0.38)' : 'rgba(6,4,14,0.8)';
      dctx.fillRect(HX, CY, HXR - HX, FY - CY + 4);
      dctx.globalCompositeOperation = 'destination-out';
      function hole(x, y, r, a) { for (var k = 3; k >= 1; k--) { dctx.globalAlpha = a / k; dctx.beginPath(); dctx.arc(x, y, r * k / 3 + 2, 0, Math.PI * 2); dctx.fill(); } dctx.globalAlpha = 1; }
      hole(ROOM.living_room.x0 + 10, FY - 15, 7, 0.9);
      hole(ROOM.living_room.x0 + 53, CY + 18, 9, 0.6);
      hole(ROOM.entrance.x0 + 29, CY + 16, 12, 0.8);
      ['amber', 'jade'].forEach(function (k) {
        if (A[k].held !== 'light') return;
        var cxp = round(A[k].x), d = A[k].dir;
        dctx.globalAlpha = 0.95; dctx.beginPath(); dctx.moveTo(cxp + d * 9, FY - 8); dctx.lineTo(cxp + d * 60, FY - 26); dctx.lineTo(cxp + d * 60, FY + 4); dctx.closePath(); dctx.fill();
        hole(cxp + d * 9, FY - 8, 5, 1);
      });
      ctx.drawImage(dark, 0, 0);
    }

    // ── overlay: name tags and speech bubbles (ported placeOverlay) ─────────
    // Bubbles carry SPOKEN lines only. Thoughts are never placed here, never
    // drawn on the canvas, and never shown on the overlay (Req 1.14, 3.6).
    var tags = {}, bubbles = {};
    var TAG_TEXT = { amber: 'Amber', jade: 'Jade', man: 'The man' };

    function buildOverlay() {
      if (!ov) return;
      ov.innerHTML = '';
      tags = {}; bubbles = {};
      ['amber', 'jade', 'man'].forEach(function (k) {
        var t = document.createElement('div');
        t.className = 'name ' + (k === 'amber' ? 'claude' : (k === 'jade' ? 'gpt' : 'man'));
        t.textContent = TAG_TEXT[k];
        ov.appendChild(t); tags[k] = t;
        var b = document.createElement('div'); b.className = 'bubble'; ov.appendChild(b);
        var tl = document.createElement('div'); tl.className = 'tail'; ov.appendChild(tl);
        bubbles[k] = { el: b, tail: tl, until: 0, text: '' };
      });
    }

    // Create the caption strip the renderer owns and insert it DIRECTLY UNDER
    // the canvas/stage (Req 1.13 / design "Narrow-screen caption strip"). The
    // strip is used only while narrow; wide screens keep it empty so CSS
    // (:empty { display:none }) gives it no space. Guarded for no-DOM/test use.
    function buildCaption() {
      cap = null;
      if (typeof document === 'undefined' || !cv) return;
      cap = document.createElement('div');
      cap.className = 'caption-strip';
      cap.setAttribute('aria-hidden', 'true');
      // Prefer inserting after the enclosing .stage (so the strip sits under
      // the whole house block), then after the .inner, then after the canvas.
      var stage = cv.closest ? cv.closest('.stage') : null;
      var inner = cv.closest ? cv.closest('.inner') : null;
      var anchor = stage || inner || cv;
      if (anchor && anchor.parentNode) {
        anchor.parentNode.insertBefore(cap, anchor.nextSibling);
      }
    }

    // Head height per pose, used to place a tag above the character's head.
    function headHeight(k) {
      if (k !== 'man') return 30;
      return ({ sit: 20, lie: 12, bath: 22, duck: 30, cook: 24 }[A.man.pose] || 24);
    }

    // True when the canvas is rendered narrower than the bubble breakpoint, so
    // the caption strip (not over-canvas bubbles) carries the spoken lines.
    // Evaluated at render time against the live CSS width (Req 1.13, 12.3).
    function isNarrow() {
      return !!(cv && cv.clientWidth && cv.clientWidth < BUBBLE_MIN_CANVAS_PX);
    }

    // Hide every over-canvas bubble + tail (used while narrow, and when a line
    // clears). Does not touch each bubble's `until`, which still governs when a
    // caption line disappears, so the ≥ 2.0 s minimum is preserved identically.
    function hideAllBubbles() {
      Object.keys(bubbles).forEach(function (k) {
        var b = bubbles[k];
        b.el.classList.remove('show');
        b.tail.classList.remove('show');
      });
    }

    // Render the caption strip from the characters whose spoken line is still
    // active (now <= until). One "Name: words" line each, in the fixed order,
    // spoken words only — never a thought, never a company name (Req 1.14, 3.6).
    // The strip clears (and collapses via CSS :empty) when all lines expire.
    function renderCaption() {
      if (!cap) return;
      var now = perfNow();
      // Expire any lapsed lines first so `until` keeps driving the ≥ 2.0 s rule.
      Object.keys(bubbles).forEach(function (k) {
        var b = bubbles[k];
        if (b.until && now > b.until) { b.until = 0; b.text = ''; }
      });
      cap.innerHTML = '';
      ['amber', 'jade', 'man'].forEach(function (k) {
        var b = bubbles[k];
        if (!b || !b.until || now > b.until || !b.text) return;
        var line = document.createElement('div');
        line.className = 'caption-line ' + (k === 'amber' ? 'claude' : (k === 'jade' ? 'gpt' : 'man'));
        var who = document.createElement('span');
        who.className = 'cap-name';
        who.textContent = TAG_TEXT[k] + ': ';
        line.appendChild(who);
        line.appendChild(document.createTextNode(b.text));
        cap.appendChild(line);
      });
    }

    function placeOverlay() {
      if (!ov || !cv.clientWidth) return;
      // Narrow: no over-canvas bubbles — hide them and drive the caption strip.
      if (isNarrow()) {
        hideAllBubbles();
        renderCaption();
      } else if (cap) {
        // Wide: the strip takes no space; the over-canvas bubbles are shown.
        cap.innerHTML = '';
      }
      var sc = cv.clientWidth / W, now = perfNow(), sw = cv.clientWidth, placed = [], bl = [];
      ['man', 'amber', 'jade'].forEach(function (k) {
        var a = A[k], head = headHeight(k);
        var x = a.x * sc, y = (FY - head) * sc;
        var t = tags[k], tw = t.offsetWidth, th = t.offsetHeight, tt = y - th - 2;
        // keep name tags from covering each other (Req 1.13)
        for (var n = 0; n < 3; n++) {
          var hit = null;
          placed.forEach(function (p) { if (!hit && x - tw / 2 < p.r && x + tw / 2 > p.l && tt < p.b && tt + th > p.t) hit = p; });
          if (!hit) break;
          tt = hit.t - th - 2;
        }
        placed.push({ l: x - tw / 2, r: x + tw / 2, t: tt, b: tt + th });
        t.style.left = x + 'px'; t.style.top = tt + 'px';
        var b = bubbles[k];
        if (b.until && now > b.until) { b.el.classList.remove('show'); b.tail.classList.remove('show'); b.until = 0; }
        var bw = b.el.offsetWidth, bh = b.el.offsetHeight;
        var left = Math.max(4, Math.min(sw - bw - 4, x - bw / 2));
        var top = tt - bh - 10;
        bl.push({ b: b, x: x, left: left, top: Math.max(4, top), bw: bw, bh: bh, vis: b.el.classList.contains('show') });
      });
      // keep speech bubbles from covering each other (Req 1.13)
      for (var i = 1; i < bl.length; i++) for (var j = 0; j < i; j++) {
        var p = bl[i], q = bl[j];
        if (!p.vis || !q.vis) continue;
        if (p.left < q.left + q.bw + 6 && p.left + p.bw + 6 > q.left && p.top < q.top + q.bh && p.top + p.bh > q.top) {
          var right = p.x >= q.x, nl = right ? q.left + q.bw + 8 : q.left - p.bw - 8;
          if (nl < 4 || nl + p.bw > sw - 4) nl = right ? q.left - p.bw - 8 : q.left + q.bw + 8;
          if (nl >= 4 && nl + p.bw <= sw - 4) p.left = nl; else p.top = Math.max(4, q.top - p.bh - 8);
        }
      }
      bl.forEach(function (o) {
        o.b.el.style.left = o.left + 'px'; o.b.el.style.top = o.top + 'px';
        o.b.tail.style.left = Math.max(o.left + 6, Math.min(o.left + o.bw - 18, o.x - 6)) + 'px';
        o.b.tail.style.top = (o.top + o.bh) + 'px';
      });
    }

    function perfNow() {
      return (typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now();
    }

    // ── thinking indicator (Req 5.6 / Req 1.14) ────────────────────────────
    // A small dot/ellipsis indicator only — never thought TEXT.
    function drawThinking(a) {
      var x = round(a.x), top = FY - 34;
      for (var i = 0; i < 3; i++) {
        var on = (Math.floor(frame / 8) % 3) >= i;
        R(x - 3 + i * 3, top, 1, 1, on ? '#efe6cb' : '#5a5260');
      }
    }

    // ── per-frame simulation: ease each character toward its target ────────
    function stepSim() {
      frame++;
      ['amber', 'jade', 'man'].forEach(function (k) {
        var a = A[k];
        var d = a.tx - a.x;
        var speed = (k === 'man') ? 0.9 : 1.4;
        if (Math.abs(d) > 0.6) {
          a.walking = true; a.dir = d > 0 ? 1 : -1;
          a.x += a.dir * Math.min(speed, Math.abs(d));
        } else {
          a.walking = false; a.x = a.tx;
        }
      });
    }

    // ── full scene draw (ported draw()) ────────────────────────────────────
    function draw() {
      var ox = 0, oy = 0;
      if (S.shaking && !REDUCE) { ox = round((rnd() - 0.5) * 4); oy = round((rnd() - 0.5) * 2); }
      ctx.setTransform(1, 0, 0, 1, 0, 0);
      sky();
      if (S.heat) { heatSky(); }
      ctx.setTransform(1, 0, 0, 1, 0, oy);
      ground();
      if (S.heat) { heatShimmer(); }
      roof(ox);
      rooms(ox);
      furniture(ox);
      if (S.heat) { heatObjects(ox); }
      ctx.setTransform(1, 0, 0, 1, ox, oy);
      drawMan(A.man);
      drawJade(A.jade);
      drawAmber(A.amber);
      // thinking indicators sit above the heads (dots only, no text)
      ['amber', 'jade'].forEach(function (k) { if (A[k].thinking) drawThinking(A[k]); });
      ctx.setTransform(1, 0, 0, 1, 0, 0);
      lights();
    }

    // ── overlap resolution (Req 1.8) ───────────────────────────────────────
    // When two characters resolve to the same canvas position, offset them
    // horizontally so they do not overlap. Lives here (not in the pure
    // Position_Map) per design decision 5.
    var OVERLAP_STEP = 9;
    function resolveOverlaps() {
      var order = ['man', 'amber', 'jade'];
      for (var i = 1; i < order.length; i++) {
        for (var j = 0; j < i; j++) {
          var a = A[order[i]], b = A[order[j]];
          if (Math.abs(a.tx - b.tx) < OVERLAP_STEP) {
            // push the later character aside, clamped into the canvas
            var dir = (a.tx >= b.tx) ? 1 : -1;
            a.tx = Math.max(0, Math.min(W - 1, b.tx + dir * OVERLAP_STEP));
          }
        }
      }
    }

    // ── World_State → character placement ──────────────────────────────────
    // Resolve one character's (room, spot[, activity]) to a canvas position
    // via the pure Position_Map, falling back to the room default when the
    // map returns no position (Req 1.6, 1.7). `positionMap` is the browser
    // global from position-map.js.
    function resolvePos(roomKey, spot, activity, manPos) {
      var pm = (typeof window !== 'undefined' && window.positionMap)
        ? window.positionMap
        : (typeof positionMap !== 'undefined' ? positionMap : null);
      if (!pm) { return { x: Math.floor(W / 2), y: FY }; }
      var r = pm(roomKey, spot || null, activity || null, manPos || null);
      if (r && r.ok) { return { x: r.x, y: r.y }; }
      // Req 1.7: no resolvable position → the room's default standing place.
      // Ask the map again with no spot (room default) to stay in-bounds.
      var d = pm(roomKey, null, null, null);
      if (d && d.ok) { return { x: d.x, y: d.y }; }
      return { x: Math.floor(W / 2), y: FY };
    }

    // Man pose: Spot first, then Man_Activity (Req 1.9).
    function manPose(spot, activity, covered) {
      if (spot === 'under_table') return 'duck';
      if (covered) return 'duck';
      if (spot === 'beside_futon') return 'sit';
      switch (activity) {
        case 'sitting_at_table': return 'sit';
        case 'asleep_in_futon':  return 'lie';
        case 'lying_in_futon':   return 'lie';
        case 'bathing':          return 'bath';
        case 'cooking':          return 'cook';
        case 'standing':         return 'stand';
        default:                 return 'stand';
      }
    }

    function heldKind(held) {
      // Map an engine held-object id to a drawable kind; a flashlight/lamp
      // lights the dark, a cup is the drink, anything else draws nothing.
      if (!held) return null;
      if (/light|lamp|torch|flashlight/i.test(held)) return 'light';
      if (/cup|glass|drink|mug/i.test(held)) return 'cup';
      return null;
    }

    // ── public controller interface ────────────────────────────────────────
    function ensureSized() {
      cv.width = W; cv.height = H;
      ctx = cv.getContext('2d');
      ctx.imageSmoothingEnabled = false;
      if (typeof document !== 'undefined') {
        dark = document.createElement('canvas');
        dark.width = W; dark.height = H; dctx = dark.getContext('2d');
      }
    }

    var api = {
      /**
       * mount(container[, overlayEl])
       * Create/attach the canvas (480x236, imageSmoothingEnabled=false). If a
       * <canvas> element is passed it is adopted; otherwise one is created and
       * appended to the container. An optional overlay element (positioned over
       * the canvas) holds the name tags and speech bubbles.
       */
      mount: function (container, overlayEl) {
        if (container && container.tagName === 'CANVAS') {
          cv = container;
        } else {
          if (typeof document === 'undefined') { throw new Error('mount needs a document'); }
          cv = document.createElement('canvas');
          if (container && container.appendChild) container.appendChild(cv);
        }
        ensureSized();
        ov = overlayEl || null;
        buildOverlay();
        buildCaption();
        return cv;
      },

      /**
       * setWorld(worldState) — snap EVERY character to its recorded place and
       * redraw the whole scene (Req 1.*, 3.8). Consumes the world_json shape
       * documented in design §3 (timeOfDay, power, shaking, robots.{robot_a,
       * robot_b}, man, rooms[key].light, objects.{broken,fallen}, stove.on).
       * Translates robot_a→amber, robot_b→jade at the renderer boundary.
       */
      setWorld: function (ws) {
        ws = ws || {};
        S.timeOfDay = ws.timeOfDay || 'night';
        S.power = (ws.power === undefined) ? true : (ws.power === 'on' || ws.power === true);
        S.shaking = !!ws.shaking;
        S.stoveOn = !!(ws.stove && ws.stove.on);
        S.broken = {}; S.fallen = {};
        var objs = ws.objects || {};
        (objs.broken || []).forEach(function (id) { S.broken[id] = true; });
        (objs.fallen || []).forEach(function (id) { S.fallen[id] = true; });
        // Heat wave only: the heat fields come from the serializer (disaster,
        // roomTemps, outside, ac{on}, fan{on,room}, windows). An earthquake
        // world has none of them, so S.heat stays null and nothing heat is run.
        if (ws.disaster === 'heat_wave') {
          S.heat = {
            ac: !!(ws.ac && ws.ac.on),
            fan: { on: !!(ws.fan && ws.fan.on), room: (ws.fan && ws.fan.room) || null },
            windows: ws.windows || {},
            outside: (typeof ws.outside === 'number') ? ws.outside : null
          };
          S.timeOfDay = 'afternoon';   // a heat wave is an afternoon scene
        } else {
          S.heat = null;
        }
        S.rooms = {};
        var rr = ws.rooms || {};
        Object.keys(rr).forEach(function (key) { S.rooms[key] = { light: (rr[key] && rr[key].light) || 'normal' }; });

        // The man first (near_resident depends on his position).
        var man = ws.man || {};
        var manPos = resolvePos(man.room || 'living_room', man.spot || null, man.activity || null, null);
        A.man.x = manPos.x; A.man.tx = manPos.x;
        A.man.pose = manPose(man.spot || null, man.activity || null, !!man.covered);
        A.man.fear = (typeof man.fear === 'number') ? man.fear : 0;
        A.man.dir = 1;

        var robots = ws.robots || {};
        var ra = robots.robot_a || {}, rb = robots.robot_b || {};
        var pa = resolvePos(ra.room || 'living_room', ra.spot || null, null, manPos);
        var pb = resolvePos(rb.room || 'living_room', rb.spot || null, null, manPos);
        A.amber.x = pa.x; A.amber.tx = pa.x; A.amber.held = heldKind(ra.held); A.amber.pose = 'idle'; A.amber.walking = false;
        A.jade.x = pb.x; A.jade.tx = pb.x; A.jade.held = heldKind(rb.held); A.jade.pose = 'idle'; A.jade.walking = false;

        resolveOverlaps();
        // snapping leaves no walk in progress
        A.amber.x = A.amber.tx; A.jade.x = A.jade.tx; A.man.x = A.man.tx;

        if (ctx) { draw(); placeOverlay(); }
      },

      /**
       * moveCharacter(id, targetPos) — start a walk toward a canvas position.
       * Under reduced motion the position is set directly (no tween).
       * id is 'amber'|'jade'|'man'.
       */
      moveCharacter: function (id, targetPos) {
        var a = A[id]; if (!a || !targetPos) return;
        var tx = Math.max(0, Math.min(W - 1, targetPos.x));
        a.tx = tx;
        if (REDUCE) { a.x = tx; a.walking = false; }
      },

      /** setPose(id, pose) — set a character's drawn pose. */
      setPose: function (id, pose) { var a = A[id]; if (a) a.pose = pose; },

      /** setHeld(id, objectId|null) — show/clear a held object. */
      setHeld: function (id, objectId) { var a = A[id]; if (a) a.held = heldKind(objectId); },

      /** setThinking(id, bool) — toggle the small thinking indicator (dots). */
      setThinking: function (id, on) { var a = A[id]; if (a) a.thinking = !!on; },

      /**
       * showBubble(id, spokenLine[, seconds]) — show a SPOKEN line in that
       * character's bubble. Never a thought (Req 1.14, 3.6): callers must pass
       * spoken lines only. An empty/falsy line clears the bubble.
       */
      showBubble: function (id, spokenLine, seconds) {
        var b = bubbles[id]; if (!b) return;
        if (!spokenLine) {
          b.el.classList.remove('show'); b.tail.classList.remove('show');
          b.until = 0; b.text = '';
          if (cap && isNarrow()) { renderCaption(); }
          return;
        }
        // Record the current spoken line and its `until` time. The lifetime
        // (and the ≥ 2.0 s minimum that Act_Out applies via max(2.0 s, actMs))
        // is unchanged: it governs both the bubble and the caption line, since
        // both read the same `until`. The default seconds (4) is also kept.
        var text = String(spokenLine);
        b.text = text;
        b.until = perfNow() + (seconds || 4) * 1000;
        if (isNarrow()) {
          // Narrow: keep the over-canvas bubble hidden; the strip shows it.
          b.el.classList.remove('show'); b.tail.classList.remove('show');
          if (cap) { renderCaption(); }
          return;
        }
        // Wide: show the over-canvas bubble exactly as before.
        b.el.innerHTML = '';
        var who = document.createElement('span'); who.className = 'who'; who.textContent = 'says';
        b.el.appendChild(who); b.el.appendChild(document.createTextNode(text));
        b.el.classList.add('show'); b.tail.classList.add('show');
        b.el.classList.remove('think'); b.tail.classList.remove('think');
      },

      /** start() — begin the requestAnimationFrame draw loop. */
      start: function () {
        if (running || !ctx) return;
        running = true;
        var last = 0, acc = 0, stepMs = 1000 / 20;
        function loop(ts) {
          if (!running) return;
          if (!last) last = ts;
          acc += ts - last; last = ts;
          while (acc >= stepMs) { stepSim(); acc -= stepMs; }
          draw();
          placeOverlay();
          rafId = requestAnimationFrame(loop);
        }
        if (typeof requestAnimationFrame !== 'undefined') {
          rafId = requestAnimationFrame(loop);
        }
      },

      /** stop() — stop the draw loop. */
      stop: function () {
        running = false;
        if (rafId && typeof cancelAnimationFrame !== 'undefined') { cancelAnimationFrame(rafId); }
        rafId = 0;
      },

      /**
       * reset() — return to the mount-time scene (calm night, characters at
       * their starting places, no heat scene) and clear every bubble, the
       * caption strip and the thinking flags. Does not start or stop the loop.
       */
      reset: function () {
        frame = 0;
        seed = SEED0;
        S.timeOfDay = 'night'; S.power = true; S.shaking = false; S.stoveOn = false;
        S.broken = {}; S.fallen = {}; S.rooms = {}; S.heat = null;
        var start = { amber: ROOM.kitchen.x0 + 50, jade: ROOM.bedroom.x0 + 34, man: ROOM.living_room.x0 + 40 };
        Object.keys(start).forEach(function (k) {
          var fresh = mkChar(start[k]);
          Object.keys(fresh).forEach(function (p) { A[k][p] = fresh[p]; });
        });
        A.man.pose = 'sit';
        BATS.forEach(function (bt, i) { bt.x = BATS0[i].x; bt.y = BATS0[i].y; });
        Object.keys(bubbles).forEach(function (k) {
          var b = bubbles[k];
          b.el.classList.remove('show'); b.tail.classList.remove('show');
          b.until = 0; b.text = '';
        });
        if (cap) { cap.innerHTML = ''; }
        if (ctx) { draw(); placeOverlay(); }
      },

      // Internal-ish accessors handy for Act_Out and tests (not required by
      // the design but consistent with how controllers drive the renderer).
      _state: function () { return { S: S, A: A, frame: frame, W: W, H: H }; }
    };

    return api;
  }

  // Also expose a class-style wrapper for callers that prefer `new`.
  function HouseRenderer() {
    var impl = createHouseRenderer();
    var self = this;
    Object.keys(impl).forEach(function (k) { self[k] = impl[k]; });
  }

  if (typeof window !== 'undefined') {
    window.createHouseRenderer = createHouseRenderer;
    window.HouseRenderer = HouseRenderer;
  }
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = { createHouseRenderer: createHouseRenderer, HouseRenderer: HouseRenderer };
  }
})();
