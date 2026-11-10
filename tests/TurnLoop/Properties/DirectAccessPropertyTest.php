<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;
use PoltergeistSwitch\TurnLoop\Clients\FakeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\TurnService;
use PoltergeistSwitch\TurnLoop\Values\TurnStateValue;

require_once __DIR__ . '/../../../api/bootstrap.php';

/**
 * Feature: public-lab, Property 10: Direct access does not depend on the caller
 *
 * For any existing episode and any caller (no cookie, the owner's cookie,
 * another Device's cookie), `GET /api/episodes/{id}` returns the same status
 * and bytes. In-memory fakes only; no real AI call.
 *
 * Validates: Requirements 7.3
 */
class DirectAccessPropertyTest extends TestCase
{
    use TestTrait;

    private const NOW = 1_800_000_000;
    private const OWNER = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    /**
     * Feature: public-lab, Property 10: Direct access does not depend on the caller
     *
     * Validates: Requirements 7.3
     */
    public function testDirectAccessDoesNotDependOnTheCaller(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generators::int(),         // seed
                Generators::choose(0, 3),  // turns applied before the GET
                Generators::vector(32, Generators::elements(str_split('0123456789abcdef'))),
            )
            ->then(function (int $seed, int $turns, array $otherChars): void {
                $other = implode('', $otherChars);
                if ($other === self::OWNER) {
                    $other = strrev($other);
                }

                $repo = new InMemoryEpisodeRepository(new Config(), fn (): int => self::NOW);
                $service = new TurnService(
                    new FakeRobotClient(),
                    new FakeRobotClient(),
                    new FakeRecorderClient(),
                    new ReplayEngineFactory($repo),
                    $repo,
                    new Config(),
                    null,
                    fn (): string => date('Y-m-d H:i:s', self::NOW),
                );
                $id = $service->createEpisode(self::OWNER, seed: $seed)->episodeId;
                $service->flipEarthquake($id);
                for ($i = 0; $i < $turns; $i++) {
                    $episode = $repo->findEpisode($id);
                    self::assertNotNull($episode);
                    if ($episode->ended) {
                        break;
                    }
                    $n = $episode->currentExpectedTurn;
                    $guard = 0;
                    do {
                        $service->ask($id, $n);
                        $turn = $repo->findTurn($id, $n);
                        self::assertNotNull($turn);
                    } while ($turn->state === TurnStateValue::waiting && ++$guard < 5);
                    $service->apply($id, $n);
                }

                $results = [];
                foreach ([[], [PS_DEVICE_COOKIE => self::OWNER], [PS_DEVICE_COOKIE => $other]] as $cookies) {
                    $response = ps_dispatch($service, 'GET', 'episodes/' . $id, $cookies);
                    $results[] = [$response->status, $response->body];
                }

                $this->assertSame(200, $results[0][0]);
                $this->assertSame($results[0], $results[1], 'the owner sees the same status and bytes');
                $this->assertSame($results[0], $results[2], 'another Device sees the same status and bytes');
            });
    }
}
