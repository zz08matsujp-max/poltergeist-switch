<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop;

use PoltergeistSwitch\TurnLoop\Http\ApiError;
use PoltergeistSwitch\TurnLoop\Repository\EpisodeRepository;

/**
 * The ended-episode list for the Replay_Screen — `GET /api/episodes?ended=1&limit=20`
 * (screens spec Requirement 11).
 *
 * A plain service with no I/O of its own beyond the repository and the (pure)
 * replay the world backfill performs. It returns only episodes whose `ended`
 * is true, newest-created first (Requirements 11.1, 11.2); each item carries
 * just four fields — id, created date, the number of turns as a non-negative
 * integer, and the starting-situation one-liner (Requirement 11.3) — so no
 * device id, API key, database credential, or AI provider name can leak
 * (Requirement 11.7).
 *
 * The one-liner is derived from turn 0's recorded World_State: the time of day
 * plus each robot's and the man's starting room, naming only Amber (robot_a)
 * and Jade (robot_b) and using the rooms' display names, e.g.
 *   "Night — Amber in the kitchen, Jade in the bedroom, the man in the living room."
 * When turn 0's world is missing (a pre-existing episode stored before the
 * screens spec added `world_json`), it is produced first by the same one-time
 * backfill the episode-view read path uses (Requirement 8.5 / 10.7), never by
 * calling AI.
 *
 * `limit` is validated here, before any query (Requirements 11.4–11.6): an
 * integer 1–100 inclusive caps the count; absent → the default of 20; not an
 * integer, `< 1`, or `> 100` → an out-of-range {@see ApiError} and no list.
 *
 * PHP 8.2 only.
 */
final class EpisodeListService
{
    /** The default cap when no limit is supplied (Requirement 11.5). */
    private const DEFAULT_LIMIT = 20;

    /** The inclusive bounds a supplied limit must fall within (Requirement 11.4). */
    private const MIN_LIMIT = 1;
    private const MAX_LIMIT = 100;

    /**
     * The engine room keys to their display names for the one-liner. Mirrors
     * the display names in {@see \PoltergeistSwitch\Engine\WorldModel::initRooms}.
     * Keeping a small fixed map here means the service reads only the room KEY
     * from the stored World_State and needs no engine instance; an unknown key
     * falls back to the key itself rather than throwing.
     *
     * @var array<string, string>
     */
    private const ROOM_DISPLAY_NAMES = [
        'living_room' => 'living room',
        'kitchen'     => 'kitchen',
        'bathroom'    => 'bathroom',
        'bedroom'     => 'bedroom',
        'entrance'    => 'entrance',
        'storeroom'   => 'storeroom',
    ];

    public function __construct(
        private readonly EpisodeRepository $repo,
        private readonly WorldStateBackfill $worldBackfill,
    ) {}

    /**
     * Return the ended-episode list. $rawLimit is the raw query value as parsed
     * by the thin handler: a string (e.g. "20"), null when absent, or an int.
     * All validation lives here (Requirements 11.4–11.6), so the handler stays
     * thin.
     *
     * @param mixed $rawLimit the raw `limit` query value, or null when absent
     * @return list<array{id: string, createdDate: string, turns: int, startingSituation: string}>
     *
     * @throws ApiError 'limit_out_of_range' (400) when $rawLimit is present but
     *                  not an integer, is less than 1, or is greater than 100
     *                  (Requirement 11.6); no list is returned.
     */
    public function list(mixed $rawLimit = null): array
    {
        $limit = $this->validateLimit($rawLimit);

        $items = [];
        foreach ($this->repo->listEndedEpisodes($limit) as $episode) {
            // Fill a pre-existing episode's missing per-turn World_State once,
            // by replaying the engine (never AI) — so turn 0's world exists for
            // the one-liner (Requirement 8.5 / 10.7). Already-filled episodes
            // are a no-op. A replay that cannot complete throws a
            // 'world_backfill_failed' ApiError (Requirement 10.8).
            $this->worldBackfill->backfill($episode->id);

            $worldsByTurn = $this->repo->loadTurnWorldJson($episode->id);

            $items[] = [
                'id'                => $episode->id,
                'createdDate'       => $episode->createdAt,
                // The number of turns as a non-negative integer: the count of
                // stored turns for the episode (Requirement 11.3).
                'turns'             => count($worldsByTurn),
                'startingSituation' => $this->startingSituation($worldsByTurn),
            ];
        }

        return $items;
    }

    /**
     * Validate the `limit` query value (Requirements 11.4–11.6). Absent (null)
     * → the default of 20. A value that is an integer 1–100 inclusive is used
     * as-is. Anything else — a non-integer (including a non-numeric or
     * fractional string), `< 1`, or `> 100` — is rejected with an out-of-range
     * error and no list is produced.
     */
    private function validateLimit(mixed $rawLimit): int
    {
        if ($rawLimit === null) {
            return self::DEFAULT_LIMIT;
        }

        $value = $this->asInteger($rawLimit);
        if ($value === null) {
            throw ApiError::outOfRange();
        }

        if ($value < self::MIN_LIMIT || $value > self::MAX_LIMIT) {
            throw ApiError::outOfRange();
        }

        return $value;
    }

    /**
     * Coerce a raw query value to an integer, or null when it is not an exact
     * integer. An int passes through. A string passes only when it is the
     * canonical decimal form of an integer (optional leading '-', digits only):
     * "20" → 20, but "20.0", "2e1", " 20", "0x14", "abc", and "" are all
     * rejected as not an integer (Requirement 11.6). Bools and other types are
     * never integers here.
     */
    private function asInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            // Accept only a plain decimal integer with no surrounding space,
            // fraction, or exponent, so "20" is an integer but "20.0" is not.
            if (preg_match('/^-?\d+$/', $value) === 1) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * Build the starting-situation one-liner from turn 0's recorded
     * World_State (Requirements 11.3, 8.5): the time of day plus Amber's,
     * Jade's, and the man's starting rooms, using room display names and naming
     * no AI company. When turn 0 has no stored world (or it is unreadable), the
     * fields degrade to a safe placeholder rather than throwing, so the list
     * still renders.
     *
     * @param array<int, string|null> $worldsByTurn turnNumber => world_json or null
     */
    private function startingSituation(array $worldsByTurn): string
    {
        $world = $this->decodeTurnZeroWorld($worldsByTurn);

        $timeOfDay = $this->timeOfDayPhrase($world);
        $amberRoom = $this->robotRoomPhrase($world, 'robot_a');
        $jadeRoom  = $this->robotRoomPhrase($world, 'robot_b');
        $manRoom   = $this->manRoomPhrase($world);

        return $timeOfDay
            . ' — Amber in ' . $amberRoom
            . ', Jade in ' . $jadeRoom
            . ', the man in ' . $manRoom . '.';
    }

    /**
     * Decode turn 0's stored `world_json` to a World_State array, or null when
     * no turn 0 world is stored or the stored string is unreadable.
     *
     * @param array<int, string|null> $worldsByTurn
     * @return array<string, mixed>|null
     */
    private function decodeTurnZeroWorld(array $worldsByTurn): ?array
    {
        $json = $worldsByTurn[0] ?? null;
        if (!is_string($json)) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The time-of-day phrase for the one-liner, capitalised (e.g. "Night").
     * A missing or non-string value degrades to "Unknown time".
     *
     * @param array<string, mixed>|null $world
     */
    private function timeOfDayPhrase(?array $world): string
    {
        $timeOfDay = $world['timeOfDay'] ?? null;
        if (!is_string($timeOfDay) || $timeOfDay === '') {
            return 'Unknown time';
        }

        return ucfirst($timeOfDay);
    }

    /**
     * The "the <room>" phrase for a robot's starting room, read from the stored
     * world's `robots.<robotId>.room`. A missing room degrades to "an unknown
     * room".
     *
     * @param array<string, mixed>|null $world
     */
    private function robotRoomPhrase(?array $world, string $robotId): string
    {
        $robots = is_array($world['robots'] ?? null) ? $world['robots'] : [];
        $robot = is_array($robots[$robotId] ?? null) ? $robots[$robotId] : [];

        return $this->roomPhrase($robot['room'] ?? null);
    }

    /**
     * The "the <room>" phrase for the man's starting room, read from the stored
     * world's `man.room`.
     *
     * @param array<string, mixed>|null $world
     */
    private function manRoomPhrase(?array $world): string
    {
        $man = is_array($world['man'] ?? null) ? $world['man'] : [];

        return $this->roomPhrase($man['room'] ?? null);
    }

    /**
     * Map a stored room key to its "the <display name>" phrase. An unknown or
     * missing key degrades to "an unknown room" rather than leaking the raw key
     * or throwing.
     */
    private function roomPhrase(mixed $roomKey): string
    {
        if (!is_string($roomKey) || $roomKey === '') {
            return 'an unknown room';
        }

        $display = self::ROOM_DISPLAY_NAMES[$roomKey] ?? null;
        if ($display === null) {
            return 'an unknown room';
        }

        return 'the ' . $display;
    }
}
