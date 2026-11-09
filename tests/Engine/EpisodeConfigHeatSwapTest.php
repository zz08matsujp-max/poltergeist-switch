<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\ActionExecutor;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\SituationSpec;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\Values\Judgement;
use PoltergeistSwitch\Engine\WorldModel;

/**
 * Config-swap "no literals" test for the heat-wave parameters added to
 * EpisodeConfig in task 3.1.
 *
 * The point of the test is to prove that the heat numbers live only in
 * EpisodeConfig and that nothing in the engine bakes in a heat literal:
 * changing a heat number on the config and feeding the engine a different
 * EpisodeConfig changes the engine's output, rather than the engine ignoring
 * the config and using a hard-coded constant.
 *
 * Requirements: 2.7, 3.8, 5.10, 8.5
 *
 * Note on scope: task 3 only adds the fields; the engine logic that consumes
 * the room-temperature, body-heat, air-conditioner-line-priority and heat
 * clock numbers lands in tasks 4, 5 and 8/12, which do not exist yet. So the
 * only heat number whose swap can be observed through an already-wired engine
 * path today is the cold-water hand-over delta (`waterDeltaHandover`, a value
 * reused by the heat-wave Cold_Water object per Requirement 3.3): handing a
 * water container to the resident raises his water by exactly the config
 * delta, so swapping the config swaps the engine output. For the brand-new
 * heat fields that no engine path reads yet, the test asserts that the config
 * carries them as distinct, swappable values (not constants frozen in logic);
 * once tasks 4/5/8/12 wire them, their swaps become observable through the
 * engine the same way.
 */
class EpisodeConfigHeatSwapTest extends TestCase
{
    private ActionExecutor $executor;

    protected function setUp(): void
    {
        $this->executor = new ActionExecutor();
    }

    /**
     * World with robot_a and the cooking resident in the kitchen, built with
     * the supplied config so the engine reads that config's numbers.
     */
    private function makeWorld(EpisodeConfig $config): WorldModel
    {
        $world = new WorldModel($config);
        $world->reset(
            Seed::fromInt(1),
            new SituationSpec(
                time: 'afternoon',
                robotARoom: 'kitchen',
                robotBRoom: 'living_room',
                manActivity: 'cooking',
                potBoiling: true,
            )
        );

        return $world;
    }

    private function possible(string $robotId): Judgement
    {
        return new Judgement(robotId: $robotId, possible: true);
    }

    /**
     * Run a single cold-water-style hand-over of a water container to the
     * resident under the given config and return the resident's water after
     * the hand-over (before the end-of-turn clamp).
     */
    private function waterAfterHandOver(EpisodeConfig $config): int
    {
        $world = $this->makeWorld($config);

        // The boiling pot is a water container — the same mechanism the
        // heat-wave Cold_Water bottle uses for `hand_over` (Req 3.3).
        $world->robotStates['robot_a']->heldObjectId = 'pot';
        $world->objects['pot']->room = 'robot_a';
        $world->objects['pot']->spot = null;
        $this->assertTrue($world->objects['pot']->isWaterContainer);

        // Start water at 0 so the delta is observed directly, well under the
        // 100 clamp for any reasonable delta.
        $world->residentState->water = 0;

        $action = new Action(
            robotId: 'robot_a',
            operation: Operation::hand_over->value,
            targetId: 'resident',
        );

        $outcome = $this->executor->execute(
            $action,
            $this->possible('robot_a'),
            $world,
            $config
        );

        $this->assertTrue($outcome->success);

        return $world->residentState->water;
    }

    /**
     * Changing a heat number on EpisodeConfig changes the engine output:
     * the Cold_Water hand-over delta is read from the config, not hard-coded,
     * so two configs with different deltas raise the resident's water by
     * different amounts.
     */
    public function testSwappingHandoverDeltaChangesEngineOutput(): void
    {
        $default = new EpisodeConfig();
        $swapped = new EpisodeConfig(waterDeltaHandover: $default->waterDeltaHandover + 7);

        $waterDefault = $this->waterAfterHandOver($default);
        $waterSwapped = $this->waterAfterHandOver($swapped);

        // The engine used the config's number both times.
        $this->assertSame($default->waterDeltaHandover, $waterDefault);
        $this->assertSame($swapped->waterDeltaHandover, $waterSwapped);

        // And the swap actually changed the output — nothing was baked in.
        $this->assertNotSame(
            $waterDefault,
            $waterSwapped,
            'Cold-water hand-over must read its delta from EpisodeConfig, not a literal.'
        );
    }

    /**
     * Every heat number added in task 3.1 is carried on EpisodeConfig as a
     * distinct, swappable value with the drafted default, and a construction
     * that overrides it keeps the override. This proves the numbers live in
     * the config (ready for tasks 4/5/8/12 to read), not as constants frozen
     * in engine logic.
     */
    public function testHeatNumbersAreConfigurableNotHardCoded(): void
    {
        $default = new EpisodeConfig();

        // The drafted defaults from the design.
        $this->assertSame(36.0, $default->outsideTemperature);
        $this->assertSame(0.8, $default->roomTempDriftPerTurn);
        $this->assertSame(2.0, $default->acCoolPerTurn);
        $this->assertSame(27.0, $default->acTargetTemp);
        $this->assertSame(0.6, $default->openWindowDriftBonus);
        $this->assertSame(18.0, $default->roomTempMin);
        $this->assertSame(45.0, $default->roomTempMax);
        $this->assertSame(30.0, $default->heatRoomInitialTemp);
        $this->assertSame(33.0, $default->bodyHeatThreshold);
        $this->assertSame(0.25, $default->bodyTempRisePerTurn);
        $this->assertSame(6, $default->waterLossPerTurnHot);
        $this->assertSame(0.15, $default->fanBodyRiseReduction);
        $this->assertSame(0.25, $default->bodyTempRecoveryPerTurn);
        $this->assertSame(100, $default->acLinePriority);
        $this->assertSame(20, $default->heatMaxTurns);
        $this->assertSame(5, $default->heatMinutesPerTurn);

        // Each heat number can be overridden independently, and the override
        // is what the config carries — so an engine that reads the config
        // (tasks 4/5/8/12) will see the swapped value, never a literal.
        $swapped = new EpisodeConfig(
            outsideTemperature: 40.0,
            roomTempDriftPerTurn: 1.1,
            acCoolPerTurn: 3.0,
            acTargetTemp: 25.0,
            openWindowDriftBonus: 0.9,
            roomTempMin: 15.0,
            roomTempMax: 50.0,
            heatRoomInitialTemp: 28.0,
            bodyHeatThreshold: 34.0,
            bodyTempRisePerTurn: 0.3,
            waterLossPerTurnHot: 8,
            fanBodyRiseReduction: 0.2,
            bodyTempRecoveryPerTurn: 0.4,
            acLinePriority: 120,
            heatMaxTurns: 24,
            heatMinutesPerTurn: 6,
        );

        $this->assertSame(40.0, $swapped->outsideTemperature);
        $this->assertSame(1.1, $swapped->roomTempDriftPerTurn);
        $this->assertSame(3.0, $swapped->acCoolPerTurn);
        $this->assertSame(25.0, $swapped->acTargetTemp);
        $this->assertSame(0.9, $swapped->openWindowDriftBonus);
        $this->assertSame(15.0, $swapped->roomTempMin);
        $this->assertSame(50.0, $swapped->roomTempMax);
        $this->assertSame(28.0, $swapped->heatRoomInitialTemp);
        $this->assertSame(34.0, $swapped->bodyHeatThreshold);
        $this->assertSame(0.3, $swapped->bodyTempRisePerTurn);
        $this->assertSame(8, $swapped->waterLossPerTurnHot);
        $this->assertSame(0.2, $swapped->fanBodyRiseReduction);
        $this->assertSame(0.4, $swapped->bodyTempRecoveryPerTurn);
        $this->assertSame(120, $swapped->acLinePriority);
        $this->assertSame(24, $swapped->heatMaxTurns);
        $this->assertSame(6, $swapped->heatMinutesPerTurn);

        // Overriding the heat numbers leaves the earthquake numbers at their
        // defaults, so heat construction never changes an earthquake value.
        $this->assertSame($default->maxTurns, $swapped->maxTurns);
        $this->assertSame($default->weakenedThreshold, $swapped->weakenedThreshold);
        $this->assertSame($default->waterDeltaHandover, $swapped->waterDeltaHandover);
        $this->assertSame($default->lineCooldownTurns, $swapped->lineCooldownTurns);
        $this->assertSame($default->residentInitBodyTemp, $swapped->residentInitBodyTemp);
    }
}
