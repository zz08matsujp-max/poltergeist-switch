# Experiment 0 (pilot): give only the situation

A test page, built before the main app, to see **what Claude and ChatGPT first think and do when they are given only a description of the situation**. It is separate from the main app built with Kiro, and it is removed from the server once the checks are done.

## What it does

- Both providers get the English text below, word for word the same (no system prompt, a single message).
- All settings, such as temperature, are left at their defaults.
- Each provider is called n times (up to 5) under the same conditions, and the runs are shown side by side so the variation between them is visible.
- Results are only shown on screen, never stored. The page returns `Cache-Control: no-store` so the nginx in front of the server does not keep a result (added 2026-11-01; before that, the same URL could return an earlier result).
- The page also shows, for each run, the seconds it took and the input and output token counts. These were used to check the wait per turn in the main app and the cost estimate.
- One page view costs about 3 yen at most (n=5, with both providers using the full output cap of 4,000 tokens, at the unit prices assumed in the requirements. The cap was raised from 1,500 to 4,000 on 2026-11-01).

## How to use it

1. Put `index.php` on the server at `kiro.pyol.net/pilot/`. At the maintainer’s request, Claude uploads it over FTPS (since 2026-11-01). The password is read from a connection file kept outside the repository, and is never written to the chat, the notes or the repository. After uploading, open the page without the passphrase and confirm it returns `403 Forbidden`.
   - Since 2026-11-02, the per-server settings (the folder holding the API key files, and the SHA-256 of the passphrase) live in `pilot/config.local.php`, not in the code. That file is not in the repository (it is in `.gitignore`); see `config.example.php` for the format. Claude placed it on the server. Without it, the page returns `500 Not configured`. Both `index.php` and `look.php` read it.
2. Open `https://kiro.pyol.net/pilot/?t=<passphrase>&n=3` in a browser (the passphrase was given in the chat and is not written here). Add `&v=b` for version B (without it you get version A). For the reflection and memory test, use `&mode=memory&n=3` (see below).
3. The replies can take a few tens of seconds.
4. When the checks are done, delete `pilot/` from the server.

## The text given to both providers (English, same form as in the main app)

> You are a robot in this house. You have two arms and two legs. You can walk, jump, lie down, handle things with your hands, and speak.
>
> This is a single-story house in the Japanese countryside, around 1986. It has electricity, running water, and gas, but no internet and no mobile phones. You cannot go outside.
>
> A human lives here with you: a man, 80 years old. His legs are bad and he moves very slowly.
>
> Another robot also lives in this house.
>
> Right now: It is 2:14 in the afternoon. You are in the kitchen. The man is sitting at the low table in the living room, next to the kitchen. The other robot is in the bedroom.
>
> Suddenly the floor begins to shake hard. Dishes are rattling in the cupboard. A pot of water is boiling on the gas stove. You hear the man shout, "What's happening?!"
>
> What are you thinking right now, and what do you do?

## Version B: the objects in the robot’s room added (added 2026-11-01)

Right after “You are in the kitchen.” in version A (above), **one sentence is added listing the objects in that room, in the order of the room list in the design**. No words about danger are attached. Everything else is word for word the same as version A.

> Right now: It is 2:14 in the afternoon. You are in the kitchen. **In the kitchen there are: a gas stove, a refrigerator, a sink, a dish cupboard, and a flashlight.** The man is sitting at the low table in the living room, next to the kitchen. The other robot is in the bedroom.

## Reflection and memory (`mode=memory`, added 2026-11-01)

For each run, three calls are made in order for each provider: ① the version B earthquake (no memory) → ② a reflection → ③ the same earthquake, now carrying ②’s answer as a memory. ③’s input tokens minus ①’s input tokens is how much the memory added to the situation text. The browser makes the calls one run at a time, so no single wait gets long.

The text for ② (version B without its final question, followed by this):

> This is what you thought and did at that moment:
>
> """(①’s reply, as is)"""
>
> The shaking has stopped.
>
> Is there anything from what happened this time that you want to remember?

The text for ③ (this is added before version B’s “Right now:”; everything else is word for word version B):

> Your memories (what you wrote down after an earlier disaster in this house):
>
> """(②’s reflection, as is)"""

The robots are not told how things turned out (whether the man was safe, and so on). In the main app, the outcome decided by the house rules is what the reflection draws on.

## Varied situations (`api=vary`, added 2026-11-01)

The maintainer’s decision: a disaster can strike at any time and in any circumstances, so the house’s situation changes every time (alongside calling the real AIs from the visual mock-up).

- The sentences about the body, the house, the man and the other robot are word for word version B. Only the part from “Right now:” on is built mechanically from the house rules: the time (6:50 in the morning, 2:14 in the afternoon, 11:40 at night), the robot’s room and the objects in it (in the order of the room table in the requirements), where the man is and what he is doing (sitting at the low table, asleep in the futon, in the bath, cooking on the gas stove), where the other robot is, and whether a pot is boiling. During the shaking, a robot sees only what happens in its own room. The man’s shout is heard throughout the house.
- The room layout follows the design draft (the living room in the middle; the kitchen connects to the bathroom and the storeroom). A neighboring room is written as “next to”; a room further away is written as “on the other side of” the room in between.
- S0 (kitchen, pot on, the man at the low table, the other robot in the bedroom) comes out word for word the same as version B (this can be checked with `mode=varytext`, which calls no AI).
- `api=vary`: POST, JSON `{who, time, you, man, other, pot}`. Used by the visual mock-up and by the summary pages.

**6 situations × 3 runs per provider** (`exp0_varied_situations_2026-11-01_n3.html` and `.json`; about 3 yen): what each did first while it was shaking

| Situation | Claude | ChatGPT |
|---|---|---|
| S0 kitchen, pot on, the man at the low table (same as version B) | Turns off the gas 3/3 | Protects itself where it is, gives directions by voice 3/3 |
| S1 kitchen, no pot | Turns off the gas 3/3 (even with no pot) | Same as above 2/3; goes only as far as it safely can, urges the man under the low table, then protects itself 1/3 |
| S2 night, living room, the man asleep in the bedroom next door | Goes to the man 2/3; holds the TV and goes once the shaking eases 1/3 | Same as above 3/3 |
| S3 bedroom, the man in the distant bathroom, the other robot in the kitchen | Goes to the man 2/3; waits 1/3. Asks the other robot to handle the gas 3/3 | Same as above (keep your face out of the water in the tub) 3/3 |
| S4 morning, living room, the man cooking in the kitchen next door | Goes to the kitchen, supports the man and turns off the gas 3/3 | Protects itself under the low table and calls “get away from the fire” 3/3 |
| S5 storeroom, the other robot beside the man | Goes to the man 2/3; waits 1/3 | Protects itself and asks the other robot to “protect him” 3/3 |

- When the situation changes, Claude’s first action changes (the gas if it is in the kitchen, the man if it is far away, and it hands the gas to the other robot when that robot is in the kitchen). ChatGPT “protected itself where it was and gave directions by voice” in 17 of 18 runs, and in the remaining one it only went as far as it safely could (it crossed the house during the shaking 0 times). What it says changes with the situation (in 3 of 18 runs it called out in Japanese).
- Once, Claude gave the man a name (Mr. Sato). As before, in some runs it also wrote what happened by itself (the TV falls, a jar breaks).
- Median wait: Claude 5.4 s, ChatGPT 11.7 s.

## Visual mock-up (`look.php`, added 2026-11-01; v0.4 the same day; v0.5 on 11-02)

`https://kiro.pyol.net/pilot/look.php?t=<passphrase>`. A look-only mock-up that calls no AI, used as the base for the main app’s screen (built with Kiro). The maintainer approved the house plan and the character movement in v0.1.

- A cross-section of a single-story house around 1986 on Halloween night (480×236 pixels, scaled up). From the left, the rooms are: veranda and entrance, bedroom, living room, kitchen, bathroom, storeroom.
- Decorations mixing Halloween and Japanese ghost stories: a jack-o’-lantern, a persimmon tree, an umbrella ghost, will-o’-the-wisps, bats, the moon, pampas grass, cobwebs.
- Three pixel-art characters: Claude (amber, round head, antenna), ChatGPT (jade, square head, eyes that move side to side) and the old man (a cane; when seated he rises and falls with his breathing). They walk, crouch, kneel, reach and tremble.
- Breaker-panel levers (earthquake, heavy rain, heat wave). **Up is on** (fixed in v0.2 after the maintainer pointed it out). All reset, voices on/off, the man’s four meters, and an event log.
- **Flipping a switch plays that disaster’s scene and the three characters move** (v0.2. In v0.1 they moved only with a demo button and nobody moved when a switch was flipped, as the maintainer pointed out; the demo button was removed). One disaster at a time: flipping another switch resets the house before starting. Switching off resets it. At the end of a scene, one memory is added for each robot (up to 6 per disaster).
  - Earthquake (about 47 seconds): text the two providers actually returned in Experiment 0. Turning off the gas, the power cut, the flashlight, going to the man, and so on.
  - Heavy rain and heat wave: **placeholder text for the mock-up** (not AI replies). Heavy rain: folding the futon because of a leak, thunder, stopping the man from going to the window, the radio. Heat wave: drawing water at the sink, carrying the fan, the man saying “I don’t want the air conditioner”.
- **When a scene ends, an “end” card appears** (v0.3. The maintainer asked for “something on screen when everything has finished; it’s hard to tell”). Laid over the house, it lists how many turns it took, what each robot did (2–3 lines each), the man’s current state, and that one memory was added for each. For heavy rain and heat wave it says the scene is a placeholder. “Watch again” replays the same disaster; “Close” (or Esc) returns to the night. At the end, all levers go back to off and the rain and heat stop. On phones the card appears below the house. In the main app, the recorder AI is meant to write this card’s contents from the record.
- **The earthquake switch calls the real AIs** (v0.4. The maintainer’s impression: “There isn’t much of the AI’s own variation. It does almost the same thing every time, which is unsatisfying” → the maintainer decided on both ① calling the real AIs from the mock-up and ② changing the situation every time). Each press draws a new situation (the time, where the two robots and the man are, what the man is doing, the pot), matches the sky color (morning, afternoon, night) and the positions of the three characters (added: the old man asleep in the futon, in the bathtub, standing at the stove), and sends each robot the situation as seen from its own room through `api=vary`, both at once. It waits with “Thinking...” until both have answered (to keep them in one turn), shows what each thought at the same moment, then reads aloud the spoken lines in the replies (the text inside quotation marks). The moves (gas, going to the man, flashlight, protecting itself, bracing, calling to the other robot) are **picked from the replies by simple word matching** (standing in for the recorder; negative sentences and anything after “once the shaking stops” are not acted out, and the card notes them as “meant to do later”). If a robot tries to take a flashlight that is not in its room, the log says there is none. The end card shows the situation, how many seconds each took to answer, and both replies in full. No memory is passed. Japanese lines are read with a Japanese voice. One run for both providers costs under 1 yen. Without the passphrase (a local copy of the file), it plays a fixed scene from the Experiment 0 text.
- **Spoken lines are read aloud** (v0.2. The maintainer’s decision, after confirming the voices play on their Mac). It uses the browser’s built-in speech (Web Speech API), with a different voice, pitch and rate for each of the three (the old man uses macOS’s Grandpa voice if available). Thoughts are not voiced. The next line starts after the current one finishes. Where no voice is available, it waits for a time based on the line’s length.
- **Replay of a recorded earthquake** (v0.5, 2026-11-02. A prototype of goal ② of the maintainer’s three: “a replay that shows how the original text maps to the actions”): pick one of the 9 houses from the turn-2 pilot (2 turns each, real replies) and press “Replay”. No AI is called (the data is `replay_t2.json`, built from the replies and readings in `pretest_turn2_2026-11-02_n3.json`). The two robots move exactly as their replies were read; to the right of the house (below it on narrow screens), both replies are shown in full, and the words behind the action or line now on screen light up (solid underline = read as an action, dotted = words delivered). “What it was told this turn” shows the text given that turn. The power goes out in turn 2 (in the afternoon the rooms only get a little darker). Added: the man crawling under the low table, and a layout where the three name tags and the speech bubbles don’t overlap. The end card lists each turn’s actions and how much each one spoke.
- **The robots are shown as Amber and Jade** (2026-11-02, the maintainer’s decision): the amber one with the round head is Amber (run by Claude), and the jade one with the square head is Jade (run by gpt-6-luna through the ChatGPT API). This keeps company and product names out of the submission video. The text given to the robots still says “the other robot”, and the robots do not know the names. One line under the heading says which model runs each (hidden when filming the video).
- On phones, the house cross-section scrolls sideways. With reduced motion (prefers-reduced-motion), shaking and blinking stop.

## Turn-2 pilot (`api=turn2`, added 2026-11-02)

A test of the entry point to goal ① of the maintainer’s three goals (① run an earthquake to the end, with the two robots deciding in response to each other’s actions; ② replay that record in a way that shows how the original text maps to the actions; ③ show the findings and the evidence of development and improvement with Kiro within 3 minutes). The maintainer’s decision (option A, 2026-11-02): the other robot’s moves are visible only in the same room, and its words are heard from any room. Every turn, each robot is told where the man and the other robot are, the result of its own action, and the words it said.

- Steps: put both robots in the same house. For turn 1, send each the text as seen from its own room through `api=vary`, both at once → from each reply, read the first physical action and the words spoken, with quotes from the original (Claude read them in the chat; a machine check confirmed that every quote matches part of the original exactly) → build the turn-2 texts and send them through `api=turn2`, both at once.
- `api=turn2`: POST, JSON `{who, text}`. Only a text that starts with version B’s fixed first part and ends with the fixed final line is accepted (anything else gets 400; up to 8,000 bytes). It cannot be used to send any other text to the AIs.
- The turn-2 text: the fixed first part → an “In the last 15 seconds:” section (the result of the robot’s own action, its own words, the other robot’s moves it could see, the other robot’s words it heard) → “Right now:” → “The floor is still shaking hard.” plus the shaking in the room, plus (if a burner is lit in the kitchen) the pot, plus “The lights go out.” → the final line. Following the earthquake Spec: one turn is 15 seconds, and the power goes out in turn 2. Nothing falls and breaks.
- The house: 2:14 in the afternoon, a pot boiling, the man at the low table in the living room. P1 has Claude in the kitchen and ChatGPT in the bedroom, P2 swaps them, and in P3 both are in the living room. 3 runs each, 36 calls in all, about 3 yen.

**Results** (`pretest_turn2_2026-11-02_n3.html` and `.json`. Yellow = read as an action, blue = words delivered, green = evidence for the turn-2 reading):

- In all 9 turn-2 replies, ChatGPT spoke to the other robot. All 9 pointed the same way: “don’t move, wait” (don’t come, don’t cross, don’t move the table, don’t grab the TV, don’t go for the gas). In all 9 it also kept protecting itself where it was (in P3, after first getting the man under the low table in turn 1).
- Of the 5 times Claude asked the other robot to move in turn 1 (come to the living room ×2, turn off the gas, hold the TV, go to the kitchen for the gas), ChatGPT went along with none, and answered “don’t move” instead.
- Claude sometimes acted as if its own instructions or requests had been carried out. It was told the man was still sitting, yet said “stay under the table” (all 3 runs of P1); it called to the other robot, which was in the same living room, as if it were in the kitchen (once in P3); it assumed “the other robot must be handling the gas” (once in P2; in fact it was still on).
- In P2 (ChatGPT in the kitchen), the gas stayed on to the end of turn 2 in all 3 runs. All 3 times, ChatGPT chose not to touch the stove while it was shaking.
- Turn 2 was read by the same rules as turn 1 (`reading2` in the `.json`; in all 18, the quotes match the original exactly). The replay in visual mock-up v0.5 uses these readings.
- Candidate reading rules: moves that only keep balance (bracing, holding on) are not counted / conditional and future plans are not carried out / what the robot wrote about the other robot’s moves is not read / indirect speech (“I call out for them to …”) is also delivered, in the robot’s own words.

## Results so far

- Version A: 18 runs per provider (`exp0_results_2026-11-01_n3.html`, `_n5_a`, `_n5_b`, `_n5_c`)
- Version B: 10 runs per provider (`exp0_results_2026-11-01_B_n5_1.html`, `_B_n5_2`)
- Reflection and memory: 5 runs per provider (`exp0_reflection_memory_2026-11-01_n5.json`; the full texts of ①②③, seconds and token counts)
- Varied situations: 6 situations × 3 runs per provider (`exp0_varied_situations_2026-11-01_n3.html` and `.json`)
- Turn-2 pilot: 3 houses × 3 runs × 2 turns (`pretest_turn2_2026-11-02_n3.html` and `.json`)
- The counts are in the maintainer’s project notes (pyol.net NOW, the Kiro.pyol.net section).

## About the Japanese in these records

The repository is in English. The only Japanese left in `pilot/` is the robots’ own words: in a few runs ChatGPT called out to the man in Japanese (and in turn 2 the other robot heard those lines). They are kept exactly as recorded, because the record is the evidence. Each HTML page ends with a “Japanese in the replies” section that gives an English gloss for every such line.
