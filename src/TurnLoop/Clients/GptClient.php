<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop\Clients;

use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\ParallelHttp;
use PoltergeistSwitch\TurnLoop\Values\ReplyRecord;

/**
 * The Robot_Client for the robot driven by OpenAI (Requirement 1.3): model
 * `gpt-6-luna` through the OpenAI Responses API.
 *
 * The request carries a single user input and `max_output_tokens = 4000`, with
 * no system/instructions prompt and provider default parameters
 * (Requirement 1.2), under the identical per-call timeout of
 * {@see Config::$perCallTimeoutSeconds} applied via `CURLOPT_TIMEOUT`
 * (Requirements 1.7, 1.11).
 *
 * Truncation is detected by `status === "incomplete"` on the response
 * (Requirement 1.5); token counts come from the response usage fields
 * (`input_tokens`, `output_tokens`). Cost is computed from the configured
 * per-token rates (Config), not from literals in this client. A failure,
 * timeout, or a body that is not valid JSON of the expected shape yields an
 * unanswered record with no tokens or cost accrued (Requirement 1.8), with no
 * retry inside one request (Requirement 1.9).
 *
 * The API key is supplied at construction from the secrets source
 * (Requirement 1.10); it is never read from a literal in source, and is never
 * echoed into any returned value or error.
 */
final class GptClient implements RobotClient
{
    /** The model this client calls (Requirement 1.3). */
    private const MODEL = 'gpt-6-luna';

    /** The OpenAI Responses API endpoint. */
    private const ENDPOINT = 'https://api.openai.com/v1/responses';

    /** The maximum output tokens for every call (Requirement 1.2). */
    private const MAX_OUTPUT_TOKENS = 4000;

    private string $apiKey;

    private Config $config;

    private ParallelHttp $http;

    /**
     * @param string $apiKey The OpenAI API key, read at the call site from the
     *                       secrets source (Requirement 1.10). Never a literal
     *                       in source; never echoed anywhere.
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
     * Build the exact request body sent for a prompt: a single user input, max
     * output 4000 tokens, no system/instructions, provider default parameters
     * (Requirement 1.2). Exposed so the request shape can be asserted without a
     * network call.
     *
     * @return array<string,mixed>
     */
    public function requestBody(string $prompt): array
    {
        return [
            'model' => self::MODEL,
            'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
            'input' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => $prompt],
                    ],
                ],
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
            'authorization: Bearer ' . $this->apiKey,
        ]);

        return $handle;
    }

    public function finish(string $prompt, array $result): ReplyRecord
    {
        $seconds = $result['seconds'];

        // A transport failure/timeout, or any non-2xx HTTP status, is
        // unanswered with no tokens or cost accrued (Requirement 1.8). A short
        // machine-only failure reason is attached (never a key, credential,
        // path, provider name, or provider message).
        if ($result['error'] !== null || $result['body'] === null) {
            return ReplyRecord::unanswered($prompt, $seconds, $this->failureReason($result));
        }
        if ($result['status'] < 200 || $result['status'] >= 300) {
            return ReplyRecord::unanswered($prompt, $seconds, $this->failureReason($result));
        }

        $decoded = json_decode($result['body'], true);
        if (!is_array($decoded)) {
            return ReplyRecord::unanswered($prompt, $seconds, 'invalid_json');
        }

        $reply = $this->extractText($decoded);
        $usage = $decoded['usage'] ?? null;
        if ($reply === null || !is_array($usage)) {
            return ReplyRecord::unanswered($prompt, $seconds, 'invalid_json');
        }

        $inputTokens = $this->intField($usage, 'input_tokens');
        $outputTokens = $this->intField($usage, 'output_tokens');
        if ($inputTokens === null || $outputTokens === null) {
            return ReplyRecord::unanswered($prompt, $seconds, 'invalid_json');
        }

        // Truncated when the Responses call stopped incomplete at the token
        // limit (Requirement 1.5).
        $truncated = (($decoded['status'] ?? null) === 'incomplete');

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
     * Derive a short, machine-only failure reason token from a transport or
     * HTTP failure result (never a key, credential, path, provider name, or
     * provider message). A transport error that looks like a timeout becomes
     * "timeout", any other transport error or a null body becomes
     * "transport_error"; a non-2xx status becomes "http_<status>", with a short
     * enumerated provider error TYPE appended when present, e.g.
     * "http_400:invalid_request_error". Only the short error.type token is ever
     * used — never error.message.
     *
     * @param array{status:int,body:?string,seconds:float,error:?string} $result
     */
    private function failureReason(array $result): string
    {
        if ($result['error'] !== null || $result['body'] === null) {
            $error = (string) ($result['error'] ?? '');
            if ($error !== '' && (stripos($error, 'timeout') !== false || stripos($error, 'timed out') !== false)) {
                return 'timeout';
            }

            return 'transport_error';
        }

        $reason = 'http_' . $result['status'];
        $type = $this->providerErrorType($result['body']);
        if ($type !== null) {
            $reason .= ':' . $type;
        }

        return $reason;
    }

    /**
     * The short machine error TYPE from a provider error body of the shape
     * {"error":{"type":"invalid_request_error",...}}, or null when absent. Only
     * the enumerated type token is returned, never error.message, so no
     * provider message text is ever stored.
     */
    private function providerErrorType(?string $body): ?string
    {
        if ($body === null) {
            return null;
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return null;
        }
        $error = $decoded['error'] ?? null;
        if (is_array($error) && isset($error['type']) && is_string($error['type']) && $error['type'] !== '') {
            return $error['type'];
        }

        return null;
    }

    /**
     * Pull the assistant text out of an OpenAI Responses body. Prefers the
     * convenience `output_text` field; otherwise walks the `output` array's
     * message content blocks. Returns null when the shape is not recognised.
     *
     * @param array<mixed> $decoded
     */
    private function extractText(array $decoded): ?string
    {
        if (isset($decoded['output_text']) && is_string($decoded['output_text'])) {
            return $decoded['output_text'];
        }

        $output = $decoded['output'] ?? null;
        if (!is_array($output)) {
            return null;
        }

        $text = '';
        foreach ($output as $item) {
            if (!is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }
            $content = $item['content'] ?? null;
            if (!is_array($content)) {
                continue;
            }
            foreach ($content as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'output_text' && isset($block['text']) && is_string($block['text'])) {
                    $text .= $block['text'];
                }
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
