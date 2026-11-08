# Implementation Plan: Robot Memory

## Overview

Build the robot-memory feature — the fourth spec of PoltergeistSwitch, layered on `house-rules-engine`, `turn-loop`, and `screens`. Each robot gains a memory that accumulates across every episode it lives through: after an ended episode each robot is asked once, in its own words, what it wants to remember, and a non-empty answer becomes a `Memory`; at the start of every later episode the robot receives all its retained memories of the current generation, oldest first, in a fixed `Memories_Block` inside each turn's situation text.

The work is strictly ordered so the PHP side is fully built and tested before the screens: autoload/scaffold → the SQL migration file → value objects → the `EpisodeRepository` additions (PDO + in-memory fake) → the pure `ReflectionTextBuilder` → the `SituationTextGenerator`/`Engine` memories-block change → `MemoryService` → `ReflectService` → the `api/` endpoints and the extended create endpoint → the three screens last. Every task is commit-sized, test-first, and ends with its tests passing; no task depends on code that does not yet exist.

Reuse, not duplication: the turn-loop `RobotClient`/`FakeRobotClient`, `ParallelHttp`, `EngineFactory`/`ReplayEngineFactory`, `Config`, the `EpisodeRepository` interface with its `PdoEpisodeRepository` and `InMemoryEpisodeRepository`, and the `prompts`/`responses` storage shape are reused unchanged except for the explicit additions here. The turn rules, `applyTurn`, and the recorder are not changed.

Stack: PHP 8.2 (no features added after 8.2 — no typed class constants, `json_validate()`, `#[\Override]`, property hooks, asymmetric visibility, or the pipe operator), PHPUnit 11, Eris ^1.3 property tests with `->limitTo(100)`, plain classes under `src/Memory/` and the two engine additions under `src/Engine/`, thin entry points in `api/`, the migration in `sql/`, tests in `tests/Memory/` and `tests/Engine/`. No real provider API is ever called in a test: all reflect tests use the turn-loop `FakeRobotClient`. Every API response and every page sends `Cache-Control: no-store`. No API key, passphrase, database credential, secrets path, or AI company/model name ever appears in code, SQL, docs, `.kiro/`, or any response body or page output.

**The migration is a FILE only.** Task 2 writes `sql/2026-11-09_add_memory.sql` and is validated by static checks and a test that the file exists, declares utf8mb4, and contains the expected tables and columns. **No task runs SQL against a real database** — the maintainer applies the migration on the server over FTPS after deployment. `PdoEpisodeRepository` locking/atomicity tests that need MySQL are written to SKIP when no MySQL test database is available; the in-memory fake carries the logic coverage (mirroring the turn-loop spec).

---

## Tasks

- [x] 1. Composer/autoload and test scaffold for the Memory namespace
  - Extend the existing `composer.json` autoload with PSR-4 `"PoltergeistSwitch\\Memory\\" => "src/Memory/"` (the `PoltergeistSwitch\Engine` and `PoltergeistSwitch\TurnLoop` namespaces are already built and consumed unchanged)
  - Add `'PoltergeistSwitch\\Memory\\' => __DIR__ . '/../src/Memory/'` to the prefix list of the hand-written PSR-4 autoloader in `api/bootstrap.php` (the list that currently maps `PoltergeistSwitch\Engine` and `PoltergeistSwitch\TurnLoop` only), so the memory endpoints resolve on the server where there is no Composer autoloader and do not return HTTP 500
  - Confirm `autoload-dev` PSR-4 covers `tests/Memory/` and `tests/Engine/` (via the existing `"PoltergeistSwitch\\Tests\\" => "tests/"` mapping)
  - Create the directory skeleton: `src/Memory/`, `src/Memory/Values/`, `tests/Memory/`, `tests/Memory/Properties/`, `tests/Engine/Properties/`
  - Run `composer dump-autoload` and confirm a green (zero new tests) PHPUnit run; `php -l` clean on any touched PHP
  - _Requirements: none (infrastructure only)_

  - [x] 1.1 Write a guard test in `tests/Memory/BootstrapAutoloaderTest.php` asserting that every top-level namespace directory under `src/` is registered in the hand-written autoloader prefix list in `api/bootstrap.php`: for each directory `<Dir>` under `src/`, the prefix `PoltergeistSwitch\<Dir>\` maps to that directory in `api/bootstrap.php`, so a new namespace (such as Memory) can never ship without its server-side mapping and the memory endpoints never 500 on the server; no SQL and no network is involved
    - _Requirements: none (infrastructure only)_

- [x] 2. SQL migration FILE for memory, generations, reflection slots, snapshot columns, and prompt/response kind
  - [x] 2.1 Write `sql/2026-11-09_add_memory.sql` (file only — never run against a database; the maintainer applies it on the server):
    - `memories` table (id BIGINT auto-increment doubling as finish-order seq, robot_id, generation, disaster, source_episode_id, body MEDIUMTEXT, status active|retired, created_at, retired_at; `idx_active_pick (robot_id, disaster, generation, status, id)`, `idx_snapshot (robot_id, generation, status, id)`, `UNIQUE KEY uq_source (source_episode_id, robot_id)`)
    - `memory_generation` single-row table (id TINYINT, generation default 1, updated_at) seeded with generation 1
    - `reflections` table (episode_id, robot_id, status in_progress|answered|failed, answered_memory_id, reason, claimed_at; PK `(episode_id, robot_id)`)
    - `ALTER TABLE episodes` add `memory_generation` NOT NULL DEFAULT 1, `robot_a_memory_snapshot` MEDIUMTEXT NULL, `robot_b_memory_snapshot` MEDIUMTEXT NULL (a NULL snapshot marks a pre-feature episode)
    - `ALTER TABLE prompts` and `ALTER TABLE responses` add `kind` VARCHAR(12) NOT NULL DEFAULT 'turn'
    - InnoDB, `utf8mb4`/`utf8mb4_unicode_ci` throughout; no `CHECK` reliance; no secret, credential, or provider name anywhere in the file
    - _Requirements: 3.1, 3.7, 4.6, 6.1, 8.2, 8.3, 2.5_
  - [x] 2.2 Write a static-check test in `tests/Memory/MigrationFileTest.php` asserting the file exists, declares `utf8mb4`, and contains the `memories`, `memory_generation`, and `reflections` tables, the three `episodes` columns, and the `kind` column on `prompts` and `responses`; no SQL is executed
    - _Requirements: 3.1, 8.2, 2.5_

- [x] 3. Memory value objects and row DTOs
  - [x] 3.1 Implement the value objects in `src/Memory/Values/`:
    - `MemoryRow` (readonly: robotId, generation, disaster, sourceEpisodeId, body, status, createdAt, nullable seq, nullable retiredAt)
    - `MemorySnapshot` (readonly: `bodies` string[] oldest first; `isEmpty()`)
    - `ReflectionResult` (readonly: `robots` keyed `robot_a`/`robot_b`) and `RobotReflection` (readonly: answered, nullable memory, activeCount, nullable reason)
    - `MemoryStatus` (readonly: generation, amberActiveCount, amberLengths int[], jadeActiveCount, jadeLengths int[])
    - `ReflectionClaim` (the claim outcome: `claimed` | `reuse` | `skip`, plus the reused body when `reuse`)
    - _Requirements: 2.6, 3.7, 5.2, 6.1_
  - [x]* 3.2 Write unit tests in `tests/Memory/Values/` for round-trip construction of each value object; `MemorySnapshot::isEmpty()`; `RobotReflection` carries a reason only when not answered
    - _Requirements: 2.6, 3.7, 5.2_

- [x] 4. EpisodeRepository additions — interface, in-memory fake, and PDO implementation
  - [x] 4.1 Extend the turn-loop `EpisodeRepository` interface with the memory methods:
    - generations (`currentGeneration`, `advanceGeneration`), per-episode snapshot + generation (`storeEpisodeMemory`, `episodeGeneration`, `episodeSnapshot`), memory reads (`activeMemories`, `activeCount`), the atomic cap/retire write (`appendMemory`), and the reflection slot claim/commit (`claimReflectionSlot`, `commitReflectionAnswer`, `commitReflectionFailure`, `reflectionAnswer`)
    - _Requirements: 8.1_
  - [x] 4.2 Implement the additions in `InMemoryEpisodeRepository` (the turn-loop test fake):
    - active-memory cap of 6 per `(robot, disaster, generation)` enforced on `appendMemory` by retiring every active row past the 6 newest (retire-down-to-6, oldest first by finish-order seq), never deleting a retired row; memories appended in finish order; `activeMemories` returns active rows of `(robot, generation)` across all disasters oldest first, capped at 18, excluding retired and other-generation rows
    - the claim semantics matching the `SELECT … FOR UPDATE` design: first claim wins `in_progress`; a fresh `in_progress` (within 120 s) returns `skip`; `answered` returns `reuse` with the stored body; a `failed` or stale (`claimed_at` older than 120 s) `in_progress` is re-claimable; `appendMemory` idempotent on `(sourceEpisodeId, robotId)`; interleaved commits for the same triple serialize so the committed active count never exceeds 6
    - the store is one memory store per robot accumulated across all episodes (live demo, research, and development alike), keyed only by robot/disaster/generation and never by run kind
    - _Requirements: 3.2, 3.3, 3.4, 3.5, 3.6, 3.8, 6.1, 8.4, 8.5, 2.8, 2.9, 2.10_
  - [x] 4.3 Implement the additions in `PdoEpisodeRepository` (PDO/MySQL):
    - `appendMemory` runs in one transaction that first takes a locking read (`SELECT … FOR UPDATE`) over the `(robot, disaster, generation)` active rows, inserts the new memory, then retires every active row past the 6 newest (`ORDER BY id ASC … OFFSET 6`), guarded idempotent by `uq_source`; `claimReflectionSlot` uses `INSERT … ON DUPLICATE KEY` then `SELECT … FOR UPDATE` in a short transaction with the 120 s stale recovery; commit-answer and commit-failure are short transactions; generation read/advance; snapshot store/read as JSON text decoded with `json_decode(..., flags: JSON_THROW_ON_ERROR)`; a malformed snapshot is treated as empty
    - every existing turn read (`ReplayEngineFactory`, the episode view, the debug page) is filtered on `kind = 'turn'` and never queries `turn_number = -1`; reflection rows are written with `kind = 'reflection'` and `turn_number = -1`; no `SKIP LOCKED`
    - _Requirements: 8.1, 8.4, 8.5, 2.5, 2.8, 2.9, 2.10, 3.4, 4.1, 4.2, 6.6_
  - [x] 4.4 Write the cap/retirement property test in `tests/Memory/Properties/CapRetirementPropertyTest.php`:
    - **Property 1: Cap, retirement, and read-ordering** — for any sequence of appends across robots, disasters, and generations (including two interleaved commits for the same `(robot, disaster, generation)` from two different episodes), the active count per triple is at most 6 at every committed state; adding a 7th retires the oldest active of that triple while its row remains and no other-generation memory is retired; a retired memory never appears in any read; `activeMemories` returns oldest first across all disasters excluding retired and other-generation rows
    - **Property 2: Memories round-trip verbatim with their tags in finish order** — storing a non-empty answer appends exactly one memory whose body equals the answer byte-for-byte with matching robot, generation, disaster, source episode, and created time, read back in finish order
    - Each tagged `Feature: robot-memory, Property N: …`, `Eris\TestTrait`, `->limitTo(100)`
    - _Requirements: 9.2, 3.3, 3.4, 3.5, 3.6, 3.8, 8.4, 2.6, 3.7_
  - [x]* 4.5 Write unit tests in `tests/Memory/Repository/EpisodeRepositoryTest.php` against the in-memory fake (required behaviour), with the `PdoEpisodeRepository` locking/atomicity tests written to SKIP when no MySQL test database is available:
    - the atomic store commits only after insert + retire; a duplicate store for the same source episode + robot creates no duplicate; a stale `in_progress` (older than 120 s) is re-claimable while a fresh one returns `skip`; the PDO two-connection test asserts a second `claimReflectionSlot` blocks then reads committed state and two per-triple commits serialize to exactly 6 active
    - _Requirements: 8.4, 8.5, 2.8, 2.9, 2.10_

- [x] 5. ReflectionTextBuilder (pure engine)
  - [x] 5.1 Implement `ReflectionTextBuilder` in `src/Engine/ReflectionTextBuilder.php`:
    - a pure class taking a robot id, the episode's ordered `TurnRecord`s (first played turn to last), the earthquake start turn, the post-episode `WorldModel`, and the robot's `MemorySnapshot`; makes no AI, HTTP, or database/external-memory call
    - assembles, in order separated by blank lines: the fixed body section, the fixed house section (reused from `SituationTextGenerator`), the `Memories_Block` (Appendix A form) only when the snapshot is non-empty, the fixed lead-in `Here is what happened after the shaking began:`, one event block per played turn oldest first, the `Right now:` paragraph for the world after the last turn, a blank line, then the exact question `Is there anything from what happened this time that you want to remember?`
    - each event block headed `{15·k} to {15·(k+1)} seconds after the shaking began:` counting up from the first played/shaking turn (k = 0 for turn 1); within a block perceived items first (the man's line rendered per Req 9.10–9.11, then the house events rendered per Req 9.14) then acted items (own action result, own spoken lines `You said, "…"`, own reported speech `You {ownForm}.`, then under rule A the other robot's action, its spoken lines, its reported speech `You heard the other robot {heardAs}.`); a block with no lines shows the neutral line `Nothing happened that you noticed.`; never repeats the static room-object list per turn
    - excludes the robot's own reply text, thoughts, judgement data, resident meter values, and any AI company/model name; engine-authored parts satisfy the banned-word rule (Req 9.15 word set) while memory bodies and quoted speech are exempt
    - `const REFLECTION_TEXT_VERSION = 'reflection-text-2026-11-09';` (plain PHP 8.2 class constant, within 32 chars) returned by `version()`, stored with the reflection prompt; bump it whenever the fixed body/house text, the lead-in, an event-block form, or the question changes
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 1.8, 1.9, 1.10, 1.11_
  - [x] 5.2 Write the banned-word / no-reply-text and structure property tests in `tests/Engine/Properties/`:
    - **Property 6: Engine-authored reflection and turn text is banned-word clean and never contains the robot's reply, while memory bodies are exempt** — for any ended episode with arbitrary reply strings and arbitrary memory bodies, the engine-authored parts of the Reflection_Text (and of each turn's situation text) contain no banned word and no AI company/model name and never contain the robot's own reply text; a banned word inside a memory body does not fail the check, one in an engine-authored part does
    - **Property 7: Reflection_Text structure, count-up headings, and memories-block presence** — sections appear in order (body, house, memories block iff the robot had memories this episode, the exact lead-in, one event block per played turn oldest first with the count-up headings, the `Right now:` paragraph, a blank line, then the exact question); perceived items precede acted items in each block; the memories block when present uses the Appendix A heading with each body in its own `"""` block oldest first
    - Each tagged `Feature: robot-memory, Property N: …`, `->limitTo(100)` (required, not optional)
    - _Requirements: 9.6, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 1.10, 1.11_
  - [x] 5.3 Write a golden test in `tests/Engine/ReflectionTextVersionTest.php` asserting `ReflectionTextBuilder::REFLECTION_TEXT_VERSION === 'reflection-text-2026-11-09'` so any change to the fixed text, lead-in, event-block forms, or question forces a conscious bump (required, not optional)
    - _Requirements: 1.8, 1.9_
  - [x]* 5.4 Write example tests in `tests/Engine/ReflectionTextBuilderTest.php` for the exact wording not covered universally: the forward count-up heading literals (turn 1 = `0 to 15 seconds after the shaking began:`), the exact lead-in and question literals, a turn event block that includes the man's line heard and the house events perceived (shaking start/continue/stop, lights out, objects falling/breaking where visible) with no per-turn object list repeated, and the `Nothing happened that you noticed.` neutral line
    - _Requirements: 1.2, 1.3, 1.4, 1.10, 9.7_

- [x] 6. Thread memories into turn texts — SituationTextGenerator / Engine
  - [x] 6.1 Implement the memories-block threading in `src/Engine/`:
    - add `Engine::setMemories(array $memoriesByRobot)` (`['robot_a' => string[], 'robot_b' => string[]]`); `Engine::situationTextFor` builds the `$memoriesBlock` from that robot's list and passes it to `SituationTextGenerator::generate`; when `setMemories` is not called or an empty list is set, pass `null` so no block appears and behaviour is unchanged
    - `SituationTextGenerator` fills its section-3 placeholder with the `Memories_Block` in the Appendix A form (heading `Your memories (what you wrote down after an earlier disaster in this house):`, then each body in its own `"""` block oldest first, verbatim), inserted between the fixed house section and the recent-events section; memory bodies are exempt from the banned-word check while every engine-authored part stays checked
    - bump the situation-text version constant on `SituationTextGenerator` from `situation-text-2026-11-08c` to `situation-text-2026-11-09` (within 32 chars), exposed by `Engine::situationTextVersion()`
    - `ReplayEngineFactory::forEpisode` reads the episode's stored snapshots and calls `Engine::setMemories` before any `situationTextFor`, so a rebuild uses the frozen verbatim bodies and matches the live run byte-for-byte
    - _Requirements: 6.2, 6.3, 6.4, 6.5, 6.6, 6.7, 6.8_
  - [x] 6.2 Write the snapshot/rebuild-equality property test in `tests/Memory/Properties/SnapshotEqualityPropertyTest.php`:
    - **Property 4: The snapshot is the generation's active memories, and every turn — live and rebuilt — uses the same block** — each robot's stored snapshot equals its current-generation active bodies across all disasters, oldest first, capped at 18; every turn renders the same `Memories_Block`; an `Engine` rebuilt by `ReplayEngineFactory` from the stored snapshot produces turn text byte-identical to the live run for both robots
    - Tagged `Feature: robot-memory, Property 4: …`, `->limitTo(100)` (required, not optional)
    - _Requirements: 9.4, 6.1, 6.2, 6.6_
  - [x] 6.3 Write the golden tests in `tests/Engine/SituationTextMemoriesBlockTest.php`:
    - with no memories the S0–S5 situation texts are byte-for-byte unchanged (required, not optional)
    - `Engine::situationTextVersion()` equals `'situation-text-2026-11-09'` so adding the block forces a conscious bump (required, not optional)
    - _Requirements: 9.8, 6.5, 6.8_
  - [x]* 6.4 Write an example test for the Appendix A `Memories_Block` format and its position between the house and recent-events sections (heading, each body in its own `"""` block, oldest first, the exact separators)
    - _Requirements: 6.3, 6.4, 9.9_

- [ ] 7. MemoryService — snapshot, reset, status
  - [x] 7.1 Implement `MemoryService` in `src/Memory/MemoryService.php`:
    - `snapshotForNewEpisode($episodeId)`: read the current generation, read each robot's active-memory bodies of that generation across all disasters oldest first capped at 18, store the two ordered lists and the generation with the episode (called inside `createEpisode`, reusing the same insert transaction)
    - `reset()`: advance the current generation to a new higher integer, delete no memory row; returns a `MemoryStatus`
    - `status()`: return the current generation and, per robot reported as Amber (robot_a) / Jade (robot_b), the active-memory count and each active memory's length, excluding retired and other-generation rows; return no device id, key, credential, or company/model name
    - a running episode keeps its own stored generation and snapshot when a reset occurs, and its later reflections store under its own generation
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 5.2, 5.3, 6.1_
  - [x] 7.2 Write the reset and status property tests in `tests/Memory/Properties/`:
    - **Property 3: Reset starts a fresh generation with zero memories and deletes nothing** — reset advances the current generation and removes no row; an episode created immediately after receives an empty snapshot for both robots
    - **Property 8: Status reports exactly the current generation's active counts and lengths** — `status()` reports the current generation and per-robot the count and character lengths of current-generation active memories oldest first, excluding retired and older-generation rows
    - Each tagged `Feature: robot-memory, Property N: …`, `->limitTo(100)` (required, not optional)
    - _Requirements: 9.3, 4.2, 4.3, 5.2, 5.3_
  - [x]* 7.3 Write an example test in `tests/Memory/MemoryServiceTest.php` for a reset during a running episode: the running episode keeps its generation and snapshot and its later reflection is stored under its own generation
    - _Requirements: 4.4, 9.9_

- [ ] 8. ReflectService — asking, idempotency, storing answers
  - [x] 8.1 Implement `ReflectService` in `src/Memory/ReflectService.php` (reuses the turn-loop `RobotClient`, `ParallelHttp`, `EngineFactory`, `Config`; holds no rules logic):
    - guard: load the episode; 404 if unknown; 409 `not_ended` if it has not ended (including an episode released after 5 idle minutes — it is not marked ended), changing no state and making no `RobotClient` call; 409 `not_reflectable` for a pre-feature episode whose snapshot columns are NULL, changing no state and making no `RobotClient` call
    - rebuild the episode via `EngineFactory::forEpisode` (which now sets memories from the stored snapshot) to obtain the ordered `TurnRecord`s, the earthquake start turn, and the final `WorldModel`
    - per robot claim a slot (`claimReflectionSlot`): `answered` → reuse the stored answer and make no call; a fresh `in_progress` → skip (not double-asked); unclaimed/failed/stale → claim and call
    - build each newly claimed robot's Reflection_Text with `ReflectionTextBuilder::build` and send the texts as-is (the text already ends with the question — not appended again) to the claimed robots in parallel through one `curl_multi` handle, with one user message, no system prompt, provider default parameters, max output 4000 tokens, and the same per-call timeout and failure handling as a turn
    - store each called robot's full prompt with `REFLECTION_TEXT_VERSION` and full response with its usage metadata in the `prompts`/`responses` tables tagged `kind = 'reflection'` and turn number `-1`
    - commit: a non-empty answer is stored verbatim as one `Memory` tagged robot/generation/disaster/source-episode/created-time with the cap/retirement applied in the same transaction and the slot marked `answered`; an empty answer or a failed/abandoned call adds no memory, records a short reason (`empty_answer`, `no_reply`, `timeout`), and releases the slot to `failed` for a later retry
    - return a `ReflectionResult` per robot (answered with body and new active count, or failed with a short reason); no device id, key, credential, or company/model name in the result
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.7, 2.8, 2.9, 2.10, 2.11, 8.4, 8.5_
  - [~] 8.2 Write the reflect-idempotency property test in `tests/Memory/Properties/ReflectIdempotencyPropertyTest.php`:
    - **Property 5: Reflect is idempotent per robot per episode** — for any sequence of reflect calls on an ended episode (repeated or interleaved to model concurrency against the fake), each robot is asked at most once once it has a stored answer, a robot whose earlier call failed may be asked again, and storing an already-stored answer for the same episode and robot creates no duplicate memory; the `FakeRobotClient` invocation count asserts "at most once per robot per episode"
    - Tagged `Feature: robot-memory, Property 5: …`, `->limitTo(100)` (required, not optional)
    - _Requirements: 9.5, 2.8, 2.9, 2.10, 8.5_
  - [ ]* 8.3 Write example tests in `tests/Memory/ReflectServiceTest.php` using fakes: the not-ended / idle-released guard (409 `not_ended`, no `RobotClient` call); the pre-feature `not_reflectable` guard (NULL snapshot, no `RobotClient` call); the retry path after a failed robot asks only that robot again; the stale-claim re-claim path (an `in_progress` older than 120 s is re-claimable, a fresh one skipped); the empty-answer vs failed-call reason paths; the reflect client invoked with one user message, no system prompt, max 4000 tokens under the turn-loop timeout
    - _Requirements: 2.2, 2.4, 2.7, 2.10, 2.11_

- [ ] 9. API entry points and the extended create endpoint
  - [x] 9.1 Implement the three `api/` entry points, extend create, and add routes:
    - `api/episodes_reflect.php` (`POST /api/episodes/{id}/reflect`) → `ReflectService::reflect`, 200 per-robot result, 404 unknown, 409 `not_ended` / `not_reflectable`, body shape from the design (`amber`/`jade` with answered, memory, activeCount, reason)
    - `api/memory_reset.php` (`POST /api/memory/reset`) → `MemoryService::reset`, 200 `{ "generation": N }`
    - `api/memory_get.php` (`GET /api/memory`) → `MemoryService::status`, 200 generation + per-robot counts/lengths
    - extend `api/episodes_create.php` to call `MemoryService::snapshotForNewEpisode($episodeId)` inside the create flow so every new episode carries a snapshot and a generation; add the three routes to `router.php`
    - each entry point only parses the request, calls one service method, writes JSON, and sends `Cache-Control: no-store`; no logic in `api/`; no device id, key, credential, or company/model name in any body
    - _Requirements: 2.1, 2.12, 4.1, 4.5, 5.1, 5.4, 5.5, 6.1_
  - [~] 9.2 Write the no-leak and no-store property tests in `tests/Memory/Properties/`:
    - **Property 9: No memory response leaks a secret, device id, or provider name** — for any reflect, reset, or status response the serialized body contains none of the configured API keys, database credentials, the secrets path, or any device id, and names neither AI provider nor model
    - **Property 10: Every memory response is JSON and uncacheable** — for any request to reflect, reset, or status the response body parses as JSON and carries `Cache-Control: no-store`
    - Each tagged `Feature: robot-memory, Property N: …`, `->limitTo(100)` (required, not optional)
    - _Requirements: 2.12, 4.5, 5.4, 5.5_
  - [ ]* 9.3 Write a smoke/integration test in `tests/Memory/ApiRoutingTest.php` with a fake-backed service: the three routes resolve to their service methods; a reflection prompt/response is stored with `kind = 'reflection'` and turn number `-1` and no existing turn read (`kind = 'turn'`, turn numbers `0 … n`) returns it; a repository scan finds no secret literal in the files this spec changes
    - _Requirements: 2.1, 2.5, 4.1, 5.1, 8.3_

- [~] 10. Checkpoint — PHP side complete
  - Ensure all tests pass and `php -l` is clean on every changed PHP file; ask the maintainer if questions arise before starting the screens.

- [ ] 11. Observe screen — status line, All reset, missed-reflection catch-up
  - [~] 11.1 Extend `public/index.php`, `public/js/observe.js`, add `public/js/memory-ui.js`, and `public/css/screens.css`:
    - on load and after each reset, call `GET /api/memory` and render `Generation N · Amber remembers k · Jade remembers k` via a shared `memory-ui.js` helper
    - an "All reset" button reveals an in-page confirmation panel (Confirm/Cancel, no browser `alert`/`confirm`); on Confirm call `POST /api/memory/reset` and refresh the status line with the returned generation
    - on load, if this device's last episode has ended but is not yet reflected, call `POST /api/episodes/{id}/reflect` once (safe under idempotency); ignore a `not_reflectable` response
    - name the robots only Amber and Jade, never a company or model; the page sends `Cache-Control: no-store`
    - _Requirements: 7.1, 7.2, 7.7, 7.8, 2.8, 2.9_
  - [ ]* 11.2 Write a rendering test / assertion for the status-line format and the in-page confirm panel (no browser dialog) in the project's JS/PHP test harness
    - _Requirements: 7.1, 7.2_

- [ ] 12. End card — reflect and show, empty-vs-failed phrasing
  - [~] 12.1 Extend the Observe End_Card flow in `public/js/observe.js` + `public/js/memory-ui.js`:
    - when `apply` reports the episode ended, call `POST /api/episodes/{id}/reflect` and show `Writing down what to remember…` while awaited
    - when the response returns, for each robot that `answered` show its new memory in full inside a collapsible `<details>` with its new active count; for `answered: false` distinguish `empty_answer` (e.g. "Jade chose not to write anything down.") from `no_reply`/`timeout` (e.g. "Jade's reflection did not come back."), with no provider name in either
    - _Requirements: 7.3, 7.4, 7.5, 7.7, 7.8_
  - [ ]* 12.2 Write a rendering test for the End_Card: the awaiting text, the collapsible memory with its count, and the two distinct phrasings for empty-answer vs failed-call
    - _Requirements: 7.4, 7.5_

- [ ] 13. Replay screen — started-with counts
  - [~] 13.1 Extend `public/replay.php` and `public/js/replay.js`:
    - when a stored episode is loaded, show for each robot how many memories it started the episode with (the length of that robot's stored `Memory_Snapshot`, read from the episode view); the per-turn `Memories_Block` already appears as part of the stored situation text, so no extra work there
    - name the robots only Amber and Jade; the page sends `Cache-Control: no-store`
    - _Requirements: 7.6, 7.7, 7.8_
  - [ ]* 13.2 Write a rendering test asserting the per-robot started-with count equals the stored snapshot length
    - _Requirements: 7.6_

- [ ] 14. Final checkpoint — language level and full suite
  - [~] 14.1 Run `php -l` with no error on every PHP file changed by this spec and confirm only PHP 8.2 language features are used (no typed class constants, `json_validate()`, `#[\Override]`, property hooks, asymmetric visibility, or the pipe operator); run the full PHPUnit suite including every required property test (Properties 1–10) and the golden tests, and confirm they pass
    - _Requirements: 9.1, 9.10_

## Notes

- **(a) Required vs optional.** Every Eris property test (Properties 1–10, Req 9.2–9.6) and every golden test that guards the S0–S5 texts and the version-string literals is a non-optional sub-task. Only plain example/unit tests for exact wording, reason phrasing, and screen renderings are marked `*` and may be skipped for a faster MVP. Core implementation sub-tasks are never optional.
- **(b) No real network in tests.** All reflect tests use the turn-loop `FakeRobotClient`, which returns a configured answer deterministically and opens no connection; it counts invocations so Property 5 can assert "at most once per robot per episode". The store logic runs through `InMemoryEpisodeRepository`; `PdoEpisodeRepository` locking/atomicity tests SKIP when no MySQL test database is available.
- **(c) The migration is a file only.** Task 2 writes `sql/2026-11-09_add_memory.sql`; no task runs SQL against a real database. The maintainer applies the `memories`, `memory_generation`, and `reflections` tables and the `episodes` / `prompts` / `responses` alterations on the server over FTPS after deployment.
- **(d) PHP side before screens.** Tasks 1–10 build and test the whole PHP side; the screens (11–13) come after the checkpoint.
- **(e) Every response is uncacheable; no secrets or provider names.** Every API response and every page sends `Cache-Control: no-store`; no API key, passphrase, database credential, secrets path, or AI company/model name appears in code, SQL, docs, `.kiro/`, or any response body or page output. On screen the robots are only Amber and Jade.
- **(f) Version bumps are conscious.** `ReflectionTextBuilder::REFLECTION_TEXT_VERSION = 'reflection-text-2026-11-09'` and `Engine::situationTextVersion()` = `'situation-text-2026-11-09'` are guarded by golden tests so any change to the fixed text forces a deliberate bump; both literals fit the 32-character version column.

---

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1"] },
    { "id": 1, "tasks": ["2.1", "3.1"] },
    { "id": 2, "tasks": ["2.2", "3.2", "4.1", "5.1"] },
    { "id": 3, "tasks": ["4.2", "4.3", "5.2", "5.3", "5.4", "6.1"] },
    { "id": 4, "tasks": ["4.4", "4.5", "6.2", "6.3", "6.4", "7.1"] },
    { "id": 5, "tasks": ["7.2", "7.3", "8.1"] },
    { "id": 6, "tasks": ["8.2", "8.3", "9.1"] },
    { "id": 7, "tasks": ["9.2", "9.3"] },
    { "id": 8, "tasks": ["11.1", "13.1"] },
    { "id": 9, "tasks": ["11.2", "12.1", "13.2"] },
    { "id": 10, "tasks": ["12.2", "14.1"] }
  ]
}
```
