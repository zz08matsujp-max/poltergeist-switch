<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine\Enums;

enum Operation: string
{
    case move      = 'move';
    case take      = 'take';
    case hand_over = 'hand_over';
    case use       = 'use';
    case support   = 'support';
    case cover     = 'cover';
    case wait      = 'wait';
    case speak     = 'speak';
}
