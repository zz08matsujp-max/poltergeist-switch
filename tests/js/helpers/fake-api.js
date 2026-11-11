'use strict';
// A scripted fake of the api.js client for the Observe tests. No dependencies.
//
//   const api = createFakeApi({
//     createEpisode: [{ status: 200, body: view }],           // queue: one entry per call
//     getEpisode: function (i, args) { return { status: 200, body: view }; },
//     ask: { status: 200, body: {} }                          // a single value repeats
//   });
//
// Each method's script is one of:
//   - an array of responses, used in call order (the last one repeats);
//   - a function (callIndex, args) returning a response (or a Promise);
//   - a single { status, body } object, returned on every call.
// A response may also be an Error instance (the call rejects, like a network
// failure) or a Promise (e.g. a never-settling one). A method with no script
// answers { status: 200, body: null }.
//
// `calls` records every call as { method, args, index } in order; `count(m)`
// gives the number of calls of one method. `judgePass` and `judgeStatus` exist
// on the fake only when the script names them.

const METHODS = ['createEpisode', 'flipDisaster', 'getEpisode', 'ask', 'apply', 'reflect',
  'memoryStatus', 'resetMemory', 'listEnded'];

const OPTIONAL_METHODS = ['judgePass', 'judgeStatus'];

function createFakeApi(script) {
  const scripts = script || {};
  const calls = [];
  const counts = {};

  function respond(method, args) {
    const index = counts[method] || 0;
    counts[method] = index + 1;
    calls.push({ method: method, args: args, index: index });

    const s = scripts[method];
    let r;
    if (typeof s === 'function') {
      try { r = s(index, args); } catch (e) { return Promise.reject(e); }
    } else if (Array.isArray(s)) {
      r = s.length === 0 ? { status: 200, body: null } : s[Math.min(index, s.length - 1)];
    } else if (s !== undefined) {
      r = s;
    } else {
      r = { status: 200, body: null };
    }
    if (r instanceof Error) { return Promise.reject(r); }
    return Promise.resolve(r);
  }

  const api = {
    calls: calls,
    count: function (method) { return counts[method] || 0; },
    callsOf: function (method) { return calls.filter(function (c) { return c.method === method; }); }
  };
  METHODS.forEach(function (m) {
    api[m] = function () { return respond(m, Array.prototype.slice.call(arguments)); };
  });
  // Optional calls (judge pass): present only when scripted, so a fake without
  // them behaves exactly as before and Observe's typeof guards stay untouched.
  OPTIONAL_METHODS.forEach(function (m) {
    if (scripts[m] !== undefined) {
      api[m] = function () { return respond(m, Array.prototype.slice.call(arguments)); };
    }
  });
  return api;
}

module.exports = { createFakeApi: createFakeApi };
