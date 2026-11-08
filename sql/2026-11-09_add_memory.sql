-- Migration: robot memory, generations, reflection slots, per-episode
-- snapshot columns, and a prompt/response kind tag.
--
-- This file is schema only. It is NEVER run against a database by any task;
-- the maintainer applies it on the server over FTPS after deployment.
--
-- MySQL, InnoDB, utf8mb4 everywhere so multibyte robot answers and quoted
-- speech round-trip without loss (Requirements 3.1, 8.2). No CHECK constraint
-- is relied on (MySQL historically ignored CHECK, so status values are enforced
-- in PHP, not here). No secret, credential, or provider name appears anywhere
-- in this file (Requirement 8.3).

-- Each stored reflection answer, kept as the robot's own words verbatim
-- (Requirements 3.1, 3.7). The BIGINT auto-increment id doubles as the
-- finish-order sequence, so reads oldest-first simply order by id ascending
-- (Requirement 3.6). A memory is never deleted; retirement flips `status`
-- to 'retired' and records `retired_at` (Requirements 3.4, 3.5).
CREATE TABLE memories (
  id                BIGINT       NOT NULL AUTO_INCREMENT,
  robot_id          VARCHAR(16)  NOT NULL,
  generation        INT          NOT NULL DEFAULT 1,
  disaster          VARCHAR(16)  NOT NULL DEFAULT 'earthquake',
  source_episode_id CHAR(36)     NOT NULL,
  body              MEDIUMTEXT   NOT NULL,
  status            VARCHAR(8)   NOT NULL DEFAULT 'active',
  created_at        DATETIME     NOT NULL,
  retired_at        DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_active_pick (robot_id, disaster, generation, status, id),
  KEY idx_snapshot (robot_id, generation, status, id),
  UNIQUE KEY uq_source (source_episode_id, robot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A single row holding the current generation. "All reset" advances the
-- generation; no memory row is ever deleted (Requirements 4.1, 4.2, 4.6).
-- Seeded with generation 1 so a fresh install starts in generation 1.
CREATE TABLE memory_generation (
  id         TINYINT  NOT NULL DEFAULT 1,
  generation INT      NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO memory_generation (id, generation, updated_at)
VALUES (1, 1, UTC_TIMESTAMP());

-- One reflection slot per robot per episode, used to make asking idempotent and
-- to recover a stale in-progress claim (Requirements 2.8, 2.9, 2.10, 8.5). The
-- status moves in_progress -> answered | failed; `answered_memory_id` links to
-- the stored memory, `reason` records why a failed attempt stored nothing, and
-- `claimed_at` drives the 120 s stale-recovery window.
CREATE TABLE reflections (
  episode_id         CHAR(36)     NOT NULL,
  robot_id           VARCHAR(16)  NOT NULL,
  status             VARCHAR(12)  NOT NULL DEFAULT 'in_progress',
  answered_memory_id BIGINT       NULL,
  reason             VARCHAR(32)  NULL,
  claimed_at         DATETIME     NOT NULL,
  PRIMARY KEY (episode_id, robot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Each episode carries the generation it was created in and a frozen snapshot of
-- each robot's active memories, oldest first, so every turn and every rebuild use
-- the same memories block (Requirements 6.1, 6.2, 6.6). The snapshot columns are
-- NULL for a pre-feature episode created before this migration; a NULL snapshot
-- marks such an episode and makes it non-reflectable (Requirement 2.11 handling).
ALTER TABLE episodes
  ADD COLUMN memory_generation       INT        NOT NULL DEFAULT 1,
  ADD COLUMN robot_a_memory_snapshot MEDIUMTEXT NULL,
  ADD COLUMN robot_b_memory_snapshot MEDIUMTEXT NULL;

-- Tag each stored prompt and response with its kind so reflection rows
-- (kind = 'reflection', turn_number = -1) never appear in a turn read
-- (kind = 'turn'). Existing turn rows default to 'turn' (Requirement 2.5).
ALTER TABLE prompts
  ADD COLUMN kind VARCHAR(12) NOT NULL DEFAULT 'turn';

ALTER TABLE responses
  ADD COLUMN kind VARCHAR(12) NOT NULL DEFAULT 'turn';
