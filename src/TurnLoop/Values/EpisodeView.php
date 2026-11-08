<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop\Values;

/**
 * The GET response shape returned by the API and read by the debug page
 * (Requirement 9.4; design "Response shape (EpisodeView)").
 *
 * Carries the episode's state and its stored turn records. Turn numbers are
 * zero-based, following the engine's own numbering (Requirements 4.1, 12.3).
 * $earthquakeStartTurn is null until the earthquake switch is flipped
 * (Requirement 4.4).
 */
readonly class EpisodeView
{
    /**
     * @param TurnView[] $turns the stored turn records, in turn order
     */
    public function __construct(
        public string $episodeId,
        public string $disaster,             // 'earthquake' in this spec
        public int $currentExpectedTurn,     // zero-based (Req 4.1, 7.4)
        public ?int $earthquakeStartTurn,    // null until flipped (Req 4.4)
        public bool $ended,                  // Req 7.5
        public array $turns,                 // TurnView[]
    ) {}
}
