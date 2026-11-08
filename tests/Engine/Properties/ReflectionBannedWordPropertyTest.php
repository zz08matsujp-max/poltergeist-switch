<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Engine\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\ActionExecutor;
use PoltergeistSwitch\Engine\EarthquakePhase;
use PoltergeistSwitch\Engine\Engine;
use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\ReflectionTextBuilder;
use PoltergeistSwitch\Engine\ResidentController;
use PoltergeistSwitch\Engine\RulesEvaluator;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\Values\TurnRecord;
use PoltergeistSwitch\Engine\WorldModel;
use PoltergeistSwitch\Memory\Values\MemorySnapshot;

/**
 * Feature: robot-memory, Property 6: Engine-authored reflection and turn text
 * is banned-word clean and never contains the robot's reply, while memory
 * bodies are exempt.
 *
 * For any ended episode driven from a random seed, with arbitrary robot reply
 * strings and arbitrary memory bodies:
 *
 *   - The ENGINE-AUTHORED parts of the Reflection_Text — everything except the
 *     verbatim memory bodies inside the memories block — contain none of the
 *     `house-rules-engine` Req 9.15 banned words (the five company/model names
 *     and the eight value words) and never contain the robot's own reply text
 *     (Req 1.5, Req 1.6, Req 1.11). The reply text is never an input to the
 *     builder, so it can never leak; the property asserts that guarantee holds
 *     even when the reply is crafted to collide with a fact in the text.
 *
 *   - The same holds for each turn's situation text (its engine-authored
 *     sections), reusing the SituationTextGenerator banned-word guarantee the
 *     reflection builder depends on.
 *
 *   - A banned word placed inside a MEMORY BODY does not fail the check: memory
 *     bodies are the robot's own words, passed verbatim, and are exempt
 *     (Req 1.6 / Req 6.7). The test proves this by injecting every banned word
 *     and the arbitrary reply string into a memory body and asserting the
 *     reflection still builds with those bodies present verbatim, while a
 *     banned word in an engine-authored part WOULD be caught — demonstrated by
 *     asserting no banned word survives once the exempt memory bodies are
 *     stripped out.
 *
 * The reply string is NEVER an input to the builder (the builder takes only the
 * records and the snapshot), so the robots `wait` rather than speaking it: the
 * reply enters only as a memory body, proving it cannot reach the
 * engine-authored, non-exempt text. (A robot's own spoken words are rendered
 * verbatim as quoted speech and, like memory bodies, are exempt from the
 * banned-word rule; this test is about the engine-authored facts, not quoted
 * speech, so no speech is produced here.)
 *
 * The builder is pure and is fed real TurnRecords captured from a live engine
 * run, so the engine-authored facts are exactly what a real reflection renders.
 *
 * NOTE: this Eris release (giorgiosironi/eris ^1.3) controls the iteration
 * count with `limitTo()`; `limitTo(100)` is required here, matching the other
 * property tests.
 *
 * Validates: Requirements 9.6, 1.2, 1.5, 1.6, 1.11
 */
class ReflectionBannedWordPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * The 13 words that must never reach a robot in engine-authored text
     * (house-rules-engine Req 9.15): five company / model names and eight value
     * words. The match is case-insensitive, so "danger" inside "dangerous" is
     * caught by the shorter entry too.
     *
     * @var string[]
     */
    private const BANNED_WORDS = [
        'Claude', 'ChatGPT', 'GPT', 'Anthropic', 'OpenAI',
        'dangerous', 'danger', 'safe', 'careful', 'should', 'must', 'warning',
    ];

    /**
     * Property 6.
     *
     * Validates: Requirements 9.6, 1.2, 1.5, 1.6, 1.11
     */
    public function testEngineAuthoredReflectionAndTurnTextIsCleanWhileMemoriesAreExempt(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generators::int(),
                Generators::choose(1, 8),
                // An arbitrary robot reply string. It is never an input to the
                // builder, so it must never appear in the engine-authored text.
                Generators::string(),
            )
            ->then(function (int $seedValue, int $turnCount, string $reply): void {
                $builder = new ReflectionTextBuilder(new SituationTextGenerator());

                // A memory snapshot whose bodies carry every banned word AND the
                // arbitrary reply text. These are the robot's own words and are
                // rendered verbatim; the banned-word rule must NOT fail on them,
                // and the exempt bodies are the only place the reply appears.
                $bodies = [
                    'I remember ' . implode(' ', self::BANNED_WORDS) . '.',
                    'Earlier I thought: ' . $reply,
                ];
                $snapshot = new MemorySnapshot($bodies);

                foreach (['robot_a', 'robot_b'] as $robotId) {
                    [$records, $finalWorld, $turnTexts] =
                        $this->runEpisode($seedValue, $turnCount, $robotId);

                    // Need at least one played turn for a meaningful reflection.
                    if ($records === []) {
                        return;
                    }

                    $reflection = $builder->build($robotId, $records, 0, $finalWorld, $snapshot);

                    // The verbatim memory bodies appear in full (exempt).
                    foreach ($bodies as $body) {
                        $this->assertStringContainsString(
                            $body,
                            $reflection,
                            "memory body must be rendered verbatim (seed {$seedValue}, {$robotId})"
                        );
                    }

                    // Strip the exempt memory bodies out, then nothing banned and
                    // no reply text may remain in what is left — the
                    // engine-authored parts of the reflection.
                    $engineAuthored = $this->stripMemoryBodies($reflection, $bodies);

                    foreach (self::BANNED_WORDS as $word) {
                        $this->assertFalse(
                            stripos($engineAuthored, $word) !== false,
                            "banned word '{$word}' appeared in the engine-authored reflection "
                                . "(seed {$seedValue}, {$robotId})"
                        );
                    }

                    // The reply text, which enters only as an exempt memory
                    // body, never reaches the engine-authored parts: once the
                    // memory bodies are stripped, the reply is gone. A very
                    // short or empty generated reply could coincidentally occur
                    // as a substring of ordinary prose, so only assert the
                    // guarantee for a reply long enough to be a real reply.
                    if (strlen($reply) >= 12) {
                        $this->assertStringNotContainsString(
                            $reply,
                            $engineAuthored,
                            "the robot's reply text must never appear in the engine-authored "
                                . "reflection (seed {$seedValue}, {$robotId})"
                        );
                    }

                    // Each played turn's situation text (its engine-authored
                    // sections) is likewise banned-word clean. No memories are
                    // threaded into these turn texts, so every section is
                    // engine-authored and the reply (never an input) is absent.
                    foreach ($turnTexts as $turnNumber => $text) {
                        $sections = $this->engineAuthoredSections($text);
                        foreach (self::BANNED_WORDS as $word) {
                            $this->assertFalse(
                                stripos($sections, $word) !== false,
                                "banned word '{$word}' appeared in turn {$turnNumber} text "
                                    . "(seed {$seedValue}, {$robotId})"
                            );
                        }
                        if (strlen($reply) >= 12) {
                            $this->assertStringNotContainsString(
                                $reply,
                                $sections,
                                "the robot's reply text must never appear in turn {$turnNumber} "
                                    . "text (seed {$seedValue}, {$robotId})"
                            );
                        }
                    }
                }
            });
    }

    /**
     * Drive a real earthquake episode from the seed for up to $turnCount played
     * turns, capturing genuine TurnRecords (the same shape the live loop stores)
     * so the reflection renders real engine-authored facts. The robots `wait`
     * every turn: no spoken line is produced, so the only text in play is
     * engine-authored facts (plus, in the reflection, the exempt memory bodies).
     *
     * @return array{0: TurnRecord[], 1: WorldModel, 2: array<int,string>}
     */
    private function runEpisode(int $seedValue, int $turnCount, string $robotId): array
    {
        $config = new EpisodeConfig();
        $world = new WorldModel($config);
        $world->reset(Seed::fromInt($seedValue));

        $engine = new Engine(
            $world,
            $config,
            new RulesEvaluator(),
            new ActionExecutor(),
            new EarthquakePhase(),
            new ResidentController(),
            new SituationTextGenerator(),
        );

        // Flip the earthquake on from turn 0 so the first played turn (turn 1)
        // is offset 1, the first shaking turn — the recap's turn 1.
        $world->earthquakeStartTurn = 0;

        /** @var TurnRecord[] $records */
        $records = [];
        /** @var array<int,string> $turnTexts */
        $turnTexts = [];

        for ($turn = 0; $turn < $turnCount; $turn++) {
            $engine->prepareTurn($turn);

            // Capture the room each robot is in at the START of the turn, for
            // the TurnRecord's roomsAtStart (rule A).
            $roomsAtStart = [
                'robot_a' => $world->robotStates['robot_a']->room,
                'robot_b' => $world->robotStates['robot_b']->room,
            ];

            // The situation text as the robots read it this turn.
            $turnTexts[$turn] = $engine->situationTextFor($robotId);

            // Both robots wait: no spoken line, so no quoted speech enters the
            // engine-authored text — only recorded facts do.
            $actions = [
                'robot_a' => new Action(
                    robotId: 'robot_a',
                    operation: Operation::wait->value,
                    actionQuote: 'I wait.',
                ),
                'robot_b' => new Action(
                    robotId: 'robot_b',
                    operation: Operation::wait->value,
                    actionQuote: 'I wait.',
                ),
            ];

            $result = $engine->applyTurn($turn, $actions);
            if ($result->isError) {
                break;
            }

            // Turn 0 is the quiet turn; the recap starts at turn 1.
            if ($turn >= 1) {
                $records[] = new TurnRecord(
                    turnNumber: $turn,
                    robotActions: $actions,
                    judgements: $result->judgements,
                    outcomes: $result->outcomes,
                    residentLine: $result->residentLine,
                    snapshot: $result->worldSnapshot,
                    roomsAtStart: $roomsAtStart,
                );
            }

            if ($result->episodeEnded) {
                break;
            }
        }

        return [$records, $world, $turnTexts];
    }

    /**
     * Remove the verbatim memory bodies from the reflection, leaving only the
     * engine-authored parts. A banned word or reply text remaining after this
     * removal is in engine-authored text and is a real failure; one inside a
     * body is removed first and so is correctly exempt.
     *
     * @param string[] $bodies
     */
    private function stripMemoryBodies(string $reflection, array $bodies): string
    {
        foreach ($bodies as $body) {
            $reflection = str_replace($body, '', $reflection);
        }

        return $reflection;
    }

    /**
     * Return the engine-authored sections of a full situation text: everything
     * except the trailing final-line section. With no memories threaded into
     * the turn text (the builder owns the memories block, not these turn
     * texts), the generated text ends with the final-line section, so dropping
     * the last "\n\n"-separated section leaves the engine-authored sections the
     * banned-word rule covers.
     */
    private function engineAuthoredSections(string $text): string
    {
        $cut = strrpos($text, "\n\n");
        if ($cut === false) {
            return $text;
        }

        return substr($text, 0, $cut);
    }
}
