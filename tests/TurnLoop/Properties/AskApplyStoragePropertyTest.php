<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\Engine;
use PoltergeistSwitch\Engine\Enums\Operation;
use PoltergeistSwitch\Engine\EvidenceCheck;
use PoltergeistSwitch\Engine\Values\Action;
use PoltergeistSwitch\Engine\WorldModel;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;
use PoltergeistSwitch\TurnLoop\Clients\FakeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\EngineFactory;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\TurnService;
use PoltergeistSwitch\TurnLoop\Values\EpisodeView;
use PoltergeistSwitch\TurnLoop\Values\Reading;
use PoltergeistSwitch\TurnLoop\Values\ReplyRecord;
use PoltergeistSwitch\TurnLoop\Values\TurnStateValue;
use PoltergeistSwitch\TurnLoop\Values\TurnView;

/**
 * Feature: turn-loop — the ask/apply/storage property tests (task 16.1).
 *
 * These Eris property tests, each configured with `limitTo(100)`, drive a real
 * {@see TurnService} over the in-memory repository and the real
 * {@see ReplayEngineFactory}, with fake robot/recorder clients that open no
 * network connection (Requirement 14.1). They exercise the four load-bearing
 * correctness guarantees of the turn loop:
 *
 *   - Property 1: Ask idempotency — repeated ask calls a robot at most once.
 *   - Property 2: Apply advances once — repeated apply advances the world once.
 *   - Property 3: Four layers stored — an applied turn stores all four layers
 *     for both robots, no-reply robot included.
 *   - Property 4: Unbacked actions become unknown — EvidenceCheck drops any
 *     action whose quote is not an exact substring of the reply.
 *
 * Requirements: 14.2, 14.3, 14.4, 14.5
 */
class AskApplyStoragePropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Feature: turn-loop, Property 1: Ask idempotency.
     *
     * For any `waiting` turn, calling ask two or more times in succession
     * invokes each of the two fake robots AT MOST ONCE across all the repeated
     * ask calls: a stored success is reused and never re-called, and no robot's
     * attempt count ever exceeds 2 (Requirements 5.2, 5.3, 5.4, 14.2). The
     * number of repeated asks and the seed are drawn by Eris; both robots
     * succeed on their first call here, so the second and later asks must reuse
     * the stored replies and call neither robot.
     *
     * Validates: Requirements 14.2
     */
    public function testRepeatedAskCallsEachRobotAtMostOnce(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generators::int(),
                Generators::choose(2, 6),
            )
            ->then(function (int $seedValue, int $askCount): void {
                $repo = new InMemoryEpisodeRepository();
                $robotA = new FakeRobotClient();
                $robotB = new FakeRobotClient();
                $service = $this->service($repo, $robotA, $robotB);

                $episodeId = $this->newEpisode($service, $seedValue, 'dev_p1_' . $seedValue);

                // Turn 1 is logically `waiting`: its row is created lazily by
                // the first ask, so there is no row to inspect before then.
                $this->assertNull($repo->findTurn($episodeId, 1));

                for ($i = 0; $i < $askCount; $i++) {
                    $service->ask($episodeId, 1);
                }

                // Each robot is invoked at most once across every ask call: the
                // first ask calls both, the rest reuse the stored successes.
                $this->assertLessThanOrEqual(
                    1,
                    $robotA->callCount(),
                    "robot_a called {$robotA->callCount()} times across {$askCount} asks (seed {$seedValue})"
                );
                $this->assertLessThanOrEqual(
                    1,
                    $robotB->callCount(),
                    "robot_b called {$robotB->callCount()} times across {$askCount} asks (seed {$seedValue})"
                );

                // No attempt count ever exceeds the bound of 2 (Requirement 5.4).
                $turnAfter = $repo->findTurn($episodeId, 1);
                $this->assertNotNull($turnAfter);
                $this->assertLessThanOrEqual(2, $turnAfter->attemptsA);
                $this->assertLessThanOrEqual(2, $turnAfter->attemptsB);
            });
    }

    /**
     * Feature: turn-loop, Property 2: Apply advances once.
     *
     * For any `replies_complete` turn, calling apply two or more times in
     * succession rebuilds-and-applies the engine EXACTLY ONCE, lands the turn in
     * Turn_State `applied`, and writes no duplicate records (Requirements 6.6,
     * 7.4, 8.10, 14.3). The engine factory is wrapped so every rebuild is
     * counted; only the first apply may rehydrate the engine and call
     * `Engine::applyTurn`, so the rebuild count across all repeated applies must
     * be exactly one. The in-memory store's idempotent four-layer write is what
     * guarantees no duplicate records on the repeated applies.
     *
     * Validates: Requirements 14.3
     */
    public function testRepeatedApplyAdvancesTheWorldExactlyOnce(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generators::int(),
                Generators::choose(2, 6),
            )
            ->then(function (int $seedValue, int $applyCount): void {
                $repo = new InMemoryEpisodeRepository();
                $counting = new CountingEngineFactory(new ReplayEngineFactory($repo));
                $service = new TurnService(
                    new FakeRobotClient(),
                    new FakeRobotClient(),
                    new FakeRecorderClient(),
                    $counting,
                    $repo,
                    new Config(),
                );

                // Reach `replies_complete`: create, flip, and ask once so both
                // robots answer. createEpisode/flipEarthquake/ask all rebuild
                // the engine, so reset the counter to isolate apply.
                $created = $service->createEpisode('dev_p2_' . $seedValue, seed: $seedValue);
                $service->flipEarthquake($created->episodeId);
                $episodeId = $created->episodeId;
                $service->ask($episodeId, 1);

                $turnBefore = $repo->findTurn($episodeId, 1);
                $this->assertNotNull($turnBefore);
                $this->assertSame(TurnStateValue::replies_complete, $turnBefore->state, 'guard: ready to apply');

                $counting->resetCounts();

                for ($i = 0; $i < $applyCount; $i++) {
                    $service->apply($episodeId, 1);
                }

                // Only the first apply rehydrates-and-applies; the rest return
                // the stored `applied` state without touching the engine again,
                // so the engine was rebuilt for apply exactly once (Req 6.6).
                $this->assertSame(
                    1,
                    $counting->forEpisodeCount,
                    "apply rebuilt the engine {$counting->forEpisodeCount} times across {$applyCount} applies (seed {$seedValue})"
                );

                // The turn landed `applied` (Requirement 7.4).
                $turnAfter = $repo->findTurn($episodeId, 1);
                $this->assertNotNull($turnAfter);
                $this->assertSame(TurnStateValue::applied, $turnAfter->state);

                // No duplicate records: exactly one applied-turn write survives
                // the repeated applies (Requirement 8.10). The view carries a
                // single turn-1 record with no robot duplicated.
                $view = $repo->loadEpisodeView($episodeId);
                $this->assertNotNull($view);
                $turnViews = array_values(array_filter(
                    $view->turns,
                    static fn (TurnView $t): bool => $t->turnNumber === 1,
                ));
                $this->assertCount(1, $turnViews, 'exactly one stored turn-1 record (no duplicates)');
            });
    }

    /**
     * Feature: turn-loop, Property 3: Four layers stored.
     *
     * For any turn that reaches `applied`, all four layers — the situation text,
     * the reading, the judgement, and the outcome — are stored for BOTH robot_a
     * and robot_b, including a robot that was marked no reply whose recorded
     * action is `unknown` (Requirements 6.4, 6.8, 14.4). A boolean drawn by Eris
     * chooses whether robot_a answers or always fails: when it fails, two asks
     * spend its two attempts and mark it no reply, and its four layers must
     * still be present with an empty reply and action `unknown`.
     *
     * Validates: Requirements 14.4
     */
    public function testAppliedTurnStoresAllFourLayersForBothRobots(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generators::int(),
                Generators::bool(),
            )
            ->then(function (int $seedValue, bool $aNoReply): void {
                $repo = new InMemoryEpisodeRepository();
                $robotA = $aNoReply
                    ? new FakeRobotClient(ReplyRecord::unanswered(''))
                    : new FakeRobotClient(new ReplyRecord('', 'I take cover under the table.', 1, 1, 0.0, 0.0, false, false));
                $robotB = new FakeRobotClient(new ReplyRecord('', 'I move to the window.', 1, 1, 0.0, 0.0, false, false));
                $service = $this->service($repo, $robotA, $robotB);

                $created = $service->createEpisode('dev_p3_' . $seedValue . ($aNoReply ? '_n' : '_y'), seed: $seedValue);
                $service->flipEarthquake($created->episodeId);
                $episodeId = $created->episodeId;

                // Two asks: a failing robot_a spends both attempts and is marked
                // no reply; a succeeding robot_a answers on the first ask. Either
                // way the turn reaches `replies_complete` after at most two asks.
                $service->ask($episodeId, 1);
                $service->ask($episodeId, 1);

                $turn = $repo->findTurn($episodeId, 1);
                $this->assertNotNull($turn);
                $this->assertSame(TurnStateValue::replies_complete, $turn->state, 'guard: ready to apply');
                if ($aNoReply) {
                    $this->assertTrue($turn->noReplyA, 'guard: robot_a marked no reply');
                }

                $view = $service->apply($episodeId, 1);

                $turnView = $this->turnViewFor($view, 1);
                $this->assertSame(TurnStateValue::applied, $turnView->state);

                // Both robots carry a full Four_Layers record. Every layer is
                // present; the situation-text layer is a stored string. The
                // stored prompt rides on the robot's reply row, so a no-reply
                // robot's situation-text layer is legitimately empty while a
                // robot that answered carries the non-empty text it was sent.
                foreach (['robot_a', 'robot_b'] as $robotId) {
                    $this->assertArrayHasKey($robotId, $turnView->robots, "{$robotId} is missing from the applied turn");
                    $layers = $turnView->robots[$robotId];

                    // Layer 1: the situation text (present as a string).
                    $this->assertIsString($layers->situationText);
                    if (!$layers->noReply) {
                        $this->assertNotSame('', $layers->situationText, "{$robotId} answered but has no situation text");
                    }
                    // Layer 3: the reading is present.
                    $this->assertInstanceOf(Reading::class, $layers->reading);
                    // Layer 4a/4b: judgement and outcome are present for this robot.
                    $this->assertSame($robotId, $layers->judgement->robotId);
                    $this->assertSame($robotId, $layers->outcome->robotId);
                }

                // The no-reply robot still has all four layers, with an empty
                // reply and action `unknown` (Requirement 6.8).
                if ($aNoReply) {
                    $a = $turnView->robots['robot_a'];
                    $this->assertTrue($a->noReply, 'robot_a is flagged no reply in its layers');
                    $this->assertSame('', $a->reply, 'a no-reply robot has an empty reply');
                    $this->assertTrue($a->reading->unknown, 'a no-reply robot reads as unknown');
                    $this->assertSame(
                        Operation::unknown->value,
                        $a->reading->action->operation,
                        'a no-reply robot has action `unknown`'
                    );
                }
            });
    }

    /**
     * Feature: turn-loop, Property 4: Unbacked actions become unknown.
     *
     * For any reply and any action quote that is NOT an exact substring of that
     * reply, {@see EvidenceCheck::verify} sets the reading's recorded action to
     * `unknown` and drops the target; any surviving spoken line or reported
     * quote is itself an exact substring of the reply (Requirement 14.5). The
     * reply, a non-substring quote, and the action's starting operation/target
     * are drawn by Eris; a guard skips the rare draw where the "quote" happens
     * to be a substring, because the property is about unbacked quotes only.
     *
     * Validates: Requirements 14.5
     */
    public function testUnbackedActionQuoteBecomesUnknown(): void
    {
        $operations = [
            Operation::move->value,
            Operation::take->value,
            Operation::use->value,
            Operation::cover->value,
            Operation::support->value,
        ];

        $this->limitTo(100)
            ->forAll(
                Generators::string(),
                Generators::string(),
                Generators::elements($operations),
            )
            ->then(function (string $reply, string $quote, string $operation): void {
                // The property is about quotes NOT backed by the reply. The
                // empty string is trivially a substring of any reply, so a draw
                // whose quote is already contained is outside the property's
                // scope and is skipped.
                if ($quote === '' || str_contains($reply, $quote)) {
                    $this->assertTrue(true, 'drawn quote is backed by the reply; outside this property');

                    return;
                }

                $action = new Action(
                    robotId: 'robot_a',
                    operation: $operation,
                    targetId: 'pot',
                    targetSpot: 'gas_stove',
                    words: [],
                    actionQuote: $quote,
                    reported: [],
                );

                $verified = EvidenceCheck::verify($reply, $action);

                // An unbacked action quote turns the action into `unknown` with
                // no target and no surviving quote (Requirement 14.5).
                $this->assertSame(Operation::unknown->value, $verified->operation);
                $this->assertNull($verified->targetId);
                $this->assertNull($verified->targetSpot);
                $this->assertNull($verified->actionQuote);

                // Any spoken line that survives is an exact substring of the
                // reply (there are none here, but the invariant must hold).
                foreach ($verified->words as $line) {
                    $this->assertTrue(
                        str_contains($reply, $line),
                        'a surviving spoken line must be an exact substring of the reply'
                    );
                }
            });
    }

    // --- helpers ----------------------------------------------------------

    private function service(
        InMemoryEpisodeRepository $repo,
        FakeRobotClient $robotA,
        FakeRobotClient $robotB,
    ): TurnService {
        return new TurnService(
            $robotA,
            $robotB,
            new FakeRecorderClient(),
            new ReplayEngineFactory($repo),
            $repo,
            new Config(),
        );
    }

    /**
     * Create an episode and flip the earthquake so turn 1 is the first shaking
     * turn, waiting for its robots. Returns the episode id.
     */
    private function newEpisode(TurnService $service, int $seed, string $device): string
    {
        $created = $service->createEpisode($device, seed: $seed);
        $service->flipEarthquake($created->episodeId);

        return $created->episodeId;
    }

    private function turnViewFor(EpisodeView $view, int $turnNumber): TurnView
    {
        foreach ($view->turns as $turn) {
            if ($turn->turnNumber === $turnNumber) {
                return $turn;
            }
        }

        $this->fail("no stored turn {$turnNumber} in the view");
    }
}

/**
 * An {@see EngineFactory} that delegates to the real {@see ReplayEngineFactory}
 * but counts how many times each rebuild entry point was called, so Property 2
 * can assert that repeated applies rebuild-and-apply the engine exactly once.
 */
final class CountingEngineFactory implements EngineFactory
{
    public int $forEpisodeCount = 0;

    public int $worldForEpisodeCount = 0;

    public function __construct(private readonly ReplayEngineFactory $inner) {}

    public function forEpisode(string $episodeId): Engine
    {
        $this->forEpisodeCount++;

        return $this->inner->forEpisode($episodeId);
    }

    public function worldForEpisode(string $episodeId): WorldModel
    {
        $this->worldForEpisodeCount++;

        return $this->inner->worldForEpisode($episodeId);
    }

    public function resetCounts(): void
    {
        $this->forEpisodeCount = 0;
        $this->worldForEpisodeCount = 0;
    }
}
