<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Memory\MemoryService;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryUsageRepository;
use PoltergeistSwitch\TurnLoop\Clients\FakeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\DailyLimitPolicy;
use PoltergeistSwitch\TurnLoop\EpisodeStarter;
use PoltergeistSwitch\TurnLoop\IpHasher;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\TurnService;

require_once __DIR__ . '/../../../api/bootstrap.php';

/**
 * Property-based test for the cross-site Origin guard of ps_dispatch (Eris).
 *
 * Feature: public-lab, Property 25: Cross-site POST is refused and changes nothing
 *
 * **Validates: Requirements 19.1, 19.2, 19.3, 19.5, 19.6**
 */
class CrossSitePropertyTest extends TestCase
{
    use TestTrait;

    private const NOW = 1_800_000_000;
    private const DEVICE = '00112233445566778899aabbccddeeff';
    private const IP = '198.51.100.23';

    /** Exact values, near misses and odd strings. */
    private const POOL = [
        'https://kiro.pyol.net',
        'https://kiro.pyol.net/',
        'https://KIRO.pyol.net',
        'https://kiro.pyol.net:8443',
        'http://kiro.pyol.net',
        'https://evil.example',
        'null',
        '',
        ' ',
        'https://other.example',
    ];

    /** @return array<string,mixed> */
    private function fixture(): array
    {
        $repo = new InMemoryEpisodeRepository();
        $usage = new InMemoryUsageRepository(fn (): int => self::NOW);
        $robotA = new FakeRobotClient();
        $robotB = new FakeRobotClient();
        $recorder = new FakeRecorderClient();
        $turns = new TurnService($robotA, $robotB, $recorder, new ReplayEngineFactory($repo), $repo, new Config());
        $memory = new MemoryService($repo);
        $starter = new EpisodeStarter(
            $turns,
            $memory,
            $usage,
            new DailyLimitPolicy(1000, 1000, 1000),
            fn (): int => self::NOW,
        );

        return compact('repo', 'usage', 'robotA', 'robotB', 'turns', 'memory', 'starter');
    }

    /**
     * @param array<string,mixed> $f
     * @param list<string>        $allowed
     */
    private function post(array $f, string $route, ?string $origin, array $allowed): \PsApiResponse
    {
        return ps_dispatch(
            $f['turns'],
            'POST',
            $route,
            ['ps_device' => self::DEVICE],
            [],
            null,
            $f['memory'],
            null,
            $f['starter'],
            self::IP,
            new IpHasher(str_repeat('ab', 32)),
            $origin,
            $allowed,
        );
    }

    public function testCrossSitePostIsRefusedExactlyWhenNotAListMemberAndChangesNothing(): void
    {
        // Feature: public-lab, Property 25: Cross-site POST is refused and changes nothing
        $this
            ->limitTo(100)
            ->forAll(
                Generators::oneOf(Generators::elements(...self::POOL), Generators::string()),
                Generators::seq(Generators::elements(...self::POOL)),
                Generators::elements('episodes', 'memory/reset', 'episodes/ep_unknown/disaster'),
            )
            ->then(function (string $origin, array $allowed, string $route): void {
                $allowed = array_values($allowed);
                $label = 'origin ' . json_encode($origin) . ' allowed ' . json_encode($allowed) . " route $route";

                $f = $this->fixture();
                $rowsBefore = $f['usage']->rows;
                $runningBefore = $f['repo']->countRunningEpisodes();
                $callsBefore = $f['robotA']->callCount() + $f['robotB']->callCount();

                $r = $this->post($f, $route, $origin, $allowed);

                if (!in_array($origin, $allowed, true)) {
                    $this->assertSame(403, $r->status, $label);
                    $this->assertSame('{"error":"cross_site"}', $r->body, $label);
                    $this->assertSame('no-store', $r->headers['Cache-Control'] ?? null, $label);
                    $this->assertArrayNotHasKey('Set-Cookie', $r->headers, $label);
                    $this->assertSame($rowsBefore, $f['usage']->rows, "usage changed $label");
                    $this->assertSame($runningBefore, $f['repo']->countRunningEpisodes(), "episode created $label");
                    $this->assertSame($callsBefore, $f['robotA']->callCount() + $f['robotB']->callCount(), $label);

                    return;
                }

                // A listed Origin is answered as with no Origin header at all.
                $g = $this->fixture();
                $baseline = $this->post($g, $route, null, $allowed);

                $this->assertNotSame(403, $r->status, $label);
                $this->assertSame($baseline->status, $r->status, $label);
                $this->assertSame($baseline->headers['Cache-Control'] ?? null, $r->headers['Cache-Control'] ?? null, $label);
                $this->assertSame(
                    array_key_exists('Set-Cookie', $baseline->headers),
                    array_key_exists('Set-Cookie', $r->headers),
                    $label
                );
                $this->assertSame(
                    json_decode($baseline->body, true)['error'] ?? null,
                    json_decode($r->body, true)['error'] ?? null,
                    $label
                );
            });
    }

    public function testNoOriginAndGetAreNeverRefusedForThisReason(): void
    {
        // Feature: public-lab, Property 25: Cross-site POST is refused and changes nothing
        $this
            ->limitTo(100)
            ->forAll(
                Generators::seq(Generators::elements(...self::POOL)),
                Generators::oneOf(Generators::elements(...self::POOL), Generators::string()),
            )
            ->then(function (array $allowed, string $origin): void {
                $allowed = array_values($allowed);
                $f = $this->fixture();

                $noOrigin = $this->post($f, 'memory/reset', null, $allowed);
                $this->assertNotSame(403, $noOrigin->status);

                $get = ps_dispatch(
                    $f['turns'],
                    'GET',
                    'memory',
                    ['ps_device' => self::DEVICE],
                    [],
                    null,
                    $f['memory'],
                    null,
                    $f['starter'],
                    self::IP,
                    new IpHasher(str_repeat('ab', 32)),
                    $origin,
                    $allowed,
                );
                $this->assertNotSame(403, $get->status);
            });
    }
}
