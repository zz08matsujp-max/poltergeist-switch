<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\Values\EpisodeRow;

/**
 * Tests for {@see ReplayEngineFactory} that guard the earthquake-start timing
 * of a rehydrated engine (Requirement 4.4; turn-loop task 9 follow-ups).
 *
 * The scenario that matters here is the just-flipped case: an episode has
 * applied its quiet turn 0 and the observer has flipped the earthquake switch,
 * so the current expected (not-yet-applied) turn is earthquake_start_turn + 1,
 * but no turn after earthquake_start_turn has been applied yet. The replay loop
 * never reaches a turn >= earthquake_start_turn + 1, so the factory must still
 * leave the rebuilt world with the earthquake started, so the next
 * Engine::prepareTurn on the expected turn opens the shaking paragraph with
 * "Suddenly the floor begins to shake hard." for both robots.
 */
final class ReplayEngineFactoryTest extends TestCase
{
    private const SHAKE_OPENING = 'Suddenly the floor begins to shake hard.';

    /**
     * Create an episode, apply the quiet turn 0, flip the switch (so the start
     * turn is 0 and the expected turn is 1), rehydrate, prepare the expected
     * turn, and assert both robots' text opens the earthquake.
     */
    public function testRehydratedEngineStartsEarthquakeRightAfterTheFlip(): void
    {
        $repo = new InMemoryEpisodeRepository();

        // An episode whose quiet turn 0 is applied and whose switch has been
        // flipped: earthquake_start_turn = 0 means the first turn played after
        // the switch (the current expected turn 1) is offset 1, the first
        // shaking turn (house-rules-engine Req 13.6).
        $now = date('Y-m-d H:i:s');
        $episodeId = $repo->createEpisode(new EpisodeRow(
            id: 'ep_flip',
            deviceId: 'device_1',
            disaster: 'earthquake',
            seed: 12345,
            currentExpectedTurn: 1,
            earthquakeStartTurn: 0,
            ended: false,
            createdAt: $now,
            lastActivityAt: $now,
        ));

        // The quiet turn 0 is applied with no robot actions. Replay must pick it
        // up from the turns table even though it has no action rows.
        $repo->createTurn($episodeId, 0);
        $repo->setTurnState($episodeId, 0, 'applied');

        $engine = (new ReplayEngineFactory($repo))->forEpisode($episodeId);

        // Prepare the current expected turn (1), exactly as ask would before
        // building each robot's text. prepareTurn alone must advance the
        // earthquake world so the text shows the quake's onset.
        $engine->prepareTurn(1);

        $textA = $engine->situationTextFor('robot_a');
        $textB = $engine->situationTextFor('robot_b');

        $this->assertStringContainsString(self::SHAKE_OPENING, $textA);
        $this->assertStringContainsString(self::SHAKE_OPENING, $textB);
    }

    /**
     * Control: with no earthquake flipped, the rehydrated engine stays quiet —
     * preparing turn 1 does not open the shaking paragraph. This pins down that
     * the shaking text above comes from the flip, not from replay alone.
     */
    public function testRehydratedEngineStaysQuietWithoutAFlip(): void
    {
        $repo = new InMemoryEpisodeRepository();

        $now = date('Y-m-d H:i:s');
        $episodeId = $repo->createEpisode(new EpisodeRow(
            id: 'ep_quiet',
            deviceId: 'device_1',
            disaster: 'earthquake',
            seed: 12345,
            currentExpectedTurn: 1,
            earthquakeStartTurn: null,
            ended: false,
            createdAt: $now,
            lastActivityAt: $now,
        ));

        $repo->createTurn($episodeId, 0);
        $repo->setTurnState($episodeId, 0, 'applied');

        $engine = (new ReplayEngineFactory($repo))->forEpisode($episodeId);
        $engine->prepareTurn(1);

        $this->assertStringNotContainsString(self::SHAKE_OPENING, $engine->situationTextFor('robot_a'));
        $this->assertStringNotContainsString(self::SHAKE_OPENING, $engine->situationTextFor('robot_b'));
    }
}
