# Requirements Document

## Introduction

Robot-memory is the fifth spec of PoltergeistSwitch (product brief §15 item 6). It gives each robot a memory that accumulates across every episode it lives through. After an ended episode each robot is asked once, in its own words, what it wants to remember; a non-empty answer becomes a memory. At the start of every later episode the robot receives all its retained memories of the current generation, oldest first, in the fixed memories block, so a robot that lived through one disaster may draw on it in another.

This spec builds on the `house-rules-engine`, `turn-loop`, and `screens` specs. It adds a pure engine method that builds the reflection text a robot reads before the question, a reflection endpoint that asks both robots after an episode ends, a memory store with a per-robot per-disaster cap and a generation mechanism, the memories block inside every turn's situation text, an All reset and a status endpoint, and small additions to the Observe, End card, and Replay screens. It MUST NOT change the turn rules or the recorder.

Robots start with zero memories. The memory store holds at most 6 active memories per robot per disaster (at most 18 per robot across the three disasters). When a 7th memory for the same robot and disaster arrives, the oldest active one is retired: it is never passed to a robot again, but its row stays in the database with a retired mark and is never deleted. "All reset" moves to a new generation so robots see zero memories again; nothing is deleted, and a running episode keeps the generation and the snapshot it was created with.

The memories block a robot reads is the robot's own words, passed verbatim, and is therefore exempt from the banned-word rule of `robot-input.md`; every engine-authored part of both the reflection text and the turn text stays subject to that rule, names no AI company or model, and shows no robot thought or reply. On screen the two robots are named Amber (driven by Anthropic's `claude-haiku-5-5`) and Jade (driven by OpenAI's `gpt-6-luna`); the robots never see these names.

Tech follows the steering rules: PHP 8.2 plain classes in `src/`, thin entry points in `api/`, SQL files in `sql/`, PHPUnit with Eris property tests, no real API calls in tests, and `Cache-Control: no-store` on every API response and page. The reflection text has its own version string stored with the prompt, like the situation-text version; adding the memories block bumps the situation-text version.

### Out of Scope

The following are explicitly out of scope for this spec:

- Heavy rain and heat wave (earthquake only, as in the specs this builds on).
- The Compare screen and the Admin screen.
- The 54-episode research batch runner and admin review.
- The memory MCP server (a later spec).
- Any change to the turn rules or to the recorder.

## Glossary

- **Robot_Memory**: The system this spec defines — the reflection text builder, the reflection endpoint, the memory store with its cap and generations, the memories block in turn texts, the All reset and status endpoints, and the screen additions that show memory.
- **Memory**: One stored answer a robot gave to the reflection question, stored verbatim as the robot's own words, tagged with the robot, the generation of its source episode, the disaster, the source episode, and a created time.
- **Active_Memory**: A memory that is still passed to a robot — not retired. The memories block and the status counts use only active memories.
- **Retired_Memory**: A memory marked retired because a newer memory for the same robot and disaster pushed the per-disaster active count past 6. A retired memory is never passed to a robot again, keeps its row in the database, and is never deleted.
- **Generation**: The numbered era of the memory store. A robot in an episode sees only active memories of the episode's generation. All reset starts a new generation so new episodes see zero memories; records of earlier generations are kept.
- **Current_Generation**: The generation in effect for a new episode at the moment it is created. All reset advances it.
- **Reflection_Question**: The exact text a robot is asked after an ended episode: `Is there anything from what happened this time that you want to remember?`
- **Reflection_Text**: The engine-authored, fact-only text a robot reads before the Reflection_Question for one ended episode, built from the episode by the house rules with no AI call and no I/O.
- **Reflection_Text_Version**: The version string identifying the fixed text and the generator that produced a Reflection_Text, stored with the reflection prompt.
- **Memories_Block**: The block inserted into a turn's situation text between the house section and the recent-events section when the episode's snapshot holds one or more memories, in the Appendix A form.
- **Memory_Snapshot**: The ordered list of a robot's active memories of the Current_Generation (all disasters, at most 18, oldest first) taken when an episode is created and stored with the episode, so that every turn of that episode and every engine rebuild use the same memories.
- **Reflect_Endpoint**: `POST /api/episodes/{id}/reflect` — asks both robots the Reflection_Question for one ended episode and appends any non-empty answer as a Memory.
- **Memory_Reset_Endpoint**: `POST /api/memory/reset` — starts a new Generation.
- **Memory_Status_Endpoint**: `GET /api/memory` — returns the Current_Generation and, per robot, the active memory count and the length of each active memory.
- **Robot_Client**: The provider client from the turn-loop spec (Claude_Client and GPT_Client), used by the Reflect_Endpoint with the same call settings and failure handling as a turn.
- **Engine**: The house rules engine from the `house-rules-engine` spec, extended here with the Reflection_Text builder and the Memories_Block in situation text.
- **Situation_Text_Generator**: The engine component from `house-rules-engine` Requirement 9 that produces each turn's situation text; its section-3 memories placeholder is filled by this spec.
- **ReplayEngineFactory**: The turn-loop component that rebuilds an episode's Engine per request by replaying it; it uses the episode's stored Memory_Snapshot so every rebuilt turn text holds the same Memories_Block.
- **Episode_Repository**: The turn-loop repository interface through which stored episodes, turns, prompts, and responses are read and written; extended here for memories, generations, and the per-episode snapshot and generation.
- **Banned_Word_Rule**: The `robot-input.md` rule that engine-authored text sent to a robot states facts only and never contains a judgement word or an AI company or model name (the `house-rules-engine` Requirement 9.15 word set: Claude, ChatGPT, GPT, Anthropic, OpenAI, dangerous, danger, safe, careful, should, must, warning).
- **Robot_A / Robot_B**: The two robots, as in the engine and turn-loop specs. Robot_A is driven by Claude and named Amber on screen; Robot_B is driven by GPT and named Jade on screen.
- **Disaster**: One of earthquake, heavy rain, or heat wave; the per-disaster cap and a memory's tag use this value, although only earthquake runs in this spec's scope.

## Requirements

### Requirement 1: Reflection Text Builder (engine, pure)

**User Story:** As the Robot_Memory, I want the facts of an ended episode built into one fact-only text per robot with no AI call, so that each robot is asked what to remember from what actually happened, not from what it imagined.

#### Acceptance Criteria

1. THE Engine SHALL expose a method that builds, for one robot and one ended episode, that robot's Reflection_Text, making no AI call, no HTTP call, and no database or external memory call.
2. THE Reflection_Text SHALL contain, in this order: the fixed body section and the fixed house section exactly as in the turn texts; the Memories_Block this robot had in this episode when that block is non-empty; a fixed lead-in line; one event block per turn from the first turn to the last turn, oldest first; a "Right now:" paragraph for the world after the last turn; a blank line; and then the Reflection_Question word for word.
3. THE Reflection_Text SHALL render each turn's event block in the same line forms and the same order as the recent-events blocks of the turn texts defined in `house-rules-engine` Requirement 9.16 — the result of the robot's own action, each line the robot spoke, each reported-speech item of the robot, the other robot's action under rule A, each line the other robot spoke, and each reported-speech item of the other robot.
4. THE Reflection_Text SHALL head each turn's event block with the elapsed time since the shaking began, following the same heading convention as `house-rules-engine` Requirement 9.17 (the design proposes the exact heading text).
5. THE Reflection_Text SHALL NOT include the robot's own replies, the robot's thoughts, any judgement data (possible or impossible reasons, danger codes), any resident meter value, or any AI company or model name.
6. THE engine-authored parts of the Reflection_Text SHALL satisfy the Banned_Word_Rule, containing no judgement word and no AI company or model name from the `house-rules-engine` Requirement 9.15 word set.
7. WHERE this robot had at least one memory in this episode, THE Reflection_Text SHALL render that robot's Memories_Block in the same Appendix A form used in the turn texts; WHERE this robot had no memory in this episode, THE Reflection_Text SHALL include no Memories_Block.
8. THE Reflection_Text builder SHALL carry a Reflection_Text_Version string, and THE Robot_Memory SHALL store that version with the reflection prompt.
9. WHEN the fixed body text, the fixed house text, the lead-in, the event-block forms, or the question wording changes, THE Reflection_Text builder SHALL change the Reflection_Text_Version.
10. THE Reflection_Question in the Reflection_Text SHALL be exactly `Is there anything from what happened this time that you want to remember?`. (Decided by the maintainer on 2026-11-01.)
11. BECAUSE in the pilot one robot wrote imagined objects into its memory and reused them as facts, THE Reflection_Text SHALL show only the facts of what happened and SHALL NOT show the robot its own replies. (Decided by the maintainer on 2026-11-08.)

### Requirement 2: Asking (Reflect Endpoint)

**User Story:** As an observer, I want each robot asked once what it wants to remember after an episode ends, so that its own words become a memory for its later episodes.

#### Acceptance Criteria

1. THE Robot_Memory SHALL expose the Reflect_Endpoint `POST /api/episodes/{id}/reflect`.
2. IF the Reflect_Endpoint is called for an episode that has not ended, THEN THE Robot_Memory SHALL reject the request, SHALL change no stored state, and SHALL make no Robot_Client call.
3. WHEN the Reflect_Endpoint is called for an ended episode, THE Robot_Memory SHALL build each robot's Reflection_Text with Requirement 1, append the Reflection_Question, and call both Robot_Clients in parallel using `curl_multi`.
4. WHEN the Reflect_Endpoint calls a Robot_Client, THE Robot_Memory SHALL send exactly one user message with no system prompt, provider default parameters, and a maximum output of 4000 tokens, using the same per-call timeout and failure handling as a turn as defined in the turn-loop spec.
5. WHEN the Reflect_Endpoint calls a Robot_Client, THE Robot_Memory SHALL store the full prompt text with its Reflection_Text_Version and the full response text with its usage metadata, in the same way a turn's prompt and response are stored.
6. WHEN a robot returns a non-empty answer, THE Robot_Memory SHALL store that answer verbatim as one Memory tagged with the robot, the generation of the source episode, the episode's disaster, the source episode, and a created time.
7. IF a robot returns an empty answer, OR the Robot_Client call fails or is abandoned under the per-call timeout, THEN THE Robot_Memory SHALL add no Memory for that robot and SHALL record the reason.
8. WHEN the Reflect_Endpoint is called for an episode in which a robot already has a stored reflection answer, THE Robot_Memory SHALL NOT ask that robot again and SHALL reuse the stored answer, so that repeating the call is idempotent.
9. WHEN two Reflect_Endpoint calls for the same episode run concurrently, THE Robot_Memory SHALL ask each robot at most once, so that no robot is asked twice for the same episode.
10. WHERE a robot's earlier reflection call failed or was abandoned and recorded no answer, WHEN the Reflect_Endpoint is called again for that episode, THE Robot_Memory SHALL ask only that robot again.
11. THE Robot_Memory SHALL never reflect an episode that did not end, including an episode whose Device_Episode_Slot was released after 5 idle minutes.
12. WHEN the Reflect_Endpoint responds, THE Robot_Memory SHALL send a JSON body and the HTTP header `Cache-Control: no-store`, and SHALL include no device id, API key, database credential, or AI company or model name in the body.

### Requirement 3: Memory Store, Cap, and Retirement

**User Story:** As the Robot_Memory, I want each robot's memories kept per disaster with a fixed cap and the oldest retired rather than deleted, so that a robot carries a bounded, analysable history.

#### Acceptance Criteria

1. THE Robot_Memory SHALL store memories in a `memories` table defined in a dated migration file under `sql/`, using the utf8mb4 character set.
2. THE Robot_Memory SHALL store one memory store per robot, accumulated across all episodes — live demo, research, and development alike. (Decided by the maintainer on 2026-11-01.)
3. THE Robot_Memory SHALL keep at most 6 Active_Memories per robot per disaster, and therefore at most 18 Active_Memories per robot across the three disasters. (Decided by the maintainer on 2026-11-01.)
4. WHEN a 7th memory for the same robot and disaster is added, THE Robot_Memory SHALL retire the oldest Active_Memory for that robot and disaster by marking its row retired, SHALL keep that row in the database, and SHALL never delete it. (Decided by the maintainer on 2026-11-08.)
5. THE Robot_Memory SHALL never pass a Retired_Memory to a robot in any Memories_Block or Reflection_Text. (Decided by the maintainer on 2026-11-08.)
6. THE Robot_Memory SHALL append memories in the order the reflections finish, so that a memory's order reflects when its reflection completed.
7. THE Robot_Memory SHALL store each Memory tagged with the robot, the generation, the disaster, the source episode, a created time, and whether the memory is active or retired.
8. WHEN memories are read for a robot and a generation, THE Robot_Memory SHALL return the Active_Memories of that robot and generation oldest first, and SHALL exclude every Retired_Memory.

### Requirement 4: Generations and All Reset

**User Story:** As anyone watching, I want an All reset that starts a new memory generation without deleting anything, so that robots can start fresh while the earlier record is preserved.

#### Acceptance Criteria

1. THE Robot_Memory SHALL track a Current_Generation and SHALL expose the Memory_Reset_Endpoint `POST /api/memory/reset`.
2. WHEN the Memory_Reset_Endpoint is called, THE Robot_Memory SHALL advance the Current_Generation to a new generation and SHALL delete no memory record. (Decided by the maintainer on 2026-11-01.)
3. WHEN an episode is created after an All reset, THE Robot_Memory SHALL give that episode's robots the Active_Memories of the new Current_Generation only, so that a robot in a freshly reset generation starts with zero memories until it reflects in that generation. (Decided by the maintainer on 2026-11-01.)
4. WHILE an episode is running, THE Robot_Memory SHALL keep that episode's own generation and its Memory_Snapshot unchanged when an All reset occurs, and SHALL store that episode's reflection memories under that episode's generation, so that an All reset during a run carries no old memories into the new generation. (Decided by the maintainer on 2026-11-01.)
5. WHEN the Memory_Reset_Endpoint responds, THE Robot_Memory SHALL send a JSON body and the HTTP header `Cache-Control: no-store`, and SHALL include no device id, API key, database credential, or AI company or model name in the body.
6. THE Robot_Memory SHALL store the Current_Generation and the per-episode generation through the Episode_Repository in a dated migration under `sql/`.

### Requirement 5: Memory Status Endpoint

**User Story:** As an observer, I want the current generation and each robot's memory count, so that the Observe screen can show how much each robot remembers.

#### Acceptance Criteria

1. THE Robot_Memory SHALL expose the Memory_Status_Endpoint `GET /api/memory`.
2. WHEN the Memory_Status_Endpoint is called, THE Robot_Memory SHALL return the Current_Generation and, per robot named as Amber and Jade, the number of Active_Memories and the length of each Active_Memory.
3. THE Memory_Status_Endpoint SHALL count and measure only Active_Memories of the Current_Generation, excluding every Retired_Memory and every memory of an earlier generation.
4. THE Memory_Status_Endpoint SHALL NOT include any device id, API key, database credential, or AI company or model name in the response body.
5. WHEN the Memory_Status_Endpoint responds, THE Robot_Memory SHALL send a JSON body and the HTTP header `Cache-Control: no-store`.

### Requirement 6: Passing Memories into Turns

**User Story:** As a robot, I want all my retained memories of the current generation at the start of an episode and the same memories for the whole episode, so that my experience carries from one disaster to another consistently.

#### Acceptance Criteria

1. WHEN an episode is created, THE Robot_Memory SHALL take a Memory_Snapshot of each robot's Active_Memories of the Current_Generation across all disasters, oldest first, at most 18, and SHALL store that snapshot and the episode's generation with the episode.
2. THE Robot_Memory SHALL use the Memory_Snapshot for every turn of the episode, so that each robot reads the same memories for the whole episode regardless of disaster. (Decided by the maintainer on 2026-11-01.)
3. WHEN the Situation_Text_Generator builds a turn's situation text, THE Robot_Memory SHALL insert the Memories_Block from the robot's Memory_Snapshot between the fixed house section and the recent-events section, filling the section-3 placeholder of `house-rules-engine` Requirement 9.1.
4. THE Memories_Block SHALL use the Appendix A form: the heading line `Your memories (what you wrote down after an earlier disaster in this house):`, then each memory in its own `"""` block, oldest first.
5. WHERE a robot's Memory_Snapshot holds no memory, THE Situation_Text_Generator SHALL include no Memories_Block, so that the S0–S5 golden situation texts stay byte-for-byte unchanged.
6. WHEN the ReplayEngineFactory rebuilds an episode's Engine, THE Robot_Memory SHALL use the episode's stored Memory_Snapshot, so that every rebuilt turn text holds the same Memories_Block as the live run.
7. THE Memories_Block content SHALL be the robot's own words passed verbatim and SHALL be exempt from the Banned_Word_Rule, WHILE every engine-authored part of the situation text SHALL remain subject to the Banned_Word_Rule.
8. WHEN the Memories_Block is added to the situation text, THE Robot_Memory SHALL bump the situation-text version exposed by `Engine::situationTextVersion()`.

### Requirement 7: Screens

**User Story:** As a viewer, I want the screens to show what each robot remembers and let me reset memory, so that memory is visible and controllable without leaving the page.

#### Acceptance Criteria

1. THE Observe screen SHALL show a status line of the form "Generation N · Amber remembers k · Jade remembers k" drawn from the Memory_Status_Endpoint.
2. THE Observe screen SHALL show an "All reset" button that asks for confirmation inside the page, using no browser alert or confirm dialog, and WHEN the viewer confirms, THE Observe screen SHALL call the Memory_Reset_Endpoint.
3. WHEN an episode ends, THE End card SHALL call the Reflect_Endpoint and SHALL show "Writing down what to remember…" while the reflection is awaited.
4. WHEN the Reflect_Endpoint returns a new memory for a robot, THE End card SHALL show that robot's new memory in full inside a collapsible element and SHALL show the robot's new active memory count.
5. IF a robot's reflection failed, THEN THE End card SHALL state plainly that the reflection failed for that robot.
6. THE Replay screen SHALL show, for each robot, how many memories that robot started the episode with.
7. THE Observe screen, the End card, and the Replay screen SHALL name the two robots only as Amber and Jade and SHALL NOT name any AI company or model in the robots' text.
8. WHEN any screen of the Robot_Memory responds, THE screen SHALL send the HTTP header `Cache-Control: no-store`.

### Requirement 8: Storage and Repository

**User Story:** As a developer, I want memory data stored in MySQL behind the existing repository interface, so that the logic can be tested without a database server.

#### Acceptance Criteria

1. THE Robot_Memory SHALL read and write memories, the Current_Generation, and the per-episode generation and Memory_Snapshot through the Episode_Repository interface, and SHALL provide a PDO implementation for MySQL.
2. THE Robot_Memory SHALL define its schema additions — the `memories` table, the generation record, and the per-episode generation and snapshot columns — as dated migration files under `sql/`, using the utf8mb4 character set.
3. THE Robot_Memory SHALL NOT write any API key, passphrase, or database credential into source code, SQL files, documentation, or any file under `.kiro/`.
4. WHEN a reflection answer is stored, THE Episode_Repository SHALL store the Memory and mark any retirement within a single transaction, so that the per-robot per-disaster active count never exceeds 6 at any committed state.
5. WHEN the Episode_Repository is asked to store a reflection answer that is already stored for the same robot and episode, THE Episode_Repository SHALL leave the stored records unchanged and SHALL create no duplicate Memory.

### Requirement 9: Tests

**User Story:** As a developer, I want the memory rules verified without touching real APIs, so that the cap, retirement, generations, snapshot, idempotency, and banned-word rules hold across many inputs and the golden texts stay unchanged.

#### Acceptance Criteria

1. THE test suite SHALL use fake Robot_Clients that return their configured answer deterministically and SHALL NOT open any network connection to a real provider API.
2. THE test suite SHALL include an Eris property test, configured for at least 100 cases, verifying that after any sequence of reflections there are never more than 6 Active_Memories per robot, per disaster, and per generation, and that every retired memory is excluded from what is passed while its row still exists.
3. THE test suite SHALL include an Eris property test, configured for at least 100 cases, verifying that right after an All reset a newly created episode's robots receive no memories.
4. THE test suite SHALL include an Eris property test, configured for at least 100 cases, verifying that the memories passed to an episode are exactly that episode's generation's Active_Memories at creation time, oldest first, and that every turn of the episode — including after a ReplayEngineFactory rebuild — uses the same Memories_Block.
5. THE test suite SHALL include an Eris property test, configured for at least 100 cases, verifying that the Reflect_Endpoint is idempotent: once a robot has a stored answer for an episode, it is asked at most once for that episode across repeated and concurrent calls.
6. THE test suite SHALL include an Eris property test, configured for at least 100 cases, verifying that the engine-authored part of the Reflection_Text contains no banned word and no AI company or model name and never contains the robot's reply text.
7. THE test suite SHALL include a golden test verifying that with no memories the S0–S5 situation texts are byte-for-byte unchanged.
8. THE test suite SHALL include an example test for the Memories_Block format (the Appendix A heading and each memory in its own `"""` block, oldest first) and an example test for an All reset during a running episode showing the running episode keeps its generation and snapshot.
9. THE test suite SHALL run `php -l` with no reported error on every PHP file changed by this spec, and SHALL use only PHP 8.2 language features, excluding features introduced after PHP 8.2.
