// Camera for filming the replay: framings measured from the page, keyframes in seconds of film, eased moves between them.
// The zoom is a CSS transform on <body> applied only while the screenshot is taken, so the page's own layout code never sees it.
const VW = 1760, VH = 990;
async function framings(p) {
  return await p.evaluate(([VW, VH]) => {
    const R = id => { const r = document.getElementById(id).getBoundingClientRect(); return { x: r.left + scrollX, y: r.top + scrollY, w: r.width, h: r.height }; };
    const st = R('stage'), rd = R('reader');
    const fit = (x0, y0, x1, y1) => { const s = Math.min(VW / (x1 - x0), VH / (y1 - y0)); const w = VW / s, h = VH / s; return { x: Math.min(Math.max(0, (x0 + x1) / 2 - w / 2), VW - w), y: Math.max(0, (y0 + y1) / 2 - h / 2), w, h }; };
    return {
      F: { x: 0, y: 0, w: VW, h: VH },
      H: fit(st.x - 10, st.y - 10, st.x + st.w + 10, st.y + st.h + 10),
      R: fit(rd.x - 20, rd.y - 20, rd.x + rd.w + 20, rd.y + rd.h + 20),
      W: fit(st.x + st.w * 0.2, Math.min(st.y, rd.y) - 12, rd.x + rd.w + 12, Math.max(st.y + st.h, rd.y + rd.h) + 12),
    };
  }, [VW, VH]);
}
const ease = u => u < 0 ? 0 : u > 1 ? 1 : u * u * (3 - 2 * u);
function at(keys, F, t) {   // keys: [[t, name], ...] sorted; returns the visible rect at time t
  if (t <= keys[0][0]) return F[keys[0][1]];
  for (let i = 1; i < keys.length; i++) if (t <= keys[i][0]) {
    const [t0, a] = keys[i - 1], [t1, b] = keys[i], u = ease((t - t0) / (t1 - t0)), A = F[a], B = F[b];
    return { x: A.x + (B.x - A.x) * u, y: A.y + (B.y - A.y) * u, w: A.w + (B.w - A.w) * u, h: A.h + (B.h - A.h) * u };
  }
  return F[keys[keys.length - 1][1]];
}
async function apply(p, r) {
  const s = VW / r.w;
  await p.evaluate(([s, x, y]) => { document.documentElement.style.overflow = 'hidden'; const b = document.body.style; b.transformOrigin = '0 0'; b.transform = s === 1 && !x && !y ? '' : `scale(${s}) translate(${-x}px, ${-y}px)`; }, [s, r.x, r.y]);
}
async function clear(p) { await p.evaluate(() => { document.body.style.transform = ''; }); }
module.exports = { framings, at, apply, clear };
