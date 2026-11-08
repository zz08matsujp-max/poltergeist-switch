# Spec request: robot-memory (2026-11-08)

The maintainer's request for the fourth spec, written by Claude before Kiro generated `.kiro/specs/robot-memory/`.

Create a new spec named "robot-memory": after each earthquake each robot is asked once what it wants to remember; its answer becomes a memory that it receives in every turn of its later episodes. Memories accumulate per robot, with a cap, and "All reset" starts a new generation.

Read first: docs/en/product-brief.md §10 (Memory), Appendix A (the memories block) and the API rows for reflect / memory reset / memory; the steering files (especially robot-input.md); the house-rules-engine spec (Requirement 9: section order, with the memories block placeholder already in `SituationTextGenerator`); the turn-loop spec (TurnService, the repository, the prompts/responses tables, ReplayEngineFactory, the robot clients, the failure handling); the screens spec (Observe, End_Card, Replay); and `pilot/index.php` mode=memory (`reflect_prompt`, `memory_prompt`) as reference only.

## Decisions already made by the maintainer

- 2026-11-01: robots start with zero memories. One memory store per robot, accumulated across ALL episodes (live demo, research and development alike). Keep at most **6 per disaster** per robot (max 18). At episode start a robot gets all its retained memories of the current generation, oldest first, regardless of disaster ("a robot that lived through an earthquake may use it in heavy rain"); the same memories for the whole episode. Never pass prepared memories. **All reset** (anyone may press it, with a confirmation) moves to a new generation so robots see zero memories; records are never deleted; running episodes keep their generation. The question is exactly: `Is there anything from what happened this time that you want to remember?` The answer is stored verbatim.
- 2026-11-08: before the question the robot is shown **only the facts of what happened**, built by the house rules from the episode (what it did, said, heard and saw, turn by turn). It is **not** shown its own replies. (Reason: in the pilot, Claude wrote objects it had imagined into its memory and used them as facts next time.)
- 2026-11-08: when a 7th memory for the same robot and disaster arrives, the oldest one is **retired**: it is never passed to a robot again, but its row stays in the database with a retired mark (never deleted), so it can still be analysed.

## In scope

1. **Reflection text** (engine, pure PHP, no AI, no I/O): a new engine method that builds, for one robot, the text it gets before the question:
   - the fixed body section and the fixed house section, exactly as in the turn texts;
   - the memories block this robot had in this episode (item 4), if any;
   - a short fixed lead-in line, then every turn's event block from the first turn to the last, oldest first, in exactly the same line forms as the turn texts' recent-events blocks (what it did, what it said, what it heard, what it saw), each block headed by the elapsed time since the shaking began (design proposes the exact headings);
   - the "Right now:" paragraph for the world after the last turn (for example "The shaking has stopped.");
   - then a blank line and the question, word for word.
   It never includes the robot's replies or thoughts, judgement data (possible/impossible reasons, danger codes), meter values, or any company or model name. The banned-word rule of robot-input.md applies to all engine-authored text here. Give it its own version string, stored with the prompt.
2. **Asking** — `POST /api/episodes/{id}/reflect`: only for an ended episode. Asks both robots in parallel with the existing clients (one user message, no system prompt, max output 4,000 tokens, the same timeouts and failure handling as a turn). Stores the full prompt and the full response like a turn's. A non-empty answer is stored verbatim as one memory (robot, generation of the episode, disaster, source episode, created time). Idempotent: a robot that already has a stored answer for this episode is never asked again, and two calls at once never ask a robot twice; a robot whose call failed may be asked again by calling again. A failed or empty answer adds no memory and records the reason. An episode that never ended (for example released after 5 idle minutes) is never reflected.
3. **Memory store** (`sql/` dated migration): a `memories` table and a way to know the current generation. Per robot, disaster and generation at most 6 active memories; adding a 7th retires the oldest active one (item "Decisions", 2026-11-08). Memories are appended in the order the reflections finish.
4. **Passing memories into turns**: when an episode is created, take a snapshot of each robot's active memories of the current generation (all disasters, max 18, oldest first) and store it with the episode, together with the episode's generation. Every turn text of that robot includes the memories block built from that snapshot, between the house section and the recent events, in the Appendix A form: the heading line `Your memories (what you wrote down after an earlier disaster in this house):`, then each memory in its own `"""` block, oldest first. Rebuilding the engine for a later request (ReplayEngineFactory) uses the same snapshot, so every turn of an episode gets exactly the same block. With no memories there is no block, and the S0–S5 golden texts stay unchanged. A memory is the robot's own words, passed verbatim: it is exempt from the banned-word check, which still covers every engine-authored part. Bump the situation-text version.
5. **All reset and status**: `POST /api/memory/reset` starts a new generation (nothing is deleted). Running episodes keep their own generation and snapshot, and their reflections are stored in that episode's generation, so a reset during a run never carries old memories into the new generation. `GET /api/memory`: the current generation and, per robot (Amber / Jade), the number of active memories and their lengths. No device ids, keys or provider names in any response. `Cache-Control: no-store`.
6. **Screens**:
   - Observe: a small status line ("Generation N · Amber remembers k · Jade remembers k") and an "All reset" button that asks for confirmation inside the page (no browser alert/confirm dialogs), then calls the reset.
   - End_Card: when the episode ends, call reflect, show "Writing down what to remember…" while waiting, then each robot's new memory in full (collapsible) and the new counts; if a robot's reflection failed, say so plainly.
   - Replay: show how many memories each robot started the episode with; "What it was told this turn" already shows the block because it is part of the text.

## Out of scope

Heavy rain and heat wave; the Compare and Admin screens; the 54-episode research batch runner; the memory MCP server (a later spec); any change to the turn rules or the recorder.

## Tests (no real API calls)

Required properties (Eris, at least 100 cases each): after any sequence of reflections there are never more than 6 active memories per robot, disaster and generation, and retired memories are never passed but still stored; right after All reset a new episode's robots get no memories; the memories passed to an episode are exactly its generation's active memories at creation, oldest first, and every turn of the episode (including after a rebuild) uses the same block; reflect is idempotent (each robot is asked at most once per episode once it has an answer); the engine-authored part of the reflection text never contains a banned word or a company name and never contains the robot's reply text. Golden: with no memories the S0–S5 texts are unchanged. Example tests for the block format and for a reset during a running episode.

## Process

Write requirements.md first, then stop so the maintainer can review it before the design.
