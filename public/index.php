<?php

declare(strict_types=1);

/**
 * Observe_Screen (screens Requirement 4): the site's top page, served at
 * public/index.php as https://kiro.pyol.net/. It creates one live earthquake
 * episode and runs it turn by turn through the turn-loop API.
 *
 * This file holds NO rules logic and touches NO database or AI provider. It is
 * a static HTML document whose only PHP is the no-store header below. All
 * episode work happens in the browser against api/index.php (see public/js/).
 *
 * The markup leaves the hooks observe.js fills in (the End_Card container,
 * the resume / busy message slots).
 *
 * Caching: nginx sits in front of PHP, so every page MUST be uncacheable. The
 * no-store header is the FIRST thing this script does, before any output
 * (Requirement 4.1, 12.7).
 *
 * Relative URLs only: on the server public/ IS the web root (api/ sits beside
 * it); locally the same files serve under /public/. So every script, style and
 * API call uses a relative URL with no leading '/' and no absolute origin, so
 * the one set of files works in both places (Requirement 12.9, 12.10).
 *
 * The two robots are named only Amber (robot_a) and Jade (robot_b). The ONE
 * place an AI company/model is named is the header model line below, which is
 * page chrome allowed to name the providers (Requirement 4.2); the robots'
 * own text and the "What happened" log never name a company (Requirement 12.8).
 */

header('Cache-Control: no-store');

require_once __DIR__ . '/security_headers.php';
ps_send_security_headers();

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Poltergeist Switch</title>

  <!-- DotGothic16 (Google Fonts). preconnect + the stylesheet the CSS @imports;
       screens.css also @imports the same family so text is visible during load
       (Req 12.1, 12.2). All relative — no leading '/', no origin. -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DotGothic16&display=swap">

  <!-- Shared screen styles (ported look). Relative URL (Req 12.9, 12.10). -->
  <link rel="stylesheet" href="css/screens.css">
</head>
<body>
  <div class="wrap">
    <header>
      <h1>Poltergeist Switch</h1>
      <p class="tag">
        A behaviour lab for future home robots. A 1986 rural Japanese house,
        no internet, two robots and one very slow 80-year-old man. Flip a
        switch and watch what each robot does. There is no score &mdash; only
        the record.
      </p>
      <!-- Model line: PAGE chrome, the one place provider/model names appear
           (Req 4.2). The robots never see this and their own text never names
           a company (Req 12.8). -->
      <p class="models">
        Amber runs on Anthropic's <code>claude-haiku-5-5</code>.
        Jade runs on OpenAI's <code>gpt-6-luna</code>.
      </p>
      <p class="models">
        <a href="replay.php">Watch a past episode &rarr;</a>
      </p>
    </header>

    <!-- House_Renderer stage. house-renderer.js mount(canvas, overlay) adopts
         the <canvas> and positions name tags / bubbles in the .overlay. The
         .inner is position:relative so the overlay can be placed over it. -->
    <section class="stage" aria-label="The house">
      <div class="inner" id="stage-inner">
        <canvas id="house" width="480" height="236"
                role="img" aria-label="Cross-section of the house with Amber, Jade and the man"></canvas>
        <div class="overlay" id="overlay" aria-hidden="true"></div>
      </div>
    </section>

    <div class="board observe">
      <!-- Breaker switches: exactly two (Earthquake, Heat wave); lever-up = ON,
           lever-down = OFF. On first load both levers are OFF (no .on class). -->
      <section class="panel" aria-label="Disaster switches">
        <h2>Breaker switches</h2>
        <div class="switches">
          <button type="button" class="sw" id="sw-earthquake" aria-pressed="false">
            <span class="lever" aria-hidden="true"></span>
            <span>Earthquake</span>
            <span class="mark">OFF</span>
          </button>
          <button type="button" class="sw" id="sw-heat" aria-pressed="false">
            <span class="lever" aria-hidden="true"></span>
            <span>Heat wave</span>
            <span class="mark">OFF</span>
          </button>
        </div>
        <!-- Notice area: 409 / resume / busy / 429 / Start hint messages appear
             here, directly under the levers. Hidden while empty. -->
        <div class="message" id="notice" aria-live="polite" hidden></div>
      </section>

      <!-- Resident meters: temperature, water, injury, fear. injury and fear
           carry the alarm-coloured fill (Req 4.10). Start empty / zeroed. -->
      <section class="panel" aria-label="The man's condition">
        <h2>The man</h2>
        <div class="meters">
          <div class="meter" id="meter-temp">
            <span class="label">Temp</span>
            <span class="bar"><span class="fill"></span></span>
            <span class="val">&mdash;</span>
          </div>
          <div class="meter" id="meter-water">
            <span class="label">Water</span>
            <span class="bar"><span class="fill"></span></span>
            <span class="val">&mdash;</span>
          </div>
          <div class="meter injury" id="meter-injury">
            <span class="label">Injury</span>
            <span class="bar"><span class="fill"></span></span>
            <span class="val">&mdash;</span>
          </div>
          <div class="meter fear" id="meter-fear">
            <span class="label">Fear</span>
            <span class="bar"><span class="fill"></span></span>
            <span class="val">&mdash;</span>
          </div>
          <!-- Heat wave only: the temperature of the room the man is in.
               Hidden (and never filled) in an earthquake episode. -->
          <div class="meter room" id="meter-room" hidden>
            <span class="label">Room</span>
            <span class="bar"><span class="fill"></span></span>
            <span class="val">&mdash;</span>
          </div>
        </div>
      </section>

      <!-- "What happened" log, grouped and ordered by turn first -> most recent
           (Req 4.10). New entries are announced via aria-live=polite (Req 12.5,
           finalised in task 11 but added now). Starts empty. -->
      <section class="panel" aria-label="What happened">
        <h2>What happened</h2>
        <div class="log" id="log" aria-live="polite"></div>
      </section>
    </div>

    <!-- Controls: Start and Voices on/off. Levers only choose the disaster;
         Start creates the episode for the lever that is ON and starts the run. -->
    <div class="controls">
      <button type="button" class="btn primary" id="btn-start">Start</button>
      <button type="button" class="btn" id="btn-voices" aria-pressed="true">Voices: on</button>
    </div>

    <!-- Memory bar (robot-memory spec, Req 7.1, 7.2). observe.js fills the
         status line from GET /api/memory on load and after each reset, with the
         exact form "Generation N · Amber remembers k · Jade remembers k". "All
         reset" reveals the in-page confirm panel below it (no browser dialog);
         Confirm calls POST /api/memory/reset. The robots are named only Amber
         and Jade here (Req 7.7). -->
    <div class="memory-bar" aria-label="Robot memory">
      <span class="memory-status" id="memory-status" aria-live="polite"></span>
      <button type="button" class="btn" id="btn-reset-memory">All reset</button>
    </div>
    <!-- Mount point for the in-page All-reset confirmation panel (Req 7.2).
         observe.js builds the Confirm / Cancel panel into here via memory-ui.js
         and removes it on Confirm or Cancel. Empty and hidden until then. -->
    <div class="confirm-host" id="reset-confirm-host" hidden></div>

    <!-- End_Card container. observe.js fills it when a run ends. The notice
         area lives in the Breaker switches panel above. -->
    <div id="endcard-host"></div>
  </div>

  <!-- Scripts, in dependency order; observe.js last. All relative (Req 12.9,
       12.10). position-map.js -> house-renderer.js -> act-out.js -> voices.js
       -> api.js -> memory-ui.js -> observe.js. memory-ui.js is the shared
       status-line / confirm-panel / reflect-phrasing helper (robot-memory Req
       7); observe.js uses it, so it loads first. -->
  <script src="js/position-map.js"></script>
  <script src="js/house-renderer.js"></script>
  <script src="js/speech-sequencer.js"></script>
  <script src="js/act-out.js"></script>
  <script src="js/voices.js"></script>
  <script src="js/api.js"></script>
  <script src="js/memory-ui.js"></script>
  <script src="js/observe.js"></script>
</body>
</html>
