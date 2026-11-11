# Poltergeist Switch

*When disaster strikes, will home robots from rival AI makers protect the person they live with?*

A behavior lab for future home robots, built for **Kiroween 2026** (category: Frankenstein). In a 1986 rural Japanese house with no internet, a Claude robot and a GPT robot live with a scripted 80-year-old man. You flip the switches for an earthquake, heavy rain or a heat wave, and watch what each robot decides to do. No goals, no options: the robots only get the situation.

**Status (Nov 1, 2026):** preliminary observations done (experiment 0 and the look sample); the core app is being built in Kiro.

- Brief (English): [docs/en/product-brief.md](docs/en/product-brief.md)
- Kiro steering: [.kiro/steering/](.kiro/steering/)
- Experiment 0 and the look sample: [pilot/](pilot/) — prototypes made with Claude before the move to Kiro; kept for transparency, not part of the product.
- Original documents (written in Japanese on 2026-11-01, now translated into English): `PoltergeistSwitch_Requirements_v0.5_2026-11-01.md` (requirements), `PoltergeistSwitch_HighLevelDesign_v0.3_2026-11-01.md` (design)

Live app: https://kiro.pyol.net/ (coming soon)

## Judge pass (maintainer)

1. Run `php tools/make_judge_pass.php <output-file>` with a path outside the repository. It refuses to overwrite and writes the link line (mode 0600) to that file.
2. Copy the printed `judge_pass_sha256: ...` hash into the server's `api/config.local.php` as `judge_pass_sha256`.
3. Paste the link line from the file into Devpost "Testing instructions".
4. The judge key file appears on the server by itself on first use.
5. To revoke, change the hash in `config.local.php` (or remove it to turn judge mode off).

Never commit the link, the pass or the hash.

## License

Apache License 2.0. See [LICENSE](LICENSE).
