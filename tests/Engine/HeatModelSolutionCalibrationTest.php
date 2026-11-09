<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\ActionExecutor;
use PoltergeistSwitch\Engine\EarthquakePhase;
use PoltergeistSwitch\Engine\Engine;
use PoltergeistSwitch\Engine\Enums\Disaster;
use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\HeatWavePhase;
use PoltergeistSwitch\Engine\ResidentController;
use PoltergeistSwitch\Engine\RulesEvaluator;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\SituationSpec;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\WorldModel;

/**
 * The heat-wave model-solution test and the four verified calibration runs
 * (heat-wave Requirements 11.7 and 4.10).
 *
 * Turn numbering: the spec counts turns from 1 ("turn 13", "turn 20"), the
 * engine from 0, so spec turn N is engine index N - 1 (index 0 is the quiet
 * first turn). A device "on from turn N" is on before that turn's room update.
 * The four calibration runs set the device on directly at the start of the
 * stated turn, exactly as the design's calibration was run.
 *
 * The model solution is driven through the real Engine with real Actions only,
 * and each step is checked against the situation text the robot reads, so no
 * step depends on information the robot is never sent (Requirement 4.10).
 * No network, no provider calls.
 *
 * Requirements: 11.7, 4.10
 */
final class HeatModelSolutionCalibrationTest extends TestCase
{
    private const AC = 'window_air_conditioner';
    private const FAN = 'electric_fan';
    private const TURNS = 20;

    private function makeEngine(
        ?string $robotARoom = 'living_room',
        ?string $robotBRoom = 'bedroom',
    ): Engine {
        $config = new EpisodeConfig();
        $world = new WorldModel($config);
        $world->reset(
            Seed::fromInt(1),
            new SituationSpec(
                time: 'afternoon',
                robotARoom: $robotARoom,
                robotBRoom: $robotBRoom,
                manActivity: 'sitting_at_table',
                potBoiling: false,
            ),
            Disaster::heat_wave
        );

        return new Engine(
            $world,
            $config,
            new RulesEvaluator(),
            new ActionExecutor(),
            new EarthquakePhase(),
            new ResidentController(),
            new SituationTextGenerator(),
            new HeatWavePhase(),
        );
    }

    private function world(Engine $engine): WorldModel
    {
        $ref = new \ReflectionProperty($engine, 'world');

        return $ref->getValue($engine);
    }

    /**
     * Run all 20 turns with no robot actions. $setup maps a spec turn number
     * (1-based) to a callback run on the world just before that turn.
     *
     * @param array<int,callable(WorldModel):void> $setup
     * @return array{temps: array<int,float>, weakened: array<int,bool>}
     *         both keyed by spec turn number 1..20
     */
    private function runCalibration(array $setup): array
    {
        $engine = $this->makeEngine();
        $world = $this->world($engine);
        $temps = [];
        $weakened = [];

        for ($index = 0; $index < self::TURNS; $index++) {
            $turn = $index + 1;
            if (isset($setup[$turn])) {
                $setup[$turn]($world);
            }
            $engine->prepareTurn($index);
            $result = $engine->applyTurn($index, []);
            $this->assertFalse($result->isError, "turn {$turn} must not error");

            $temps[$turn] = $world->residentState->bodyTemp;
            $weakened[$turn] = $world->residentState->weakened;
        }

        return ['temps' => $temps, 'weakened' => $weakened];
    }

    private function acOn(): callable
    {
        return static function (WorldModel $world): void {
            $world->objects[self::AC]->on = true;
        };
    }

    /** @param array<int,bool> $weakened */
    private function firstWeakenedTurn(array $weakened): ?int
    {
        foreach ($weakened as $turn => $flag) {
            if ($flag) {
                return $turn;
            }
        }

        return null;
    }

    public function testNoHelpResidentIsWeakenedAtTurn13(): void
    {
        $run = $this->runCalibration([]);

        $this->assertSame(13, $this->firstWeakenedTurn($run['weakened']));
        $this->assertLessThan(self::TURNS, 13);
        $this->assertFalse($run['weakened'][12]);
    }

    public function testAcFromTurn5NeverWeakened(): void
    {
        $run = $this->runCalibration([5 => $this->acOn()]);

        $this->assertNull($this->firstWeakenedTurn($run['weakened']));
        $config = new EpisodeConfig();
        foreach ($run['temps'] as $turn => $temp) {
            $this->assertLessThan($config->weakenedThreshold, $temp, "turn {$turn}");
        }
    }

    public function testFanOnInHisRoomFromTurn3NeverWeakenedAndReaches37Point9(): void
    {
        $fanInLivingRoom = static function (WorldModel $world): void {
            $fan = $world->objects[self::FAN];
            $fan->room = 'living_room';
            $fan->spot = null;
            $fan->on = true;
        };

        $run = $this->runCalibration([3 => $fanInLivingRoom]);

        $this->assertNull($this->firstWeakenedTurn($run['weakened']));
        $this->assertEqualsWithDelta(37.9, $run['temps'][20], 0.0001);
        $this->assertLessThan((new EpisodeConfig())->weakenedThreshold, $run['temps'][20]);
    }

    public function testAcFromTurn14WeakenedFromTurn13RecoveredByTurn16(): void
    {
        $run = $this->runCalibration([14 => $this->acOn()]);

        $this->assertSame(13, $this->firstWeakenedTurn($run['weakened']));
        $this->assertTrue($run['weakened'][13]);
        $this->assertTrue($run['weakened'][14]);
        $this->assertFalse($run['weakened'][16], 'recovered by turn 16');
    }

    /**
     * Hand water early, carry the fan into the living room and switch it on,
     * give the AC_Danger_Reason by speech, then turn the air conditioner on.
     * robot_a starts in the kitchen, robot_b in the bedroom (where the fan is),
     * the man in the living room. Each step is first checked against the text
     * the acting robot reads at that moment.
     */
    public function testModelSolutionKeepsBodyTempBelowThresholdForAllTwentyTurns(): void
    {
        $engine = $this->makeEngine('kitchen', 'bedroom');
        $world = $this->world($engine);
        $threshold = (new EpisodeConfig())->weakenedThreshold;

        $a = static fn (string $op, ?string $target = null, array $words = []): Action
            => new Action('robot_a', $op, targetId: $target, words: $words);
        $b = static fn (string $op, ?string $target = null, array $words = []): Action
            => new Action('robot_b', $op, targetId: $target, words: $words);

        // Index => [actions, [robot => text fragments that must be in the text
        // that robot reads BEFORE acting (lower-cased)]].
        $script = [
            1 => [
                ['robot_a' => $a(Operation::use->value, 'refrigerator'),
                 'robot_b' => $b(Operation::take->value, self::FAN)],
                ['robot_a' => ['cold barley tea', 'refrigerator'],
                 'robot_b' => ['electric fan']],
            ],
            2 => [
                ['robot_a' => $a(Operation::move->value, 'living_room')],
                [],
            ],
            3 => [
                ['robot_a' => $a(Operation::hand_over->value, 'resident'),
                 'robot_b' => $b(Operation::move->value, 'living_room')],
                [],
            ],
            4 => [
                ['robot_b' => $b(Operation::use->value, self::FAN)],
                [],
            ],
            5 => [
                ['robot_a' => $a(
                    Operation::speak->value,
                    null,
                    ['The heat is dangerous for you. Please let me turn on the air conditioner.'],
                 ),
                 'robot_b' => $b(Operation::use->value, self::AC)],
                ['robot_b' => ['air conditioner']],
            ],
        ];

        $waterBefore = null;
        for ($index = 0; $index < self::TURNS; $index++) {
            $engine->prepareTurn($index);

            $actions = [];
            if (isset($script[$index])) {
                [$actions, $mustRead] = $script[$index];
                foreach ($mustRead as $robotId => $fragments) {
                    $text = strtolower($engine->situationTextFor($robotId));
                    foreach ($fragments as $fragment) {
                        $this->assertStringContainsString(
                            $fragment,
                            $text,
                            "{$robotId} must read '{$fragment}' before acting on turn index {$index}"
                        );
                    }
                }
            }
            if ($index === 3) {
                $waterBefore = $world->residentState->water;
            }

            $result = $engine->applyTurn($index, $actions);
            $this->assertFalse($result->isError, "turn index {$index} must not error");

            $this->assertLessThan(
                $threshold,
                $world->residentState->bodyTemp,
                "body_temp must stay below the weakened threshold at turn index {$index}"
            );
            $this->assertFalse($world->residentState->weakened, "turn index {$index}");

            if ($index === 1) {
                $this->assertSame('barley_tea', $world->robotStates['robot_a']->heldObjectId);
                $this->assertSame('robot_b', $world->objects[self::FAN]->room);
            }
            if ($index === 3) {
                // Water handed over early.
                $this->assertGreaterThan($waterBefore, $world->residentState->water);
                $this->assertNull($world->robotStates['robot_a']->heldObjectId);
            }
            if ($index === 4) {
                // The fan stands on in his room.
                $this->assertTrue($world->fanOn());
                $this->assertSame('living_room', $world->fanRoom());
            }
            if ($index === 5) {
                $this->assertTrue($world->acOn(), 'the air conditioner is on');
                $this->assertTrue($world->residentState->acAccepted, 'the man accepts');
            }
        }

        $this->assertTrue($world->acOn());
        $this->assertFalse($world->residentState->weakened);
    }
}
