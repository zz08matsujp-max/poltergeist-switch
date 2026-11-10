'use strict';
// A 2D context stub that records drawing calls and style assignments.
// Each log entry is a short string, so a log is easy to compare and store.

const RECORDED_CALLS = [
  'fillRect', 'clearRect', 'arc', 'fill', 'beginPath', 'closePath', 'moveTo', 'lineTo',
  'setTransform', 'drawImage', 'createLinearGradient', 'createRadialGradient',
];
const RECORDED_PROPS = ['fillStyle', 'globalAlpha', 'globalCompositeOperation'];

function makeContext(log) {
  const ctx = { imageSmoothingEnabled: true };
  RECORDED_CALLS.forEach(function (name) {
    ctx[name] = function () {
      const args = Array.prototype.slice.call(arguments).map(function (a) {
        return (a && typeof a === 'object') ? '[canvas]' : String(a);
      });
      log.push(name + '(' + args.join(',') + ')');
      if (name === 'createLinearGradient' || name === 'createRadialGradient') {
        return { addColorStop: function (o, c) { log.push('addColorStop(' + o + ',' + c + ')'); } };
      }
      return undefined;
    };
  });
  RECORDED_PROPS.forEach(function (prop) {
    let value = prop === 'globalAlpha' ? 1 : '';
    Object.defineProperty(ctx, prop, {
      get: function () { return value; },
      set: function (v) { value = v; log.push(prop + '=' + String(v)); },
      enumerable: true,
    });
  });
  return ctx;
}

// A canvas whose getContext('2d') returns one recording context writing to `log`.
function makeCanvas(log) {
  const ctx = makeContext(log);
  return {
    tagName: 'CANVAS',
    width: 0,
    height: 0,
    clientWidth: 0,
    getContext: function () { return ctx; },
  };
}

// Installs a minimal global `document` so the renderer can create its offscreen
// darkness canvas. Returns a function that removes it.
function installDocument(log) {
  global.document = {
    createElement: function (tag) {
      if (tag === 'canvas') { return makeCanvas(log); }
      return { className: '', setAttribute: function () {}, appendChild: function () {} };
    },
  };
  return function () { delete global.document; };
}

module.exports = { makeCanvas: makeCanvas, makeContext: makeContext, installDocument: installDocument };
