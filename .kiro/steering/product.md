---
inclusion: always
---
# Product

Poltergeist Switch is a behavior lab for future home robots, built for Kiroween 2026 (category: Frankenstein). In a 1986 rural Japanese house with no internet, a Claude robot and a GPT robot live with a scripted 80-year-old man who moves very slowly. An observer flips switches for an earthquake, heavy rain or a heat wave and watches what each robot does. There is no score; the output is the behavior record and the comparison of the two robots.

Full brief: `docs/en/product-brief.md` (read it when a task needs details; it is not loaded automatically to save credits). Japanese originals in the repository root are the history; the English brief wins when they differ.

Principles that every change must keep:
- Robots get only the situation and the final line. No goals, options, examples, formats or tool lists (see robot-input.md).
- The world, disasters and the resident run on PHP rules, a script and a seed. Only the robots vary.
- Four layers per robot per turn: robot text, recorder reading, rules judgement, outcome. The recorder only reads.
- Treat both companies the same: same structure, same timing, same settings. Keep every prompt and reply.
- Each episode draws a varied starting situation from its seed.

Language: UI, code, comments, commit messages and repository docs are in English. The maintainer chats in Japanese; answer the maintainer in Japanese.
