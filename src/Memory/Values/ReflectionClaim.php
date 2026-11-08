<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Memory\Values;

use InvalidArgumentException;

/**
 * The result of claiming a reflection slot (design,
 * EpisodeRepository::claimReflectionSlot; Requirements 2.8, 2.9, 2.10).
 *
 * `claimReflectionSlot` returns one of three outcomes telling the service what
 * to do:
 * - claimed (`claimed()`): call this robot — a new, previously failed, or stale
 *   re-claimed slot.
 * - reuse (`reuse($body)`): the robot already answered; reuse the stored body
 *   verbatim and make no call. The reused body is carried in `reusedBody`.
 * - skip (`skip()`): a fresh in-progress claim already exists, so this caller
 *   does nothing (the robot is not double-asked).
 *
 * `reusedBody` is non-null only for a `reuse` outcome.
 */
readonly class ReflectionClaim
{
    private function __construct(
        public ReflectionClaimKind $kind,
        public ?string $reusedBody = null, // the stored answer to reuse; non-null only when kind is reuse
    ) {
        if ($kind === ReflectionClaimKind::reuse && $reusedBody === null) {
            throw new InvalidArgumentException('A reuse claim must carry the reused body.');
        }
        if ($kind !== ReflectionClaimKind::reuse && $reusedBody !== null) {
            throw new InvalidArgumentException('Only a reuse claim may carry a reused body.');
        }
    }

    /** Call this robot: a new, failed, or stale re-claimed slot (Req 2.8, 2.10). */
    public static function claimed(): self
    {
        return new self(ReflectionClaimKind::claimed);
    }

    /** The robot already answered; reuse the stored body verbatim, make no call (Req 2.9). */
    public static function reuse(string $body): self
    {
        return new self(ReflectionClaimKind::reuse, $body);
    }

    /** A fresh in-progress claim exists; do nothing (Req 2.8). */
    public static function skip(): self
    {
        return new self(ReflectionClaimKind::skip);
    }

    public function isClaimed(): bool
    {
        return $this->kind === ReflectionClaimKind::claimed;
    }

    public function isReuse(): bool
    {
        return $this->kind === ReflectionClaimKind::reuse;
    }

    public function isSkip(): bool
    {
        return $this->kind === ReflectionClaimKind::skip;
    }
}
