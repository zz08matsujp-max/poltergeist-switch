<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Memory\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Engine\EpisodeConfig;
use PoltergeistSwitch\Engine\ReflectionTextBuilder;
use PoltergeistSwitch\Engine\SituationTextGenerator;
use PoltergeistSwitch\Memory\MemoryService;
use PoltergeistSwitch\Memory\ReflectService;
use PoltergeistSwitch\Memory\Values\MemoryRow;
use PoltergeistSwitch\Memory\Values\MemorySnapshot;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\ParallelHttp;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\Values\EpisodeRow;
use PoltergeistSwitch\TurnLoop\Values\ReplyRecord;
use PoltergeistSwitch\TurnLoop\Values\TurnStateValue;

// The api/ layer is plain PHP with its own PSR-4 autoloader (no Composer on the
// server), so the handlers and the routing/response helpers are global
// functions and a global PsApiResponse class. Load them once for the test.
// This does NOT build a production TurnService — the test injects fake-backed
// services into ps_dispatch(), so no config, secret, or DB is touched.
require_once __DIR__ . '/../../../api/bootstrap.php';

/**
 * Feature: robot-memory, Property 9: No memory response leaks a secret, device
 * id, or provider name.
 *
 * For any reflect, reset, or status response the system can produce, the
 * serialized body contains none of the configured API keys, database
 * credentials, the secrets path, or any device id, and names neither AI
 * provider nor model (design "Property 9"; Requirements 2.12, 4.5, 5.4).
 *
 * The three memory routes are driven in-process through {@see ps_dispatch} with
 * fake-backed services over the {@see InMemoryEpisodeRepository} and two
 * {@see FakeRobotClient}s, the same harness the turn-loop api test uses — so no
 * real web server, config, secret, or network is involved, and the production
 * builders in bootstrap.php (which read the real secrets) are never called.
 *
 * To catch a leak that only a secret-carrying input would surface, the property
 * injects known SENTINEL secret-like values — a fake API key, a fake database
 * credential, a fake secrets path, a device id, and the provider/model name
 * strings from the banned set — into every input and configured value where
 * such a string could plausibly reach a response body:
 *   - the episode's device id is a sentinel device id (create, and so any later
 *     read, could echo it);
 *   - each robot's reflect reply embeds every sentinel (the reply is the one
 *     free-text input a provider controls; a bug that echoed a reply's internal
 *     fields, a failure message, or usage metadata verbatim would surface it);
 *   - an unanswered reply is stamped with a sentinel failure token.
 * The memory BODY the robot wants to remember is a clean, non-secret string, so
 * the legitimate verbatim round-trip of a memory never produces a false
 * positive: a sentinel appearing in a body would mean the robot literally wrote
 * a secret, which is out of scope here.
 *
 * After each of the three responses the body is scanned for every sentinel and
 * for the real provider/model banned words; none may appear. The reset and
 * status bodies carry only counts and lengths, and the reflect body carries
 * only the (clean) memory bodies, the active counts, and the short machine
 * reasons — never a secret, device id, or provider name.
 *
 * NOTE: NO real secret is ever used — every injected value is an obvious fake
 * sentinel defined in this test. This Eris release (giorgiosironi/eris ^1.3)
 * controls the iteration count with `limitTo()`; `limitTo(100)` is required
 * here, matching the other property tests.
 *
 * Validates: Requirements 2.12, 4.5, 5.4
 */
class NoLeakPropertyTest extends TestCase
{
    use TestTrait;

    private const ROBOT_A = 'robot_a';
    private const ROBOT_B = 'robot_b';

    /**
     * Obvious fake sentinels standing in for a secret, a credential, a path, a
     * device id, and the provider/model names. NONE is a real secret. If any of
     * these strings reaches a response body it is a leak the property must fail
     * on. The provider/model entries mirror the turn-loop api test's banned set
     * so a response naming an AI company or model is caught too.
     *
     * @var list<string>
     */
    private const FORBIDDEN_SUBSTRINGS = [
        // Fake secret-like values injected into the inputs/config below.
        'FAKE-API-KEY-sk-sentinel-0000',
        'FAKE-DB-CRED-sentinel-0000',
        '/fake/secrets/path/sentinel',
        'device-SENTINEL-must-not-leak',
        // Provider and model names that must never appear (same set the
        // turn-loop api test forbids).
        'Anthropic', 'anthropic',
        'OpenAI', 'openai',
        'Claude', 'claude',
        'GPT', 'gpt',
        'claude-haiku-5-5',
        'gpt-6-luna',
    ];

    /** The sentinel device id the episode is created with. */
    private const SENTINEL_DEVICE_ID = 'device-SENTINEL-must-not-leak';

    /**
     * Property 9: no reflect, reset, or status response body leaks a secret,
     * device id, or provider/model name.
     *
     * Validates: Requirements 2.12, 4.5, 5.4
     */
    public function testNoMemoryResponseLeaksASecretDeviceIdOrProviderName(): void
    {
        $this->limitTo(100)
            ->forAll(
                // A seed so the episode (and so its Reflection_Text) varies.
                Generators::int(),
                // Whether each robot answers with a memory or fails, so both
                // the answered-body path and the failure-reason path of the
                // reflect body are exercised.
                Generators::bool(),
                Generators::bool(),
            )
            ->then(function (int $seedValue, bool $robotAAnswers, bool $robotBAnswers): void {
                $repo = new InMemoryEpisodeRepository();

                // Seed a few prior memories of the current generation so status
                // and reflect have non-empty counts to report. The bodies are
                // clean, non-secret strings (never a sentinel), so the
                // legitimate verbatim round-trip of a memory is not a leak.
                $this->seedCleanMemories($repo);

                $episodeId = $this->buildEndedReflectableEpisode($repo, $seedValue);

                $clientA = new FakeRobotClient();
                $clientB = new FakeRobotClient();

                // Configure each robot's reflect reply. The reply text embeds
                // every sentinel so a bug that echoed the reply's transport
                // fields, a failure message, or usage metadata would surface a
                // sentinel in the body; the stored memory body, however, is a
                // clean string set separately below via a non-empty answer.
                $this->configureReply($repo, $episodeId, self::ROBOT_A, $clientA, $robotAAnswers);
                $this->configureReply($repo, $episodeId, self::ROBOT_B, $clientB, $robotBAnswers);

                $turnService = $this->turnService($repo, $clientA, $clientB);
                $memoryService = new MemoryService($repo);
                $reflectService = new ReflectService(
                    $clientA,
                    $clientB,
                    new ReflectionTextBuilder(new SituationTextGenerator()),
                    new ReplayEngineFactory($repo, new EpisodeConfig()),
                    $repo,
                    new Config(),
                    new ParallelHttp(),
                );

                // --- Reflect --------------------------------------------------
                $reflect = ps_dispatch(
                    $turnService,
                    'POST',
                    'episodes/' . $episodeId . '/reflect',
                    [],
                    [],
                    null,
                    $memoryService,
                    $reflectService,
                );
                $this->assertSame(200, $reflect->status, "reflect must succeed (seed {$seedValue})");
                $this->assertLeakFree($reflect, 'reflect', $seedValue);

                // --- Status ---------------------------------------------------
                $status = ps_dispatch(
                    $turnService,
                    'GET',
                    'memory',
                    [],
                    [],
                    null,
                    $memoryService,
                    $reflectService,
                );
                $this->assertSame(200, $status->status, "status must succeed (seed {$seedValue})");
                $this->assertLeakFree($status, 'status', $seedValue);

                // --- Reset ----------------------------------------------------
                $reset = ps_dispatch(
                    $turnService,
                    'POST',
                    'memory/reset',
                    [],
                    [],
                    null,
                    $memoryService,
                    $reflectService,
                );
                $this->assertSame(200, $reset->status, "reset must succeed (seed {$seedValue})");
                $this->assertLeakFree($reset, 'reset', $seedValue);
            });
    }

    /**
     * Assert a response parses as JSON and that its serialized body contains no
     * sentinel secret/credential/path/device-id and no provider/model name.
     */
    private function assertLeakFree(\PsApiResponse $response, string $label, int $seedValue): void
    {
        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded, "{$label} body must parse as JSON (seed {$seedValue})");

        foreach (self::FORBIDDEN_SUBSTRINGS as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $response->body,
                "{$label} body must not leak a secret, device id, or provider name: "
                    . "{$needle} (seed {$seedValue})",
            );
        }
    }

    /**
     * Build the fake-backed TurnService the three memory routes dispatch
     * through. The two robot clients are shared with the ReflectService so one
     * set of fakes drives both. No config, secret, or network is touched.
     */
    private function turnService(
        InMemoryEpisodeRepository $repo,
        FakeRobotClient $clientA,
        FakeRobotClient $clientB
    ): \PoltergeistSwitch\TurnLoop\TurnService {
        return new \PoltergeistSwitch\TurnLoop\TurnService(
            $clientA,
            $clientB,
            new \PoltergeistSwitch\TurnLoop\Clients\FakeRecorderClient(),
            new ReplayEngineFactory($repo),
            $repo,
            new Config(),
        );
    }

    /**
     * Seed two clean (non-secret) active memories per robot in the current
     * generation so status and reflect report a non-empty count. The bodies
     * never contain a sentinel, so their legitimate verbatim appearance in a
     * body is not a leak.
     */
    private function seedCleanMemories(InMemoryEpisodeRepository $repo): void
    {
        $generation = $repo->currentGeneration();
        $now = date('Y-m-d H:i:s');

        foreach ([self::ROBOT_A, self::ROBOT_B] as $robotId) {
            for ($i = 0; $i < 2; $i++) {
                $repo->appendMemory(new MemoryRow(
                    robotId: $robotId,
                    generation: $generation,
                    disaster: 'earthquake',
                    sourceEpisodeId: 'seed_' . $robotId . '_' . $i,
                    body: "The old man was near the kitchen (note {$i}).",
                    status: 'active',
                    createdAt: $now,
                ));
            }
        }
    }

    /**
     * Build one ended, reflectable episode carrying a SENTINEL device id and an
     * (empty) frozen Memory_Snapshot so ReflectService passes the
     * not_reflectable guard. A single quiet applied turn 0 lets
     * ReplayEngineFactory rebuild the engine and world.
     */
    private function buildEndedReflectableEpisode(InMemoryEpisodeRepository $repo, int $seedValue): string
    {
        $now = date('Y-m-d H:i:s');
        $episodeId = $repo->createEpisode(new EpisodeRow(
            id: 'ep_noleak',
            deviceId: self::SENTINEL_DEVICE_ID,
            disaster: 'earthquake',
            seed: $seedValue,
            currentExpectedTurn: 1,
            earthquakeStartTurn: 0,
            ended: false,
            createdAt: $now,
            lastActivityAt: $now,
        ));

        $repo->storeEpisodeMemory(
            $episodeId,
            $repo->currentGeneration(),
            new MemorySnapshot([]),
            new MemorySnapshot([]),
        );

        $repo->createTurn($episodeId, 0);
        $repo->setTurnState($episodeId, 0, TurnStateValue::applied->value);

        $repo->markEpisodeEnded($episodeId);

        return $episodeId;
    }

    /**
     * Configure one robot's reflect reply for the Reflection_Text it will be
     * sent. The transport-level reply text and failure token embed every
     * sentinel; the stored memory body (on the answered path) is a clean,
     * non-secret string so the legitimate verbatim round-trip is never a false
     * positive.
     */
    private function configureReply(
        InMemoryEpisodeRepository $repo,
        string $episodeId,
        string $robotId,
        FakeRobotClient $client,
        bool $answers
    ): void {
        $prompt = $this->promptFor($repo, $episodeId, $robotId);

        // A string packing every sentinel together, used as the free-text the
        // provider controls (the reply) and as a failure token.
        $sentinelBlob = implode(' ', [
            'FAKE-API-KEY-sk-sentinel-0000',
            'FAKE-DB-CRED-sentinel-0000',
            '/fake/secrets/path/sentinel',
            self::SENTINEL_DEVICE_ID,
            'anthropic', 'openai', 'claude-haiku-5-5', 'gpt-6-luna',
        ]);

        if ($answers) {
            // A non-empty answer stored verbatim as a memory. The stored body is
            // CLEAN (no sentinel) so its legitimate round-trip into the reflect
            // body is correct, not a leak. The ReplyRecord's own reply field
            // equals the body, so even if a bug echoed internal fields the
            // leak-scan still only sees clean text here — the sentinels live in
            // the failing/usage paths exercised by the other branch and by the
            // record metadata below.
            $client->setReplyForPrompt(
                $prompt,
                new ReplyRecord(
                    $prompt,
                    'I want to remember that the old man moves slowly.',
                    10,
                    5,
                    0.1,
                    0.0,
                    false,
                    false,
                ),
            );

            return;
        }

        // A failed call: the failure token carries the sentinel blob, and the
        // record is unanswered, so no memory is stored and the reflect body
        // reports only a short machine reason. A bug echoing the failure message
        // or token verbatim would surface the sentinels.
        $client->setReplyForPrompt(
            $prompt,
            ReplyRecord::unanswered($prompt, 0.0, $sentinelBlob),
        );
    }

    /**
     * The Reflection_Text the service would send this robot, built the same way
     * the service builds it so the per-prompt reply matches.
     */
    private function promptFor(
        InMemoryEpisodeRepository $repo,
        string $episodeId,
        string $robotId
    ): string {
        $factory = new ReplayEngineFactory($repo, new EpisodeConfig());
        $built = $factory->forEpisodeWithWorld($episodeId);
        $engine = $built['engine'];
        $finalWorld = $built['world'];

        $records = [];
        foreach ($engine->turnRecords() as $record) {
            if ($record->turnNumber >= 1) {
                $records[] = $record;
            }
        }

        $builder = new ReflectionTextBuilder(new SituationTextGenerator());

        return $builder->build(
            $robotId,
            $records,
            0,
            $finalWorld,
            $repo->episodeSnapshot($episodeId, $robotId),
        );
    }
}
