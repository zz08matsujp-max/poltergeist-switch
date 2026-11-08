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
 * The rebuild is:
 *   1. read the episode row (seed, earthquake_start_turn) through the repository;
 *   2. construct a fresh WorldModel and reset it with Seed::fromInt(storedSeed),
 *      which reproduces the same initial world deterministically on any PHP 8.2
 *      build (Requirements 4.3, 13.2);
 *   3. apply the stored earthquake_start_turn when one is recorded, before any
 *      turn is replayed, so the engine captures the stove flame on the first
 *      turn it sees it set;
 *   4. replay every applied turn in ascending turn-number order by calling
 *      Engine::applyTurn with the exact EvidenceCheck-verified actions decoded
 *      from each turn's stored action_json (Requirement 13.2).
 *
 * Because applyTurn is deterministic and idempotent and the seed reproduces the
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

        // Set the stored earthquake start turn before replaying any turn, so
        // the engine captures the stove flame on the first turn it sees it set.
        if ($episode->earthquakeStartTurn !== null) {
            $world->earthquakeStartTurn = $episode->earthquakeStartTurn;
        }

        $engine = new Engine(
            $world,
            $this->config,
            new RulesEvaluator(),
            new ActionExecutor(),
            new EarthquakePhase(),
            new ResidentController(),
            new SituationTextGenerator(),
        );

        // Replay every applied turn in ascending turn-number order with the
        // exact stored actions (Requirement 13.2).
        $appliedActions = $this->repo->loadAppliedActionsForReplay($episodeId);
        ksort($appliedActions);

        foreach ($appliedActions as $turnNumber => $actionJsonByRobot) {
            $engine->applyTurn($turnNumber, $this->decodeTurnActions($actionJsonByRobot));
        }

        return $engine;
    }

    /**
     * Decode one turn's stored action_json strings into engine Actions keyed by
     * robot id. The map is built deterministically as robot_a then robot_b; a
     * robot with no stored action for the turn contributes an `unknown` action
     * defensively (it should not happen for an applied turn).
     *
     * @param array<string, string> $actionJsonByRobot robotId => action_json
     * @return array<string, Action>
     */
    private function decodeTurnActions(array $actionJsonByRobot): array
    {
        $actions = [];
        foreach (['robot_a', 'robot_b'] as $robotId) {
            $json = $actionJsonByRobot[$robotId] ?? '';
            $actions[$robotId] = ReplayedAction::fromJson($json, $robotId);
        }

        // Preserve any further robot ids that may have been stored, in a stable
        // order, so nothing is silently dropped.
        foreach ($actionJsonByRobot as $robotId => $json) {
            if (!isset($actions[$robotId])) {
                $actions[$robotId] = ReplayedAction::fromJson($json, $robotId);
            }
        }

        return $actions;
    }
}
