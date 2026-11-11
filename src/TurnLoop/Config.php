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
        // An episode that never had an accepted ask (no turn row >= 1) frees
        // its slot after this many seconds without activity (public-lab Req 19).
        public int $unaskedReleaseSeconds = 60,

        // --- Public lab: daily start limits (Req 13.3) ---
        public int $dailyDeviceLimit = 10,
        public int $dailyIpLimit = 30,
        public int $dailySiteLimit = 100,

        // --- Public lab: Featured replay ids, in display order (Req 6.1) ---
        /** @var list<string> */
        public array $featuredEpisodeIds = [
            'b9931223-fc11-4162-8a4d-a8cf55886e1e',
            '450e9249-94db-4744-987d-ec6774bf5057',
            '4113e82c-2f09-49e4-b647-a1481d794807',
            '2a3800d5-4029-448c-8152-fd622693a775',
            '3ef560a5-c35c-4632-869f-d01fb2dd2e2b',
            'bae88cfc-7eea-4cb6-a742-a49c0ccc401c',
            '36e82c3e-03bc-4396-bfdc-e3fc33ffe671',
            '186823c0-3ff4-4289-ad30-ca98ef97708e',
        ],

        // --- Judge pass (Requirement 21) ---
        public string $judgeUntilUtc = '2027-01-29T21:00:00Z',
        public int $judgeDailyLimit = 100,
    ) {
        $this->assertInvariants();
    }

    /** Judging_End as Unix seconds. */
    public function judgeUntilUnix(): int
    {
        return $this->parseJudgeUntil($this->judgeUntilUtc)
            ?? throw new \InvalidArgumentException('judgeUntilUtc must be Y-m-dTH:i:sZ in UTC');
    }

    private function parseJudgeUntil(string $value): ?int
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        if ($dt === false || $dt->format('Y-m-d\TH:i:s\Z') !== $value) {
            return null;
        }

        return $dt->getTimestamp();
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
        foreach ([$this->dailyDeviceLimit, $this->dailyIpLimit, $this->dailySiteLimit] as $limit) {
            if ($limit < 1) {
                throw new \InvalidArgumentException('daily limits must be at least 1');
            }
        }
        if ($this->parseJudgeUntil($this->judgeUntilUtc) === null) {
            throw new \InvalidArgumentException('judgeUntilUtc must be Y-m-dTH:i:sZ in UTC');
        }
        if ($this->judgeDailyLimit < 1) {
            throw new \InvalidArgumentException('judgeDailyLimit must be at least 1');
        }
        foreach ($this->featuredEpisodeIds as $id) {
            if (!is_string($id) || preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                $id
            ) !== 1) {
                throw new \InvalidArgumentException('featuredEpisodeIds must be UUIDs');
            }
        }
    }
}
