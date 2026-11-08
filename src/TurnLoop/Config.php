<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop;

/**
 * Turn-loop configuration values (Requirements 1.11, 1.12, 9.9, 9.10).
 *
 * The per-call timeout and the per-request budget are draft values that follow
 * the EpisodeConfig `(draft)` convention and are not final; the maintainer must
 * verify the per-request budget against the server's PHP `max_execution_time`
 * and the nginx/proxy timeout so it stays below both (Requirement 1.13).
 *
 * The per-call timeout is kept strictly below the per-request budget
 * (Requirement 1.12); see assertInvariants().
 *
 * The cost-per-token rates are draft estimates from product brief §14 (about
 * $0.10 per million input tokens and $0.50 per million output tokens for both
 * models). They are kept here as Config values so the clients compute cost from
 * configuration rather than from literals embedded in their source.
 */
readonly class Config
{
    public function __construct(
        // --- Timing (draft; Req 1.11, 1.12) ---
        public int $perCallTimeoutSeconds = 75,
        public int $perRequestBudgetSeconds = 85,

        // --- Cost per token (draft from brief §14) ---
        // $0.10 per 1,000,000 input tokens, $0.50 per 1,000,000 output tokens.
        public float $inputCostPerToken = 0.10 / 1_000_000,
        public float $outputCostPerToken = 0.50 / 1_000_000,

        // --- Slot limits (Req 9.9, 9.10) ---
        public int $maxRunningEpisodes = 5,
        public int $idleSlotReleaseSeconds = 300,
    ) {
        $this->assertInvariants();
    }

    /**
     * The per-call timeout MUST stay strictly below the per-request budget
     * (Requirement 1.12), so a single provider call can time out and still
     * leave the request time to respond.
     */
    private function assertInvariants(): void
    {
        if ($this->perCallTimeoutSeconds >= $this->perRequestBudgetSeconds) {
            throw new \InvalidArgumentException(
                'perCallTimeoutSeconds must be strictly below perRequestBudgetSeconds'
            );
        }
    }
}
