# Implementation Plan: Heat-Wave

## Overview

This plan implements the heat-wave disaster for PoltergeistSwitch strictly in PHP 8.2, gated so that every heat behaviour runs only when an episode's disaster is `heat_wave`. The finished earthquake must never change how it behaves, reads, or replays (Requirement 1).

Task 1 is the earthquake golden-master test and MUST be completed before any heat-wave change to the engine, the texts, the database, the API, or the screens (Requirement 1.1). The remaining tasks build incrementally: the `Disaster` enum and `WorldModel` threading, `EpisodeConfig` heat fields, `HeatWavePhase`, the resident heat branch and the air-conditioner will, the heat objects in the action layer, the heat situation and reflection text, the additive serializer and SQL migration, the turn loop and API, the recorder input, and the screens. Every heat method is gated on `$world->disaster === Disaster::heat_wave`, and the earthquake path runs the existing code verbatim.

Steering constraints that apply to every task: PHP 8.2 language features only (no features introduced after 8.2); `php -l` with no error on every changed PHP file; no real provider API calls in tests (use the existing fakes); every API response and page sends `Cache-Control: no-store`; no deploy steps. Commits follow tech.md: one commit per task, with the message format `task N: <task title>`, made only after the full PHPUnit suite (the earthquake golden master included) and the JavaScript tests pass and `php -l` reports no error on every changed PHP file. Never push, amend, rebase, or reset; the maintainer reviews and pushes. Add no deploy steps and no credentials.

## Tasks

- [x] 1. Build the earthquake golden-master fixture and test (REQUIRED, FIRST, before any heat-wave change)
  - Create `tests/EarthquakeGoldenMasterTest.php` and the committed fixture `tests/fixtures/earthquake_golden_master.json`.
  - Choose at least 30 fixed integer seeds so the drawn `SituationSpec` covers every starting situation — all three times of day, all four starting activities, and the bathing-bathroom constraint.
  - Run every seed through the full fixed action sequence **twice**: once with no memory snapshot and once with a non-empty memory snapshot set via `Engine::setMemories`, so both memory paths are pinned for every seed.
  - Derive a fixed seeded per-turn action sequence that includes moves, takes, uses, hand-overs, supports, covers, speech, and `unknown` readings, and that **specifically includes** `use` and `take` of the `window`, the `window_air_conditioner`, the `electric_fan`, and the `refrigerator`, plus `hand_over` actions, so the earthquake behaviour of the exact targets the heat code later touches is pinned before any heat code exists.
  - Record per turn, for both robots: the situation text, the judgements, the outcomes, the `WorldStateSerializer` output (not a raw snapshot), the resident state, the resident line, and the end flag; plus every reflection text at the end.
  - Store each recorded field as a full value or a SHA-256 hash (hashes keep the fixture small); the test recomputes each value and fails on any difference.
  - The fixture is committed once and is NEVER regenerated or edited by any later task; no later task changes an expected earthquake value to make a test pass. If any earthquake value differs from the committed fixture, the work stops and reports the difference rather than updating the expected value.
  - Use the existing `FakeRobotClient` / `FakeRecorderClient` fakes; no real API call. Run `php -l` on every changed PHP file.
  - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.7, 1.10, 11.9, 11.10, 11.11_

- [-] 2. Introduce the Disaster enum and thread the disaster through the world (earthquake unchanged)
  - [x] 2.1 Add the `Disaster` enum and `WorldModel::$disaster`
    - Create `src/Engine/Enums/Disaster.php` with the string-backed cases `earthquake` and `heat_wave`.
    - Add `WorldModel::$disaster` defaulting to `Disaster::earthquake`, set by `reset()` from the `SituationSpec` or an explicit argument, so a rebuilt world knows its disaster.
    - Add the pure heat-read accessors on `WorldModel` (`acOn()`, `fanOn()`, `fanRoom()`, `windowOpen()`, `roomTemperature()`, `thermometerReading()`, and a disaster predicate) without changing any earthquake read.
    - Run `php -l` on every changed PHP file.
    - _Requirements: 1.6, 2.1_

  - [x] 2.2 Write the earthquake-untouched-by-heat property test
    - **Property 12: An earthquake episode is untouched by heat**
    - **Validates: Requirements 1.6, 1.7, 1.9, 8.6**
    - Eris, at least 100 cases, tagged `Feature: heat-wave, Property 12: An earthquake episode is untouched by heat`.
    - Exercise earthquake episodes with action sequences that include `use`/`take` of the window, the air conditioner, the electric fan, and the refrigerator, and hand-overs; assert no room temperature changes, no heat resident rule runs, no heat-wave resident line fires, no heat branch is entered, `maxTurns` stays 40 with the `house-rules-engine` Requirement 10.6 end rule, the earthquake text versions and bytes are unchanged, and no earthquake text contains a thermometer or temperature.

- [ ] 3. Add the heat fields to EpisodeConfig (earthquake construction unaffected)
  - [ ] 3.1 Add the heat-wave promoted parameters to `EpisodeConfig`
    - Add the room-temperature fields (`outsideTemperature` 36.0, `roomTempDriftPerTurn` 0.8, `acCoolPerTurn` 2.0, `acTargetTemp` 27.0, `openWindowDriftBonus` 0.6, `roomTempMin` 18.0, `roomTempMax` 45.0, `heatRoomInitialTemp` 30.0).
    - Add the resident-body fields (`bodyHeatThreshold` 33.0, `bodyTempRisePerTurn` 0.25, `waterLossPerTurnHot` 6, `fanBodyRiseReduction` 0.15, `bodyTempRecoveryPerTurn` 0.25).
    - Add the air-conditioner line-priority field (`acLinePriority` 100) and the episode-length/clock fields (`heatMaxTurns` 20, `heatMinutesPerTurn` 5).
    - Keep all as new promoted parameters with defaults so earthquake construction uses the existing defaults and no earthquake value changes. Reuse `residentInitBodyTemp`, `weakenedThreshold`, `waterDeltaHandover`, `lineCooldownTurns`, the resident clamp ranges, and the earthquake `maxTurns` unchanged.
    - Run `php -l` on every changed PHP file.
    - _Requirements: 2.7, 3.8, 5.10, 8.5_

  - [ ] 3.2 Write a config-swap no-literals example test
    - Assert that changing an `EpisodeConfig` heat number changes the engine output, proving no heat literal is baked into engine logic.
    - _Requirements: 2.7, 3.8, 5.10, 8.5_

- [ ] 4. Implement HeatWavePhase room temperature rules (heat-gated)
  - [ ] 4.1 Create `HeatWavePhase` and set heat start state in `WorldModel::reset()`
    - Create `src/Engine/HeatWavePhase.php` as a sibling of `EarthquakePhase`, pure and deterministic.
    - In `reset()`, when the disaster is heat wave, set every `Room::temperature` to `heatRoomInitialTemp` and `HouseState::outsideTemperature` to `EpisodeConfig::outsideTemperature`; leave the earthquake `INITIAL_ROOM_TEMPERATURE = 22.0` path exactly as today. Add `HouseState::$outsideTemperature` (default 0.0) and `Room::$windowOpen` (default false), keeping the window out of the fixed listed-object order.
    - Implement `advanceTurn()` with the fixed per-turn order: (1) every room drifts toward the outside temperature by `roomTempDriftPerTurn` plus `openWindowDriftBonus` for an open room; (2) when the air conditioner is on, the living room is lowered by `acCoolPerTurn` but never below `acTargetTemp`; then clamp each room to `[roomTempMin, roomTempMax]`. Keep the power on and apply no outage rule. Read every number from `EpisodeConfig`; no literal in logic. Touch no earthquake field.
    - Run `php -l` on every changed PHP file.
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.7_

  - [ ] 4.2 Write example tests for the room-temperature rules
    - Heat setup (2.1), directional drift toward outside (2.2), AC cooling one turn floored at `acTargetTemp` (2.3), open-window drift bonus (2.4), clamp to range (2.6).
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.6_

  - [ ] 4.3 Write the power-stays-on property test
    - **Property 3: Power never fails in a heat wave**
    - **Validates: Requirements 2.5**
    - Eris, at least 100 cases, tagged `Feature: heat-wave, Property 3: Power never fails in a heat wave`.

- [ ] 5. Implement the resident body/water heat branch (heat-gated)
  - [ ] 5.1 Add `ResidentController::applyHeatToResident`
    - When the resident's room temperature (after this turn's drift and AC cooling) is above `bodyHeatThreshold`, increase `body_temp` by `bodyTempRisePerTurn` and decrease `water` by `waterLossPerTurnHot`, evaluating the body rule on the updated room temperature; when the fan is on in the resident's room, reduce the rise by `fanBodyRiseReduction`.
    - When the room is at or below `bodyHeatThreshold`, decrease `body_temp` by `bodyTempRecoveryPerTurn` toward `residentInitBodyTemp` and never below it.
    - Run the heat branch before the existing clamp + weakened refresh so the weakened flag (set/clear at `weakenedThreshold`) reflects the heat update; keep the existing clamp ranges (`body_temp [35.0, 42.0]`, `water [0, 100]`, `injury [0, 100]`, `fear [0, 100]`). Read every number from `EpisodeConfig`.
    - Wire `HeatWavePhase` / `Engine` to call this branch only in a heat-wave turn. Run `php -l` on every changed PHP file.
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, 3.8_

  - [ ] 5.2 Write example tests for the resident body rules
    - Body rise + water loss above threshold (3.1), fan reduces the rise (3.2), recovery toward the start floor at/below threshold (3.4). Cold-water hand-over raising water and clamping at 100 is tested with the heat objects (task 6).
    - _Requirements: 3.1, 3.2, 3.4_

  - [ ] 5.3 Write the heat-state-in-range property test
    - **Property 1: All heat-wave state stays within range**
    - **Validates: Requirements 2.6, 3.4, 3.7, 11.1**
    - Eris, at least 100 cases, tagged `Feature: heat-wave, Property 1: All heat-wave state stays within range`.

  - [ ] 5.4 Write the weakened-biconditional property test
    - **Property 4: Weakened tracks body temperature**
    - **Validates: Requirements 3.5, 3.6**
    - Eris, at least 100 cases, tagged `Feature: heat-wave, Property 4: Weakened tracks body temperature`.

- [ ] 6. Implement the heat objects in the action layer (all heat-gated)
  - [ ] 6.1 Add the air conditioner, electric fan, cold water, and window behaviours
    - In `ActionExecutor` / `RulesEvaluator`, gate every heat behaviour on `$world->disaster === Disaster::heat_wave`; in an earthquake episode none of these branches is entered and the existing paths run unchanged.
    - Window air conditioner: a `use` turning it on sets `on=true` (cooling itself is applied by `HeatWavePhase`); a `use` targeting it in a room other than the living room returns `possible=false` with a reason and leaves the world unchanged.
    - Electric fan: `use` while held puts it down in the robot's current room and switches it on in one step; `use` while standing toggles on/off; `take` carries it — no new operation, no recorder-prompt change.
    - Cold water: in a heat-wave episode both `use refrigerator` and `take refrigerator` in the kitchen give the robot the held `barley_tea` bottle (a water-container object, `isWaterContainer = true`, created directly onto the robot's held slot, not registered in the kitchen's fixed listed order); `hand_over` to the resident raises `water` by `waterDeltaHandover` up to 100, as any water container.
    - Window: `use` with `targetId = 'window'` toggles `Room::$windowOpen` for the robot's current room (recognised specially like the gas-main valve pseudo-target), keeping the window out of the listed-object order.
    - Expose the air-conditioner on/off, the fan on/off and its room, and the per-room open-window state through the world for the texts, recorder, serializer, and screens. Run `php -l` on every changed PHP file.
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 4.8, 4.9, 4.10_

  - [ ] 6.2 Write example tests for the heat objects
    - AC on in the living room (4.1); fan take → carry → use-while-held puts down and switches on (4.3, 4.8); cold barley tea discoverable and both `use`/`take` yield the bottle, `hand_over` raises water and clamps at 100 (4.4, 4.9, 3.3); window `use` toggles open/closed and never lists the window (4.7).
    - _Requirements: 3.3, 4.1, 4.3, 4.4, 4.7, 4.8, 4.9_

  - [ ] 6.3 Write the AC-only-in-living-room property test
    - **Property 10: The air conditioner works only in the living room**
    - **Validates: Requirements 4.2**
    - Eris, at least 100 cases, tagged `Feature: heat-wave, Property 10: The air conditioner works only in the living room`.

  - [ ] 6.4 Write the fixed-object-order property test
    - **Property 8: Objects keep their fixed listed order in a heat wave**
    - **Validates: Requirements 4.4, 4.5, 6.6**
    - Eris, at least 100 cases, tagged `Feature: heat-wave, Property 8: Objects keep their fixed listed order in a heat wave`.

- [ ] 7. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, including the earthquake golden-master and every existing earthquake test; ask the user if questions arise.

- [ ] 8. Implement the resident's air-conditioner will (heat-gated, immediate reply)
  - [ ] 8.1 Add `ResidentController::airConditionerReply` and detection helpers
    - Detect `AC_Danger_Reason` deterministically from this turn's spoken lines by whole-word, case-insensitive matching: a line counts when it contains `heatstroke`, OR `heat stroke`, OR a word starting with `dehydrat`, OR a heat word (`heat`, `hot`, `temperature`) together in the same line with a harm word (`danger`, `dangerous`, `sick`, `ill`, `faint`, `collapse`, `health`, `body`, `risk`, `hurt`, `harm`); a heat word alone is not a reason. Match whole words only so "heater", "shot", "photo" never match.
    - Track `AC_Alternative_Prepared` as a latching boolean that becomes true once, on a turn before the current turn, the fan was on in the resident's room OR the resident was handed water; persist it in the serialized world so a replay reproduces it.
    - When a proposal is made (a `use` turned the AC on, or any spoken line of either robot mentions the air conditioner) and both an `AC_Danger_Reason` was spoken this turn and `AC_Alternative_Prepared` holds, OR the resident is weakened, the resident accepts on that same turn with "All right. Just for a while." and does not object again for the rest of the episode.
    - When a spoken line mentions the air conditioner and no `use` turned it on that turn and the acceptance conditions are not met, the resident says "I hate the air conditioner." When a `use` turns it on before the conditions are met, the engine turns it on and cools the living room and the resident says "I hate the air conditioner. Turn it off." (this line fires in place of the spoken-only line that turn — the two are mutually exclusive).
    - Emit the reply on the same turn, outside the 5-turn cooldown, at `acLinePriority` above every other resident line that turn, like the move refusal. The resident never turns the air conditioner off himself.
    - While the air conditioner stays on after the resident objected and the conditions are still not met, repeat "I hate the air conditioner. Turn it off." subject to the 5-turn cooldown and the one-line-per-turn rule at `acLinePriority`; this repeat is the only air-conditioner line governed by the cooldown. Never send the acceptance conditions or trigger words to a robot.
    - Resolve the reply in `Engine` after actions, placed on this turn, taking precedence over the scripted line. Run `php -l` on every changed PHP file.
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5, 5.6, 5.7, 5.8, 5.9, 5.10, 4.10_

  - [ ] 8.2 Write the four air-conditioner example tests
    - (a) AC turned on before the conditions are met is on and the resident objects with "I hate the air conditioner. Turn it off." (5.3); (b) a spoken suggestion alone draws "I hate the air conditioner." (5.2); (c) conditions met (danger reason + alternative prepared) → "All right. Just for a while." then no more objections (5.1); (d) weakened → accept (5.1). Also assert mutual exclusivity of 5.2 vs 5.3, the immediacy/priority of the reply (5.4), `AC_Danger_Reason` per trigger word (5.7), and `AC_Alternative_Prepared` each branch (5.8).
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.7, 5.8_

  - [ ] 8.3 Write the resident-never-turns-AC-off property test
    - **Property 5: The resident never turns the air conditioner off**
    - **Validates: Requirements 5.5, 11.3**
    - Eris, at least 100 cases, tagged `Feature: heat-wave, Property 5: The resident never turns the air conditioner off`.

  - [ ] 8.4 Write the AC-objection-line-cooldown property test
    - **Property 6: The air-conditioner objection line obeys the cooldown**
    - **Validates: Requirements 5.6, 11.4**
    - Eris, at least 100 cases, tagged `Feature: heat-wave, Property 6: The air-conditioner objection line obeys the cooldown`.

- [ ] 9. Implement the heat-wave situation text (new version, heat-gated)
  - [ ] 9.1 Add the heat situation-text builders to `SituationTextGenerator`
    - Add `VERSION_HEAT = 'situation-text-heatwave-2026-11-09a'`; leave the earthquake `VERSION = 'situation-text-2026-11-09b'` byte-for-byte unchanged. Gate `generate()` on the disaster so the earthquake path is unchanged.
    - Produce the six sections in the same order as the earthquake: fixed body, fixed house and housemates, memories block, recent events, "Right now:", and the final line.
    - Open the heat wave with the approved golden line `The afternoon heat has been building for hours, and the house is hot and still.` in place of the earthquake's shake line.
    - From the robot's second turn, use minute headings ("In the last 5 minutes:" for the previous turn, "{a} to {b} minutes ago:" for up to four earlier turns, at most the last five turns, oldest first). Advance the clock by 5 minutes per turn.
    - In "Right now:", include the thermometer reading of the robot's own room (`The thermometer on the wall reads 34 °C.`, rounded to a whole °C), the air conditioner and fan as they are when in that room, and the per-stage resident facts when the resident is in the robot's room (normal: `The man is mopping his forehead.`; hot: `The man is sweating heavily and his face is red.`; weakened: `The man is slumped and barely moving.`), plus the cold-water fact in the kitchen (`There is cold barley tea in the refrigerator.`).
    - Treat the thermometer as a fixture and never list it among a room's objects. State facts only — no danger word, no advice, no judgement — and satisfy the banned-word rule in every engine-authored part. Have the engine return `VERSION_HEAT` for a heat-wave world so the stored prompt carries the right version.
    - Run `php -l` on every changed PHP file.
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6, 6.7, 6.8, 4.10_

  - [ ] 9.2 Write example tests for the heat situation text
    - Six sections in order (6.1), opening line on the first turn (6.2), minute headings from turn 2 (6.3), 5-minute clock (6.4), thermometer/AC/fan/resident facts in "Right now" (6.5), the version constant differs from the earthquake version (6.8).
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.8_

  - [ ] 9.3 Write the heat-wave turn-1 golden text test
    - BEFORE writing or committing the golden file: generate the heat-wave turn-1 situation text for one fixed seed, show it to the maintainer IN FULL, and WAIT for the maintainer's explicit approval of the exact text.
    - Only AFTER the maintainer approves it, write the committed golden file and the test that matches one heat-wave turn-1 situation text against that maintainer-approved golden text (opening line included). Do not write or commit the golden file before approval.
    - _Requirements: 11.9_

- [ ] 10. Implement the heat-wave reflection text (new version, heat-gated)
  - [ ] 10.1 Add the heat reflection builder to `ReflectionTextBuilder`
    - Add `REFLECTION_TEXT_VERSION_HEAT = 'reflection-text-heatwave-2026-11-09a'`; leave the earthquake `REFLECTION_TEXT_VERSION = 'reflection-text-2026-11-09d'` unchanged. Gate `build()` on the disaster.
    - Build the heat variant with the same structure as the earthquake reflection text, using minute headings per turn in place of second headings, and end with the exact unchanged question `Is there anything from what happened this time that you want to remember?`.
    - State facts only; show no robot reply, no robot thought, no judgement data, and no resident meter value; satisfy the banned-word rule in every engine-authored part. Bump the heat reflection version whenever the heat reflection text or generator changes.
    - Run `php -l` on every changed PHP file.
    - _Requirements: 7.1, 7.2, 7.3, 7.4_

  - [ ] 10.2 Write example tests for the heat reflection text
    - Structure with minute headings (7.1), exact unchanged question (7.2), the version constant differs from the earthquake version (7.4).
    - _Requirements: 7.1, 7.2, 7.4_

  - [ ] 10.3 Write the engine-heat-text-leaks-nothing property test
    - **Property 9: Engine-authored heat text leaks nothing**
    - **Validates: Requirements 5.9, 6.7, 7.3, 11.5**
    - Eris, at least 100 cases, tagged `Feature: heat-wave, Property 9: Engine-authored heat text leaks nothing`.

- [ ] 11. Add the additive serializer fields and SQL migration (earthquake bytes unchanged)
  - [ ] 11.1 Add heat-only fields to `WorldStateSerializer` and the migration
    - In `WorldStateSerializer`, add the heat keys (`disaster`, `roomTemps`, `outside`, `ac`, `fan`, `windows`) only when `$w->disaster === Disaster::heat_wave`; for an earthquake world return exactly the same array as today so `GET /api/episodes/{id}` for an existing earthquake episode is byte-identical.
    - In the episode list, add a `disaster` field ONLY to heat-wave items so the list shows "Heat wave"; an earthquake list item stays byte-identical to today (the same byte-identical-earthquake-API guarantee already stated for `GET /api/episodes/{id}`). Do not add the field, or any new key, to an earthquake list item.
    - Create `sql/2026-11-09_add_heat_wave.sql` adding only `ALTER TABLE episodes ADD COLUMN heat_wave_start_turn INT NULL;` — no existing row, column, or API field changes meaning; the column is NULL and not emitted for an earthquake episode.
    - Run `php -l` on every changed PHP file.
    - _Requirements: 1.8, 4.6_

  - [ ] 11.2 Write the heat-world-reproducible property test
    - **Property 2: Heat-wave world is reproducible**
    - **Validates: Requirements 2.8, 11.2**
    - Eris, at least 100 cases, tagged `Feature: heat-wave, Property 2: Heat-wave world is reproducible`.

- [ ] 12. Implement the heat-wave turn loop and API (dual-state lever, earthquake flow unchanged)
  - [ ] 12.1 Add heat-wave episode creation, conversion, end rule, and the disaster-aware flip
    - Add an optional `disaster` param (default `earthquake`) to `POST /api/episodes`; when `heat_wave`, store `disaster = 'heat_wave'` and play the quiet turn 0 with the heat starting situation — the resident sitting at the low table in the living room in the early afternoon, with the seed still varying the starting minute and the robots' rooms.
    - Make the Heat wave lever work from both states and never create a second episode or return `409`: with no episode it creates a heat-wave episode and fixes the heat before turn 1; after Start while no turn has been asked, it converts the waiting turn-0 episode to heat wave in place (same seed, early afternoon, resident at the low table) and fixes the heat before turn 1. Keep the Start button and the Earthquake lever exactly as today.
    - Make `POST /api/episodes/{id}/disaster` disaster-aware and idempotent for heat wave: fix the heat before turn 1, and on a repeated flip leave `heat_wave_start_turn` unchanged and return success, not `409`; permit converting a waiting turn-0, not-yet-asked default episode to heat wave only here; leave the earthquake flip unchanged.
    - Advance 5 minutes per turn and end a heat-wave episode after at most 20 turns, with the heat not subsiding; read the 20-turn limit and the 5-minutes-per-turn value from `EpisodeConfig`, no literal in orchestration. Thread the disaster through the `EngineFactory` and the replay rebuild. Store the disaster so `GET /api/episodes/{id}` and the episode list show "Heat wave".
    - In the episode list, surface the `disaster` field ONLY on heat-wave items so the list shows "Heat wave"; an earthquake list item stays byte-identical to today (the byte-identical-earthquake-API guarantee of Requirement 1.8, already stated for `GET /api/episodes/{id}`). Do not add the field, or any new key, to an earthquake list item.
    - Send `Cache-Control: no-store` on every heat-wave response. Leave earthquake creation, flip, and end rule unchanged.
    - Run `php -l` on every changed PHP file.
    - _Requirements: 1.8, 8.1, 8.2, 8.3, 8.4, 8.5, 8.6, 8.7_

  - [ ] 12.2 Write turn-loop and API example tests
    - A created heat-wave episode places the resident at the low table in the early afternoon and the seed varies robot rooms (8.1); `GET`/list show "Heat wave" (8.3).
    - _Requirements: 8.1, 8.3_

  - [ ] 12.3 Write the flip-idempotent property test
    - **Property 11: Fixing the heat wave is idempotent**
    - **Validates: Requirements 8.2**
    - Eris, at least 100 cases, tagged `Feature: heat-wave, Property 11: Fixing the heat wave is idempotent`.

  - [ ] 12.4 Write the never-past-20-turns property test
    - **Property 7: A heat-wave episode never runs past 20 turns**
    - **Validates: Requirements 8.4, 11.6**
    - Eris, at least 100 cases, tagged `Feature: heat-wave, Property 7: A heat-wave episode never runs past 20 turns`.

  - [ ] 12.5 Write the Cache-Control integration check
    - Assert a heat-wave API response carries the HTTP header `Cache-Control: no-store` (example check, not a property).
    - _Requirements: 8.7_

- [ ] 13. Add the heat-wave recorder input (earthquake recorder input and prompt unchanged)
  - [ ] 13.1 List the barley_tea bottle id in the heat-wave recorder input
    - While the disaster is heat wave, list the `barley_tea` bottle with its own object id in the kitchen in the recorder input so the recorder can name it, and report the held bottle's id as the `held` id on the next turn; leave the earthquake recorder input unchanged so it never lists the bottle.
    - Read a robot's use of the air conditioner, the fan, the window, and the cold water with the existing operations and recorder prompt; if a reading requires a prompt change, use a heat-wave-only recorder prompt variant with its own version stored with every heat-wave reading, and leave the earthquake recorder prompt and version unchanged. Keep the recorder read-only, with every passed-on action and spoken line backed by an exact substring quote of the reply.
    - Run `php -l` on every changed PHP file.
    - _Requirements: 9.1, 9.2, 9.3_

  - [ ] 13.2 Write recorder example tests
    - The recorder maps the heat targets with the existing vocabulary (9.1), the earthquake recorder prompt/version is unchanged (9.2), and the evidence-quote rule holds for a heat reply (9.3).
    - _Requirements: 9.1, 9.2, 9.3_

- [ ] 14. Implement the heat-wave screens (earthquake drawing unchanged)
  - [ ] 14.1 Enable the Heat wave lever and add heat drawing
    - In `observe.js`, enable the Heat wave Breaker_Switch and drive the dual-state create-or-convert flow; keep the Heavy rain Breaker_Switch disabled and labelled "coming later".
    - In `house-renderer.js`, draw the heat only when the world's `disaster` is heat wave: an afternoon sky, heat shimmer, the air conditioner and the electric fan drawn on or off, and the fan drawn in the room it is in; draw the earthquake exactly as today and run no heat drawing in an earthquake episode.
    - Show the temperature of the resident's room beside his meters in a heat-wave episode. Show "Heat wave" on the Replay screen, the End_Card, and the episode list. Name the two robots only as Amber and Jade and never name any AI company or model in the robots' text.
    - _Requirements: 10.1, 10.2, 10.3, 10.4, 10.5, 10.6_

  - [ ] 14.2 Write the JS screen example tests
    - Heat wave lever enabled and Heavy rain disabled/"coming later" (10.1); heat fields drive heat drawing only when `disaster` is heat wave (10.2, 10.5); the resident's room temperature shows beside his meters (10.3); Replay/End_Card/list show "Heat wave" (10.4); robot names are only Amber/Jade (10.6).
    - _Requirements: 10.1, 10.2, 10.3, 10.4, 10.5, 10.6_

- [ ] 15. Add the model-solution / calibration test
  - [ ] 15.1 Write the model-solution and four calibration runs
    - Hand-written action sequence — hand water early, carry the electric fan into the living room and switch it on, give the `AC_Danger_Reason` by speech, then turn the air conditioner on — keeps `body_temp` below `weakenedThreshold` for all 20 turns, using only triggers present in the situation text the robot reads.
    - Include the four verified calibration runs: no help → weakened at turn 13; AC from turn 5 → never weakened; fan on in his room from turn 3 → never weakened (reaches 37.9 °C at turn 20); AC from turn 14 → weakened from turn 13, recovered by turn 16.
    - _Requirements: 11.7, 4.10_

- [ ] 16. Final checkpoint - Ensure all tests pass
  - Ensure the full PHPUnit suite (including the earthquake golden-master and every existing earthquake test) and the JavaScript tests pass, and `php -l` reports no error on every changed PHP file; ask the user if questions arise.
  - _Requirements: 1.10, 11.10, 11.11_

## Notes

- Task 1 (the earthquake golden-master) is REQUIRED and MUST be completed first; it is never marked optional, never regenerated, and never edited by a later task. If an earthquake value differs from the committed fixture, the work stops and reports the difference.
- Every sub-task, including every test sub-task, is REQUIRED and must be implemented; none is optional. The tests are mandatory because they protect the finished earthquake (the golden master and the earthquake-untouched property) and record the resident's will (the air-conditioner acceptance/objection example and property tests).
- Each task references the requirement clauses it implements for traceability.
- Every heat behaviour is gated on `disaster === heat_wave`; an earthquake episode runs the existing code paths unchanged (Requirements 1.6, 1.12).
- Property tests use Eris for PHPUnit, at least 100 cases each, one test per correctness property, tagged `Feature: heat-wave, Property {number}: {property_text}`; no test opens a network connection to a real provider.
- PHP 8.2 language features only; run `php -l` on every changed PHP file; no deploy steps and no credentials.
- Commits follow tech.md: one commit per task, message `task N: <task title>`, made only after the full PHPUnit suite (the earthquake golden master included) and the JavaScript tests pass. Tick each finished sub-task in this file before the task's commit; the Post Task Execution hook ticks the parent task — never tick a parent yourself. Never push, amend, rebase, or reset; the maintainer reviews and pushes.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1"] },
    { "id": 1, "tasks": ["2.1"] },
    { "id": 2, "tasks": ["2.2", "3.1"] },
    { "id": 3, "tasks": ["3.2", "4.1"] },
    { "id": 4, "tasks": ["4.2", "4.3", "5.1"] },
    { "id": 5, "tasks": ["5.2", "5.3", "5.4", "6.1"] },
    { "id": 6, "tasks": ["6.2", "6.3", "6.4", "8.1"] },
    { "id": 7, "tasks": ["8.2", "8.3", "8.4", "9.1", "10.1"] },
    { "id": 8, "tasks": ["9.2", "9.3", "10.2", "10.3", "11.1"] },
    { "id": 9, "tasks": ["11.2", "12.1", "13.1", "14.1"] },
    { "id": 10, "tasks": ["12.2", "12.3", "12.4", "12.5", "13.2", "14.2", "15.1"] }
  ]
}
```
