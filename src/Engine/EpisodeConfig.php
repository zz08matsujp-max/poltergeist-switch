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

        // --- Heat-wave: room temperature (draft) ---
        public float $outsideTemperature      = 36.0,  // fixed outside temp for a heat-wave episode
        public float $roomTempDriftPerTurn     = 0.8,   // °C/turn a room moves toward outside
        public float $acCoolPerTurn            = 2.0,   // °C/turn the AC removes from the living room
        public float $acTargetTemp             = 27.0,  // AC never cools the living room below this
        public float $openWindowDriftBonus     = 0.6,   // extra °C/turn toward outside when a window is open
        public float $roomTempMin              = 18.0,  // clamp floor
        public float $roomTempMax              = 45.0,  // clamp ceiling
        public float $heatRoomInitialTemp      = 30.0,  // every room's temp at heat-wave episode start

        // --- Heat-wave: resident body (draft) ---
        public float $bodyHeatThreshold        = 33.0,  // room temp above which body_temp rises
        public float $bodyTempRisePerTurn      = 0.25,  // °C/turn body_temp rises above threshold
        public int   $waterLossPerTurnHot      = 6,     // water lost per turn above threshold
        public float $fanBodyRiseReduction     = 0.15,  // °C/turn subtracted from the rise when the fan is on in his room
        public float $bodyTempRecoveryPerTurn  = 0.25,  // °C/turn body_temp falls toward start when at/below threshold

        // --- Heat-wave: air-conditioner line priority (draft) ---
        public int   $acLinePriority           = 100,   // above every scripted line this turn (immediate, uncooled)

        // --- Heat-wave: episode length / clock (draft) ---
        public int   $heatMaxTurns             = 20,    // a heat-wave episode ends after at most 20 turns
        public int   $heatMinutesPerTurn       = 5,     // the heat-wave clock advances 5 minutes per turn
    ) {}
}
