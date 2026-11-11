<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop\Properties;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Memory\MemoryService;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryEpisodeRepository;
use PoltergeistSwitch\Tests\TurnLoop\InMemoryUsageRepository;
use PoltergeistSwitch\TurnLoop\DailyLimitPolicy;
use PoltergeistSwitch\TurnLoop\EpisodeStarter;
use PoltergeistSwitch\TurnLoop\Http\ApiError;
use PoltergeistSwitch\TurnLoop\TurnService;
use PoltergeistSwitch\TurnLoop\UsageKeys;
use PoltergeistSwitch\TurnLoop\Values\EpisodeView;

/**
 * Scripted TurnService: no repository, no AI. The mode decides what a start does.
 */
final class ScriptedTurnService extends TurnService
{
    public string $mode = 'ok';

    public function assertCanStart(string $deviceId, bool $ignoreSiteCap = false): void
    {
        if ($this->mode === 'conflict') {
            throw ApiError::conflict('device_already_running');
        }
    }

    public function createEpisode(string $deviceId, ?int $seed = null, string $disaster = 'earthquake', bool $ignoreSiteCap = false): EpisodeView
    {
        if ($this->mode === 'create_fail') {
            throw new \RuntimeException('createEpisode failed');
        }
        if ($this->mode === 'create_409') {
            // the racing 409 raised inside createEpisode itself
            throw ApiError::conflict('too_many_running');
        }
        return new EpisodeView('ep_scripted', $disaster, 1, null, false, []);
    }
}

/**
 * Property-based test for starts that count nothing (Eris), over
 * {@see EpisodeStarter} with {@see InMemoryUsageRepository}.
 *
 * Feature: public-lab, Property 13: Denied or failed starts count nothing
 *
 * **Validates: Requirements 13.9**
 *
 * The oracle counts only starts that created an episode: a start whose
 * createEpisode succeeded ('ok', or 'snapshot_fail' where the failure comes
 * after the episode exists). A 429, a 409 and a failing createEpisode leave
 * every count as it was.
 */
class StartReleasePropertyTest extends TestCase
{
    use TestTrait;

    private const DEVICES = ['dev_a', 'dev_b'];
    private const IPS = ['ip_a', 'ip_b', null];
    private const MODES = ['ok', 'ok', 'conflict', 'create_fail', 'create_409', 'snapshot_fail'];
    private const LIMIT_DEVICE = 3;
    private const LIMIT_IP = 4;
    private const LIMIT_SITE = 7;
    private const DAY_UNIX = 1793750400; // fixed instant; the day never changes in a run

    public function testDeniedOrFailedStartsCountNothing(): void
    {
        // Feature: public-lab, Property 13: Denied or failed starts count nothing
        $this
            ->limitTo(100)
            ->forAll(
                Generators::seq(
                    Generators::tuple(
                        Generators::elements(...self::MODES),
                        Generators::elements(...self::DEVICES),
                        Generators::elements(...self::IPS),
                    ),
                ),
            )
            ->then(function (array $steps): void {
                $usage = new InMemoryUsageRepository();
                $policy = new DailyLimitPolicy(self::LIMIT_DEVICE, self::LIMIT_IP, self::LIMIT_SITE);
                $turns = (new \ReflectionClass(ScriptedTurnService::class))->newInstanceWithoutConstructor();
                $good = new MemoryService(new InMemoryEpisodeRepository());
                // A MemoryService without its repository throws on first use: a failure after the episode exists.
                $broken = (new \ReflectionClass(MemoryService::class))->newInstanceWithoutConstructor();

                $day = DailyLimitPolicy::utcDay(self::DAY_UNIX);
                $model = ['device' => [], 'ip' => [], 'site' => 0];

                foreach ($steps as $i => [$mode, $device, $ip]) {
                    $turns->mode = $mode;
                    $starter = new EpisodeStarter(
                        $turns,
                        $mode === 'snapshot_fail' ? $broken : $good,
                        $usage,
                        $policy,
                        static fn (): int => self::DAY_UNIX,
                    );
                    $keys = new UsageKeys('k_' . $device, $ip === null ? null : 'k_' . $ip);

                    $before = $usage->counts($day, $keys);
                    $siteBefore = $usage->rows;

                    $denied = $mode !== 'conflict' && (
                        ($model['device'][$device] ?? 0) >= self::LIMIT_DEVICE
                        || ($ip !== null && ($model['ip'][$ip] ?? 0) >= self::LIMIT_IP)
                        || $model['site'] >= self::LIMIT_SITE
                    );

                    $thrown = null;
                    $view = null;
                    try {
                        $view = $starter->start($device, $keys);
                    } catch (\Throwable $e) {
                        $thrown = $e;
                    }

                    $label = "(step $i, mode $mode, device $device, ip " . ($ip ?? 'none') . ')';

                    if ($mode === 'conflict') {
                        $this->assertInstanceOf(ApiError::class, $thrown, $label);
                        $this->assertSame(409, $thrown->status, $label);
                        $this->assertSame($siteBefore, $usage->rows, "409 changed usage $label");
                    } elseif ($denied) {
                        $this->assertInstanceOf(ApiError::class, $thrown, $label);
                        $this->assertSame(429, $thrown->status, $label);
                        $this->assertSame($siteBefore, $usage->rows, "429 changed usage $label");
                    } elseif ($mode === 'create_fail' || $mode === 'create_409') {
                        $this->assertNotNull($thrown, $label);
                        $this->assertNull($view, $label);
                        $this->assertSame($before, $usage->counts($day, $keys), "failed create kept a count $label");
                        // A released row may remain at zero; only non-zero counts matter.
                        $nonZero = static fn (array $rows): array => array_filter(
                            array_map(static fn (array $r): int => $r['starts'], $rows),
                            static fn (int $n): bool => $n > 0,
                        );
                        $this->assertSame($nonZero($siteBefore), $nonZero($usage->rows), "failed create changed counts $label");
                    } else {
                        // 'ok' or 'snapshot_fail': the episode exists, so the start is counted.
                        if ($mode === 'ok') {
                            $this->assertNull($thrown, $label);
                        } else {
                            $this->assertNotNull($thrown, $label);
                        }
                        $model['device'][$device] = ($model['device'][$device] ?? 0) + 1;
                        if ($ip !== null) {
                            $model['ip'][$ip] = ($model['ip'][$ip] ?? 0) + 1;
                        }
                        $model['site']++;
                    }

                    // Whatever happened, the stored counts equal the model.
                    $after = $usage->counts($day, $keys);
                    $this->assertSame($model['device'][$device] ?? 0, $after['device'], "device count $label");
                    $this->assertSame($ip === null ? null : ($model['ip'][$ip] ?? 0), $after['ip'], "ip count $label");
                    $this->assertSame($model['site'], $after['site'], "site count $label");
                }
            });
    }
}
