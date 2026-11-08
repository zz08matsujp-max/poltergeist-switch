# Requirements Document

## Introduction

The turn-loop is the second spec of PoltergeistSwitch. It drives one earthquake episode end to end with the real robots, so that a human can read every turn: for each robot the text it was sent, its reply, the recorder's reading with its evidence quotes, the rules' judgement, and the outcome.

This spec builds on the existing `house-rules-engine` spec and MUST NOT change the engine's behaviour. It uses the engine's public surface — `Engine::situationTextFor`, `Engine::applyTurn`, `EvidenceCheck::verify`, and the `TurnRecord`/`TurnResult`/`Action`/`Judgement`/`Outcome` value objects — as given. Where this spec needs something the engine does not yet provide, it records the gap in Requirement 11 and requires a change to the `house-rules-engine` spec to be proposed first, rather than assuming new engine behaviour.

The spec covers five things from product brief §15 items 2–3: the two AI clients and the recorder client; the recorder's reading contract; the two-phase turn flow (`ask`, `apply`) with idempotency; MySQL storage behind a repository interface; the HTTP API subset and a minimal debug page.

Scope is earthquake only, one running episode per device, no memories. Tech is PHP 8.2 plain classes in `src/`, thin entry points in `api/`, SQL files in `sql/`, PHPUnit with Eris property tests, and no real API calls in tests.

### Out of Scope

The following are explicitly out of scope for this spec:

- The Observe, Replay, and Compare screens (only a minimal debug page is in scope; the real Observe screen is a later spec).
- Memory, reflection, All reset, and generations.
- Heavy rain and heat wave (earthquake only).
- The research batch and admin review.
- The memory MCP server.
- Action codes A1–N1 (the recorder reads the first physical action, spoken lines, and reported speech only; code classification is deferred).

## Glossary

- **Turn_Loop**: The system this spec defines — the server-side orchestration that runs one episode through repeated `ask` and `apply` calls, plus its storage, API, and debug page.
- **Robot_Client**: A client that sends one user message to a provider and returns the reply together with its usage metadata. There are two provider implementations (Claude and GPT) and a fake implementation for tests, all behind one interface.
- **Claude_Client**: The Robot_Client for the robot driven by Anthropic, using model `claude-haiku-5-5` through the Anthropic Messages API.
- **GPT_Client**: The Robot_Client for the robot driven by OpenAI, using model `gpt-6-luna` through the OpenAI Responses API.
- **Recorder_Client**: The client that reads one robot reply into a Reading, using model `claude-haiku-5-5` at temperature 0.
- **Reply_Record**: The stored result of one Robot_Client call — full prompt text, full reply text, input tokens, output tokens, seconds elapsed, cost, an unanswered flag, and a truncated flag.
- **Truncated**: A reply the provider cut off at the output-token limit — Anthropic `stop_reason = max_tokens`, OpenAI `status = incomplete`.
- **Reading**: The recorder's reading of one reply, as defined by `house-rules-engine` Requirement 12.1 — the first physical action (operation, target id, spot) with its `actionQuote`, the spoken lines exactly as quoted, and the reported-speech items (`quote`, `heardAs`, `ownForm`).
- **EvidenceCheck**: The pure engine check (`house-rules-engine` Requirement 12.2–12.4) that keeps only the parts of a Reading backed by an exact substring quote of the reply; an unbacked action becomes `unknown`.
- **Ask_Phase**: The first half of a turn (brief §8): build each robot's situation text, call both Robot_Clients in parallel, and store each reply as it arrives.
- **Apply_Phase**: The second half of a turn (brief §8): read both stored replies with the recorder, run EvidenceCheck and `Engine::applyTurn`, and store the four layers in one transaction.
- **Turn_State**: The lifecycle state of one turn — `waiting`, `replies_complete`, or `applied`.
- **Four_Layers**: The four stored records for one robot on one applied turn (brief §2, §9) — the robot's text, the recorder's reading, the rules' judgement, and the outcome.
- **Episode_Repository**: The repository interface through which Turn_Loop reads and writes episodes, turns, prompts, responses, recordings, and actions, implemented over PDO for MySQL.
- **Device_Episode_Slot**: The one running episode allowed per device, keyed by a random device id stored in a cookie.
- **Config_File**: A local PHP configuration file (like `pilot/config.local.php`), never committed, that provides the path to the secrets directory outside the web root from which API keys are read.
- **Debug_Page**: A minimal plain-HTML page that starts an episode, turns the earthquake on, and steps through turns, showing the Four_Layers per robot per turn.
- **Robot_A / Robot_B**: The two robots, as in the engine spec. Robot_A is driven by Claude_Client, Robot_B by GPT_Client.

## Requirements

### Requirement 1: Robot Client Interface and Call Settings

**User Story:** As the Turn_Loop, I want each robot driven through one uniform client that applies identical call settings, so that both companies are treated the same and replies carry the metadata the experiment needs.

#### Acceptance Criteria

1. THE Turn_Loop SHALL define one Robot_Client interface with a Claude_Client implementation, a GPT_Client implementation, and a fake implementation for tests.
2. WHEN a Robot_Client is called, THE Robot_Client SHALL send exactly one user message with no system prompt, provider default parameters, and a maximum output of 4000 tokens.
3. THE Claude_Client SHALL call model `claude-haiku-5-5` through the Anthropic Messages API, and THE GPT_Client SHALL call model `gpt-6-luna` through the OpenAI Responses API, with the settings in criterion 2 identical for both.
4. WHEN a Robot_Client call returns successfully, THE Robot_Client SHALL produce a Reply_Record containing the full prompt text, the full reply text, the input tokens, the output tokens, the seconds elapsed, the cost, an unanswered flag set to false, and the truncated flag.
5. WHEN an Anthropic reply has `stop_reason` equal to `max_tokens`, THE Claude_Client SHALL set the truncated flag to true; WHEN an OpenAI reply has `status` equal to `incomplete`, THE GPT_Client SHALL set the truncated flag to true.
6. WHEN both robots are called for one turn, THE Turn_Loop SHALL issue both Robot_Client calls in parallel using `curl_multi`.
7. IF a Robot_Client call exceeds the configured per-call timeout (draft 75 s), THEN THE Robot_Client SHALL abandon that call and report the robot as unanswered for that attempt.
8. WHEN a Robot_Client call fails or is abandoned, THE Robot_Client SHALL produce a Reply_Record with the unanswered flag set to true and no token or cost totals accrued for that attempt.
9. WHEN a Robot_Client attempt is unanswered, THE Turn_Loop SHALL NOT retry that robot within the same HTTP request.
10. WHEN a Robot_Client or the Recorder_Client is constructed, THE Turn_Loop SHALL provide its API key from the secrets source defined in Requirement 2 rather than from any literal in source.
11. THE Turn_Loop SHALL read the per-call timeout and the per-request budget from configurable values rather than from literals embedded in criteria, with draft values of a 75 s per-call timeout and an 85 s per-request budget.
12. THE Turn_Loop SHALL keep the configured per-call timeout strictly below the configured per-request budget.
13. Note: the maintainer must verify the configured per-request budget against the server's own limits — PHP `max_execution_time` and the nginx/proxy timeout — so that the per-request budget stays below both. The draft values (per-call 75 s, per-request 85 s) follow the EpisodeConfig `(draft)` convention and are not final.

### Requirement 2: API Key and Secret Loading

**User Story:** As the maintainer, I want API keys read only from files outside the web root through an uncommitted local config, so that no secret ever appears in the repository or in a response.

#### Acceptance Criteria

1. WHEN a request requires a provider call, THE Turn_Loop SHALL read the path to the secrets directory from the Config_File, which lives outside the repository tree and is excluded from version control.
2. WHEN a Robot_Client or the Recorder_Client needs an API key, THE Turn_Loop SHALL read that key from a file in the secrets directory located outside the web root, and SHALL use only that file-read value for the call.
3. THE Turn_Loop SHALL NOT write any API key, passphrase, or database credential into source code, SQL files, documentation, or any file under `.kiro/`.
4. IF the Config_File is missing or unreadable, THEN THE Turn_Loop SHALL return an error response, SHALL change no stored state, and SHALL NOT attempt a provider call.
5. IF a required key file is missing, unreadable, or empty after trimming, THEN THE Turn_Loop SHALL return an error response for that client and SHALL NOT attempt a provider call for that client.
6. THE Turn_Loop SHALL NOT include any API key, passphrase, database credential, or the secrets directory path in any API response body, error response, or page output.

### Requirement 3: Recorder Client and Reading Contract

**User Story:** As an observer, I want each reply read into a structured Reading by a deterministic recorder that only maps words to ids, so that every action and line is traceable and the recorder never decides what is possible.

#### Acceptance Criteria

1. THE Recorder_Client SHALL call model `claude-haiku-5-5` at temperature 0 and SHALL read exactly one reply per call.
2. WHEN the Recorder_Client reads a reply, THE Recorder_Client SHALL return JSON describing the first physical action as an operation, a target id, and a spot, together with its `actionQuote`, the spoken lines exactly as quoted, and the reported-speech items each carrying a `quote`, a `heardAs` form, and an `ownForm` form, following the reading rules of `house-rules-engine` Requirement 12.6.
3. WHEN a reply only speaks with no physical action, THE Recorder_Client SHALL return a Reading whose operation is `speak` carrying its spoken lines (house-rules-engine Requirement 5.12); WHEN a reply has the robot explicitly stay still or do nothing physical, THE Recorder_Client SHALL return a Reading whose operation is `wait`; and IF no action of any kind can be read from a reply, THEN THE Recorder_Client SHALL return a Reading whose action target and operation are `unknown`, while still returning any spoken lines and reported-speech items it reads.
4. WHEN the Turn_Loop builds the recorder's input, THE Turn_Loop SHALL supply only the operation names, the room keys, the ids of the objects in that robot's current room, the id of the object the robot is currently holding when one is held, the spot names `window`, `gas_stove`, `under_table`, `beside_futon`, and `near_resident`, and the identifier `resident`, and SHALL supply nothing else.
5. THE Recorder_Client SHALL use a fixed recorder prompt that carries a version identifier, and THE Turn_Loop SHALL store that version with every stored reading.
6. IF the Recorder_Client returns text that is not valid JSON matching the Reading shape defined in `house-rules-engine` Requirement 12.1, THEN THE Turn_Loop SHALL record the reading as `unknown`.
7. IF a Recorder_Client call fails or exceeds the configured per-call timeout of Requirement 1, THEN THE Turn_Loop SHALL abandon that call without retrying within the same HTTP request and SHALL record the reading as `unknown`.
8. WHEN a Reading has been produced, THE Turn_Loop SHALL pass it through EvidenceCheck before using it, so that any action, spoken line, or reported-speech item not backed by an exact substring quote of the reply is discarded as defined in `house-rules-engine` Requirement 12, and IF EvidenceCheck finds the action quote missing or not an exact substring of the reply, THEN THE Turn_Loop SHALL record that reading's recorded action as `unknown`.
9. THE Recorder_Client SHALL NOT decide whether an action is possible or dangerous; that decision belongs to `Engine::applyTurn`.
10. THE Turn_Loop SHALL NOT assign action codes A1–N1 to a Reading in this spec.

### Requirement 4: Episode Creation and Earthquake Start

**User Story:** As an observer, I want to create an episode and flip the earthquake switch, so that the turn clock and the disaster start turn are fixed before the robots act.

#### Acceptance Criteria

1. WHEN an episode is created, THE Turn_Loop SHALL record a new episode whose disaster is set to earthquake, whose seed is the seed used to derive its initial situation, and whose initial situation is the one the engine produces from that seed, and SHALL set the episode's current expected turn to turn 0, using the engine's own turn numbering whose first turn is 0.
2. WHEN an episode is created, THE Turn_Loop SHALL return the created episode's identifier in the response.
3. WHEN an episode is created, THE Turn_Loop SHALL initialise the engine for that episode with the recorded seed, so that the same seed reproduces the same initial situation on any PHP 8.2 build.
4. WHEN the observer flips the earthquake switch for an episode that has no earthquake start turn recorded, THE Turn_Loop SHALL set that episode's earthquake start turn so that the next turn played is earthquake offset 1, the first shaking turn whose shaking paragraph opens with "Suddenly the floor begins to shake hard.".
5. IF the observer flips the earthquake switch for an episode that already has an earthquake start turn recorded, THEN THE Turn_Loop SHALL leave the recorded earthquake start turn unchanged and SHALL return the episode's current state, so that repeating the flip is idempotent.
6. IF the observer flips the earthquake switch for an episode that has already ended, THEN THE Turn_Loop SHALL reject the request with HTTP status 409 and the episode's current state, and SHALL NOT change any recorded earthquake start turn.
7. THE Turn_Loop SHALL build each robot's situation text in this spec by calling `Engine::situationTextFor` with no memories supplied.

### Requirement 5: Ask Phase

**User Story:** As the browser driving a turn, I want the `ask` call to build each robot's text and collect both replies idempotently, so that repeating `ask` never calls an already-answered robot twice.

#### Acceptance Criteria

1. WHEN `ask` is called for a turn whose Turn_State is `waiting` and neither robot has yet answered, THE Turn_Loop SHALL build each robot's situation text with `Engine::situationTextFor`, call both Robot_Clients in parallel using `curl_multi`, and store each robot's reply and its prompt as a Reply_Record as soon as that reply arrives, storing the situation-text version with each prompt as required by Requirement 8.
2. WHEN `ask` is called for a turn that already has one robot's successful Reply_Record stored, THE Turn_Loop SHALL reuse the stored reply and call only the robot whose successful reply is missing.
3. WHEN `ask` is called for a turn that already has both robots' successful Reply_Records stored, THE Turn_Loop SHALL reuse both stored replies and SHALL NOT call any Robot_Client.
4. THE Turn_Loop SHALL bound each robot's attempt count at 2 per turn and SHALL persist that attempt count across repeated `ask` calls for the same turn.
5. WHEN a Robot_Client attempt fails or is abandoned under the configured per-call timeout of Requirement 1, THE Turn_Loop SHALL record that failed attempt against the robot's attempt count and SHALL NOT retry that robot within the same HTTP request.
6. IF a robot has reached 2 failed attempts with no successful Reply_Record for the same turn, THEN THE Turn_Loop SHALL mark that robot as no reply and SHALL set that robot's recorded action to `unknown`.
7. WHEN every robot for a turn is either successfully answered or marked no reply after exhausting its attempts, THE Turn_Loop SHALL set that turn's Turn_State to `replies_complete`.
8. WHILE a robot has fewer than 2 failed attempts and no successful reply for a turn, THE Turn_Loop SHALL leave that turn's Turn_State at `waiting`.

### Requirement 6: Apply Phase

**User Story:** As the browser driving a turn, I want the `apply` call to read both replies, judge and advance the world, and store the four layers in one transaction, so that repeating `apply` returns the same state without advancing twice.

#### Acceptance Criteria

1. IF `apply` is called for a turn that is in neither Turn_State `replies_complete` nor `applied`, THEN THE Turn_Loop SHALL reject the call as defined in Requirement 7, SHALL NOT read any reply, and SHALL NOT call `Engine::applyTurn`.
2. WHEN `apply` is called for a turn whose Turn_State is `replies_complete`, THE Turn_Loop SHALL read both stored replies with the Recorder_Client in parallel using `curl_multi`, under the configured per-call timeout of Requirement 3 with no retry within the same HTTP request.
3. WHEN both readings are produced, THE Turn_Loop SHALL run EvidenceCheck on each reading and then call `Engine::applyTurn` exactly once with the resulting actions.
4. WHEN `Engine::applyTurn` returns, THE Turn_Loop SHALL store the Four_Layers for each robot — the robot's text, the recorder's reading, the judgement, and the outcome — within a single database transaction.
5. WHEN the single transaction in criterion 4 commits successfully, THE Turn_Loop SHALL set that turn's Turn_State to `applied`.
6. WHEN `apply` is called for a turn whose Turn_State is already `applied`, THE Turn_Loop SHALL return the current stored state and SHALL NOT read replies again or call `Engine::applyTurn` again.
7. IF the single transaction in criterion 4 fails before all Four_Layers are written, THEN THE Turn_Loop SHALL roll the transaction back so that no partial records persist, SHALL leave the turn in Turn_State `replies_complete`, and SHALL return a failure indication.
8. WHEN a robot was marked no reply in the Ask_Phase, THE Turn_Loop SHALL store that robot's action as `unknown` and SHALL still store all Four_Layers for that robot on the applied turn.

### Requirement 7: Turn State Machine and Turn-Number Checks

**User Story:** As the browser driving a turn, I want strict turn states and turn-number checking, so that an out-of-order call is rejected with the current state rather than corrupting the episode.

#### Acceptance Criteria

1. THE Turn_Loop SHALL track each turn's Turn_State as one of `waiting`, `replies_complete`, or `applied`.
2. THE Turn_Loop SHALL advance a turn's Turn_State only forward, in the order `waiting` to `replies_complete` to `applied`, and SHALL NOT return a turn to an earlier Turn_State.
3. IF `ask` or `apply` is called with a turn number that does not equal the episode's current expected turn number, THEN THE Turn_Loop SHALL reject the call with HTTP status 409 and SHALL NOT change any stored state, and the response body SHALL indicate the episode's current expected turn number and that turn's current Turn_State.
4. WHEN an episode's active turn reaches Turn_State `applied`, THE Turn_Loop SHALL set the episode's current expected turn number to the applied turn number plus one, consistent with the engine's zero-based turn numbering in which the first turn is turn 0.
5. WHEN `Engine::applyTurn` reports the episode has ended, THE Turn_Loop SHALL mark the episode ended.
6. IF `ask` or `apply` is called for an episode that is marked ended, THEN THE Turn_Loop SHALL reject the call with HTTP status 409 and SHALL NOT change any stored state, and the response body SHALL indicate that the episode has ended.
7. IF `apply` is called for a turn whose Turn_State is `waiting`, OR `ask` is called for a turn whose Turn_State is `applied`, THEN THE Turn_Loop SHALL reject the call with HTTP status 409 and SHALL NOT change any stored state, and the response body SHALL indicate that turn's current Turn_State.

### Requirement 8: Storage and Repository

**User Story:** As a developer, I want episode data stored in MySQL behind a small repository interface, so that the orchestration logic can be tested without a database server.

#### Acceptance Criteria

1. THE Turn_Loop SHALL store episode data in MySQL tables using the utf8mb4 character set, so that multibyte text is stored without loss.
2. THE Turn_Loop SHALL define tables for episodes, turns, prompts, responses, recordings, and actions as a subset of product brief §13, with their definitions in SQL files under `sql/`.
3. WHEN a prompt sent to a robot is stored, THE prompts table SHALL store the full prompt text together with the situation-text version returned by `Engine::situationTextVersion()` in effect when that text was built, so that every stored prompt carries its text version as required by the robot-input steering rules.
4. WHEN a robot reply is stored, THE responses table SHALL store the full reply text, the input tokens and output tokens as non-negative integers, the seconds elapsed, the cost, and the unanswered flag and the truncated flag as booleans.
5. WHEN a reading is stored, THE recordings table SHALL store the recorder JSON, the unknown flag as a boolean, and the recorder prompt version.
6. WHEN an applied turn's records are stored, THE actions table SHALL store the judgement and the outcome per robot per applied turn.
7. THE Turn_Loop SHALL access all storage through an Episode_Repository interface, and SHALL provide a PDO implementation of that interface for MySQL.
8. WHEN the Turn_Loop stores the records of one applied turn, THE Episode_Repository SHALL commit only after all records for that applied turn are written within a single transaction.
9. IF the single transaction in criterion 8 fails, THEN THE Episode_Repository SHALL roll it back so that no partial records persist and SHALL report the failure to the caller.
10. WHEN the Episode_Repository is asked to store an already-stored applied turn's records again, THE Episode_Repository SHALL leave the stored records unchanged and SHALL create no duplicate records.

### Requirement 9: HTTP API

**User Story:** As the browser and the debug page, I want a small JSON API with no caching, so that each turn can be driven in short, idempotent calls.

#### Acceptance Criteria

1. THE Turn_Loop SHALL expose the endpoints `POST /api/episodes`, `POST /api/episodes/{id}/disaster`, `POST /api/episodes/{id}/turns/{n}/ask`, `POST /api/episodes/{id}/turns/{n}/apply`, and `GET /api/episodes/{id}`.
2. WHEN any API endpoint responds, THE Turn_Loop SHALL return a JSON body.
3. WHEN any API endpoint responds, THE Turn_Loop SHALL send the HTTP header `Cache-Control: no-store`.
4. WHEN `GET /api/episodes/{id}` is called for an existing episode, THE Turn_Loop SHALL return the episode's state and its stored turn records.
5. IF any endpoint is called with an episode id that does not exist, THEN THE Turn_Loop SHALL reject the request with HTTP status 404.
6. WHEN `POST /api/episodes` is called without a device identifier cookie, THE Turn_Loop SHALL assign a random device identifier and set it as a cookie on the response.
7. THE Turn_Loop SHALL identify a device by the random device identifier stored in its cookie, and SHALL allow at most one running episode per Device_Episode_Slot.
8. IF a device that already has a running episode requests a new episode, THEN THE Turn_Loop SHALL reject `POST /api/episodes` with HTTP status 409.
9. IF 5 episodes are already running across all devices, THEN THE Turn_Loop SHALL reject `POST /api/episodes` with HTTP status 409.
10. WHEN an episode has had no `ask` or `apply` activity for 5 minutes, THE Turn_Loop SHALL treat that episode's Device_Episode_Slot as free.
11. THE `api/` entry points SHALL parse the request, call `src/` classes, and write the JSON response only, holding no rules logic themselves.
12. WHEN an API endpoint handles a request, THE Turn_Loop SHALL complete the HTTP response within the configured per-request budget of Requirement 1 (draft 85 s).

### Requirement 10: Debug Page

**User Story:** As the maintainer, I want a minimal debug page that steps through a full earthquake episode, so that I can read the four layers per robot per turn before any real screen exists.

#### Acceptance Criteria

1. THE Debug_Page SHALL be a single plain-HTML page that creates an episode, turns the earthquake on, and advances turns by calling the API, one turn per advance action, until the episode is ended.
2. WHEN a turn's Turn_State is `applied`, THE Debug_Page SHALL show, for Robot_A and for Robot_B, the Four_Layers for that turn: the situation text the robot was sent, the robot's reply, the recorder's reading together with the EvidenceCheck-backed evidence quotes, the judgement, and the outcome.
3. IF a robot was marked no reply for an applied turn, THEN THE Debug_Page SHALL show that robot's reply as absent and its recorded action as `unknown` while still showing the other three layers for that robot.
4. WHEN the API reports that the episode has ended, THE Debug_Page SHALL stop offering to advance further turns.
5. THE Debug_Page SHALL send the HTTP header `Cache-Control: no-store` for the page response.
6. THE Debug_Page SHALL NOT display any robot thought or plan beyond what the recorder's reading and the engine's outcome contain.
7. THE Debug_Page SHALL NOT name or otherwise identify either AI provider in its output.
8. THE Debug_Page SHALL label Robot_A as "Amber" and Robot_B as "Jade", as on the other screens (product brief §11).

### Requirement 11: Dependency on the House Rules Engine

**User Story:** As a developer, I want this spec to use the engine without changing it, so that engine behaviour stays owned by its own spec and any shortfall is surfaced for review.

#### Acceptance Criteria

1. THE Turn_Loop SHALL invoke engine behaviour only through the public entry points `Engine::situationTextFor`, `Engine::applyTurn`, and `EvidenceCheck::verify` as defined in the `house-rules-engine` spec, and SHALL NOT modify their source.
2. THE Turn_Loop SHALL consume the engine's `TurnRecord`, `TurnResult`, `Action`, `Judgement`, and `Outcome` value objects without mutating them.
3. IF a consumed engine value object is missing a field the Turn_Loop needs, THEN THE Turn_Loop SHALL treat the affected reading or action as `unknown` rather than fail the turn.
4. IF the Turn_Loop requires behaviour that the `house-rules-engine` spec does not provide, THEN THE Turn_Loop SHALL record a written, human-readable gap entry naming the missing engine behaviour and the dependent turn-loop behaviour, and SHALL require a change to the `house-rules-engine` spec to be proposed before that behaviour is relied upon.
5. WHEN the Turn_Loop plays a turn, THE Turn_Loop SHALL call `Engine::prepareTurn($turnNumber)` once before building either robot's situation text for that turn, so that the text a robot reads and the world its action is judged in by the following `Engine::applyTurn($turnNumber, …)` are the same turn's world, relying on `house-rules-engine` Requirement 13, accepted 2026-11-08, and THE Turn_Loop SHALL map the earthquake switch to a non-negative earthquake start turn so that the first robot prompt after the switch is the first shaking turn whose shaking paragraph opens with "Suddenly the floor begins to shake hard.".
6. THE Turn_Loop SHALL read the situation-text version from `Engine::situationTextVersion()` and SHALL store that version with every stored prompt, relying on `house-rules-engine` Requirement 14, accepted 2026-11-08.

### Requirement 12: Same World for Text and Judgement

**User Story:** As an observer, I want each robot to read the same turn's world that its action is then judged in, so that the first earthquake prompt is the first shaking turn and a "still shaking" reading is judged while the world is still shaking.

#### Acceptance Criteria

1. THE text a robot receives for a turn SHALL describe the same world — house state, earthquake step, and resident line — in which that robot's action for the same turn is judged by `Engine::applyTurn`.
2. WHEN the earthquake switch has been flipped, THE first earthquake prompt a robot receives SHALL be the first shaking turn, whose shaking paragraph opens with "Suddenly the floor begins to shake hard." as in the S0 pilot text.
3. THE Turn_Loop SHALL use the engine's own turn numbers, whose first turn is 0.
4. WHEN the Turn_Loop plays a turn, THE Turn_Loop SHALL call `Engine::prepareTurn($turnNumber)` before `Engine::situationTextFor`, so that the same-world guarantee of criterion 1 holds, relying on `house-rules-engine` Requirement 13.

### Requirement 13: Engine Rehydration by Replay

**User Story:** As a developer, I want the engine rebuilt per request by replaying the episode, so that each fresh PHP process holds the correct world without keeping an engine in memory between requests.

#### Acceptance Criteria

1. THE Turn_Loop SHALL rebuild an episode's Engine on each HTTP request by replaying the episode from its stored state, because each request starts a fresh PHP process that holds no engine in memory.
2. WHEN the Turn_Loop rebuilds an episode's Engine, THE Turn_Loop SHALL reset the engine from the episode's stored seed, apply the stored earthquake start turn, and replay every applied turn in order by calling `Engine::applyTurn($turnNumber, $actions)` with the exact actions that were passed to `applyTurn` for that turn, namely the actions as verified by EvidenceCheck and stored.
3. WHEN an applied turn's records are stored, THE actions table SHALL store, per robot per applied turn, the exact action passed to `Engine::applyTurn` after EvidenceCheck as JSON in an `action_json` column, so that the engine can be replayed deterministically.
4. WHEN an episode's Engine has been rebuilt by replay, THE rebuilt Engine SHALL hold the same world state as the Engine that played those turns live.

### Requirement 14: Tests

**User Story:** As a developer, I want the turn loop verified without touching real APIs, so that idempotency and the four-layer contract hold across many inputs and one full episode.

#### Acceptance Criteria

1. THE test suite SHALL use fake Robot_Clients and a fake Recorder_Client that return their configured reply for a given input deterministically, and SHALL NOT open any network connection to a real provider API.
2. THE test suite SHALL include an Eris property test, configured with `limitTo(100)`, verifying that for any turn in Turn_State `waiting`, calling `ask` two or more times in succession invokes each of the two fake Robot_Clients at most once, counting invocations across all the repeated `ask` calls.
3. THE test suite SHALL include an Eris property test, configured with `limitTo(100)`, verifying that for any turn in Turn_State `replies_complete`, calling `apply` two or more times in succession invokes `Engine::applyTurn` exactly once and leaves the turn in Turn_State `applied`.
4. WHEN a turn reaches Turn_State `applied` in a test, THE test suite SHALL, via an Eris property test configured with `limitTo(100)`, verify that all Four_Layers — robot text, reading, judgement, and outcome — are stored for both Robot_A and Robot_B, including when a robot was marked no reply.
5. THE test suite SHALL include an Eris property test, configured with `limitTo(100)`, verifying that for any reply and any action quote that is not an exact substring of that reply, EvidenceCheck sets the reading's recorded action to `unknown`.
6. THE test suite SHALL include one end-to-end test, using fake Robot_Clients and a fake Recorder_Client, that drives one earthquake episode through repeated `ask` and `apply` calls until `Engine::applyTurn` reports the episode ended, and SHALL assert that the episode is marked ended and that every turn taken is in Turn_State `applied`.
7. THE end-to-end test SHALL terminate within 100 applied turns, and IF the episode has not reported ended by then, THEN the test SHALL fail.
8. THE test suite SHALL run `php -l` with no reported error on every PHP file changed by this spec, and SHALL use only PHP 8.2 language features, excluding features introduced after PHP 8.2.
9. THE test suite SHALL include an Eris property test, configured with `limitTo(100)`, verifying that for any sequence of applied turns, an Engine rebuilt by replay from the stored seed, the stored earthquake start turn, and the stored per-turn actions yields the same world snapshot and the same `Engine::situationTextFor` output for both Robot_A and Robot_B as the Engine that played those turns live.
10. THE test suite SHALL include a test asserting Property 15, same world for text and judgement, verifying that for a played turn the world described by `Engine::situationTextFor` matches the world in which `Engine::applyTurn` judges that turn's actions, relying on `Engine::prepareTurn`.

## Open Questions

- **(a) Cost calculation inputs** — product brief §14 gives estimated per-token prices ($0.10 per million input, $0.50 per million output) but marks them an estimate. Whether these rates are final, and where they are configured, is not decided by this spec.
- **(b) Idle-slot release trigger** — Requirement 9.6 frees a Device_Episode_Slot after 5 minutes of no turn activity. Whether the release is evaluated lazily on the next request or by a separate sweep is left to design, since no scheduler is available on shared hosting.
- **(c) Device cookie lifetime** — the random device identifier in the cookie (Requirement 9.4) has no decided lifetime; product brief §16 lists cookie lifetime as open.
