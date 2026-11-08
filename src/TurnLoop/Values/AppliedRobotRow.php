<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop\Values;

/**
 * The stored Four_Layers for one robot on one applied turn, as bundled into
 * AppliedTurnRows for EpisodeRepository::storeAppliedTurn (Requirements 8.2,
 * 8.3, 8.5, 8.6, 13.3).
 *
 * Carries, per robot: the situation text sent and its version (prompts table —
 * Requirement 8.3); the recorder JSON, unknown flag, and recorder prompt
 * version (recordings table — Requirement 8.5); and the judgement JSON, outcome
 * JSON, and the EvidenceCheck-verified action JSON for replay (actions table —
 * Requirements 8.6, 13.3). A robot marked no reply still carries all layers
 * with its action recorded as 'unknown' (Requirement 6.8).
 */
readonly class AppliedRobotRow
{
    public function __construct(
        public string $robotId,               // 'robot_a' | 'robot_b'
        public string $situationText,         // prompts.prompt_text (Req 8.3)
        public string $situationTextVersion,  // prompts.situation_text_version (Req 8.3, 11.6)
        public string $recorderJson,          // recordings.recorder_json, raw (Req 8.5)
        public bool $recorderUnknown,         // recordings.unknown (Req 8.5)
        public string $recorderPromptVersion, // recordings.recorder_prompt_version (Req 8.5)
        public string $judgementJson,         // actions.judgement_json (Req 8.6)
        public string $outcomeJson,           // actions.outcome_json (Req 8.6)
        public string $actionJson,            // actions.action_json, verified Action for replay (Req 13.3)
    ) {}
}
