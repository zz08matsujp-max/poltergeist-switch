<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;
use PoltergeistSwitch\TurnLoop\Clients\FakeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\RobotClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\Http\ApiError;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\TurnService;
use PoltergeistSwitch\TurnLoop\Values\ReplyRecord;

/**
 * Scripted robot client: the next outcome is set by the test, every logical
 * call is counted, and the repository attempt count at call time is recorded.
 */
final class ScriptedAttemptClient implements RobotClient
{
    public bool $succeed = true;
    public int $calls = 0;

    /** @var list<int> attempt count seen in the repository at each call */
    public array $attemptsSeen = [];

    public function __construct(
        private readonly InMemoryEpisodeRepository $repo,
        private readonly bool $isRobotA,
        private ?string &$episodeId,
    ) {
    }

    public function call(string $prompt): ReplyRecord
    {
        $this->calls++;
        $turn = $this->repo->findTurn((string) $this->episodeId, 1);
        $this->attemptsSeen[] = $turn === null ? -1 : ($this->isRobotA ? $turn->attemptsA : $turn->attemptsB);

        return $this->succeed
            ? new ReplyRecord($prompt, 'ok', 1, 1, 0.0, 0.0, false, false)
            : ReplyRecord::unanswered($prompt);
    }

    public function prepare(string $prompt): \CurlHandle
    {
        return curl_init();
    }

    public function finish(string $prompt, array $result): ReplyRecord
    {
        return $this->call($prompt);
    }
}

/**
 * Property-based test (Eris) for the attempt limit over the in-memory
 * repository and scripted clients. No real AI call.
 *
 * Feature: public-lab, Property 24: Provider calls and attempts stay within the Attempt_Limit
 *
 * **Validates: Requirements 18.1, 18.2, 18.4, 18.5, 18.6, 18.7, 18.14**
 */
class AttemptLimitPropertyTest extends TestCase
{
    use TestTrait;

    public function testProviderCallsAndAttemptsStayWithinTheAttemptLimit(): void
    {
        // Feature: public-lab, Property 24: Provider calls and attempts stay within the Attempt_Limit
        $this
            ->limitTo(100)
            ->forAll(
                Generators::seq(
                    Generators::tuple(
                        Generators::bool(), // lock busy
                        Generators::bool(), // robot_a call succeeds
                        Generators::bool(), // robot_b call succeeds
                    ),
                ),
            )
            ->then(function (array $ops): void {
                $repo = new InMemoryEpisodeRepository();
                $episodeId = null;
                $clientA = new ScriptedAttemptClient($repo, true, $episodeId);
                $clientB = new ScriptedAttemptClient($repo, false, $episodeId);
                $service = new TurnService(
                    $clientA,
                    $clientB,
                    new FakeRecorderClient(),
                    new ReplayEngineFactory($repo),
                    $repo,
                    new Config(),
                    null,
                    fn (): string => '2026-11-01 10:00:00',
                );
                $created = $service->createEpisode('dev_attempts', seed: 2024);
                $episodeId = $created->episodeId;
                $service->flipEarthquake($episodeId);

                // Oracle state per robot.
                $attempts = ['a' => 0, 'b' => 0];
                $success = ['a' => false, 'b' => false];

                foreach ($ops as [$busy, $okA, $okB]) {
                    $clientA->succeed = $okA;
                    $clientB->succeed = $okB;
                    $callsBefore = ['a' => $clientA->calls, 'b' => $clientB->calls];
                    $acquiredBefore = $repo->turnLockAcquireCount();

                    if ($busy) {
                        $repo->holdTurnLock('ask', $episodeId, 1);
                        try {
                            $service->ask($episodeId, 1);
                            $this->fail('a busy lock must answer turn_busy');
                        } catch (ApiError $e) {
                            $this->assertSame('turn_busy', $e->errorCode);
                        } finally {
                            $repo->freeHeldTurnLock('ask', $episodeId, 1);
                        }
                        $this->assertSame($callsBefore['a'], $clientA->calls);
                        $this->assertSame($callsBefore['b'], $clientB->calls);
                        $this->assertSame($acquiredBefore, $repo->turnLockAcquireCount());
                    } else {
                        $service->ask($episodeId, 1);
                        $this->assertSame($acquiredBefore + 1, $repo->turnLockAcquireCount());

                        foreach (['a' => $okA, 'b' => $okB] as $r => $ok) {
                            $client = $r === 'a' ? $clientA : $clientB;
                            if ($success[$r] || $attempts[$r] >= 2) {
                                $this->assertSame($callsBefore[$r], $client->calls, "robot_$r must not be called");
                                continue;
                            }
                            $attempts[$r]++;
                            $this->assertSame($callsBefore[$r] + 1, $client->calls, "robot_$r called once");
                            $this->assertSame(
                                $attempts[$r],
                                $client->attemptsSeen[array_key_last($client->attemptsSeen)],
                                "robot_$r attempt reserved before the call",
                            );
                            if ($ok) {
                                $success[$r] = true;
                            }
                        }
                    }

                    // Stored counts match the oracle and never exceed the limit.
                    $turn = $repo->findTurn($episodeId, 1);
                    $storedA = $turn === null ? 0 : $turn->attemptsA;
                    $storedB = $turn === null ? 0 : $turn->attemptsB;
                    $this->assertSame($attempts['a'], $storedA);
                    $this->assertSame($attempts['b'], $storedB);
                    $this->assertLessThanOrEqual(2, $storedA);
                    $this->assertLessThanOrEqual(2, $storedB);
                    $this->assertLessThanOrEqual(2, $clientA->calls);
                    $this->assertLessThanOrEqual(2, $clientB->calls);

                    // The lock is released after every ask that obtained it.
                    $this->assertSame($repo->turnLockAcquireCount(), $repo->turnLockReleaseCount());
                }
            });
    }
}
