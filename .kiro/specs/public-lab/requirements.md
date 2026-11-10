# Requirements Document

## Introduction

Public-lab prepares PoltergeistSwitch for a public audience. The site will be linked from the GitHub README, the YouTube description and Devpost, so anyone can try it before and during judging. Four things must change for that.

First, robot memory is one pool for the whole site today. Every visitor's run adds to it, every visitor inherits it, and "All reset" by anyone resets it for everyone. The intent is per user: each visitor starts with robots that remember nothing, and "All reset" affects only that visitor's own data. The visitor is identified by the existing `ps_device` cookie, which is already stored as `episodes.device_id`.

Second, the "Watch a past episode" list shows one global list. It becomes two groups: a fixed Featured set that everyone sees, and the visitor's own finished runs.

Third, the maintainer found Observe problems: speech bubbles vanish after about 2 s while the voice keeps reading, so the picture looks frozen; Voices start OFF; the control-row Pause, Step and Resume buttons appear to do nothing; after an episode ends the levers no longer work until a reload; a run that stops after failed ask or apply tries cannot be continued; the Heavy rain lever is not needed; and the heat-wave sky should look like the pilot v0.5 heat scene.

Fourth, every new episode calls two paid AI models, so starting new episodes is limited per UTC day for each Device, each IP address and the whole site (Requirement 13). Only starting a new episode is limited; running episodes, replays and the replay list are never limited.

Memory retirement is also per Device: the existing cap of 6 active memories per robot per disaster applies to each Device's own memories only (Requirement 2).

This spec builds on the `house-rules-engine`, `turn-loop`, `screens`, `robot-memory` and `heat-wave` specs. It MUST NOT change the engine, the house rules, the situation and reflection texts, their versions, or any recorded episode; protecting them is Requirement 1. Tech follows the steering rules: PHP 8.2 plain classes in `src/` with no I/O, thin entry points in `api/`, additive SQL in a new file in `sql/`, plain JavaScript with no build step, PHPUnit with Eris property tests, no real AI calls in tests, and `Cache-Control: no-store` on every API response and page.

### Out of Scope

- Any change to the engine, the rules, the robots' input text, or the recorder.
- Accounts, logins, or any device identification other than the existing `ps_device` cookie.
- Deleting any memory, episode, or other row (everything is hidden, never deleted).
- The Compare and Admin screens, the research batch, and the memory MCP server.
- Limiting running episodes, replays, the replay list or direct replay links (only starting a new episode is limited, see Requirement 13).
- Any change to earthquake drawing.

## Glossary

- **Public_Lab**: The system this spec defines: per-device memory, the grouped replay list, the Observe fixes, and the heat-wave sky.
- **Device**: A browser identified by the `ps_device` cookie (`PS_DEVICE_COOKIE`). Its value is stored as `episodes.device_id` when an episode is created.
- **Device_Id**: The cookie value of a Device. It never appears in any API response body, page, or log shown to a visitor.
- **Memory_Service**: The `MemoryService` class in `src/Memory/` that takes the per-episode Memory_Snapshot, reports memory status, and resets memory.
- **Reflect_Service**: The `ReflectService` class in `src/Memory/` that asks both robots once after an ended episode and stores each answer as a memory.
- **Memory_Snapshot**: The frozen per-robot list of memory bodies stored with an episode at creation (`robot_a_memory_snapshot`, `robot_b_memory_snapshot`), oldest first, at most 18 per robot.
- **Device_Memory**: A memory row that belongs to one Device. A memory belongs to the Device named by `device_id` of its source episode, read at reflection time.
- **Legacy_Memory**: A memory row written before this spec, which has no Device.
- **Reset_Point**: The per-Device marker that "All reset" records, after which the Device's earlier memories are no longer used and its earlier ended episodes are no longer listed under "Your runs". How it is stored is a design decision; it MUST be additive in `sql/`.
- **Featured_List**: The fixed, ordered list of episode ids in repository configuration (non-secret), given in Requirement 6.1.
- **Episode_List**: The "Watch a past episode" list on the Replay screen.
- **Observe**: The Observe screen (`public/index.php`, `public/js/observe.js`) with its levers, Start button, Voices button, stage, meters, log and End_Card.
- **Replay**: The Replay screen (`public/replay.php`, `public/js/replay.js`).
- **Lever**: A Breaker_Switch on Observe. Lever-up is ON, lever-down is OFF.
- **All_Reset_Button**: The Observe button that, after Confirm, calls `POST /api/memory/reset`.
- **Voices_Module**: `public/js/voices.js`, which reads spoken lines aloud with the browser's Web Speech API. A fake Voices_Module is a test double with the same interface that records calls and completes a line on demand.
- **Spoken_Line**: One spoken line of a turn: a robot's quoted words from its recorder reading, or the resident's `residentLine`. Thoughts are never Spoken_Lines.
- **Line_Sequence**: The Spoken_Lines of one turn in stored order: Amber's lines, then Jade's lines, then the man's line.
- **Reading_Time**: With Voices off, the time a Spoken_Line stays visible: 2000 ms plus 60 ms per character, capped at 12000 ms.
- **Speech_Bubble**: The bubble above a character on the stage, and on narrow screens the caption strip, that shows a Spoken_Line.
- **First_Gesture**: The visitor's first click in a page load on a Lever, Start, Notice_Resume or Try_Again (on Observe), or on Play, a step control or an Episode_List item (on Replay).
- **UTC_Day**: One calendar day in UTC, from 00:00:00 to 23:59:59 UTC. Daily limits reset at the start of each UTC_Day.
- **Daily_Limit**: The maximum number of new episodes that may be started in one UTC_Day by one Device (10), by one IP address (30), or by the whole site (100). The three numbers are `Config` values.
- **IP_Hash**: The SHA-256 hash of a visitor's IP address combined with the IP_Salt. It is the only form of the IP address that is stored.
- **IP_Salt**: A random secret created once and kept server-side outside the repository and the web root, used only to compute the IP_Hash. Where it is kept is a design decision.
- **Unfinished_Episode**: An episode of this Device that has not ended and has no ask or apply in flight, shown on Observe after a run stopped because the ask/apply recovery tries were used up.
- **Device_Busy_Notice**: The Observe notice "A running episode already exists on this device." with the actions "Resume" and "Watch a past episode".
- **Notice_Resume**: The "Resume" action of the Device_Busy_Notice, which continues this Device's running episode (`resumeEpisode` in `observe.js`) after a page reload. It is not the control-row Resume button removed by Requirement 10.
- **Try_Again**: The "Try again" button in the notice shown for an Unfinished_Episode. It continues the same episode from its stored state.
- **Heat_Palette**: The pilot v0.5 heat colours defined in Requirement 12.
- **House_Renderer**: The shared stage drawing module `public/js/house-renderer.js`.
- **Golden_Master**: `tests/fixtures/earthquake_golden_master.json` (SHA-256 starts with `c2aba50f87c22fe6`) and `EarthquakeGoldenMasterTest`.
- **Amber / Jade**: The screen names of Robot_A (Claude) and Robot_B (GPT). The robots never see these names.

## Requirements

### Requirement 1: Protecting the Finished Work (hard constraint)

**User Story:** As the maintainer, I want nothing in this spec to change how the engine behaves or how a recorded episode replays, so that the submitted video and every existing replay link stay valid.

#### Acceptance Criteria

1. THE Public_Lab SHALL keep the Golden_Master fixture unchanged, with its SHA-256 starting with `c2aba50f87c22fe6`, and SHALL pass `EarthquakeGoldenMasterTest`.
2. THE Public_Lab SHALL keep the engine, the house rules, the `EpisodeConfig` values, the situation texts, the reflection texts and their version strings unchanged, byte for byte.
3. WHEN a recorded episode is replayed, THE Public_Lab SHALL show the same turns, prompts, replies and Memory_Snapshot as before this spec, because each episode keeps its frozen Memory_Snapshot.
4. WHEN `GET /api/episodes/{id}` is called for an episode that existed before this spec, THE Public_Lab SHALL return the same bytes as before this spec.
5. IF any task would change a protected item in criteria 1 to 4, THEN THE Public_Lab SHALL stop and report the change to the maintainer and SHALL NOT edit any expected value to make a test pass.
6. WHEN the database schema changes, THE Public_Lab SHALL put the change in a new file in `sql/` that only adds tables, or adds columns that are nullable or have defaults, SHALL leave every existing row and column with its current meaning, and SHALL NOT run the file against any database.
7. THE Public_Lab SHALL write no API key, password, passphrase or Device_Id in code, docs, tests, `.kiro/` or any response body.
8. BEFORE every commit in this spec, THE Public_Lab SHALL pass `php -l` on every changed PHP file, the full PHPUnit suite and the JavaScript tests.

---

### Requirement 2: Memory Belongs to a Device

**User Story:** As a visitor, I want my runs to teach only my robots, so that other visitors' runs never change what my robots remember.

#### Acceptance Criteria

1. WHEN Reflect_Service stores a memory after an episode, THE Memory_Service SHALL record the `device_id` of that episode as the owner of the memory.
2. WHEN a new episode is created, THE Memory_Service SHALL build the Memory_Snapshot for each robot only from active memories owned by the Device that creates the episode and belonging to that robot.
3. WHEN the Memory_Service builds a robot's Memory_Snapshot, THE Memory_Service SHALL include memories of every disaster, keep the newest 18, and order them oldest first, so that a Device's earthquake memories reach its heat-wave runs and the other way round.
4. IF a robot's Device owns more than 18 active memories, THEN THE Memory_Service SHALL omit the oldest memories beyond the newest 18 from the Memory_Snapshot and SHALL keep the omitted memories stored unchanged.
5. WHEN two Devices each finish an episode and reflect, THE Memory_Service SHALL give each Device's next Memory_Snapshot only that Device's own memories.
6. THE Memory_Service SHALL exclude every Legacy_Memory from every Memory_Snapshot, so that every Device starts with no memories.
7. WHEN a Device with no active memories creates an episode, THE Memory_Service SHALL store an empty Memory_Snapshot (0 memories) for both robots.
8. THE Public_Lab SHALL delete no memory row and no episode row.
9. WHEN an episode is replayed, THE Public_Lab SHALL use the episode's stored Memory_Snapshot and SHALL NOT recompute it from the `memories` table.
10. WHEN a memory is stored, THE Memory_Service SHALL apply the existing retire-on-store cap (the 6 newest active memories per robot per disaster per generation, `MEMORY_ACTIVE_CAP` in `PdoEpisodeRepository` and the same value in the in-memory repository) to the active memories owned by the storing Device only, and SHALL retire only that Device's own oldest surplus memories.
11. WHEN a Device stores a memory, THE Memory_Service SHALL leave every memory of every other Device and every Legacy_Memory unchanged, including its status and `retired_at`.
12. WHEN the Reflect_Service reports a robot's active count for a reflection, THE Reflect_Service SHALL count only the active memories owned by the Device of the reflected episode.
13. THE Memory_Service SHALL keep the Memory_Snapshot cap of the newest 18 per robot, as written in criteria 3 and 4, unchanged.

---

### Requirement 3: Per-Device Memory Status

**User Story:** As a visitor, I want the memory line on Observe to describe my robots only, so that I can see what they remember.

#### Acceptance Criteria

1. WHEN `GET /api/memory` is called with a `ps_device` cookie, THE Memory_Service SHALL report the generation or reset state and, per robot (`amber`, `jade`), the active-memory count and the character length of each active memory of that Device only, in the same JSON shape as today.
2. IF `GET /api/memory` is called without a `ps_device` cookie, THEN THE Memory_Service SHALL report zero memories for both robots, SHALL NOT mint a cookie, and SHALL respond with status 200.
3. THE Public_Lab SHALL NOT include a Device_Id, an API key, a credential, a path, or an AI company or model name in the body of `GET /api/memory`.
4. THE Public_Lab SHALL send `Cache-Control: no-store` on `GET /api/memory`.
5. WHEN two Devices have different memories, THE Memory_Service SHALL report different counts to each, matching each Device's own active memories.

---

### Requirement 4: Per-Device Reset

**User Story:** As a visitor, I want "All reset" to clear only my robots' memory, so that pressing it never affects anyone else.

#### Acceptance Criteria

1. WHEN `POST /api/memory/reset` is called with a `ps_device` cookie and that Device has no running episode, THE Memory_Service SHALL record exactly one Reset_Point for that Device, after which none of that Device's memories created before the Reset_Point appears in any Memory_Snapshot created after it or in `GET /api/memory` for that Device.
2. WHEN a Device resets, THE Memory_Service SHALL leave every other Device's memories, Memory_Snapshots, status and episode list unchanged (identical content before and after).
3. WHEN a Device resets, THE Memory_Service SHALL delete zero rows, modify zero existing rows in any table, and add only the Reset_Point.
4. IF `POST /api/memory/reset` is called while the calling Device has a running episode, THEN THE Public_Lab SHALL respond with status 409 and a short machine code identifying that an episode is running, SHALL record no Reset_Point, and SHALL leave that Device's memories unchanged.
5. WHILE another Device has a running episode and the calling Device has none, THE Public_Lab SHALL accept the reset from the calling Device with status 200.
6. IF `POST /api/memory/reset` is called without a `ps_device` cookie, THEN THE Public_Lab SHALL respond with status 200 and an empty status, SHALL record nothing, and SHALL NOT mint a cookie.
7. WHEN a Device resets a second time with no new memory stored since its previous reset, THE Public_Lab SHALL respond with status 200, SHALL leave the Device with zero active memories, and SHALL return no error.
8. WHEN a running episode of a Device was created before that Device's reset, THE Reflect_Service SHALL NOT store a memory for it that becomes active after the reset.
9. THE Public_Lab SHALL send `Cache-Control: no-store` on every response of `POST /api/memory/reset` and SHALL NOT include a Device_Id in any response body.

---

### Requirement 5: Legacy Data Handling and Schema

**User Story:** As the maintainer, I want the existing site-wide memories left in place but never shown to anyone, so that no data is lost and every visitor starts clean.

#### Acceptance Criteria

1. THE Public_Lab SHALL add the owner and reset storage in one new file in `sql/`, named with the date and a short description like the existing files, that is additive only.
2. THE Public_Lab SHALL treat every memory with no owner as a Legacy_Memory and SHALL give a Legacy_Memory to no Device in any snapshot, status or reflection.
3. WHEN the new SQL file has not yet been applied, THE Public_Lab SHALL NOT be required to work; the maintainer applies the file over FTPS before deploying the code, and the file's header comment SHALL say so.
4. THE Public_Lab SHALL keep the existing Memory_Snapshot and episode rows unchanged by the SQL file.
5. THE Public_Lab SHALL keep the in-memory fake repository and the PDO repository behaviourally identical for the new owner and reset operations, so that the same tests describe both.

---

### Requirement 6: Replay List With Two Groups

**User Story:** As a visitor, I want to see the featured episodes and my own runs separately, so that I can watch the best recordings and review what I did.

#### Acceptance Criteria

1. THE Public_Lab SHALL read the Featured_List from non-secret repository configuration, in this order: `b9931223-fc11-4162-8a4d-a8cf55886e1e`, `450e9249-94db-4744-987d-ec6774bf5057`, `4113e82c-2f09-49e4-b647-a1481d794807`, `2a3800d5-4029-448c-8152-fd622693a775`, `3ef560a5-c35c-4632-869f-d01fb2dd2e2b`, `bae88cfc-7eea-4cb6-a742-a49c0ccc401c`, `36e82c3e-03bc-4396-bfdc-e3fc33ffe671` (earthquake) and `186823c0-3ff4-4289-ad30-ca98ef97708e` (heat wave).
2. WHEN the Episode_List is requested, THE Public_Lab SHALL return a Featured group containing the Featured_List episodes that exist and have ended, in the configured order, to every visitor, with or without a cookie.
3. WHEN the Episode_List is requested with a `ps_device` cookie, THE Public_Lab SHALL return a "Your runs" group containing that Device's ended episodes created after its Reset_Point (all of them if it has none), newest first, with the same item fields as the current list.
4. THE Public_Lab SHALL NOT list another Device's episodes in any group, except those in the Featured_List.
5. WHEN an episode is in both groups, THE Public_Lab SHALL list it in the Featured group only.
6. IF a Featured_List id does not exist or has not ended, THEN THE Public_Lab SHALL omit that id from the Featured group without an error.
7. WHEN a Device resets memory, THE Public_Lab SHALL remove that Device's earlier episodes from "Your runs" and SHALL leave them replayable by direct link.
8. THE Public_Lab SHALL keep the current limit validation of the list (integer 1 to 100) for "Your runs", and SHALL return the Featured group in full.
9. THE Replay SHALL show the two groups under the headings "Featured" and "Your runs", and SHALL show a short empty-state line under "Your runs" when the Device has none.
10. THE Public_Lab SHALL NOT include a Device_Id in the list response and SHALL send `Cache-Control: no-store`.

---

### Requirement 7: Direct Replay Links Keep Working

**User Story:** As the maintainer, I want every replay link in the video description and on Devpost to keep working, so that judges never meet a broken link.

#### Acceptance Criteria

1. WHEN `replay.php?episode=<id>` is opened for any existing ended episode id, THE Replay SHALL play that episode whichever Device owns it and whether or not it is in a list.
2. WHEN `replay.php?episode=<id>` is opened for an unknown id, THE Replay SHALL show the existing not-found message and offer the Episode_List.
3. THE Public_Lab SHALL keep `GET /api/episodes/{id}` available for any episode id, without a device check.

---

### Requirement 8: Observe Resets Cleanly

**User Story:** As a visitor, I want "All reset" to return Observe to its first-load state, so that I can start over without reloading.

#### Acceptance Criteria

1. WHEN the visitor confirms All reset and the server accepts it, THE Observe SHALL set both Levers to OFF, return the stage, meters, log and End_Card to their first-load state, and show this Device's (empty) memory status.
2. WHILE turns are running, THE Observe SHALL disable the All_Reset_Button.
3. IF the server answers All reset with 409, THEN THE Observe SHALL leave the stage and Levers unchanged and show a short message.
4. WHEN All reset has completed, THE Observe SHALL start the next run normally when the visitor flips a Lever or presses Start.

---

### Requirement 9: Observe After an Episode Ends

**User Story:** As a visitor, I want the levers to work again when an episode ends, so that I can run another one without reloading.

#### Acceptance Criteria

1. WHEN the last turn has been acted out and the reflection has finished (or has been reported as not stored), THE Observe SHALL set both Levers to OFF.
2. WHEN a Lever is flipped ON or Start is pressed after an episode has ended, THE Observe SHALL create a new episode and run it normally.
3. WHEN a new run starts after an ended episode, THE Observe SHALL clear the previous episode's client state and dismiss its End_Card.
4. THE Observe SHALL keep the existing ask and apply 5xx recovery behaviour unchanged (wait 5000 ms, read the stored episode, continue; at most 3 tries per turn), except for the notice and the recovery action defined in criteria 6 to 9.
5. WHILE a run is in progress, THE Observe SHALL ignore a second flip of a Lever or Start press instead of starting another episode. A run is in progress from the moment a run starts until it ends or stops with an Unfinished_Episode notice.
6. WHEN the ask or apply of a turn fails and the recovery tries for that turn are used up, or the failure is not recoverable, THE Observe SHALL stop the run, keep the Unfinished_Episode's turns on screen, and show the notice "A turn could not be completed." with a "Try again" button.
7. WHEN the visitor presses Try_Again, THE Observe SHALL read the stored state of the same episode, continue from its stored next turn without creating a new episode, and resume the run loop, or show the End_Card if the stored episode has ended.
8. WHILE an Unfinished_Episode is shown, WHEN a Lever is flipped or Start is pressed, THE Observe SHALL do what Try_Again does and SHALL NOT create a new episode.
9. THE Observe SHALL NOT show the word "Paused" in any notice. This includes the notices for a failed ask, a failed apply and an unreachable lab.

---

### Requirement 10: Controls and Levers Removed

**User Story:** As the maintainer, I want only the controls that work, so that the public page does not show buttons that appear to do nothing.

#### Acceptance Criteria

1. THE Observe SHALL contain no Pause, Step or Resume button in the control row, and no JavaScript code path, handler, state or CSS rule that exists only for those three buttons.
2. THE Observe SHALL keep the Start button and the Voices button.
3. THE Observe SHALL keep the Device_Busy_Notice with its Notice_Resume action, which continues this Device's running episode after a page reload (`resumeEpisode`), and its "Watch a past episode" action.
4. WHEN the visitor presses Notice_Resume, THE Observe SHALL read the stored state of this Device's running episode and continue it, so that the removal of the control-row Resume button does not remove Notice_Resume.
5. THE Observe SHALL contain two Levers only, Earthquake and Heat wave, and no Heavy rain Lever, "coming later" label, or code path for it.
6. THE Replay SHALL keep its own Play, Pause, Step back and Step forward controls unchanged.
7. THE Public_Lab SHALL keep the earthquake and heat-wave run flows unchanged apart from the removed controls.

---

### Requirement 11: Voices and Speech Bubbles

**User Story:** As a visitor, I want each spoken line to be visible while it is read aloud and the next turn to wait for it, so that the picture never looks frozen or out of step with the voice.

#### Acceptance Criteria

1. WHEN Observe or Replay loads, THE Public_Lab SHALL set Voices on and show a button labelled "Voices: on" or "Voices: off" matching the setting.
2. WHEN the visitor presses the Voices button, THE Public_Lab SHALL switch the setting and update the label within 100 ms.
3. WHILE no First_Gesture has happened in the page load, THE Voices_Module SHALL NOT start speech. On Replay the First_Gesture is the first click on Play, a step control or an Episode_List item; on Observe it is the first click on a Lever, Start, Notice_Resume or Try_Again.
4. WHEN the First_Gesture happens and Voices are on, THE Voices_Module SHALL be allowed to speak.
5. WHEN a turn is acted out, THE Observe and THE Replay SHALL show one Spoken_Line at a time in Line_Sequence order, each in its own character's Speech_Bubble, and never two Speech_Bubbles at once.
6. WHILE Voices are on and a Spoken_Line is being read aloud, THE Speech_Bubble SHALL stay visible.
7. WHEN the reading ends or fails while Voices are on, THE Speech_Bubble SHALL close within 100 ms and THE Observe and THE Replay SHALL start the next Spoken_Line.
8. WHILE Voices are off, THE Speech_Bubble SHALL stay visible for the Reading_Time of its Spoken_Line and then close.
9. IF the Voices_Module does not report the end of a reading within 12000 ms of its start, THEN THE Speech_Bubble SHALL close at 12000 ms and THE Observe and THE Replay SHALL start the next Spoken_Line.
10. WHEN a turn's Line_Sequence has finished, THE Observe and THE Replay SHALL start acting out the next turn, and THE Observe MAY still prefetch the next ask as today.
11. THE Speech_Bubble caption strip on narrow screens SHALL show the same line, in the same order and for the same duration as the Speech_Bubble.
12. IF a turn has no Spoken_Line, THEN THE Observe and THE Replay SHALL add 0 ms of waiting for speech.
13. THE Public_Lab SHALL show or speak no robot thought.
14. WHEN Voices are turned off while a Spoken_Line is being read, THE Voices_Module SHALL stop reading within 200 ms and THE Speech_Bubble SHALL stay visible for the remaining Reading_Time counted from the Reading_Time start.
15. WHEN Voices are turned on while a Spoken_Line's Speech_Bubble is visible, THE Voices_Module SHALL NOT start reading that line, and THE Speech_Bubble SHALL close at the end of its remaining Reading_Time.
16. WHEN the visitor presses Pause, Step back, Step forward or Play on the Replay, THE Replay SHALL cancel any reading and any bubble timer from the earlier position, close the open Speech_Bubble, and start no further Spoken_Line until the new position's Line_Sequence begins.

---

### Requirement 12: Heat-Wave Sky

**User Story:** As the maintainer, I want the heat-wave sky to look like the pilot v0.5 heat scene, so that the heat episode reads as a hot afternoon at first glance.

#### Acceptance Criteria

1. WHILE the world's disaster is heat wave, THE House_Renderer SHALL draw the sky as a vertical gradient from rgb(58,14,30) at the top to rgb(196,78,40) at the bottom.
2. WHILE the world's disaster is heat wave, THE House_Renderer SHALL draw the hills in `#3a1622`, the ground in `#2a1a10`, and the ground texture in `#3c2614`.
3. WHILE the world's disaster is heat wave, THE House_Renderer SHALL draw a yellow sun centred at (330, 34) made of a glow disc of `#ffc83a` at alpha 0.16 and radius 32, a glow disc of `#ffd84a` at alpha 0.32 and radius 21, and a core disc of `#ffe46a` and radius 13.
4. THE House_Renderer SHALL keep the existing heat shimmer, windows, fan and air conditioner drawing in heat-wave worlds.
5. WHILE the world's disaster is earthquake, THE House_Renderer SHALL draw exactly as today, with none of the Heat_Palette colours and no sun.
6. WHEN a heat-wave world is replaced by an earthquake world in the same House_Renderer, THE House_Renderer SHALL draw the earthquake sky and ground again.

---

### Requirement 13: Daily Usage Limits

**User Story:** As the maintainer, I want a daily cap on new episodes per Device, per IP address and for the whole site, so that a public visitor cannot run up the AI bill.

#### Acceptance Criteria

1. THE Public_Lab SHALL limit only the starting of a new episode through `POST /api/episodes`.
2. THE Public_Lab SHALL let an episode that has already started run to its end, and SHALL apply no Daily_Limit to `ask`, `apply`, reflection, `GET /api/episodes/{id}`, the Episode_List, `replay.php` or a direct replay link.
3. THE Public_Lab SHALL read three Daily_Limit values from `Config`: 10 new episodes per Device, 30 per IP address and 100 for the whole site, each counted per UTC_Day.
4. WHEN `POST /api/episodes` is called and the Device has already started 10 episodes in the current UTC_Day, THE Public_Lab SHALL respond with status 429 and the error `daily_device_limit` and SHALL create no episode.
5. WHEN `POST /api/episodes` is called and the IP address has already started 30 episodes in the current UTC_Day, THE Public_Lab SHALL respond with status 429 and the error `daily_ip_limit` and SHALL create no episode.
6. WHEN `POST /api/episodes` is called and the site has already started 100 episodes in the current UTC_Day, THE Public_Lab SHALL respond with status 429 and the error `daily_site_limit` and SHALL create no episode.
7. WHEN more than one Daily_Limit is reached, THE Public_Lab SHALL report them in this order: `daily_device_limit`, then `daily_ip_limit`, then `daily_site_limit`.
8. IF the Device already has a running episode, THEN THE Public_Lab SHALL answer `POST /api/episodes` with the existing 409 before checking any Daily_Limit, so that Notice_Resume stays available.
9. WHEN a request is answered with 429 or with any other error, THE Public_Lab SHALL count no new episode for it.
10. WHEN a new UTC_Day begins at 00:00:00 UTC, THE Public_Lab SHALL count every Device, IP address and the site from zero.
11. WHEN `POST /api/episodes` is answered with 429, THE Observe SHALL keep both Levers OFF, leave the stage unchanged and show a plain message in the notice area.
12. WHEN the error is `daily_device_limit` or `daily_ip_limit`, THE Observe SHALL show "You have run the lab many times today. It opens again tomorrow (UTC)."
13. WHEN the error is `daily_site_limit`, THE Observe SHALL show "The lab has had a busy day. It opens again tomorrow (UTC)."
14. THE Public_Lab SHALL store no raw IP address in the database, in a log, or in any response body, and SHALL store only the IP_Hash.
15. THE Public_Lab SHALL create the IP_Salt once, as random bytes, and keep it server-side outside the repository and outside the web root, in a place the design chooses, without requiring the maintainer to edit a secrets file by hand.
16. WHEN the IP_Salt does not exist yet, THE Public_Lab SHALL create it on first use and SHALL reuse the same value afterwards.
17. THE Public_Lab SHALL put the storage for the daily counts in the new additive file in `sql/` (Requirement 1, criterion 6).
18. THE Public_Lab SHALL include no Device_Id, raw IP address or IP_Hash in the 429 response body.
19. THE Public_Lab SHALL send `Cache-Control: no-store` on the 429 response.

---

### Requirement 14: Testing (all test sub-tasks are required)

**User Story:** As the maintainer, I want every behaviour of this spec covered by tests that must be run, so that the public site is safe to release.

#### Acceptance Criteria

1. THE Public_Lab SHALL mark every test sub-task in `tasks.md` as required, not optional.
2. THE Public_Lab SHALL test, against the in-memory fake repository and without any real AI call, that two Devices keep separate memories and separate Memory_Snapshots.
3. THE Public_Lab SHALL test that a reset by one Device leaves another Device's memories, status and episode list unchanged, and that reset deletes no row.
4. THE Public_Lab SHALL test that a Legacy_Memory is never used in a snapshot, a status or a reflection for any Device.
5. THE Public_Lab SHALL test the snapshot order and cap (oldest first, newest 18, across disasters) for a single Device with Eris property tests.
6. THE Public_Lab SHALL test `GET /api/memory` and `POST /api/memory/reset` with and without the cookie, and the 409 on reset during a running episode.
7. THE Public_Lab SHALL test the Episode_List grouping: Featured order, omission of missing or unended ids, no other Device's runs, no duplicates, and "Your runs" after a reset.
8. THE Public_Lab SHALL test that `replay.php?episode=<id>` and `GET /api/episodes/{id}` still work for an episode that is in no list.
9. THE Public_Lab SHALL test in JavaScript that the Levers are OFF after an episode ends and after All reset, that a new run starts and clears client state and the End_Card, and that the All_Reset_Button is disabled while turns run.
10. THE Public_Lab SHALL test that the Observe page has no control-row Pause, Step or Resume button, no Heavy rain control and no code path only for them, and that the Device_Busy_Notice still shows Notice_Resume, which continues the running episode through `resumeEpisode`.
11. THE Public_Lab SHALL test the Speech_Bubble timing with a fake Voices_Module: one line at a time in order, visible until the reading ends with Voices on, Reading_Time (2000 ms plus 60 ms per character, cap 12000 ms) with Voices off, the next turn waiting for the lines, and no speech before the First_Gesture.
12. THE Public_Lab SHALL test that the Heat_Palette sky, hills, ground and sun are drawn in heat-wave worlds only and that an earthquake world is drawn as before.
13. THE Public_Lab SHALL keep `EarthquakeGoldenMasterTest` and the whole existing suite passing.
14. THE Public_Lab SHALL test, with two Devices on the in-memory fake repository, that when one Device stores more than 6 memories for a robot and disaster only that Device's oldest surplus memories are retired, while the other Device's memories stay active and unchanged and every Legacy_Memory is unchanged, and that the reflection's active count is per Device. The same test SHALL confirm that the Memory_Snapshot cap stays at 18.
15. THE Public_Lab SHALL test in JavaScript that, after the ask/apply recovery tries are used up, the notice reads "A turn could not be completed." with a "Try again" button and never contains "Paused", that Try_Again continues the same episode from its stored state without creating a new episode, and that a Lever flip or Start press while the Unfinished_Episode is shown does the same.
16. THE Public_Lab SHALL test that a flip of a Lever or a Start press while a run is in progress is ignored (Requirement 9, criterion 5).
17. THE Public_Lab SHALL test each Daily_Limit at its boundary (the 10th, 30th and 100th episode succeed and the 11th, 31st and 101st receive 429 with `daily_device_limit`, `daily_ip_limit` and `daily_site_limit`, with nothing created), the order of the three errors, the reset at UTC midnight, and that the 409 for a running episode comes before the limits.
18. THE Public_Lab SHALL test that a running episode is not affected when a limit is reached, and that `GET /api/episodes/{id}`, the Episode_List and `replay.php` still work for a Device, IP address or site at its limit.
19. THE Public_Lab SHALL test that only the IP_Hash is stored, that the same IP address and salt give the same IP_Hash, that the IP_Salt is created once and reused, and that no raw IP address appears in the stored data or any response.
20. THE Public_Lab SHALL test in JavaScript that a 429 keeps both Levers OFF and shows the Device or IP message for `daily_device_limit` and `daily_ip_limit`, and the site message for `daily_site_limit`.
