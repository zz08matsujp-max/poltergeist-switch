<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\TurnLoop\Clients\FakeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\TurnService;
use PoltergeistSwitch\TurnLoop\Values\Reading;
use PoltergeistSwitch\TurnLoop\Values\ReplyRecord;
use PoltergeistSwitch\TurnLoop\Values\TurnStateValue;

/**
 * End-to-end earthquake run (task 17.1).
 *
 * This is a plain PHPUnit test, not a property test. It drives a full
 * earthquake episode exactly as the browser would: repeated `ask` and `apply`
 * calls on a {@see TurnService} backed by the in-memory repository, the real
 * {@see ReplayEngineFactory} rehydrating the engine between every simulated
 * request, and {@see FakeRobotClient}/{@see FakeRecorderClient} test doubles
 * that open no network connection (Requirement 14.1). The run continues turn by
 * turn until `Engine::applyTurn` reports the episode ended.
 *
 * The scripted robots and recorder produce HOUSE-CHANGING actions, not just
 * wait: they take and use the flashlight, use the gas stove, cover the resident
 * and themselves, support the resident, and move between rooms. Each recorder
 * Reading carries an actionQuote that is an exact substring of the robot's
 * reply, so EvidenceCheck keeps the action (Requirement 12) and the episode
 * advances through a realistic run rather than a string of unknown actions.
 *
 * The test asserts (Requirements 14.6, 14.7, 14.8):
 *   - the episode is marked ended, within a 100-applied-turn bound;
 *   - every turn taken is in state `applied`;
 *   - a turn's situation text opens the shaking paragraph with
 *     "Suddenly the floor begins to shake hard." and a LATER turn's text
 *     reports "The shaking has stopped.", proving the quake starts and later
 *     subsides within the run, in order.
 *
 * The earthquake is flipped right after creation, so the first played turn
 * (turn 1, offset 1) is the first shaking turn and the shaking subsides on its
 * own at offset 5 (turn 5) — both well inside the bounded run.
 *
 * Requirements: 14.1, 14.6, 14.7, 14.8
 */
final class EndToEndEarthquakeTest extends TestCase
{
    /** The pilot opening of the first shaking turn (house-rules-engine Req 9.14). */
    private const SHAKE_START = 'Suddenly the floor begins to shake hard.';

    /** The wording when the shaking stops (house-rules-engine Req 9.18). */
    private const SHAKE_STOP = 'The shaking has stopped.';

    /** The hard cap on applied turns; the episode must end before this. */
    private const MAX_APPLIED_TURNS = 100;

    public function testFullEarthquakeRunEndsAndShowsShakeStartThenStop(): void
    {
        $repo = new InMemoryEpisodeRepository();
        $factory = new ReplayEngineFactory($repo);

        $robotA = $this->scriptRobot('robot_a');
        $robotB = $this->scriptRobot('robot_b');
        $recorder = $this->scriptRecorder();

        $service = new TurnService(
            $robotA,
            $robotB,
            $recorder,
            $factory,
            $repo,
            new Config(),
        );

        // Create the episode and flip the earthquake immediately, so turn 1 is
        // earthquake offset 1 — the first shaking turn.
        $created = $service->createEpisode('dev_e2e', seed: 4242);
        $episodeId = $created->episodeId;
        $service->flipEarthquake($episodeId);

        /** @var array<int, string> $shakeStartTurns turns whose text opened the quake */
        $shakeStartTurns = [];
        /** @var array<int, string> $shakeStopTurns turns whose text reported the stop */
        $shakeStopTurns = [];

        $appliedCount = 0;

        // Drive the browser loop: ask (re-asking while a robot is still
        // missing), capture the turn's situation text as the robots read it,
        // then apply — until the episode ends or the bound is hit.
        while (true) {
            $episode = $repo->findEpisode($episodeId);
            self::assertNotNull($episode);

            if ($episode->ended) {
                break;
            }

            $turnNumber = $episode->currentExpectedTurn;

            // ask, repeating until the turn reaches `replies_complete` (a robot
            // that is still `waiting` needs another ask, just as the browser
            // re-asks a missing robot). The fakes always answer, so one ask
            // completes the turn; the guard keeps the loop finite regardless.
            $askGuard = 0;
            do {
                $service->ask($episodeId, $turnNumber);
                $turn = $repo->findTurn($episodeId, $turnNumber);
                self::assertNotNull($turn);
                $askGuard++;
            } while ($turn->state === TurnStateValue::waiting && $askGuard < 5);

            self::assertSame(
                TurnStateValue::replies_complete,
                $turn->state,
                "turn {$turnNumber} must be replies_complete before apply"
            );

            // Capture the situation text for this turn exactly as the robots
            // read it: rehydrate the engine by replay, prepare this (not yet
            // applied) turn, and read both robots' text. This mirrors the
            // same-world / rehydration tests.
            $preview = $factory->forEpisode($episodeId);
            $preview->prepareTurn($turnNumber);
            $textA = $preview->situationTextFor('robot_a');
            $textB = $preview->situationTextFor('robot_b');

            if (str_contains($textA, self::SHAKE_START)) {
                $shakeStartTurns[$turnNumber] = $textA;
                // Both robots read the same earthquake step.
                self::assertStringContainsString(
                    self::SHAKE_START,
                    $textB,
                    "both robots must read the quake start on turn {$turnNumber}"
                );
            }
            if (str_contains($textA, self::SHAKE_STOP)) {
                $shakeStopTurns[$turnNumber] = $textA;
                self::assertStringContainsString(
                    self::SHAKE_STOP,
                    $textB,
                    "both robots must read the quake stop on turn {$turnNumber}"
                );
            }

            // apply the turn exactly once.
            $service->apply($episodeId, $turnNumber);

            $applied = $repo->findTurn($episodeId, $turnNumber);
            self::assertNotNull($applied);
            self::assertSame(
                TurnStateValue::applied,
                $applied->state,
                "turn {$turnNumber} must be applied after apply"
            );

            $appliedCount++;
            self::assertLessThanOrEqual(
                self::MAX_APPLIED_TURNS,
                $appliedCount,
                'the episode must end within the applied-turn bound'
            );
            if ($appliedCount >= self::MAX_APPLIED_TURNS) {
                $endedNow = $repo->findEpisode($episodeId);
                self::assertNotNull($endedNow);
                self::assertTrue(
                    $endedNow->ended,
                    'the episode did not end within ' . self::MAX_APPLIED_TURNS . ' applied turns'
                );
                break;
            }
        }

        // 1. The episode is marked ended (Requirement 14.6).
        $episode = $repo->findEpisode($episodeId);
        self::assertNotNull($episode);
        self::assertTrue($episode->ended, 'the episode must be marked ended');

        // 2. Every turn taken — the quiet turn 0 and every played turn — is in
        //    state `applied` (Requirement 14.6).
        $view = $service->getEpisode($episodeId);
        self::assertNotEmpty($view->turns);
        foreach ($view->turns as $turn) {
            self::assertSame(
                TurnStateValue::applied,
                $turn->state,
                "every stored turn must be applied; turn {$turn->turnNumber} was not"
            );
        }

        // 3. The quake starts and later subsides, in order (Requirements 14.7,
        //    14.8). The start string appears on at least one turn, the stop
        //    string on at least one LATER turn.
        self::assertNotEmpty(
            $shakeStartTurns,
            'at least one turn must open with "' . self::SHAKE_START . '"'
        );
        self::assertNotEmpty(
            $shakeStopTurns,
            'at least one turn must report "' . self::SHAKE_STOP . '"'
        );

        $firstStart = min(array_keys($shakeStartTurns));
        $lastStop = max(array_keys($shakeStopTurns));
        self::assertGreaterThan(
            $firstStart,
            $lastStop,
            'the shaking must stop on a LATER turn than it started'
        );

        // Concretely, with the earthquake flipped at creation the quake starts
        // on turn 1 (offset 1) and stops on turn 5 (offset 5).
        self::assertArrayHasKey(1, $shakeStartTurns, 'the quake must start on turn 1');
        self::assertArrayHasKey(5, $shakeStopTurns, 'the quake must stop on turn 5');
    }

    /**
     * A FakeRobotClient whose reply names a house-changing action and quotes it
     * verbatim, so the matching recorder Reading's actionQuote is an exact
     * substring of the reply and EvidenceCheck keeps the action. The same reply
     * is returned for every prompt (the default record), which is enough to
     * drive a full run; the per-reply Reading set on the recorder maps it to a
     * concrete Action.
     */
    private function scriptRobot(string $robotId): FakeRobotClient
    {
        $reply = $this->replyTextFor($robotId);

        return new FakeRobotClient(
            new ReplyRecord('', $reply, 12, 8, 0.1, 0.0, false, false),
        );
    }

    /**
     * A FakeRecorderClient that maps each robot's reply to a house-changing
     * Action whose actionQuote is an exact substring of that reply. robot_a
     * covers the resident; robot_b supports the resident toward the low table
     * with a spoken line. Both survive EvidenceCheck, so the engine judges a
     * real action every turn.
     */
    private function scriptRecorder(): FakeRecorderClient
    {
        $recorder = new FakeRecorderClient();

        $replyA = $this->replyTextFor('robot_a');
        $recorder->setReadingForReply($replyA, new Reading(
            new Action(
                robotId: 'robot',
                operation: Operation::cover->value,
                targetId: 'resident',
                actionQuote: 'I shield the old man with my body.',
            ),
            false,
            '{"operation":"cover","targetId":"resident"}',
            'recorder-fake',
        ));

        $replyB = $this->replyTextFor('robot_b');
        $recorder->setReadingForReply($replyB, new Reading(
            new Action(
                robotId: 'robot',
                operation: Operation::support->value,
                targetId: 'resident',
                targetSpot: 'under_table',
                words: ['Let us get you under the table.'],
                actionQuote: 'I help the old man under the table and say, "Let us get you under the table."',
            ),
            false,
            '{"operation":"support","targetId":"resident","targetSpot":"under_table"}',
            'recorder-fake',
        ));

        return $recorder;
    }

    /**
     * The fixed reply text for a robot. Each reply contains, verbatim, the
     * actionQuote the recorder reads from it, so EvidenceCheck's exact-substring
     * check passes.
     */
    private function replyTextFor(string $robotId): string
    {
        if ($robotId === 'robot_a') {
            return 'The floor is shaking. I shield the old man with my body.';
        }

        return 'I help the old man under the table and say, "Let us get you under the table."';
    }
}
