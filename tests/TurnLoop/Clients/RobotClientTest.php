<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop\Clients;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\TurnLoop\Clients\ClaudeClient;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\Clients\GptClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\Values\ReplyRecord;

/**
 * Unit tests for the RobotClient providers and the fake (task 5.3).
 *
 * These tests drive the providers' {@see ClaudeClient::finish()} /
 * {@see GptClient::finish()} result-shaping with hand-built result arrays — no
 * real provider API and no network. The API key passed to the clients is a
 * throwaway placeholder, never a real secret.
 *
 * Requirements: 1.2, 1.4, 1.5, 1.8
 */
class RobotClientTest extends TestCase
{
    /** A placeholder, never a real key. */
    private const PLACEHOLDER_KEY = 'test-placeholder-key';

    private function config(): Config
    {
        return new Config();
    }

    /**
     * @param array{status:int,body:?string,seconds:float,error:?string} ...$_
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

    public function testClaudeMapsMaxTokensStopReasonToTruncated(): void
    {
        $client = new ClaudeClient(self::PLACEHOLDER_KEY, $this->config());

        $body = json_encode([
            'content' => [['type' => 'text', 'text' => 'I move under the table.']],
            'stop_reason' => 'max_tokens',
            'usage' => ['input_tokens' => 120, 'output_tokens' => 4000],
        ]);

        $record = $client->finish('prompt', $this->makeResult(['body' => $body]));

        $this->assertFalse($record->unanswered);
        $this->assertTrue($record->truncated, 'stop_reason=max_tokens means truncated');
        $this->assertSame('I move under the table.', $record->reply);
        $this->assertSame(120, $record->inputTokens);
        $this->assertSame(4000, $record->outputTokens);
        $this->assertGreaterThan(0.0, $record->cost);
    }

    public function testClaudeNonTruncatedWhenStopReasonIsEndTurn(): void
    {
        $client = new ClaudeClient(self::PLACEHOLDER_KEY, $this->config());

        $body = json_encode([
            'content' => [['type' => 'text', 'text' => 'done']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);

        $record = $client->finish('prompt', $this->makeResult(['body' => $body]));

        $this->assertFalse($record->truncated);
        $this->assertFalse($record->unanswered);
    }

    public function testGptMapsIncompleteStatusToTruncated(): void
    {
        $client = new GptClient(self::PLACEHOLDER_KEY, $this->config());

        $body = json_encode([
            'status' => 'incomplete',
            'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => 'I cover the man.']],
            ]],
            'usage' => ['input_tokens' => 80, 'output_tokens' => 4000],
        ]);

        $record = $client->finish('prompt', $this->makeResult(['body' => $body]));

        $this->assertFalse($record->unanswered);
        $this->assertTrue($record->truncated, 'status=incomplete means truncated');
        $this->assertSame('I cover the man.', $record->reply);
        $this->assertSame(80, $record->inputTokens);
        $this->assertSame(4000, $record->outputTokens);
    }

    public function testGptReadsOutputTextConvenienceField(): void
    {
        $client = new GptClient(self::PLACEHOLDER_KEY, $this->config());

        $body = json_encode([
            'status' => 'completed',
            'output_text' => 'I wait.',
            'usage' => ['input_tokens' => 3, 'output_tokens' => 2],
        ]);

        $record = $client->finish('prompt', $this->makeResult(['body' => $body]));

        $this->assertSame('I wait.', $record->reply);
        $this->assertFalse($record->truncated);
    }

    public function testErrorResultMapsToUnansweredWithZeroTokensAndCost(): void
    {
        $claude = new ClaudeClient(self::PLACEHOLDER_KEY, $this->config());
        $gpt = new GptClient(self::PLACEHOLDER_KEY, $this->config());

        $errored = $this->makeResult(['status' => 0, 'error' => 'curl error 28', 'seconds' => 75.0]);

        foreach ([$claude->finish('p', $errored), $gpt->finish('p', $errored)] as $record) {
            $this->assertTrue($record->unanswered);
            $this->assertSame(0, $record->inputTokens);
            $this->assertSame(0, $record->outputTokens);
            $this->assertSame(0.0, $record->cost);
            $this->assertSame('', $record->reply);
            $this->assertSame(75.0, $record->seconds);
        }
    }

    public function testNon2xxStatusMapsToUnanswered(): void
    {
        $client = new ClaudeClient(self::PLACEHOLDER_KEY, $this->config());

        $record = $client->finish('p', $this->makeResult(['status' => 500, 'body' => '{"error":"boom"}']));

        $this->assertTrue($record->unanswered);
    }

    public function testInvalidJsonBodyMapsToUnanswered(): void
    {
        $claude = new ClaudeClient(self::PLACEHOLDER_KEY, $this->config());
        $gpt = new GptClient(self::PLACEHOLDER_KEY, $this->config());

        $garbled = $this->makeResult(['body' => 'not json at all']);

        $this->assertTrue($claude->finish('p', $garbled)->unanswered);
        $this->assertTrue($gpt->finish('p', $garbled)->unanswered);
    }

    public function testMissingUsageMapsToUnanswered(): void
    {
        $client = new ClaudeClient(self::PLACEHOLDER_KEY, $this->config());

        $body = json_encode([
            'content' => [['type' => 'text', 'text' => 'hi']],
            'stop_reason' => 'end_turn',
        ]);

        $this->assertTrue($client->finish('p', $this->makeResult(['body' => $body]))->unanswered);
    }

    public function testClaudeRequestBodyCarriesOneUserMessageNoSystemAndMax4000(): void
    {
        $client = new ClaudeClient(self::PLACEHOLDER_KEY, $this->config());

        $decoded = $client->requestBody('the situation text');

        $this->assertSame('claude-haiku-5-5', $decoded['model']);
        $this->assertSame(4000, $decoded['max_tokens']);
        $this->assertArrayNotHasKey('system', $decoded);
        $this->assertCount(1, $decoded['messages']);
        $this->assertSame('user', $decoded['messages'][0]['role']);
        $this->assertSame('the situation text', $decoded['messages'][0]['content']);
    }

    public function testGptRequestBodyCarriesOneUserInputNoSystemAndMax4000(): void
    {
        $client = new GptClient(self::PLACEHOLDER_KEY, $this->config());

        $decoded = $client->requestBody('the situation text');

        $this->assertSame('gpt-6-luna', $decoded['model']);
        $this->assertSame(4000, $decoded['max_output_tokens']);
        $this->assertArrayNotHasKey('system', $decoded);
        $this->assertArrayNotHasKey('instructions', $decoded);
        $this->assertCount(1, $decoded['input']);
        $this->assertSame('user', $decoded['input'][0]['role']);
        $this->assertSame('the situation text', $decoded['input'][0]['content'][0]['text']);
    }

    public function testFakeRobotClientCountsCallsAndOpensNoNetwork(): void
    {
        $fake = new FakeRobotClient(new ReplyRecord('', 'canned', 2, 3, 0.1, 0.0, false, false));

        $this->assertSame(0, $fake->callCount());

        $first = $fake->call('prompt-a');
        $this->assertSame('canned', $first->reply);
        $this->assertSame('prompt-a', $first->prompt);
        $this->assertSame(1, $fake->callCount());

        $fake->call('prompt-b');
        $this->assertSame(2, $fake->callCount());
    }

    public function testFakeRobotClientReturnsPerPromptConfiguredRecord(): void
    {
        $fake = new FakeRobotClient();
        $configured = new ReplyRecord('prompt-x', 'specific', 5, 5, 0.2, 0.0, false, true);
        $fake->setReplyForPrompt('prompt-x', $configured);

        $this->assertSame($configured, $fake->call('prompt-x'));
        $this->assertSame(1, $fake->callCount());
    }
}
