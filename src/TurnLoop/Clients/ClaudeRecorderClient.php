<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop\Clients;

use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\ParallelHttp;
use PoltergeistSwitch\TurnLoop\Values\Reading;

/**
 * The Recorder_Client (Requirement 3): model `claude-haiku-5-5` at temperature 0
 * through the Anthropic Messages API, reading exactly one reply per call
 * (Requirement 3.1).
 *
 * The recorder is given a fixed, versioned prompt that only instructs the model
 * to map the reply's words to the supplied ids and return the Reading-shape
 * JSON — no danger words, no hints, no formats beyond the mechanical mapping,
 * no examples (robot-input steering). The prompt text and its version
 * ({@see PROMPT_VERSION}) are kept together so the version bumps whenever the
 * prompt changes (Requirement 3.5).
 *
 * The model's JSON output is handed to {@see ReadingMapper}, which turns it into
 * an engine Action. Recorder output that is not valid JSON of the Reading shape,
 * or a failed / timed-out call, yields an `unknown` Reading (Requirements 3.6,
 * 3.7); the call never retries within one request (Requirement 3.7). The
 * recorder never decides whether an action is possible or dangerous
 * (Requirement 3.9).
 *
 * The API key is supplied at construction from the secrets source
 * (Requirement 1.10); it is never read from a literal in source, and is never
 * echoed into any returned value or error.
 */
final class ClaudeRecorderClient implements RecorderClient
{
    /** The model this client calls (Requirement 3.1). */
    private const MODEL = 'claude-haiku-5-5';

    /** The Anthropic Messages API endpoint. */
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    /** The Anthropic API version header value. */
    private const ANTHROPIC_VERSION = '2023-06-01';

    /** The maximum output tokens for every recorder call. */
    private const MAX_OUTPUT_TOKENS = 4000;

    /**
     * The version id of the fixed recorder prompt (Requirement 3.5). Bump this
     * whenever {@see instructionText()} or the generated prompt changes, so the
     * stored reading always carries the version of the prompt that produced it.
     */
    private const PROMPT_VERSION = 'recorder-1';

    private string $apiKey;

    private Config $config;

    private ParallelHttp $http;

    private ReadingMapper $mapper;

    /**
     * @param string $apiKey The Anthropic API key, read at the call site from
     *                       the secrets source (Requirement 1.10). Never a
     *                       literal in source; never echoed anywhere.
     */
    public function __construct(string $apiKey, Config $config, ?ParallelHttp $http = null, ?ReadingMapper $mapper = null)
    {
        $this->apiKey = $apiKey;
        $this->config = $config;
        $this->http = $http ?? new ParallelHttp();
        $this->mapper = $mapper ?? new ReadingMapper();
    }

    public function read(RecorderInput $input, string $reply): Reading
    {
        $handle = $this->prepare($input, $reply);
        $results = $this->http->run(['recorder' => $handle]);
        curl_close($handle);

        return $this->finish($input, $reply, $results['recorder']);
    }

    public function promptVersion(): string
    {
        return self::PROMPT_VERSION;
    }

    /**
     * Build the exact request body sent for one reading: one user message
     * carrying the fixed instruction plus the ids-only input and the reply,
     * temperature 0 (Requirement 3.1), no system prompt, max output 4000
     * tokens. Exposed so the request shape can be asserted without a network
     * call.
     *
     * @return array<string,mixed>
     */
    public function requestBody(RecorderInput $input, string $reply): array
    {
        return [
            'model' => self::MODEL,
            'max_tokens' => self::MAX_OUTPUT_TOKENS,
            'temperature' => 0,
            'messages' => [
                ['role' => 'user', 'content' => $this->userMessage($input, $reply)],
            ],
        ];
    }

    public function prepare(RecorderInput $input, string $reply): \CurlHandle
    {
        $body = $this->requestBody($input, $reply);

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

    public function finish(RecorderInput $input, string $reply, array $result): Reading
    {
        // A transport failure/timeout, or any non-2xx HTTP status, yields an
        // unknown Reading with no retry (Requirement 3.7). The raw body (which
        // may be null) is recorded as an empty string so nothing is fabricated.
        if ($result['error'] !== null || $result['body'] === null) {
            return $this->mapper->map($this->robotIdOf($input), '', self::PROMPT_VERSION);
        }
        if ($result['status'] < 200 || $result['status'] >= 300) {
            return $this->mapper->map($this->robotIdOf($input), '', self::PROMPT_VERSION);
        }

        $decoded = json_decode($result['body'], true);
        if (!is_array($decoded)) {
            return $this->mapper->map($this->robotIdOf($input), '', self::PROMPT_VERSION);
        }

        // The recorder's own JSON is the text content of the Anthropic reply.
        $rawJson = $this->extractText($decoded) ?? '';

        // ReadingMapper turns the recorder's JSON into an engine Action, and
        // records `unknown` when the JSON is not of the Reading shape
        // (Requirement 3.6).
        return $this->mapper->map($this->robotIdOf($input), $rawJson, self::PROMPT_VERSION);
    }

    /**
     * The fixed instruction given to the recorder, kept together with
     * {@see PROMPT_VERSION}. It instructs the model only to map the reply's
     * words to the supplied ids and return the Reading-shape JSON. It carries
     * no danger words, no hints, no examples (robot-input steering).
     */
    private function instructionText(): string
    {
        return "Read the reply below and map only its words to the given ids.\n"
            . "Return one JSON object and nothing else, with these keys:\n"
            . "  operation: one of the operation names, or null if none can be read\n"
            . "  targetId: the id the action acts on, or null\n"
            . "  targetSpot: one of the spot names, or null\n"
            . "  actionQuote: the exact words from the reply the action was read from, or null\n"
            . "  words: the spoken lines, each exactly as written in the reply\n"
            . "  reported: a list of { quote, heardAs, ownForm } for speech the reply repeats from the other robot\n"
            . "Use only the ids given. Quote the reply exactly. Do not add anything of your own.";
    }

    /**
     * Compose the single user message: the fixed instruction, the ids-only
     * input (operation names, room keys, this room's object ids, the held
     * object id, the spot names, the resident id), and the reply to read.
     */
    private function userMessage(RecorderInput $input, string $reply): string
    {
        $lines = [];
        $lines[] = $this->instructionText();
        $lines[] = '';
        $lines[] = 'operations: ' . implode(', ', $input->operationNames);
        $lines[] = 'rooms: ' . implode(', ', $input->roomKeys);
        $lines[] = 'objects: ' . implode(', ', $input->roomObjectIds);
        $lines[] = 'held: ' . ($input->heldObjectId ?? 'none');
        $lines[] = 'spots: ' . implode(', ', $input->spotNames);
        $lines[] = 'resident: ' . $input->residentId;
        $lines[] = '';
        $lines[] = 'reply:';
        $lines[] = $reply;

        return implode("\n", $lines);
    }

    /**
     * The robot id this input describes. RecorderInput does not carry the robot
     * id itself (it is ids-only by Requirement 3.4), so the reading's Action is
     * built with the generic placeholder below; TurnService rebinds the robot
     * id when it keys the action for `applyTurn`.
     */
    private function robotIdOf(RecorderInput $input): string
    {
        return 'robot';
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
}
