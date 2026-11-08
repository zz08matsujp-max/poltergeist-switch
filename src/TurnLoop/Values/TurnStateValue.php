<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop\Values;

/**
 * The lifecycle state of one turn (Requirement 7).
 *
 * A turn's state only ever advances forward in the order
 * waiting -> replies_complete -> applied and never returns to an earlier state
 * (Requirements 7.1, 7.2).
 */
enum TurnStateValue: string
{
    case waiting          = 'waiting';
    case replies_complete = 'replies_complete';
    case applied          = 'applied';

    /**
     * The forward rank of this state, used to enforce forward-only ordering
     * (Requirement 7.2). waiting = 0, replies_complete = 1, applied = 2.
     */
    public function rank(): int
    {
        return match ($this) {
            self::waiting          => 0,
            self::replies_complete => 1,
            self::applied          => 2,
        };
    }

    /**
     * True when advancing from this state to $next is allowed, i.e. $next is
     * the same state or a later one in the forward order (Requirement 7.2).
     * A move to an earlier state is never allowed.
     */
    public function canAdvanceTo(self $next): bool
    {
        return $next->rank() >= $this->rank();
    }
}
