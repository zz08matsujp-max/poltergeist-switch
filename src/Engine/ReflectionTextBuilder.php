<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine;

use PoltergeistSwitch\Engine\Enums\Disaster;
use PoltergeistSwitch\Engine\Enums\PowerState;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\Values\Outcome;
use PoltergeistSwitch\Engine\Values\TurnRecord;
use PoltergeistSwitch\Memory\Values\MemorySnapshot;

/**
 * Builds, for one robot and one ended episode, the fact-only Reflection_Text
 * the robot reads before the reflection question (Requirement 1).
 *
 * The builder is pure: it makes no AI call, no HTTP call, and no database or
 * external-memory call (Req 1.1). It renders only the recorded facts of the
 * episode — what the robot did, said, heard, and saw — from the stored
 * TurnRecords, never from the robot's own reply strings, thoughts, judgement
 * data, or resident meter values (Req 1.5, Req 1.11).
 *
 * It reuses SituationTextGenerator so the fixed body and house sections, the
 * "Right now:" paragraph, and each turn's shaking paragraph are produced by the
 * one piece of code that already produces them in turn texts (the design's
 * "reuse, not duplication" mandate). The reflection walks the episode forwards
 * and heads every event block with the elapsed time counting UP from the first
 * shaking turn, distinct from the turn texts' backward countdown.
 *
 * Section order (Req 1.2), each separated by a blank line:
 *   fixed body
 *   fixed house
 *   [memories block]            — only when the snapshot is non-empty (Req 1.7)
 *   lead-in line
 *   one event block per played turn, oldest first
 *   "Right now:" paragraph for the world after the last turn
 *   (blank line)
 *   the reflection question, word for word (Req 1.10)
 *
 * Requirements: Req 1.1–1.11
 */
final class ReflectionTextBuilder
{
    /**
     * Reflection-text version (Req 1.8, Req 1.9). A plain PHP 8.2 class
     * constant — NOT a typed class constant (those arrived in PHP 8.3) —
     * identifying the fixed body/house text, the lead-in, the event-block
     * forms, and the question wording. BUMP THIS to the next dated string
     * whenever any of those change; the golden test on the literal guards
     * against silent drift. Kept at most 32 characters to fit the reflection
     * prompt's version column.
     */
    public const REFLECTION_TEXT_VERSION = 'reflection-text-2026-11-09d';

    /**
     * Heat-wave reflection-text version (heat-wave Requirement 7.4), distinct
     * from the earthquake REFLECTION_TEXT_VERSION above, which is left
     * byte-for-byte unchanged. A plain PHP 8.2 class constant (NOT a typed
     * class constant — those arrived in PHP 8.3) identifying the heat-wave
     * fixed body/house text, the heat lead-in, the minute count-up headings,
     * the heat event-block forms, and the (unchanged) question wording.
     *
     * BUMP THIS to the next dated string whenever the heat-wave reflection
     * fixed text, the lead-in, the minute-heading forms, an event-block form,
     * or the question wording changes (Requirement 7.4); the heat version
     * golden test on the literal guards against silent drift. Kept at most 32
     * characters to fit the reflection prompt's version column.
     */
    public const REFLECTION_TEXT_VERSION_HEAT = 'reflection-text-heat-2026-11-10c';

    /**
     * The single sentence that introduces the turn-by-turn recap (Req 1.2). It
     * makes no claim that the shaking stopped, because an episode can end at the
     * turn limit while the shaking is still described. Engine-authored,
     * fact-only, and banned-word clean.
     */
    private const LEAD_IN = 'Here is what happened after the shaking began:';

    /**
     * The heat-wave lead-in, mirroring LEAD_IN with the heat wave in place of
     * the shaking (heat-wave Requirement 7.1). Engine-authored, fact-only, and
     * banned-word clean — it states the plain fact that the heat was building,
     * with no danger word, no advice, and no judgement.
     */
    private const LEAD_IN_HEAT = 'Here is what happened as the heat went on:';

    /** The reflection question, exactly as decided by the maintainer (Req 1.10). */
    private const QUESTION = 'Is there anything from what happened this time that you want to remember?';

    /**
     * The neutral line shown for a played turn whose event block would
     * otherwise be empty, so the elapsed-time count-up stays unbroken (Req 1.3).
     * Engine-authored and banned-word clean.
     */
    private const NEUTRAL_LINE = 'Nothing happened that you noticed.';

    /**
     * The Appendix A heading for the memories block (Req 1.7, shared with the
     * turn-text Memories_Block form of Requirement 6).
     */
    private const MEMORIES_HEADING =
        'Your memories (what you wrote down after an earlier disaster in this house):';

    /** Heat-wave heading: the memories are from an earlier day. */
    private const MEMORIES_HEADING_HEAT =
        'Your memories (what you wrote down on an earlier day, after an earlier disaster in this house):';

    /** Earthquake turn offsets on which the house is shaking (house-rules §4). */
    private const SHAKING_OFFSETS = [1, 2, 3, 4, 12];

    /** Earthquake offset on which the power fails and the lights go out. */
    private const POWER_OUTAGE_OFFSET = 2;

    public function __construct(
        private SituationTextGenerator $textGen,
    ) {
    }

    /**
     * Build the fact-only Reflection_Text for one robot and one ended episode.
     *
     * @param string         $robotId            'robot_a' or 'robot_b'
     * @param TurnRecord[]    $records            the episode's played turns, first
     *                                            played turn to last, oldest first
     * @param int             $earthquakeStartTurn always 0 in the implemented loop;
     *                                            the first played/shaking turn is
     *                                            turn 1 = offset 1, so headings count
     *                                            up from turn 1 (k = 0)
     * @param WorldModel      $finalWorld         the world after the last turn, for
     *                                            the "Right now:" paragraph
     * @param MemorySnapshot  $snapshot           this robot's memories in this episode
     *                                            (may be empty)
     */
    public function build(
        string $robotId,
        array $records,
        int $earthquakeStartTurn,
        WorldModel $finalWorld,
        MemorySnapshot $snapshot,
    ): string {
        // Heat-wave reflection runs only for a heat-wave world; the earthquake
        // path below is byte-for-byte unchanged (heat-wave Requirement 1.7).
        if ($finalWorld->disaster === Disaster::heat_wave) {
            return $this->buildHeat($robotId, $records, $earthquakeStartTurn, $finalWorld, $snapshot);
        }

        [$fixedBody, $fixedHouse] = $this->fixedBodyAndHouse($finalWorld);

        $sections = [];
        $sections[] = $fixedBody;
        $sections[] = $fixedHouse;

        if (!$snapshot->isEmpty()) {
            $sections[] = $this->memoriesBlock($snapshot, false);
        }

        $sections[] = self::LEAD_IN;

        foreach (array_values($records) as $record) {
            $sections[] = $this->eventBlock($robotId, $record, $earthquakeStartTurn);
        }

        $sections[] = $this->rightNowParagraph($robotId, $finalWorld);
        $sections[] = self::QUESTION;

        return implode("\n\n", $sections);
    }

    /**
     * Build the heat-wave Reflection_Text for one robot and one ended episode
     * (heat-wave Requirement 7). Same section order and structure as the
     * earthquake reflection (Requirement 7.1): fixed body, fixed house,
     * [memories block], the heat lead-in, one event block per played turn with
     * minute count-up headings, the final "Right now:" paragraph, and the
     * exact unchanged question (Requirement 7.2). Two differences from the
     * earthquake path:
     *   - the lead-in is LEAD_IN_HEAT and the event-block headings count up in
     *     5-minute steps ("{a} to {b} minutes after the heat set in:") in place
     *     of the 15-second headings (Requirement 7.1);
     *   - a heat turn has no shaking paragraph, so an event block carries only
     *     the man's line heard and the acted items (own and the other robot's
     *     under rule A); a block with no such lines shows the neutral line.
     *
     * The fixed body, the fixed house, and the "Right now:" paragraph are
     * produced by SituationTextGenerator (which takes its own heat path for a
     * heat-wave world and runs the banned-word check on its engine-authored
     * parts, Requirement 7.3); the lead-in, the headings, the neutral line, and
     * the question are the builder's own fixed, banned-word-clean literals. The
     * memories and the robots' own quoted words are exempt, exactly as on the
     * earthquake path.
     *
     * @param TurnRecord[]    $records    the episode's played turns, oldest first
     * @param int             $startTurn  the quiet-turn offset (0 in the loop), so
     *                                     the first played turn heads at k = 0
     * @param WorldModel      $finalWorld the heat-wave world after the last turn
     * @param MemorySnapshot  $snapshot   this robot's memories in this episode
     */
    private function buildHeat(
        string $robotId,
        array $records,
        int $startTurn,
        WorldModel $finalWorld,
        MemorySnapshot $snapshot,
    ): string {
        [$fixedBody, $fixedHouse] = $this->fixedBodyAndHouse($finalWorld);

        $sections = [];
        $sections[] = $fixedBody;
        $sections[] = $fixedHouse;

        if (!$snapshot->isEmpty()) {
            $sections[] = $this->memoriesBlock($snapshot, true);
        }

        $sections[] = self::LEAD_IN_HEAT;

        // The minutes-per-turn heat clock lives in EpisodeConfig, read off the
        // final world, so no step literal lives in the builder.
        $minutesPerTurn = $finalWorld->heatMinutesPerTurn();
        foreach (array_values($records) as $record) {
            $sections[] = $this->heatEventBlock($robotId, $record, $startTurn, $minutesPerTurn);
        }

        $sections[] = $this->rightNowParagraph($robotId, $finalWorld);
        $sections[] = self::QUESTION;

        return implode("\n\n", $sections);
    }

    /**
     * The stored Reflection_Text_Version for the given world (Req 1.8). A
     * heat-wave world carries the distinct heat version (heat-wave Requirement
     * 7.4); every other world carries the unchanged earthquake version.
     */
    public function versionFor(WorldModel $world): string
    {
        return $world->disaster === Disaster::heat_wave
            ? self::REFLECTION_TEXT_VERSION_HEAT
            : self::REFLECTION_TEXT_VERSION;
    }

    /** The stored earthquake Reflection_Text_Version (Req 1.8). */
    public function version(): string
    {
        return self::REFLECTION_TEXT_VERSION;
    }

    /** The stored heat-wave Reflection_Text_Version (heat-wave Req 7.4). */
    public function heatVersion(): string
    {
        return self::REFLECTION_TEXT_VERSION_HEAT;
    }

    /**
     * The fixed body and fixed house sections, taken from the one place that
     * owns them: SituationTextGenerator. Rather than copy the constants, we ask
     * the generator for a full turn text and keep its first two sections, which
     * are always the fixed body and the fixed house in that order.
     *
     * @return array{0:string,1:string} [fixedBody, fixedHouse]
     */
    private function fixedBodyAndHouse(WorldModel $finalWorld): array
    {
        $full = $this->textGen->generate('robot_a', $finalWorld, []);
        $paragraphs = explode("\n\n", $full);

        return [$paragraphs[0], $paragraphs[1]];
    }

    /**
     * Render the robot's own words as the Appendix A memories block (Req 1.7):
     * the fixed heading, then each memory body in its own triple-quote block,
     * oldest first, verbatim. The bodies are the robot's own words and are
     * exempt from the banned-word rule (Req 1.6).
     */
    private function memoriesBlock(MemorySnapshot $snapshot, bool $heat): string
    {
        $parts = [$heat ? self::MEMORIES_HEADING_HEAT : self::MEMORIES_HEADING];
        foreach ($snapshot->bodies as $body) {
            $parts[] = '"""' . "\n" . $body . "\n" . '"""';
        }

        return implode("\n\n", $parts);
    }

    /**
     * Build one turn's event block (Req 1.3, Req 1.4): the count-up heading,
     * then the perceived items (the house events, then the man's line), then the
     * acted items (own action result and speech, then the other robot's under
     * rule A). An empty block shows the neutral line so the count-up is
     * unbroken. The static room-object list is never repeated here (Req 1.3).
     */
    private function eventBlock(string $robotId, TurnRecord $record, int $earthquakeStartTurn): string
    {
        $heading = $this->blockHeading($record->turnNumber, $earthquakeStartTurn);

        $lines = array_merge(
            $this->perceivedLines($robotId, $record, $earthquakeStartTurn),
            $this->actedLines($robotId, $record),
        );

        if ($lines === []) {
            $lines[] = self::NEUTRAL_LINE;
        }

        return $heading . "\n" . implode("\n", $lines);
    }

    /**
     * The forward count-up heading (Req 1.4). With earthquakeStartTurn = 0 the
     * first played/shaking turn is turn 1 = offset 1 (k = 0), so turn 1 reads
     * "0 to 15 seconds after the shaking began:", turn 2 "15 to 30 …", and so
     * on, in 15-second steps (the earthquake's 15 s per turn).
     */
    private function blockHeading(int $turnNumber, int $earthquakeStartTurn): string
    {
        $k = $turnNumber - $earthquakeStartTurn - 1;
        if ($k < 0) {
            $k = 0;
        }

        $from = 15 * $k;
        $to   = 15 * ($k + 1);

        return "{$from} to {$to} seconds after the shaking began:";
    }

    /**
     * Build one heat-wave turn's event block (heat-wave Requirement 7.1): the
     * minute count-up heading, then the man's line heard this turn (a heat turn
     * has no shaking paragraph, so there is no house-events line), then the
     * acted items — the robot's own action result and speech, then the other
     * robot's under rule A. An otherwise-empty block shows the neutral line so
     * the count-up stays unbroken. The static room-object list is never
     * repeated here (the heat "Right now:" owns it), exactly as the earthquake
     * block.
     */
    private function heatEventBlock(
        string $robotId,
        TurnRecord $record,
        int $startTurn,
        int $minutesPerTurn,
    ): string {
        $heading = $this->heatBlockHeading($record->turnNumber, $startTurn, $minutesPerTurn);

        $lines = [];

        // Perceived: the man's line this turn, rendered exactly as the
        // earthquake reflection renders it (same-room "shouts" vs heard form).
        $manLine = $this->manLine($robotId, $record);
        if ($manLine !== null) {
            $lines[] = $manLine;
        }

        // Acted: own action/speech then the other robot's under rule A.
        $lines = array_merge($lines, $this->actedLines($robotId, $record));

        if ($lines === []) {
            $lines[] = self::NEUTRAL_LINE;
        }

        return $heading . "\n" . implode("\n", $lines);
    }

    /**
     * The heat-wave forward count-up heading (heat-wave Requirement 7.1),
     * mirroring blockHeading() with minutes at the 5-minute heat clock in place
     * of 15-second seconds. With startTurn = 0 the first played turn is turn 1
     * (k = 0), so turn 1 reads "0 to 5 minutes after the heat set in:", turn 2
     * "5 to 10 …", and so on, in minutesPerTurn steps read from EpisodeConfig
     * (no step literal here).
     */
    private function heatBlockHeading(int $turnNumber, int $startTurn, int $minutesPerTurn): string
    {
        $k = $turnNumber - $startTurn - 1;
        if ($k < 0) {
            $k = 0;
        }

        $from = $minutesPerTurn * $k;
        $to   = $minutesPerTurn * ($k + 1);

        return "{$from} to {$to} minutes after the heat set in:";
    }

    /**
     * The perceived lines for one turn (Req 1.3): first the house events
     * rendered as the turn text's shaking paragraph (Req 9.14), then the man's
     * line rendered per Req 9.10–9.11 — the order of the turn texts.
     *
     * @return string[]
     */
    private function perceivedLines(string $robotId, TurnRecord $record, int $earthquakeStartTurn): array
    {
        $lines = [];

        // House events first, then the man's line: the same order as the turn
        // texts, where the shaking paragraph ends with the man's shout
        // (maintainer's review after the first live reflection, 2026-11-09).
        $house = $this->houseEvents($robotId, $record, $earthquakeStartTurn);
        if ($house !== '') {
            $lines[] = $house;
        }

        $manLine = $this->manLine($robotId, $record);
        if ($manLine !== null) {
            $lines[] = $manLine;
        }

        return $lines;
    }

    /**
     * The man's line the robot heard this turn, rendered as in Req 9.10–9.11:
     * "The man shouts, "…"" when the man was in the robot's room,
     * "You hear the man shout, "…"" otherwise. Null when there was no line.
     * The man's room is read from the stored snapshot's resident state; when it
     * is unavailable the line is rendered as heard from elsewhere.
     */
    private function manLine(string $robotId, TurnRecord $record): ?string
    {
        $line = $record->residentLine;
        if ($line === null || $line === '') {
            return null;
        }

        $robotRoom = $record->roomsAtStart[$robotId] ?? null;
        $manRoom   = null;
        $resident  = $record->snapshot->residentState ?? null;
        if ($resident instanceof ResidentState) {
            $manRoom = $resident->room;
        }

        return ($manRoom !== null && $manRoom === $robotRoom)
            ? 'The man shouts, "' . $line . '"'
            : 'You hear the man shout, "' . $line . '"';
    }

    /**
     * The house events the robot perceived this turn (Req 9.14): the shaking
     * starting/continuing/stopping, the room shake text, the boiling pot in the
     * kitchen, and the lights going out. Produced by the shaking paragraph of
     * SituationTextGenerator so the wording matches the turn texts exactly; the
     * resident's line is suppressed in that render because it is placed right
     * after it as the man's line. Returns '' when there was no shaking event.
     */
    private function houseEvents(string $robotId, TurnRecord $record, int $earthquakeStartTurn): string
    {
        $offset = $record->turnNumber - $earthquakeStartTurn;
        $isShaking   = in_array($offset, self::SHAKING_OFFSETS, true);
        $wasShaking  = in_array($offset - 1, self::SHAKING_OFFSETS, true);

        // No shaking event this turn and none the previous turn: no house line.
        if (!$isShaking && !$wasShaking) {
            return '';
        }

        $view = $this->turnView($robotId, $record, $offset, $isShaking, $wasShaking);
        $full = $this->textGen->generate($robotId, $view, []);

        return $this->extractShakingParagraph($full);
    }

    /**
     * Pull the shaking paragraph out of a full turn text. It is the paragraph
     * immediately after the "Right now:" paragraph (the turn text places the
     * shaking paragraph there). Returns '' when there is none.
     */
    private function extractShakingParagraph(string $full): string
    {
        $paragraphs = explode("\n\n", $full);
        foreach ($paragraphs as $i => $para) {
            if (str_starts_with($para, 'Right now:')) {
                return $paragraphs[$i + 1] ?? '';
            }
        }

        return '';
    }

    /**
     * The acted lines for one turn (Req 1.3), in the fixed order of Req 9.16:
     * the robot's own action result, each line it spoke, each own reported-
     * speech item, then — under rule A — the other robot's action, its spoken
     * lines, and its reported-speech items (heard in every room).
     *
     * @return string[]
     */
    private function actedLines(string $robotId, TurnRecord $record): array
    {
        $other = $this->otherRobotId($robotId);
        $shared = $this->robotsSharedRoom($record, $robotId, $other);

        /** @var Action|null $selfAction */
        $selfAction = $record->robotActions[$robotId] ?? null;
        /** @var Action|null $otherAction */
        $otherAction = $record->robotActions[$other] ?? null;
        /** @var Outcome|null $selfOutcome */
        $selfOutcome = $record->outcomes[$robotId] ?? null;
        /** @var Outcome|null $otherOutcome */
        $otherOutcome = $record->outcomes[$other] ?? null;

        $lines = [];

        // 1. the robot's own action result (omitted when the action is unknown).
        if ($selfAction !== null && $selfAction->operation !== 'unknown'
            && $selfOutcome !== null
            && $selfOutcome->whatHappenedText !== null
            && $selfOutcome->whatHappenedText !== ''
        ) {
            $lines[] = $selfOutcome->whatHappenedText;
        }

        // 2. each line the robot spoke; 3. each own reported-speech item.
        if ($selfAction !== null) {
            foreach ($selfAction->words as $line) {
                $lines[] = 'You said, "' . $line . '"';
            }
            foreach ($selfAction->reported as $reported) {
                $lines[] = 'You ' . $reported->ownForm . '.';
            }
        }

        // 4. the other robot's action: only under rule A and when not unknown.
        if ($shared
            && $otherAction !== null && $otherAction->operation !== 'unknown'
            && $otherOutcome !== null
            && $otherOutcome->seenByOtherText !== null
            && $otherOutcome->seenByOtherText !== ''
        ) {
            $lines[] = $otherOutcome->seenByOtherText;
        }

        if ($otherAction !== null) {
            // 5. each line the other robot spoke; phrasing depends on rule A.
            foreach ($otherAction->words as $line) {
                $lines[] = $shared
                    ? 'The other robot said, "' . $line . '"'
                    : 'You heard the other robot say, "' . $line . '"';
            }
            // 6. each reported-speech item of the other robot, in every room.
            foreach ($otherAction->reported as $reported) {
                $lines[] = 'You heard the other robot ' . $reported->heardAs . '.';
            }
        }

        return $lines;
    }

    /**
     * The "Right now:" paragraph for the world after the last turn, produced by
     * the same SituationTextGenerator machinery used in turns (Req 1.2).
     */
    private function rightNowParagraph(string $robotId, WorldModel $finalWorld): string
    {
        $full = $this->textGen->generate($robotId, $finalWorld, []);
        $paragraphs = explode("\n\n", $full);
        foreach ($paragraphs as $para) {
            if (str_starts_with($para, 'Right now:')) {
                return $para;
            }
        }

        // Should never happen: every turn text carries a "Right now:" paragraph.
        return 'Right now:';
    }

    /**
     * Rule A: the two robots shared a room this turn when they were in the same
     * room at the START or at the END of the turn (house-rules Req 9.16). The
     * start rooms come from roomsAtStart; the end rooms from the snapshot.
     */
    private function robotsSharedRoom(TurnRecord $record, string $self, string $other): bool
    {
        $startSelf  = $record->roomsAtStart[$self] ?? null;
        $startOther = $record->roomsAtStart[$other] ?? null;
        if ($startSelf !== null && $startSelf === $startOther) {
            return true;
        }

        $snapshot = $record->snapshot;
        $endSelf  = $snapshot->robotStates[$self]->room ?? null;
        $endOther = $snapshot->robotStates[$other]->room ?? null;

        return $endSelf !== null && $endSelf === $endOther;
    }

    /** The id of the robot that is not $robotId. */
    private function otherRobotId(string $robotId): string
    {
        return $robotId === 'robot_a' ? 'robot_b' : 'robot_a';
    }

    /**
     * Build a per-turn SituationView over one TurnRecord's snapshot so the
     * shaking paragraph can be rendered by SituationTextGenerator exactly as it
     * was in the turn text. The robot's room is its room at the start of the
     * turn (what it perceived); the earthquake flags are derived from the
     * offset; the resident line is suppressed so the shaking paragraph carries
     * no man line (that is rendered first as the perceived man's line).
     */
    private function turnView(
        string $robotId,
        TurnRecord $record,
        int $offset,
        bool $isShaking,
        bool $wasShaking,
    ): SituationView {
        $snapshot   = $record->snapshot;
        $robotRoom  = $record->roomsAtStart[$robotId] ?? ($snapshot->robotStates[$robotId]->room ?? 'living_room');
        $other      = $this->otherRobotId($robotId);
        $otherRoom  = $record->roomsAtStart[$other] ?? ($snapshot->robotStates[$other]->room ?? 'living_room');

        $resident = $snapshot->residentState ?? null;
        $manRoom  = ($resident instanceof ResidentState) ? $resident->room : 'living_room';
        $manAct   = ($resident instanceof ResidentState) ? $resident->activity : 'sitting_at_table';
        $manSpot  = ($resident instanceof ResidentState) ? $resident->spot : null;

        $house = $snapshot->houseState ?? null;
        $time  = ($house instanceof HouseState) ? $house->timeOfDay : 'afternoon';
        $powerOut = ($house instanceof HouseState) && $house->power === PowerState::outage;

        $potBoiling = isset($snapshot->objects['pot'])
            && $snapshot->objects['pot']->room === 'kitchen'
            && (($snapshot->objects['gas_stove']->on ?? false));

        $lightsOutThisTurn = $offset === self::POWER_OUTAGE_OFFSET;

        return new class(
            $robotId,
            $other,
            $robotRoom,
            $otherRoom,
            $manRoom,
            $manAct,
            $manSpot,
            $time,
            $powerOut,
            $potBoiling,
            $isShaking,
            $wasShaking,
            $lightsOutThisTurn,
        ) implements SituationView {
            public function __construct(
                private string $selfId,
                private string $otherId,
                private string $selfRoom,
                private string $otherRoom,
                private string $manRoom,
                private string $manAct,
                private ?string $manSpot,
                private string $time,
                private bool $powerOut,
                private bool $potBoiling,
                private bool $shaking,
                private bool $wasShaking,
                private bool $lightsOut,
            ) {
            }

            public function timeOfDay(): string
            {
                return $this->time;
            }

            public function robotRoom(string $robotId): string
            {
                return $robotId === $this->selfId ? $this->selfRoom : $this->otherRoom;
            }

            public function residentRoom(): string
            {
                return $this->manRoom;
            }

            public function residentActivity(): string
            {
                return $this->manAct;
            }

            public function residentSpot(): ?string
            {
                return $this->manSpot;
            }

            public function isRoomDark(string $roomKey): bool
            {
                // Darkness only affects the "Right now:" object list, which the
                // reflection does not render per turn; keep the shaking
                // paragraph's room shake text visible as the turn text does.
                return false;
            }

            /** @return string[] */
            public function visibleObjectPhrases(string $roomKey): array
            {
                // The per-turn object list is never shown in the reflection, so
                // the "Right now:" paragraph of this throwaway render is unused.
                return [];
            }

            public function isShaking(): bool
            {
                return $this->shaking;
            }

            public function isPotBoiling(): bool
            {
                return $this->potBoiling;
            }

            public function wasShakingPreviousTurn(): bool
            {
                return $this->wasShaking;
            }

            public function didLightsGoOutThisTurn(): bool
            {
                return $this->lightsOut;
            }

            public function residentSpeaksThisTurn(): bool
            {
                // Suppressed: the man's line is rendered first, separately.
                return false;
            }

            public function residentLine(): ?string
            {
                return null;
            }
        };
    }
}
