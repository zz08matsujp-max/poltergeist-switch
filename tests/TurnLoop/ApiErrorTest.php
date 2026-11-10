<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\TurnLoop\Http\ApiError;

final class ApiErrorTest extends TestCase
{
    public function testTooManyRequestsIs429WithCodeOnly(): void
    {
        $e = ApiError::tooManyRequests('daily_device_limit');

        $this->assertSame(429, $e->status);
        $this->assertSame(['error' => 'daily_device_limit'], $e->toArray());
    }

    public function testConflictEmitsEpisodeIdOnlyWhenSet(): void
    {
        $this->assertSame(['error' => 'device_already_running', 'episodeId' => 'ep_1'],
            ApiError::conflict('device_already_running', null, null, 'ep_1')->toArray());
        $this->assertSame(['error' => 'too_many_running'], ApiError::conflict('too_many_running')->toArray());
        $this->assertSame(
            ['error' => 'turn_mismatch', 'currentExpectedTurn' => 3, 'turnState' => 'waiting'],
            ApiError::conflict('turn_mismatch', 3, 'waiting')->toArray(),
        );
    }
}
