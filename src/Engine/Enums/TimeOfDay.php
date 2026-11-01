<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine\Enums;

enum TimeOfDay: string
{
    case morning   = 'morning';
    case afternoon = 'afternoon';
    case night     = 'night';
}
