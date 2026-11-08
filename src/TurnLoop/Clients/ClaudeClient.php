<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop\Clients;

use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\ParallelHttp;
use PoltergeistSwitch\TurnLoop\Values\ReplyRecord;

/**
 * The Robot_Client for the robot driven by Anthropic (Requirement 1.3): model
 * `claude-haiku-5-5` through the Anthropic Messages API.
 *
 * The request carries exactly one user message, no system prompt, provider
 * default parameters, and a maximum output of 4000 tokens (Requirement 1.2),
 * under the identical per-call timeout of {@see Config::$perCallTimeoutSeconds}
 * applied via `CURLOPT_TIMEOUT` (Requirements 1.7, 1.11).
 *
 * Truncation is detected by `stop_reason === "max_tokens"` on the response
 * (Requirement 1.5); token counts come from `usage.input_tokens` and
 * `usage.output_tokens`. Cost is computed from the configured per-token rates
 * (Config), not from literals in this client. A failure, timeout, or a body
 * that is not valid JSON of the expected shape yields an unanswered record with
 * no tokens or cost accrued (Requirement 1.8), with no retry inside one request
 * (Requirement 1.9).
 *
 * The API key is supplied at construction from the secrets source
 * (Requirement 1.10); it is never read from a literal in source, and is never
 * echoed into any returned value or error.
 */
final class ClaudeClient implements RobotClient
{
    /** The model this client calls (Requirement 1.3). */
    private const MODEL = 'claude-haiku-5-5';

    /** The Anthropic Messages API endpoint. */
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    /** The Anthropic API version header value. */
    private const ANTHROPIC_VERSION = '2023-06-01';

    /** The maximum output tokens for every call (Requirement 1.2). */
    private const MAX_OUTPUT_TOKENS = 4000;

    private string $apiKey;

    private Config $config;

    private ParallelHttp $http;

    /**
     * @param string $apiKey The Anthropic API key, read at the call site from
     *                       the secrets source (Requirement 1.10). Never a
     *                       literal in source; never echoed anywhere.
     */
    public function __construct(string $apiKey, Config $config, ?ParallelHttp $http = null)
    {
        $this->apiKey = $apiKey;
        $this->config = $config;
        $this->http = $http ?? new ParallelHttp();
    }

    public function call(string $prompt): ReplyRecord
    {
        $handle = $this->prepare($prompt);
        $results = $this->http->run(['robot' => $handle]);
        curl_close($handle);

        return $this->finish($prompt, $results['robot']);
    }

    /**
     * Build the exact request body sent for a prompt: one user message, no
     * system field, provider default parameters, max output 4000 tokens
     * (Requirement 1.2). Exposed so the request shape can be asserted without a
     * network call.
     *
     * @return array<string,mixed>
     */
    public function requestBody(string $prompt): array
    {
        return [
            'model' => self::MODEL,
            'max_tokens' => self::MAX_OUTPUT_TOKENS,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];
    }

    public function prepare(string $prompt): \CurlHandle
    {
        $body = $this->requestBody($prompt);

        $handle = curl_init();
        curl_setopt($handle, CURLOPT_URL, self::ENDPOINT);
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_TIMEOUT, $this->config->perCallTimeoutSeconds);
        curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        curl_setopt($handle, CURLOPT_HTTPHEADER, [
            'content-type: application/json',
            'anthropic-version: ' . self::ANTHROPIC_VERSION,
            'x-api-key: ' . $this->apiKey,
        ]);

        return $handle;
    }

    public function finish(string $prompt, array $result): ReplyRecord
    {
        $seconds = $result['seconds'];

        // A transport failure/timeout, or any non-2xx HTTP status, is
        // unanswered with no tokens or cost accrued (Requirement 1.8).
        if ($result['error'] !== null || $result['body'] === null) {
            return ReplyRecord::unanswered($prompt, $seconds);
        }
        if ($result['status'] < 200 || $result['status'] >= 300) {
            return ReplyRecord::unanswered($prompt, $seconds);
        }

        $decoded = json_decode($result['body'], true);
        if (!is_array($decoded)) {
            return ReplyRecord::unanswered($prompt, $seconds);
        }

        $reply = $this->extractText($decoded);
        $usage = $decoded['usage'] ?? null;
        if ($reply === null || !is_array($usage)) {
            return ReplyRecord::unanswered($prompt, $seconds);
        }

        $inputTokens = $this->intField($usage, 'input_tokens');
        $outputTokens = $this->intField($usage, 'output_tokens');
        if ($inputTokens === null || $outputTokens === null) {
            return ReplyRecord::unanswered($prompt, $seconds);
        }

        // Truncated when the model hit the output-token limit (Requirement 1.5).
        $truncated = (($decoded['stop_reason'] ?? null) === 'max_tokens');

        $cost = $inputTokens * $this->config->inputCostPerToken
            + $outputTokens * $this->config->outputCostPerToken;

        return new ReplyRecord(
            $prompt,
            $reply,
            $inputTokens,
            $outputTokens,
            $seconds,
            $cost,
            false,
            $truncated,
        );
    }

    /**
     * Concatenate the text blocks of an Anthropic Messages response `content`
     * array. Returns null when the shape is not the expected one.
     *
     * @param array<mixed> $decoded
     */
    private function extractText(array $decoded): ?string
    {
        $content = $decoded['content'] ?? null;
        if (!is_array($content)) {
            return null;
        }

        $text = '';
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && isset($block['text']) && is_string($block['text'])) {
                $text .= $block['text'];
            }
        }

        return $text;
    }

    /**
     * Read a non-negative integer usage field, or null when absent/invalid.
     *
     * @param array<mixed> $usage
     */
    private function intField(array $usage, string $key): ?int
    {
        if (!isset($usage[$key]) || !is_int($usage[$key]) || $usage[$key] < 0) {
            return null;
        }

        return $usage[$key];
    }
}
