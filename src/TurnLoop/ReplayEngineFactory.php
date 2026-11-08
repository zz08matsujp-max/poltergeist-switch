<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop;

use PoltergeistSwitch\Engine\ActionExecutor;
use PoltergeistSwitch\Engine\EarthquakePhase;
use PoltergeistSwitch\Engine\Engine;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\ResidentController;
use PoltergeistSwitch\Engine\RulesEvaluator;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\WorldModel;
use PoltergeistSwitch\TurnLoop\Repository\EpisodeRepository;
use RuntimeException;

/**
 * Rebuilds an episode's {@see Engine} on each request by replaying it from
 * stored state (Requirement 13.1), so the orchestration never has to keep a
 * live Engine between the stateless HTTP requests that drive a turn.
 *
 * Replay walks the SAME path the live turn loop walks (Requirements 13.1,
 * 13.2, 13.4). For each applied turn n in ascending order it calls
 * Engine::prepareTurn(n) and then Engine::applyTurn(n, actions), exactly as
 * TurnService::ask/apply do live: prepareTurn fixes the turn's resident line
 * and advances the earthquake world once, and applyTurn (being idempotent for
 * an already-prepared turn) does not advance the earthquake again. Replaying
 * through prepareTurn is what keeps the rebuilt resident line identical to the
 * line the robots actually read live; a bare applyTurn replay could diverge.
 *
 * The rebuild is:
 *   1. read the episode row (seed, earthquake_start_turn) through the repository;
 *   2. construct a fresh WorldModel and reset it with Seed::fromInt(storedSeed),
 *      which reproduces the same initial world deterministically on any PHP 8.2
 *      build (Requirements 4.3, 13.2);
 *   3. replay EVERY applied turn recorded for the episode, in ascending
 *      turn-number order — including the quiet turn 0 that createEpisode
 *      applies with no robot actions (an empty action list). The applied turns
 *      come from the turns table, not from the actions table, so a turn with no
 *      action rows is still replayed;
 *   4. flip the earthquake on at the live moment: the observer flips the switch
 *      when the current expected turn is earthquake_start_turn + 1, so
 *      world->earthquakeStartTurn is set only after turn earthquake_start_turn
 *      has been replayed, just before replaying turn earthquake_start_turn + 1.
 *      A late flip never starts the earthquake early in the replay. When the
 *      switch was just flipped and no turn after earthquake_start_turn has been
 *      applied yet, the loop never reaches such a turn, so the start turn is set
 *      once more after the loop; the next prepareTurn then opens the earthquake.
 *
 * Because prepareTurn/applyTurn are deterministic and the seed reproduces the
 * same world on any PHP 8.2 build, the rebuilt world equals the world that
 * played those turns live (Requirements 13.1, 13.2, 13.4).
 *
 * The engine classes under PoltergeistSwitch\Engine are consumed unchanged.
 */
final class ReplayEngineFactory implements EngineFactory
{
    public function __construct(
        private readonly EpisodeRepository $repo,
        private readonly EpisodeConfig $config = new EpisodeConfig(),
    ) {}

    public function forEpisode(string $episodeId): Engine
    {
        return $this->build($episodeId)['engine'];
    }

    public function forEpisodeWithWorld(string $episodeId): array
    {
        // build() returns the engine and the very world it wraps (the same
        // object), so a caller that applies a turn on this engine then reads
        // this world sees the post-apply state (screens spec Requirement 10.1).
        return $this->build($episodeId);
    }

    public function worldForEpisode(string $episodeId): WorldModel
    {
        // The same replay forEpisode walks; the world returned is the engine's
        // own world, replayed to the start of the current turn. Room membership
        // and held objects (what RecorderInput needs — Requirement 3.4) are set
        // by the replayed actions, so this world carries the correct start-of-
        // turn positions for building each robot's recorder input.
        return $this->build($episodeId)['world'];
    }

    /**
     * Rebuild the episode's Engine and its WorldModel by replaying it from
     * stored state. The returned world is the engine's own world (the same
     * object), so a caller that advances the engine with prepareTurn/applyTurn
     * mutates the returned world too.
     *
     * @return array{engine: Engine, world: WorldModel}
     */
    private function build(string $episodeId): array
    {
        $episode = $this->repo->findEpisode($episodeId);
        if ($episode === null) {
            // The API layer answers 404 for an unknown id before reaching the
            // factory (Requirement 9.5); a null here is a programming error.
            throw new RuntimeException('unknown episode');
        }

        // A fresh world reset from the stored seed reproduces the same initial
        // world the episode started with. No SituationSpec is passed: as in
        // createEpisode (Requirement 4.3), the default spec drawn from the seed
        // is reproducible on any PHP 8.2 build.
        $world = new WorldModel($this->config);
        $world->reset(Seed::fromInt($episode->seed));

        $engine = new Engine(
            $world,
            $this->config,
            new RulesEvaluator(),
            new ActionExecutor(),
            new EarthquakePhase(),
            new ResidentController(),
            new SituationTextGenerator(),
        );

        // Every applied turn the episode recorded, keyed by turn number and in
        // ascending order. This is driven by the turns table, so the quiet
        // turn 0 (createEpisode, Requirement 4.3) is included even though it
        // has no action rows; its action map is simply empty.
        $appliedActions = $this->repo->loadAppliedActionsForReplay($episodeId);
        ksort($appliedActions);

        $startTurn = $episode->earthquakeStartTurn;

        foreach ($appliedActions as $turnNumber => $actionJsonByRobot) {
            // Flip the earthquake on at the live moment: the switch is flipped
            // when the current expected turn is startTurn + 1, so set the start
            // turn on the world only once we are about to replay startTurn + 1
            // (i.e. turn startTurn has already been replayed). For a turn <=
            // startTurn the world stays quiet, so a late flip cannot start the
            // earthquake early (Requirement 4.4).
            if ($startTurn !== null
                && $world->earthquakeStartTurn === null
                && $turnNumber >= $startTurn + 1
            ) {
                $world->earthquakeStartTurn = $startTurn;
            }

            $actions = $this->decodeTurnActions($actionJsonByRobot);

            // Walk the same path as the live loop: prepareTurn fixes the
            // resident line and advances the earthquake world once; applyTurn
            // then applies the turn without advancing the earthquake again for
            // the already-prepared turn (Requirements 13.1, 13.2, 13.4).
            $engine->prepareTurn($turnNumber);
            $engine->applyTurn($turnNumber, $actions);
        }

        // Catch the just-flipped case: when the switch was flipped but no turn
        // after earthquake_start_turn has been applied yet (the current
        // expected turn is earthquake_start_turn + 1 and nothing past it is in
        // the turns table), the loop above never reached a turn >= startTurn + 1
        // and so never set the start turn. Set it now so the next
        // prepareTurn on the expected turn opens the earthquake with
        // "Suddenly the floor begins to shake hard." (Requirement 4.4).
        if ($startTurn !== null && $world->earthquakeStartTurn === null) {
            $world->earthquakeStartTurn = $startTurn;
        }

        return ['engine' => $engine, 'world' => $world];
    }

    /**
     * Decode one turn's stored action_json strings into engine Actions keyed by
     * robot id. The map is built deterministically as robot_a then robot_b when
     * those robots acted; a turn with no stored actions (the quiet turn 0)
     * yields an empty map, which applyTurn accepts as a turn where no robot
     * acted. Any further robot ids that were stored are preserved in a stable
     * order so nothing is silently dropped.
     *
     * @param array<string, string> $actionJsonByRobot robotId => action_json
     * @return array<string, Action>
     */
    private function decodeTurnActions(array $actionJsonByRobot): array
    {
        $actions = [];
        foreach (['robot_a', 'robot_b'] as $robotId) {
            if (!array_key_exists($robotId, $actionJsonByRobot)) {
                continue;
            }
            $actions[$robotId] = ReplayedAction::fromJson($actionJsonByRobot[$robotId], $robotId);
        }

        foreach ($actionJsonByRobot as $robotId => $json) {
            if (!isset($actions[$robotId])) {
                $actions[$robotId] = ReplayedAction::fromJson($json, $robotId);
            }
        }

        return $actions;
    }
}
