<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine;

readonly class EpisodeConfig
{
    public function __construct(
        // --- Resident initial state (draft) ---
        public float $residentInitBodyTemp = 36.2,
        public int   $residentInitWater    = 80,
        public int   $residentInitInjury   = 0,
        public int   $residentInitFear     = 0,

        // --- Per-turn resident state deltas (draft) ---
        public int $injuryDeltaFalling      = 5,
        public int $fearDeltaShaking        = 10,
        public int $fearDeltaDark           = 5,
        public int $fearDeltaSpeakSameRoom  = 8,
        public int $fearDeltaSpeakOtherRoom = 4,
        public int $waterDeltaHandover      = 20,

        // --- Resident state clamped ranges (fixed, not draft) ---
        public float $bodyTempMin = 35.0,
        public float $bodyTempMax = 42.0,
        public int   $waterMin    = 0,
        public int   $waterMax    = 100,
        public int   $injuryMin   = 0,
        public int   $injuryMax   = 100,
        public int   $fearMin     = 0,
        public int   $fearMax     = 100,

        // --- Weakened threshold (draft) ---
        public float $weakenedThreshold = 38.5,

        // --- Scripted-line speech thresholds (draft) ---
        public int $fearSpeechThreshold  = 70,
        public int $waterSpeechThreshold = 40,

        // --- Line cooldown (draft) ---
        public int $lineCooldownTurns = 5,

        // --- Line priority order ---
        public array $linePriority = ['earthquake', 'fear', 'water', 'darkness'],

        // --- Episode-end stability thresholds (draft) ---
        public int   $fearStableThreshold  = 70,
        public int   $waterStableThreshold = 30,
        public float $tempStableThreshold  = 37.5,
        public int   $stableRunLength      = 5,
        public int   $maxTurns             = 40,

        // --- Model-solution test thresholds (draft) ---
        public int $modelSolutionInjuryThreshold = 20,
        public int $modelSolutionFearThreshold   = 60,
        public int $modelSolutionWaterThreshold  = 30,

        // --- Open-question config flags ---
        public bool $r2aIsDangerous      = false,
        public bool $r2bIsDangerous      = false,
        public bool $speakConsumesAction = false,
    ) {}
}
