<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Memory\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\ReflectionTextBuilder;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Memory\MemoryService;
use PoltergeistSwitch\Memory\ReflectService;
use PoltergeistSwitch\Memory\Values\MemoryRow;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\ParallelHttp;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\Values\EpisodeRow;
use PoltergeistSwitch\TurnLoop\Values\ReplyRecord;
use PoltergeistSwitch\TurnLoop\Values\TurnStateValue;

/**
 * Feature: public-lab, Property 5: A reflection that finishes after a reset never becomes visible
 *
 * For any episode created by Device D before D's reset, and a reflection that is
 * stored after that reset, the stored memory does not appear in any
 * Memory_Snapshot created after the reset nor in D's status, and does not change
 * the other Devices' views.
 *
 * Driven through the real {@see ReflectService} against the in-memory
 * EpisodeRepository fake with a {@see FakeRobotClient} (no real AI call, no
 * network): the episode freezes D's generation at creation, D resets one or more
 * times, the episode ends, and only then does reflect() run and store its
 * answers. The memories are stored under the episode's own (older) generation, so
 * the reset hides them.
 *
 * NOTE: this Eris release (giorgiosironi/eris ^1.3) controls the iteration
 * count with `limitTo()`; `limitTo(100)` is used, matching the other property
 * tests.
 *
 * Validates: Requirements 4.8
 */
class LateReflectionPropertyTest extends TestCase
{
    use TestTrait;

    private const DEVICE = 'device_p5_d';
    private const OTHERS = ['device_p5_x', 'device_p5_y'];
    private const ROBOTS = ['robot_a', 'robot_b'];
    private const SNAPSHOT_CAP = 18;

    /**
     * A reflection stored after the Device's reset is invisible to that Device
     * and leaves other Devices unchanged.
     *
     * Validates: Requirements 4.8
     */
    public function testLateReflectionNeverBecomesVisible(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generators::int(),               // episode seed
                Generators::choose(1, 3),        // resets by D before the reflection
                Generators::choose(0, 8),        // earlier memories per other Device
                Generators::tuple(Generators::bool(), Generators::bool()), // robot answers?
                Generators::choose(0, 2),        // resets by other Devices
            )
            ->then(function (int $seed, int $resets, int $otherMemories, array $answers, int $otherResets): void {
                $label = "(seed {$seed}, resets {$resets}, other memories {$otherMemories}, otherResets {$otherResets})";
                $repo = new InMemoryEpisodeRepository();
                $service = new MemoryService($repo);

                // Other Devices have their own history.
                foreach (self::OTHERS as $index => $device) {
                    for ($r = 0; $r < $otherResets; $r++) {
                        $service->reset($device);
                    }
                    $generation = $repo->deviceGeneration($device);
                    for ($i = 0; $i < $otherMemories; $i++) {
                        $repo->appendMemory(new MemoryRow(
                            robotId: self::ROBOTS[($i + $index) % 2],
                            generation: $generation,
                            disaster: 'earthquake',
                            sourceEpisodeId: $device . '_src_' . $i,
                            body: "Other memory {$i} of {$device}.",
                            status: 'active',
                            createdAt: date('Y-m-d H:i:s'),
                            deviceId: $device,
                        ));
                    }
                }

                // D's episode is created (generation frozen), then D resets.
                $episodeId = $this->buildEndedEpisode($repo, $service, $seed);
                for ($r = 0; $r < $resets; $r++) {
                    $service->reset(self::DEVICE);
                }
                $generationAfterReset = $repo->deviceGeneration(self::DEVICE);
                $this->assertGreaterThan($repo->episodeGeneration($episodeId), $generationAfterReset, $label);

                $othersBefore = [];
                foreach (self::OTHERS as $device) {
                    $othersBefore[$device] = $this->view($repo, $service, $device);
                }

                // The late reflection finishes now and is stored.
                $clients = ['robot_a' => new FakeRobotClient(), 'robot_b' => new FakeRobotClient()];
                $expectedAnswers = [$answers[0], $answers[1]];
                foreach (self::ROBOTS as $index => $robotId) {
                    $prompt = $this->promptFor($repo, $episodeId, $robotId);
                    $clients[$robotId]->setReplyForPrompt(
                        $prompt,
                        $expectedAnswers[$index]
                            ? new ReplyRecord($prompt, "Late memory of {$robotId}.", 10, 5, 0.1, 0.0, false, false)
                            : ReplyRecord::unanswered($prompt, 0.0, 'timeout'),
                    );
                }
                $reflect = new ReflectService(
                    $clients['robot_a'],
                    $clients['robot_b'],
                    new ReflectionTextBuilder(new SituationTextGenerator()),
                    new ReplayEngineFactory($repo, new EpisodeConfig()),
                    $repo,
                    new Config(),
                    new ParallelHttp(),
                );
                $result = $reflect->reflect($episodeId);

                // The answers really were stored (the property is not vacuous).
                foreach (self::ROBOTS as $index => $robotId) {
                    $this->assertSame(
                        $expectedAnswers[$index],
                        $result->robots[$robotId]->answered,
                        "{$robotId} answered as configured {$label}"
                    );
                    $this->assertSame(
                        $expectedAnswers[$index],
                        $repo->reflectionAnswer($episodeId, $robotId) !== null,
                        "{$robotId} answer stored {$label}"
                    );
                }

                // D's status is empty and D's generation did not move.
                $status = $service->status(self::DEVICE);
                $this->assertSame(0, $status->amberActiveCount, $label);
                $this->assertSame(0, $status->jadeActiveCount, $label);
                $this->assertSame([], $status->amberLengths, $label);
                $this->assertSame([], $status->jadeLengths, $label);
                $this->assertSame($generationAfterReset, $repo->deviceGeneration(self::DEVICE), $label);

                // Any snapshot created after the reset is empty.
                $service->snapshotForNewEpisode('ep_p5_after', self::DEVICE);
                foreach (self::ROBOTS as $robotId) {
                    $this->assertTrue($repo->episodeSnapshot('ep_p5_after', $robotId)->isEmpty(), "{$robotId} {$label}");
                    $this->assertSame(
                        [],
                        $repo->activeMemories(self::DEVICE, $robotId, $generationAfterReset, self::SNAPSHOT_CAP)->bodies,
                        "{$robotId} {$label}"
                    );
                }

                // Other Devices see exactly what they saw before.
                foreach ($othersBefore as $device => $before) {
                    $this->assertEquals($before, $this->view($repo, $service, $device), "{$device} unchanged {$label}");
                }
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function view(InMemoryEpisodeRepository $repo, MemoryService $service, string $device): array
    {
        $generation = $repo->deviceGeneration($device);

        return [
            'status' => $service->status($device),
            'robot_a' => $repo->activeMemories($device, 'robot_a', $generation, self::SNAPSHOT_CAP)->bodies,
            'robot_b' => $repo->activeMemories($device, 'robot_b', $generation, self::SNAPSHOT_CAP)->bodies,
        ];
    }

    /**
     * One ended, reflectable episode of Device D with a frozen snapshot at D's
     * current generation (taken at creation, before any reset).
     */
    private function buildEndedEpisode(InMemoryEpisodeRepository $repo, MemoryService $service, int $seed): string
    {
        $now = date('Y-m-d H:i:s');
        $episodeId = $repo->createEpisode(new EpisodeRow(
            id: 'ep_p5',
            deviceId: self::DEVICE,
            disaster: 'earthquake',
            seed: $seed,
            currentExpectedTurn: 1,
            earthquakeStartTurn: 0,
            ended: false,
            createdAt: $now,
            lastActivityAt: $now,
        ));
        $service->snapshotForNewEpisode($episodeId, self::DEVICE);

        $repo->createTurn($episodeId, 0);
        $repo->setTurnState($episodeId, 0, TurnStateValue::applied->value);
        $repo->markEpisodeEnded($episodeId);

        return $episodeId;
    }

    private function promptFor(InMemoryEpisodeRepository $repo, string $episodeId, string $robotId): string
    {
        $built = (new ReplayEngineFactory($repo, new EpisodeConfig()))->forEpisodeWithWorld($episodeId);

        $records = [];
        foreach ($built['engine']->turnRecords() as $record) {
            if ($record->turnNumber >= 1) {
                $records[] = $record;
            }
        }

        return (new ReflectionTextBuilder(new SituationTextGenerator()))->build(
            $robotId,
            $records,
            0,
            $built['world'],
            $repo->episodeSnapshot($episodeId, $robotId),
        );
    }
}
