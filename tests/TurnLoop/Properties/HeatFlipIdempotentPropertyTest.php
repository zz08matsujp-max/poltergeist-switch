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

/**
 * Property-based test for the heat-wave spec (Eris).
 *
 * Feature: heat-wave, Property 11: Fixing the heat wave is idempotent.
 *
 * Raising the Heat wave lever on a live episode is idempotent: for any seed and
 * any number of repeated flips, every flip after the first leaves
 * `heat_wave_start_turn` unchanged, never creates a second episode, and never
 * returns a 409 — it always succeeds and returns the same episode's view with
 * the same heat-wave start turn. This holds whether the episode was created
 * directly as a heat-wave episode or converted in place from a waiting default
 * (turn-0, not-yet-asked) episode.
 *
 * The property drives a real {@see TurnService} over the in-memory repository
 * and the real {@see ReplayEngineFactory}; fake clients open no network
 * connection, so no real provider API is touched.
 *
 * This Eris release sets the iteration count with `limitTo()`; `limitTo(100)`
 * makes the "at least 100 cases" explicit.
 *
 * Validates: Requirements 8.2
 */
class HeatFlipIdempotentPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property 11: fixing the heat wave is idempotent.
     *
     * Validates: Requirements 8.2
     */
    public function testFixingTheHeatWaveIsIdempotent(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generators::int(),            // episode seed
                Generators::choose(2, 8),     // how many times to flip
                Generators::bool(),           // start as heat wave vs convert a default episode
            )
            ->then(function (int $seedValue, int $flips, bool $createAsHeatWave): void {
                $repo = new InMemoryEpisodeRepository();
                $service = new TurnService(
                    new FakeRobotClient(),
                    new FakeRobotClient(),
                    new FakeRecorderClient(),
                    new ReplayEngineFactory($repo),
                    $repo,
                    new Config(),
                );

                // Create the episode either directly as a heat-wave episode or
                // as a waiting default episode that the first flip converts.
                $disaster = $createAsHeatWave ? 'heat_wave' : 'earthquake';
                $episodeId = $service
                    ->createEpisode('dev_prop', seed: $seedValue, disaster: $disaster)
                    ->episodeId;

                // The number of episodes before any flip, so we can prove no
                // flip ever creates a second one.
                $episodeCountBefore = $this->runningAndEndedCount($repo);

                // Flip the Heat wave lever $flips times. Every flip must succeed
                // (no exception, no 409) and return the SAME episode view.
                $startTurns = [];
                for ($i = 0; $i < $flips; $i++) {
                    $view = $service->flipDisaster($episodeId, 'heat_wave');

                    $this->assertSame(
                        $episodeId,
                        $view->episodeId,
                        "flip {$i} must return the same episode (seed {$seedValue})"
                    );
                    $this->assertSame(
                        'heat_wave',
                        $view->disaster,
                        "the episode must be a heat wave after flip {$i} (seed {$seedValue})"
                    );

                    $episode = $repo->findEpisode($episodeId);
                    $this->assertNotNull($episode);
                    $startTurns[] = $episode->heatWaveStartTurn;
                }

                // The heat-wave start turn is recorded (the heat is fixed) and
                // is the SAME value across every flip: idempotent.
                $this->assertNotNull(
                    $startTurns[0],
                    "the heat must be fixed after the first flip (seed {$seedValue})"
                );
                foreach ($startTurns as $index => $startTurn) {
                    $this->assertSame(
                        $startTurns[0],
                        $startTurn,
                        "the heat-wave start turn must not change on flip {$index} "
                            . "(seed {$seedValue})"
                    );
                }

                // No flip ever created a second episode.
                $this->assertSame(
                    $episodeCountBefore,
                    $this->runningAndEndedCount($repo),
                    "no flip may create a second episode (seed {$seedValue})"
                );
            });
    }

    /**
     * The number of episodes the repository holds, however they are counted,
     * so a test can prove a flip never created a second one. listEndedEpisodes
     * only returns ended ones, so this counts via the device-slot and the
     * ended list together; a fresh episode here is running, so we count it
     * through findRunningEpisodeForDevice plus the ended list, which is enough
     * for this single-device property.
     */
    private function runningAndEndedCount(InMemoryEpisodeRepository $repo): int
    {
        $running = $repo->findRunningEpisodeForDevice('dev_prop') !== null ? 1 : 0;
        $ended = count($repo->listEndedEpisodes(100));

        return $running + $ended;
    }
}
