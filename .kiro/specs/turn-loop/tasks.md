# Implementation Plan: Turn Loop

## Overview

Implement the server-side turn loop that drives one earthquake episode end to end with the real robots, so a human can read the Four_Layers per robot per turn: the situation text sent, the robot's reply, the recorder's reading with its evidence quotes, the rules' judgement, and the outcome. The turn loop sits on top of the already-built `house-rules-engine` and does not change it: it consumes `Engine::situationTextFor`, `Engine::applyTurn`, `Engine::prepareTurn`, `Engine::situationTextVersion`, and `EvidenceCheck::verify`, plus the engine value objects, unchanged.

The build follows a test-first progression: each task ends with passing tests, no task depends on code that does not yet exist, and no real provider API is ever called in tests (fakes only). Idempotency of `ask`/`apply`, the Four_Layer contract, and replay-based rehydration are the load-bearing correctness guarantees and are covered by required property tests and one end-to-end test.

Stack: PHP 8.2, PHPUnit 11, Eris ^1.3, plain classes in `src/TurnLoop/`, thin entry points in `api/`, a single plain-HTML debug page in `public/`, DDL in `sql/turn_loop.sql`, tests in `tests/TurnLoop/`.

**Engine prerequisites (hard dependency):** `house-rules-engine` task 28 (`Engine::prepareTurn`, accepted Gap A — same world for text and judgement) and task 29 (`Engine::situationTextVersion`, accepted Gap B — situation-text version) must be complete before any turn-loop task that builds situation text or judges a turn runs: tasks 11 (ask), 12 (apply), 16 (same-world Property 15 and rehydration Property 16 tests), and 17 (end-to-end test). Those tasks live in the `house-rules-engine` spec, not this one.

---

## Tasks

- [x] 1. Composer/autoload and test scaffold for the TurnLoop namespace
  - Extend the existing `composer.json` autoload with PSR-4 `"PoltergeistSwitch\\TurnLoop\\" => "src/TurnLoop/"` (the engine under `PoltergeistSwitch\Engine` is already built and is consumed unchanged)
  - Confirm `autoload-dev` PSR-4 covers `tests/TurnLoop/` (via the existing `"PoltergeistSwitch\\Tests\\" => "tests/"` mapping)
  - Create the directory skeleton: `src/TurnLoop/Clients/`, `src/TurnLoop/Repository/`, `src/TurnLoop/Values/`, `src/TurnLoop/Http/`, `tests/TurnLoop/Properties/`
  - Run `composer dump-autoload` and confirm a green (zero new tests) PHPUnit run
  - **Engine-prereq note:** `house-rules-engine` tasks 28 (`Engine::prepareTurn`) and 29 (`Engine::situationTextVersion`) are external prerequisites for the ask/apply/text tasks and the same-world and rehydration property tests; do not start tasks 11, 12, 16, or 17 until both engine tasks are complete
  - _Requirements: none (infrastructure only); engine tasks 28/29 are external prerequisites_

- [x] 2. Value objects and row DTOs
  - [x] 2.1 Implement the TurnLoop value objects in `src/TurnLoop/Values/`:
    - `ReplyRecord` (readonly: `prompt`, `reply`, `inputTokens`, `outputTokens`, `seconds`, `cost`, `unanswered`, `truncated`) with non-negative token counts; an unanswered record carries empty reply, zero tokens, zero cost
    - `Reading` (readonly: `action` (engine `Action`), `unknown`, `rawJson`, `promptVersion`)
    - `TurnStateValue` (enum or value object with `waiting`, `replies_complete`, `applied`; forward-only ordering helper)
    - `EpisodeView` (the GET response shape: `episodeId`, `disaster`, `currentExpectedTurn`, `earthquakeStartTurn`, `ended`, `turns[]` each with per-robot Four_Layers)
    - _Requirements: 1.4, 1.5, 1.8, 8.4_
  - [x] 2.2 Implement the repository row DTOs in `src/TurnLoop/Values/`:
    - `EpisodeRow`, `TurnRow`, `PromptReplyRow`, `AppliedTurnRows` mirroring the MySQL tables of the design
    - _Requirements: 8.2, 8.3, 8.4, 8.5, 8.6_
  - [ ]* 2.3 Write unit tests in `tests/TurnLoop/Values/` for the value objects and DTOs
    - Round-trip construction preserves all fields; unanswered `ReplyRecord` has zero tokens and zero cost; `TurnStateValue` only advances forward
    - _Requirements: 1.4, 1.8, 7.2, 8.4_

- [x] 3. Config and SecretLoader
  - [x] 3.1 Implement `Config` in `src/TurnLoop/Config.php`:
    - Draft `perCallTimeoutSeconds = 75` and `perRequestBudgetSeconds = 85`, with the per-call timeout kept strictly below the per-request budget
    - `inputCostPerToken` / `outputCostPerToken` draft rates from brief §14 (not literals in the clients), `maxRunningEpisodes = 5`, `idleSlotReleaseSeconds = 300`
    - _Requirements: 1.11, 1.12, 9.9, 9.10_
  - [x] 3.2 Implement `SecretLoader` in `src/TurnLoop/SecretLoader.php`:
    - Read the secrets directory path from an uncommitted local `Config_File` that lives outside the repository tree; read each named key from a file in that directory outside the web root; trim and reject empty
    - A missing/unreadable config returns an error and attempts no provider call; a missing/unreadable/empty key file returns an error for that client and attempts no provider call for it
    - Never return a key, credential, or the secrets path in any output
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 1.10_
  - [x] 3.3 Write unit tests in `tests/TurnLoop/ConfigSecretLoaderTest.php` using a temporary fake secrets dir (never a real key):
    - Reads a key from a fake file; missing config → error with no provider call; missing/empty key file → error for that client; per-call timeout strictly below per-request budget; no secret value appears in any returned string
    - Required, not optional (decided by the maintainer on 2026-11-08): these tests guard the API keys
    - _Requirements: 2.4, 2.5, 2.6, 1.12_

- [x] 4. ParallelHttp helper
  - [x] 4.1 Implement `ParallelHttp` in `src/TurnLoop/ParallelHttp.php`:
    - Wrap `curl_multi_init` / `curl_multi_add_handle` / `curl_multi_exec` so each client contributes a configured `CurlHandle` and receives its own result array `{status, body, seconds, error}`, with `CURLOPT_TIMEOUT` set to the configured per-call timeout; no retry inside one request
    - _Requirements: 1.6, 1.7, 5.1, 6.2_
  - [x]* 4.2 Write unit tests in `tests/TurnLoop/ParallelHttpTest.php` exercising the result-shaping against prepared handles (no real network; local handles or stub handles), asserting a timed-out/errored handle yields `error` set
    - _Requirements: 1.6, 1.7_

- [-] 5. RobotClient interface and the two providers plus the fake
  - [x] 5.1 Implement the `RobotClient` interface in `src/TurnLoop/Clients/RobotClient.php` (`call`, `prepare`, `finish`) and `ClaudeClient` + `GptClient`:
    - `ClaudeClient` calls model `claude-haiku-5-5` through the Anthropic Messages API; truncated when `stop_reason === "max_tokens"`; tokens from `usage.input_tokens`/`usage.output_tokens`
    - `GptClient` calls model `gpt-6-luna` through the OpenAI Responses API; truncated when `status === "incomplete"`; tokens from the response usage fields
    - Both send exactly one user message, no system prompt, provider default parameters, `max output = 4000` tokens, identical per-call timeout; API key supplied at construction from `SecretLoader`, never a literal
    - A failure or timeout yields a `ReplyRecord` with `unanswered = true`, no tokens or cost accrued, and no retry within one request; cost computed from `Config` rates
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.7, 1.8, 1.9, 1.10_
  - [x] 5.2 Implement `FakeRobotClient` in `src/TurnLoop/Clients/FakeRobotClient.php`:
    - Returns a configured `ReplyRecord` for a given prompt deterministically, opens no network connection, and counts invocations (so ask-idempotency can assert "at most once per turn")
    - _Requirements: 1.1, 14.1_
  - [x]* 5.3 Write unit tests in `tests/TurnLoop/Clients/RobotClientTest.php`:
    - `finish` maps a `stop_reason=max_tokens` body to `truncated=true` (Claude) and `status=incomplete` to `truncated=true` (GPT); an error/timeout result maps to `unanswered=true` with zero tokens/cost; the request body carries one user message, no system prompt, `max output 4000`; `FakeRobotClient` counts calls
    - _Requirements: 1.2, 1.4, 1.5, 1.8_

- [ ] 6. RecorderClient, RecorderInput, ReadingMapper, and EvidenceCheck wiring
  - [ ] 6.1 Implement `RecorderInput` and `RecorderInput::forRobot` in `src/TurnLoop/Clients/RecorderInput.php`:
    - Carries only the operation names, the room keys, the ids of objects in that robot's current room, the held object's id when one is held, the spot names `window`/`gas_stove`/`under_table`/`beside_futon`, and the literal `resident` — nothing else (no other room's objects, no danger words, no hints/formats/examples)
    - _Requirements: 3.4_
  - [ ] 6.2 Implement `ReadingMapper` in `src/TurnLoop/Clients/ReadingMapper.php`:
    - Maps recorder JSON to an engine `Action`: speak-only → `speak` carrying spoken lines; explicit stay-still/do-nothing → `wait`; unreadable, invalid JSON, or JSON not matching the Reading shape → `unknown` while still returning any spoken lines and reported-speech items; assigns no action codes A1–N1
    - _Requirements: 3.2, 3.3, 3.6, 3.9, 3.10_
  - [ ] 6.3 Implement the `RecorderClient` interface and `ClaudeRecorderClient` + `FakeRecorderClient` in `src/TurnLoop/Clients/`:
    - `ClaudeRecorderClient` calls model `claude-haiku-5-5` at temperature 0, reads exactly one reply per call under a fixed versioned recorder prompt, returns `unknown` on invalid JSON or on a failed/timed-out call with no retry; exposes `promptVersion()`
    - `FakeRecorderClient` returns a configured `Reading` deterministically with no network
    - `TurnService` (task 11/12) calls `EvidenceCheck::verify($reply, $reading->action)` before using any reading; an action whose `actionQuote` is missing or not an exact substring of the reply becomes `unknown`, and surviving spoken lines/reported quotes are exact substrings — wire the call site here as a small helper used by apply
    - _Requirements: 3.1, 3.5, 3.6, 3.7, 3.8, 1.7_
  - [ ]* 6.4 Write unit tests in `tests/TurnLoop/Clients/RecorderClientTest.php`:
    - `RecorderInput` contains only the allowed identifiers (no other room's object ids); the three classification cases speak/wait/unknown map correctly; invalid JSON → `unknown`; a failed/timeout result → `unknown`; the recorder prompt version is returned and non-empty
    - _Requirements: 3.3, 3.4, 3.6, 3.7_

- [ ] 7. SQL DDL for the turn-loop tables
  - [ ] 7.1 Create `sql/turn_loop.sql` with utf8mb4 tables and keys as in the design:
    - `episodes` (id, device_id, disaster, seed, current_expected_turn, earthquake_start_turn, ended, created_at, last_activity_at; running/device indexes)
    - `turns` (episode_id, turn_number, state, attempts_a/b, no_reply_a/b, resident_line; PK on episode_id+turn_number)
    - `prompts` (…, prompt_text, `situation_text_version`; PK per robot per turn)
    - `responses` (…, attempt, reply_text, input/output tokens unsigned, seconds, cost, unanswered, truncated; PK includes attempt)
    - `recordings` (…, recorder_json, unknown, recorder_prompt_version; PK per robot per turn)
    - `actions` (…, judgement_json, outcome_json, `action_json`; PK per robot per turn)
    - No secret, credential, or provider name anywhere in the file
    - _Requirements: 8.1, 8.2, 8.3, 8.4, 8.5, 8.6, 13.3, 2.3_

- [ ] 8. EpisodeRepository interface, in-memory fake, and PDO implementation
  - [ ] 8.1 Implement the `EpisodeRepository` interface in `src/TurnLoop/Repository/EpisodeRepository.php`:
    - Episode CRUD/slot queries (`createEpisode`, `findEpisode`, `countRunningEpisodes`, `findRunningEpisodeForDevice`, `setEarthquakeStartTurn`, `markEpisodeEnded`, `setCurrentExpectedTurn`), turn lifecycle (`findTurn`, `createTurn`, `setTurnState`), reply persistence (`savePromptAndReply`, `incrementAttempt`, `markNoReply`), the single-transaction four-layer write `storeAppliedTurn`, and `loadEpisodeView`
    - _Requirements: 8.7, 9.4_
  - [ ] 8.2 Implement `InMemoryEpisodeRepository` in `tests/TurnLoop/` (or a shared test double) for driving `TurnService` without a database:
    - Lazy idle-slot release: an episode counts as running only when `ended = 0` AND `last_activity_at` is within `idleSlotReleaseSeconds`; `storeAppliedTurn` is idempotent (a repeat creates no duplicates)
    - _Requirements: 8.7, 8.8, 8.10, 9.7, 9.8, 9.9, 9.10_
  - [ ] 8.3 Implement `PdoEpisodeRepository` in `src/TurnLoop/Repository/PdoEpisodeRepository.php`:
    - PDO/MySQL implementation; `storeAppliedTurn` runs inside `beginTransaction()`/`commit()` with `rollBack()` on any exception and reports failure; idempotent re-store guarded by the `(episode_id, turn_number, robot_id)` keys; device/slot queries apply the lazy 5-minute idle release
    - _Requirements: 8.1, 8.7, 8.8, 8.9, 8.10, 9.7, 9.8, 9.9, 9.10_
  - [ ]* 8.4 Write unit tests in `tests/TurnLoop/Repository/EpisodeRepositoryTest.php` against the in-memory fake (required behaviour), with the `PdoEpisodeRepository` tests guarded/skipped when no MySQL is available:
    - Four-layer write commits only after all records; rollback leaves no partial records; a repeated `storeAppliedTurn` creates no duplicates; device already running → refused; 5 running → refused; an idle episode past the threshold frees its slot
    - _Requirements: 8.8, 8.9, 8.10, 9.7, 9.8, 9.9, 9.10_

- [ ] 9. EngineFactory and ReplayEngineFactory
  - [ ] 9.1 Implement the `EngineFactory` interface and `ReplayEngineFactory` in `src/TurnLoop/`:
    - Rebuild an episode's `Engine` per request: construct a fresh `Engine`, `reset(Seed::fromInt(storedSeed))`, apply the stored `earthquake_start_turn` when recorded, then replay every applied turn in ascending order by `Engine::applyTurn($turnNumber, $actions)` using the exact actions decoded from each turn's stored `action_json`
    - Implement `ReplayedAction::fromJson(string $actionJson): Action` (and the inverse encode used by `apply`) reconstructing `operation`, `targetId`, `targetSpot`, `words`, `actionQuote`, and `reported`; a missing/malformed field decodes to operation `unknown`
    - _Requirements: 13.1, 13.2, 13.3, 13.4, 11.3_
  - [ ]* 9.2 Write unit tests in `tests/TurnLoop/ReplayEngineFactoryTest.php`:
    - Replaying a stored sequence reproduces the same world snapshot as a directly played sequence; a malformed `action_json` decodes to operation `unknown`
    - _Requirements: 13.2, 13.4, 11.3_

- [ ] 10. TurnService — createEpisode and flipEarthquake
  - [ ] 10.1 Implement `TurnService::createEpisode` and `TurnService::flipEarthquake` in `src/TurnLoop/TurnService.php`:
    - `createEpisode`: enforce the Device_Episode_Slot rules first (reject with 409 if this device already has a running episode or if 5 episodes run across all devices); otherwise draw a seed, build an `Engine` whose `reset(seed)` yields the initial situation, record the episode with disaster = earthquake, the seed, the initial situation, and current expected turn = 0; return the new episode id; assign a device id when the cookie is absent
    - `flipEarthquake`: if no earthquake start turn is recorded and the episode has not ended, set `earthquakeStartTurn = T` so the next turn played is offset 1, the first shaking turn whose shaking paragraph opens with "Suddenly the floor begins to shake hard."; if one is already recorded, leave it unchanged and return current state (idempotent); if the episode has ended, reject with 409 and change nothing
    - Switch mapping with no negative start turn (house-rules-engine Req 13.6; clarified by the maintainer on 2026-11-08): the engine's offset is `turn − earthquakeStartTurn`, so the next turn played (the current expected turn E) is offset 1 only when `earthquakeStartTurn = E − 1`. Therefore `createEpisode` applies a quiet turn 0 through the engine (no robot calls, no actions, as the engine tests do), leaving the current expected turn at 1, and `flipEarthquake` sets `earthquakeStartTurn = E − 1`, which is always ≥ 0. Test: after create and flip, the first `ask` builds texts whose shaking paragraph opens with "Suddenly the floor begins to shake hard."
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 9.6, 9.7, 9.8, 9.9_
  - [ ]* 10.2 Write unit tests in `tests/TurnLoop/TurnServiceCreateTest.php`:
    - Two episodes with the same seed produce identical initial situations; second episode for the same device → 409; sixth running episode → 409; repeated flip leaves the start turn unchanged; flip on an ended episode → 409
    - _Requirements: 4.3, 4.5, 4.6, 9.8, 9.9_

- [ ] 11. TurnService — ask
  - [ ] 11.1 Implement `TurnService::ask` in `src/TurnLoop/TurnService.php` (**requires engine tasks 28/29**):
    - Rehydrate the episode's `Engine` via `ReplayEngineFactory`; reject with 409 on turn-number mismatch, ended episode, or a turn already `applied`
    - Call `Engine::prepareTurn($turnNumber)` once before building either robot's text; for each robot with no stored successful reply and fewer than 2 attempts, build its text with `Engine::situationTextFor($robotId)` (no memories) and stage a prepared curl handle
    - Fire the staged robots in parallel through one `curl_multi` handle; as each reply arrives, store it and its prompt with the situation-text version from `Engine::situationTextVersion()`, and increment the robot's attempt count (persisted across repeated `ask` calls)
    - Reuse a robot with a stored successful reply (never call it again); count a failed/abandoned attempt without retrying in this request; at 2 failed attempts with no success, mark the robot no reply and set its recorded action to `unknown`
    - When every robot is answered or marked no reply, set the turn's state to `replies_complete`; otherwise leave it `waiting`
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5, 5.6, 5.7, 5.8, 4.7, 11.5, 11.6, 12.4, 8.3_
  - [ ]* 11.2 Write unit tests in `tests/TurnLoop/TurnServiceAskTest.php` using fakes:
    - A single `ask` calls both fakes once; a second `ask` with both successes calls neither; a robot with one stored success is reused and only the missing robot is called; a robot that fails twice is marked no reply with action `unknown`; the stored prompt carries the situation-text version
    - _Requirements: 5.2, 5.3, 5.6, 5.7, 8.3_

- [ ] 12. TurnService — apply
  - [ ] 12.1 Implement `TurnService::apply` in `src/TurnLoop/TurnService.php` (**requires engine tasks 28/29**):
    - Reject with 409 if the turn is in neither `replies_complete` nor `applied`, reading no reply and not calling `applyTurn`
    - For `replies_complete`: read both stored replies with the recorder in parallel under the per-call timeout with no retry; run `EvidenceCheck::verify` on each reading; call `Engine::applyTurn($turnNumber, $actions)` exactly once with the resulting actions keyed by `robot_a`/`robot_b` (a no-reply robot contributes an `unknown` action)
    - Store the Four_Layers for each robot — situation text, reading, judgement, outcome — plus `action_json` within one transaction; on success set the state to `applied`; on failure before all four layers are written, roll back, leave the turn `replies_complete`, and return a failure indication
    - For an already-`applied` turn, return current stored state without reading replies or calling `applyTurn` again; when `applyTurn` reports the episode ended, mark it ended, otherwise set current expected turn to applied turn + 1
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6, 6.7, 6.8, 7.4, 7.5, 13.3_
  - [ ]* 12.2 Write unit tests in `tests/TurnLoop/TurnServiceApplyTest.php` using fakes:
    - `apply` on `waiting` → 409 with no engine call; a single `apply` advances once and lands `applied`; a second `apply` returns stored state without re-advancing; a no-reply robot still gets all four layers with action `unknown`; an injected mid-write failure rolls back and leaves the turn `replies_complete`
    - _Requirements: 6.1, 6.6, 6.7, 6.8_

- [ ] 13. Turn state machine, turn-number checks, and getEpisode
  - [ ] 13.1 Implement the turn state machine and `TurnService::getEpisode` in `src/TurnLoop/TurnService.php`:
    - Track each turn's state as one of `waiting`/`replies_complete`/`applied`, advancing only forward; reject a turn-number mismatch, an ended episode, or a wrong-state call (`apply` on `waiting`, `ask` on `applied`) with HTTP 409 and a body indicating the current expected turn and that turn's state; engine turn numbers are zero-based with current expected turn starting at 0
    - `getEpisode` returns the episode state and its stored turn records as an `EpisodeView`
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.5, 7.6, 7.7, 9.4, 12.3_
  - [ ]* 13.2 Write unit tests in `tests/TurnLoop/TurnServiceStateTest.php`:
    - A turn state never returns to an earlier state; a mismatched turn number → 409 with current state and no stored change; a call on an ended episode → 409; `getEpisode` returns stored turn records
    - _Requirements: 7.2, 7.3, 7.6, 9.4_

- [ ] 14. API entry points and bootstrap
  - [ ] 14.1 Implement the five `api/` entry points plus `api/bootstrap.php`:
    - `episodes_create.php` (`POST /api/episodes`), `episodes_disaster.php`, `episodes_ask.php`, `episodes_apply.php`, `episodes_get.php`; `bootstrap.php` loads config, builds `TurnService`, and sends `Cache-Control: no-store`
    - Each entry point parses the request only (ids, turn number, device cookie), calls one `TurnService` method, and writes the JSON body; 404 on an unknown episode id; set a random device cookie when absent; no rules logic in `api/`; no secret or provider name in any body
    - _Requirements: 9.1, 9.2, 9.3, 9.4, 9.5, 9.6, 9.11, 2.6_
  - [ ]* 14.2 Write integration tests in `tests/TurnLoop/ApiTest.php` driving the entry points with a fake-backed `TurnService`:
    - Every response parses as JSON and carries `Cache-Control: no-store`; an unknown id → 404; a new request without a cookie sets a device cookie; no response body contains a key, credential, path, or provider name
    - _Requirements: 9.2, 9.3, 9.5, 9.6, 2.6_

- [ ] 15. Debug page
  - [ ] 15.1 Implement `public/debug.php`:
    - A single plain-HTML page with small JS that creates an episode, flips the earthquake, and advances one turn per action (calling `ask` then `apply`, re-calling `ask` when a robot is still missing) until the API reports the episode ended
    - For each `applied` turn show, for Amber (`robot_a`) and Jade (`robot_b`), the Four_Layers with the EvidenceCheck-backed evidence quotes highlighted; a no-reply robot shows its reply absent and action `unknown` while still showing the other three layers; stop offering to advance once ended
    - Send `Cache-Control: no-store` for the page; show no robot thought or plan beyond the reading and outcome; never name or identify either AI provider
    - _Requirements: 10.1, 10.2, 10.3, 10.4, 10.5, 10.6, 10.7, 10.8_

- [ ] 16. Property tests (required, Eris limitTo(100))
  - [ ] 16.1 Write the ask/apply/storage property tests in `tests/TurnLoop/Properties/`:
    - **Property 1: Ask idempotency** — for any `waiting` turn, repeated `ask` invokes each fake robot at most once across all calls; a stored success is reused; no attempt count exceeds 2
    - **Property 2: Apply advances once** — for any `replies_complete` turn, repeated `apply` invokes `Engine::applyTurn` exactly once, lands `applied`, and writes no duplicate records
    - **Property 3: Four layers stored** — for any turn that reaches `applied`, all four layers are stored for both robots, including a no-reply robot whose action is `unknown`
    - **Property 4: Unbacked actions become unknown** — for any reply and any action quote not an exact substring of the reply, `EvidenceCheck::verify` sets the action to `unknown`; surviving quotes are exact substrings
    - Each test is tagged `Feature: turn-loop, Property N: …` and uses `Eris\TestTrait` with `->limitTo(100)`
    - _Requirements: 14.2, 14.3, 14.4, 14.5_
  - [ ] 16.2 Write the same-world and rehydration property tests in `tests/TurnLoop/Properties/` (**requires engine tasks 28/29**):
    - **Property 15: Same world for text and judgement** — for a played turn, the earthquake step and resident line described by `Engine::situationTextFor` equal those in which `Engine::applyTurn` judges that turn, relying on `Engine::prepareTurn` being called before `situationTextFor`
    - **Property 16: Rehydration by replay** — for any sequence of applied turns, an `Engine` rebuilt by `ReplayEngineFactory` from the stored seed, earthquake start turn, and per-turn `action_json` yields the same world snapshot and the same `Engine::situationTextFor` output for both robots as the live-played `Engine`
    - Property 16 uses `->limitTo(100)`; both are tagged `Feature: turn-loop, Property N: …`
    - _Requirements: 14.9, 14.10, 12.1, 12.2, 12.4, 13.1, 13.2, 13.4_

- [ ] 17. End-to-end earthquake run and language-level check
  - [ ] 17.1 Write the end-to-end test in `tests/TurnLoop/EndToEndEarthquakeTest.php` (**requires engine tasks 28/29**):
    - Drive a full earthquake episode with `FakeRobotClient`s and a `FakeRecorderClient` through repeated `ask` and `apply` calls (as the browser would), using the real `ReplayEngineFactory` for rehydration between simulated requests, until `Engine::applyTurn` reports the episode ended
    - Assert the episode is marked ended and that every turn taken is in state `applied`; bound the loop at 100 applied turns and fail if the episode has not ended by then
    - Run `php -l` with no error on every PHP file changed by this spec; use only PHP 8.2 language features (no typed class constants, `json_validate()`, `#[\Override]`, property hooks, asymmetric visibility, or the pipe operator)
    - _Requirements: 14.1, 14.6, 14.7, 14.8_

- [ ] 18. Final checkpoint
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- **(a) Hard engine prerequisite.** `house-rules-engine` tasks 28 (`Engine::prepareTurn`) and 29 (`Engine::situationTextVersion`) must be complete before the ask/apply/text tasks (11, 12) and the same-world and rehydration property tests (16.2) and the end-to-end test (17.1) run. Those tasks live in the `house-rules-engine` spec, not this one; the dependency graph below can only list this spec's task ids, so wave 0 (scaffold) assumes engine 28/29 are already done.
- **(b) No real network in tests.** All tests use `FakeRobotClient` and `FakeRecorderClient`, which return a configured reply/reading deterministically and open no connection to a real provider API.
- **(c) Property tests and the end-to-end test are required.** Tasks 16.1, 16.2, and 17.1 are not optional; only test-only sub-tasks marked with `*` may be skipped for a faster MVP.
- **(d) Every response is uncacheable.** Every API response and the debug page send `Cache-Control: no-store`.
- **(e) Draft timeout values.** The draft per-call timeout (75 s) and per-request budget (85 s) must be checked by the maintainer against the server's PHP `max_execution_time` and the nginx/proxy timeout so the budget stays below both; the per-call timeout stays strictly below the per-request budget.
- **(f) No secrets or provider names.** No API key, passphrase, database credential, secrets path, or AI provider name ever appears in code, SQL, docs, `.kiro/`, or any response body or page output. Keys are read only from files outside the web root through the uncommitted local `Config_File`.

---

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1"] },
    { "id": 1, "tasks": ["2.1", "3.1", "4.1", "7.1"] },
    { "id": 2, "tasks": ["2.2", "2.3", "3.2", "4.2"] },
    { "id": 3, "tasks": ["3.3", "5.1", "6.1", "8.1"] },
    { "id": 4, "tasks": ["5.2", "6.2", "8.2"] },
    { "id": 5, "tasks": ["5.3", "6.3", "8.3", "9.1"] },
    { "id": 6, "tasks": ["6.4", "8.4", "9.2", "10.1"] },
    { "id": 7, "tasks": ["10.2", "11.1"] },
    { "id": 8, "tasks": ["11.2", "12.1"] },
    { "id": 9, "tasks": ["12.2", "13.1"] },
    { "id": 10, "tasks": ["13.2", "14.1"] },
    { "id": 11, "tasks": ["14.2", "15.1"] },
    { "id": 12, "tasks": ["16.1", "16.2"] },
    { "id": 13, "tasks": ["17.1"] }
  ]
}
```
