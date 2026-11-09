<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine;

use PoltergeistSwitch\Engine\Enums\ManActivity;

/**
 * Mutable state of the scripted elderly resident. `coverFlag` is
 * turn-scoped: it is set when a robot covers the resident this turn and
 * cleared at the end of the turn. `activity` holds a ManActivity value.
 */
class ResidentState
{
    /**
     * `acAlternativePrepared` and `acAccepted` are the two latching booleans of
     * the resident's air-conditioner will (heat-wave Requirement 5). Both start
     * false, flip to true once their condition holds, and never flip back:
     *   - `acAlternativePrepared` becomes true once, on a turn BEFORE the
     *     current turn, the electric fan was on in the resident's room OR the
     *     resident was handed water (Req 5.8);
     *   - `acAccepted` becomes true the turn the resident accepts the air
     *     conditioner, after which he never objects again for the rest of the
     *     episode (Req 5.1).
     * They live on ResidentState so they are carried in WorldModel::snapshot()
     * / restore() and are reproduced exactly when an episode is replayed turn
     * by turn from its seed (the latch re-forms as the same turns re-run). They
     * are left at their false defaults for an earthquake episode, which never
     * touches them.
     *
     * @param array<string,int> $lastLineDeliveredOn line_key => turn number of last delivery
     */
    public function __construct(
        public float $bodyTemp,
        public int $water,
        public int $injury,
        public int $fear,
        public bool $weakened,
        public string $room,
        public ?string $spot = null,
        public string $activity = ManActivity::sitting_at_table->value,
        public bool $coverFlag = false,
        public array $lastLineDeliveredOn = [],
        public bool $acAlternativePrepared = false,
        public bool $acAccepted = false,
    ) {}
}
