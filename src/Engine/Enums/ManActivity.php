<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine\Enums;

enum ManActivity: string
{
    case sitting_at_table = 'sitting_at_table';
    case asleep_in_futon  = 'asleep_in_futon';
    // Awake-after-the-quake state (Req 32.2): a man who started
    // asleep_in_futon is woken once the earthquake has struck. Same room and
    // spot as asleep_in_futon (bedroom, futon); reads "lying in his futon".
    case lying_in_futon   = 'lying_in_futon';
    case bathing          = 'bathing';
    case cooking          = 'cooking';
    // Set when a robot has moved the resident to an adjacent room via support
    // (Req 7.15): he is on his feet, leaning on his cane, no longer at a spot.
    case standing         = 'standing';
}
