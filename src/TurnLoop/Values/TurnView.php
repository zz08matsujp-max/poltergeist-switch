<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop\Values;

/**
 * One turn as carried in an EpisodeView (Requirement 9.4; design "Response
 * shape (EpisodeView)").
 *
 * An applied turn carries the per-robot Four_Layers for robot_a and robot_b
 * (Requirement 10.2). The $robots map is keyed by robot id ('robot_a',
 * 'robot_b') with RobotFourLayers values.
 */
readonly class TurnView
{
    /**
     * @param array<string, RobotFourLayers> $robots keyed by robot id ('robot_a', 'robot_b')
     */
    public function __construct(
        public int $turnNumber,
        public TurnStateValue $state,
        public array $robots,
        public ?string $residentLine = null,
    ) {}
}
