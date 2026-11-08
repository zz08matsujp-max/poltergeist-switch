# Spec request: screens (2026-11-08)

The maintainer's request for the third spec, written by Claude before Kiro generated `.kiro/specs/screens/`.

Create a new spec named "screens": the Observe screen (live) and the Replay screen (stored), built on the turn-loop API, porting the approved look of `pilot/look.php`.

Read first: docs/en/product-brief.md §1, §2, §8, §9, §11 and §15, the steering files, the turn-loop spec (API, EpisodeView, four layers) and `pilot/look.php` (the approved look: Halloween cross-section of the house at 480×236 logical pixels, the three pixel-art characters drawn in code, breaker-lever switches with up = ON, speech bubbles, browser voices, the end card, the replay reader with highlighted evidence quotes). `pilot/` is reference only: port its drawing and animation code into the product, do not link to it.

## In scope

1. **Shared house renderer** (`public/js/`, plain JavaScript, no build): sky by the episode's time of day, rooms, furniture, decorations, darkness after the outage (only dimmed in the afternoon), shaking, fallen/broken objects, the man's poses (sitting at the low table, asleep in his futon, in the bathtub, cooking at the stove, standing with his cane, crouching under the low table) and the two robots (Amber: amber, round head, antenna; Jade: jade, square head, scanning eye). Map the engine's six room keys and spots (window, gas_stove, under_table, beside_futon, near_resident) to canvas positions. Name tags and speech bubbles never overlap.
2. **Acting out a turn** from its stored four layers: the reading (operation, target, spot) drives the motion (walk to a room or spot, take or hand over an object, turn the stove off, switch a light on, shield the man, take cover, help the man move, wait); a move judged impossible is shown as a short attempt that stops; `unknown` stays idle. Spoken lines appear in bubbles in order and are read aloud; thoughts are never shown in bubbles. The man's line appears in his bubble. The robots' positions after the turn come from the stored world (item 5), so the picture always ends in the recorded state.
3. **Observe screen** (the site's top page, `public/index.php`, served at https://kiro.pyol.net/): title, tagline, one small line saying which model each robot runs on (Amber: Anthropic claude-haiku-5-5, Jade: OpenAI gpt-6-luna); the house; the three breaker switches (earthquake works; heavy rain and heat wave are shown but disabled, labelled "coming later"); a Start button that creates an episode and a lever that flips the earthquake; then the turns run automatically: ask, apply, act out. While a turn is being acted out, the next turn's `ask` is already sent, so the waiting time is hidden (the result of an applied turn never depends on the animation). Pause / Step / Resume, Voices on/off. Resident meters (temperature, water, injury, fear) and a "What happened" log by turn. When the episode ends, the end card: turns taken, what each robot did (from the outcome texts), the man's state, and the full replies; buttons "Watch again" (opens the Replay of this episode) and "Close". If this device already runs an episode (409), offer to resume it; if 5 are running, say so and offer the Replay screen. Memory, reflection and All reset are not in this spec: leave their places out.
4. **Replay screen** (`public/replay.php`, and `?episode=<id>` to open one directly): a list of ended episodes, newest first, with date, turns and a one-line situation (time of day, where each robot and the man started); no AI calls. Play turn by turn (play, pause, step back, step forward) with each robot's reply beside the house (below it on narrow screens) and the evidence quote lit up while it is acted out: solid underline for the action, dotted for words passed on. "What it was told this turn" opens the text that robot received. A link from the Observe end card and from the top page.
5. **API additions** (turn-loop code, small and tested):
   - Store the world after every applied turn (a `world_json` column on `turns`: each robot's room, spot and held object; the man's room, spot, activity and state; power, shaking, time of day, light per room, broken and fallen objects; the stove), and the initial world for turn 0. Include it per turn in `GET /api/episodes/{id}`. For episodes stored before this change, fill it once by replaying the engine.
   - `GET /api/episodes?ended=1&limit=20`: ended episodes, newest first (id, created date, turns, starting situation); no device ids.
   - Write the ALTER statement in `sql/` as a dated migration file.
6. **Look and quality**: English UI; DotGothic16; mobile first (16 px side gutter, no horizontal scroll, the house scales with `image-rendering: pixelated`); keyboard usable buttons with visible focus; `aria-live` on the log; `prefers-reduced-motion` stops the shaking and walking animation (positions jump); `Cache-Control: no-store` on every page. Never name an AI company in the robots' text; the screens use Amber and Jade.

## Out of scope

Memory, reflection, All reset and generations; heavy rain and heat wave behaviour; the Compare and Admin screens; watching another device's running episode; the research batch. The debug page stays as it is.

## Tests

PHP tests for the API additions (world_json is stored for every applied turn and round-trips; the list endpoint returns only ended episodes, newest first, without device ids, keys or provider names; Cache-Control: no-store). Keep the renderer's mapping from (room, spot, activity) to positions in a small pure function and test it with a tiny Node-free test page or document the expected positions in design.md; Claude will check the screens with a headless browser after deployment.

## Process

Write requirements.md first, then stop so the maintainer can review it before the design.
