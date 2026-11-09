<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine\Enums;

/**
 * The disaster an episode runs. An earthquake episode behaves, reads, and
 * replays exactly as it does today; a heat-wave episode runs the heat rules
 * that this spec adds, all gated on this value (heat-wave Requirement 1.6).
 *
 * String-backed so it round-trips through the stored world and the API.
 */
enum Disaster: string
{
    case earthquake = 'earthquake';
    case heat_wave  = 'heat_wave';
}
