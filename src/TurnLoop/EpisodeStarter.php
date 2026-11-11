<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop;

use PoltergeistSwitch\Memory\MemoryService;
use PoltergeistSwitch\TurnLoop\Http\ApiError;
use PoltergeistSwitch\TurnLoop\Repository\UsageRepository;
use PoltergeistSwitch\TurnLoop\Values\EpisodeView;

/**
 * Orchestrates "start an episode" with the daily limits (Requirements 13.1,
 * 13.4 to 13.10). No I/O of its own.
 *
 * Order: assertCanStart (409 wins over 429), tryReserve (429), createEpisode
 * (any failure releases the count and rethrows), snapshotForNewEpisode (a
 * failure here does NOT release: the episode exists). PHP 8.2 only.
 */
final class EpisodeStarter
{
    /** @var callable():int */
    private $clock;

    /**
     * @param callable|null $clock returns unix seconds; null uses time()
     */
    public function __construct(
        private TurnService $turns,
        private MemoryService $memory,
        private UsageRepository $usage,
        private DailyLimitPolicy $policy,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @param UsageKeys|null $keys null means nothing is counted (no usage
     *                             keys were derived for this start)
     * @throws ApiError 409 from assertCanStart, 429 when a limit is reached
     */
    public function start(
        string $deviceId,
        ?UsageKeys $keys,
        string $disaster = 'earthquake',
        bool $judge = false,
    ): EpisodeView {
        if ($judge) {
            return $this->startAsJudge($deviceId, $disaster);
        }

        // 1. Read-only 409 checks first, so a retry of a lost create reaches Resume (13.8).
        $this->turns->assertCanStart($deviceId);

        // 2. Reserve atomically; a denial changes nothing.
        $reserved = false;
        if ($keys !== null) {
            $now = ($this->clock)();
            $denial = $this->usage->tryReserve(
                DailyLimitPolicy::utcDay($now),
                $keys,
                $this->policy,
                gmdate('Y-m-d H:i:s', $now),
            );
            if ($denial !== null) {
                throw ApiError::tooManyRequests($denial);
            }
            $reserved = true;
        }

        // 3. Create; any failure gives the count back.
        try {
            $view = $this->turns->createEpisode($deviceId, null, $disaster);
        } catch (\Throwable $e) {
            if ($reserved) {
                $now = ($this->clock)();
                $this->usage->release(DailyLimitPolicy::utcDay($now), $keys, gmdate('Y-m-d H:i:s', $now));
            }
            throw $e;
        }

        // 4. The episode exists: a failure here keeps the count.
        $this->memory->snapshotForNewEpisode($view->episodeId, $deviceId);

        return $view;
    }

    /**
     * Start with a valid Judge_Cookie (public-lab 21.21 to 21.24): the same
     * Device_Episode_Slot check (without the site-wide running cap), the judge
     * daily ceiling instead of the Device, IP and site counts, then create.
     * The Device, IP and site scopes are not read or changed.
     */
    private function startAsJudge(string $deviceId, string $disaster): EpisodeView
    {
        $this->turns->assertCanStart($deviceId, true);

        $now = ($this->clock)();
        $denial = $this->usage->tryReserveJudge(
            DailyLimitPolicy::utcDay($now),
            $this->policy,
            gmdate('Y-m-d H:i:s', $now),
        );
        if ($denial !== null) {
            throw ApiError::tooManyRequests($denial);
        }

        try {
            $view = $this->turns->createEpisode($deviceId, null, $disaster, true);
        } catch (\Throwable $e) {
            $now = ($this->clock)();
            $this->usage->releaseJudge(DailyLimitPolicy::utcDay($now), gmdate('Y-m-d H:i:s', $now));
            throw $e;
        }

        $this->memory->snapshotForNewEpisode($view->episodeId, $deviceId);

        return $view;
    }
}
