<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\ActionExecutor;
use PoltergeistSwitch\Engine\EarthquakePhase;
use PoltergeistSwitch\Engine\Engine;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\ResidentController;
use PoltergeistSwitch\Engine\RulesEvaluator;
use PoltergeistSwitch\Engine\Seed;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Engine\WorldModel;

/**
 * Feature: turn-loop, Property 15: Same world for text and judgement.
 *
 * For a played turn, the earthquake step and resident line that
 * {@see Engine::situationTextFor} describes to each robot are the SAME ones in
 * which {@see Engine::applyTurn} then judges that turn. This is the load-bearing
 * same-world guarantee of Requirement 12.1–12.2 and 12.4: the robot reads the
 * world its action is judged in, so the first earthquake prompt is the first
 * shaking turn and a "still shaking" reading is judged while the world still
 * shakes.
 *
 * The guarantee rests on {@see Engine::prepareTurn} being called once before
 * {@see Engine::situationTextFor} (Requirement 12.4). prepareTurn advances the
 * earthquake world and fixes the resident line for the turn; applyTurn, seeing
 * the turn already prepared, judges that same world without advancing it again.
 *
 * The property drives, for a random earthquake episode and a random run length:
 *   1. prepareTurn($n) — advances the earthquake world and fixes the line;
 *   2. capture the world's earthquake step (shaking / lights-out / falling
 *      objects) and the resident line as the text generator sees them, and
 *      capture both robots' situation text;
 *   3. assert the text reflects that captured step and line (the shaking
 *      paragraph opens as the pilot S0 text, and a spoken line appears exactly
 *      when the resident speaks this turn);
 *   4. applyTurn($n, []) — judges the turn;
 *   5. assert the TurnResult's resident line equals the line the text showed and
 *      the TurnResult's world snapshot carries the same earthquake step the text
 *      showed. Equal line + equal step across every turn is the evidence that
 *      text and judgement share one world.
 *
 * No real provider API is touched: this exercises the pure engine directly
 * (Requirement 14.1). Eris drives the seed and run length with limitTo(100).
 *
 * Requirements: 12.1, 12.2, 12.4, 14.10
 */
final class SameWorldPropertyTest extends TestCase
{
    use TestTrait;

    /** The pilot S0 opening of the first shaking turn (Requirement 12.2). */
    private const SHAKE_OPENING = 'Suddenly the floor begins to shake hard.';

    /** The continued-shaking opening of a later shaking turn. */
    private const STILL_SHAKING = 'The floor is still shaking hard.';

    /** The resident's earthquake-start shout, used when no scripted line is set. */
    private const RESIDENT_SHOUT = "What's happening?!";

    /**
     * Property 15: for every turn of an earthquake episode, the earthquake step
     * and resident line described to each robot equal those judged by applyTurn.
     *
     * Validates: Requirements 12.1, 12.2, 12.4
     */
    public function testTextAndJudgementShareTheSameWorldEachTurn(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generators::int(),
                Generators::choose(1, 13),
            )
            ->then(function (int $seedValue, int $turnCount): void {
                // Two fresh Seed instances from the same int: a Seed's PRNG is
                // consumed by reset(), so each world needs its own Seed to
                // derive the identical start layout. Here we only need one
                // engine, but keep the single-engine build simple.
                $seed = Seed::fromInt($seedValue);
                $engine = $this->buildEngine($seed, $world);

                // Flip the earthquake on from turn 0 so the shaking/power steps
                // actually move and the first played turn is earthquake offset 1,
                // the first shaking turn (Requirement 12.2).
                $world->earthquakeStartTurn = 0;

                for ($turn = 0; $turn < $turnCount; $turn++) {
                    // 1. Prepare the turn: advance the earthquake world and fix
                    //    the resident line for this turn (Requirement 12.4).
                    $engine->prepareTurn($turn);

                    // 2. Capture the world's earthquake step and resident line
                    //    exactly as the text generator reads them, plus the two
                    //    robots' situation text.
                    $textShaking = $world->isShaking();
                    $wasShaking = $world->wasShakingPreviousTurn();
                    $textSpeaks = $world->residentSpeaksThisTurn();
                    $textLine = $world->residentLine();

                    $textA = $engine->situationTextFor('robot_a');
                    $textB = $engine->situationTextFor('robot_b');

                    // 3. The text must reflect the captured earthquake step.
                    //    On a shaking turn the paragraph opens with the pilot S0
                    //    wording (first shaking turn) or the continued-shaking
                    //    wording; on a non-shaking turn neither opening appears.
                    if ($textShaking) {
                        $expectedOpening = $wasShaking
                            ? self::STILL_SHAKING
                            : self::SHAKE_OPENING;
                        $this->assertStringContainsString(
                            $expectedOpening,
                            $textA,
                            "robot_a's text must open the shaking paragraph for turn {$turn} "
                                . "(seed {$seedValue}, turnCount {$turnCount})"
                        );
                        $this->assertStringContainsString(
                            $expectedOpening,
                            $textB,
                            "robot_b's text must open the shaking paragraph for turn {$turn} "
                                . "(seed {$seedValue}, turnCount {$turnCount})"
                        );
                    } else {
                        $this->assertStringNotContainsString(
                            self::SHAKE_OPENING,
                            $textA,
                            "a non-shaking turn {$turn} must not open the shaking paragraph "
                                . "(seed {$seedValue})"
                        );
                        $this->assertStringNotContainsString(
                            self::STILL_SHAKING,
                            $textA,
                            "a non-shaking turn {$turn} must not report continued shaking "
                                . "(seed {$seedValue})"
                        );
                    }

                    // The spoken line is rendered inside the shaking paragraph,
                    // which the generator emits only on a shaking turn. So the
                    // line's exact text appears in both robots' text when the
                    // resident speaks AND the turn is shaking, and it is the line
                    // prepareTurn fixed (Requirement 12.1: same resident line).
                    // A robot outside the man's room hears it as reported speech,
                    // but the quoted line itself is still an exact substring, so
                    // the substring check holds for both robots either way.
                    if ($textSpeaks && $textShaking) {
                        $spokenLine = $textLine ?? self::RESIDENT_SHOUT;
                        $this->assertStringContainsString(
                            $spokenLine,
                            $textA,
                            "robot_a's text must carry the resident's line for turn {$turn} "
                                . "(seed {$seedValue})"
                        );
                        $this->assertStringContainsString(
                            $spokenLine,
                            $textB,
                            "robot_b's text must carry the resident's line for turn {$turn} "
                                . "(seed {$seedValue})"
                        );
                    }

                    // 4. Judge the turn. Because the turn is already prepared,
                    //    applyTurn judges the very world the text described and
                    //    does not advance the earthquake again (Requirement 12.4).
                    $result = $engine->applyTurn($turn, []);
                    $this->assertFalse(
                        $result->isError,
                        "applyTurn({$turn}) must succeed (seed {$seedValue}): "
                            . ($result->errorMessage ?? '')
                    );

                    // 5a. Same resident line: the line the robots read is the
                    //     line applyTurn recorded for the turn (Requirement 12.1).
                    $this->assertSame(
                        $textLine,
                        $result->residentLine,
                        "the resident line read in the text must equal the line judged for "
                            . "turn {$turn} (seed {$seedValue}, turnCount {$turnCount})"
                    );

                    // 5b. Same earthquake step: the shaking the text described is
                    //     the shaking applyTurn judged this turn (Requirement 12.1).
                    $this->assertSame(
                        $textShaking,
                        $result->worldSnapshot->houseState->shaking,
                        "the shaking step read in the text must equal the step judged for "
                            . "turn {$turn} (seed {$seedValue}, turnCount {$turnCount})"
                    );

                    if ($result->episodeEnded) {
                        break;
                    }
                }
            });
    }

    /**
     * Construct a fully wired Engine with its WorldModel reset to $seed,
     * mirroring how the production layers assemble the engine (design §d) and
     * the way ReplayEngineFactory builds it. All collaborators are no-argument
     * plain classes.
     */
    private function buildEngine(Seed $seed, ?WorldModel &$world = null): Engine
    {
        $config = new EpisodeConfig();

        $world = new WorldModel($config);
        $world->reset($seed);

        return new Engine(
            $world,
            $config,
            new RulesEvaluator(),
            new ActionExecutor(),
            new EarthquakePhase(),
            new ResidentController(),
            new SituationTextGenerator(),
        );
    }
}
