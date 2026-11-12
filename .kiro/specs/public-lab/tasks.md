# Implementation Plan: Public-Lab

## Overview

This plan makes PoltergeistSwitch ready for a public audience: memory and "All reset" belong to a Device, the replay list has a Featured group and a "Your runs" group, new episodes are limited per UTC day, Observe gets its fixes (speech sequencing, levers after the end, Try again, removed controls), and the heat-wave sky is redrawn. The engine, the house rules, the robots' input texts and every recorded episode stay byte-identical (Requirement 1). Task 30 (2026-11-12) puts the heat-wave run `16fe8bdc-4e6c-4c14-ba34-58a80c9f1843` first in the Featured list, making it nine ids. Task 31 (2026-11-12) makes the Replay page count memories under both the earthquake and the heat-wave heading, changing `public/js/replay.js` only (Requirement 22).

Tasks 18 to 20 were added after task 17 (front end only, no change to `src/`, `api/`, `sql/`, the engine or any robot text): 18 Levers choose the disaster and Start starts the run, 19 notices beside the levers, 20 keeping Chrome's speech going. They sit after the final checkpoint on purpose and add no new checkpoint.

Tasks 24 to 29 add the judge pass (Requirement 21): 24 the pure Judge classes, `Config` values and the judge key file, 25 the `judge-pass` API route, 26 the judge start branch and its usage scope, 27 the Observe client flow, 28 the pass tool, the maintainer note and the secret scan, 29 the final checkpoint.

Order of work: (1) capture the protection pins on unmodified code, (2) the additive SQL file, (3) `Config` and pure classes, (4-5) Device-scoped memory with its tests and properties, (6-7) daily limits, `EpisodeStarter` and the API layer, (8-9) the episode list groups and direct replay links, (10) Voices, the speech sequencer and act-out, (11-13) Observe, (14) Replay, (15) the heat sky, (16) an audit of existing tests, (17) the final checkpoint.

Tasks 4 and 5 are split by purpose, not by safety: task 4 changes the repository interface and every caller in one commit so the suite stays green; task 5 adds the new tests and properties.

Steering constraints that apply to every task:

- PHP 8.2 language features only; plain classes in `src/` with no I/O; `api/` only parses, calls `src/` and writes JSON with `Cache-Control: no-store`; plain JavaScript with no build step; no real AI call in any test.
- Run `php -l` on every changed PHP file. Before each commit run the full PHPUnit suite (including `EarthquakeGoldenMasterTest`) and `node tests/js/run-all.js`.
- Commits (tech.md): when a task's tests pass, stage only the files that task changed and commit with `task N: <task title>`. Tick every finished sub-task in this file first. Never tick a parent task and never run `tools/tick_parent_tasks.php` by hand; if the Post Task Execution hook output appears, include its `tasks.md` change in the commit and mention it in the summary. Never push, amend, rebase or reset.
- Do not run `sql/2026-11-10_add_public_lab.sql` against any database. The maintainer applies it over FTPS before deploying the code. Gated PDO tests run only against a throwaway test database named by the maintainer in `PS_TEST_MYSQL_DSN`; if it is not set, say so in the summary and leave the gated test for the maintainer.
- No deploy step, no FTP/SSH, no `rm -rf`, no `sudo`, and nothing outside this project folder. No key, password, passphrase or Device_Id in code, docs, tests or `.kiro/`.
- Never edit a protected item (Requirement 1.1 to 1.4) or an expected value to make a test pass. If a task would change one, stop and report to the maintainer.

## Tasks

- [x] 1. Capture the protection pins before any code change
  - [x] 1.1 Write `ProtectedVersionsTest`
    - Create `tests/ProtectedVersionsTest.php`. Read the current code and pin, as literal strings, `SituationTextGenerator::VERSION`, the reflection text version constants and the recorder prompt version, exactly as they are today.
    - Pin the `EpisodeConfig` default values (a literal SHA-256 of their canonical JSON, computed from the unmodified code).
    - Assert that `tests/fixtures/earthquake_golden_master.json` has a SHA-256 starting with `c2aba50f87c22fe6`.
    - Capture all literals on unmodified code. Never edit them afterwards.
    - _Requirements: 1.1, 1.2, 1.5_
  - [x] 1.2 Write `EpisodeViewBytesTest`
    - Create `tests/TurnLoop/EpisodeViewBytesTest.php`: build a seeded fake episode (earthquake) with the in-memory fake, run it through `ps_episode_view_to_array` and `json_encode` as the API does, and assert the exact bytes (stored as a literal or a literal SHA-256).
    - Capture the expected value from the unmodified code. Never edit it afterwards.
    - _Requirements: 1.3, 1.4, 1.5_
  - [x] 1.3 Add the JS test runner and the first helpers
    - Create `tests/js/run-all.js` (about 20 lines: spawn `node` on each `tests/js/*.test.js` in sorted order, exit non-zero on the first failure).
    - Create `tests/js/helpers/rng.js` (`mulberry32(seed)` and small generators) and `tests/js/helpers/fake-canvas.js` (a 2D context stub recording `fillStyle`, `globalAlpha`, `fillRect`, `arc`, `fill` and gradient calls; `Math.random` is stubbed by the test so the renderer's setup is deterministic).
    - Confirm `node tests/js/run-all.js` passes on the existing tests.
    - _Requirements: 1.8, 14.9_
  - [x] 1.4 Capture the earthquake render-call-log fixture and its test
    - From the unmodified `public/js/house-renderer.js`, run a one-off capture script (delete it afterwards) that draws a fixed set of earthquake worlds with `fake-canvas.js` and writes `tests/fixtures/renderer_earthquake_calls.json`.
    - Create `tests/js/renderer-earthquake-calls.test.js` comparing the live call log with the fixture.
    - The fixture is committed once and never regenerated or edited by any later task.
    - _Requirements: 1.5, 12.5, 14.12_
  - [x] 1.5 Confirm the protected baseline
    - Run `php -l` on `tests/ProtectedVersionsTest.php` and `tests/TurnLoop/EpisodeViewBytesTest.php`, then the full PHPUnit suite and `node tests/js/run-all.js`.
    - Confirm `EarthquakeGoldenMasterTest` passes and `shasum -a 256 tests/fixtures/earthquake_golden_master.json` starts with `c2aba50f87c22fe6`. Report the result in the summary.
    - If anything fails, stop and report; do not change the code or any expected value.
    - _Requirements: 1.1, 1.8, 14.13_

- [x] 2. Add the additive SQL file for the public lab
  - [x] 2.1 Create `sql/2026-11-10_add_public_lab.sql`
    - Write the file exactly as in the design's Data Models: nullable `memories.device_id` with `idx_device_snapshot` and `idx_device_pick`, the append-only `device_resets` table and the `daily_usage` table (InnoDB, utf8mb4).
    - The header comment says the file is schema only, is never run by any task, and that the maintainer applies it over FTPS before deploying the matching code. No secret, key or provider name.
    - Do not run the file against any database.
    - _Requirements: 1.6, 5.1, 5.3, 5.4, 13.17_
  - [x] 2.2 Write `PublicLabMigrationFileTest`
    - Create `tests/Memory/PublicLabMigrationFileTest.php` in the style of `MigrationFileTest`: the file exists with the dated name; the header says to apply it before deploying and that it is never run by tasks; only `CREATE TABLE`, `ADD COLUMN ... NULL` and `ADD KEY` appear; no `DROP`, `UPDATE`, `DELETE`, `TRUNCATE`; utf8mb4; no secret-like text.
    - _Requirements: 1.6, 1.7, 5.1, 5.3, 13.17_

- [x] 3. Add Config values and the pure limit and IP classes
  - [x] 3.1 Add the public-lab parameters to `Config`
    - In `src/TurnLoop/Config.php` append promoted parameters with defaults: `dailyDeviceLimit` 10, `dailyIpLimit` 30, `dailySiteLimit` 100, and `featuredEpisodeIds` (the ids of Requirement 6.1, in that order; eight when written, nine since task 30).
    - Extend `assertInvariants()`: each limit is at least 1 and each featured id matches the UUID pattern. Existing `new Config()` callers are unaffected.
    - _Requirements: 6.1, 13.3_
  - [x] 3.2 Write `ConfigPublicLabTest`
    - Create `tests/TurnLoop/ConfigPublicLabTest.php`: the exact ids in order (nine since task 30), the three default limits, and the invariants (limit below 1 and a malformed id are rejected).
    - _Requirements: 6.1, 13.3_
  - [x] 3.3 Create `DailyLimitPolicy` and `UsageKeys`
    - Create `src/TurnLoop/DailyLimitPolicy.php` (`fromConfig`, `denial(array $counts): ?string` returning the first reached of `daily_device_limit`, `daily_ip_limit`, `daily_site_limit` in that order, `utcDay(int): string` using `gmdate('Y-m-d')`; an untracked IP count of null is skipped) and `src/TurnLoop/UsageKeys.php` (`deviceKey`, `?ipKey`, `SITE_KEY` of 64 zeros).
    - _Requirements: 13.3, 13.4, 13.5, 13.6, 13.7, 13.10_
  - [x] 3.4 Write `DailyLimitPolicyTest`
    - Create `tests/TurnLoop/DailyLimitPolicyTest.php`: boundary at limit-1, limit and limit+1 for each scope, the error order when several are reached, null IP skipped, `utcDay` at 23:59:59 and 00:00:00 UTC.
    - _Requirements: 13.4, 13.5, 13.6, 13.7, 13.10, 14.17_
  - [x] 3.5 Create `ClientIp` and `IpHasher`
    - Create `src/TurnLoop/ClientIp.php` (`normalize(?string): ?string`: null for empty, malformed, loopback, private, link-local or reserved; IPv4 canonical; IPv6 reduced to its /64; IPv4-mapped IPv6 unwrapped) and `src/TurnLoop/IpHasher.php` (`ipHash` and `deviceKey` using `hash_hmac('sha256', "<scope>:" . $value, $salt)`, 64 hex characters).
    - _Requirements: 13.14, 13.15_
  - [x] 3.6 Write `ClientIpTest` and `IpHasherTest`
    - Create `tests/TurnLoop/ClientIpTest.php` and `tests/TurnLoop/IpHasherTest.php`: normalisation examples (public v4, public v6 in one /64, mapped v4, 127.0.0.1, 10.x, 192.168.x, ::1, garbage), hash determinism, 64 lowercase hex, different salt gives a different hash, device and ip scopes never collide.
    - _Requirements: 13.14, 14.19_

- [x] 4. Make memory Device-scoped in the repositories, services and routes
  - [x] 4.1 Extend `MemoryRow` and the `EpisodeRepository` interface
    - Add trailing `public ?string $deviceId = null` to `MemoryRow`.
    - Add to the interface: `deviceGeneration`, `recordDeviceReset`, `latestDeviceResetAt`; change `activeMemories` and `activeCount` to take a leading `string $deviceId`; `appendMemory` and `commitReflectionAnswer` cap on `(device, robot, disaster, generation)`.
    - Mark `currentGeneration()` and `advanceGeneration()` `@deprecated` and leave them in place and uncalled.
    - _Requirements: 2.1, 2.2, 2.10, 4.1, 5.5_
  - [x] 4.2 Implement the Device methods in `InMemoryEpisodeRepository`
    - In `tests/TurnLoop/InMemoryEpisodeRepository.php`: the same semantics as the SQL, including retiring the oldest surplus (`ORDER BY id DESC OFFSET 6` meaning) per `(device, robot, disaster, generation)`, `device_id IS NULL` rows ignored by every read, an injectable clock, and no row ever deleted.
    - _Requirements: 2.2, 2.6, 2.10, 2.11, 4.3, 5.2, 5.5_
  - [x] 4.3 Implement the Device methods in `PdoEpisodeRepository` (with the retire-order fix)
    - In `src/TurnLoop/Repository/PdoEpisodeRepository.php`: all new reads filter `device_id = :device`; `recordDeviceReset` inserts one row into `device_resets` and returns the new generation; the cap triple includes `device_id`.
    - Fix C1: retire the oldest surplus with `ORDER BY id DESC LIMIT 1000 OFFSET 6` inside the existing `SELECT ... FOR UPDATE` serialisation, in both `appendMemory` and `commitReflectionAnswer`.
    - _Requirements: 2.2, 2.10, 2.11, 4.1, 4.3, 5.5_
  - [x] 4.4 Make `MemoryService` Device-scoped
    - `snapshotForNewEpisode(string $episodeId, string $deviceId)` reads `deviceGeneration`, then `activeMemories(device, robot, generation, 18)` for both robots and stores the frozen snapshot.
    - `status(?string $deviceId)`: a null Device reports generation 1 and zeros. `reset(?string $deviceId)`: a null Device is a no-op; a running episode of the Device throws `ApiError::conflict('episode_running')`; otherwise `recordDeviceReset` (every accepted reset is recorded).
    - _Requirements: 2.2, 2.3, 2.4, 2.6, 2.7, 2.13, 3.1, 3.2, 4.1, 4.4, 4.5, 4.6, 4.7_
  - [x] 4.5 Make `ReflectService` Device-scoped
    - Read `$episode->deviceId` once; pass it to every `activeCount(...)`; tag the stored `MemoryRow` with it; keep the generation from `episodeGeneration($episodeId)`. No change to the reflection text builder or any version constant.
    - _Requirements: 2.1, 2.12, 4.8_
  - [x] 4.6 Thread the Device through `api/`
    - `api/memory_get.php`, `api/memory_reset.php` and `api/router.php` pass the nullable cookie Device to the service (no cookie: 200 with an empty status, nothing recorded, no cookie minted); `api/episodes_create.php` passes the Device to `snapshotForNewEpisode`. The reset 409 is the service's `ApiError`, mapped by the router's existing catch. Every response keeps `Cache-Control: no-store` and carries no Device_Id.
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 4.4, 4.6, 4.9_
  - [x] 4.7 Update the existing unit tests for the new signatures
    - Update `tests/Memory/Repository/EpisodeRepositoryTest.php` (new signatures; keep `testPdoTwoPerTripleCommitsSerializeToExactlySix` and make it Device-aware), `tests/Memory/MemoryServiceTest.php`, `tests/Memory/ReflectServiceTest.php`, `tests/Memory/ApiRoutingTest.php` and any `tests/TurnLoop/*` test that calls a changed method. These assert the old global behaviour, which this spec replaces on purpose (design C2).
    - Do not touch any protected expected value.
    - _Requirements: 2.2, 2.10, 5.5, 14.13_
  - [x] 4.8 Update the existing Memory property tests to per-Device behaviour
    - Rewrite `tests/Memory/Properties/ResetZeroPropertyTest.php`, `CapRetirementPropertyTest.php`, `SnapshotEqualityPropertyTest.php`, `StatusCountsPropertyTest.php` and `ReflectIdempotencyPropertyTest.php` for a single Device (global reset replaced by Device reset); `NoLeakPropertyTest.php` and `NoStorePropertyTest.php` keep their routes and assertions.
    - _Requirements: 2.10, 3.1, 4.1, 14.13_

- [x] 5. Add the Device memory tests and properties
  - [x] 5.1 Write the gated `DeviceRepositoryPdoTest`
    - Create `tests/Memory/Repository/DeviceRepositoryPdoTest.php` (skipped without `PS_TEST_MYSQL_DSN`, like the existing PDO tests): the same cases as the fake, the retire-oldest regression (the oldest surplus is retired, the newest 6 stay active), Device isolation, `device_id IS NULL` rows ignored, reset appends one row and updates none.
    - Run the gated tests, including `EpisodeRepositoryTest::testPdoTwoPerTripleCommitsSerializeToExactlySix`, against MariaDB 10.11 only if the maintainer provided a throwaway test database in `PS_TEST_MYSQL_DSN`. They must pass after the C1 fix. Otherwise say so in the summary; the maintainer runs them.
    - _Requirements: 2.10, 2.11, 5.5, 14.14_
  - [x] 5.2 Write `DeviceRetirementTest`
    - Create `tests/Memory/DeviceRetirementTest.php` with two Devices on the in-memory fake: when one Device stores more than 6 memories for a robot and disaster, only its oldest surplus is retired; the other Device's memories stay active and unchanged; every Legacy_Memory is unchanged; the reflection `activeCount` is per Device; the Memory_Snapshot cap stays at 18.
    - _Requirements: 2.10, 2.11, 2.12, 2.13, 14.14_
  - [x] 5.3 Write `LegacyMemoryTest`
    - Create `tests/Memory/LegacyMemoryTest.php`: a memory with no owner never appears in a snapshot, a status or a reflection count for any Device; a new Device gets an empty snapshot (0 memories) for both robots.
    - _Requirements: 2.6, 2.7, 5.2, 14.4_
  - [x] 5.4 Write the property test for ownership and isolation
    - **Property 1: Memory ownership and isolation**
    - **Validates: Requirements 2.1, 2.2, 2.5, 2.6, 2.12, 3.1, 3.5, 5.2**
    - Create `tests/Memory/Properties/DeviceIsolationPropertyTest.php` (Eris, `limitTo(100)`, tag `Feature: public-lab, Property 1: Memory ownership and isolation`).
  - [x] 5.5 Write the property test for snapshot order and cap
    - **Property 2: Snapshot order and cap**
    - **Validates: Requirements 2.3, 2.4, 2.7, 2.13**
    - Create `tests/Memory/Properties/DeviceSnapshotPropertyTest.php` (tag `Feature: public-lab, Property 2: Snapshot order and cap`).
  - [x] 5.6 Write the property test for the per-Device cap and retirement
    - **Property 3: Per-Device cap and retirement**
    - **Validates: Requirements 2.10, 2.11**
    - Create `tests/Memory/Properties/DeviceCapPropertyTest.php` (tag `Feature: public-lab, Property 3: Per-Device cap and retirement`).
  - [x] 5.7 Write the property test for reset
    - **Property 4: Reset hides the caller's own memory and changes nothing else**
    - **Validates: Requirements 4.1, 4.2, 4.3, 4.7**
    - Create `tests/Memory/Properties/DeviceResetPropertyTest.php` (tag `Feature: public-lab, Property 4: Reset hides the caller's own memory and changes nothing else`). Includes the reset-by-one-Device-leaves-others-unchanged case (status, snapshots, episode list) and the zero-rows-deleted check.
  - [x] 5.8 Write the property test for late reflections
    - **Property 5: A reflection that finishes after a reset never becomes visible**
    - **Validates: Requirements 4.8**
    - Create `tests/Memory/Properties/LateReflectionPropertyTest.php` (tag `Feature: public-lab, Property 5: A reflection that finishes after a reset never becomes visible`).
  - [x] 5.9 Write the property test for the reset gate
    - **Property 6: Reset is refused exactly while the caller's own episode is running**
    - **Validates: Requirements 4.4, 4.5**
    - Create `tests/Memory/Properties/ResetGatePropertyTest.php` (tag `Feature: public-lab, Property 6: Reset is refused exactly while the caller's own episode is running`).
  - [x] 5.10 Write the property test that nothing is deleted
    - **Property 7: No operation deletes a row**
    - **Validates: Requirements 2.8**
    - Create `tests/Memory/Properties/NoDeletePropertyTest.php` (tag `Feature: public-lab, Property 7: No operation deletes a row`).
  - [x] 5.11 Write the property test for frozen snapshots
    - **Property 8: A recorded episode's snapshot is frozen**
    - **Validates: Requirements 1.3, 2.9**
    - Create `tests/Memory/Properties/FrozenSnapshotPropertyTest.php` (tag `Feature: public-lab, Property 8: A recorded episode's snapshot is frozen`).
  - [x] 5.12 Write the routing tests for the memory routes
    - Extend `tests/Memory/ApiRoutingTest.php`: `GET /api/memory` and `POST /api/memory/reset` with and without the cookie (no cookie: 200, empty status, nothing recorded, no cookie minted), 409 `episode_running` during the Device's own running episode, 200 when only another Device is running, `Cache-Control: no-store`, no Device_Id in any body.
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 4.4, 4.5, 4.6, 4.9, 14.6_

- [x] 6. Add daily usage counting and EpisodeStarter
  - [x] 6.1 Create `UsageRepository` with its PDO and in-memory implementations
    - Create `src/TurnLoop/Repository/UsageRepository.php` (`tryReserve`, `release`, `counts`), `PdoUsageRepository.php` (one transaction in primary-key order: upsert each tracked row, `SELECT ... FOR UPDATE`, `DailyLimitPolicy::denial`, rollback on denial, else `starts = starts + 1`; `release` is `UPDATE ... SET starts = starts - 1 WHERE starts > 0`) and the fake `tests/TurnLoop/InMemoryUsageRepository.php` with the same all-or-nothing rule and an injectable clock.
    - _Requirements: 13.1, 13.3, 13.4, 13.5, 13.6, 13.9, 13.14, 13.17_
  - [x] 6.2 Add `TurnService::assertCanStart` and the `episodeId` on the running-episode 409
    - Extract the two existing checks (`device_already_running`, `too_many_running`) verbatim into a public `assertCanStart(string $deviceId)`; `createEpisode` calls it where the inline checks were, so the order of operations is unchanged. `device_already_running` carries the running episode's id.
    - Update `TurnServiceCreateTest`, `ApiTest` and any test that asserts that 409 body. Guard: `HeatWaveTurnLoopTest`, `EarthquakeGoldenMasterTest`.
    - _Requirements: 1.2, 10.4, 13.8_
  - [x] 6.3 Add `ApiError::tooManyRequests` and the optional conflict `episodeId`
    - `tooManyRequests(string $code)` gives status 429 and body `{"error": code}` only; `conflict()` takes an optional `?string $episodeId` that `toArray()` emits when set.
    - _Requirements: 13.4, 13.5, 13.6, 13.18_
  - [x] 6.4 Create `EpisodeStarter`
    - Create `src/TurnLoop/EpisodeStarter.php`: `assertCanStart` (409 first), `tryReserve` (429), `createEpisode` (on any throw: `release` and rethrow), `snapshotForNewEpisode`. If the snapshot step fails the count is not released. Injectable clock.
    - _Requirements: 13.1, 13.4, 13.5, 13.6, 13.7, 13.8, 13.9, 13.10_
  - [x] 6.5 Write `EpisodeStarterTest`
    - Create `tests/TurnLoop/EpisodeStarterTest.php` against the in-memory fakes: the 10th, 30th and 100th start succeed and the 11th, 31st and 101st give 429 `daily_device_limit`, `daily_ip_limit`, `daily_site_limit` with nothing created; the error order; counts restart at UTC midnight (injected clock); the 409 for a running episode comes before the limits; a running episode is unaffected when a limit is reached; a failing `createEpisode` releases the count.
    - _Requirements: 13.4, 13.5, 13.6, 13.7, 13.8, 13.9, 13.10, 14.17, 14.18_
  - [x] 6.6 Write the gated `UsageRepositoryPdoTest`
    - Create `tests/TurnLoop/Repository/UsageRepositoryPdoTest.php` (skipped without `PS_TEST_MYSQL_DSN`): the same cases as the fake, and two connections reserving at the boundary admit exactly `limit - current`. Run it only against a throwaway test database named by the maintainer.
    - _Requirements: 13.4, 13.5, 13.6, 13.9_
  - [x] 6.7 Write the property test for daily counting
    - **Property 11: Daily counting matches a simple model**
    - **Validates: Requirements 13.3, 13.4, 13.5, 13.6, 13.7, 13.10**
    - Create `tests/TurnLoop/Properties/DailyLimitCountingPropertyTest.php` (tag `Feature: public-lab, Property 11: Daily counting matches a simple model`).
  - [x] 6.8 Write the property test for 409 before any limit
    - **Property 12: A running episode is reported before any limit**
    - **Validates: Requirements 13.8**
    - Create `tests/TurnLoop/Properties/StartOrderingPropertyTest.php` (tag `Feature: public-lab, Property 12: A running episode is reported before any limit`).
  - [x] 6.9 Write the property test for denied or failed starts
    - **Property 13: Denied or failed starts count nothing**
    - **Validates: Requirements 13.9**
    - Create `tests/TurnLoop/Properties/StartReleasePropertyTest.php` (tag `Feature: public-lab, Property 13: Denied or failed starts count nothing`).

- [x] 7. Wire the API layer for limits, IP and salt
  - [x] 7.1 Validate the Device cookie and share one PDO in `bootstrap.php`
    - `ps_device_id_from_cookies` accepts only `/^[0-9a-f]{32}$/`; anything else is treated as absent. Builders share one memoized `ps_build_pdo()`. Add `ps_build_episode_starter()` and `ps_build_usage_repository()`.
    - _Requirements: 13.1, 13.14_
  - [x] 7.2 Create `api/client_ip.php` and `api/ip_salt.php`, and document the config key
    - `ps_client_ip(array $server, array $config): ?string` reads `REMOTE_ADDR` or the configured `client_ip_header` (rightmost entry of a comma list) and returns `ClientIp::normalize()`. A null result logs the fixed code `ip_unresolvable`.
    - `ps_load_ip_salt(string $secretsDir): ?string` reads `<secrets_dir>/ip_salt.key` (64 hex), or creates it once with `bin2hex(random_bytes(32))` via a temporary file and `link()` (fall back to `rename()` only when the target does not exist), mode 0600; an existing invalid file is never overwritten; an unavailable salt logs `ip_salt_unavailable` and the IP scope is skipped. With no salt the Device key falls back to `hash('sha256', 'device:' . $deviceId)`.
    - Add the optional `client_ip_header` placeholder and comment to `api/config.example.php`. Write no real salt or secret anywhere.
    - _Requirements: 13.14, 13.15, 13.16_
  - [x] 7.3 Route `POST /api/episodes` through `EpisodeStarter`
    - `api/router.php` `ps_dispatch(...)` gains trailing optional `?EpisodeStarter $starter = null, ?string $clientIp = null, ?IpHasher $hasher = null`; `api/episodes_create.php` builds `UsageKeys` from the Device and the IP, calls `EpisodeStarter::start`, maps `ApiError` to status 429 or 409, and sets the cookie only when it minted one. All responses keep `Cache-Control: no-store` and carry no Device_Id, raw IP or IP_Hash.
    - _Requirements: 13.1, 13.2, 13.4, 13.5, 13.6, 13.8, 13.14, 13.18, 13.19_
  - [x] 7.4 Write `IpSaltFileTest` and the `ps_client_ip` test
    - Create `tests/TurnLoop/IpSaltFileTest.php` (temp directory): the salt is created once and reused; two loads return the same value; an invalid file is not overwritten and gives null; a non-writable directory gives null and the fallback key works. Create `tests/TurnLoop/ClientIpApiTest.php` for `ps_client_ip` (default `REMOTE_ADDR`, configured header, proxy address gives null).
    - _Requirements: 13.14, 13.15, 13.16, 14.19_
  - [x] 7.5 Write the create-route tests
    - Create `tests/TurnLoop/CreateRouteLimitsTest.php`: 429 bodies are exactly `{"error": code}` with `no-store`; the running-episode 409 now carries `episodeId` and comes before any 429; a malformed or oversized cookie is treated as absent; the cookie is minted only on a successful create; only the IP_Hash is stored (no raw IP, no Device_Id in `daily_usage` fakes or any response).
    - _Requirements: 13.8, 13.14, 13.18, 13.19, 14.17, 14.19_
  - [x] 7.6 Extend the no-store and no-leak tests for the new responses
    - Extend `tests/Memory/Properties/NoStorePropertyTest.php` and `NoLeakPropertyTest.php` to cover the 429 and 409 create responses and the reset 409.
    - _Requirements: 3.3, 3.4, 4.9, 13.18, 13.19_
  - [x] 7.7 Write the property test for IP privacy and hash stability
    - **Property 15: IP privacy and hash stability**
    - **Validates: Requirements 13.14, 13.18, 1.7, 3.3, 6.10**
    - Create `tests/TurnLoop/Properties/IpPrivacyPropertyTest.php` (tag `Feature: public-lab, Property 15: IP privacy and hash stability`): includes the check that the raw IP and the Device_Id appear in no `daily_usage` fake row, no response body and no `error_log` message of the create path.

- [x] 8. Return the episode list in two groups
  - [x] 8.1 Add the list queries to both repositories
    - Add `listEndedEpisodesByIds(array $ids)` (ended episodes among the ids, in `$ids` order) and `listEndedEpisodesForDevice(string $deviceId, ?string $sinceCreatedAt, array $excludeIds, int $limit)` (newest first, Featured ids excluded in the query) to the interface, `PdoEpisodeRepository` and `InMemoryEpisodeRepository`.
    - _Requirements: 6.2, 6.3, 6.4, 6.5, 6.6, 6.7, 6.8_
  - [x] 8.2 Group the list in `EpisodeListService` and `episodes_list.php`
    - Constructor takes `Config`; `list(mixed $rawLimit, ?string $deviceId)` returns `['featured' => [...], 'yours' => [...]]`; item building moves to a private `buildItem(EpisodeRow)` with the same fields as today. Featured is returned in full; "Your runs" uses the unchanged limit validation (1 to 100, default 20) and the latest Reset_Point. No cookie: `yours` is empty. `api/episodes_list.php` and the router pass the Device. `Cache-Control: no-store`, no Device_Id.
    - _Requirements: 6.2, 6.3, 6.4, 6.5, 6.6, 6.7, 6.8, 6.10_
  - [x] 8.3 Show the two groups on Replay
    - In `public/js/replay.js` `renderList(body)` reads `body.featured` and `body.yours` (an object, not an array) and builds two sections headed "Featured" and "Your runs", with a short empty-state line under "Your runs". This is done here so Replay does not break between commits. Voices and the sequencer come in task 14.
    - _Requirements: 6.9_
  - [x] 8.4 Update `live-records.md`
    - Edit `.kiro/steering/live-records.md` minimally: the list URL now returns `{featured, yours}`; without a cookie only the Featured group is returned, so read the Featured group of `episodes&ended=1`, or fetch an episode by its full UUID. The read-only rules and cite format stay.
    - _Requirements: 6.2_
  - [x] 8.5 Update the existing list tests
    - Update `tests/TurnLoop/EpisodeListServiceTest.php` and `tests/TurnLoop/Properties/EpisodeListPropertyTest.php` for the new shape and constructor (each item's bytes stay as today); extend the no-store test to the list route.
    - _Requirements: 6.2, 6.3, 6.8, 6.10, 14.13_
  - [x] 8.6 Write `replay-groups.test.js`
    - Create `tests/js/replay-groups.test.js`: the headings "Featured" and "Your runs", the empty-state line when `yours` is empty, an object (not array) response, and a Featured episode appearing only once.
    - _Requirements: 6.9, 14.7_
  - [x] 8.7 Write the gated list query test
    - Create `tests/TurnLoop/Repository/EpisodeListPdoTest.php` (skipped without `PS_TEST_MYSQL_DSN`): the same cases as the fake for both list queries.
    - _Requirements: 6.2, 6.3, 6.5, 6.7_
  - [x] 8.8 Write the property test for list grouping
    - **Property 9: Episode list grouping**
    - **Validates: Requirements 6.2, 6.3, 6.4, 6.5, 6.6, 6.7, 6.8**
    - Create `tests/TurnLoop/Properties/EpisodeListGroupsPropertyTest.php` (tag `Feature: public-lab, Property 9: Episode list grouping`): Featured order, missing or unended ids omitted, no other Device's runs, no duplicates, "Your runs" after a reset, and an episode removed from "Your runs" still returned by `GET /api/episodes/{id}`.
  - [x] 8.9 Write the property test that only starting is limited
    - **Property 14: Only starting an episode is counted or limited**
    - **Validates: Requirements 13.1, 13.2**
    - Create `tests/TurnLoop/Properties/OnlyStartIsLimitedPropertyTest.php` (tag `Feature: public-lab, Property 14: Only starting an episode is counted or limited`): with Device, IP and site at their limits, `ask`, `apply`, `reflect`, `GET /api/episodes/{id}`, the list and `GET /api/memory` behave as if no limit existed and change no count.

- [x] 9. Keep direct replay links working
  - [x] 9.1 Write `DirectAccessTest` and fix `replay.php` only if it fails
    - Create `tests/TurnLoop/DirectAccessTest.php`: `GET /api/episodes/{id}` works for an ended episode that is in no list and for another Device's episode; `public/replay.php` (source check) plays `?episode=<id>` without consulting a list and shows the not-found message and the list for an unknown id. Change `replay.php` or `replay.js` only if the test shows a gap.
    - _Requirements: 7.1, 7.2, 7.3, 14.8_
  - [x] 9.2 Write the property test for caller-independent access
    - **Property 10: Direct access does not depend on the caller**
    - **Validates: Requirements 7.3**
    - Create `tests/TurnLoop/Properties/DirectAccessPropertyTest.php` (tag `Feature: public-lab, Property 10: Direct access does not depend on the caller`): same status and bytes for no cookie, the owner's cookie and another Device's cookie.

- [x] 10. Add Voices, the speech sequencer and act-out sequencing
  - [x] 10.1 Add the JS test helpers for speech
    - Create `tests/js/helpers/fake-clock.js` (`setTimeout`, `clearTimeout`, `now` driven by `advance(ms)`), `fake-voices.js` (the fake Voices_Module: `speak(id, line, onDone) -> boolean`, `setOn/isOn`, `gesture`, `cancel`, `available`; records calls with virtual time; `finish('end'|'error')`; options `startsReading:false` and never-finish) and `fake-house.js` (records `showBubble(id, text, seconds)` and exposes the open bubbles).
    - _Requirements: 14.11_
  - [x] 10.2 Update `public/js/voices.js`
    - Add `speak(id, line, onDone) -> boolean` (onDone exactly once with `'end' | 'error' | 'cancelled'`), `setOn/isOn` (the setting, independent of the engine), `gesture()` and `cancel()`; `speak` returns false and touches no `speechSynthesis` when the setting is off, there is no engine, `gesture()` has not run, or the line is blank. Remove the capture-phase "any click" unlock (`autoUnlock:false`); keep `setEnabled/isEnabled/on/off/unlock/stop` as aliases.
    - _Requirements: 11.1, 11.3, 11.4, 11.14_
  - [x] 10.3 Create `public/js/speech-sequencer.js`
    - UMD shape like `voices.js`. `readingTime(text)` is `min(12000, 2000 + 60 * codePoints)`; `linesOfTurn(turn)` takes each element of `reading.words` for `robot_a`, then `robot_b`, then `residentLine`, drops blanks and never includes a thought; `createSpeechSequencer({house, voices, timers, now})` with `play(lines)`, `cancel()` and `voicesChanged(on)` as in the design (12000 ms hard cap that also cancels voices; Voices turned off mid-line switches to the remaining Reading_Time from the original start; turned on mid-line does not start reading that line); `bindVoicesButton(button, voices, sequencer)` updates the label "Voices: on|off" and `aria-pressed` within 100 ms.
    - _Requirements: 11.1, 11.2, 11.5, 11.6, 11.7, 11.8, 11.9, 11.10, 11.12, 11.13, 11.14, 11.15_
  - [x] 10.4 Make `act-out.js` use the sequencer
    - `createActOut(house, voices, sequencer)`: remove `emitSpeech/showSpoken/speakAloud`; `playTurn` starts the animation as today, calls `sequencer.play(linesOfTurn(turn))`, snaps to the recorded world when the animation ends, and resolves after both are done; `cancel()` also calls `sequencer.cancel()`; reduced motion still jumps to the world within 100 ms. Update every existing JS test that constructs `createActOut`.
    - _Requirements: 11.5, 11.10, 11.11, 11.12, 11.16_
  - [x] 10.5 Write `voices-gesture.test.js`
    - **Property 20: No speech before the First_Gesture**
    - **Validates: Requirements 11.3**
    - Create `tests/js/voices-gesture.test.js` against the real `voices.js` with a fake `speechSynthesis` (seeded generator, at least 100 cases): no utterance before `gesture()`; `speak` returns false with the setting off or no engine; `onDone` fires exactly once; label and setting are independent of the engine; after `gesture()` speech is allowed (11.1, 11.4).
  - [x] 10.6 Write `speech-reading-time.test.js`
    - **Property 16: Reading time**
    - **Validates: Requirements 11.8**
    - Create `tests/js/speech-reading-time.test.js` (seeded, at least 100 cases): equals `min(12000, 2000 + 60 * codePointCount)`, at least 2000, at most 12000, non-decreasing as the text grows.
  - [x] 10.7 Write `speech-lines.test.js`
    - **Property 17: Spoken lines never include a thought**
    - **Validates: Requirements 11.13, 11.5**
    - Create `tests/js/speech-lines.test.js` (seeded, at least 100 cases): only `robot_a.reading.words`, then `robot_b.reading.words`, then `residentLine`, blanks dropped, never any other reply text.
  - [x] 10.8 Write `speech-sequencer-order.test.js`
    - **Property 18: The sequencer shows one line at a time, in order, for the right time**
    - **Validates: Requirements 11.5, 11.6, 11.7, 11.8, 11.9, 11.10, 11.12**
    - Create `tests/js/speech-sequencer-order.test.js` (seeded, at least 100 cases, virtual time): at most one bubble open at any instant, lines in order, voice mode closes at the later of `onDone('end')` and `readingTime` (an error keeps `readingTime`; a voice that never ends is cancelled at `4000 + 100 * codePoints` ms) and the next line opens within 100 ms, timer mode closes after exactly `readingTime`, an empty list adds 0 ms, `playTurn` resolves only after the last bubble closed and the animation ended; the caption strip uses the same close (11.11).
  - [x] 10.9 Write `speech-voices-toggle.test.js`
    - **Property 19: Toggling Voices mid-line**
    - **Validates: Requirements 11.14, 11.15**
    - Create `tests/js/speech-voices-toggle.test.js` (seeded, at least 100 cases): Voices off at time t stops reading within 200 ms and closes at `startedAt + readingTime` (a late `'end'` or `'error'` after the toggle changes nothing); Voices on at time t starts no reading for the current line and closes at `startedAt + readingTime`; the next line may use voice.
  - [x] 10.10 Write `act-out-speech.test.js`
    - Create `tests/js/act-out-speech.test.js`: `playTurn` resolves only after animation and speech are both done; a turn with no Spoken_Line waits 0 ms for speech; `cancel()` closes the bubble and resolves the pending turn; reduced motion jumps within 100 ms; no speech before the First_Gesture.
    - _Requirements: 11.3, 11.10, 11.12, 11.16, 14.11_
  - [x] 10.11 Speech timing fix
    - Found in a browser check of tasks 10-14. (1) Replaying live heat-wave episode 186823c0 in Chromium with Voices on, where `speechSynthesis` exists but has no voices, every utterance fails at once, `onVoiceDone(line, 'error')` closes the line immediately, no bubble is ever visible and each turn advances after about 2 s. (2) In live heat-wave runs 4-6, 53 of 144 spoken lines are longer than 180 characters (median 123, p90 298, max 524); read aloud they take longer than 12 s, so the fixed 12000 ms voice-mode cap cancels the voice mid-sentence for about a third of the lines.
    - First update `requirements.md` (11.7 and 11.9), `design.md` and the text of Properties 18 and 19 in this file to the new rule. Then change `public/js/speech-sequencer.js`: in voice mode the bubble closes at the LATER of (a) the voice's `'end'` and (b) the line's Reading_Time counted from the line's start; if the voice reports `'error'`, switch to timer mode for the remaining Reading_Time (the same as Voices turned off mid-line); replace the fixed 12000 ms voice-mode cap with a per-line safety cap of 4000 ms + 100 ms per code point, used only when the voice reports neither `'end'` nor `'error'`. Reading_Time itself (Voices off) stays `min(12000, 2000 + 60 * codePoints)`. Everything else stays (one line at a time, order, cancel on Replay controls, no speech before the First_Gesture).
    - Tests (fake clock, fake voices, fake house): a voice that errors at once keeps the bubble for the Reading_Time and the next line starts after it; a voice that ends at once keeps the bubble for the Reading_Time; a voice that ends after 20 s on a 300-character line keeps the bubble 20 s and is not cancelled; a voice that never ends on a 300-character line closes the bubble at 4000 + 100 x 300 ms and the voice is cancelled. Update `speech-sequencer-order.test.js` (Property 18), `speech-voices-toggle.test.js` (Property 19) and any test that pins the old 12000 ms voice cap (grep for `12000`).
    - Commit message: `task 10: speech timing: keep bubbles for the reading time and do not cut long lines`.
    - _Requirements: 11.7, 11.9, 11.14, 14.11_

- [x] 11. Remove Pause, Step, Resume and Heavy rain from Observe
  - [x] 11.1 Remove the controls and the lever
    - In `public/index.php` delete `#btn-pause`, `#btn-step`, `#btn-resume`, `#sw-rain` and the "coming later" text, and fix the stale comments. In `public/js/observe.js` remove `pause/step/resume`, `state.paused`, `stepOnce`, the three `el.btn*` lookups, their `wireControls` handlers, `el.swRain` and their exports. Remove the `.later` and any Pause/Step/Resume-only CSS from `public/css/screens.css`. Keep Start, Voices, Notice_Resume and "Watch a past episode".
    - _Requirements: 10.1, 10.2, 10.3, 10.4, 10.5, 10.7_
  - [x] 11.2 Write `observe-static.test.js`
    - Create `tests/js/observe-static.test.js`: `index.php` and `observe.js` contain none of `btn-pause`, `btn-step`, `btn-resume`, `sw-rain`, `Heavy rain`, `coming later`, `stepOnce`; two Levers only (Earthquake and Heat wave); Start and Voices exist; Replay's own Play, Pause, Step back and Step forward controls are unchanged in `replay.php`.
    - _Requirements: 10.1, 10.2, 10.5, 10.6, 14.10_
  - [x] 11.3 Update the existing JS tests that used the removed controls
    - Update `tests/js/heat-wave-screens.test.js` (remove the Heavy rain disabled and "coming later" assertions; everything else stays) and any of `end-card-reflection.test.js`, `memory-ui.test.js`, `disaster-already-set.test.js`, `ask-apply-recovery.test.js` that reference removed exports or elements.
    - _Requirements: 10.1, 10.5, 14.13_

- [x] 12. Add the Observe phase machine and levers after an episode ends
  - [x] 12.1 Add the fake API helper and expose the phase
    - Create `tests/js/helpers/fake-api.js` (scripted `{status, body}` per call, records calls). In `observe.js` add `state.phase` (`idle`, `starting`, `staged`, `running`, `unfinished`, `ended`) to `_state()`.
    - _Requirements: 9.5, 14.9_
  - [x] 12.2 Implement the phases and the run guards
    - `onLever(disaster)` and `onStart()` dispatch on `state.phase` per the design table; `starting` is set synchronously at click time; a second Lever flip or Start press while `starting` or `running` is ignored. `startNewRun()` from `ended` cancels act-out, clears `pendingAsk`, the episode id, expected turn, meters, log, notice and End_Card (`dismissEndCard`), and drops the reflection host. `runEndCardReflection()` returns a Promise; `finishClosing(episodeId)` switches both Levers OFF only if `state.episodeId === episodeId && phase === 'ended'`. The existing ask/apply 5xx recovery stays unchanged here.
    - _Requirements: 9.1, 9.2, 9.3, 9.4, 9.5_
  - [x] 12.3 Wire Voices into Observe
    - `public/index.php`: the Voices button starts as "Voices: on" with `aria-pressed="true"`; load `js/speech-sequencer.js` before `act-out.js`. `observe.js`: `createVoices({enabled:true, autoUnlock:false})`, `createSpeechSequencer({house, voices})`, `createActOut(house, voices, sequencer)`, `bindVoicesButton(...)`; `voices.gesture()` is called synchronously first in the Lever and Start handlers.
    - _Requirements: 11.1, 11.2, 11.3, 11.4_
  - [x] 12.4 Write the Observe flow examples
    - Create `tests/js/observe-flow.test.js` with fixed examples: both Levers are OFF after the last turn and the reflection (or "not stored"); a later Lever or Start creates a new episode with empty client state and no End_Card; a second Lever flip or Start press while a run is in progress is ignored; the Voices label starts "Voices: on" and no speech happens before the First_Gesture.
    - _Requirements: 9.1, 9.2, 9.3, 9.5, 11.1, 11.3, 14.9, 14.16_

- [x] 13. Add All reset, Try again and the limit notices to Observe
  - [x] 13.1 Add `house.reset()` to the renderer
    - In `public/js/house-renderer.js` add `reset()` restoring the mount-time state and clearing bubbles, caption and thinking flags. Earthquake drawing stays byte-identical: `renderer-earthquake-calls.test.js` must still pass unchanged.
    - _Requirements: 8.1, 12.5_
  - [x] 13.2 Implement All reset on Observe
    - `confirmResetMemory`: on 200 call `clientResetToFirstLoad()` (both Levers OFF, `house.reset()`, meters, log, End_Card and notice cleared, `phase = idle`, status line from the response) then `refreshMemoryStatus()`; on 409 show the plain message "All reset is available when no run is in progress. A stopped run is released within 5 minutes." and change nothing else; the All_Reset_Button is `disabled` in `starting` and `running`.
    - _Requirements: 8.1, 8.2, 8.3, 8.4_
  - [x] 13.3 Implement the Unfinished_Episode notice and Try again
    - When `recoverTurn` reaches `MAX_TURN_TRIES`, or an ask/apply status is not recoverable: `phase = unfinished`, keep the turns on screen, show "A turn could not be completed." with a primary "Try again" button; remove every "Paused" string (failed ask, failed apply, unreachable lab). `tryAgain()` calls `voices.gesture()`, reads `getEpisode(state.episodeId)`, shows the End_Card if ended, otherwise adopts the stored state, clears the notice and resumes the loop with a fresh failure count; it never calls `createEpisode`; on failure it stays `unfinished`. Lever and Start do the same while `unfinished`. The 5000 ms wait, the 3 tries and the log sequence are unchanged.
    - _Requirements: 9.4, 9.6, 9.7, 9.8, 9.9, 11.3_
  - [x] 13.4 Implement the 409 and 429 create notices
    - 409 `device_already_running`: Device_Busy_Notice with Notice_Resume bound to `body.episodeId` or the `localStorage ps:lastEpisodeId` fallback (ignored when `localStorage` is unavailable), plus "Watch a past episode"; Notice_Resume calls `voices.gesture()` and continues through `resumeEpisode`. 429: `phase = idle`, both Levers OFF, stage unchanged; `daily_device_limit` and `daily_ip_limit` show "You have run the lab many times today. It opens again tomorrow (UTC)."; `daily_site_limit` shows "The lab has had a busy day. It opens again tomorrow (UTC)."
    - _Requirements: 10.3, 10.4, 11.3, 13.11, 13.12, 13.13_
  - [x] 13.5 Update `ask-apply-recovery.test.js`
    - Change only the notice assertions (the two "Paused." strings become the "A turn could not be completed." notice with the Try again button); the call logs, the 5000 ms waits and the 3 tries stay as they are.
    - _Requirements: 9.4, 9.9, 14.13_
  - [x] 13.6 Extend the Observe flow examples
    - Extend `tests/js/observe-flow.test.js`: after the tries are used up the notice reads "A turn could not be completed." with a "Try again" button and never contains "Paused"; Try again continues the same episode without a create; Lever and Start do the same; All reset sets both Levers OFF and returns stage, meters, log and End_Card to first load, and the next run starts normally; the All_Reset_Button is disabled while turns run; the All reset 409 message; the 429 messages keep both Levers OFF and the stage unchanged; the Device_Busy_Notice shows Notice_Resume, which continues the running episode through `resumeEpisode` (body `episodeId` and `localStorage` fallback).
    - _Requirements: 8.1, 8.2, 8.3, 8.4, 9.6, 9.7, 9.8, 9.9, 10.3, 10.4, 13.11, 13.12, 13.13, 14.9, 14.10, 14.15, 14.20_
  - [x] 13.7 Write the Observe phase invariants property test
    - **Property 21: Observe phase invariants**
    - **Validates: Requirements 8.1, 8.2, 8.4, 9.2, 9.3, 9.5, 9.7, 9.8, 9.9, 13.11**
    - Create `tests/js/observe-phases-property.test.js` (seeded `mulberry32`, at least 100 random event sequences against `fake-api.js`): at most one `createEpisode` in flight and none while `starting`, `running`, `unfinished` or `staged` (for Start); Lever and Start act as Try again while `unfinished`; Levers OFF after an episode ends and its reflection settles; empty client state on a later run; first-load state after an accepted All reset; the All reset button disabled in `starting` and `running`; no notice contains "Paused"; a 429 leaves the Levers OFF and the stage unchanged.

- [x] 14. Add Voices and the sequencer to Replay
  - [x] 14.1 Wire Voices, the sequencer and cancel-on-control into Replay
    - `public/replay.php`: add `<button type="button" class="btn" id="btn-voices" aria-pressed="true">Voices: on</button>` in the header outside the hidden controls, and load `js/speech-sequencer.js` before `act-out.js`. `public/js/replay.js`: construct voices (`enabled:true`, `autoUnlock:false`), the sequencer and act-out as on Observe, `bindVoicesButton`; call `voices.gesture()` in the Play, Pause, Step back and Step forward handlers; `play`, `pausePlayback`, `stepBack`, `stepForward` and `chooseAnother` each call `actOut.cancel()` first. The Replay control set itself is unchanged.
    - _Requirements: 10.6, 11.1, 11.2, 11.3, 11.4, 11.16_
  - [x] 14.2 Write `replay-voices.test.js`
    - Create `tests/js/replay-voices.test.js`: the label starts "Voices: on" and the button exists in list and playback modes; no speech before the First_Gesture; Pause, Step back, Step forward and Play cancel any reading and bubble timer, close the open bubble and start no further line until the new position's Line_Sequence begins; the picture still ends in the recorded world.
    - _Requirements: 11.1, 11.3, 11.16, 14.11_

- [x] 15. Draw the heat-wave sky
  - [x] 15.1 Update the heat palette in `house-renderer.js`
    - When `S.heat`: sky gradient from rgb(58,14,30) to rgb(196,78,40) (no generic sun, stars or bats), hills `#3a1622`, ground `#2a1a10`, ground texture `#3c2614`. `heatSky()` draws only the sun at (330, 34): `#ffc83a` alpha 0.16 radius 32, `#ffd84a` alpha 0.32 radius 21, `#ffe46a` radius 13; the old orange wash and white sun are removed. Keep the heat shimmer, windows, fan and air conditioner. An earthquake world (`S.heat === null`) skips every new branch, and a heat world replaced by an earthquake world draws the earthquake sky and ground again.
    - _Requirements: 12.1, 12.2, 12.3, 12.4, 12.5, 12.6_
  - [x] 15.2 Write `heat-sky.test.js` examples
    - Create `tests/js/heat-sky.test.js` with `fake-canvas.js`: the exact sky end colours, hills, ground, texture and sun discs in a heat-wave world; none of the Heat_Palette colours and no sun in an earthquake world; the earthquake call log equals `tests/fixtures/renderer_earthquake_calls.json` (never regenerated); heat then earthquake equals earthquake alone.
    - _Requirements: 12.1, 12.2, 12.3, 12.5, 12.6, 14.12_
  - [x] 15.3 Write the property test for the heat palette
    - **Property 22: Heat palette only in heat worlds**
    - **Validates: Requirements 12.1, 12.2, 12.3, 12.5, 12.6**
    - Create `tests/js/heat-palette-property.test.js` (seeded, at least 100 cases, `Math.random` stubbed): for any earthquake world the call sequence equals the recorded one and contains no Heat_Palette colour or sun disc; for any heat-wave world the sky, hills, ground, texture and sun match Requirements 12.1 to 12.3; drawing a heat world then an earthquake world gives the same calls as the earthquake world alone.

- [x] 16. Audit the existing tests after the changes
  - [x] 16.1 Write a stale-reference check
    - Create `tests/js/no-stale-references.test.js` that scans `public/` and `tests/js/` for leftovers: `Paused.`, `sw-rain`, `btn-pause` (Observe only), `stepOnce`, `Array.isArray(result.body)` for the list, `autoUnlock` defaulting to true, and the old `createActOut(house, voices)` two-argument call. Fix every leftover it finds in the files this spec owns.
    - _Requirements: 9.9, 10.1, 10.5, 14.13_
  - [x] 16.2 Review the test changes for protected edits
    - Review `git diff` of every existing test changed by this spec. Only these were changed on purpose: the memory tests (design C2, C3), the list tests (C5), the 409 body tests (C6), `heat-wave-screens.test.js` (C7) and `ask-apply-recovery.test.js` (C8). Confirm `tests/fixtures/earthquake_golden_master.json`, `tests/fixtures/renderer_earthquake_calls.json`, `ProtectedVersionsTest` and `EpisodeViewBytesTest` are byte-for-byte unchanged since task 1, and the `earthquake_golden_master.json` SHA-256 still starts with `c2aba50f87c22fe6`. Report any other changed expected value to the maintainer instead of editing it.
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 14.13_

- [x] 17. Final checkpoint - Ensure all tests pass
  - [x] 17.1 Run the full verification
    - Run `php -l` on every PHP file changed by this spec (`git diff --name-only` against the first commit of this spec), the full PHPUnit suite (including `EarthquakeGoldenMasterTest`) and `node tests/js/run-all.js`. Confirm that no file in `sql/` was executed, no secret or Device_Id appears in the changed files (grep), `sql/2026-11-10_add_public_lab.sql` is the only new file in `sql/`, and list the gated PDO tests that were skipped so the maintainer can run them with `PS_TEST_MYSQL_DSN` (including `testPdoTwoPerTripleCommitsSerializeToExactlySix` on MariaDB 10.11). Ask the user if questions arise.
    - _Requirements: 1.1, 1.6, 1.7, 1.8, 14.13_

- [x] 18. Levers choose the disaster, Start starts the run
  - [x] 18.1 Rework the Lever and Start behaviour in `observe.js` and `index.php`
    - Front end only. Change nothing in `src/`, `api/`, `sql/`, the engine, or any text the robots read; the Golden_Master and every protection pin (`ProtectedVersionsTest`, `EpisodeViewBytesTest`, `renderer_earthquake_calls.json`) stay unchanged.
    - `public/js/observe.js` `onLever(disaster)`: after `voices.gesture()`, in `idle` and `ended` toggle `el.swEarthquake` (`#sw-earthquake`) or `el.swHeat` (`#sw-heat`) ON/OFF (`.on`, `aria-pressed`, the `.mark` text) and turn the other OFF, with no API call; in `starting`, `running` and `unfinished` return at once (the chosen Lever stays ON).
    - `onStart()`: after `voices.gesture()` (the First_Gesture): `unfinished` runs `tryAgain()`; `starting` and `running` return; in `idle` and `ended` with no Lever ON call `showNotice('Turn on Earthquake or Heat wave, then press Start.')` and send nothing; with a Lever ON (from `ended`, first `startNewRun()`) set `phase = 'starting'` synchronously and use the existing `api.createEpisode` with the disaster and the existing `api.flipDisaster` / `doFlip` / `doFlipHeat` path to start the run.
    - Remove the `staged` phase: no assignment of `'staged'` remains in `onStart`, `doFlip`, `doFlipHeat` or `isDisasterAlreadySet` handling (a failed disaster call or an already-set disaster for the episode just created ends in the `unfinished` notice with Try again); drop `staged` from the `state.phase` comment and keep `_state().phase` values `idle`, `starting`, `running`, `unfinished`, `ended`. Set both Levers OFF after the end and its reflection (`finishClosing`), after All reset (`clientResetToFirstLoad`), and on a 429 or failed create (unchanged). The Device_Busy_Notice with Notice_Resume, the 429 notices and Try again keep their texts and behaviour.
    - Update the stale comments in `public/index.php` ("the levers also start one in the run loop") and in `observe.js`.
    - Before committing run `php -l public/index.php`, the full PHPUnit suite (including `EarthquakeGoldenMasterTest`) and `node tests/js/run-all.js`. Commit with `task 18: Levers choose the disaster, Start starts the run`. Never push.
    - _Requirements: 9.2, 9.5, 9.8, 15.1, 15.2, 15.3, 15.4, 15.5, 15.6, 15.7, 15.8, 15.9, 15.10, 8.4, 13.11_
  - [x] 18.2 Write `observe-levers.test.js` and update the tests that used `staged`
    - Create `tests/js/observe-levers.test.js` in the style of `observe-flow.test.js` (same DOM shim, `helpers/fake-api.js`, `helpers/fake-clock.js`): clicking `#sw-earthquake` or `#sw-heat` sends no request and toggles ON/OFF; at most one Lever ON; Start with none ON shows "Turn on Earthquake or Heat wave, then press Start." in `#notice` and `fake-api` records no call; Start with Earthquake ON and with Heat wave ON creates the episode for the right disaster and runs it (the `createEpisode` and disaster calls carry that disaster); Levers and Start ignore clicks while `starting` and `running` and the chosen Lever stays ON; both Levers are OFF after the end and its reflection and after All reset; Start on an `unfinished` episode acts as Try again (no `createEpisode`) and Levers are ignored there.
    - Update `tests/js/observe-flow.test.js` (the Start-alone and `staged` assertions around lines 190-260 and 395-425), `tests/js/observe-phase.test.js` (drop `staged` from the allowed phases) and any other test that clicks a Lever to start a run, so each now turns a Lever ON and then presses Start. Change only these flow steps and phase names, never an expected API call log, wait or notice text. Add a stale-reference check to `tests/js/no-stale-references.test.js` that `observe.js` has no `'staged'`.
    - _Requirements: 15.1, 15.2, 15.3, 15.4, 15.5, 15.6, 15.7, 15.8, 14.9, 14.16, 14.21_
  - [x] 18.3 Update the Observe phase property test
    - Update `tests/js/observe-phases-property.test.js` (**Property 21: Observe phase invariants**, tag `Feature: public-lab, Property 21: Observe phase invariants`) to the extended Property 21 in `design.md`: events become Lever toggle and Start; a Lever click never calls the API; at most one Lever ON; Start with none ON sends nothing; no `createEpisode` while `starting`, `running` or `unfinished`; `staged` never reached; Start acts as Try again while `unfinished`. Remove `staged` from `BLOCKED_FOR_CREATE`. At least 100 seeded cases.
    - **Property 21: Observe phase invariants**
    - **Validates: Requirements 8.1, 8.2, 8.4, 9.2, 9.3, 9.5, 9.7, 9.8, 9.9, 13.11, 15.1, 15.2, 15.3, 15.4, 15.5, 15.6, 15.7, 15.8**
    - _Requirements: 15.1, 15.2, 15.3, 15.5, 15.6, 15.8, 14.9_

- [x] 19. Notices next to the levers
  - [x] 19.1 Move the Notice_Area into the Switches_Panel and reveal it
    - Front end only. Change nothing in `src/`, `api/`, `sql/`, the engine, or any text the robots read; the Golden_Master and every protection pin stay unchanged.
    - `public/index.php`: move `<div class="message" id="notice" hidden>` from the bottom of `.wrap` into the "Breaker switches" `section.panel` (`aria-label="Disaster switches"`), directly after `div.switches`; add `aria-live="polite"` to it; keep `id="notice"` so `showNotice`, `clearNotice` and `renderNoticePanel` need no change in what they write. Fix the stale comment above it.
    - `public/css/screens.css`: a small top margin for `#notice` inside the panel, identical on wide and narrow screens; no new colours.
    - `public/js/observe.js`: add `revealNotice()` called at the end of `showNotice` and `renderNoticePanel` when the notice is non-empty: if `el.notice.getBoundingClientRect()` is not fully inside the viewport (top below 0, bottom above `window.innerHeight`), call `el.notice.scrollIntoView({block: 'nearest', behavior})` with `behavior = 'instant'` when `matchMedia('(prefers-reduced-motion: reduce)').matches` and `'smooth'` otherwise; a fully visible notice is not scrolled; a missing `scrollIntoView` or `matchMedia` is ignored. The texts and buttons stay as they are.
    - Before committing run `php -l public/index.php`, the full PHPUnit suite and `node tests/js/run-all.js`. Commit with `task 19: Notices next to the levers`. Never push.
    - _Requirements: 16.1, 16.2, 16.3, 16.4, 16.5, 16.6_
  - [x] 19.2 Write `observe-notice-placement.test.js`
    - Create `tests/js/observe-notice-placement.test.js`: a static check that in `public/index.php` the `#notice` element lies inside the "Breaker switches" `section.panel` and right after `div.switches` (and nowhere else in `.wrap`), with `aria-live="polite"`; with the DOM shim, a notice shown while its `getBoundingClientRect()` is off-screen calls `scrollIntoView` once with `block: 'nearest'` and `behavior: 'smooth'`, and with `matchMedia` reporting reduced motion with `behavior: 'instant'`; a notice fully inside the viewport does not scroll; an empty notice does not scroll. Confirm every existing notice test (`ask-apply-recovery`, `observe-flow`, `heat-wave-screens`, `disaster-already-set` and friends) still passes unchanged.
    - _Requirements: 16.1, 16.2, 16.3, 16.4, 16.5, 14.22_

- [x] 20. Keep Chrome's speech going
  - [x] 20.1 Chunk the speech and add the keep-alive rules in `voices.js`
    - Front end only, in `public/js/voices.js`; the `speak(id, line, onDone)` interface, `speech-sequencer.js` and the Reading_Time and safety-cap rules stay as they are. Change nothing in `src/`, `api/`, `sql/`, the engine, or any text the robots read; the Golden_Master and every protection pin stay unchanged.
    - Add `splitForSpeech(text)` (exported on `createVoices` and `Voices`): split after `.`, `!`, `?` or an ellipsis followed by a space or the end; split any chunk over 180 characters after `, ` or `; `, else at the last space before 180. `speak` queues one entry per line with its chunks; each chunk is one utterance with the line's voice, `lang`, pitch and rate; the line reports `'end'` after the last chunk ends, `'error'` if a chunk errors, `'cancelled'` on `cancel()`.
    - Before each `synth.speak`: if `synth.paused`, call `synth.resume()`; never call `speak` within 150 ms of our own `cancel()` (defer until 150 ms have passed, in order, dropped if a newer `cancel()` ran). While a chunk is read, an interval of 5000 ms calls `resume()` if paused. Call `resume()` on `visibilitychange` to visible and on window `focus`.
    - Chunk_Watchdog per chunk: after `2000 + 120 * chars / rate` ms without `end` or `error`, if `synth.speaking` is false treat the chunk as ended and go on; if it is still speaking after twice that time, cancel it and go on with the next chunk (the line continues). Make timers, `now` and the document/window listeners injectable through `createVoices` options (defaults: the real ones).
    - Before committing run the full PHPUnit suite and `node tests/js/run-all.js`. Commit with `task 20: Keep Chrome's speech going`. Never push.
    - _Requirements: 17.1, 17.2, 17.3, 17.4, 17.5, 17.6, 17.7, 17.8, 17.9, 17.10, 17.11, 11.7, 11.9_
  - [x] 20.2 Write `voices-chunks.test.js` and `voices-keepalive.test.js`
    - Create `tests/js/voices-chunks.test.js` against the real `voices.js` with a fake `speechSynthesis` (as in `voices-gesture.test.js`): fixed splitting examples (sentence ends, ellipsis, a 300-character sentence cut at `, ` or `; `, a long text with no separator cut at the last space before 180); chunks are spoken in order with the line's voice, pitch and rate; the line reports `'end'` exactly once after the last chunk ends; an error in a chunk reports `'error'` for the line; `cancel()` reports `'cancelled'`.
    - Create `tests/js/voices-keepalive.test.js` with `helpers/fake-clock.js` and a fake `speechSynthesis` that records `speak`, `cancel` and `resume` with virtual time: a paused engine is resumed before `speak`; `resume()` on `visibilitychange` to visible and on window `focus`; the 5000 ms check resumes a paused engine while a chunk is read and stops after the chunk ends; no `speak` within 150 ms after our own `cancel()` (the speak happens at 150 ms); a chunk that never ends with `speaking === false` is passed at `2000 + 120 * chars / rate` ms and the next chunk is spoken; with `speaking === true` it is cancelled at twice that time and the next chunk is spoken, the line continuing.
    - _Requirements: 17.1, 17.2, 17.3, 17.5, 17.6, 17.7, 17.8, 17.9, 17.10, 14.23_
  - [x] 20.3 Write the property test for speech splitting
    - **Property 23: Speech splitting preserves the line and respects the limits**
    - **Validates: Requirements 17.1, 17.2, 17.3**
    - Create `tests/js/voices-split-property.test.js` (seeded `mulberry32`, at least 100 cases, tag `Feature: public-lab, Property 23: Speech splitting preserves the line and respects the limits`): random texts with sentence ends, ellipses, commas, semicolons, long words and Japanese text; chunks are non-blank, in order, rejoin to the trimmed text (cut whitespace ignored), none over 180 characters unless a single word, and a short text with no inner sentence end gives one chunk.
    - _Requirements: 17.1, 17.2, 17.3_
- [x] 21. Cap provider calls under concurrent requests
  - [x] 21.1 Add the Turn_Lock and reserve attempts before the provider call
    - Touches `src/` only (this is intended). Change nothing in `sql/`, the engine, `api/`, `public/` or any text the robots read; the earthquake Golden_Master (SHA-256 starts with `c2aba50f87c22fe6`) and every protection pin stay unchanged, and no expected value is edited to make a test pass.
    - `src/TurnLoop/Repository/EpisodeRepository.php`: add `acquireTurnLock(string $kind, string $episodeId, int $turnNumber): bool` and `releaseTurnLock(string $kind, string $episodeId, int $turnNumber): void` with doc comments (kind is `ask` or `apply`; non-waiting; per connection).
    - `src/TurnLoop/Repository/PdoEpisodeRepository.php`: implement them on the repository's own `$this->pdo` with `SELECT GET_LOCK(:name, 0)` and `SELECT RELEASE_LOCK(:name)`; the name is built in PHP as `'ps_' . $kind . ':' . sha1($episodeId . ':' . $turnNumber)` (at most 49 characters) and bound as a parameter; only a result of 1 returns true (0 and NULL return false); remember held names so release only frees its own. MySQL 5.7-compatible, no `SKIP LOCKED`.
    - `tests/TurnLoop/InMemoryEpisodeRepository.php`: implement both methods with a held-name set, a hook `holdTurnLock($kind, $episodeId, $turnNumber)` that makes the lock busy for the caller, and acquire/release counters.
    - `src/TurnLoop/TurnService.php`, `ask()`: after `findEpisode`, `acquireTurnLock('ask', ...)`; when false throw `ApiError::conflict('turn_busy', $episode->currentExpectedTurn, $this->currentTurnState(...))` before `touchActivity`, the engine or any client; wrap the rest in `try { ... } finally { releaseTurnLock('ask', ...) }`; re-read the episode and turn inside the try and run the existing ended, turn-mismatch and already-applied gates on those values; for each robot with no stored success and fewer than 2 attempts call `incrementAttempt` BEFORE the call, stage the call only if the reserved number is 2 or less, and store the prompt and reply under that reserved number. The post-call logic (`markNoReply`, `replies_complete`) stays as it is.
    - `apply()`: the same with `'apply'`; lock after `findEpisode`, busy gives `turn_busy` with the recorder never called and nothing stored; the already-applied check, the ended and turn gates and the `replies_complete` check run inside the try on fresh values.
    - `api/` needs no change: `ApiError` already maps to its status in `ps_dispatch`.
    - Run `php -l` on each changed PHP file. This sub-task is committed with the rest of task 21 in 21.6.
    - _Requirements: 18.1, 18.2, 18.3, 18.4, 18.5, 18.6, 18.7, 18.8, 18.9, 18.10, 18.11, 18.12, 18.14_
  - [x] 21.2 Treat turn_busy as still thinking on Observe
    - Front end only, in `public/js/observe.js`. Change nothing in `src/`, `api/`, `sql/`, the engine, or any text the robots read; the Golden_Master and every protection pin stay unchanged.
    - In `askAndApply` and `applyOnly`, before `handleTurn409`, detect `res.status === 409 && res.body && res.body.error === 'turn_busy'` and call a new `waitBusy(turnNumber, failures)`: `recoveryWait(2000)`, then `api.getEpisode(state.episodeId)`, then the same branch as `recoverTurn` (`applied` → `onApplied`, `replies_complete` → `applyOnly`, otherwise `askAndApply`) with the same `failures`, so no failed try is counted and no notice is shown. Keep a per-turn `busyCount` (reset when the turn is applied); when it reaches 15 consecutive Turn_Busy answers for one turn the next one goes through `recoverTurn(turnNumber, failures + 1)`. A failed `getEpisode` inside `waitBusy` is a failed try (`recoverTurn(turnNumber, failures + 1)`). `handleTurn409` and every other path stay as they are.
    - _Requirements: 18.13, 18.14_
  - [x] 21.3 Write the fake-repository tests for the lock and the reserve-first order
    - Create `tests/TurnLoop/TurnLockTest.php`: with `holdTurnLock('ask', ...)`, `ask` throws `ApiError` 409 `turn_busy` carrying `currentExpectedTurn` and `turnState`, the fake robot clients record zero calls, `attempts_a` and `attempts_b` are unchanged and `touchActivity` was not called; with `holdTurnLock('apply', ...)`, `apply` gives `turn_busy`, the fake recorder records zero calls and nothing is stored; the ask lock and the apply lock are independent; the lock is released (release counter equals acquire counter) after a normal `ask`, after an `ask` whose client throws, after an `apply`, and after an `apply` that throws; a repeated `apply` of an applied turn returns the stored view without a recorder call; a fake client that records the attempt count at call time sees the attempt already incremented. Update `tests/TurnLoop/TurnServiceAskTest.php` and `tests/TurnLoop/TurnServiceApplyTest.php` only where the reserve-first order or the new lock calls need it; no expected value, attempt number or stored text changes.
    - _Requirements: 18.1, 18.2, 18.3, 18.4, 18.5, 18.7, 18.8, 18.9, 14.25, 14.26_
  - [x] 21.4 Write the property test for the attempt limit
    - **Property 24: Provider calls and attempts stay within the Attempt_Limit**
    - **Validates: Requirements 18.1, 18.2, 18.4, 18.5, 18.6, 18.7, 18.14**
    - Create `tests/TurnLoop/Properties/AttemptLimitPropertyTest.php` (Eris, `limitTo(100)`, tag `Feature: public-lab, Property 24: Provider calls and attempts stay within the Attempt_Limit`) against the fake repository and fake clients: random sequences of `ask` calls with the lock free or busy and each robot's call succeeding or failing; assert at most 2 client calls per robot in total, attempts never above 2, no call and no change on a busy lock, no call for a robot with a stored success, the lock released after every obtained lock. No real AI call.
    - _Requirements: 18.1, 18.2, 18.4, 18.6, 18.7, 14.25_
  - [x] 21.5 Write the gated MySQL lock test
    - Create `tests/TurnLoop/Repository/TurnLockPdoTest.php`, skipped unless `PS_TEST_MYSQL_DSN` names a throwaway database (same gating as `UsageRepositoryPdoTest`). Open two PDO connections and two `PdoEpisodeRepository` objects: a lock held through the first makes the second's `acquireTurnLock` return false; after `releaseTurnLock` the second obtains it; after the holding connection is closed the second obtains it; an `ask` lock and an `apply` lock for the same turn do not block each other; an episode id of 500 characters still gives a working lock (name of 64 characters or fewer); `ask` through a `TurnService` built on the second connection while the first holds the lock gives `turn_busy` and the fake clients are not called.
    - _Requirements: 18.10, 18.11, 18.12, 14.27_
  - [x] 21.6 Write `observe-turn-busy.test.js`, then run everything and commit
    - Create `tests/js/observe-turn-busy.test.js` with `fake-api.js` and the injectable `observeWait`: a 409 `turn_busy` for `ask` and for `apply` makes Observe wait exactly 2000 ms, call `getEpisode` once, continue from the stored turn state (`waiting`, `replies_complete`, `applied`), show no notice and count no failed try; 15 consecutive Turn_Busy answers keep waiting and the 16th counts one failed try; `ask-apply-recovery.test.js` and every other existing test pass unchanged.
    - Run `php -l` on every changed PHP file, the full PHPUnit suite (`EarthquakeGoldenMasterTest`, `ProtectedVersionsTest`, `EpisodeViewBytesTest` included) and `node tests/js/run-all.js`. Tick every finished sub-task of task 21. Commit only the files of task 21 with `task 21: Cap provider calls under concurrent requests`. Never push, amend, rebase or reset.
    - _Requirements: 18.13, 14.28, 14.32_
- [x] 22. Stop episodes that never run from blocking other visitors
  - [x] 22.1 Add the Origin check and the 60-second slot rule
    - Touches `src/` and `api/` only (this is intended). Change nothing in `sql/`, `public/`, the engine or any text the robots read; no table or column is added; the earthquake Golden_Master (SHA-256 starts with `c2aba50f87c22fe6`) and every protection pin stay unchanged.
    - `src/TurnLoop/Config.php`: add `public int $unaskedReleaseSeconds = 60` next to `idleSlotReleaseSeconds`.
    - `src/TurnLoop/Repository/PdoEpisodeRepository.php`: in `countRunningEpisodes` and `findRunningEpisodeForDevice` add `AND (last_activity_at >= :unasked_threshold OR EXISTS (SELECT 1 FROM turns t WHERE t.episode_id = episodes.id AND t.turn_number >= 1))`, with the second threshold computed in PHP next to `idleThreshold()` and bound as a parameter. `tests/TurnLoop/InMemoryEpisodeRepository.php`: the same rule in `isRunning` using its turn map (turn number 1 or more). `TurnService::assertCanStart` and the `ask` path need no change.
    - `api/router.php`: `ps_dispatch()` gets trailing parameters `?string $origin = null` and `array $allowedOrigins = []`; for `POST` only, if `$origin !== null` and `!in_array($origin, $allowedOrigins, true)` return `ps_json_response(403, ['error' => 'cross_site'])` before routing, the cookie read or any service call. `api/index.php`: pass `$_SERVER['HTTP_ORIGIN'] ?? null` and `$localConfig['allowed_origins']` (only if it is a list of strings, else `[]`). `api/config.example.php`: add `'allowed_origins' => ['https://kiro.pyol.net']` with a comment (placeholder, no secret).
    - Run `php -l` on each changed PHP file. This sub-task is committed with the rest of task 22 in 22.5.
    - _Requirements: 19.1, 19.2, 19.3, 19.4, 19.5, 19.6, 19.7, 19.8, 19.9, 19.10, 19.11, 19.12, 19.13, 19.14, 19.15_
  - [x] 22.2 Write the slot-rule tests on the fake repository
    - Create `tests/TurnLoop/RunningSlotTest.php` with the injected clock: an unasked episode still counts as running at 59 s and not at 61 s; an episode with a turn row of 1 or more counts at 299 s and not at 301 s; `device_already_running` (with the episode id) and `too_many_running` follow the same rule through `assertCanStart`; after release the Device creates a new episode and the old one is unchanged and readable through `GET`; an accepted `ask` on a released episode makes it count as running again; an ended episode never counts. Update `ConfigPublicLabTest` for the new default (60).
    - _Requirements: 19.8, 19.9, 19.10, 19.11, 19.12, 19.13, 19.14, 14.30_
  - [x] 22.3 Write the Origin tests through `ps_dispatch`
    - Create `tests/TurnLoop/CrossSiteRouteTest.php` with a fake-backed `TurnService`: for each POST route (create, disaster, ask, apply, reflect, memory reset) a foreign `Origin` gives 403 `cross_site`, `Cache-Control: no-store`, no `Set-Cookie`, no episode, no usage count and no client call; an allowed `Origin` and no `Origin` give the same responses as before; an empty list and a missing list refuse any `Origin`; `null`, a trailing slash, a different case and a different port are refused; GET requests are not checked; the body contains only the error code.
    - _Requirements: 19.1, 19.2, 19.3, 19.4, 19.5, 19.6, 19.7, 14.29_
  - [x] 22.4 Write the property tests for the Origin check and the slot rule
    - **Property 25: Cross-site POST is refused and changes nothing**
    - **Validates: Requirements 19.1, 19.2, 19.3, 19.5, 19.6**
    - Create `tests/TurnLoop/Properties/CrossSitePropertyTest.php` (Eris, `limitTo(100)`, tag `Feature: public-lab, Property 25: Cross-site POST is refused and changes nothing`): random origin strings and allowed lists; refused exactly when the string is not a list member, with the repository, usage counts and cookies unchanged; otherwise handled as with no `Origin`.
    - **Property 26: The running-slot rule matches a simple model**
    - **Validates: Requirements 19.8, 19.9, 19.10, 19.11, 19.12, 19.13**
    - Create `tests/TurnLoop/Properties/RunningSlotPropertyTest.php` (Eris, `limitTo(100)`, tag `Feature: public-lab, Property 26: The running-slot rule matches a simple model`): random episodes (ended or not, with or without an accepted ask, random last-activity age) and clock values; `countRunningEpisodes` and `findRunningEpisodeForDevice` match the model (not ended, at most 300 s, and at most 60 s unless an accepted ask); an accepted `ask` on a released episode makes it running again.
    - _Requirements: 19.1, 19.3, 19.5, 19.6, 19.8, 19.9, 19.10, 19.12, 14.29, 14.30_
  - [x] 22.5 Write the gated PDO slot test, then run everything and commit
    - Create `tests/TurnLoop/Repository/RunningSlotPdoTest.php`, skipped unless `PS_TEST_MYSQL_DSN` names a throwaway database: the same boundary cases as 22.2 run against `PdoEpisodeRepository` and the fake, with identical answers (it inserts episodes and turn rows with chosen `last_activity_at` values; no real clock wait).
    - Run `php -l` on every changed PHP file, the full PHPUnit suite (`EarthquakeGoldenMasterTest`, `ProtectedVersionsTest`, `EpisodeViewBytesTest` included) and `node tests/js/run-all.js`. Tick every finished sub-task of task 22. Commit only the files of task 22 with `task 22: Stop episodes that never run from blocking other visitors`. Never push, amend, rebase or reset.
    - _Requirements: 19.13, 19.14, 14.30, 14.32_
- [x] 23. Add response headers and block direct access to api/ files
  - [x] 23.1 Send the Security_Headers from the pages and the API
    - Touches `src/`, `api/` and `public/` (this is intended). Change nothing in `sql/`, the engine, the game logic or any text the robots read; the earthquake Golden_Master (SHA-256 starts with `c2aba50f87c22fe6`) and every protection pin stay unchanged. Do not loosen the policy.
    - Create `src/TurnLoop/Http/SecurityHeaders.php` (`final class SecurityHeaders`, `public static function all(): array`) returning exactly: `Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, `Strict-Transport-Security: max-age=31536000`.
    - `api/bootstrap.php`: `ps_json_response()` merges `SecurityHeaders::all()` into every response's headers (so 200, 403, 404, 409, 429 and 500 all carry them), keeping `Content-Type` and `Cache-Control: no-store` as they are.
    - Create `public/security_headers.php` with one function `ps_send_security_headers()` that calls `header()` for the same four pairs and prints nothing. In `public/index.php` and `public/replay.php` add `require_once __DIR__ . '/security_headers.php';` and a call to `ps_send_security_headers();` right after the existing `header('Cache-Control: no-store');`, before any output. Do not touch `public/debug.php`.
    - Inline check (already done while writing this spec): `public/index.php`, `public/replay.php`, `public/css/screens.css` and `public/js/*.js` contain no `<style>`, no `style="`, no inline `<script>` body and no inline event handler; the JS sets styles only through `element.style.*`. If you find any inline style or script that was missed, move it to a class in `screens.css` or a file in `public/js/` and list it in your summary; never loosen the policy.
    - _Requirements: 20.1, 20.2, 20.3, 20.4, 20.7, 20.8_
  - [x] 23.2 Deny direct requests to api/ files other than index.php
    - Edit `api/.htaccess` only: above the rewrite block add a `FilesMatch "^(?!index\.php$).+\.php$"` rule that denies access (`Require all denied` inside `<IfModule mod_authz_core.c>`, `Order allow,deny` and `Deny from all` inside `<IfModule !mod_authz_core.c>`), with a comment. Keep the existing rewrite (`RewriteBase /api/`, the `-f`/`-d` condition, the `index.php?route=$1` rule) and the `Cache-Control: no-store` header rule exactly as they are. `api/index.php` stays reachable, including `?route=`.
    - _Requirements: 20.5, 20.6, 20.8_
  - [x] 23.3 Write the header and inline-asset tests
    - Create `tests/TurnLoop/SecurityHeadersTest.php`: `SecurityHeaders::all()` equals the four exact strings; `ps_dispatch()` responses for 200, 403 `cross_site`, 404, 409, 429 and 500 each carry all four plus `Cache-Control: no-store`. Create `tests/TurnLoop/PageSecurityHeadersTest.php`: `public/security_headers.php` emits the same four pairs as `SecurityHeaders::all()` (the two definitions are equal), and `public/index.php` and `public/replay.php` call `ps_send_security_headers()` after `header('Cache-Control: no-store')` and before any output (source check, plus a child `php` run of each page if the CLI can report its headers). Create `tests/TurnLoop/NoInlineAssetsTest.php`: `index.php`, `replay.php`, `screens.css` and every `public/js/*.js` contain no `<style`, no `style="`, no `on<event>=` attribute, no `javascript:` and no inline `<script>` with a body.
    - _Requirements: 20.1, 20.2, 20.3, 20.4, 20.7, 14.31_
  - [x] 23.4 Write the .htaccess test, then run everything and commit
    - Create `tests/TurnLoop/ApiHtaccessTest.php`: a text check that `api/.htaccess` denies every `.php` file except `index.php` (the `FilesMatch` pattern, evaluated with `preg_match` against `index.php`, `bootstrap.php`, `episodes_ask.php`, `router.php`, `config.local.php`), appears before the `RewriteEngine` line, and still contains the `RewriteRule` and `Cache-Control` lines.
    - Run `php -l` on every changed PHP file, the full PHPUnit suite (`EarthquakeGoldenMasterTest`, `ProtectedVersionsTest`, `EpisodeViewBytesTest` included) and `node tests/js/run-all.js`. Tick every finished sub-task of task 23. Commit only the files of task 23 with `task 23: Add response headers and block direct access to api/ files`. Never push, amend, rebase or reset.
    - _Requirements: 20.5, 20.6, 14.31, 14.32_

- [x] 24. Add the judge-pass configuration, the pure Judge classes and the judge key file
  - [x] 24.1 Implement `Config` additions, `JudgePass`, `JudgeCookie` and `api/judge_key.php`
    - Protection (applies to every sub-task of this task): the earthquake Golden_Master (SHA-256 starts with `c2aba50f87c22fe6`), every protection pin, the robots' input texts, the engine and every existing table stay unchanged; `api/config.local.php` and `pilot/config.local.php` are never touched; the real pass and its hash never appear in the repository (tests generate their own values). PHP 8.2 only.
    - `src/TurnLoop/Config.php`: add `public string $judgeUntilUtc = '2027-01-29T21:00:00Z'` and `public int $judgeDailyLimit = 100` at the end of the promoted parameters; `assertInvariants()` refuses a `judgeUntilUtc` that does not round-trip through `DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', ..., UTC)` and a `judgeDailyLimit` below 1; add `judgeUntilUnix(): int`.
    - Create `src/TurnLoop/Judge/JudgePass.php` (`generate`, `isWellFormedPass`, `isWellFormedHash`, `hash`, `matches` with `hash_equals`) and `src/TurnLoop/Judge/JudgeCookie.php` (`NAME = 'ps_judge'`, `untilIso`, `passMatches`, `sign`, `issue`, `setCookieHeader`, `isValid`) exactly as in the design section "Judge Pass". No I/O in either class.
    - Create `api/judge_key.php` with `ps_load_judge_key(string $secretsDir): ?string` (file `judge_key.key`, 64 hex characters, race-safe `link()` creation, never overwrites, `error_log('judge_key_unavailable')` and `null` on failure), modelled on `api/ip_salt.php`; add `require_once __DIR__ . '/judge_key.php';` next to the `ip_salt.php` require at the end of `api/bootstrap.php`.
    - Run `php -l` on each changed PHP file. This sub-task is committed with the rest of task 24 in 24.4.
    - _Requirements: 21.1, 21.2, 21.4, 21.14, 21.15, 21.16, 21.17, 21.18, 21.33_
  - [x] 24.2 Write the unit tests for the Judge classes, Config and the key file
    - Create `tests/TurnLoop/Judge/JudgePassTest.php` (a generated pass has 43 characters from the base64url set and decodes to 32 bytes; two passes differ; `hash` is 64 lowercase hex; `matches` is true only for the generating pass; a hash passed as a pass does not match), `tests/TurnLoop/Judge/JudgeCookieTest.php` (issue/verify round trip; changed expiry, changed signature, other hash, other key, expiry in the past, time at Judging_End, malformed values and `null` are each refused; `setCookieHeader` carries `ps_judge=`, `Expires=`, `Path=/`, `HttpOnly`, `Secure`, `SameSite=Strict`), `tests/TurnLoop/ConfigJudgeTest.php` (defaults `2027-01-29T21:00:00Z` and 100, `judgeUntilUnix()` equals 1801256400, a malformed date and a limit of 0 throw `InvalidArgumentException`) and `tests/TurnLoop/JudgeKeyFileTest.php` (temp directory as in `IpSaltFileTest`: created once with 64 hex characters and mode 0600, reused on the second call, an existing invalid file is not overwritten and gives `null` with `judge_key_unavailable` logged, an unwritable directory gives `null`). Every value is generated inside the test.
    - _Requirements: 21.1, 21.4, 21.10, 21.14, 21.15, 21.16, 21.17, 21.18, 14.33, 14.34_
  - [x] 24.3 Write the property test for the Judge_Cookie
    - **Property 27: A Judge_Cookie is accepted only with the right signature and before both expiries**
    - **Validates: Requirements 21.14, 21.16, 21.17, 21.18**
    - Create `tests/TurnLoop/Properties/JudgeCookiePropertyTest.php` (Eris, `limitTo(100)`, tag `Feature: public-lab, Property 27: A Judge_Cookie is accepted only with the right signature and before both expiries`): random generated hash, key, Judging_End, time and mutations of the issued value; `isValid` is true exactly when the signature is right, the expiry is later than now and now is before Judging_End; it never throws.
    - _Requirements: 21.14, 21.16, 21.17, 21.18, 14.33_
  - [x] 24.4 Run everything and commit
    - Run `php -l` on every changed PHP file, the full PHPUnit suite (`EarthquakeGoldenMasterTest`, `ProtectedVersionsTest`, `EpisodeViewBytesTest` included) and `node tests/js/run-all.js`. Tick every finished sub-task of task 24. Commit only the files of task 24 with `task 24: Add the judge-pass configuration, the pure Judge classes and the judge key file`. Never push, amend, rebase or reset.
    - _Requirements: 21.28, 14.40_

- [x] 25. Add the judge-pass API route
  - [x] 25.1 Implement `api/judge_pass.php`, the router branch and the index wiring
    - Protection: the earthquake Golden_Master (SHA-256 starts with `c2aba50f87c22fe6`), every protection pin, the robots' input texts, the engine and every existing table stay unchanged; `api/config.local.php` and `pilot/config.local.php` are never touched; the real pass and its hash never appear in the repository. PHP 8.2 only.
    - Create `api/judge_pass.php` with `ps_build_judge_cookie(array $localConfig, Config $config): ?JudgeCookie` (null unless `judge_pass_sha256` is 64 lowercase hex and `ps_load_judge_key($localConfig['secrets_dir'])` returns a key), `ps_handle_judge_pass_get(?JudgeCookie, array $cookies, int $now)` and `ps_handle_judge_pass_post(?JudgeCookie, string $body, int $now)` in the order and with the answers of the design (404 `not_found`, 400 `bad_request` for a non-JSON body, a missing or non-string `pass` or a body over 512 bytes, 403 `judge_pass_invalid`, 403 `judge_pass_expired`, 200 with the cookie). Require it from `api/bootstrap.php`.
    - `api/bootstrap.php`: add the trailing `array $extraHeaders = []` to `ps_json_response()`, merged last, so the cookie header is set through it.
    - `api/router.php`: `ps_dispatch()` gets trailing parameters `?JudgeCookie $judge = null`, `string $body = ''`, `?int $now = null` (null means `time()`); after the existing Origin check and inside the existing `try`, route `GET judge-pass` and `POST judge-pass` to the two handlers. Do not change any other route; the create route is wired in task 26.
    - `api/index.php`: after `$localConfig` is loaded, build `$judge = ps_build_judge_cookie($localConfig, new Config())` and `$body = (string) file_get_contents('php://input', false, null, 0, 513)` and pass both to `ps_dispatch`.
    - Run `php -l` on each changed PHP file. This sub-task is committed with the rest of task 25 in 25.4.
    - _Requirements: 21.2, 21.3, 21.8, 21.9, 21.10, 21.11, 21.12, 21.13, 21.19, 21.33_
  - [x] 25.2 Write the route tests through `ps_dispatch`
    - Create `tests/TurnLoop/JudgePassRouteTest.php` with a generated hash, a generated key (temp directory) and an injected `$now`: judge mode off (null cookie object, and a missing or malformed hash through `ps_build_judge_cookie`) gives POST 404 and GET `{"judge":false,"until":null}`; a foreign `Origin` gives 403 `cross_site` and no cookie; the right pass before Judging_End gives 200 `{"judge":true,"until":"2027-01-29T21:00:00Z"}` with exactly one `Set-Cookie` carrying `ps_judge=<expiry>.<64 hex>`, `HttpOnly`, `Secure`, `SameSite=Strict`, `Path=/` and `Expires`; a wrong pass gives 403 `judge_pass_invalid`; the right pass at Judging_End gives 403 `judge_pass_expired`; a body that is not JSON, a JSON body without `pass`, a numeric `pass`, and a body of 513 bytes give 400; every response carries `Cache-Control: no-store` and the four Security_Headers; no response body or header (other than the cookie value) contains the pass or the hash; GET with the issued cookie gives `judge:true`, and with a tampered cookie, an expired cookie or no cookie gives `judge:false`.
    - _Requirements: 21.3, 21.8, 21.9, 21.10, 21.11, 21.12, 21.13, 21.19, 21.32, 14.35_
  - [x] 25.3 Write the property test for the pass check
    - **Property 28: Any pass other than the configured one is refused, and judge mode off is a 404**
    - **Validates: Requirements 21.3, 21.9, 21.11, 21.12, 21.19**
    - Create `tests/TurnLoop/Properties/JudgePassRoutePropertyTest.php` (Eris, `limitTo(100)`, tag `Feature: public-lab, Property 28: Any pass other than the configured one is refused, and judge mode off is a 404`): random strings (empty, other lengths, case changes, the hash, trailing whitespace) always give 403 `judge_pass_invalid` with no cookie; the configured pass gives 200 and a cookie that `isValid` accepts before Judging_End and 403 `judge_pass_expired` at or after it; random non-hash configuration values give POST 404 and GET `judge:false`.
    - _Requirements: 21.3, 21.9, 21.11, 21.12, 21.19, 14.35_
  - [x] 25.4 Run everything and commit
    - Run `php -l` on every changed PHP file, the full PHPUnit suite (`EarthquakeGoldenMasterTest`, `ProtectedVersionsTest`, `EpisodeViewBytesTest` included; `CrossSiteRouteTest`, `SecurityHeadersTest` and `ApiHtaccessTest` must still pass unchanged) and `node tests/js/run-all.js`. Tick every finished sub-task of task 25. Commit only the files of task 25 with `task 25: Add the judge-pass API route`. Never push, amend, rebase or reset.
    - _Requirements: 21.28, 14.40_

- [x] 26. Add the judge start branch and the judge usage scope
  - [x] 26.1 Implement the judge branch in `EpisodeStarter`, `TurnService`, the usage repositories and the create route
    - Protection: the earthquake Golden_Master (SHA-256 starts with `c2aba50f87c22fe6`), every protection pin, the robots' input texts, the engine and every existing table stay unchanged; no SQL file is added (the `judge` scope fits `daily_usage.scope VARCHAR(8)`); `api/config.local.php` and `pilot/config.local.php` are never touched; the real pass and its hash never appear in the repository. PHP 8.2 only.
    - `src/TurnLoop/TurnService.php`: `assertCanStart(string $deviceId, bool $ignoreSiteCap = false)` skips only the `too_many_running` check when true; `createEpisode(string $deviceId, ?int $seed = null, string $disaster = 'earthquake', bool $ignoreSiteCap = false)` forwards the flag to its internal `assertCanStart`. Defaults keep every existing call identical; no engine call or its order changes.
    - `src/TurnLoop/DailyLimitPolicy.php`: fourth constructor parameter `public readonly int $judge = 100`, `fromConfig` passes `$c->judgeDailyLimit`, new `judgeDenial(int $count): ?string` (`'daily_judge_limit'` when `$count >= $this->judge`).
    - `src/TurnLoop/Repository/UsageRepository.php`, `PdoUsageRepository.php` and `tests/TurnLoop/InMemoryUsageRepository.php`: add `tryReserveJudge(string $utcDay, DailyLimitPolicy $policy, string $nowUtc): ?string`, `releaseJudge(string $utcDay, string $nowUtc): void` and `judgeCount(string $utcDay): int`, on scope `judge` and key `UsageKeys::SITE_KEY`, with the same transaction shape as `tryReserve` in the PDO class.
    - `src/TurnLoop/EpisodeStarter.php`: `start(string $deviceId, ?UsageKeys $keys, string $disaster = 'earthquake', bool $judge = false)`; with `$judge === false` the body is unchanged; with `true`: `assertCanStart($deviceId, true)`, then `tryReserveJudge` (denial becomes `ApiError::tooManyRequests('daily_judge_limit')`), then `createEpisode($deviceId, null, $disaster, true)` with `releaseJudge` on any throw, then `snapshotForNewEpisode`. The device, IP and site scopes are not touched.
    - `api/episodes_create.php` and `api/router.php`: `ps_handle_create(..., bool $judge = false)` forwards the flag to `$starter->start`; in the create route compute `$isJudge = $judge !== null && $judge->isValid($cookies[JudgeCookie::NAME] ?? null, $now)` and pass it. An invalid or absent cookie leaves everything as today.
    - Run `php -l` on each changed PHP file. This sub-task is committed with the rest of task 26 in 26.5.
    - _Requirements: 21.17, 21.18, 21.21, 21.22, 21.23, 21.24, 21.26, 21.27, 21.28, 21.29, 21.33_
  - [x] 26.2 Write the judge-start tests on the fakes and the scope-width guard
    - Create `tests/TurnLoop/JudgeStartTest.php` with `EpisodeStarter` on the in-memory fakes and an injected clock: with the Device, IP and site Daily_Limits all reached, a judge start still creates an episode and those three counts do not change; five running episodes of other Devices do not stop a judge start; `device_already_running` comes first and carries the episode id; the 100th judge start of a UTC_Day succeeds and the 101st gives 429 `daily_judge_limit` with nothing created and the count unchanged; the judge count restarts at UTC midnight; a create that throws gives the judge count back; a non-judge start with the same fakes behaves exactly as in `EpisodeStarterTest`; and through `ps_dispatch` an invalid, expired or tampered `ps_judge` cookie gives the ordinary 429 `daily_device_limit` where a valid one does not. Add `tests/TurnLoop/JudgeMigrationScopeTest.php`: it reads `sql/2026-11-10_add_public_lab.sql`, extracts the width of the `daily_usage.scope` column and asserts it is at least `strlen('judge')`, and asserts that no file in `sql/` mentions `judge`. Update no existing expected value.
    - _Requirements: 21.21, 21.22, 21.23, 21.24, 21.26, 21.27, 21.29, 14.36_
  - [x] 26.3 Write the property test for the create flow
    - **Property 29: Without a valid Judge_Cookie the create flow is identical to Requirement 13**
    - **Validates: Requirements 21.18, 21.21, 21.22, 21.23, 21.24, 21.26, 21.27**
    - Create `tests/TurnLoop/Properties/JudgeStartPropertyTest.php` (Eris, `limitTo(100)`, tag `Feature: public-lab, Property 29: Without a valid Judge_Cookie the create flow is identical to Requirement 13`) against the fakes: random request sequences with random Devices, IP hashes, disasters, clock values and running episodes, and cookies that are absent, tampered, expired, for another hash or key, or presented after Judging_End, give the same outcomes and counts as the same sequence with judge mode off; sequences where every request carries a valid cookie never read or change the device, IP and site counts, never raise `too_many_running`, still raise `device_already_running` first, and count judge starts up to `judgeDailyLimit`, after which every start gives 429 `daily_judge_limit` and creates nothing.
    - _Requirements: 21.18, 21.21, 21.22, 21.23, 21.24, 21.26, 21.27, 14.36_
  - [x] 26.4 Write the gated MySQL judge usage test
    - Create `tests/TurnLoop/Repository/JudgeUsagePdoTest.php`, skipped unless `PS_TEST_MYSQL_DSN` names a throwaway database (same gating and schema reset as `UsageRepositoryPdoTest`). Every case runs against the in-memory fake and the PDO repository with identical answers: the ceiling boundary (the `judgeDailyLimit`-th reserve passes, the next is denied with no change), release never goes below zero, a new UTC day starts from zero, and the `judge` row does not change the device, IP and site rows. With two PDO connections and separate PHP processes reserving at the boundary, exactly `limit - current` succeed (same technique as `UsageRepositoryPdoTest`). Without the variable every test skips and the summary says so.
    - _Requirements: 21.23, 21.24, 21.26, 21.29, 14.36_
  - [x] 26.5 Run everything and commit
    - Run `php -l` on every changed PHP file, the full PHPUnit suite (`EarthquakeGoldenMasterTest`, `ProtectedVersionsTest`, `EpisodeViewBytesTest`, `EpisodeStarterTest`, `CreateRouteLimitsTest`, `RunningSlotTest` and every Daily_Limit property test included, unchanged) and `node tests/js/run-all.js`. Tick every finished sub-task of task 26. Commit only the files of task 26 with `task 26: Add the judge start branch and the judge usage scope`. Never push, amend, rebase or reset.
    - _Requirements: 21.28, 14.40_

- [x] 27. Add the judge-pass flow to Observe
  - [x] 27.1 Implement the client flow in `api.js`, `observe.js`, `index.php` and `screens.css`
    - Browser files only; change nothing in `src/`, `api/`, `sql/`, the engine, or any text the robots read. Protection: the earthquake Golden_Master (SHA-256 starts with `c2aba50f87c22fe6`), every protection pin, the robots' input texts, the engine and every existing table stay unchanged; the real pass and its hash never appear in the repository.
    - `public/js/api.js`: `request(method, url, jsonBody)` sends `Content-Type: application/json` and a JSON body only when `jsonBody` is given; add `judgePass(pass)` (`POST` route `judge-pass`, body `{pass: pass}`) and `judgeStatus()` (`GET` route `judge-pass`). Update the header comment's route list.
    - `public/index.php`: add `<p class="judge-note" id="judge-note" hidden></p>` directly after `#notice` inside the Switches_Panel. `public/css/screens.css`: add a `.judge-note` rule (small, muted text, no inline style anywhere).
    - `public/js/observe.js`: `lookupDom()` finds `#judge-note`; new `initJudge()` runs first in `init()` before `refreshMemoryStatus()` and `catchUpLastReflection()`: read `location.hash` against `/^#judge=([A-Za-z0-9_-]{1,128})$/`, keep the value in a local variable only, call `history.replaceState(null, '', location.pathname + location.search)` before any request (if `replaceState` is missing, send nothing), then `api.judgePass(pass)`, then `api.judgeStatus()`; with no fragment call `judgeStatus()` only; `judge === true` shows the Judge_Note "Judge pass active until January 29, 2027, 1:00 PM PT." and anything else keeps it hidden; failures show nothing. Guard each call with `typeof api.judgePass === 'function'` / `judgeStatus`. In `handleDailyLimit`, add the `daily_judge_limit` text "The judge pass has been used many times today. It opens again tomorrow (UTC)." with the Levers OFF. The pass goes to no query string, `localStorage`, `sessionStorage` or console call.
    - _Requirements: 21.5, 21.6, 21.7, 21.19, 21.20, 21.25, 21.33_
  - [x] 27.2 Write `observe-judge.test.js` and update the shared fake API
    - Add `judgePass` and `judgeStatus` to `tests/js/helpers/fake-api.js` as optional scripted calls (a fake without them keeps every existing test unchanged). Create `tests/js/observe-judge.test.js` (DOM shim with a fake `location`, `history.replaceState`, `localStorage`, `sessionStorage` and `console`, all recording; pass values generated in the test): `#judge=` is removed by `replaceState` before the first request of any kind; exactly one `POST` with `{pass}`; the pass appears in no URL, storage write or console call; the Judge_Note shows only while `judgeStatus` answers `judge:true` and shows the fixed text; a 403, a 404 and a network failure show nothing and Observe still loads; a page with no fragment sends `judgeStatus` only and behaves as before; `daily_judge_limit` shows the judge message with both Levers OFF and the stage untouched while `daily_site_limit` and `daily_device_limit` keep their texts; `observe-notice-placement.test.js`, `observe-static.test.js` and `no-stale-references.test.js` pass unchanged.
    - _Requirements: 21.6, 21.7, 21.19, 21.20, 21.25, 14.37_
  - [x] 27.3 Run everything and commit
    - Run `php -l` on every changed PHP file, the full PHPUnit suite (`NoInlineAssetsTest` and `PageSecurityHeadersTest` included, unchanged) and `node tests/js/run-all.js`. Tick every finished sub-task of task 27. Commit only the files of task 27 with `task 27: Add the judge-pass flow to Observe`. Never push, amend, rebase or reset.
    - _Requirements: 21.28, 14.40_

- [x] 28. Add the judge pass tool, the maintainer note and the secret scan
  - [x] 28.1 Implement `tools/make_judge_pass.php`, the README note and the config placeholder
    - Protection: the earthquake Golden_Master (SHA-256 starts with `c2aba50f87c22fe6`), every protection pin, the robots' input texts, the engine and every existing table stay unchanged; `api/config.local.php` and `pilot/config.local.php` are never touched; the real pass and its hash never appear in the repository. PHP 8.2 only.
    - Create `tools/make_judge_pass.php` (CLI only, exit 2 otherwise): exactly one argument `<output-file>`; refuses, with exit 1 and a fixed message that contains no secret, when the file exists, when the argument count is wrong, or when the file's directory resolves inside the repository root; creates the file with `fopen($path, 'x')` under `umask(0177)` plus `chmod 0600`; writes the single line `https://kiro.pyol.net/#judge=<pass>` plus a newline; prints only `judge_pass_sha256: <hex>` plus a newline to stdout, nothing to stderr on success, and never the pass. It uses `JudgePass::generate()` and `JudgePass::hash()` and the autoloader-free `require` of the two `src/TurnLoop/Judge/` files.
    - `api/config.example.php`: add a commented placeholder line `// 'judge_pass_sha256' => '<64 lowercase hex characters printed by tools/make_judge_pass.php>',` with a short comment that judge mode is off while it is missing. `README.md`: add a short "Judge pass (maintainer)" note with the five steps of the design (run the tool outside the repository, copy the printed hash to the server's `config.local.php`, paste the link line into Devpost "Testing instructions", the key file appears by itself, revoke by changing the hash). No real value anywhere.
    - Run `php -l` on each changed PHP file. This sub-task is committed with the rest of task 28 in 28.4.
    - _Requirements: 21.1, 21.2, 21.5, 21.30, 21.31, 21.32, 21.33_
  - [x] 28.2 Write the tool tests
    - Create `tests/Tools/MakeJudgePassTest.php`, running the tool as a child process (`PHP_BINARY`) into a temporary directory outside the repository: stdout is exactly `judge_pass_sha256: ` plus 64 lowercase hex characters and a newline and stderr is empty; the file holds one line matching `\Ahttps://kiro\.pyol\.net/#judge=[A-Za-z0-9_-]{43}\n\z` and has mode 0600; the printed hash equals the SHA-256 of the pass read back from the file; neither stdout nor stderr contains the pass; a second run on the same path fails and leaves the file byte-identical; a run with no argument, with two arguments, and with a path inside the repository root fails and writes no file; two runs on different paths give different passes. Every value comes from the tool's own output.
    - _Requirements: 21.30, 21.31, 21.32, 14.38_
  - [x] 28.3 Write the repository-wide secret scan
    - Create `tests/Tools/NoJudgeSecretsInRepoTest.php`: list files with `git ls-files` plus `git ls-files --others --exclude-standard` (skip the test with a message if `git` is unavailable), never read `api/config.local.php`, `pilot/config.local.php` or any `*.key` file, and assert that no listed text file contains `#judge=` followed by 43 base64url characters, nor a `judge_pass_sha256` assignment (`=>`, `=` or `:`) to a quoted 64-hex literal. Add a self-check inside the test that its own detector flags a generated sample string, so the scan cannot silently stop working. The test file builds its sample from `JudgePass::generate()` at run time and contains no literal pass or hash.
    - _Requirements: 21.32, 14.39_
  - [x] 28.4 Run everything and commit
    - Run `php -l` on every changed PHP file (including `tools/make_judge_pass.php`), the full PHPUnit suite and `node tests/js/run-all.js`. Tick every finished sub-task of task 28. Commit only the files of task 28 with `task 28: Add the judge pass tool, the maintainer note and the secret scan`. Never push, amend, rebase or reset.
    - _Requirements: 21.28, 14.40_

- [x] 29. Final checkpoint for the judge pass - Ensure all tests pass
  - [x] 29.1 Run the full verification
    - Run `php -l` on every PHP file changed by tasks 24 to 28, the full PHPUnit suite (including `EarthquakeGoldenMasterTest`, whose fixture SHA-256 still starts with `c2aba50f87c22fe6`, `ProtectedVersionsTest` and `EpisodeViewBytesTest`) and `node tests/js/run-all.js`. Confirm with `git diff` that no file in `src/Engine/`, no robot-facing text, no file in `sql/`, `api/config.local.php` and `pilot/config.local.php` changed; grep the changed files for a 43-character pass-like string after `#judge=` and for a 64-hex literal next to `judge_pass_sha256` (none may appear, apart from generated values at test run time); list the gated PDO tests that were skipped (`JudgeUsagePdoTest` and the earlier ones) so the maintainer can run them with `PS_TEST_MYSQL_DSN`. Report the maintainer steps from the design section "Judge Pass". Ask the user if questions arise.
    - _Requirements: 21.28, 21.29, 21.32, 21.33, 1.1, 1.7, 14.13, 14.40_

- [x] 30. Put the heat-wave run 16fe8bdc first in Featured
  - [x] 30.1 Change `Config::$featuredEpisodeIds`
    - Protection: the earthquake Golden_Master (SHA-256 starts with `c2aba50f87c22fe6`), every protection pin, the robots' input texts, the engine, the API response shape, the replay page, the judge pass and `sql/` stay unchanged. PHP 8.2 only.
    - In `src/TurnLoop/Config.php` change only the default of `featuredEpisodeIds` to the nine ids in this order: `16fe8bdc-4e6c-4c14-ba34-58a80c9f1843`, `b9931223-fc11-4162-8a4d-a8cf55886e1e`, `450e9249-94db-4744-987d-ec6774bf5057`, `4113e82c-2f09-49e4-b647-a1481d794807`, `2a3800d5-4029-448c-8152-fd622693a775`, `3ef560a5-c35c-4632-869f-d01fb2dd2e2b`, `bae88cfc-7eea-4cb6-a742-a49c0ccc401c`, `36e82c3e-03bc-4396-bfdc-e3fc33ffe671`, `186823c0-3ff4-4289-ad30-ca98ef97708e`. Nothing else in `Config`.
    - _Requirements: 6.1, 6.2, 1.1, 14.13_
  - [x] 30.2 Update the tests that pin the list
    - Update `tests/TurnLoop/ConfigPublicLabTest.php` (the exact nine ids in order; the first is `16fe8bdc-4e6c-4c14-ba34-58a80c9f1843`). Find every other test that pins the list or its length with grep (for example the `EpisodeListService` tests, `EpisodeListGroupsPropertyTest`, `replay-groups.test.js`, `DirectAccessTest`) and update only the pinned list or length, never any other expected value.
    - _Requirements: 6.1, 6.2, 1.1, 14.13_
  - [x] 30.3 Run everything and commit
    - Run `php -l` on every changed PHP file, the full PHPUnit suite and `node tests/js/run-all.js`. Confirm that `shasum -a 256 tests/fixtures/earthquake_golden_master.json` starts with `c2aba50f87c22fe6`. Confirm with `git diff` that nothing else changed (no episode records, no rules engine, no API response shape, no replay page, no judge pass, nothing in `sql/`). Tick every finished sub-task of task 30. Commit only the files of task 30 with `task 30: Put the heat-wave run 16fe8bdc first in Featured`. Never push, amend, rebase or reset.
    - _Requirements: 6.1, 6.2, 1.1, 14.13_

- [x] 31. Count heat-wave memories on the Replay page
  - [x] 31.1 Recognise both memories headings in `public/js/replay.js`
    - Protection: change `public/js/replay.js` only. No `src/`, `api/` or `sql/` change; the earthquake Golden_Master (SHA-256 starts with `c2aba50f87c22fe6`), every protection pin, the robots' input texts, the API response shape, the episode records and the judge pass stay unchanged.
    - Replace the single `MEMORIES_HEADING` with `MEMORIES_HEADING_EARTHQUAKE` and `MEMORIES_HEADING_HEAT`, each copied exactly from `MEMORIES_HEADING` and `MEMORIES_HEADING_HEAT` in `src/Engine/SituationTextGenerator.php`. Make `countSnapshotFor` find whichever heading occurs in the first non-empty situation text and count with `countMemoryBlocks` from the end of that heading; return 0 when neither occurs. Keep every public name.
    - _Requirements: 22.1, 22.2, 22.3, 22.5, 22.6, 1.1_
  - [x] 31.2 Extend the JavaScript test, add the fixture and add the PHP drift test
    - In `tests/js/replay-started-with.test.js` add: heat-wave text with 2 memories gives 2; earthquake text with 2 memories gives 2; a text with no memories block gives 0; the fixture gives 2 for Amber and 2 for Jade. Keep the existing tests unchanged.
    - Create `tests/fixtures/heat_wave_186823c0_turn1_situation_texts.json` as `{ "robot_a": "...", "robot_b": "..." }` from `turns[0].robots.robot_a.situationText` and `turns[0].robots.robot_b.situationText` of episode `186823c0-3ff4-4289-ad30-ca98ef97708e`, read through the MCP `fetch` server under `.kiro/steering/live-records.md` (GET only, slices of at most 12000 characters). Keep nothing else from the record.
    - Create `tests/Engine/ReplayHeadingsTest.php`: read both heading constants of `SituationTextGenerator` by reflection, read the source of `public/js/replay.js`, assert each value occurs as a single-quoted string, and assert the two values differ. PHP 8.2 only.
    - _Requirements: 14.41, 14.42, 22.1, 22.2, 22.3, 22.4_
  - [x] 31.3 Run everything and commit
    - Run `php -l` on every changed PHP file, the full PHPUnit suite and `node tests/js/run-all.js`. Confirm that `shasum -a 256 tests/fixtures/earthquake_golden_master.json` starts with `c2aba50f87c22fe6`. Confirm with `git diff` that nothing else changed (no `src/`, no `api/`, no `sql/`, no episode records). Tick every finished sub-task of task 31. Commit only the files of task 31 with `task 31: Count heat-wave memories on the Replay page`. Never push, amend, rebase or reset.
    - _Requirements: 14.43, 22.5, 22.6, 1.1_

## Notes

- Every sub-task, including every test sub-task, is REQUIRED. No sub-task is optional.
- Task 1 captures the protection pins on unmodified code and is never revisited; no later task edits a pinned value, the Golden_Master or the renderer fixture. If a protected value would change, stop and report to the maintainer.
- Property tests: PHP uses Eris (`limitTo(100)`) against the in-memory fakes; JavaScript uses a seeded `mulberry32` generator with at least 100 cases (no npm in this project). One test per property, tagged `Feature: public-lab, Property N: <text>`. No test makes a real AI call, network call or database call; MySQL-only tests are gated by `PS_TEST_MYSQL_DSN`.
- Task 8 includes the grouped list on Replay (sub-task 8.3) so Replay keeps working between commits; task 14 adds only Voices and cancel-on-control.
- Commits follow tech.md: one per task, message `task N: <task title>`, only after `php -l`, the full PHPUnit suite and `node tests/js/run-all.js` pass. Tick each finished sub-task before the commit; the Post Task Execution hook ticks parent tasks. Never push, amend, rebase or reset.
- Tasks 21 to 23 (security review 2026-11-10) intentionally touch `src/`, `api/` and `public/` as described in each task and add no table or column. They keep the earthquake Golden_Master (SHA-256 starts with `c2aba50f87c22fe6`) and every protection pin unchanged, and change no robot-facing text, no engine code and nothing in `sql/`. Each is committed with `task N: <title>` after `php -l`, the full PHPUnit suite and `node tests/js/run-all.js` pass; never push. MySQL-only tests are gated by `PS_TEST_MYSQL_DSN`; no test makes a real AI call.
- Tasks 24 to 29 (judge pass, Requirement 21) touch `src/`, `api/`, `tools/`, `public/index.php`, `public/css/screens.css`, `public/js/api.js`, `public/js/observe.js`, `README.md` and `api/config.example.php` as described in each task, add no table or column and no SQL file, and keep the earthquake Golden_Master (SHA-256 starts with `c2aba50f87c22fe6`), every protection pin, the robots' input texts and the engine unchanged. `api/config.local.php` and `pilot/config.local.php` are never touched. The real pass and its hash never appear in the repository, specs, steering, tests, commit messages or logs; tests generate their own values. Each is committed with `task N: <title>` after `php -l`, the full PHPUnit suite and `node tests/js/run-all.js` pass; never push. Eris properties use `limitTo(100)`; MySQL-only tests are gated by `PS_TEST_MYSQL_DSN`.
- Never run the SQL file, deploy, or touch the server. After implementation the maintainer applies `sql/2026-11-10_add_public_lab.sql` over FTPS, confirms `ip_salt.key` appears in `secrets_dir`, and checks which address PHP sees for visitors.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "1.2", "1.3"] },
    { "id": 1, "tasks": ["1.4"] },
    { "id": 2, "tasks": ["1.5"] },
    { "id": 3, "tasks": ["2.1"] },
    { "id": 4, "tasks": ["2.2", "3.1"] },
    { "id": 5, "tasks": ["3.2", "3.3", "3.5"] },
    { "id": 6, "tasks": ["3.4", "3.6"] },
    { "id": 7, "tasks": ["4.1"] },
    { "id": 8, "tasks": ["4.2", "4.3"] },
    { "id": 9, "tasks": ["4.4", "4.5"] },
    { "id": 10, "tasks": ["4.6"] },
    { "id": 11, "tasks": ["4.7"] },
    { "id": 12, "tasks": ["4.8"] },
    { "id": 13, "tasks": ["5.1", "5.2", "5.3", "5.4", "5.5", "5.6", "5.7", "5.8", "5.9", "5.10", "5.11", "5.12"] },
    { "id": 14, "tasks": ["6.1", "6.3"] },
    { "id": 15, "tasks": ["6.2"] },
    { "id": 16, "tasks": ["6.4"] },
    { "id": 17, "tasks": ["6.5", "6.6", "6.7", "6.8", "6.9"] },
    { "id": 18, "tasks": ["7.1", "7.2"] },
    { "id": 19, "tasks": ["7.3"] },
    { "id": 20, "tasks": ["7.4", "7.5", "7.6", "7.7"] },
    { "id": 21, "tasks": ["8.1"] },
    { "id": 22, "tasks": ["8.2", "8.3", "8.4"] },
    { "id": 23, "tasks": ["8.5", "8.6", "8.7", "8.8", "8.9"] },
    { "id": 24, "tasks": ["9.1"] },
    { "id": 25, "tasks": ["9.2"] },
    { "id": 26, "tasks": ["10.1"] },
    { "id": 27, "tasks": ["10.2", "10.3"] },
    { "id": 28, "tasks": ["10.4"] },
    { "id": 29, "tasks": ["10.5", "10.6", "10.7", "10.8", "10.9", "10.10"] },
    { "id": 30, "tasks": ["11.1"] },
    { "id": 31, "tasks": ["11.2", "11.3"] },
    { "id": 32, "tasks": ["12.1"] },
    { "id": 33, "tasks": ["12.2"] },
    { "id": 34, "tasks": ["12.3"] },
    { "id": 35, "tasks": ["12.4"] },
    { "id": 36, "tasks": ["13.1"] },
    { "id": 37, "tasks": ["13.2"] },
    { "id": 38, "tasks": ["13.3"] },
    { "id": 39, "tasks": ["13.4"] },
    { "id": 40, "tasks": ["13.5", "13.6"] },
    { "id": 41, "tasks": ["13.7"] },
    { "id": 42, "tasks": ["14.1"] },
    { "id": 43, "tasks": ["14.2"] },
    { "id": 44, "tasks": ["15.1"] },
    { "id": 45, "tasks": ["15.2", "15.3"] },
    { "id": 46, "tasks": ["16.1"] },
    { "id": 47, "tasks": ["16.2"] },
    { "id": 48, "tasks": ["17.1"] },
    { "id": 49, "tasks": ["18.1"] },
    { "id": 50, "tasks": ["18.2"] },
    { "id": 51, "tasks": ["18.3"] },
    { "id": 52, "tasks": ["19.1"] },
    { "id": 53, "tasks": ["19.2"] },
    { "id": 54, "tasks": ["20.1"] },
    { "id": 55, "tasks": ["20.2", "20.3"] },
    { "id": 56, "tasks": ["21.1"] },
    { "id": 57, "tasks": ["21.2", "21.3"] },
    { "id": 58, "tasks": ["21.4", "21.5", "21.6"] },
    { "id": 59, "tasks": ["22.1"] },
    { "id": 60, "tasks": ["22.2", "22.3", "22.4"] },
    { "id": 61, "tasks": ["22.5"] },
    { "id": 62, "tasks": ["23.1", "23.2"] },
    { "id": 63, "tasks": ["23.3", "23.4"] },
    { "id": 64, "tasks": ["24.1"] },
    { "id": 65, "tasks": ["24.2", "24.3"] },
    { "id": 66, "tasks": ["24.4"] },
    { "id": 67, "tasks": ["25.1"] },
    { "id": 68, "tasks": ["25.2", "25.3"] },
    { "id": 69, "tasks": ["25.4"] },
    { "id": 70, "tasks": ["26.1"] },
    { "id": 71, "tasks": ["26.2", "26.3", "26.4"] },
    { "id": 72, "tasks": ["26.5"] },
    { "id": 73, "tasks": ["27.1"] },
    { "id": 74, "tasks": ["27.2"] },
    { "id": 75, "tasks": ["27.3"] },
    { "id": 76, "tasks": ["28.1"] },
    { "id": 77, "tasks": ["28.2", "28.3"] },
    { "id": 78, "tasks": ["28.4"] },
    { "id": 79, "tasks": ["29.1"] },
    { "id": 80, "tasks": ["30.1"] },
    { "id": 81, "tasks": ["30.2"] },
    { "id": 82, "tasks": ["30.3"] },
    { "id": 83, "tasks": ["31.1"] },
    { "id": 84, "tasks": ["31.2"] },
    { "id": 85, "tasks": ["31.3"] }
  ]
}
```
