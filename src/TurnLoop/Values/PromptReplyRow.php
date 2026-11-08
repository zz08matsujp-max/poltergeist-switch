<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop\Values;

/**
 * The prompt and reply for one robot on one turn attempt, as passed to
 * EpisodeRepository::savePromptAndReply (Requirements 8.2, 8.3, 8.4).
 *
 * Mirrors the `prompts` table (prompt_text + situation_text_version per robot
 * per turn — Requirements 8.3, 11.6) and the `responses` table (one row per
 * attempt — Requirement 8.4). The reply fields are carried in a ReplyRecord so
 * the non-negative token counts and the unanswered/truncated flags stay on the
 * one value object; an unanswered attempt has empty reply, zero tokens, and
 * zero cost (Requirement 1.8).
 */
readonly class PromptReplyRow
{
    public function __construct(
        public string $episodeId,
        public int $turnNumber,
        public string $robotId,               // 'robot_a' | 'robot_b'
        public int $attempt,                  // 1..2 (Req 5.4)
        public string $situationTextVersion,  // Engine::situationTextVersion() per prompt (Req 8.3, 11.6)
        public ReplyRecord $reply,            // prompt text + reply + usage/flags (Req 8.3, 8.4)
    ) {}
}
