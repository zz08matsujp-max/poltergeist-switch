<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine\Enums;

enum PowerState: string
{
    case on     = 'on';
    case outage = 'outage';
}
