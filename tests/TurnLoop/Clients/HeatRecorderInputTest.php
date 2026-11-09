<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop\Clients;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\Enums\Disaster;
use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\SituationSpec;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\WorldModel;
use PoltergeistSwitch\TurnLoop\Clients\ClaudeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\ReadingMapper;
use PoltergeistSwitch\TurnLoop\Clients\RecorderInput;
use PoltergeistSwitch\TurnLoop\Clients\VerifiedReading;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\Values\Reading;

/**
 * Example tests for the heat-wave recorder input (task 13.2):
 *
 *   - 9.1 The recorder maps the heat targets (the window air conditioner, the
 *     electric fan, the window, and the cold water) with the existing
 *     operations and ids/spots. The heat-wave recorder input lists the
 *     `barley_tea` bottle with its own id in the kitchen so the recorder can
 *     name it.
 *   - 9.2 The earthquake recorder prompt and its version are unchanged, and the
 *     earthquake recorder input never lists the cold-water bottle. No recorder
 *     prompt change was needed, so the heat-wave recorder uses the same prompt
 *     version as today; the listing of the bottle id does not touch the
 *     earthquake path.
 *   - 9.3 The recorder reads only, and every action and spoken line it passes
 *     on is backed by an exact substring quote of the reply (EvidenceCheck).
 *
 * No real provider API and no network; the API key is a placeholder.
 *
 * Requirements: 9.1, 9.2, 9.3
 */
class HeatRecorderInputTest extends TestCase
{
    /** A placeholder, never a real key. */
    private const PLACEHOLDER_KEY = 'test-placeholder-key';

    private function config(): Config
    {
        return new Config();
    }

    /**
     * A heat-wave world with the robots placed in explicit rooms, built the
     * same way the heat-object tests build theirs.
     */
    private function makeHeatWorld(string $robotARoom, string $robotBRoom = 'bedroom'): WorldModel
    {
        $world = new WorldModel(new EpisodeConfig());
        $world->reset(
            Seed::fromInt(1),
            new SituationSpec(
                time: 'afternoon',
                robotARoom: $robotARoom,
                robotBRoom: $robotBRoom,
                manActivity: 'sitting_at_table',
                potBoiling: false,
            ),
            Disaster::heat_wave,
        );

        return $world;
    }

    // ---------- 9.1 the recorder maps the heat targets ----------

    public function testHeatRecorderInputListsBarleyTeaIdInTheKitchen(): void
    {
        $world = $this->makeHeatWorld(robotARoom: 'kitchen');

        $input = RecorderInput::forRobot($world, 'robot_a');

        // The cold-water bottle id is listed so the recorder can name it (9.1),
        // appended after the real kitchen objects without disturbing them.
        $this->assertContains('barley_tea', $input->roomObjectIds);
        $this->assertContains('refrigerator', $input->roomObjectIds, 'the real refrigerator is still listed');
        $this->assertSame(
            'barley_tea',
            $input->roomObjectIds[array_key_last($input->roomObjectIds)],
            'the bottle id is appended last, leaving the fixed kitchen order intact',
        );
    }

    public function testHeatRecorderInputDoesNotListBarleyTeaOutsideTheKitchen(): void
    {
        // The bottle lives only in the kitchen; a robot elsewhere never sees it.
        $world = $this->makeHeatWorld(robotARoom: 'living_room');

        $input = RecorderInput::forRobot($world, 'robot_a');

        $this->assertNotContains('barley_tea', $input->roomObjectIds);
        $this->assertContains('window_air_conditioner', $input->roomObjectIds, 'guard: the AC is in the living room');
    }

    public function testHeatRecorderInputListsTheAirConditionerAndFanWithTheExistingVocabulary(): void
    {
        // The AC is a living-room object; the fan is a bedroom object. Each is a
        // normal object id the recorder maps with the existing `use`/`take`.
        $acInput = RecorderInput::forRobot($this->makeHeatWorld('living_room'), 'robot_a');
        $this->assertContains('window_air_conditioner', $acInput->roomObjectIds);

        $fanInput = RecorderInput::forRobot($this->makeHeatWorld('bedroom'), 'robot_a');
        $this->assertContains('electric_fan', $fanInput->roomObjectIds);

        // The window is one of the fixed spot names already, so no new id is
        // needed for it, and the operation vocabulary is the existing one.
        $this->assertContains('window', $acInput->spotNames);
        $this->assertNotContains(Operation::unknown->value, $acInput->operationNames);
        $this->assertContains('use', $acInput->operationNames);
        $this->assertContains('take', $acInput->operationNames);
        $this->assertContains('hand_over', $acInput->operationNames);
    }

    public function testHeldBottleIsReportedAsTheHeldIdOnTheNextTurn(): void
    {
        // Once a robot holds the bottle, its own id is reported as `held`, so
        // the recorder can map a `hand_over` to it mechanically (9.1).
        $world = $this->makeHeatWorld(robotARoom: 'kitchen');
        $world->robotStates['robot_a']->heldObjectId = RecorderInput::BARLEY_TEA_ID;

        $input = RecorderInput::forRobot($world, 'robot_a');

        $this->assertSame('barley_tea', $input->heldObjectId);
    }

    public function testRecorderMapsAHeatUseOntoTheAirConditionerId(): void
    {
        // A heat reply the recorder reads: the id list comes from the heat-wave
        // recorder input, so the mapped `use` targets the AC's own id.
        $mapper = new ReadingMapper();
        $json = json_encode([
            'operation' => 'use',
            'targetId' => 'window_air_conditioner',
            'actionQuote' => 'I switch on the window air conditioner.',
        ]);

        $reading = $mapper->map('robot_a', $json, 'recorder-2c');

        $this->assertFalse($reading->unknown);
        $this->assertSame(Operation::use->value, $reading->action->operation);
        $this->assertSame('window_air_conditioner', $reading->action->targetId);
    }

    // ---------- 9.2 the earthquake recorder input and prompt are unchanged ----

    public function testEarthquakeRecorderInputNeverListsTheBottle(): void
    {
        // An earthquake world in the kitchen: the bottle is never listed (9.2).
        $world = new WorldModel();
        $world->reset(Seed::fromInt(12345));
        $world->robotStates['robot_a']->room = 'kitchen';

        $this->assertSame(Disaster::earthquake, $world->disaster, 'guard: default disaster is earthquake');

        $input = RecorderInput::forRobot($world, 'robot_a');

        $this->assertNotContains('barley_tea', $input->roomObjectIds);
    }

    public function testEarthquakeRecorderPromptVersionIsUnchanged(): void
    {
        // No recorder prompt change was required, so the earthquake prompt
        // version stays `recorder-2c`; the heat-wave input change does not add
        // a heat-wave prompt variant (9.2).
        $client = new ClaudeRecorderClient(self::PLACEHOLDER_KEY, $this->config());

        $this->assertSame('recorder-2c', $client->promptVersion());
    }

    // ---------- 9.3 the evidence-quote rule holds for a heat reply ----------

    public function testHeatActionBackedByAnExactQuoteSurvives(): void
    {
        $reply = 'It is hot and still. I reach into the refrigerator and take the bottle of cold barley tea.';
        $reading = new Reading(
            new Action('robot_a', Operation::take->value, 'refrigerator', null, [], 'I reach into the refrigerator'),
            false,
            '{"operation":"take"}',
            'recorder-2c',
        );

        $verified = VerifiedReading::verify($reply, $reading);

        $this->assertFalse($verified->unknown, 'a quote-backed heat action survives the evidence check');
        $this->assertSame(Operation::take->value, $verified->action->operation);
        $this->assertSame('refrigerator', $verified->action->targetId);
    }

    public function testHeatActionNotBackedByAQuoteBecomesUnknown(): void
    {
        // The recorder may never add an action the reply does not support (9.3).
        $reply = 'I stand by the window and wait.';
        $reading = new Reading(
            new Action('robot_a', Operation::use->value, 'window_air_conditioner', null, [], 'I switch on the air conditioner'),
            false,
            '{"operation":"use"}',
            'recorder-2c',
        );

        $verified = VerifiedReading::verify($reply, $reading);

        $this->assertTrue($verified->unknown, 'an unquoted heat action turns unknown');
        $this->assertSame(Operation::unknown->value, $verified->action->operation);
    }

    public function testHeatSpokenLineKeptOnlyWhenQuotedExactly(): void
    {
        $reply = 'I say to him, "It is very hot in here." and I turn on the fan.';
        $reading = new Reading(
            new Action('robot_a', Operation::use->value, 'electric_fan', null, ['It is very hot in here.', 'Please drink some water.'], 'I turn on the fan'),
            false,
            '{"operation":"use"}',
            'recorder-2c',
        );

        $verified = VerifiedReading::verify($reply, $reading);

        $this->assertFalse($verified->unknown);
        $this->assertSame(
            ['It is very hot in here.'],
            $verified->action->words,
            'only the exactly-quoted spoken line survives; the invented one is dropped',
        );
    }
}
