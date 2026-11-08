<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop;

use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\Values\ReportedSpeech;

/**
 * Encode/decode helper between an engine {@see Action} and the stored
 * `action_json` payload (Requirements 13.2, 13.3, 11.3).
 *
 * `fromJson` is the decode used by {@see ReplayEngineFactory} to rebuild the
 * EvidenceCheck-verified actions that were passed to `Engine::applyTurn` live,
 * so replay reproduces the same world. `toJson` is the inverse encode used by
 * apply (task 12) to produce `action_json` when it stores an applied turn.
 *
 * The decode contract is deliberately identical to the private
 * decodeAction/unknownAction/stringList/reportedList/nullableString helpers in
 * {@see \PoltergeistSwitch\TurnLoop\Repository\PdoEpisodeRepository} and
 * {@see \PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository}: the same
 * field names and the same unknown-on-malformed behaviour, so replay matches
 * what those repositories produce for the EpisodeView. A missing/malformed
 * field, a non-array JSON payload, or an operation string that is not a valid
 * {@see Operation} enum value decodes to an Action with operation `unknown` and
 * null/empty fields (Requirement 11.3).
 *
 * The JSON shape carries `operation`, `targetId`, `targetSpot`, `words`,
 * `actionQuote`, and `reported` (each reported item as `{quote, heardAs,
 * ownForm}`). It never carries `robotId`: the robot id is implied by the
 * storage key, so it is passed into `fromJson` and dropped by `toJson`.
 */
final class ReplayedAction
{
    /**
     * Decode a stored `action_json` back into an engine Action. The robot id is
     * supplied separately because the Action carries it but it is not part of
     * the per-turn-per-robot JSON payload (Requirements 13.2, 11.3).
     */
    public static function fromJson(string $actionJson, string $robotId): Action
    {
        $decoded = json_decode($actionJson, true);
        if (!is_array($decoded)) {
            return self::unknownAction($robotId);
        }

        $operation = $decoded['operation'] ?? null;
        if (!is_string($operation) || Operation::tryFrom($operation) === null) {
            return self::unknownAction($robotId);
        }

        return new Action(
            robotId: $robotId,
            operation: $operation,
            targetId: self::nullableString($decoded, 'targetId'),
            targetSpot: self::nullableString($decoded, 'targetSpot'),
            words: self::stringList($decoded['words'] ?? null),
            actionQuote: self::nullableString($decoded, 'actionQuote'),
            reported: self::reportedList($decoded['reported'] ?? null),
        );
    }

    /**
     * Encode an engine Action to the stored `action_json` payload used by apply
     * (Requirement 13.3). The robot id is not encoded (it is implied by the
     * storage key); every other field round-trips through {@see fromJson}.
     */
    public static function toJson(Action $action): string
    {
        $reported = [];
        foreach ($action->reported as $item) {
            if ($item instanceof ReportedSpeech) {
                $reported[] = [
                    'quote' => $item->quote,
                    'heardAs' => $item->heardAs,
                    'ownForm' => $item->ownForm,
                ];
            }
        }

        $payload = [
            'operation' => $action->operation,
            'targetId' => $action->targetId,
            'targetSpot' => $action->targetSpot,
            'words' => array_values(array_filter(
                $action->words,
                static fn ($word): bool => is_string($word),
            )),
            'actionQuote' => $action->actionQuote,
            'reported' => $reported,
        ];

        $encoded = json_encode($payload);

        // json_encode can only fail here on malformed UTF-8; fall back to an
        // empty object, which fromJson reads back as operation `unknown`.
        return $encoded === false ? '{}' : $encoded;
    }

    private static function unknownAction(string $robotId): Action
    {
        return new Action(
            robotId: $robotId,
            operation: Operation::unknown->value,
            targetId: null,
            targetSpot: null,
            words: [],
            actionQuote: null,
            reported: [],
        );
    }

    /**
     * @param mixed $raw
     * @return string[]
     */
    private static function stringList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (is_string($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param mixed $raw
     * @return ReportedSpeech[]
     */
    private static function reportedList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (
                is_array($item)
                && isset($item['quote'], $item['heardAs'], $item['ownForm'])
                && is_string($item['quote'])
                && is_string($item['heardAs'])
                && is_string($item['ownForm'])
            ) {
                $out[] = new ReportedSpeech($item['quote'], $item['heardAs'], $item['ownForm']);
            }
        }

        return $out;
    }

    /**
     * @param array<mixed> $decoded
     */
    private static function nullableString(array $decoded, string $key): ?string
    {
        $value = $decoded[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
