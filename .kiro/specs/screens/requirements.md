# Requirements Document

## Introduction

The screens spec is the fourth spec of PoltergeistSwitch (product brief §15 items 4–5). It delivers the two public screens that judges and the public use: the Observe screen, which creates one live earthquake episode and runs it turn by turn, and the Replay screen, which plays back a stored episode without calling any AI. Both screens share one house renderer that ports the approved Halloween look of `pilot/look.php` (a 480×236 logical-pixel cross-section of the house with three pixel-art characters drawn in code).

The screens are driven by the stored Four_Layers of each turn (the robot's text, the recorder's reading, the rules' judgement, and the outcome) and by the world recorded after each turn. To supply the recorded world and the list of ended episodes, this spec adds two small, tested pieces to the turn-loop API: a per-turn stored world (`world_json`) and a list endpoint for ended episodes. It does not change the engine or the turn loop's turn flow.

On screen the two robots are named **Amber** (amber, round head, antenna; driven by Anthropic's `claude-haiku-5-5`) and **Jade** (jade, square head, scanning eye; driven by OpenAI's `gpt-6-luna`). The robots never see these names, and no screen names an AI company in the robots' own text. Both companies get the same structure, timing and settings.

Tech follows the steering rules: PHP 8.2, plain HTML/CSS/JavaScript with Canvas, no Node/Composer/build step on the server, DotGothic16 font, and `Cache-Control: no-store` on every page and API response. The renderer is plain JavaScript in `public/js/`; the pages are `public/index.php` (Observe, the site top page) and `public/replay.php` (Replay). API additions are PHP in `src/` and `api/`, with the schema change written as a dated migration file in `sql/`.

On the server the web root is the site folder itself, so the `public/` directory is deployed as the web root: `public/index.php` becomes `https://kiro.pyol.net/` and `public/replay.php` becomes `/replay.php`, with `api/` and `src/` beside the web root. So that the same files work both at the deployed web root and under `/public/` on a local dev server, every page uses relative URLs for its scripts, styles and API calls (for example `api/index.php?route=...`) and no absolute path or absolute origin for its own resources.

### Out of Scope

The following are explicitly out of scope for this spec:

- Memory, reflection, All reset, and generations (their places are left out of the Observe screen).
- Heavy rain and heat wave behaviour (their switches are shown but disabled, labelled "coming later").
- The Compare and Admin screens.
- Watching another device's running episode (the 5-running case offers the Replay screen instead).
- The research batch and admin review.
- The existing debug page, which stays as it is.

## Glossary

- **Screens**: The system this spec defines — the Observe screen, the Replay screen, the shared House_Renderer, and the two turn-loop API additions that feed them.
- **House_Renderer**: The shared plain-JavaScript module in `public/js/` that draws the Halloween cross-section of the house, the three characters, decorations, disaster effects, speech bubbles and name tags onto a 480×236 logical-pixel canvas scaled with `image-rendering: pixelated`, ported from `pilot/look.php`.
- **Observe_Screen**: The site's top page, served at `public/index.php` as `https://kiro.pyol.net/`, which creates one live earthquake episode and runs it turn by turn through the turn-loop API.
- **Replay_Screen**: The page served at `public/replay.php` that lists ended episodes and plays a chosen stored episode back turn by turn with no AI calls.
- **Amber**: The on-screen name of Robot_A (amber colour, round head, antenna), driven by Anthropic's `claude-haiku-5-5`.
- **Jade**: The on-screen name of Robot_B (jade colour, square head, scanning eye), driven by OpenAI's `gpt-6-luna`.
- **Four_Layers**: The four stored records for one robot on one applied turn (brief §9) — the robot's text, the recorder's reading, the rules' judgement, and the outcome — as produced by the turn-loop spec.
- **Reading**: The recorder's reading of one reply — the first physical action (operation, target id, spot) with its action evidence quote, the spoken lines with their quotes, and the reported-speech items — as defined by `house-rules-engine` Requirement 12.
- **Evidence_Quote**: The exact words of a reply that a reading was read from — one quote for the action and one per spoken line or reported-speech item — verified by the engine to be an exact substring of the stored reply.
- **World_State**: The recorded state of the house after an applied turn (and the initial state for turn 0): each robot's room, spot and held object; the man's room, spot, activity and state; power, shaking, time of day, per-room light, broken and fallen objects, and the stove.
- **Act_Out**: The animated playback of one turn from its Four_Layers and recorded World_State: the reading drives motion, spoken lines appear in bubbles and are read aloud in order, and the picture ends in the turn's recorded World_State.
- **Position_Map**: The pure function that maps an engine room key, a spot, and (for the man) an activity to a canvas position for a character.
- **Room_Key**: One of the engine's six room keys — `living_room`, `kitchen`, `bathroom`, `bedroom`, `entrance`, `storeroom`.
- **Spot**: One of the engine's named spots — `window`, `gas_stove`, `under_table`, `beside_futon`, `near_resident`.
- **Man_Activity**: One of the resident's activities — `sitting_at_table`, `asleep_in_futon`, `bathing`, `cooking`, `standing`.
- **Breaker_Switch**: A disaster switch drawn as a breaker lever, where the lever up means ON, with ON/OFF marks (earthquake, heavy rain, heat wave).
- **End_Card**: The panel shown on the Observe_Screen when an episode ends: turns taken, what each robot did, the man's state, the full replies, and the "Watch again" and "Close" buttons.
- **Voices**: The browser's Web Speech API speech synthesis used to read spoken lines aloud, with a Voices on/off control.
- **Episode_List**: The list of ended episodes returned by the list endpoint and shown on the Replay_Screen, newest first, each with id, created date, turns and a one-line starting situation.
- **Turn_Loop_API**: The existing JSON API from the turn-loop spec (`POST /api/episodes`, `POST /api/episodes/{id}/disaster`, `.../turns/{n}/ask`, `.../turns/{n}/apply`, `GET /api/episodes/{id}`), which this spec extends with `world_json` per turn and the `GET /api/episodes?ended=1&limit=20` list endpoint.

## Requirements

### Requirement 1: Shared House Renderer

**User Story:** As a viewer, I want the house drawn as the approved Halloween cross-section with the three characters in their recorded places, so that both screens show the same world consistently.

#### Acceptance Criteria

1. THE House_Renderer SHALL draw the house as a cross-section on a canvas of 480 logical pixels wide by 236 logical pixels high, displayed scaled to its container with `image-rendering: pixelated`.
2. THE House_Renderer SHALL draw the six rooms in the fixed left-to-right layout ported from `pilot/look.php`, with the furniture, decorations and outdoor scenery (jack-o'-lantern lantern, persimmon tree, bats, pampas grass, will-o'-the-wisp) of the approved look.
3. WHEN a World_State gives a time of day of morning, afternoon, or night, THE House_Renderer SHALL draw the sky for that time of day.
4. IF a World_State gives a time of day that is not one of morning, afternoon, or night, THEN THE House_Renderer SHALL draw the sky for night.
5. THE House_Renderer SHALL draw Amber as an amber robot with a round head and an antenna, Jade as a jade robot with a square head and a scanning eye, and the man with a cane.
6. WHEN a World_State places a character in a Room_Key with a Spot, THE House_Renderer SHALL draw that character at the canvas position the Position_Map returns for that Room_Key and Spot.
7. IF a World_State places a character in a Room_Key and Spot for which the Position_Map returns no position, THEN THE House_Renderer SHALL draw that character at that Room_Key's default standing place.
8. WHEN two characters resolve to the same position, THE House_Renderer SHALL offset them horizontally so that they do not overlap.
9. WHEN a World_State gives the man a Man_Activity, THE House_Renderer SHALL draw the man in the pose for that activity: sitting at the low table, asleep in his futon, in the bathtub, cooking at the stove, standing with his cane, or crouching under the low table.
10. WHILE a World_State reports the power is out and the time of day is morning or night, THE House_Renderer SHALL darken the rooms.
11. WHILE a World_State reports the power is out and the time of day is afternoon, THE House_Renderer SHALL dim the rooms rather than darken them.
12. WHEN a World_State reports shaking, THE House_Renderer SHALL show the house shaking, and WHEN a World_State lists broken or fallen objects THE House_Renderer SHALL draw those objects as broken or fallen.
13. THE House_Renderer SHALL position every name tag and every speech bubble so that no name tag or speech bubble overlaps another name tag or speech bubble.
14. THE House_Renderer SHALL draw a robot thought in no speech bubble and SHALL display no robot thought anywhere on the canvas or overlay.

### Requirement 2: Position Map (pure function)

**User Story:** As a developer, I want the mapping from room, spot and activity to a canvas position kept in one small pure function, so that the placement rules can be tested without a browser.

#### Acceptance Criteria

1. THE Position_Map SHALL be a pure function that, given a Room_Key, a Spot or no spot, (for the man) a Man_Activity, and the man's current position, returns a canvas position as an (x, y) pair where x is 0 to 479 and y is 0 to 235 inclusive within the 480×236 logical-pixel area.
2. THE Position_Map SHALL return a position for each of the six Room_Keys `living_room`, `kitchen`, `bathroom`, `bedroom`, `entrance`, and `storeroom`.
3. THE Position_Map SHALL return a fixed position for each of the Spots `window`, `gas_stove`, `under_table`, and `beside_futon`.
4. WHEN the Spot `near_resident` is given, THE Position_Map SHALL return a position next to the man's current position, taking the man's current position into account rather than returning a fixed point.
5. THE Position_Map SHALL return a position for each Man_Activity `sitting_at_table`, `asleep_in_futon`, `bathing`, `cooking`, and `standing`.
6. WHEN the same Room_Key, Spot, Man_Activity and man's current position are given, THE Position_Map SHALL return a position whose x and y are equal to those returned by every prior call with the same inputs.
7. WHEN a Room_Key is given with no Spot, THE Position_Map SHALL return the position for that room's default standing place.
8. WHEN a Room_Key is given, THE Position_Map SHALL return an x coordinate that is greater than or equal to the left edge and less than or equal to the right edge of that room's drawn bounds on the canvas.
9. IF a Room_Key, Spot, or Man_Activity that is not one of the listed values is given, THEN THE Position_Map SHALL return a result indicating the input is unrecognised, without returning an out-of-area position.
10. WHEN a Room_Key is given together with a Spot that is defined for that room, THE Position_Map SHALL return an (x, y) pair whose x and y both fall within that room's drawn bounds on the canvas.

### Requirement 3: Acting Out a Turn

**User Story:** As a viewer, I want each turn acted out from its stored layers, so that I see what each robot attempted and the picture always ends in the recorded world.

#### Acceptance Criteria

1. WHEN a turn is acted out, THE Screens SHALL drive each robot's motion from that turn's Reading: walking to a room or spot, taking or handing over an object, turning the stove off, switching a light on, shielding the man, taking cover, helping the man move, or waiting.
2. WHEN a turn's Reading has an action the rules judged impossible, THE Screens SHALL show that robot an attempt lasting between 0.5 and 2.0 seconds that stops before the action completes.
3. WHEN a turn's Reading for a robot is `unknown`, THE Screens SHALL keep that robot idle for that turn.
4. WHEN a turn is acted out, THE Screens SHALL show each spoken line in that character's speech bubble in the stored order and SHALL read each spoken line aloud in the stored order when Voices are on.
5. WHEN a spoken line is shown in a speech bubble, THE Screens SHALL keep that bubble visible for at least 2.0 seconds or until the turn's acting-out ends, whichever is longer.
6. THE Screens SHALL show no robot thought in any speech bubble.
7. WHEN the man has a spoken line for a turn, THE Screens SHALL show that line in the man's speech bubble.
8. WHEN a turn has finished being acted out, THE Screens SHALL leave every character at the position given by that turn's recorded World_State, so that the picture ends in the recorded state.
9. WHEN a turn's Reading contains an impossible or `unknown` action, THE Screens SHALL still leave every character at the position given by that turn's recorded World_State when the turn finishes being acted out.
10. WHILE Voices are off, THE Screens SHALL act out a turn and show its bubbles without reading any line aloud.
11. THE Screens SHALL use a distinct voice, pitch, and rate for each character Amber, Jade, and the man.
12. WHEN a spoken line is in Japanese and a Japanese voice is available, THE Screens SHALL read that line with a Japanese voice.
13. THE Screens SHALL read no robot thought aloud.

### Requirement 4: Observe Screen Layout and Setup

**User Story:** As a judge or visitor, I want the top page to introduce the lab and let me start one earthquake, so that I can watch the robots respond.

#### Acceptance Criteria

1. THE Observe_Screen SHALL be the site's top page, served at `public/index.php`.
2. THE Observe_Screen SHALL show the title, the tagline, and one line stating that Amber runs on Anthropic's `claude-haiku-5-5` and Jade runs on OpenAI's `gpt-6-luna`.
3. THE Observe_Screen SHALL show the House_Renderer and exactly three Breaker_Switches labelled Earthquake, Heavy rain, and Heat wave, each displayed with the lever-up position indicating the ON state and the lever-down position indicating the OFF state.
4. THE Observe_Screen SHALL render the Heavy rain and Heat wave Breaker_Switches in a disabled state that rejects user activation and SHALL label each of them "coming later".
5. WHEN the Observe_Screen first loads, THE Observe_Screen SHALL present no episode in progress and the Earthquake Breaker_Switch lever in the OFF (lever-down) position.
6. WHEN the viewer presses the Start button, THE Observe_Screen SHALL create one episode and SHALL show the calm house of turn 0 from the initial World_State with no AI call and no turns running yet.
7. WHEN the viewer raises the Earthquake Breaker_Switch lever to the ON position while an episode exists, THE Observe_Screen SHALL flip the earthquake and SHALL begin running the turns.
8. WHEN the viewer raises the Earthquake Breaker_Switch lever to the ON position while no episode exists, THE Observe_Screen SHALL create the episode, then flip the earthquake, and then begin running the turns.
9. IF the viewer presses the Start button while an episode is already in progress, THEN THE Observe_Screen SHALL not create an additional episode and SHALL leave the in-progress episode unchanged.
10. THE Observe_Screen SHALL show resident meters for temperature, water, injury, and fear, and a "What happened" log in which entries are grouped and ordered by turn from the first turn to the most recent turn.
11. THE Observe_Screen SHALL omit any control or panel for memory, reflection, or All reset.
12. THE Observe_Screen SHALL show Pause, Step, Resume, and a Voices on/off control.

### Requirement 5: Observe Screen Running a Live Episode

**User Story:** As a viewer, I want the earthquake episode to run automatically with the waiting time hidden, so that I watch a continuous sequence of turns.

#### Acceptance Criteria

1. WHEN the viewer presses Start, THE Observe_Screen SHALL create an episode through `POST /api/episodes` and SHALL show the calm house of turn 0 from the initial World_State without making any AI call.
2. WHEN the viewer raises the Earthquake lever while an episode exists, THE Observe_Screen SHALL flip the earthquake through `POST /api/episodes/{id}/disaster` and SHALL begin running the turns.
3. WHEN the viewer raises the Earthquake lever while no episode exists, THE Observe_Screen SHALL first create the episode through `POST /api/episodes`, then flip the earthquake and begin running the turns.
4. WHILE an episode is running and not paused, THE Observe_Screen SHALL play the current expected turn by calling `ask`, then `apply`, then acting out the applied turn, and SHALL advance to the next expected turn once the current turn's acting-out animation has completed.
5. WHEN a turn is applied and the episode has not ended, THE Observe_Screen SHALL send the next expected turn's `ask` as soon as that turn is applied, without waiting for the acting-out to finish.
6. WHILE a robot's reply for a turn is being awaited, THE Observe_Screen SHALL show a "thinking" indicator on that robot.
7. THE Observe_Screen SHALL treat the result of an applied turn as independent of the animation, so that the stored turn is never changed by how it is acted out.
8. WHEN the viewer presses Pause, THE Observe_Screen SHALL stop advancing to new turns after the current turn finishes acting out while leaving the current turn's stored result and any already-sent next `ask` result unchanged, and WHEN the viewer presses Resume THE Observe_Screen SHALL continue advancing from the current expected turn.
9. WHEN the viewer presses Step while paused, THE Observe_Screen SHALL play exactly one more turn and then stop advancing.
10. WHEN a turn is applied, THE Observe_Screen SHALL update the resident meters and append that turn's entry to the "What happened" log.
11. WHEN `apply` reports that the episode has ended, THE Observe_Screen SHALL stop advancing to new turns and SHALL NOT send any further `ask` or `apply` call for that episode.

### Requirement 6: Observe Screen End Card

**User Story:** As a viewer, I want a summary when the episode ends, so that I can read what happened and choose to watch again.

#### Acceptance Criteria

1. WHEN the episode has ended, THE Observe_Screen SHALL show the End_Card displaying the total number of turns taken as a non-negative integer, a per-robot summary of each robot's actions drawn from the outcome texts, the man's final state, and the full stored replies for both Amber and Jade.
2. IF the episode ended with zero completed turns, THEN THE End_Card SHALL show a turn count of 0 and a summary indicating that no robot actions occurred.
3. THE End_Card SHALL show a "Watch again" button and a "Close" button.
4. WHEN the viewer presses "Close", THE Observe_Screen SHALL dismiss the End_Card.
5. THE End_Card SHALL name the two robots as Amber and Jade and SHALL NOT include any AI company name in the robots' text.
6. WHEN the viewer presses "Watch again", THE Observe_Screen SHALL open the Replay_Screen for this episode's id.
7. IF the Replay_Screen cannot be opened for this episode's id, THEN THE Observe_Screen SHALL keep the End_Card visible and SHALL show an error indication that the replay could not be opened.

### Requirement 7: Observe Screen Slot and Resume Handling

**User Story:** As a viewer, I want clear handling when a device or the lab is already busy, so that I always have a next step.

#### Acceptance Criteria

1. IF `POST /api/episodes` is rejected with HTTP status 409 because this device already runs an episode, THEN THE Observe_Screen SHALL display a message identifying that a running episode exists on this device and SHALL present a resume control for that episode.
2. WHEN the viewer activates the resume control for the running episode, THE Observe_Screen SHALL fetch the episode state using `GET /api/episodes/{id}` and SHALL continue the turn loop from the expected turn number returned in that response.
3. IF `GET /api/episodes/{id}` fails or returns no episode when resuming, THEN THE Observe_Screen SHALL display an error message indicating the episode could not be resumed and SHALL offer the Replay_Screen.
4. IF `POST /api/episodes` is rejected with HTTP status 409 because 5 episodes are already running across all devices, THEN THE Observe_Screen SHALL display a message indicating the lab is busy and SHALL present a control that opens the Replay_Screen.
5. IF a turn call returns HTTP status 409 containing the episode's current expected turn number, THEN THE Observe_Screen SHALL set its local turn number to the expected turn number from the response and SHALL continue the turn loop from that turn without discarding previously recorded turns.

### Requirement 8: Replay Screen Episode List

**User Story:** As a judge or visitor, I want to pick a past episode from a list, so that I can replay it without spending any AI calls.

#### Acceptance Criteria

1. THE Replay_Screen SHALL be served at `public/replay.php`.
2. WHEN the Replay_Screen loads without a chosen episode, THE Replay_Screen SHALL fetch the Episode_List through `GET /api/episodes?ended=1&limit=20` and show at most 20 episodes ordered from the most recently created to the least recently created.
3. IF fetching the Episode_List does not complete within 10 seconds or returns an error, THEN THE Replay_Screen SHALL show a message that the list could not be loaded and SHALL make no AI call.
4. IF the fetched Episode_List is empty, THEN THE Replay_Screen SHALL show a message that there are no replayable episodes yet.
5. WHEN the Episode_List is shown, THE Replay_Screen SHALL show for each episode its created date, the number of turns, and a single-line starting situation naming the time of day and the starting room of each robot and the man.
6. THE Replay_Screen SHALL make no AI call at any time.
7. WHEN the Replay_Screen is opened with `?episode=<id>`, THE Replay_Screen SHALL load that episode directly through `GET /api/episodes/{id}` and begin playback of it.
8. IF the Replay_Screen is opened with `?episode=<id>` for an id that does not exist or has not ended, THEN THE Replay_Screen SHALL show an error message and SHALL fall back to showing the Episode_List, making no AI call.
9. THE Observe_Screen End_Card and the top page SHALL each link to the Replay_Screen.

### Requirement 9: Replay Screen Playback and Evidence

**User Story:** As a viewer, I want turn-by-turn playback with each reply shown and the evidence quote lit up, so that I can see exactly what each action and line was read from.

#### Acceptance Criteria

1. THE Replay_Screen SHALL play a loaded episode turn by turn with Play, Pause, Step back, and Step forward controls.
2. WHEN a turn is played AND the viewport width is at least 768 pixels, THE Replay_Screen SHALL show each robot's reply beside the House_Renderer.
3. WHILE a turn is played AND the viewport width is less than 768 pixels, THE Replay_Screen SHALL show each robot's reply below the House_Renderer.
4. WHILE a turn's action is being acted out AND that action has an associated Evidence_Quote, THE Replay_Screen SHALL highlight the action's Evidence_Quote in that robot's reply with a solid underline.
5. WHILE a turn's words passed on are being acted out AND those words have an associated Evidence_Quote, THE Replay_Screen SHALL highlight that Evidence_Quote in that robot's reply with a dotted underline.
6. IF a turn's action or words passed on have no associated Evidence_Quote, THEN THE Replay_Screen SHALL show no highlight for that action or those words.
7. WHEN the viewer opens "What it was told this turn" for a robot, THE Replay_Screen SHALL show the text that robot received for that turn.
8. WHEN the viewer presses Step back AND the current turn is not the first turn, THE Replay_Screen SHALL move to the previous turn.
9. IF the viewer presses Step back AND the current turn is the first turn, THEN THE Replay_Screen SHALL remain on the first turn.
10. WHEN the viewer presses Step forward AND the current turn is not the last turn, THE Replay_Screen SHALL move to the next turn.
11. IF the viewer presses Step forward AND the current turn is the last turn, THEN THE Replay_Screen SHALL remain on the last turn.
12. THE Replay_Screen SHALL act out each turn using the same House_Renderer and Act_Out behaviour as the Observe_Screen.

### Requirement 10: Stored World per Turn (API)

**User Story:** As the Screens, I want the world recorded after every applied turn and for turn 0, so that playback and live acting-out can place characters without re-running the engine.

#### Acceptance Criteria

1. WHEN a turn is applied, THE Turn_Loop_API SHALL store a World_State as a `world_json` value on the `turns` table for that turn.
2. WHEN an episode is created, THE Turn_Loop_API SHALL store the initial World_State as the world for turn 0.
3. THE stored World_State SHALL include each robot's room, spot and held object; the man's room, spot, activity and state; the power; whether the house is shaking; the time of day; the light level per room; the broken and fallen objects; and the stove's state.
4. WHEN `GET /api/episodes/{id}` is called for an existing episode, THE Turn_Loop_API SHALL include the stored World_State for each turn in the response.
5. IF `GET /api/episodes/{id}` is called for an episode id that does not exist, THEN THE Turn_Loop_API SHALL leave stored data unchanged and return a not-found error response indicating that no episode matches the given id.
6. WHEN a stored World_State is written and then read back, THE Turn_Loop_API SHALL return a World_State in which every field listed in criterion 3 equals the value that was written, with no field added, dropped or altered (round-trip).
7. WHERE an episode has at least one turn whose `world_json` is absent or null, WHEN that episode is next read via `GET /api/episodes/{id}`, THE Turn_Loop_API SHALL fill only the missing World_State values by replaying the engine for that episode exactly once, so that turns already holding a `world_json` value are left unchanged.
8. IF the backfill replay for an episode fails to complete, THEN THE Turn_Loop_API SHALL leave the existing stored World_State values unchanged, persist no partial World_State for that replay, and return an error response indicating that the World_State backfill failed.
9. THE Turn_Loop_API SHALL write the schema change that adds `world_json` as a dated migration file in `sql/`.

### Requirement 11: Ended-Episode List Endpoint (API)

**User Story:** As the Replay_Screen, I want a list of ended episodes newest first, so that I can offer them for replay without exposing any device or secret.

#### Acceptance Criteria

1. WHEN `GET /api/episodes?ended=1&limit=20` is called, THE Turn_Loop_API SHALL return only episodes whose ended state is true, excluding every episode that has not ended.
2. THE Turn_Loop_API SHALL order the returned ended episodes by created date descending, and for episodes sharing the same created date, by id descending.
3. THE Turn_Loop_API SHALL return for each ended episode its id, its created date, its number of turns as a non-negative integer, and its starting situation text.
4. WHEN the `limit` value is an integer between 1 and 100 inclusive, THE Turn_Loop_API SHALL return at most that number of ended episodes.
5. IF the `limit` value is absent, THEN THE Turn_Loop_API SHALL return at most 20 ended episodes.
6. IF the `limit` value is not an integer, is less than 1, or is greater than 100, THEN THE Turn_Loop_API SHALL reject the request with an error response indicating the limit is out of range, and SHALL NOT return any episode list.
7. THE Turn_Loop_API SHALL NOT include any device id, API key, database credential, or AI provider name in the Episode_List response.
8. WHEN no ended episodes exist, THE Turn_Loop_API SHALL return an empty Episode_List.
9. WHEN the Episode_List endpoint responds, THE Turn_Loop_API SHALL send the HTTP header `Cache-Control: no-store`.

### Requirement 12: Look, Accessibility and Caching

**User Story:** As a visitor on any device, I want the screens readable, usable by keyboard, and respectful of motion settings, so that everyone can watch comfortably.

#### Acceptance Criteria

1. THE Screens SHALL present their user interface in English using the DotGothic16 font.
2. WHERE the DotGothic16 font fails to load, THE Screens SHALL render text in a fallback sans-serif font and SHALL keep all text visible during font loading.
3. THE Screens SHALL lay out mobile first for a minimum viewport width of 320 pixels with a 16-pixel left and right gutter, SHALL produce no horizontal scroll at any viewport width from 320 pixels upward, and SHALL scale the house image with `image-rendering: pixelated`.
4. THE Screens SHALL make every button reachable and operable by keyboard alone (Tab to reach, Enter or Space to activate) and SHALL show a visible focus indicator with a minimum 3:1 contrast ratio against adjacent colors on the currently focused control.
5. WHEN a new entry is added to the "What happened" log, THE Screens SHALL announce it through a region marked with `aria-live` set to a polite announcement so screen readers read the entry without interrupting current speech.
6. WHILE the viewer's system requests reduced motion, THE Screens SHALL stop the shaking and walking animation and SHALL move each character by setting its position directly within 100 milliseconds with no intermediate frames.
7. WHEN any page of the Screens responds, THE Screens SHALL send the HTTP header `Cache-Control: no-store`.
8. THE Screens SHALL name the robots only as Amber and Jade and SHALL NOT name any AI company in the robots' text.
9. THE Screens SHALL reference each page's scripts, styles, and API endpoints using relative URLs so that each page works both when `public/` is served as the web root and when `public/` is served under a `/public/` path.
10. THE Screens SHALL reference each page's own scripts, styles, and API calls without any absolute path beginning with `/` and without any absolute origin.
