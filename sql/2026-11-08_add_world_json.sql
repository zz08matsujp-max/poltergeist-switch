-- Migration: add the per-turn recorded World_State to the turns table.
-- The screens spec records a World_State per turn: the state of the house
-- after each applied turn, and the initial (calm, pre-earthquake) state for
-- turn 0. It is stored as JSON text in utf8mb4 so multibyte content round-trips
-- without loss (Requirement 10.9).
-- The column is nullable so existing rows are backfilled once on read.

ALTER TABLE turns ADD COLUMN world_json MEDIUMTEXT NULL AFTER resident_line;
