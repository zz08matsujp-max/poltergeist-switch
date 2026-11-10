<?php

declare(strict_types=1);

/**
 * Replay_Screen (screens Requirement 8/9): served at public/replay.php. It
 * lists ended episodes and plays a chosen stored episode back turn by turn,
 * with each reply shown and its evidence quotes lit up. It makes NO AI call at
 * any time (Req 8.6): the page only ever reads through GET /api/episodes
 * (the ended list) and GET /api/episodes/{id} (one stored episode).
 *
 * This file holds NO rules logic and touches NO database or AI provider. It is
 * a static HTML document whose only PHP is the no-store header below. All work
 * happens in the browser against api/index.php (see public/js/replay.js).
 *
 * This task (10.1) builds the PAGE + the episode LIST and the direct-load
 * dispatch; the turn-by-turn PLAYBACK and evidence highlighting are task 10.2.
 * The markup carries the playback regions (.replay-main > .stage/.inner,
 * .replies, the .controls and the "What it was told this turn" affordance) so
 * 10.2 only adds behaviour, not structure; replay.js hands a loaded view to a
 * clearly-marked startPlayback() stub that 10.2 fills in.
 *
 * Caching: nginx sits in front of PHP, so every page MUST be uncacheable. The
 * no-store header is the FIRST thing this script does, before any output
 * (Requirement 8.1, 12.7).
 *
 * Relative URLs only: on the server public/ IS the web root (api/ sits beside
 * it); locally the same files serve under /public/. So every script, style and
 * the Google Fonts link uses a relative URL with no leading '/' and no absolute
 * origin, so the one set of files works in both places (Req 12.9, 12.10).
 *
 * The two robots are named only Amber (robot_a) and Jade (robot_b); no AI
 * company/model name appears on this page (Req 12.8) — the model line lives
 * only on the Observe top page, not here.
 */

header('Cache-Control: no-store');

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Poltergeist Switch &mdash; Replay</title>

  <!-- DotGothic16 (Google Fonts). preconnect + the stylesheet; screens.css also
       @imports the same family so text is visible during font load (Req 12.1,
       12.2). All relative — no leading '/', no origin. -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DotGothic16&display=swap">

  <!-- Shared screen styles (ported look). Relative URL (Req 12.9, 12.10). -->
  <link rel="stylesheet" href="css/screens.css">
</head>
<body>
  <div class="wrap">
    <header>
      <h1>Replay</h1>
      <p class="tag">
        Watch a finished episode play back turn by turn. Each robot's reply is
        shown beside the house, with the words the recorder relied on lit up.
        Nothing here calls a robot &mdash; it only reads the record.
      </p>
      <!-- Back to the Observe top page. Relative URL; supports Req 8.9 from the
           Replay side (the top page already links here). -->
      <p class="models">
        <a href="index.php">&larr; Back to the live lab</a>
      </p>
      <!-- Voices toggle (public-lab Req 11.1): in the header, outside the
           hidden controls, so it is available in list and playback modes.
           Label and aria-pressed are kept by bindVoicesButton. -->
      <button type="button" class="btn" id="btn-voices" aria-pressed="true">Voices: on</button>
    </header>

    <!-- A message area for the list/load errors and the empty-list state
         (Req 8.3, 8.4, 8.8). Starts hidden; replay.js fills and shows it. -->
    <div class="message" id="notice" hidden></div>

    <!-- LIST mode container (Req 8.2, 8.5). replay.js renders one .item per
         ended episode here; each item is clickable and enters playback by
         navigating to replay.php?episode=<id>. Hidden in direct-load mode. -->
    <div class="episode-list" id="episode-list" hidden></div>

    <!-- PLAYBACK region. The .replay-main is a two-column grid at >= 768px:
         the LEFT (stage) column holds the House_Renderer canvas, the playback
         controls, the "Turn N of M" counter and the "What it was told" panel,
         so the controls sit DIRECTLY UNDER the canvas rather than below the
         long reply column (Req 9.1, 12.3); the RIGHT column holds the replies
         (beside the stage at >= 768px, below it under 768px per the CSS). Under
         768px everything is a single column, so the controls still sit directly
         under the canvas. Hidden until a view is loaded in direct-load mode. -->
    <div class="replay-main" id="replay-main" hidden>
      <!-- LEFT column: the stage and everything that belongs under the canvas. -->
      <div class="stage-col" id="stage-col">
        <section class="stage" aria-label="The house">
          <div class="inner" id="stage-inner">
            <canvas id="house" width="480" height="236"
                    role="img" aria-label="Cross-section of the house with Amber, Jade and the man"></canvas>
            <div class="overlay" id="overlay" aria-hidden="true"></div>
          </div>
        </section>

        <!-- Playback controls (wired in replay.js). Play, Pause, Step back,
             Step forward, "What it was told this turn" (Req 9.1, 9.7), the live
             "Turn N of M" counter (Req 9.1, 9.8-9.11) and a "Choose another
             episode" button that returns to the Episode_List (Req 8.2). Hidden
             until playback; sits directly under the canvas (task 19). -->
        <div class="controls" id="controls" hidden>
          <button type="button" class="btn primary" id="btn-play">Play</button>
          <button type="button" class="btn" id="btn-pause">Pause</button>
          <button type="button" class="btn" id="btn-stepback">Step back</button>
          <button type="button" class="btn" id="btn-stepforward">Step forward</button>
          <button type="button" class="btn" id="btn-told">What it was told this turn</button>
          <button type="button" class="btn" id="btn-choose">Choose another episode</button>
          <!-- Live "Turn N of M" position counter (Req 9.1). replay.js updates
               its text in renderTurn; M excludes turn 0 (the calm setup). -->
          <span class="turn-indicator" id="turn-indicator" aria-live="polite"></span>
          <!-- Heat wave only: names the disaster; hidden for an earthquake. -->
          <span class="disaster-tag" id="disaster-label" hidden></span>
        </div>

        <!-- "What it was told this turn" panel (Req 9.7). Sits under the
             controls in the stage column. -->
        <div class="message" id="told-host" hidden></div>
      </div>

      <!-- RIGHT column: the two robots' replies (filled and highlighted in
           replay.js). -->
      <div class="replies" id="replies">
        <div class="reply amber" id="reply-amber">
          <h3>Amber</h3>
          <!-- How many memories this robot started the episode with (robot-memory
               Requirement 7.6): the length of its stored Memory_Snapshot, read
               from the loaded episode view. Filled by replay.js; hidden until a
               view is loaded. Names only Amber (Req 7.7). -->
          <div class="started-with" id="started-amber" hidden></div>
          <div class="text" id="reply-amber-text"></div>
        </div>
        <div class="reply jade" id="reply-jade">
          <h3>Jade</h3>
          <div class="started-with" id="started-jade" hidden></div>
          <div class="text" id="reply-jade-text"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Scripts, in dependency order; replay.js last. All relative (Req 12.9,
       12.10). position-map.js -> house-renderer.js -> act-out.js -> voices.js
       -> api.js -> replay.js. -->
  <script src="js/position-map.js"></script>
  <script src="js/house-renderer.js"></script>
  <script src="js/speech-sequencer.js"></script>
  <script src="js/act-out.js"></script>
  <script src="js/voices.js"></script>
  <script src="js/api.js"></script>
  <script src="js/replay.js"></script>
</body>
</html>
