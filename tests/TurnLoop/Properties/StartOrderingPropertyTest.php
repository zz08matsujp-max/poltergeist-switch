<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Memory\MemoryService;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryUsageRepository;
use PoltergeistSwitch\TurnLoop\Clients\FakeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\DailyLimitPolicy;
use PoltergeistSwitch\TurnLoop\EpisodeStarter;
use PoltergeistSwitch\TurnLoop\Http\ApiError;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\TurnService;
use PoltergeistSwitch\TurnLoop\UsageKeys;

/**
 * Property-based test (Eris), against the in-memory fakes; no real AI call.
 *
 * Feature: public-lab, Property 12: A running episode is reported before any limit
 *
 * For any usage counts (including counts at or above every limit) and a Device
 * that already has a running episode, EpisodeStarter::start fails with 409
 * device_already_running carrying that episode's id, never 429, leaves every
 * usage count unchanged and creates no episode.
 *
 * Validates: Requirements 13.8
 */
class StartOrderingPropertyTest extends TestCase
{
    use TestTrait;

    private const DEVICE = 'dev_order';
    private const NOW = 1_800_000_000;

    /**
     * Feature: public-lab, Property 12: A running episode is reported before any limit
     *
     * Validates: Requirements 13.8
     */
    public function testRunningEpisodeIsReportedBeforeAnyLimit(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generators::choose(1, 12),   // device limit
                Generators::choose(1, 12),   // ip limit
                Generators::choose(1, 12),   // site limit
                Generators::choose(0, 15),   // device count so far
                Generators::choose(0, 15),   // ip count so far
                Generators::choose(0, 15),   // site count so far
                Generators::bool(),          // IP key tracked or not
                Generators::bool(),          // usage keys given or null
                Generators::choose(0, 4),    // running episodes of other Devices
            )
            ->then(function (
                int $deviceLimit,
                int $ipLimit,
                int $siteLimit,
                int $deviceCount,
                int $ipCount,
                int $siteCount,
                bool $withIp,
                bool $withKeys,
                int $others,
            ): void {
                $repo = new InMemoryEpisodeRepository();
                $turns = new TurnService(
                    new FakeRobotClient(),
                    new FakeRobotClient(),
                    new FakeRecorderClient(),
                    new ReplayEngineFactory($repo),
                    $repo,
                    new Config(),
                );
                $usage = new InMemoryUsageRepository(fn (): int => self::NOW);
                $starter = new EpisodeStarter(
                    $turns,
                    new MemoryService($repo),
                    $usage,
                    new DailyLimitPolicy($deviceLimit, $ipLimit, $siteLimit),
                    fn (): int => self::NOW,
                );

                for ($i = 0; $i < $others; $i++) {
                    $turns->createEpisode('dev_other_' . $i, seed: $i + 1);
                }
                $runningId = $turns->createEpisode(self::DEVICE, seed: 42)->episodeId;

                $keys = new UsageKeys(str_repeat('a', 64), $withIp ? str_repeat('b', 64) : null);
                $day = DailyLimitPolicy::utcDay(self::NOW);
                $usage->rows["$day|device|" . $keys->deviceKey] = ['starts' => $deviceCount, 'updatedAt' => 'x'];
                $usage->rows["$day|site|" . $keys->siteKey] = ['starts' => $siteCount, 'updatedAt' => 'x'];
                if ($withIp) {
                    $usage->rows["$day|ip|" . $keys->ipKey] = ['starts' => $ipCount, 'updatedAt' => 'x'];
                }

                $rowsBefore = $usage->rows;
                $runningBefore = $repo->countRunningEpisodes();
                $endedBefore = count($repo->listEndedEpisodes(100));
                $label = "(limits {$deviceLimit}/{$ipLimit}/{$siteLimit}, counts {$deviceCount}/{$ipCount}/{$siteCount}, ip {$withIp}, keys {$withKeys}, others {$others})";

                try {
                    $starter->start(self::DEVICE, $withKeys ? $keys : null);
                    $this->fail('start must fail while the Device has a running episode ' . $label);
                } catch (ApiError $e) {
                    $this->assertSame(409, $e->status, 'never 429 ' . $label);
                    $this->assertSame('device_already_running', $e->errorCode, $label);
                    $this->assertSame($runningId, $e->episodeId, $label);
                }

                $this->assertSame($rowsBefore, $usage->rows, 'usage counts unchanged ' . $label);
                $this->assertSame($runningBefore, $repo->countRunningEpisodes(), 'no episode created ' . $label);
                $this->assertCount($endedBefore, $repo->listEndedEpisodes(100), $label);
            });
    }
}
