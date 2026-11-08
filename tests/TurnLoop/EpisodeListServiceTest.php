<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\TurnLoop\EpisodeListService;
use PoltergeistSwitch\TurnLoop\Http\ApiError;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\Values\EpisodeRow;
use PoltergeistSwitch\TurnLoop\WorldStateBackfill;

/**
 * Example tests for {@see EpisodeListService} (task 4.1; screens spec
 * Requirements 11.1–11.8, 8.5). They drive the service over the in-memory
 * repository; episodes carry turn 0's world directly, so the one-time backfill
 * is a no-op and no engine replay (and no AI) is exercised here.
 *
 * The exhaustive property tests (ended-only, ordering, limit bounds,
 * out-of-range rejection, and no-leak) are task 4.3; these are a small sanity
 * net for the service's shape and the one-liner wording.
 */
class EpisodeListServiceTest extends TestCase
{
    private function service(InMemoryEpisodeRepository $repo): EpisodeListService
    {
        return new EpisodeListService(
            $repo,
            new WorldStateBackfill($repo, new ReplayEngineFactory($repo)),
        );
    }

    /**
     * Seed one episode: an episodes row plus turn 0 carrying the given world.
     */
    private function seedEpisode(
        InMemoryEpisodeRepository $repo,
        string $id,
        string $createdAt,
        bool $ended,
        ?string $worldJson,
        int $extraTurns = 0,
    ): void {
        $repo->createEpisode(new EpisodeRow(
            id: $id,
            deviceId: 'dev_' . $id,
            disaster: 'earthquake',
            seed: 1,
            currentExpectedTurn: 1,
            earthquakeStartTurn: null,
            ended: $ended,
            createdAt: $createdAt,
            lastActivityAt: $createdAt,
        ));

        $repo->createTurn($id, 0, $worldJson);
        for ($t = 1; $t <= $extraTurns; $t++) {
            $repo->createTurn($id, $t, null);
        }
    }

    private function worldJson(
        string $timeOfDay,
        string $amberRoom,
        string $jadeRoom,
        string $manRoom,
    ): string {
        return json_encode([
            'timeOfDay' => $timeOfDay,
            'robots' => [
                'robot_a' => ['room' => $amberRoom, 'spot' => null, 'held' => null],
                'robot_b' => ['room' => $jadeRoom, 'spot' => null, 'held' => null],
            ],
            'man' => ['room' => $manRoom, 'spot' => null, 'activity' => 'standing'],
        ], JSON_UNESCAPED_UNICODE);
    }

    public function testNoEndedEpisodesReturnsEmptyList(): void
    {
        // Requirement 11.8: no ended episodes → an empty list.
        $repo = new InMemoryEpisodeRepository();
        $this->seedEpisode($repo, 'a', '2026-11-01 10:00:00', ended: false, worldJson: $this->worldJson('night', 'kitchen', 'bedroom', 'living_room'));

        $this->assertSame([], $this->service($repo)->list());
    }

    public function testItemShapeAndOneLinerWording(): void
    {
        // Requirements 11.3, 8.5: four fields per item, turns as a non-negative
        // integer counting only the robot turns taken (applied turns AFTER turn
        // 0, excluding the quiet turn-0 setup, matching the End_Card), and the
        // one-liner naming Amber/Jade and the man with room display names.
        $repo = new InMemoryEpisodeRepository();
        $this->seedEpisode(
            $repo,
            'a',
            '2026-11-01 10:00:00',
            ended: true,
            worldJson: $this->worldJson('night', 'kitchen', 'bedroom', 'living_room'),
            extraTurns: 2, // turn 0 + turns 1 and 2 → two robot turns taken
        );

        $list = $this->service($repo)->list();

        $this->assertCount(1, $list);
        $item = $list[0];
        $this->assertSame(['id', 'createdDate', 'turns', 'startingSituation'], array_keys($item));
        $this->assertSame('a', $item['id']);
        $this->assertSame('2026-11-01 10:00:00', $item['createdDate']);
        // turn 0 + turns 1 and 2 are stored, so the robot turns taken (after
        // turn 0) is 2 — NOT 3 (the old count that wrongly included turn 0).
        $this->assertSame(2, $item['turns']);
        $this->assertSame(
            'Night — Amber in the kitchen, Jade in the bedroom, the man in the living room.',
            $item['startingSituation'],
        );
    }

    public function testTurnsExcludeTurnZeroMatchingTheEndCard(): void
    {
        // Requirement 11.3: the list's turns field counts only applied turns
        // AFTER turn 0 (the robot turns taken), matching the End_Card's "turns
        // taken". An episode with turn 0 plus N robot turns (turns 1..N) must
        // report N, never N + 1 — so the quiet turn-0 setup is excluded. This
        // directly exercises the ±1 that the old count($worldsByTurn) got wrong.
        $repo = new InMemoryEpisodeRepository();
        $w = $this->worldJson('morning', 'kitchen', 'bedroom', 'living_room');

        // Thirteen robot turns taken (turns 1..13) on top of turn 0: the
        // End_Card calls this "13 turns taken", so the list must say 13, not 14.
        $this->seedEpisode($repo, 'a', '2026-11-01 10:00:00', ended: true, worldJson: $w, extraTurns: 13);

        $list = $this->service($repo)->list();

        $this->assertCount(1, $list);
        $this->assertSame(13, $list[0]['turns']);
    }

    public function testTurnsIsZeroWhenOnlyTurnZeroIsStored(): void
    {
        // Requirement 11.3, 7.2: an episode that only ever stored turn 0 (no
        // robot turns taken) reports 0 — the turn-0 setup never counts.
        $repo = new InMemoryEpisodeRepository();
        $this->seedEpisode(
            $repo,
            'a',
            '2026-11-01 10:00:00',
            ended: true,
            worldJson: $this->worldJson('night', 'kitchen', 'bedroom', 'living_room'),
        );

        $list = $this->service($repo)->list();

        $this->assertCount(1, $list);
        $this->assertSame(0, $list[0]['turns']);
    }

    public function testReturnsOnlyEndedNewestFirst(): void
    {
        // Requirements 11.1, 11.2: only ended episodes, created-date desc then
        // id desc.
        $repo = new InMemoryEpisodeRepository();
        $w = $this->worldJson('morning', 'living_room', 'living_room', 'living_room');
        $this->seedEpisode($repo, 'old', '2026-11-01 09:00:00', ended: true, worldJson: $w);
        $this->seedEpisode($repo, 'new', '2026-11-02 09:00:00', ended: true, worldJson: $w);
        $this->seedEpisode($repo, 'running', '2026-11-03 09:00:00', ended: false, worldJson: $w);
        // Same created_at as 'new' → id desc tiebreak ('new' > 'dup').
        $this->seedEpisode($repo, 'dup', '2026-11-02 09:00:00', ended: true, worldJson: $w);

        $ids = array_map(static fn (array $i): string => $i['id'], $this->service($repo)->list());

        $this->assertSame(['new', 'dup', 'old'], $ids);
    }

    public function testLimitCapsResultCount(): void
    {
        // Requirement 11.4: an integer 1–100 caps the count.
        $repo = new InMemoryEpisodeRepository();
        $w = $this->worldJson('afternoon', 'kitchen', 'kitchen', 'kitchen');
        for ($i = 0; $i < 5; $i++) {
            $this->seedEpisode($repo, 'e' . $i, '2026-11-0' . ($i + 1) . ' 09:00:00', ended: true, worldJson: $w);
        }

        $this->assertCount(2, $this->service($repo)->list(2));
        // A string from the query is accepted when it is a plain integer.
        $this->assertCount(3, $this->service($repo)->list('3'));
    }

    public function testAbsentLimitDefaultsToTwenty(): void
    {
        // Requirement 11.5: absent limit → at most 20.
        $repo = new InMemoryEpisodeRepository();
        $w = $this->worldJson('night', 'bedroom', 'bedroom', 'bedroom');
        for ($i = 0; $i < 25; $i++) {
            $this->seedEpisode($repo, sprintf('e%02d', $i), sprintf('2026-11-01 09:%02d:00', $i), ended: true, worldJson: $w);
        }

        $this->assertCount(20, $this->service($repo)->list());
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function outOfRangeLimits(): array
    {
        return [
            'zero'       => [0],
            'negative'   => [-1],
            'too big'    => [101],
            'fractional' => ['20.0'],
            'non-numeric'=> ['abc'],
            'empty'      => [''],
        ];
    }

    /**
     * @dataProvider outOfRangeLimits
     */
    public function testOutOfRangeLimitIsRejectedWithNoList(mixed $limit): void
    {
        // Requirement 11.6: a not-an-integer, < 1, or > 100 limit is rejected
        // with an out-of-range error and no list.
        $repo = new InMemoryEpisodeRepository();
        $this->seedEpisode($repo, 'a', '2026-11-01 10:00:00', ended: true, worldJson: $this->worldJson('night', 'kitchen', 'bedroom', 'living_room'));

        try {
            $this->service($repo)->list($limit);
            $this->fail('expected an out-of-range ApiError');
        } catch (ApiError $e) {
            $this->assertSame(400, $e->status);
            $this->assertSame('limit_out_of_range', $e->errorCode);
        }
    }

    public function testBoundaryLimitsAreAccepted(): void
    {
        // Requirement 11.4: 1 and 100 inclusive are in range.
        $repo = new InMemoryEpisodeRepository();
        $this->seedEpisode($repo, 'a', '2026-11-01 10:00:00', ended: true, worldJson: $this->worldJson('night', 'kitchen', 'bedroom', 'living_room'));

        $this->assertCount(1, $this->service($repo)->list(1));
        $this->assertCount(1, $this->service($repo)->list(100));
    }
}
