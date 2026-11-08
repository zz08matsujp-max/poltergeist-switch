<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop\Clients;

use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\Values\ReportedSpeech;
use PoltergeistSwitch\TurnLoop\Values\Reading;

/**
 * Maps the recorder's raw JSON output to an engine {@see Action}, wrapped in a
 * {@see Reading} (Requirements 3.2, 3.3, 3.6, 3.9, 3.10).
 *
 * The mapper only turns words into ids; it never decides whether an action is
 * possible or dangerous — that belongs to `Engine::applyTurn` (Requirement 3.9)
 * — and it assigns no action codes A1–N1 (Requirement 3.10).
 *
 * Classification (Requirement 3.3):
 *   - a reply that only speaks (no physical action) maps to operation `speak`
 *     carrying its spoken lines;
 *   - a reply where the robot explicitly stays still / does nothing physical
 *     maps to operation `wait`;
 *   - a reply that yields no readable action maps to operation `unknown`, while
 *     any spoken lines and reported-speech items it reads are still returned;
 *   - invalid JSON, or JSON that does not match the Reading shape, is recorded
 *     as `unknown` (Requirement 3.6), while any readable spoken lines and
 *     reported-speech items are still carried.
 *
 * The Reading shape this mapper accepts (house-rules-engine Requirement 12.1):
 *   {
 *     "operation":   string|null,   // one of the operation names, or null
 *     "targetId":    string|null,
 *     "targetSpot":  string|null,
 *     "actionQuote": string|null,
 *     "words":       string[],      // spoken lines, exactly as quoted
 *     "reported":    [ { "quote": s, "heardAs": s, "ownForm": s }, ... ]
 *   }
 */
final class ReadingMapper
{
    /**
     * Map the recorder's raw output for one robot into a Reading. The raw JSON
     * and the recorder prompt version are carried verbatim onto the Reading so
     * they can be stored (Requirement 8.5).
     */
    public function map(string $robotId, string $rawJson, string $promptVersion): Reading
    {
        // Recorders sometimes wrap the JSON object in prose or a Markdown code
        // fence (```json … ```), so decoding the whole text fails. Pull out the
        // first complete top-level `{…}` object before decoding; the ORIGINAL
        // raw text is still stored on the Reading (Requirement 8.5), so only
        // WHAT is handed to json_decode changes, not what is stored.
        $decoded = $this->decodeFirstJsonObject($rawJson);

        // No complete balanced object found, or JSON that is not an object →
        // unknown (Req 3.6). No words or reported items can be read from a
        // non-array shape. This is the "no JSON object at all" case.
        if ($decoded === null) {
            return $this->unknownReading($robotId, [], [], $rawJson, $promptVersion);
        }

        // Spoken lines and reported-speech items are read first, so they can be
        // carried even onto an `unknown` action (Requirement 3.3).
        $words = $this->readWords($decoded);
        $reported = $this->readReported($decoded);

        $operation = $decoded['operation'] ?? null;
        $targetId = $this->nullableString($decoded, 'targetId');
        $targetSpot = $this->nullableString($decoded, 'targetSpot');
        $actionQuote = $this->nullableString($decoded, 'actionQuote');

        // An operation that is present but not a recognised name does not match
        // the Reading shape → unknown, keeping any readable words/reported.
        if ($operation !== null && !$this->isKnownOperation($operation)) {
            return $this->unknownReading($robotId, $words, $reported, $rawJson, $promptVersion);
        }

        // Speak-only: no physical operation but spoken lines present →
        // operation `speak` carrying the spoken lines (Requirement 3.3).
        //
        // The actionQuote is the first spoken line. Each spoken line is copied
        // verbatim from the reply (recorder rule 5), so the first line is an
        // exact substring of the reply, which lets EvidenceCheck::verify keep
        // the `speak` operation instead of turning it into `unknown`. Without a
        // non-null, quote-backed actionQuote the evidence check would discard a
        // valid speak-only reading.
        if (($operation === null || $operation === Operation::speak->value) && $words !== []) {
            $action = new Action(
                robotId: $robotId,
                operation: Operation::speak->value,
                targetId: null,
                targetSpot: null,
                words: $words,
                actionQuote: $words[0],
                reported: $reported,
            );

            return new Reading($action, false, $rawJson, $promptVersion);
        }

        // Explicit stay-still / do-nothing → operation `wait` (Requirement 3.3).
        if ($operation === Operation::wait->value) {
            $action = new Action(
                robotId: $robotId,
                operation: Operation::wait->value,
                targetId: null,
                targetSpot: null,
                words: $words,
                actionQuote: $actionQuote,
                reported: $reported,
            );

            return new Reading($action, false, $rawJson, $promptVersion);
        }

        // No readable action of any kind → unknown, still carrying any spoken
        // lines and reported-speech items (Requirement 3.3).
        if ($operation === null || $operation === Operation::unknown->value) {
            return $this->unknownReading($robotId, $words, $reported, $rawJson, $promptVersion);
        }

        // Otherwise map the first physical action mechanically. The mapper does
        // not judge whether it is possible (Requirement 3.9).
        $action = new Action(
            robotId: $robotId,
            operation: $operation,
            targetId: $targetId,
            targetSpot: $targetSpot,
            words: $words,
            actionQuote: $actionQuote,
            reported: $reported,
        );

        return new Reading($action, false, $rawJson, $promptVersion);
    }

    /**
     * Whether the recorder text carries a decodable JSON object: there is a
     * first complete balanced top-level `{…}` object AND it decodes to an
     * array. Shares {@see decodeFirstJsonObject} with {@see map}, so the two
     * always agree on "was there a decodable JSON object". The recorder client
     * uses this to choose a `no_action` failure reason (a valid-JSON reading
     * with nothing to read) over `unreadable` (no JSON object at all).
     */
    public function hasJsonObject(string $rawText): bool
    {
        return $this->decodeFirstJsonObject($rawText) !== null;
    }

    /**
     * Pull the first complete top-level `{…}` object out of the raw text and
     * decode it, returning the decoded array, or null when there is no complete
     * object or it does not decode to an array. Both {@see map} and
     * {@see hasJsonObject} go through here so they stay consistent.
     *
     * @return array<mixed>|null
     */
    private function decodeFirstJsonObject(string $text): ?array
    {
        $candidate = $this->firstJsonObject($text);
        if ($candidate === null) {
            return null;
        }

        $decoded = json_decode($candidate, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Extract the first complete top-level JSON object `{…}` from arbitrary
     * recorder text, so prose or a Markdown code fence around the JSON does
     * not break decoding (Requirement 3.6).
     *
     * The scan is string- and brace-aware: it finds the first `{`, then tracks
     * brace depth, but only counts braces that are outside a JSON string. A `"`
     * toggles in/out of a string, and a `\` inside a string escapes the next
     * character (so `\"` does not end the string and `\\` is a literal
     * backslash). When depth returns to 0 the first complete object is
     * returned. A code fence or prose before/after the object is skipped
     * naturally. Returns null when no balanced object is found.
     */
    private function firstJsonObject(string $text): ?string
    {
        $length = strlen($text);
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escaped) {
                    // This character is escaped; consume it literally.
                    $escaped = false;
                } elseif ($char === '\\') {
                    // Backslash escapes the next character inside a string.
                    $escaped = true;
                } elseif ($char === '"') {
                    // Unescaped quote closes the string.
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    // Depth back to 0: first complete top-level object found.
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        // Ran off the end without balancing: no complete object.
        return null;
    }

    /**
     * Build an `unknown` Reading that still carries any readable spoken lines
     * and reported-speech items (Requirements 3.3, 3.6).
     *
     * @param string[]         $words
     * @param ReportedSpeech[] $reported
     */
    private function unknownReading(string $robotId, array $words, array $reported, string $rawJson, string $promptVersion): Reading
    {
        $action = new Action(
            robotId: $robotId,
            operation: Operation::unknown->value,
            targetId: null,
            targetSpot: null,
            words: $words,
            actionQuote: null,
            reported: $reported,
        );

        return new Reading($action, true, $rawJson, $promptVersion);
    }

    /**
     * Read the spoken lines array, keeping only string entries.
     *
     * @param array<mixed> $decoded
     * @return string[]
     */
    private function readWords(array $decoded): array
    {
        $raw = $decoded['words'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $words = [];
        foreach ($raw as $line) {
            if (is_string($line)) {
                $words[] = $line;
            }
        }

        return $words;
    }

    /**
     * Read the reported-speech items, keeping only well-shaped entries
     * (`quote`, `heardAs`, `ownForm` all strings).
     *
     * @param array<mixed> $decoded
     * @return ReportedSpeech[]
     */
    private function readReported(array $decoded): array
    {
        $raw = $decoded['reported'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $reported = [];
        foreach ($raw as $item) {
            if (
                is_array($item)
                && isset($item['quote'], $item['heardAs'], $item['ownForm'])
                && is_string($item['quote'])
                && is_string($item['heardAs'])
                && is_string($item['ownForm'])
            ) {
                $reported[] = new ReportedSpeech($item['quote'], $item['heardAs'], $item['ownForm']);
            }
        }

        return $reported;
    }

    /**
     * Whether a value is a recognised operation name (any Operation enum value,
     * including `wait`, `speak`, and `unknown`).
     */
    private function isKnownOperation(mixed $operation): bool
    {
        return is_string($operation) && Operation::tryFrom($operation) !== null;
    }

    /**
     * Read a nullable string field; a non-string present value becomes null.
     *
     * @param array<mixed> $decoded
     */
    private function nullableString(array $decoded, string $key): ?string
    {
        $value = $decoded[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
