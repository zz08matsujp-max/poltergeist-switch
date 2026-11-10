'use strict';
// Small seeded generators for the JS tests. No dependencies.

function mulberry32(seed) {
  let a = seed >>> 0;
  return function () {
    a = (a + 0x6D2B79F5) >>> 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

function int(rng, min, max) { return min + Math.floor(rng() * (max - min + 1)); }
function pick(rng, list) { return list[Math.floor(rng() * list.length)]; }
function bool(rng, p) { return rng() < (p === undefined ? 0.5 : p); }
function subset(rng, list, p) { return list.filter(function () { return rng() < (p === undefined ? 0.5 : p); }); }

module.exports = { mulberry32: mulberry32, int: int, pick: pick, bool: bool, subset: subset };
