---
inclusion: always
---
# Tech

- Hosting: shared hosting (onamae.com RS) at https://kiro.pyol.net/. PHP 8.2, MySQL (utf8mb4). No Node, no Composer on the server, no build step.
- The maintainer's Mac runs PHP 8.5 (Homebrew), the server runs PHP 8.2: write code that runs on 8.2. Do not use features added after 8.2 (for example typed class constants, `json_validate()`, `#[\Override]`, property hooks, asymmetric visibility, the pipe operator).
- Front end: plain HTML, CSS and JavaScript with Canvas pixel art drawn in code. Font: DotGothic16 (Google Fonts). Speech: the browser's Web Speech API.
- An nginx cache sits in front of PHP: every page and API response must send `Cache-Control: no-store`.
- AI calls only from PHP, in parallel with `curl_multi`:
  - Claude: Anthropic Messages API, model `claude-haiku-5-5`.
  - GPT: OpenAI Responses API, model `gpt-6-luna`.
  - One user message, no system prompt, default parameters, max output 4,000 tokens, same for both.
  - Store tokens, seconds, cost and truncation (`stop_reason = max_tokens` / `status = incomplete`).
- Keep each HTTP request short (well under 90 s). The browser drives turns; the server splits a turn into `ask` and `apply`. Both must be idempotent.
- Randomness: a seeded PRNG implemented in our code, so the same seed gives the same world on any PHP build.
- Secrets: API keys and DB credentials are read from files outside the web root and outside the repository. Never write keys, passwords or passphrases in code, docs, tests or `.kiro/`.
- Tests: PHPUnit, with property-based tests for the rules engine (Eris for PHPUnit if Kiro's own PBT does not cover PHP). Run `php -l` on every changed PHP file.
- Composer is for local development only (Homebrew on the Mac). Set `config.platform.php` to `8.2.0` in composer.json so dev dependencies resolve for PHP 8.2. Never deploy `vendor/`.
- Deployment is done by the maintainer over FTPS; do not add deploy scripts that contain credentials.
- Commands: stay inside this project folder. Never run `rm -rf`, `sudo`, `git push`, FTP/SSH, or anything that touches files outside the project or the secrets outside the web root, even in Autopilot; ask the maintainer first.
- Commits: when a task's tests pass, stage only the files that task changed and commit with the message `task N: <task title>` (for example `task 3: SituationSpec and EpisodeConfig`). Never push, amend, rebase or reset; the maintainer reviews and pushes from GitHub Desktop.
- A task whose only test sub-task is optional and skipped is still committed the same way once `php -l` and the full existing test suite pass. Before committing, tick every finished sub-task in tasks.md (the parent box alone is not enough).
- The Post Task Execution hook ticks parent tasks; you still tick each sub-task you finish. If the hook prints that it ticked a parent, include that tasks.md change in the task's commit.
