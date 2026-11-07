# Spec request: turn-loop (2026-11-08)

The maintainer's request for the second spec, written before Kiro generated `.kiro/specs/turn-loop/`.

Create a new spec named "turn-loop": the turn loop with the real robots, so that one earthquake episode can run from zero memory to the end and a human can read every turn.

Read first: docs/en/product-brief.md §7–§9 and §12–§15, the steering files, and the existing spec .kiro/specs/house-rules-engine/ (Engine::applyTurn, Engine::situationTextFor, EvidenceCheck, TurnRecord). This spec builds on that engine. Do not change the engine's behaviour; if something is missing there, say so and propose a change to that spec first.

## In scope (brief §15 items 2 and 3)

1. **AI clients**: Claude `claude-haiku-5-5` (Anthropic Messages API) and GPT `gpt-6-luna` (OpenAI Responses API). One user message, no system prompt, default parameters, max output 4,000 tokens, the same for both. Call both in parallel with curl_multi. Store the full prompt and reply, tokens, seconds, cost and truncation (`stop_reason = max_tokens` / `status = incomplete`). Timeout 90 s per call, no retry inside one request. Keys are read from files outside the web root through a local config file that is never committed (like `pilot/config.local.php`).
2. **Recorder**: `claude-haiku-5-5` (decided by the maintainer on 2026-11-08), temperature 0, one reply at a time. It returns JSON: the first physical action (operation, target id, spot), its actionQuote, the spoken lines exactly as quoted, and reported speech (quote, heardAs, ownForm), following the reading rules of house-rules-engine Requirement 12.6. Give it only what it needs to map words to ids: the operation names, the room keys, the ids of the objects in that robot's room, and "resident". The recorder prompt is a fixed, versioned text stored with every reading. Invalid JSON gives `unknown`. Every reading then goes through EvidenceCheck. The recorder never decides what is possible or dangerous. Action codes (A1–N1) are out of scope for now.
3. **Turn flow** (brief §8): `ask` builds each robot's text with Engine::situationTextFor (no memories in this spec), calls both robots, and stores each reply as soon as it arrives; a repeated `ask` reuses stored replies and calls only a missing robot; after two failed attempts that robot's turn is "no reply" and its action is `unknown`. `apply` reads both replies with the recorder (in parallel), runs EvidenceCheck and Engine::applyTurn, and stores the four layers (robot text, reading, judgement, outcome) in one transaction; a repeated `apply` returns the current state. Turn states: waiting → replies complete → applied. A wrong turn number returns HTTP 409 with the current state. The observer's earthquake switch sets the start turn.
4. **Storage**: MySQL (utf8mb4) tables for episodes, turns, prompts, responses, recordings and actions (a subset of brief §13), with SQL files in `sql/`. Use PDO behind a small repository interface so the logic can be tested without a server.
5. **API** (brief §12 subset): `POST /api/episodes`, `POST /api/episodes/{id}/disaster`, `POST /api/episodes/{id}/turns/{n}/ask`, `POST /api/episodes/{id}/turns/{n}/apply`, `GET /api/episodes/{id}`. JSON, `Cache-Control: no-store` on every response. One running episode per device (random id in a cookie), at most 5 at once; an episode with no turn for 5 minutes frees its slot.
6. **Debug page**: a minimal plain-HTML page that starts an episode, turns the earthquake on and steps through the turns, showing for each robot the text it was sent, its reply, the reading with its quotes, the judgement and the outcome. The real Observe screen comes in a later spec.

## Out of scope

Observe, Replay and Compare screens; memory, reflection, All reset and generations; heavy rain and heat wave; the research batch; the MCP server.

## Tests

Never call the real APIs in tests; use fake AI clients. Include property tests with Eris (`limitTo(100)`): a repeated ask calls each robot at most once per turn; a repeated apply advances the engine once; both robots get all four layers stored every applied turn; a reading whose quote is not in the reply becomes unknown. Also one end-to-end test with fake robots and a fake recorder that runs a full earthquake episode to the end.

## Process

Write requirements.md first, then stop so the maintainer can review it before the design.
