<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\ActionExecutor;
use PoltergeistSwitch\Engine\EarthquakePhase;
use PoltergeistSwitch\Engine\Engine;
use PoltergeistSwitch\Engine\Enums\Disaster;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\HeatWavePhase;
use PoltergeistSwitch\Engine\ResidentController;
use PoltergeistSwitch\Engine\RulesEvaluator;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\SituationSpec;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Engine\WorldModel;

/**
 * Heat-wave turn-1 golden text test (task 9.3, Requirement 11.9).
 *
 * This pins the EXACT bytes of the heat-wave turn-1 situation text that Robot_A
 * reads, for one fixed seed, against a committed golden file the maintainer
 * approved in full. The golden file was generated from the real engine flow
 * (WorldModel::reset with Disaster::heat_wave, an 8-arg Engine including
 * HeatWavePhase, then applyTurn(0, []) -> prepareTurn(1) ->
 * situationTextFor('robot_a')) and holds exactly those bytes, with sections
 * separated by a blank line ("\n\n") and no trailing newline.
 *
 * The test drives the SAME real engine flow (not a hand-built generate([])
 * call) and asserts the result equals the committed golden file byte-for-byte,
 * opening line included. A self-consistency assertion also pins that the golden
 * file on disk equals the live engine output for this exact seed and spec, so
 * the committed golden can never silently drift from the engine.
 *
 * No network, no real provider calls: the pure Engine is driven directly.
 *
 * Requirements: 11.9
 */
final class HeatSituationTextGoldenTest extends TestCase
{
    /** The committed golden file holding the maintainer-approved bytes. */
    private const GOLDEN_PATH = __DIR__ . '/../fixtures/heat_wave_turn1_golden.txt';

    /** The one fixed seed the golden was approved for. */
    private const SEED = 1;

    /**
     * Build the heat-wave episode with the fixed seed and the approved starting
     * situation, then run the real engine turn flow and return Robot_A's turn-1
     * situation text exactly as a robot reads it.
     */
    private function generateTurn1TextForRobotA(): string
    {
        $config = new EpisodeConfig();

        $world = new WorldModel($config);
        $world->reset(
            Seed::fromInt(self::SEED),
            new SituationSpec(
                time: 'afternoon',
                robotARoom: 'living_room',
                robotBRoom: 'bedroom',
                manActivity: 'sitting_at_table',
                potBoiling: false,
            ),
            Disaster::heat_wave
        );

        $engine = new Engine(
            $world,
            $config,
            new RulesEvaluator(),
            new ActionExecutor(),
            new EarthquakePhase(),
            new ResidentController(),
            new SituationTextGenerator(),
            new HeatWavePhase(),
        );

        // The live turn loop plays a quiet turn 0 (no robot actions), then
        // prepares and reads turn 1 -- the robot's first played turn.
        $engine->applyTurn(0, []);
        $engine->prepareTurn(1);

        return $engine->situationTextFor('robot_a');
    }

    /**
     * The turn-1 situation text equals the committed, maintainer-approved
     * golden file byte-for-byte, opening line included (Requirement 11.9).
     */
    public function testTurn1TextMatchesTheCommittedGolden(): void
    {
        self::assertFileExists(
            self::GOLDEN_PATH,
            'The committed heat-wave turn-1 golden file is missing.'
        );

        $golden = file_get_contents(self::GOLDEN_PATH);
        self::assertIsString($golden);

        $live = $this->generateTurn1TextForRobotA();

        self::assertSame(
            $golden,
            $live,
            'The heat-wave turn-1 situation text differs from the committed, '
            . 'maintainer-approved golden. STOP and report the difference rather '
            . 'than editing the expected golden.'
        );
    }

    /**
     * Self-consistency: the committed golden file holds exactly the bytes the
     * real engine produces for this exact seed and spec, so the golden cannot
     * silently drift from the engine flow it was generated from.
     */
    public function testGoldenFileIsSelfConsistentWithTheEngineOutput(): void
    {
        $golden = file_get_contents(self::GOLDEN_PATH);
        self::assertIsString($golden);

        $live = $this->generateTurn1TextForRobotA();

        // Byte-for-byte, including the absence of a trailing newline.
        self::assertSame($golden, $live);
        self::assertSame(strlen($live), strlen($golden));
    }
}
