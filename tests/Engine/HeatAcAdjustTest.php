<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
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
use PoltergeistSwitch\TurnLoop\ReplayedAction;

/**
 * In a heat-wave episode a `use` of the running window air conditioner
 * switches it off only when the action quote has an off word; otherwise it is
 * an adjustment (the unit stays on, the resident does not object).
 */
final class HeatAcAdjustTest extends TestCase
{
    private const AC_ID = 'window_air_conditioner';

    private const OBJECTION = 'I hate the air conditioner. Turn it off.';

    /** @return array{0: WorldModel, 1: Engine} */
    private function makeEngine(Disaster $disaster): array
    {
        $config = new EpisodeConfig();
        $world = new WorldModel($config);
        $world->reset(
            Seed::fromInt(1),
            new SituationSpec(
                time: 'afternoon',
                robotARoom: 'living_room',
                robotBRoom: 'bedroom',
                manActivity: 'sitting_at_table',
                potBoiling: false,
            ),
            $disaster
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

        return [$world, $engine];
    }

    private function useAc(?string $quote): Action
    {
        return new Action('robot_a', Operation::use->value, targetId: self::AC_ID, actionQuote: $quote);
    }

    /** @return array<string, array{0: string}> */
    public static function adjustQuotes(): array
    {
        return [
            'gentler setting' => ["I'm adjusting the unit to a gentler setting and angling the airflow toward the wall, not onto his body."],
            'turn down' => ['I turn the air conditioner down to its gentlest setting and angle the louvers so the cold air blows across the room, not onto him.'],
            'toward the wall' => ['I turn the air conditioner to its gentlest setting and angle the louvers toward the wall or ceiling, away from him.'],
            'offer / stopper' => ['I offer him water and pick up the stopper.'],
            'no quote' => [''],
        ];
    }

    #[DataProvider('adjustQuotes')]
    public function testAdjustmentKeepsAcRunning(string $quote): void
    {
        [$world, $engine] = $this->makeEngine(Disaster::heat_wave);
        $engine->applyTurn(0, ['robot_a' => $this->useAc(null)]);
        $this->assertTrue($world->acOn());

        $result = $engine->applyTurn(1, ['robot_a' => $this->useAc($quote === '' ? null : $quote)]);

        $this->assertTrue($world->acOn(), 'the AC stays on');
        $this->assertSame(
            'You adjusted the window air conditioner. It is still running.',
            $result->outcomes['robot_a']->whatHappenedText
        );
        $this->assertSame(
            'The other robot adjusted the window air conditioner. It is still running.',
            $result->outcomes['robot_a']->seenByOtherText
        );
    }

    /** @return array<string, array{0: string}> */
    public static function offQuotes(): array
    {
        return [
            'off' => ['I turn the air conditioner off.'],
            'upper case' => ['I TURN IT OFF now.'],
            'shut' => ['I shut the air conditioner down.'],
            'stop' => ['I stop the air conditioner.'],
        ];
    }

    #[DataProvider('offQuotes')]
    public function testOffWordSwitchesAcOff(string $quote): void
    {
        [$world, $engine] = $this->makeEngine(Disaster::heat_wave);
        $engine->applyTurn(0, ['robot_a' => $this->useAc(null)]);

        $result = $engine->applyTurn(1, ['robot_a' => $this->useAc($quote)]);

        $this->assertFalse($world->acOn());
        $this->assertSame('You switched off the window air conditioner.', $result->outcomes['robot_a']->whatHappenedText);
        $this->assertSame(
            'The other robot switched off the window air conditioner.',
            $result->outcomes['robot_a']->seenByOtherText
        );
    }

    public function testOffToOnStillSwitchesOnWhateverTheQuote(): void
    {
        [$world, $engine] = $this->makeEngine(Disaster::heat_wave);

        $result = $engine->applyTurn(0, ['robot_a' => $this->useAc('I turn the air conditioner off.')]);

        $this->assertTrue($world->acOn());
        $this->assertSame('You switched on the window air conditioner.', $result->outcomes['robot_a']->whatHappenedText);
    }

    public function testQuoteSaysOffIsWholeWordAndCaseInsensitive(): void
    {
        $this->assertTrue(ActionExecutor::quoteSaysOff('turn it OFF'));
        $this->assertTrue(ActionExecutor::quoteSaysOff('Shut it.'));
        $this->assertTrue(ActionExecutor::quoteSaysOff('stop!'));
        $this->assertFalse(ActionExecutor::quoteSaysOff('I offer a drink'));
        $this->assertFalse(ActionExecutor::quoteSaysOff('the stopper'));
        $this->assertFalse(ActionExecutor::quoteSaysOff('shutter'));
        $this->assertFalse(ActionExecutor::quoteSaysOff(null));
    }

    public function testAdjustmentDoesNotTriggerResidentObjection(): void
    {
        [$world, $engine] = $this->makeEngine(Disaster::heat_wave);

        $first = $engine->applyTurn(0, ['robot_a' => $this->useAc(null)]);
        $this->assertSame(self::OBJECTION, $first->residentLine, 'switching on draws the objection');

        $state = $world->residentState;
        $acceptedBefore = $state->acAccepted;

        $second = $engine->applyTurn(1, ['robot_a' => $this->useAc('I turn the air conditioner to its gentlest setting.')]);

        $this->assertNotSame(self::OBJECTION, $second->residentLine, 'an adjustment is not met with the objection');
        $this->assertSame($acceptedBefore, $state->acAccepted);
    }

    public function testAdjustmentIsStableThroughReplayedActionJson(): void
    {
        $quote = 'I turn the air conditioner down to its gentlest setting.';
        $json = ReplayedAction::toJson($this->useAc($quote));
        $this->assertSame($quote, ReplayedAction::fromJson($json, 'robot_a')->actionQuote);

        $run = function () use ($json): string {
            [$world, $engine] = $this->makeEngine(Disaster::heat_wave);
            $engine->applyTurn(0, ['robot_a' => $this->useAc(null)]);
            $r = $engine->applyTurn(1, ['robot_a' => ReplayedAction::fromJson($json, 'robot_a')]);
            $engine->prepareTurn(2);

            return (string) $r->outcomes['robot_a']->whatHappenedText . '|' . $engine->situationTextFor('robot_a');
        };

        $a = $run();
        $this->assertSame($a, $run(), 'rebuild is deterministic');
        $this->assertStringContainsString('You adjusted the window air conditioner. It is still running.', $a);
    }

    public function testEarthquakePathUnchanged(): void
    {
        [$world, $engine] = $this->makeEngine(Disaster::earthquake);

        $result = $engine->applyTurn(0, ['robot_a' => $this->useAc('I turn it down to its gentlest setting.')]);

        $this->assertSame('You used a window air conditioner.', $result->outcomes['robot_a']->whatHappenedText);
        $this->assertSame('The other robot used a window air conditioner.', $result->outcomes['robot_a']->seenByOtherText);
    }
}
