<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;
use PoltergeistSwitch\TurnLoop\Clients\FakeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\TurnService;
use PoltergeistSwitch\TurnLoop\Values\EpisodeRow;

/**
 * Property-based test for the running-slot rule (Eris), on the fake repository.
 *
 * Feature: public-lab, Property 26: The running-slot rule matches a simple model
 *
 * **Validates: Requirements 19.8, 19.9, 19.10, 19.11, 19.12, 19.13**
 */
class RunningSlotPropertyTest extends TestCase
{
    use TestTrait;

    private const NOW = 1_800_000_000;
    private const DEVICES = ['dev_a', 'dev_b', 'dev_c'];

    private static function stamp(int $unix): string
    {
        return date('Y-m-d H:i:s', $unix);
    }

    public function testCountAndFindMatchTheModel(): void
    {
        // Feature: public-lab, Property 26: The running-slot rule matches a simple model
        $this
            ->limitTo(100)
            ->forAll(
                Generators::seq(
                    Generators::tuple(
                        Generators::elements(...self::DEVICES),
                        Generators::bool(),                    // ended
                        Generators::bool(),                    // has an accepted ask (turn row >= 1)
                        Generators::choose(0, 400),            // age of last activity in seconds
                    ),
                ),
            )
            ->then(function (array $episodes): void {
                $repo = new InMemoryEpisodeRepository(new Config(), static fn (): int => self::NOW);

                $modelRunning = []; // id => device
                foreach (array_values($episodes) as $i => [$device, $ended, $asked, $age]) {
                    $id = 'ep_' . $i;
                    $repo->createEpisode(new EpisodeRow(
                        id: $id,
                        deviceId: $device,
                        disaster: 'earthquake',
                        seed: $i,
                        currentExpectedTurn: 1,
                        earthquakeStartTurn: null,
                        ended: $ended,
                        createdAt: self::stamp(self::NOW - $age),
                        lastActivityAt: self::stamp(self::NOW - $age),
                        heatWaveStartTurn: null,
                    ));
                    // Turn 0 is the quiet turn every episode has; it never counts as asked.
                    $repo->createTurn($id, 0);
                    if ($asked) {
                        $repo->createTurn($id, 1);
                    }
                    if (!$ended && $age <= 300 && ($asked || $age <= 60)) {
                        $modelRunning[$id] = $device;
                    }
                }

                $this->assertSame(count($modelRunning), $repo->countRunningEpisodes(), json_encode($episodes));

                foreach (self::DEVICES as $device) {
                    $ids = array_keys(array_filter($modelRunning, static fn (string $d): bool => $d === $device));
                    $found = $repo->findRunningEpisodeForDevice($device);
                    if ($ids === []) {
                        $this->assertNull($found, "device $device " . json_encode($episodes));
                    } else {
                        $this->assertNotNull($found, "device $device " . json_encode($episodes));
                        $this->assertContains($found->id, $ids);
                        $this->assertSame($device, $found->deviceId);
                    }
                }
            });
    }

    public function testAcceptedAskOnReleasedEpisodeMakesItRunningAgain(): void
    {
        // Feature: public-lab, Property 26: The running-slot rule matches a simple model
        $this
            ->limitTo(100)
            ->forAll(Generators::choose(61, 10_000))
            ->then(function (int $silence): void {
                $clock = new \ArrayObject(['now' => self::NOW]);
                $config = new Config();
                $repo = new InMemoryEpisodeRepository($config, static fn (): int => $clock['now']);
                $service = new TurnService(
                    new FakeRobotClient(),
                    new FakeRobotClient(),
                    new FakeRecorderClient(),
                    new ReplayEngineFactory($repo),
                    $repo,
                    $config,
                    null,
                    static fn (): string => date('Y-m-d H:i:s', $clock['now']),
                );
                $created = $service->createEpisode('dev_a', seed: 7);
                $service->flipEarthquake($created->episodeId);

                $clock['now'] += $silence;
                $this->assertSame(0, $repo->countRunningEpisodes(), "released after $silence s");
                $this->assertNull($repo->findRunningEpisodeForDevice('dev_a'));

                $service->ask($created->episodeId, 1);

                $this->assertSame(1, $repo->countRunningEpisodes(), "after $silence s");
                $running = $repo->findRunningEpisodeForDevice('dev_a');
                $this->assertNotNull($running);
                $this->assertSame($created->episodeId, $running->id);
            });
    }
}
