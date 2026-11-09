-- Migration: heat-wave episode support (heat-wave Requirement 1.8).
--
-- This file is schema only. It is NEVER run against a database by any task;
-- the maintainer applies it on the server over FTPS after deployment.
--
-- MySQL, InnoDB, utf8mb4. The change is purely additive: a single new nullable
-- column on `episodes` that records the turn a heat-wave episode's heat was
-- fixed. It is NULL for every existing (earthquake) row and for any earthquake
-- episode created later, so no existing row, column, or API field changes
-- meaning and `GET /api/episodes/{id}` for an earthquake episode returns the
-- same bytes as before. No secret, credential, or provider name appears here.

ALTER TABLE episodes ADD COLUMN heat_wave_start_turn INT NULL;
