/*
 * api.js — relative-URL API client for the Screens spec (Requirements 12.9, 12.10).
 *
 * Every request is built from a RELATIVE base ('api/index.php') with NO leading
 * '/' and NO absolute origin, so the same client works unchanged both at the
 * deployed web root (https://kiro.pyol.net/, where public/ IS the web root and
 * api/ sits beside it, resolving to /api/index.php) and locally under /public/
 * (where it resolves relative to the page's directory). The browser resolves
 * the relative base against the current page URL; no page or call ever names an
 * absolute path or origin (design "Deployment and relative URLs").
 *
 * The underlying JSON API is the turn-loop API reached through the ?route=
 * front controller (api/index.php; see api/router.php). The routes are:
 *   POST  episodes                         — create          createEpisode()
 *   POST  episodes/{id}/disaster           — flip earthquake flipDisaster(id)
 *   POST  episodes/{id}/turns/{n}/ask      — ask the robots  ask(id, turn)
 *   POST  episodes/{id}/turns/{n}/apply    — apply the turn  apply(id, turn)
 *   GET   episodes/{id}                    — one episode     getEpisode(id)
 *   GET   episodes&ended=1&limit=N         — the ended list  listEnded(limit)
 *   POST  episodes/{id}/reflect            — reflect an end  reflect(id)
 *   GET   memory                           — memory status   memoryStatus()
 *   POST  memory/reset                     — new generation  resetMemory()
 *
 * Every method returns { status, body } where status is the HTTP status code
 * (the controllers need to see 409/404/400/500) and body is the parsed JSON
 * (carrying `error`, `currentExpectedTurn`, `turnState`, etc., when present).
 * Requests ride the device cookie via credentials: 'same-origin'. The server
 * sets Cache-Control: no-store on every response; this client adds no caching
 * of its own (no leading-slash path, no absolute origin, no stored responses).
 *
 * No build step on the server: plain browser JS. It attaches `createApiClient`
 * to the browser global when a window exists, and exports via CommonJS when a
 * module system exists (so a headless check can require it).
 */
(function () {
  'use strict';

  // The relative base: no leading '/', no origin. A page at the web root
  // resolves this against '/', giving '/api/index.php'; a page under /public/
  // resolves it against that directory. This single string is the ONLY place
  // the API location is named, and it is deliberately relative (Req 12.9/12.10).
  var API_BASE = 'api/index.php';

  // Build a request URL for a given route value and optional extra query pairs.
  // The route value is percent-encoded as a whole (e.g. 'episodes/abc' ->
  // 'episodes%2Fabc') so a slash inside an id never breaks the query, matching
  // index.php's `?route=` parsing. The result stays relative — it never gains a
  // leading slash or an origin.
  function buildUrl(routeValue, extraQuery) {
    var qs = 'route=' + encodeURIComponent(routeValue);
    if (extraQuery) {
      for (var key in extraQuery) {
        if (Object.prototype.hasOwnProperty.call(extraQuery, key)) {
          qs += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(extraQuery[key]);
        }
      }
    }
    return API_BASE + '?' + qs;
  }

  // Perform a fetch and return { status, body }. The body is parsed as JSON
  // when the response carries any text; a non-JSON or empty body yields null so
  // the caller still gets the status. Network failures surface as a rejected
  // promise for the caller to handle (e.g. the Replay list 10 s timeout).
  function request(method, url) {
    return fetch(url, {
      method: method,
      // Carry the device cookie so the server can match this device's slot.
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    }).then(function (res) {
      return res.text().then(function (text) {
        var body = null;
        if (text) {
          try { body = JSON.parse(text); } catch (e) { body = null; }
        }
        return { status: res.status, body: body };
      });
    });
  }

  // Coerce a turn number to a non-negative integer string for the path. Turns
  // are zero-based; router.php accepts digits only.
  function turnSegment(turn) {
    var n = Number(turn);
    if (!isFinite(n) || n < 0) { n = 0; }
    return String(Math.floor(n));
  }

  function createApiClient() {
    return {
      /** POST episodes — create one episode for this device. */
      createEpisode: function () {
        return request('POST', buildUrl('episodes'));
      },

      /** POST episodes/{id}/disaster — flip the earthquake for an episode. */
      flipDisaster: function (id) {
        return request('POST', buildUrl('episodes/' + String(id) + '/disaster'));
      },

      /** POST episodes/{id}/turns/{n}/ask — ask the robots for turn n. */
      ask: function (id, turn) {
        return request('POST', buildUrl('episodes/' + String(id) + '/turns/' + turnSegment(turn) + '/ask'));
      },

      /** POST episodes/{id}/turns/{n}/apply — apply turn n. */
      apply: function (id, turn) {
        return request('POST', buildUrl('episodes/' + String(id) + '/turns/' + turnSegment(turn) + '/apply'));
      },

      /** GET episodes/{id} — fetch one episode view (with per-turn world). */
      getEpisode: function (id) {
        return request('GET', buildUrl('episodes/' + String(id)));
      },

      /**
       * GET episodes&ended=1&limit=N — the ended-episode list, newest first.
       * limit is omitted from the query when not supplied so the server applies
       * its default of 20; an out-of-range limit is rejected by the server.
       */
      listEnded: function (limit) {
        var extra = { ended: '1' };
        if (limit !== undefined && limit !== null && limit !== '') {
          extra.limit = limit;
        }
        return request('GET', buildUrl('episodes', extra));
      },

      /**
       * POST episodes/{id}/reflect — ask both robots once after an ended
       * episode (robot-memory Requirement 2.1). Idempotent per robot per
       * episode, so a repeat call (the missed-reflection catch-up and the
       * End_Card both firing) is safe. Returns { status, body } where a 409
       * body carries a short code ('not_ended' / 'not_reflectable').
       */
      reflect: function (id) {
        return request('POST', buildUrl('episodes/' + String(id) + '/reflect'));
      },

      /**
       * GET memory — the current generation and per-robot active-memory counts
       * and lengths (robot-memory Requirement 5.1). Body shape:
       * { generation, robots: { amber: {activeCount, lengths}, jade: {…} } }.
       */
      memoryStatus: function () {
        return request('GET', buildUrl('memory'));
      },

      /**
       * POST memory/reset — start a new memory generation, deleting nothing
       * (robot-memory Requirement 4.5). Body shape: { generation: N }.
       */
      resetMemory: function () {
        return request('POST', buildUrl('memory/reset'));
      },

      /** Exposed for tests/controllers that need the raw relative base. */
      _base: API_BASE,
      /** Exposed so a caller can inspect/compose a URL without a request. */
      _buildUrl: buildUrl
    };
  }

  if (typeof window !== 'undefined') {
    window.createApiClient = createApiClient;
  }
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = { createApiClient: createApiClient };
  }
})();
