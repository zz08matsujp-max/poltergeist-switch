<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Memory\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Memory\MemoryService;
use PoltergeistSwitch\Memory\Values\MemoryRow;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;

/**
 * Feature: public-lab, Property 8: A recorded episode's snapshot is frozen.
 *
 * For any episode and any later changes to the `memories` table (new memories,
 * retirements, resets by any Device), reading that episode's snapshot (the
 * very read a replay rebuild uses) returns exactly the bodies stored at
 * creation.
 *
 * The expected bodies are captured from the snapshot right after creation and
 * compared with later reads; the later changes are random appends by this
 * Device, another Device and Legacy (enough to trigger retire-on-store), plus
 * resets by both Devices and further episodes created in between.
 *
 * Validates: Requirements 1.3, 2.9
 */
final class FrozenSnapshotPropertyTest extends TestCase
{
    use TestTrait;

    private const DEVICE = 'dev_frozen';
    private const OTHER = 'dev_other';
    private const ROBOTS = ['robot_a', 'robot_b'];
    private const DISASTERS = ['earthquake', 'heavy_rain', 'heat_wave'];

    /**
     * Feature: public-lab, Property 8: A recorded episode's snapshot is frozen
     *
     * Validates: Requirements 1.3, 2.9
     */
    public function testRecordedSnapshotIsUnchangedByLaterMemoryChanges(): void
    {
        $step = Generators::tuple(
            Generators::choose(0, 1),  // robot
            Generators::choose(0, 2),  // disaster
            Generators::choose(0, 2),  // 0 = this Device, 1 = other Device, 2 = Legacy
            Generators::choose(0, 5),  // 0 = reset by the owner Device first; 1 = new episode after
        );

        $this->limitTo(100)
            ->forAll(
                Generators::seq($step), // memories stored before the episode
                Generators::seq($step), // changes made after the episode was recorded
                Generators::choose(0, 40) // extra appends to force retirements
            )
            ->then(function (array $before, array $after, int $extra): void {
                $repo = new InMemoryEpisodeRepository();
                $service = new MemoryService($repo);
                $n = 0;

                $apply = function (array $plan) use ($repo, $service, &$n): void {
                    foreach ($plan as [$robotIdx, $disasterIdx, $owner, $marker]) {
                        if ($marker === 0 && $owner !== 2) {
                            $repo->recordDeviceReset($owner === 0 ? self::DEVICE : self::OTHER, '2026-11-09 12:00:00');
                        }

                        $n++;
                        $deviceId = match ($owner) {
                            0 => self::DEVICE,
                            1 => self::OTHER,
                            default => null,
                        };
                        $generation = $deviceId === null ? 1 : $repo->deviceGeneration($deviceId);

                        $repo->appendMemory(new MemoryRow(
                            robotId: self::ROBOTS[$robotIdx],
                            generation: $generation,
                            disaster: self::DISASTERS[$disasterIdx],
                            sourceEpisodeId: "src_{$n}",
                            body: "m{$n}",
                            status: 'active',
                            createdAt: '2026-11-09 12:00:00',
                            deviceId: $deviceId,
                        ));

                        if ($marker === 1) {
                            $service->snapshotForNewEpisode("ep_later_{$n}", self::DEVICE);
                        }
                    }
                };

                $apply($before);

                $service->snapshotForNewEpisode('ep_recorded', self::DEVICE);

                $frozen = [];
                foreach (self::ROBOTS as $robot) {
                    $frozen[$robot] = $repo->episodeSnapshot('ep_recorded', $robot)->bodies;
                }

                $apply($after);

                // Many more memories for this Device, so the retire-on-store cap
                // retires rows that the snapshot contains.
                for ($i = 0; $i < $extra; $i++) {
                    $n++;
                    $repo->appendMemory(new MemoryRow(
                        robotId: self::ROBOTS[$i % 2],
                        generation: $repo->deviceGeneration(self::DEVICE),
                        disaster: self::DISASTERS[$i % 3],
                        sourceEpisodeId: "src_{$n}",
                        body: "m{$n}",
                        status: 'active',
                        createdAt: '2026-11-09 12:00:00',
                        deviceId: self::DEVICE,
                    ));
                }

                // A reset by each Device, the last word in "later changes".
                $repo->recordDeviceReset(self::DEVICE, '2026-11-09 13:00:00');
                $repo->recordDeviceReset(self::OTHER, '2026-11-09 13:00:00');

                $this->assertTrue($repo->episodeHasSnapshot('ep_recorded'));
                foreach (self::ROBOTS as $robot) {
                    $this->assertSame(
                        $frozen[$robot],
                        $repo->episodeSnapshot('ep_recorded', $robot)->bodies,
                        "{$robot} snapshot of a recorded episode must not change"
                    );
                }
            });
    }
}
