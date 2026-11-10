-- Migration: public lab (per-device memory, per-device reset points, daily start counters).
--
-- This file is schema only. It is NEVER run against a database by any task.
-- The maintainer applies it on the server over FTPS BEFORE deploying the matching
-- code: the new code reads memories.device_id, device_resets and daily_usage and
-- is not required to work until this file has been applied.
--
-- MySQL, InnoDB, utf8mb4. Purely additive: one nullable column and two indexes on
-- `memories`, and two new tables. Every existing row and column keeps its meaning.
-- Existing memories keep device_id NULL and are therefore "legacy": no Device is
-- ever shown one. No secret, credential, key or provider name appears here.

ALTER TABLE memories
  ADD COLUMN device_id VARCHAR(64) NULL,
  ADD KEY idx_device_snapshot (device_id, robot_id, generation, status, id),
  ADD KEY idx_device_pick (device_id, robot_id, disaster, generation, status, id);

-- One row per accepted "All reset", append-only. A Device's generation is
-- 1 + COUNT(rows for it). reset_at gates the "Your runs" list.
CREATE TABLE device_resets (
  id        BIGINT      NOT NULL AUTO_INCREMENT,
  device_id VARCHAR(64) NOT NULL,
  reset_at  DATETIME    NOT NULL,
  PRIMARY KEY (id),
  KEY idx_device (device_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Episode starts per UTC day. usage_date is computed in PHP (UTC), never from
-- created_at (which is server-local time). key_hash is an HMAC-SHA-256 hex
-- string; no raw Device_Id or IP address is stored. The 'site' scope uses 64 zeros.
CREATE TABLE daily_usage (
  usage_date DATE         NOT NULL,
  scope      VARCHAR(8)   NOT NULL,
  key_hash   CHAR(64)     NOT NULL,
  starts     INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME     NOT NULL,
  PRIMARY KEY (usage_date, scope, key_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
