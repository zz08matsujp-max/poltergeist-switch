# Implementation Plan: Screens

## Overview

Implement the two public screens of PoltergeistSwitch — the Observe screen (the site's top page, which creates one live earthquake episode and runs it turn by turn) and the Replay screen (which plays back a stored episode with no AI call) — plus the two small turn-loop API additions that feed them: a per-turn stored World_State (`world_json`) and an ended-episode list endpoint. Both screens share one House_Renderer (ported from `pilot/look.php`) and a pure Position_Map, both drawing the approved Halloween 480×236 cross-section with Amber (`robot_a`), Jade (`robot_b`), and the man. This spec changes neither the engine nor the turn-loop's turn flow; it extends the stored data and adds read-only views.

The build is ordered to honour the maintainer's required sequence: the PHP API additions come first (so the screens have real data to consume), then the pure Position_Map and the shared renderer, then the Act_Out driver and voices, then the two pages and a final accessibility/caching pass. Each task is incremental and ends with passing tests; no task depends on code that does not yet exist. No real provider API is ever called — the API additions are read-only or replay-only and never call AI, and the Replay screen makes no AI call at any time.

Stack: PHP 8.2 only (no language features added after 8.2), plain classes in `src/TurnLoop/`, thin entry points in `api/`, a dated migration in `sql/`, plain HTML/CSS/JavaScript with Canvas and no build step in `public/` (DotGothic16 font), PHPUnit 11 with Eris ^1.3 for the required property tests, tests in `tests/TurnLoop/`.

**Per the steering:** when a task's tests pass, the maintainer stages only that task's files and commits with the message `task N: <task title>` (never pushes, amends, rebases, or resets). `php -l` is run on every changed PHP file, and the full existing PHPUnit suite is run before each commit. The JavaScript House_Renderer, Position_Map, Act_Out, voices, and the two screen controllers are verified with a headless browser **after deployment** (there is no Node/build step on the server), so that verification is a post-deployment note rather than a task here; the design's Position_Map positions table and Testing Strategy stand in for a JS test runner.

---

## Tasks

- [x] 1. SQL migration adding the per-turn stored world
  - [x] 1.1 Create the dated migration `sql/2026-11-08_add_world_json.sql`
    - `ALTER TABLE turns ADD COLUMN world_json MEDIUMTEXT NULL AFTER resident_line;` (utf8mb4; nullable so existing rows are backfilled once on read), with a comment noting the screens spec adds a per-turn recorded World_State
    - No secret, credential, or provider name anywhere in the file
    - _Requirements: 10.9_

- [x] 2. WorldStateSerializer (plain PHP, no I/O)
  - [x] 2.1 Implement `WorldStateSerializer` in `src/TurnLoop/WorldStateSerializer.php`
    - Pure mapper between the engine `WorldModel` and the World_State array: `toArray(WorldModel $w): array` reading only public engine state — `timeOfDay`, `power->value`, `shaking`; each `RobotState`'s `room`, `spot`, `heldObjectId` (as `held`) keyed `robot_a`/`robot_b`; the man's `room`, `spot`, `activity`, `bodyTemp`, `water`, `injury`, `fear`, `weakened`, and `covered` (from `coverFlag`); each `Room`'s `lightLevel->value` under `rooms[key].light`; `objects.broken`/`objects.fallen` as the object ids with those flags true in the fixed room order; and `stove.on` from `objects['gas_stove']->on`
    - `toJson(WorldModel $w): string` = `json_encode(toArray($w))` with `JSON_UNESCAPED_UNICODE` so the stored string round-trips verbatim; read-back parses to the same array
    - Run `php -l` on the new file; PHP 8.2 features only
    - _Requirements: 10.3, 10.6_
  - [x] 2.2 Write the required round-trip property test in `tests/TurnLoop/Properties/WorldStateRoundTripTest.php`
    - **Property 1: World_State round-trip**
    - **Validates: Requirements 10.6, 10.3**
    - Generate random valid `WorldModel`s (random robot rooms/spots/held, man room/spot/activity/state, power, shaking, time of day, per-room light, broken/fallen sets, stove), serialise and read back, and assert every Requirement-10.3 field equals the value written with no field added, dropped, or altered; use `Eris\TestTrait` with `->limitTo(100)`, tagged `Feature: screens, Property 1: World_State round-trip`
    - _Requirements: 10.6, 10.3_

- [ ] 3. Store, return, and backfill world_json
  - [x] 3.1 Extend the repository to read and write `world_json`
    - In `PdoEpisodeRepository` (and the in-memory test double) persist `world_json` on the `turns` row when a turn is stored and when turn 0 is created, and read it back per turn when loading an episode view; leave all existing turn-loop behaviour unchanged
    - Run `php -l` on changed PHP files
    - _Requirements: 10.1, 10.2_
  - [~] 3.2 Extend `TurnService` to store the world and include it in the episode view
    - On apply, store the serialiser's output of the post-apply `WorldModel` as that turn's `world_json` (Req 10.1); on episode creation store the initial (calm, pre-earthquake) World_State as turn 0's world (Req 10.2)
    - Add a `world` field to each `TurnView` in the `EpisodeView` so `GET /api/episodes/{id}` returns the stored World_State per turn (Req 10.4); keep `episodes_get.php` thin; a GET for an id that does not exist leaves stored data unchanged and returns the existing not-found error (Req 10.5)
    - _Requirements: 10.1, 10.2, 10.4, 10.5_
  - [~] 3.3 Implement the one-time backfill for pre-existing episodes
    - When a read finds an episode with at least one turn whose `world_json` is absent or null, replay the engine for that episode exactly once via the existing `ReplayEngineFactory` (no AI call), serialise the world at each turn, and fill only the missing values — turns already holding a `world_json` are left byte-for-byte unchanged; commit the fills only after a complete replay
    - If the backfill replay fails to complete, leave existing stored values unchanged, persist no partial World_State, and return an error indicating the World_State backfill failed
    - Run `php -l` on changed PHP files
    - _Requirements: 10.7, 10.8_
  - [~] 3.4 Write the required backfill property tests in `tests/TurnLoop/Properties/`
    - **Property 2: Backfill fills only missing turns, exactly once**
    - **Validates: Requirements 10.7**
    - Generate ended episodes with a random subset of turns missing `world_json`, read once, and assert the missing turns are filled, the present ones are unchanged, and the replay ran exactly once (spy on `ReplayEngineFactory`)
    - **Property 3: Backfill failure persists nothing**
    - **Validates: Requirements 10.8**
    - Inject a replay that fails at a random turn and assert no stored value changed, nothing partial was persisted, and a backfill-failed error is returned; both tests use `Eris\TestTrait` `->limitTo(100)`, tagged `Feature: screens, Property N: …`
    - _Requirements: 10.7, 10.8_
  - [ ]* 3.5 Write unit/example tests for the write and read paths in `tests/TurnLoop/`
    - `world_json` is written on apply and for turn 0 (Req 10.1, 10.2); `GET /api/episodes/{id}` includes it per turn (Req 10.4); a GET on a missing id returns not-found and changes nothing (Req 10.5)
    - _Requirements: 10.1, 10.2, 10.4, 10.5_

- [ ] 4. Ended-episode list endpoint
  - [~] 4.1 Implement `EpisodeListService` in `src/TurnLoop/EpisodeListService.php` (plain PHP)
    - Return only episodes whose `ended` is true, ordered by created date descending then id descending (Req 11.1, 11.2); for each item return `{ id, createdDate, turns, startingSituation }` — id, created date, number of turns as a non-negative integer, and the starting-situation one-liner (Req 11.3)
    - Validate `limit`: an integer 1–100 inclusive caps the count; absent → default 20; not an integer, `< 1`, or `> 100` → reject with an out-of-range error and return no list (Req 11.4, 11.5, 11.6)
    - Select only the four fields; include no device id, API key, database credential, or AI provider name (Req 11.7); no ended episodes → an empty list (Req 11.8)
    - Build the starting-situation one-liner from turn 0's `world_json` (time of day plus each robot's and the man's starting room, naming only Amber and Jade, using room display names); if turn 0's world is missing, produce it via the same backfill (task 3.3) first (Req 8.5)
    - Add the repository query `WHERE ended = 1 ORDER BY created_at DESC, id DESC LIMIT :limit` to `PdoEpisodeRepository` (and the in-memory double); run `php -l` on changed PHP files
    - _Requirements: 11.1, 11.2, 11.3, 11.4, 11.5, 11.6, 11.7, 11.8, 8.5_
  - [~] 4.2 Implement the thin handler `api/episodes_list.php` and wire the route
    - Parse `ended` and `limit` from the query, call `EpisodeListService`, and write the JSON list; all validation lives in `src/`, matching the existing `episodes_get.php` pattern; wire `GET /api/episodes?ended=1&limit=20` through `router.php`
    - Send `Cache-Control: no-store` on the response (Req 11.9); no rules logic in `api/`; run `php -l` on changed PHP files
    - _Requirements: 11.9, 11.4, 11.5, 11.6_
  - [~] 4.3 Write the required list property tests in `tests/TurnLoop/Properties/`
    - **Property 4: List returns only ended episodes, newest first**
    - **Validates: Requirements 11.1, 11.2**
    - **Property 5: Limit bounds the result count**
    - **Validates: Requirements 11.4, 11.5**
    - **Property 6: Out-of-range limit is rejected with no list**
    - **Validates: Requirements 11.6**
    - **Property 7: List leaks no device id, key, credential, or provider name**
    - **Validates: Requirements 11.7**
    - Generate random mixes of ended/not-ended episodes with random created dates, ids, and device ids; assert the result is exactly the ended ones in created-desc then id-desc order, the size is at most the limit (or 20 when absent), out-of-range limits are rejected with no list, and no device id/key/credential/provider name appears anywhere in the response; each uses `Eris\TestTrait` `->limitTo(100)`, tagged `Feature: screens, Property N: …`
    - _Requirements: 11.1, 11.2, 11.4, 11.5, 11.6, 11.7_
  - [ ]* 4.4 Write unit/example tests for the list handler in `tests/TurnLoop/`
    - The endpoint sends `Cache-Control: no-store` (Req 11.9); an empty set returns an empty list (Req 11.8); the starting-situation one-liner names the time of day and each robot's and the man's starting room using Amber/Jade and no provider name (Req 11.3, 8.5)
    - _Requirements: 11.9, 11.8, 11.3, 8.5_

- [~] 5. Checkpoint - API additions
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 6. Position_Map pure function
  - [~] 6.1 Implement `positionMap` in `public/js/position-map.js`
    - Pure function `positionMap(roomKey, spot, manActivity, manPos)` returning `{ ok: true, x, y }` with `0 ≤ x ≤ 479`, `0 ≤ y ≤ 235` or `{ ok: false, reason: 'unrecognised' }` (Req 2.1)
    - Return a position for each of the six Room_Keys (Req 2.2) and each Man_Activity `sitting_at_table`/`asleep_in_futon`/`bathing`/`cooking`/`standing` (Req 2.5); fixed positions for the spots `window` (per the five rooms that have a window fixture), `gas_stove`, `under_table`, `beside_futon`, and the man's start spots `low_table`/`futon`/`bathtub` (Req 2.3); for `near_resident` return a position next to `manPos` (offset by a small fixed dx, clamped into the area), depending on `manPos` not fixed (Req 2.4)
    - For a Room_Key with no spot return that room's default standing place (Req 2.7); keep the returned x inside that room's drawn `x0..x1` band, and for a spot defined for that room keep both x and y inside the room's bounds (Req 2.8, 2.10); determinism: same inputs → same `(x, y)` (Req 2.6)
    - An unknown spot with a known Room_Key is NOT an error: resolve to that room's default standing place and return `{ ok: true, … }` (Req 1.7); only an unrecognised Room_Key or Man_Activity returns `{ ok: false, reason: 'unrecognised' }`, never an out-of-area position (Req 2.9)
    - Encode the exact `(room, spot, activity, manPos) → (x, y)` values from the design's Position_Map tables
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.7, 2.8, 2.9, 2.10, 1.7_
  - [ ]* 6.2 Encode the documented positions table as an in-page verification check
    - Since there is no JS test runner on the server, add a tiny self-contained in-page assertion block (or a `data-expected` table read by the post-deployment headless pass) that checks the documented `(room, spot, activity, manPos) → (x, y)` outputs: a position for all six rooms (2.2), fixed spots incl. per-room windows and the man's start spots (2.3), a position per activity (2.5), `near_resident` next to the man (2.4), default standing with no spot (2.7), x within band and (x, y) within bounds (2.8, 2.10), determinism (2.6), unknown spot with known room → room default (1.7), and unrecognised room/activity → `{ ok: false }` never out of area (2.9)
    - This lightweight check stands in for a runner (design Testing Strategy) and is verified by the headless browser after deployment
    - _Requirements: 2.2, 2.3, 2.4, 2.5, 2.6, 2.7, 2.8, 2.9, 2.10, 1.7_

- [ ] 7. House_Renderer
  - [~] 7.1 Implement `house-renderer.js` ported from `pilot/look.php`
    - Own a `<canvas width="480" height="236">` drawn with `imageSmoothingEnabled = false` and displayed scaled with CSS `image-rendering: pixelated` (Req 1.1); draw the six rooms in the fixed left-to-right bands mapping the engine's room keys onto the ported cross-section, with the ported furniture, decorations, and outdoor scenery (jack-o'-lantern lantern, persimmon tree, bats, pampas grass, will-o'-the-wisp) (Req 1.2); draw a window in the five rooms that have a window fixture and none in the storeroom
    - Draw Amber (amber, round head, antenna), Jade (jade, square head, scanning eye), and the man with a cane (Req 1.5); place each character via the Position_Map for its Room_Key and Spot, falling back to the room's default standing place when the map returns no position (Req 1.6, 1.7); offset two characters horizontally when they resolve to the same position (Req 1.8)
    - Choose the man's pose from his Spot first (`under_table` → crouch/`duck`, `beside_futon` → beside the futon) then fall back to his Man_Activity pose (sitting at the low table, asleep in the futon, in the bathtub, cooking at the stove, standing with the cane) (Req 1.9)
    - Draw the sky by time of day for morning/afternoon/night, and any other value as night (Req 1.3, 1.4); darken rooms when power is out and the time is morning or night, and only dim them in the afternoon (Req 1.10, 1.11); show the house shaking when shaking is reported, and draw broken/fallen objects (Req 1.12)
    - Position every name tag and speech bubble so none overlaps another name tag or bubble (Req 1.13); never draw a robot thought in a bubble and never show a thought anywhere on the canvas or overlay (Req 1.14, 3.6)
    - Expose the controller interface: `mount`, `setWorld` (snap every character to its recorded place, consuming the World_State JSON shape), `moveCharacter`, `setPose`, `setHeld`, `setThinking`, `showBubble` (spoken line only), `start`/`stop`; translate `robot_a`/`robot_b` to `amber`/`jade` at the renderer boundary
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 1.8, 1.9, 1.10, 1.11, 1.12, 1.13, 1.14, 3.6_

- [ ] 8. Act_Out and voices
  - [~] 8.1 Implement `act-out.js`
    - Act out one turn from its `RobotFourLayers.reading` and the turn's recorded World_State for both screens using the same House_Renderer (Req 3.1, 9.12): map the single physical `Operation` to an animation — `move` walk to the target, `take` walk and show held, `hand_over` walk toward the other robot and drop, `use` turn the stove off or switch a light on at the target, `support` help the man move, `cover` shield the man or take cover, `wait` idle, `speak` no motion; the animation target comes from the Position_Map, so an unknown spot with a known room animates toward the room default rather than erroring (Req 3.1, 1.7)
    - For an action the rules judged impossible, play a short attempt lasting between 0.5 and 2.0 seconds that stops before completion (Req 3.2); for an `unknown` reading (including a no-reply robot) keep that robot idle (Req 3.3)
    - Show each spoken line in that character's bubble in the stored order (the man's line in the man's bubble) and keep a bubble visible for at least 2.0 seconds or until the acting-out ends, whichever is longer; never show a thought in a bubble (Req 3.4, 3.5, 3.7, 3.6)
    - End every turn by calling `houseRenderer.setWorld(turnWorldState)` so every character ends at the recorded position, even for impossible or unknown actions (Req 3.8, 3.9)
    - Under reduced motion, stop the shaking and walking animation and set each character's position directly within 100 ms with no intermediate frames (Req 12.6)
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, 3.8, 3.9, 9.12, 1.7, 12.6_
  - [~] 8.2 Implement `voices.js`
    - Use the browser's Web Speech API with a distinct voice, pitch, and rate per character (Amber, Jade, the man) (Req 3.11); read each spoken line aloud in the stored order when Voices are on and read nothing while off (Req 3.4, 3.10); for a Japanese line use a Japanese voice when available, else fall back to `lang = 'ja-JP'` (Req 3.12); never speak a thought (Req 3.13); unlock playback on the first user gesture
    - _Requirements: 3.11, 3.12, 3.13, 3.4, 3.10_

- [ ] 9. Observe screen
  - [~] 9.1 Implement the relative-URL API client `public/js/api.js` and shared `public/css/screens.css`
    - `api.js` builds every request from a relative base (no leading `/`, no absolute origin) so `api/index.php?route=episodes/{id}` and `api/index.php?route=episodes&ended=1&limit=20` resolve both at the web root and under `/public/` (Req 12.9, 12.10)
    - `screens.css` ports the approved look: DotGothic16 with a sans-serif fallback and text visible during font load, `image-rendering: pixelated`, a mobile-first layout from 320 px with 16 px gutters and no horizontal scroll, a visible focus indicator (≥ 3:1 contrast), and a reduced-motion rule (Req 12.1, 12.2, 12.3, 12.4, 12.6)
    - _Requirements: 12.9, 12.10, 12.1, 12.2, 12.3_
  - [~] 9.2 Implement `public/index.php` and `public/js/observe.js` — layout and setup
    - `index.php` is the top page, sends `Cache-Control: no-store` first, and references scripts/styles with relative URLs (Req 4.1, 12.7, 12.9, 12.10); show the title, tagline, and the one line that Amber runs on Anthropic's `claude-haiku-5-5` and Jade on OpenAI's `gpt-6-luna` (Req 4.2)
    - Show the House_Renderer and exactly three Breaker_Switches labelled Earthquake, Heavy rain, Heat wave with lever-up = ON and lever-down = OFF; render Heavy rain and Heat wave disabled and labelled "coming later" (Req 4.3, 4.4); on first load show no episode in progress and the Earthquake lever OFF (Req 4.5)
    - Show resident meters for temperature, water, injury, and fear, and a "What happened" log grouped and ordered by turn from first to most recent; show Pause, Step, Resume, and a Voices on/off control; omit any memory, reflection, or All reset control (Req 4.10, 4.12, 4.11)
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.10, 4.11, 4.12, 12.7, 12.9, 12.10_
  - [~] 9.3 Implement the Observe run loop in `observe.js`
    - Start pressed: `POST /api/episodes`; on success read turn 0's `world_json` and show the calm turn-0 house with no AI call and no turns running; Start while an episode is in progress creates no additional episode and leaves it unchanged (Req 4.6, 5.1, 4.9)
    - Earthquake lever raised with an episode present: `POST /api/episodes/{id}/disaster`, then run the turns; raised with no episode present: `POST /api/episodes` first, then the disaster flip, then run the turns (Req 4.7, 4.8, 5.2, 5.3)
    - Run loop while running and not paused: play the current expected turn by calling `ask`, then `apply`, then acting out the applied turn, advancing once the acting-out finishes; as soon as a turn is applied and the episode has not ended, send the next expected turn's `ask` immediately without waiting for the acting-out (Req 5.4, 5.5); show a "thinking" indicator on a robot whose reply is awaited (Req 5.6); treat the applied result as independent of the animation (Req 5.7)
    - Pause stops advancing after the current turn finishes acting out, leaving stored results unchanged; Resume continues from the current expected turn; Step (while paused) plays exactly one more turn then stops (Req 5.8, 5.9); on each apply update the meters and append the turn's log entry (Req 5.10); when apply reports ended, stop advancing and send no further ask/apply (Req 5.11)
    - _Requirements: 4.6, 4.7, 4.8, 4.9, 5.1, 5.2, 5.3, 5.4, 5.5, 5.6, 5.7, 5.8, 5.9, 5.10, 5.11_
  - [~] 9.4 Implement the End_Card and 409 slot/resume handling in `observe.js`
    - When the episode ends, show the End_Card with the total turns taken as a non-negative integer, a per-robot summary drawn from the outcome texts, the man's final state, and the full stored replies for Amber and Jade; a zero-turn end shows count 0 and a "no robot actions occurred" summary; name the robots only Amber and Jade with no AI company name in the robots' text; show "Watch again" and "Close" buttons; Close dismisses the card; "Watch again" opens the Replay screen for this episode's id, and if it cannot be opened keep the card and show an error (Req 6.1–6.7)
    - `POST /api/episodes` 409 because this device already runs an episode: show that message and a resume control; activating resume fetches `GET /api/episodes/{id}` and continues from the returned expected turn; if that GET fails or returns no episode, show a "could not be resumed" error and offer the Replay screen (Req 7.1, 7.2, 7.3)
    - `POST /api/episodes` 409 because 5 episodes run across all devices: show a "lab is busy" message and a control opening the Replay screen (Req 7.4); any turn call 409 carrying the current expected turn number: set the local turn number to that value and continue without discarding recorded turns (Req 7.5)
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6, 6.7, 7.1, 7.2, 7.3, 7.4, 7.5_

- [ ] 10. Replay screen
  - [~] 10.1 Implement `public/replay.php` and the Replay episode list in `public/js/replay.js`
    - `replay.php` is served at `public/replay.php`, sends `Cache-Control: no-store` first, and uses relative URLs (Req 8.1, 12.7, 12.9, 12.10); when loaded without a chosen episode, fetch the list through `GET /api/episodes?ended=1&limit=20` and show at most 20 episodes newest-created first (Req 8.2); a fetch that does not complete within 10 seconds or returns an error shows a "could not load the list" message and makes no AI call (Req 8.3); an empty list shows a "no replayable episodes yet" message (Req 8.4)
    - For each episode show its created date, number of turns, and a single-line starting situation naming the time of day and the starting room of each robot and the man (Req 8.5); make no AI call at any time (Req 8.6); opened with `?episode=<id>` load that episode directly through `GET /api/episodes/{id}` and begin playback (Req 8.7); for an id that does not exist or has not ended, show an error and fall back to the list, making no AI call (Req 8.8); link to the Replay screen from the top page (Req 8.9)
    - _Requirements: 8.1, 8.2, 8.3, 8.4, 8.5, 8.6, 8.7, 8.8, 8.9, 12.7, 12.9, 12.10_
  - [~] 10.2 Implement Replay playback and evidence highlighting in `replay.js`
    - Play a loaded episode turn by turn with Play, Pause, Step back, Step forward controls, acting out each turn with the same House_Renderer and Act_Out as Observe (Req 9.1, 9.12)
    - Show each robot's reply beside the House_Renderer at viewport width ≥ 768 px and below it under 768 px (Req 9.2, 9.3); while a turn's action is acted out and has an Evidence_Quote, highlight the action's quote in the reply with a solid underline; for the words passed on with an Evidence_Quote, highlight with a dotted underline; with no Evidence_Quote show no highlight (Req 9.4, 9.5, 9.6)
    - "What it was told this turn" opens the stored situation text the robot received for that turn (Req 9.7); Step back moves to the previous turn unless on the first turn where it stays; Step forward moves to the next turn unless on the last turn where it stays (Req 9.8, 9.9, 9.10, 9.11)
    - _Requirements: 9.1, 9.2, 9.3, 9.4, 9.5, 9.6, 9.7, 9.8, 9.9, 9.10, 9.11, 9.12_

- [ ] 11. Accessibility and caching pass across both pages
  - [~] 11.1 Apply the shared look, accessibility, and caching rules to Observe and Replay
    - Present the UI in English with DotGothic16, falling back to a sans-serif font with text visible during load (Req 12.1, 12.2); lay out mobile-first from 320 px with 16 px gutters, no horizontal scroll from 320 px up, and `image-rendering: pixelated` (Req 12.3)
    - Make every button reachable and operable by keyboard alone with a visible focus indicator of ≥ 3:1 contrast (Req 12.4); announce each new "What happened" log entry through an `aria-live="polite"` region (Req 12.5); send `Cache-Control: no-store` on both pages (Req 12.7); name the robots only Amber and Jade with no AI company in the robots' text (Req 12.8)
    - _Requirements: 12.1, 12.2, 12.3, 12.4, 12.5, 12.7, 12.8_

- [~] 12. Final checkpoint
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- **(a) API additions first, with required tests.** Tasks 1–4 add `world_json` and the ended-episode list to the already-built turn-loop; their property tests (2.2, 3.4, 4.3) and the list/world unit tests are required, not optional — only sub-tasks marked `*` may be skipped for a faster MVP.
- **(b) No AI is ever called by this spec.** The world backfill replays the engine only (never AI), and the Replay screen makes no AI call at any time; the only AI calls remain the turn-loop's own `ask`/`apply`, unchanged.
- **(c) The JS modules are verified after deployment.** The House_Renderer, Position_Map, Act_Out, voices, and the two screen controllers are checked with a headless browser after deployment (no Node/build step on the server); the Position_Map positions table and the design's Testing Strategy stand in for a JS test runner, so task 6.2 is a lightweight in-page check rather than a server test.
- **(d) Every page and API response is uncacheable.** `index.php`, `replay.php`, and the list endpoint each send `Cache-Control: no-store`.
- **(e) No secrets or provider names leak.** No device id, API key, database credential, secrets path, or AI provider name appears in the list response, the World_State, the renderer, or any page output; the robots are named only Amber and Jade.
- **(f) Relative URLs only.** Every page references its scripts, styles, and API calls with relative URLs (no leading `/`, no absolute origin) so the same files work at the deployed web root and under `/public/` locally.
- **(g) PHP 8.2 and `php -l`.** All PHP uses only 8.2 language features, and `php -l` is run on every changed PHP file before each commit.

---

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1"] },
    { "id": 1, "tasks": ["2.1"] },
    { "id": 2, "tasks": ["2.2", "3.1"] },
    { "id": 3, "tasks": ["3.2"] },
    { "id": 4, "tasks": ["3.3"] },
    { "id": 5, "tasks": ["3.4", "3.5", "4.1"] },
    { "id": 6, "tasks": ["4.2"] },
    { "id": 7, "tasks": ["4.3", "4.4"] },
    { "id": 8, "tasks": ["6.1"] },
    { "id": 9, "tasks": ["6.2", "7.1"] },
    { "id": 10, "tasks": ["8.1", "8.2"] },
    { "id": 11, "tasks": ["9.1"] },
    { "id": 12, "tasks": ["9.2"] },
    { "id": 13, "tasks": ["9.3"] },
    { "id": 14, "tasks": ["9.4", "10.1"] },
    { "id": 15, "tasks": ["10.2"] },
    { "id": 16, "tasks": ["11.1"] }
  ]
}
```
