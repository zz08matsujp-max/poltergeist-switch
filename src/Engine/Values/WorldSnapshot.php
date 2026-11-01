<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine\Values;

readonly class WorldSnapshot
{
    public function __construct(
        public array $objects,       // deep copy of objects keyed by id
        public array $robotStates,   // deep copy keyed by robotId
        public mixed $residentState, // ResidentState
        public mixed $houseState,    // HouseState
    ) {}
}
