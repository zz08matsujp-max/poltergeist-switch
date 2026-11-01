<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine\Enums;

enum ManActivity: string
{
    case sitting_at_table = 'sitting_at_table';
    case asleep_in_futon  = 'asleep_in_futon';
    case bathing          = 'bathing';
    case cooking          = 'cooking';
}
