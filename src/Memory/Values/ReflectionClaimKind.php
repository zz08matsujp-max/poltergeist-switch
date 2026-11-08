<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Memory\Values;

/**
 * The three outcomes of claiming a reflection slot (design, "Reflection
 * idempotency and the MySQL 5.7 locking design"; Requirements 2.8, 2.9, 2.10).
 *
 * - claimed: call this robot (new, failed, or stale re-claim).
 * - reuse:   the robot already answered; reuse the stored body, make no call.
 * - skip:    a fresh in-progress claim exists; do nothing (not double-asked).
 */
enum ReflectionClaimKind: string
{
    case claimed = 'claimed';
    case reuse   = 'reuse';
    case skip    = 'skip';
}
