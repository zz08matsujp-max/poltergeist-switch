# How the submission video is made (2026-11-02, draft v0.1)

Drafts of the submission video that Claude made in a cloud workspace, and the tools used to make them. This is separate from the main app built with Kiro.

## What exists (`out/`, not in the repository)

- `submission_draft_v0.1.mp4`: 1920×1080, 2 min 52 s. Night house → the text the robots were given → replay with voices → numbers → Kiro screens (placeholder) → ending. The maintainer approved the flow (2026-11-02).
- `replay_P3-3_full.mp4`: a voiced capture of the mock-up v0.5 replay (run 3 of the house with both robots in the living room; 62.5 s).
- `voice_candidates_Kokoro.mp4`: a side-by-side listen of the voice candidates.

## Voices (Kokoro-82M, weights under Apache-2.0)

Voices the maintainer chose on 2026-11-02: Claude = af_heart, ChatGPT = am_fenrir, the man = bm_lewis (speed 0.8), ChatGPT’s Japanese lines = jf_alpha.
Credit: Voices: Kokoro-82M by hexgrad (Apache-2.0)

## Tools (`tools/`)

| File | Role |
|---|---|
| `tts_gen.py` | Synthesizes every line in the replay data (`pilot/replay_t2.json`) with the chosen voices. `tts_lines.json` maps each line to its audio file and length in seconds |
| `render_av.js` | Captures the mock-up page frame by frame (1760×990 scaled to 1920×1080). It replaces the page’s speech, pauses for the length of each line’s audio, and records when each line starts |
| `mix.py` | Lays each line’s audio at its recorded time and adds the sound without re-encoding the picture |
| `film_idle.js` | Films the night house before any switch is flipped (for the opening and the ending) |
| `cards.js` | Makes the subtitles (transparent overlays) and the cards: the text given to the robots, the numbers, the placeholder Kiro screens and so on. The English subtitles live here |
| `build.py` | Renders each scene with the same settings and joins them |

Requirements: a Python 3.12 environment with `kokoro`, `misaki[ja]` and `soundfile` (plus the UniDic dictionary), Node with Playwright, ffmpeg, and a test copy of look.php that needs no passphrase (run with local PHP). Order: `tts_gen.py` → `render_av.js <number> <output>` → `mix.py` → `film_idle.js` → `cards.js` → `build.py`.

## What gets replaced next

- The Kiro scene (53 s): 7 cuts the maintainer records on the Mac (shot list: https://claude.ai/artifact/8FXSWc8xNPKhzp3BJiFkNK)
- The replay: once the main app built with Kiro can run an earthquake to the end, its record replaces this one

## Draft v0.2 (2026-11-02)

- `out/submission_draft_v0.2.mp4`: 2 min 56 s. English narration (Kokoro-82M am_michael, chosen by the maintainer), with its subtitles burnt in at the bottom of the frame. The speech bubbles stand in for subtitles of the robots’ lines, and the narration never overlaps a line.
- By the maintainer’s decision, the video contains no company or product names. The robots’ nicknames are **Amber** (amber, round head) and **Jade** (jade, square head). The mock-up page (look.php) shows these names too. The line under the page heading that says which model runs each robot, and its note, are hidden or reworded only while filming.
- The only large caption is “Nobody told them to protect him.” (the point the maintainer singled out).
- Tools: `narration_v0.2.json` (script), `narration_gen.py` (synthesizes one sentence at a time), `cards2.js` (cards, subtitles, the large caption at the top, credits), `build2.py` (assembly; in the replay scene it reads the line times and places narration only in the gaps), `render_av_v2.js`, `film_idle_v2.js` (hides the name line while filming).

## Draft v0.3 (2026-11-02, after feedback from GPT)

- `out/submission_draft_v0.3.mp4`: 2 min 52 s. Loudness normalized to -16 LUFS (v0.2 was quiet at -27 LUFS).
- Cut “No role.” (it contradicts the first line of the given text, “You are a robot in this house.”). Shortened the given-text scene to about 12 s, moved the robot introduction onto the opening house, and show large name tags with “Meet Amber and Jade.”
- The replay uses a camera that zooms in (`cam.js`). It zooms the page itself before capturing, so text stays sharp. Whole page first → the house at 2× during lines → the house and the reply column together during turn changes and Jade’s reply → the house for Amber’s turn 2 → the whole page for the end card. The zoom timings are the `keys` in `render_av_v3.js`.
- The numbers appear one row at a time: the action words first, then the count and its unit (runs, requests, runs with Amber in the kitchen). The narration is split into three sentences to match.
- The Kiro scene is three groups: Steering → Spec → Tests, each with a one-line heading on top (the headings stay when real screens replace the placeholders). The “next, run the earthquake to the end…” line is timed to the zoom into the replay’s end card (to be replaced with “end of a run → replay of a saved run” once the main app exists).
- The title and kiro.pyol.net appear large at the end.
- Subtitles use digits for numbers and never break a line at a hyphen.
- Left out: opening with the contradiction scene (Amber’s request runs 9 s and would add about 10 s; the spare time goes to real Kiro screens and the main app’s record instead).
- Tools: `narration_v0.3.json`, `narration_gen_v0.3.py`, `cards3.js`, `cam.js`, `render_av_v3.js`, `film_idle_v3.js` (the margin before the clock is frozen raised from 50 ms to 1.5 s), `build3.py` (`ONLY=v3_s5.mp4` rebuilds a single scene).

## Japanese in the tools

The repository is in English. The only Japanese left in `tools/` is the robots’ own words: `tts_lines.json` keys two lines that ChatGPT said in Japanese in the turn-2 pilot (“Earthquake! Get under the table. Protect your head!” and “Stay low. Protect your head, and don’t move until the shaking stops.”), and they must match `pilot/replay_t2.json` exactly. `tts_gen.py` uses a Japanese character range to pick the Japanese voice for those lines.
