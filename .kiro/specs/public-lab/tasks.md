# Implementation Plan: Public-Lab

## Overview

This plan makes PoltergeistSwitch ready for a public audience: memory and "All reset" belong to a Device, the replay list has a Featured group and a "Your runs" group, new episodes are limited per UTC day, Observe gets its fixes (speech sequencing, levers after the end, Try again, removed controls), and the heat-wave sky is redrawn. The engine, the house rules, the robots' input texts and every recorded episode stay byte-identical (Requirement 1).

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
    - In `src/TurnLoop/Config.php` append promoted parameters with defaults: `dailyDeviceLimit` 10, `dailyIpLimit` 30, `dailySiteLimit` 100, and `featuredEpisodeIds` (the eight ids of Requirement 6.1, in that order).
    - Extend `assertInvariants()`: each limit is at least 1 and each featured id matches the UUID pattern. Existing `new Config()` callers are unaffected.
    - _Requirements: 6.1, 13.3_
  - [x] 3.2 Write `ConfigPublicLabTest`
    - Create `tests/TurnLoop/ConfigPublicLabTest.php`: the exact eight ids in order, the three default limits, and the invariants (limit below 1 and a malformed id are rejected).
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

- [~] 9. Keep direct replay links working
  - [x] 9.1 Write `DirectAccessTest` and fix `replay.php` only if it fails
    - Create `tests/TurnLoop/DirectAccessTest.php`: `GET /api/episodes/{id}` works for an ended episode that is in no list and for another Device's episode; `public/replay.php` (source check) plays `?episode=<id>` without consulting a list and shows the not-found message and the list for an unknown id. Change `replay.php` or `replay.js` only if the test shows a gap.
    - _Requirements: 7.1, 7.2, 7.3, 14.8_
  - [x] 9.2 Write the property test for caller-independent access
    - **Property 10: Direct access does not depend on the caller**
    - **Validates: Requirements 7.3**
    - Create `tests/TurnLoop/Properties/DirectAccessPropertyTest.php` (tag `Feature: public-lab, Property 10: Direct access does not depend on the caller`): same status and bytes for no cookie, the owner's cookie and another Device's cookie.

- [ ] 10. Add Voices, the speech sequencer and act-out sequencing
  - [ ] 10.1 Add the JS test helpers for speech
    - Create `tests/js/helpers/fake-clock.js` (`setTimeout`, `clearTimeout`, `now` driven by `advance(ms)`), `fake-voices.js` (the fake Voices_Module: `speak(id, line, onDone) -> boolean`, `setOn/isOn`, `gesture`, `cancel`, `available`; records calls with virtual time; `finish('end'|'error')`; options `startsReading:false` and never-finish) and `fake-house.js` (records `showBubble(id, text, seconds)` and exposes the open bubbles).
    - _Requirements: 14.11_
  - [ ] 10.2 Update `public/js/voices.js`
    - Add `speak(id, line, onDone) -> boolean` (onDone exactly once with `'end' | 'error' | 'cancelled'`), `setOn/isOn` (the setting, independent of the engine), `gesture()` and `cancel()`; `speak` returns false and touches no `speechSynthesis` when the setting is off, there is no engine, `gesture()` has not run, or the line is blank. Remove the capture-phase "any click" unlock (`autoUnlock:false`); keep `setEnabled/isEnabled/on/off/unlock/stop` as aliases.
    - _Requirements: 11.1, 11.3, 11.4, 11.14_
  - [ ] 10.3 Create `public/js/speech-sequencer.js`
    - UMD shape like `voices.js`. `readingTime(text)` is `min(12000, 2000 + 60 * codePoints)`; `linesOfTurn(turn)` takes each element of `reading.words` for `robot_a`, then `robot_b`, then `residentLine`, drops blanks and never includes a thought; `createSpeechSequencer({house, voices, timers, now})` with `play(lines)`, `cancel()` and `voicesChanged(on)` as in the design (12000 ms hard cap that also cancels voices; Voices turned off mid-line switches to the remaining Reading_Time from the original start; turned on mid-line does not start reading that line); `bindVoicesButton(button, voices, sequencer)` updates the label "Voices: on|off" and `aria-pressed` within 100 ms.
    - _Requirements: 11.1, 11.2, 11.5, 11.6, 11.7, 11.8, 11.9, 11.10, 11.12, 11.13, 11.14, 11.15_
  - [ ] 10.4 Make `act-out.js` use the sequencer
    - `createActOut(house, voices, sequencer)`: remove `emitSpeech/showSpoken/speakAloud`; `playTurn` starts the animation as today, calls `sequencer.play(linesOfTurn(turn))`, snaps to the recorded world when the animation ends, and resolves after both are done; `cancel()` also calls `sequencer.cancel()`; reduced motion still jumps to the world within 100 ms. Update every existing JS test that constructs `createActOut`.
    - _Requirements: 11.5, 11.10, 11.11, 11.12, 11.16_
  - [ ] 10.5 Write `voices-gesture.test.js`
    - **Property 20: No speech before the First_Gesture**
    - **Validates: Requirements 11.3**
    - Create `tests/js/voices-gesture.test.js` against the real `voices.js` with a fake `speechSynthesis` (seeded generator, at least 100 cases): no utterance before `gesture()`; `speak` returns false with the setting off or no engine; `onDone` fires exactly once; label and setting are independent of the engine; after `gesture()` speech is allowed (11.1, 11.4).
  - [ ] 10.6 Write `speech-reading-time.test.js`
    - **Property 16: Reading time**
    - **Validates: Requirements 11.8**
    - Create `tests/js/speech-reading-time.test.js` (seeded, at least 100 cases): equals `min(12000, 2000 + 60 * codePointCount)`, at least 2000, at most 12000, non-decreasing as the text grows.
  - [ ] 10.7 Write `speech-lines.test.js`
    - **Property 17: Spoken lines never include a thought**
    - **Validates: Requirements 11.13, 11.5**
    - Create `tests/js/speech-lines.test.js` (seeded, at least 100 cases): only `robot_a.reading.words`, then `robot_b.reading.words`, then `residentLine`, blanks dropped, never any other reply text.
  - [ ] 10.8 Write `speech-sequencer-order.test.js`
    - **Property 18: The sequencer shows one line at a time, in order, for the right time**
    - **Validates: Requirements 11.5, 11.6, 11.7, 11.8, 11.9, 11.10, 11.12**
    - Create `tests/js/speech-sequencer-order.test.js` (seeded, at least 100 cases, virtual time): at most one bubble open at any instant, lines in order, voice mode closes on `onDone` or 12000 ms and the next line opens within 100 ms, timer mode closes after exactly `readingTime`, an empty list adds 0 ms, `playTurn` resolves only after the last bubble closed and the animation ended; the caption strip uses the same close (11.11).
  - [ ] 10.9 Write `speech-voices-toggle.test.js`
    - **Property 19: Toggling Voices mid-line**
    - **Validates: Requirements 11.14, 11.15**
    - Create `tests/js/speech-voices-toggle.test.js` (seeded, at least 100 cases): Voices off at time t stops reading within 200 ms and closes at `startedAt + readingTime`; Voices on at time t starts no reading for the current line and closes at `startedAt + readingTime`; the next line may use voice.
  - [ ] 10.10 Write `act-out-speech.test.js`
    - Create `tests/js/act-out-speech.test.js`: `playTurn` resolves only after animation and speech are both done; a turn with no Spoken_Line waits 0 ms for speech; `cancel()` closes the bubble and resolves the pending turn; reduced motion jumps within 100 ms; no speech before the First_Gesture.
    - _Requirements: 11.3, 11.10, 11.12, 11.16, 14.11_

- [ ] 11. Remove Pause, Step, Resume and Heavy rain from Observe
  - [ ] 11.1 Remove the controls and the lever
    - In `public/index.php` delete `#btn-pause`, `#btn-step`, `#btn-resume`, `#sw-rain` and the "coming later" text, and fix the stale comments. In `public/js/observe.js` remove `pause/step/resume`, `state.paused`, `stepOnce`, the three `el.btn*` lookups, their `wireControls` handlers, `el.swRain` and their exports. Remove the `.later` and any Pause/Step/Resume-only CSS from `public/css/screens.css`. Keep Start, Voices, Notice_Resume and "Watch a past episode".
    - _Requirements: 10.1, 10.2, 10.3, 10.4, 10.5, 10.7_
  - [ ] 11.2 Write `observe-static.test.js`
    - Create `tests/js/observe-static.test.js`: `index.php` and `observe.js` contain none of `btn-pause`, `btn-step`, `btn-resume`, `sw-rain`, `Heavy rain`, `coming later`, `stepOnce`; two Levers only (Earthquake and Heat wave); Start and Voices exist; Replay's own Play, Pause, Step back and Step forward controls are unchanged in `replay.php`.
    - _Requirements: 10.1, 10.2, 10.5, 10.6, 14.10_
  - [ ] 11.3 Update the existing JS tests that used the removed controls
    - Update `tests/js/heat-wave-screens.test.js` (remove the Heavy rain disabled and "coming later" assertions; everything else stays) and any of `end-card-reflection.test.js`, `memory-ui.test.js`, `disaster-already-set.test.js`, `ask-apply-recovery.test.js` that reference removed exports or elements.
    - _Requirements: 10.1, 10.5, 14.13_

- [ ] 12. Add the Observe phase machine and levers after an episode ends
  - [ ] 12.1 Add the fake API helper and expose the phase
    - Create `tests/js/helpers/fake-api.js` (scripted `{status, body}` per call, records calls). In `observe.js` add `state.phase` (`idle`, `starting`, `staged`, `running`, `unfinished`, `ended`) to `_state()`.
    - _Requirements: 9.5, 14.9_
  - [ ] 12.2 Implement the phases and the run guards
    - `onLever(disaster)` and `onStart()` dispatch on `state.phase` per the design table; `starting` is set synchronously at click time; a second Lever flip or Start press while `starting` or `running` is ignored. `startNewRun()` from `ended` cancels act-out, clears `pendingAsk`, the episode id, expected turn, meters, log, notice and End_Card (`dismissEndCard`), and drops the reflection host. `runEndCardReflection()` returns a Promise; `finishClosing(episodeId)` switches both Levers OFF only if `state.episodeId === episodeId && phase === 'ended'`. The existing ask/apply 5xx recovery stays unchanged here.
    - _Requirements: 9.1, 9.2, 9.3, 9.4, 9.5_
  - [ ] 12.3 Wire Voices into Observe
    - `public/index.php`: the Voices button starts as "Voices: on" with `aria-pressed="true"`; load `js/speech-sequencer.js` before `act-out.js`. `observe.js`: `createVoices({enabled:true, autoUnlock:false})`, `createSpeechSequencer({house, voices})`, `createActOut(house, voices, sequencer)`, `bindVoicesButton(...)`; `voices.gesture()` is called synchronously first in the Lever and Start handlers.
    - _Requirements: 11.1, 11.2, 11.3, 11.4_
  - [ ] 12.4 Write the Observe flow examples
    - Create `tests/js/observe-flow.test.js` with fixed examples: both Levers are OFF after the last turn and the reflection (or "not stored"); a later Lever or Start creates a new episode with empty client state and no End_Card; a second Lever flip or Start press while a run is in progress is ignored; the Voices label starts "Voices: on" and no speech happens before the First_Gesture.
    - _Requirements: 9.1, 9.2, 9.3, 9.5, 11.1, 11.3, 14.9, 14.16_

- [ ] 13. Add All reset, Try again and the limit notices to Observe
  - [ ] 13.1 Add `house.reset()` to the renderer
    - In `public/js/house-renderer.js` add `reset()` restoring the mount-time state and clearing bubbles, caption and thinking flags. Earthquake drawing stays byte-identical: `renderer-earthquake-calls.test.js` must still pass unchanged.
    - _Requirements: 8.1, 12.5_
  - [ ] 13.2 Implement All reset on Observe
    - `confirmResetMemory`: on 200 call `clientResetToFirstLoad()` (both Levers OFF, `house.reset()`, meters, log, End_Card and notice cleared, `phase = idle`, status line from the response) then `refreshMemoryStatus()`; on 409 show the plain message "All reset is available when no run is in progress. A stopped run is released within 5 minutes." and change nothing else; the All_Reset_Button is `disabled` in `starting` and `running`.
    - _Requirements: 8.1, 8.2, 8.3, 8.4_
  - [ ] 13.3 Implement the Unfinished_Episode notice and Try again
    - When `recoverTurn` reaches `MAX_TURN_TRIES`, or an ask/apply status is not recoverable: `phase = unfinished`, keep the turns on screen, show "A turn could not be completed." with a primary "Try again" button; remove every "Paused" string (failed ask, failed apply, unreachable lab). `tryAgain()` calls `voices.gesture()`, reads `getEpisode(state.episodeId)`, shows the End_Card if ended, otherwise adopts the stored state, clears the notice and resumes the loop with a fresh failure count; it never calls `createEpisode`; on failure it stays `unfinished`. Lever and Start do the same while `unfinished`. The 5000 ms wait, the 3 tries and the log sequence are unchanged.
    - _Requirements: 9.4, 9.6, 9.7, 9.8, 9.9, 11.3_
  - [ ] 13.4 Implement the 409 and 429 create notices
    - 409 `device_already_running`: Device_Busy_Notice with Notice_Resume bound to `body.episodeId` or the `localStorage ps:lastEpisodeId` fallback (ignored when `localStorage` is unavailable), plus "Watch a past episode"; Notice_Resume calls `voices.gesture()` and continues through `resumeEpisode`. 429: `phase = idle`, both Levers OFF, stage unchanged; `daily_device_limit` and `daily_ip_limit` show "You have run the lab many times today. It opens again tomorrow (UTC)."; `daily_site_limit` shows "The lab has had a busy day. It opens again tomorrow (UTC)."
    - _Requirements: 10.3, 10.4, 11.3, 13.11, 13.12, 13.13_
  - [ ] 13.5 Update `ask-apply-recovery.test.js`
    - Change only the notice assertions (the two "Paused." strings become the "A turn could not be completed." notice with the Try again button); the call logs, the 5000 ms waits and the 3 tries stay as they are.
    - _Requirements: 9.4, 9.9, 14.13_
  - [ ] 13.6 Extend the Observe flow examples
    - Extend `tests/js/observe-flow.test.js`: after the tries are used up the notice reads "A turn could not be completed." with a "Try again" button and never contains "Paused"; Try again continues the same episode without a create; Lever and Start do the same; All reset sets both Levers OFF and returns stage, meters, log and End_Card to first load, and the next run starts normally; the All_Reset_Button is disabled while turns run; the All reset 409 message; the 429 messages keep both Levers OFF and the stage unchanged; the Device_Busy_Notice shows Notice_Resume, which continues the running episode through `resumeEpisode` (body `episodeId` and `localStorage` fallback).
    - _Requirements: 8.1, 8.2, 8.3, 8.4, 9.6, 9.7, 9.8, 9.9, 10.3, 10.4, 13.11, 13.12, 13.13, 14.9, 14.10, 14.15, 14.20_
  - [ ] 13.7 Write the Observe phase invariants property test
    - **Property 21: Observe phase invariants**
    - **Validates: Requirements 8.1, 8.2, 8.4, 9.2, 9.3, 9.5, 9.7, 9.8, 9.9, 13.11**
    - Create `tests/js/observe-phases-property.test.js` (seeded `mulberry32`, at least 100 random event sequences against `fake-api.js`): at most one `createEpisode` in flight and none while `starting`, `running`, `unfinished` or `staged` (for Start); Lever and Start act as Try again while `unfinished`; Levers OFF after an episode ends and its reflection settles; empty client state on a later run; first-load state after an accepted All reset; the All reset button disabled in `starting` and `running`; no notice contains "Paused"; a 429 leaves the Levers OFF and the stage unchanged.

- [ ] 14. Add Voices and the sequencer to Replay
  - [ ] 14.1 Wire Voices, the sequencer and cancel-on-control into Replay
    - `public/replay.php`: add `<button type="button" class="btn" id="btn-voices" aria-pressed="true">Voices: on</button>` in the header outside the hidden controls, and load `js/speech-sequencer.js` before `act-out.js`. `public/js/replay.js`: construct voices (`enabled:true`, `autoUnlock:false`), the sequencer and act-out as on Observe, `bindVoicesButton`; call `voices.gesture()` in the Play, Pause, Step back and Step forward handlers; `play`, `pausePlayback`, `stepBack`, `stepForward` and `chooseAnother` each call `actOut.cancel()` first. The Replay control set itself is unchanged.
    - _Requirements: 10.6, 11.1, 11.2, 11.3, 11.4, 11.16_
  - [ ] 14.2 Write `replay-voices.test.js`
    - Create `tests/js/replay-voices.test.js`: the label starts "Voices: on" and the button exists in list and playback modes; no speech before the First_Gesture; Pause, Step back, Step forward and Play cancel any reading and bubble timer, close the open bubble and start no further line until the new position's Line_Sequence begins; the picture still ends in the recorded world.
    - _Requirements: 11.1, 11.3, 11.16, 14.11_

- [ ] 15. Draw the heat-wave sky
  - [ ] 15.1 Update the heat palette in `house-renderer.js`
    - When `S.heat`: sky gradient from rgb(58,14,30) to rgb(196,78,40) (no generic sun, stars or bats), hills `#3a1622`, ground `#2a1a10`, ground texture `#3c2614`. `heatSky()` draws only the sun at (330, 34): `#ffc83a` alpha 0.16 radius 32, `#ffd84a` alpha 0.32 radius 21, `#ffe46a` radius 13; the old orange wash and white sun are removed. Keep the heat shimmer, windows, fan and air conditioner. An earthquake world (`S.heat === null`) skips every new branch, and a heat world replaced by an earthquake world draws the earthquake sky and ground again.
    - _Requirements: 12.1, 12.2, 12.3, 12.4, 12.5, 12.6_
  - [ ] 15.2 Write `heat-sky.test.js` examples
    - Create `tests/js/heat-sky.test.js` with `fake-canvas.js`: the exact sky end colours, hills, ground, texture and sun discs in a heat-wave world; none of the Heat_Palette colours and no sun in an earthquake world; the earthquake call log equals `tests/fixtures/renderer_earthquake_calls.json` (never regenerated); heat then earthquake equals earthquake alone.
    - _Requirements: 12.1, 12.2, 12.3, 12.5, 12.6, 14.12_
  - [ ] 15.3 Write the property test for the heat palette
    - **Property 22: Heat palette only in heat worlds**
    - **Validates: Requirements 12.1, 12.2, 12.3, 12.5, 12.6**
    - Create `tests/js/heat-palette-property.test.js` (seeded, at least 100 cases, `Math.random` stubbed): for any earthquake world the call sequence equals the recorded one and contains no Heat_Palette colour or sun disc; for any heat-wave world the sky, hills, ground, texture and sun match Requirements 12.1 to 12.3; drawing a heat world then an earthquake world gives the same calls as the earthquake world alone.

- [ ] 16. Audit the existing tests after the changes
  - [ ] 16.1 Write a stale-reference check
    - Create `tests/js/no-stale-references.test.js` that scans `public/` and `tests/js/` for leftovers: `Paused.`, `sw-rain`, `btn-pause` (Observe only), `stepOnce`, `Array.isArray(result.body)` for the list, `autoUnlock` defaulting to true, and the old `createActOut(house, voices)` two-argument call. Fix every leftover it finds in the files this spec owns.
    - _Requirements: 9.9, 10.1, 10.5, 14.13_
  - [ ] 16.2 Review the test changes for protected edits
    - Review `git diff` of every existing test changed by this spec. Only these were changed on purpose: the memory tests (design C2, C3), the list tests (C5), the 409 body tests (C6), `heat-wave-screens.test.js` (C7) and `ask-apply-recovery.test.js` (C8). Confirm `tests/fixtures/earthquake_golden_master.json`, `tests/fixtures/renderer_earthquake_calls.json`, `ProtectedVersionsTest` and `EpisodeViewBytesTest` are byte-for-byte unchanged since task 1, and the `earthquake_golden_master.json` SHA-256 still starts with `c2aba50f87c22fe6`. Report any other changed expected value to the maintainer instead of editing it.
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 14.13_

- [ ] 17. Final checkpoint - Ensure all tests pass
  - [ ] 17.1 Run the full verification
    - Run `php -l` on every PHP file changed by this spec (`git diff --name-only` against the first commit of this spec), the full PHPUnit suite (including `EarthquakeGoldenMasterTest`) and `node tests/js/run-all.js`. Confirm that no file in `sql/` was executed, no secret or Device_Id appears in the changed files (grep), `sql/2026-11-10_add_public_lab.sql` is the only new file in `sql/`, and list the gated PDO tests that were skipped so the maintainer can run them with `PS_TEST_MYSQL_DSN` (including `testPdoTwoPerTripleCommitsSerializeToExactlySix` on MariaDB 10.11). Ask the user if questions arise.
    - _Requirements: 1.1, 1.6, 1.7, 1.8, 14.13_

## Notes

- Every sub-task, including every test sub-task, is REQUIRED. No sub-task is optional.
- Task 1 captures the protection pins on unmodified code and is never revisited; no later task edits a pinned value, the Golden_Master or the renderer fixture. If a protected value would change, stop and report to the maintainer.
- Property tests: PHP uses Eris (`limitTo(100)`) against the in-memory fakes; JavaScript uses a seeded `mulberry32` generator with at least 100 cases (no npm in this project). One test per property, tagged `Feature: public-lab, Property N: <text>`. No test makes a real AI call, network call or database call; MySQL-only tests are gated by `PS_TEST_MYSQL_DSN`.
- Task 8 includes the grouped list on Replay (sub-task 8.3) so Replay keeps working between commits; task 14 adds only Voices and cancel-on-control.
- Commits follow tech.md: one per task, message `task N: <task title>`, only after `php -l`, the full PHPUnit suite and `node tests/js/run-all.js` pass. Tick each finished sub-task before the commit; the Post Task Execution hook ticks parent tasks. Never push, amend, rebase or reset.
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
    { "id": 48, "tasks": ["17.1"] }
  ]
}
```
