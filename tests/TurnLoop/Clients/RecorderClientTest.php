<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop\Clients;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\WorldModel;
use PoltergeistSwitch\TurnLoop\Clients\ClaudeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\FakeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\ReadingMapper;
use PoltergeistSwitch\TurnLoop\Clients\RecorderInput;
use PoltergeistSwitch\TurnLoop\Clients\VerifiedReading;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\Values\Reading;

/**
 * Unit tests for the recorder client pieces (task 6.4):
 * {@see RecorderInput}, {@see ReadingMapper}, {@see ClaudeRecorderClient},
 * {@see FakeRecorderClient}, and the {@see VerifiedReading} EvidenceCheck
 * wiring. No real provider API and no network; the API key is a placeholder,
 * never a real secret.
 *
 * Requirements: 3.3, 3.4, 3.6, 3.7, 3.8
 */
class RecorderClientTest extends TestCase
{
    /** A placeholder, never a real key. */
    private const PLACEHOLDER_KEY = 'test-placeholder-key';

    private function config(): Config
    {
        return new Config();
    }

    /**
     * @param array{status:int,body:?string,seconds:float,error:?string} $overrides
     * @return array{status:int,body:?string,seconds:float,error:?string}
     */
    private function makeResult(array $overrides): array
    {
        return array_merge(
            ['status' => 200, 'body' => null, 'seconds' => 0.5, 'error' => null],
            $overrides,
        );
    }

    // ---------- RecorderInput (Requirement 3.4) ----------

    public function testRecorderInputCarriesOnlyAllowedIdentifiers(): void
    {
        $world = new WorldModel();
        $world->reset(Seed::fromInt(12345));

        // Put robot_a alone in the kitchen so we can check only kitchen objects
        // appear, and nothing from any other room.
        $world->robotStates['robot_a']->room = 'kitchen';

        $input = RecorderInput::forRobot($world, 'robot_a');

        // Operation names: the eight non-unknown Operation values, no 'unknown'.
        $this->assertNotContains(Operation::unknown->value, $input->operationNames);
        $this->assertContains('move', $input->operationNames);
        $this->assertContains('speak', $input->operationNames);
        $this->assertCount(8, $input->operationNames);

        // Room keys: the six room keys from the world.
        $this->assertSame(array_keys($world->rooms), $input->roomKeys);
        $this->assertCount(6, $input->roomKeys);

        // Object ids: only objects whose room is the robot's room.
        $this->assertNotEmpty($input->roomObjectIds);
        foreach ($input->roomObjectIds as $id) {
            $this->assertSame('kitchen', $world->objects[$id]->room, "object {$id} must be in the robot's room");
        }

        // No object from another room leaks in (e.g. the living-room TV).
        $this->assertNotContains('tv', $input->roomObjectIds);
        $this->assertNotContains('futon', $input->roomObjectIds);

        // Fixed spots and the literal resident id, nothing else.
        $this->assertSame(['window', 'gas_stove', 'under_table', 'beside_futon'], $input->spotNames);
        $this->assertSame('resident', $input->residentId);
    }

    public function testRecorderInputGivesRoomObjectsEvenWhenRoomIsDark(): void
    {
        $world = new WorldModel();
        $world->reset(Seed::fromInt(999));
        $world->robotStates['robot_a']->room = 'kitchen';

        // A dark room hides objects from `objects_visible_to`, but the recorder
        // must still be given the mechanical id list.
        $world->rooms['kitchen']->lightLevel = \PoltergeistSwitch\Engine\Enums\LightLevel::dark;

        $this->assertSame([], $world->objects_visible_to('robot_a', 0), 'guard: a dark room is dark to the robot');

        $input = RecorderInput::forRobot($world, 'robot_a');
        $this->assertNotEmpty($input->roomObjectIds, 'the recorder gets the full id list regardless of light');
    }

    public function testRecorderInputCarriesHeldObjectId(): void
    {
        $world = new WorldModel();
        $world->reset(Seed::fromInt(7));
        $world->robotStates['robot_a']->heldObjectId = 'flashlight';

        $input = RecorderInput::forRobot($world, 'robot_a');
        $this->assertSame('flashlight', $input->heldObjectId);
    }

    public function testRecorderInputHeldObjectIdNullWhenNothingHeld(): void
    {
        $world = new WorldModel();
        $world->reset(Seed::fromInt(7));
        $world->robotStates['robot_a']->heldObjectId = null;

        $input = RecorderInput::forRobot($world, 'robot_a');
        $this->assertNull($input->heldObjectId);
    }

    // ---------- ReadingMapper classification (Requirement 3.3) ----------

    public function testMapsSpeakOnlyToSpeakCarryingSpokenLines(): void
    {
        $mapper = new ReadingMapper();
        $json = json_encode([
            'operation' => null,
            'words' => ['Are you all right?'],
        ]);

        $reading = $mapper->map('robot_a', $json, 'recorder-1');

        $this->assertFalse($reading->unknown);
        $this->assertSame(Operation::speak->value, $reading->action->operation);
        $this->assertSame(['Are you all right?'], $reading->action->words);
        $this->assertNull($reading->action->targetId);
    }

    public function testMapsExplicitWaitToWait(): void
    {
        $mapper = new ReadingMapper();
        $json = json_encode([
            'operation' => 'wait',
            'actionQuote' => 'I stay still.',
        ]);

        $reading = $mapper->map('robot_a', $json, 'recorder-1');

        $this->assertFalse($reading->unknown);
        $this->assertSame(Operation::wait->value, $reading->action->operation);
    }

    public function testMapsPhysicalActionWithTargetAndQuote(): void
    {
        $mapper = new ReadingMapper();
        $json = json_encode([
            'operation' => 'move',
            'targetSpot' => 'under_table',
            'actionQuote' => 'I crawl under the table.',
        ]);

        $reading = $mapper->map('robot_a', $json, 'recorder-1');

        $this->assertFalse($reading->unknown);
        $this->assertSame('move', $reading->action->operation);
        $this->assertSame('under_table', $reading->action->targetSpot);
        $this->assertSame('I crawl under the table.', $reading->action->actionQuote);
    }

    public function testMapsNoReadableActionToUnknownKeepingSpokenLines(): void
    {
        $mapper = new ReadingMapper();
        $json = json_encode([
            'operation' => 'unknown',
            'words' => ['Hmm.'],
        ]);

        $reading = $mapper->map('robot_a', $json, 'recorder-1');

        $this->assertTrue($reading->unknown);
        $this->assertSame(Operation::unknown->value, $reading->action->operation);
        $this->assertSame(['Hmm.'], $reading->action->words, 'spoken lines survive onto an unknown action');
    }

    public function testInvalidJsonMapsToUnknown(): void
    {
        $mapper = new ReadingMapper();

        $reading = $mapper->map('robot_a', 'not json at all', 'recorder-1');

        $this->assertTrue($reading->unknown);
        $this->assertSame(Operation::unknown->value, $reading->action->operation);
    }

    public function testJsonNotMatchingReadingShapeMapsToUnknown(): void
    {
        $mapper = new ReadingMapper();
        // An unrecognised operation name does not match the Reading shape.
        $json = json_encode(['operation' => 'teleport']);

        $reading = $mapper->map('robot_a', $json, 'recorder-1');

        $this->assertTrue($reading->unknown);
        $this->assertSame(Operation::unknown->value, $reading->action->operation);
    }

    public function testMapperAssignsNoActionCodes(): void
    {
        // The engine Action has no A1-N1 code field; assert the mapped action
        // carries only the fields the mapper is allowed to set (Req 3.10).
        $mapper = new ReadingMapper();
        $json = json_encode(['operation' => 'cover', 'targetId' => 'futon', 'actionQuote' => 'I pull the futon over him.']);

        $reading = $mapper->map('robot_b', $json, 'recorder-1');

        $this->assertSame('robot_b', $reading->action->robotId);
        $this->assertSame('cover', $reading->action->operation);
    }

    // ---------- ClaudeRecorderClient.finish (Requirements 3.6, 3.7) ----------

    public function testRecorderFinishReadsValidReadingJson(): void
    {
        $client = new ClaudeRecorderClient(self::PLACEHOLDER_KEY, $this->config());
        $input = $this->sampleInput();

        $recorderJson = json_encode(['operation' => 'wait', 'actionQuote' => 'I wait.']);
        $body = json_encode([
            'content' => [['type' => 'text', 'text' => $recorderJson]],
        ]);

        $reading = $client->finish($input, 'I wait.', $this->makeResult(['body' => $body]));

        $this->assertFalse($reading->unknown);
        $this->assertSame(Operation::wait->value, $reading->action->operation);
        $this->assertSame($recorderJson, $reading->rawJson);
    }

    public function testRecorderFinishInvalidModelJsonMapsToUnknown(): void
    {
        $client = new ClaudeRecorderClient(self::PLACEHOLDER_KEY, $this->config());
        $input = $this->sampleInput();

        $body = json_encode([
            'content' => [['type' => 'text', 'text' => 'this is not json']],
        ]);

        $reading = $client->finish($input, 'reply', $this->makeResult(['body' => $body]));

        $this->assertTrue($reading->unknown);
        $this->assertSame(Operation::unknown->value, $reading->action->operation);
    }

    public function testRecorderFinishErrorResultMapsToUnknown(): void
    {
        $client = new ClaudeRecorderClient(self::PLACEHOLDER_KEY, $this->config());
        $input = $this->sampleInput();

        $errored = $this->makeResult(['status' => 0, 'error' => 'curl error 28', 'seconds' => 75.0]);

        $reading = $client->finish($input, 'reply', $errored);

        $this->assertTrue($reading->unknown);
        $this->assertSame(Operation::unknown->value, $reading->action->operation);
    }

    public function testRecorderFinishNon2xxMapsToUnknown(): void
    {
        $client = new ClaudeRecorderClient(self::PLACEHOLDER_KEY, $this->config());
        $input = $this->sampleInput();

        $reading = $client->finish($input, 'reply', $this->makeResult(['status' => 500, 'body' => '{"error":"boom"}']));

        $this->assertTrue($reading->unknown);
    }

    public function testRecorderRequestBodyIsTemperatureZeroOneUserMessageNoSystem(): void
    {
        $client = new ClaudeRecorderClient(self::PLACEHOLDER_KEY, $this->config());
        $input = $this->sampleInput();

        $decoded = $client->requestBody($input, 'the reply');

        $this->assertSame('claude-haiku-5-5', $decoded['model']);
        $this->assertSame(0, $decoded['temperature']);
        $this->assertSame(4000, $decoded['max_tokens']);
        $this->assertArrayNotHasKey('system', $decoded);
        $this->assertCount(1, $decoded['messages']);
        $this->assertSame('user', $decoded['messages'][0]['role']);
        // The reply is carried in the single user message.
        $this->assertStringContainsString('the reply', $decoded['messages'][0]['content']);
    }

    public function testPromptVersionIsReturnedAndNonEmpty(): void
    {
        $client = new ClaudeRecorderClient(self::PLACEHOLDER_KEY, $this->config());
        $this->assertNotSame('', $client->promptVersion());
    }

    // ---------- FakeRecorderClient (Requirement 14.1) ----------

    public function testFakeRecorderClientCountsReadsAndOpensNoNetwork(): void
    {
        $fake = new FakeRecorderClient();

        $this->assertSame(0, $fake->callCount());

        $input = $this->sampleInput();
        $fake->read($input, 'reply-a');
        $fake->read($input, 'reply-b');

        $this->assertSame(2, $fake->callCount());
        $this->assertNotSame('', $fake->promptVersion());
    }

    public function testFakeRecorderClientReturnsPerReplyConfiguredReading(): void
    {
        $fake = new FakeRecorderClient();
        $configured = new Reading(
            new Action('robot', Operation::move->value, null, 'under_table', [], 'I move under the table.'),
            false,
            '{"operation":"move"}',
            'recorder-fake',
        );
        $fake->setReadingForReply('I move under the table.', $configured);

        $this->assertSame($configured, $fake->read($this->sampleInput(), 'I move under the table.'));
    }

    // ---------- VerifiedReading (Requirement 3.8) ----------

    public function testVerifiedReadingKeepsQuoteBackedAction(): void
    {
        $reply = 'I crawl under the table to shelter.';
        $reading = new Reading(
            new Action('robot_a', 'move', null, 'under_table', [], 'I crawl under the table'),
            false,
            '{"operation":"move"}',
            'recorder-1',
        );

        $verified = VerifiedReading::verify($reply, $reading);

        $this->assertFalse($verified->unknown);
        $this->assertSame('move', $verified->action->operation);
        $this->assertSame('I crawl under the table', $verified->action->actionQuote);
    }

    public function testVerifiedReadingUnbackedActionBecomesUnknown(): void
    {
        $reply = 'I wait by the window.';
        $reading = new Reading(
            new Action('robot_a', 'move', null, 'under_table', [], 'I crawl under the table'),
            false,
            '{"operation":"move"}',
            'recorder-1',
        );

        $verified = VerifiedReading::verify($reply, $reading);

        $this->assertTrue($verified->unknown, 'a quote not in the reply turns the action unknown');
        $this->assertSame(Operation::unknown->value, $verified->action->operation);
        $this->assertNull($verified->action->actionQuote);
    }

    public function testVerifiedReadingDropsUnbackedSpokenLines(): void
    {
        $reply = 'I say: "Stay down." and I cover him.';
        $reading = new Reading(
            new Action('robot_a', 'cover', 'futon', null, ['Stay down.', 'Run outside now.'], 'I cover him'),
            false,
            '{"operation":"cover"}',
            'recorder-1',
        );

        $verified = VerifiedReading::verify($reply, $reading);

        $this->assertFalse($verified->unknown);
        $this->assertSame(['Stay down.'], $verified->action->words, 'only the quoted line survives');
    }

    private function sampleInput(): RecorderInput
    {
        $world = new WorldModel();
        $world->reset(Seed::fromInt(42));

        return RecorderInput::forRobot($world, 'robot_a');
    }
}
