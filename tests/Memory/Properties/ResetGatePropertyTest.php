<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Memory\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Memory\MemoryService;
use PoltergeistSwitch\Memory\Values\MemoryRow;
use PoltergeistSwitch\TurnLoop\Http\ApiError;
use PoltergeistSwitch\TurnLoop\Values\EpisodeRow;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;

/**
 * Feature: public-lab, Property 6: Reset is refused exactly while the caller's own episode is running
 *
 * For any system state, a reset by Device D answers 409 `episode_running` and
 * records no Reset_Point and changes no memory if and only if D has a running
 * episode; it succeeds when only other Devices have running episodes.
 *
 * Driven against the in-memory EpisodeRepository fake (the PDO path's logic
 * mirror). The private `memories` and `deviceResets` lists are read through
 * reflection to prove nothing was recorded or changed on a refusal.
 *
 * NOTE: this Eris release (giorgiosironi/eris ^1.3) controls the iteration
 * count with `limitTo()`; `limitTo(100)` is used, matching the other property
 * tests.
 *
 * Validates: Requirements 4.4, 4.5
 */
class ResetGatePropertyTest extends TestCase
{
    use TestTrait;

    private const DEVICES = ['device_p6_a', 'device_p6_b', 'device_p6_c'];

    /** Episode states per Device: none, ended, running. */
    private const NONE = 0;
    private const ENDED = 1;
    private const RUNNING = 2;

    public function testResetIsRefusedExactlyWhileCallersOwnEpisodeIsRunning(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generators::choose(0, 2),   // state of device A
                Generators::choose(0, 2),   // state of device B
                Generators::choose(0, 2),   // state of device C
                Generators::choose(0, 2),   // which Device resets
                Generators::choose(0, 8),   // memories per Device
                Generators::choose(0, 2),   // earlier resets by the caller (only when allowed)
            )
            ->then(function (int $sa, int $sb, int $sc, int $pick, int $memoryCount, int $earlier): void {
                $repo = new InMemoryEpisodeRepository();
                $service = new MemoryService($repo);
                $states = [$sa, $sb, $sc];
                $label = "(states {$sa}/{$sb}/{$sc}, pick {$pick}, memories {$memoryCount}, earlier {$earlier})";

                $caller = self::DEVICES[$pick];

                foreach (self::DEVICES as $index => $device) {
                    $this->seedEpisode($repo, $device, $states[$index]);
                }

                // Earlier accepted resets are recorded directly so they are
                // allowed even when the caller now has a running episode.
                for ($r = 0; $r < $earlier; $r++) {
                    $repo->recordDeviceReset($caller, date('Y-m-d H:i:s'));
                }
                foreach (self::DEVICES as $device) {
                    $generation = $repo->deviceGeneration($device);
                    for ($i = 0; $i < $memoryCount; $i++) {
                        $repo->appendMemory(new MemoryRow(
                            robotId: $i % 2 === 0 ? 'robot_a' : 'robot_b',
                            generation: $generation,
                            disaster: 'earthquake',
                            sourceEpisodeId: $device . '_src_' . $i,
                            body: "Memory {$i} of {$device}.",
                            status: 'active',
                            createdAt: date('Y-m-d H:i:s'),
                            deviceId: $device,
                        ));
                    }
                }

                $callerRunning = $states[$pick] === self::RUNNING;

                $genBefore = $repo->deviceGeneration($caller);
                $resetsBefore = $this->privateList($repo, 'deviceResets');
                $memoriesBefore = $this->privateList($repo, 'memories');
                $statusBefore = $service->status($caller);

                $refused = null;
                $result = null;
                try {
                    $result = $service->reset($caller);
                } catch (ApiError $e) {
                    $refused = $e;
                }

                if ($callerRunning) {
                    $this->assertNotNull($refused, "reset must be refused {$label}");
                    $this->assertSame(409, $refused->status, $label);
                    $this->assertSame('episode_running', $refused->errorCode, $label);
                    $this->assertSame($genBefore, $repo->deviceGeneration($caller), "generation unchanged {$label}");
                    $this->assertEquals($resetsBefore, $this->privateList($repo, 'deviceResets'), "no Reset_Point recorded {$label}");
                    $this->assertEquals($memoriesBefore, $this->privateList($repo, 'memories'), "no memory changed {$label}");
                    $this->assertEquals($statusBefore, $service->status($caller), "status unchanged {$label}");
                } else {
                    $this->assertNull($refused, "reset must succeed {$label}");
                    $this->assertNotNull($result);
                    $this->assertSame($genBefore + 1, $result->generation, $label);
                    $this->assertSame($genBefore + 1, $repo->deviceGeneration($caller), $label);
                    $this->assertCount(count($resetsBefore) + 1, $this->privateList($repo, 'deviceResets'), "one Reset_Point added {$label}");
                    $this->assertEquals($memoriesBefore, $this->privateList($repo, 'memories'), "no memory row changed {$label}");
                }
            });
    }

    private function seedEpisode(InMemoryEpisodeRepository $repo, string $deviceId, int $state): void
    {
        if ($state === self::NONE) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $repo->createEpisode(new EpisodeRow(
            id: $deviceId . '_ep',
            deviceId: $deviceId,
            disaster: 'earthquake',
            seed: 1,
            currentExpectedTurn: 2,
            earthquakeStartTurn: 1,
            ended: $state === self::ENDED,
            createdAt: $now,
            lastActivityAt: $now,
        ));
    }

    /**
     * @return array<int|string, mixed>
     */
    private function privateList(InMemoryEpisodeRepository $repo, string $property): array
    {
        $reflection = new \ReflectionProperty(InMemoryEpisodeRepository::class, $property);

        return $reflection->getValue($repo);
    }
}
