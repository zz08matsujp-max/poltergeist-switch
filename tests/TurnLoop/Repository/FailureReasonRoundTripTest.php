<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop\Repository;

use PDO;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;
use PoltergeistSwitch\TurnLoop\Repository\EpisodeRepository;
use PoltergeistSwitch\TurnLoop\Repository\PdoEpisodeRepository;
use PoltergeistSwitch\TurnLoop\Values\AppliedRobotRow;
use PoltergeistSwitch\TurnLoop\Values\AppliedTurnRows;
use PoltergeistSwitch\TurnLoop\Values\EpisodeRow;
use PoltergeistSwitch\TurnLoop\Values\PromptReplyRow;
use PoltergeistSwitch\TurnLoop\Values\ReplyRecord;

/**
 * Round-trip tests for the machine failure reasons added in task 19.2: storing
 * an applied turn with a recorder failure reason (recordings.failure_reason)
 * and an unanswered reply reason (responses.failure_reason) must make BOTH
 * readable back on the EpisodeView — and therefore in the API serializer.
 *
 * The in-memory fake is the required path; the PDO/MySQL path is exercised too
 * but is skipped when no MySQL is available (guarded by the PS_TEST_MYSQL_DSN
 * environment variable), so the suite stays green without a database.
 *
 * Requirements: 3.6, 3.7, 8.5, 10.2
 */
class FailureReasonRoundTripTest extends TestCase
{
    public function testInMemoryRoundTripsRecorderAndReplyFailureReasons(): void
    {
        $repo = new InMemoryEpisodeRepository();
        $this->seedEpisodeWithFailedTurn($repo);

        $this->assertFailureReasonsReadBack($repo);
    }

    public function testPdoRoundTripsRecorderAndReplyFailureReasons(): void
    {
        $dsn = getenv('PS_TEST_MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            $this->markTestSkipped('no MySQL available (set PS_TEST_MYSQL_DSN to run)');
        }

        $user = (string) (getenv('PS_TEST_MYSQL_USER') ?: '');
        $pass = (string) (getenv('PS_TEST_MYSQL_PASS') ?: '');

        try {
            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL connection failed');
        }

        $repo = new PdoEpisodeRepository($pdo);
        $this->seedEpisodeWithFailedTurn($repo);

        $this->assertFailureReasonsReadBack($repo);
    }

    /**
     * Store a quiet-free episode with one applied turn where robot_a's reading
     * failed (reason http_400:invalid_request_error) and robot_b produced no
     * reply (an unanswered response carrying reason timeout, and a no-reply
     * recorder reading carrying reason no_reply).
     */
    private function seedEpisodeWithFailedTurn(EpisodeRepository $repo): void
    {
        $episodeId = 'ep_fail_1';
        $now = '2026-11-09 12:00:00';

        $repo->createEpisode(new EpisodeRow(
            id: $episodeId,
            deviceId: 'dev_fail',
            disaster: 'earthquake',
            seed: 7,
            currentExpectedTurn: 1,
            earthquakeStartTurn: 0,
            ended: false,
            createdAt: $now,
            lastActivityAt: $now,
        ));

        $repo->createTurn($episodeId, 1);

        // robot_a: a reply arrived but the recorder read failed with an HTTP
        // 400 of a known Anthropic error type.
        $repo->savePromptAndReply(new PromptReplyRow(
            episodeId: $episodeId,
            turnNumber: 1,
            robotId: 'robot_a',
            attempt: 1,
            situationTextVersion: 'v1',
            reply: new ReplyRecord('prompt-a', 'I crawl under the table.', 2, 2, 0.1, 0.0, false, false),
        ));

        // robot_b: an unanswered reply that timed out.
        $repo->savePromptAndReply(new PromptReplyRow(
            episodeId: $episodeId,
            turnNumber: 1,
            robotId: 'robot_b',
            attempt: 1,
            situationTextVersion: 'v1',
            reply: ReplyRecord::unanswered('prompt-b', 75.0, 'timeout'),
        ));

        $robots = [
            'robot_a' => new AppliedRobotRow(
                robotId: 'robot_a',
                situationText: 'prompt-a',
                situationTextVersion: 'v1',
                recorderJson: '',
                recorderUnknown: true,
                recorderPromptVersion: 'recorder-2b',
                judgementJson: json_encode(['possible' => false]),
                outcomeJson: json_encode(['success' => false]),
                actionJson: json_encode(['operation' => 'unknown']),
                recorderFailureReason: 'http_400:invalid_request_error',
            ),
            'robot_b' => new AppliedRobotRow(
                robotId: 'robot_b',
                situationText: 'prompt-b',
                situationTextVersion: 'v1',
                recorderJson: '',
                recorderUnknown: true,
                recorderPromptVersion: 'recorder-2b',
                judgementJson: json_encode(['possible' => false]),
                outcomeJson: json_encode(['success' => false]),
                actionJson: json_encode(['operation' => 'unknown']),
                recorderFailureReason: 'no_reply',
            ),
        ];

        $repo->storeAppliedTurn(new AppliedTurnRows(
            episodeId: $episodeId,
            turnNumber: 1,
            robots: $robots,
            residentLine: null,
        ));
        $repo->setTurnState($episodeId, 1, 'replies_complete');
        $repo->setTurnState($episodeId, 1, 'applied');
    }

    private function assertFailureReasonsReadBack(EpisodeRepository $repo): void
    {
        $view = $repo->loadEpisodeView('ep_fail_1');
        $this->assertNotNull($view);

        $turn = null;
        foreach ($view->turns as $t) {
            if ($t->turnNumber === 1) {
                $turn = $t;
                break;
            }
        }
        $this->assertNotNull($turn, 'the applied turn must be in the view');

        $this->assertArrayHasKey('robot_a', $turn->robots);
        $this->assertArrayHasKey('robot_b', $turn->robots);

        $a = $turn->robots['robot_a'];
        $b = $turn->robots['robot_b'];

        // robot_a: the recorder failure reason reads back on the reading.
        $this->assertSame('http_400:invalid_request_error', $a->reading->failureReason);
        $this->assertSame('http_400:invalid_request_error', $a->recorderFailureReason);

        // robot_b: the no-reply recorder reason reads back, and the unanswered
        // reply reason (timeout) is surfaced as the reply failure reason.
        $this->assertSame('no_reply', $b->reading->failureReason);
        $this->assertSame('timeout', $b->replyFailureReason);
        $this->assertSame('', $b->reply, 'robot_b has no reply');
    }
}
