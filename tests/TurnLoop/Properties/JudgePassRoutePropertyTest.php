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
use PoltergeistSwitch\TurnLoop\Judge\JudgeCookie;
use PoltergeistSwitch\TurnLoop\Judge\JudgePass;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\TurnService;

require_once __DIR__ . '/../../../api/bootstrap.php';

/**
 * Property-based test for the judge-pass route (Eris). Every pass, hash and
 * key is generated inside the test; no real secret is used.
 *
 * Feature: public-lab, Property 28: Any pass other than the configured one is refused, and judge mode off is a 404
 *
 * **Validates: Requirements 21.3, 21.9, 21.11, 21.12, 21.19**
 */
class JudgePassRoutePropertyTest extends TestCase
{
    use TestTrait;

    private const ALLOWED = 'https://kiro.pyol.net';

    private string $dir;
    private Config $config;
    private TurnService $turns;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ps_judge_prop_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
        file_put_contents($this->dir . '/judge_key.key', bin2hex(random_bytes(32)));

        $this->config = new Config();
        $repo = new InMemoryEpisodeRepository();
        $this->turns = new TurnService(
            new FakeRobotClient(),
            new FakeRobotClient(),
            new FakeRecorderClient(),
            new ReplayEngineFactory($repo),
            $repo,
            $this->config,
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        rmdir($this->dir);
    }

    private static function passFor(int $seed): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', 'pass:' . $seed, true)), '+/', '-_'), '=');
    }

    private function judgeFor(string $pass): JudgeCookie
    {
        $judge = ps_build_judge_cookie(
            ['judge_pass_sha256' => JudgePass::hash($pass), 'secrets_dir' => $this->dir],
            $this->config,
        );
        $this->assertNotNull($judge);

        return $judge;
    }

    /** @param array<string,string> $cookies */
    private function call(?JudgeCookie $judge, string $method, string $body, int $now, array $cookies = []): \PsApiResponse
    {
        return ps_dispatch(
            $this->turns,
            $method,
            'judge-pass',
            $cookies,
            [],
            null,
            null,
            null,
            null,
            null,
            null,
            self::ALLOWED,
            [self::ALLOWED],
            $judge,
            $body,
            $now,
        );
    }

    private function hasSetCookie(\PsApiResponse $r): bool
    {
        foreach (array_keys($r->headers) as $name) {
            if (strtolower((string) $name) === 'set-cookie') {
                return true;
            }
        }

        return false;
    }

    /** A string that is not the pass, built from the pass in one of several ways. */
    private static function otherThan(string $pass, string $kind, string $noise, int $n): string
    {
        switch ($kind) {
            case 'empty':
                return '';
            case 'swapcase':
                return strtoupper($pass) !== $pass ? strtoupper($pass) : strtolower($pass) . 'x';
            case 'hash':
                return JudgePass::hash($pass);
            case 'trailing_space':
                return $pass . ' ';
            case 'trailing_newline':
                return $pass . "\n";
            case 'leading_space':
                return ' ' . $pass;
            case 'shorter':
                return substr($pass, 0, $n % strlen($pass));
            case 'longer':
                return $pass . 'a' . substr($noise, 0, $n % 20);
            case 'one_char_changed':
                $i = $n % strlen($pass);
                $c = $pass[$i] === 'a' ? 'b' : 'a';

                return substr($pass, 0, $i) . $c . substr($pass, $i + 1);
            default:
                return $noise === $pass ? $noise . 'x' : $noise;
        }
    }

    public function testAnyOtherPassIsRefusedWithoutCookie(): void
    {
        // Feature: public-lab, Property 28: Any pass other than the configured one is refused, and judge mode off is a 404
        $this
            ->limitTo(100)
            ->forAll(
                Generators::choose(0, 1_000_000),
                Generators::elements(
                    'empty', 'swapcase', 'hash', 'trailing_space', 'trailing_newline',
                    'leading_space', 'shorter', 'longer', 'one_char_changed', 'random',
                ),
                Generators::string(),
                Generators::choose(0, 1000),
                Generators::choose(-100_000_000, 100_000_000),
            )
            ->then(function (int $seed, string $kind, string $noise, int $n, int $delta): void {
                $pass = self::passFor($seed);
                $other = self::otherThan($pass, $kind, substr($noise, 0, 40), $n);
                $this->assertNotSame($pass, $other);
                $body = json_encode(['pass' => $other], JSON_INVALID_UTF8_SUBSTITUTE);
                $this->assertIsString($body);
                $this->assertLessThanOrEqual(512, strlen($body));

                $now = $this->config->judgeUntilUnix() + $delta;
                $r = $this->call($this->judgeFor($pass), 'POST', $body, $now);

                $this->assertSame(403, $r->status);
                $this->assertSame('{"error":"judge_pass_invalid"}', $r->body);
                $this->assertFalse($this->hasSetCookie($r));
            });
    }

    public function testConfiguredPassIsAcceptedOnlyBeforeJudgingEnd(): void
    {
        // Feature: public-lab, Property 28: Any pass other than the configured one is refused, and judge mode off is a 404
        $this
            ->limitTo(100)
            ->forAll(
                Generators::choose(0, 1_000_000),
                Generators::choose(-100_000_000, 100_000_000),
            )
            ->then(function (int $seed, int $delta): void {
                $pass = self::passFor($seed);
                $judge = $this->judgeFor($pass);
                $end = $this->config->judgeUntilUnix();
                $now = $end + $delta;

                $r = $this->call($judge, 'POST', (string) json_encode(['pass' => $pass]), $now);

                if ($now < $end) {
                    $this->assertSame(200, $r->status);
                    $this->assertTrue($this->hasSetCookie($r));
                    $this->assertSame(1, preg_match('/\Aps_judge=([^;]+);/', $r->headers['Set-Cookie'], $m));
                    $this->assertTrue($judge->isValid($m[1], $now));
                } else {
                    $this->assertSame(403, $r->status);
                    $this->assertSame('{"error":"judge_pass_expired"}', $r->body);
                    $this->assertFalse($this->hasSetCookie($r));
                }
            });
    }

    public function testMalformedOrMissingHashTurnsJudgeModeOff(): void
    {
        // Feature: public-lab, Property 28: Any pass other than the configured one is refused, and judge mode off is a 404
        $this
            ->limitTo(100)
            ->forAll(
                Generators::choose(0, 1_000_000),
                Generators::elements('missing', 'upper', 'short', 'long', 'nonhex', 'int', 'bool', 'array', 'empty'),
                Generators::choose(1, 63),
                Generators::string(),
                Generators::elements('GET', 'POST'),
                Generators::choose(0, 2),
            )
            ->then(function (int $seed, string $kind, int $cut, string $noise, string $method, int $cookieKind): void {
                $pass = self::passFor($seed);
                $hash = JudgePass::hash($pass);
                $local = ['secrets_dir' => $this->dir];
                switch ($kind) {
                    case 'upper':
                        $local['judge_pass_sha256'] = strtoupper('a' . substr($hash, 1));
                        break;
                    case 'short':
                        $local['judge_pass_sha256'] = substr($hash, 0, $cut);
                        break;
                    case 'long':
                        $local['judge_pass_sha256'] = $hash . 'a';
                        break;
                    case 'nonhex':
                        $local['judge_pass_sha256'] = str_repeat('g', 64);
                        break;
                    case 'int':
                        $local['judge_pass_sha256'] = $seed;
                        break;
                    case 'bool':
                        $local['judge_pass_sha256'] = true;
                        break;
                    case 'array':
                        $local['judge_pass_sha256'] = [$hash];
                        break;
                    case 'empty':
                        $local['judge_pass_sha256'] = '';
                        break;
                    default:
                        break;
                }

                $judge = ps_build_judge_cookie($local, $this->config);
                $this->assertNull($judge);

                // A cookie signed by a working service for the same pass, or junk.
                $cookies = [];
                if ($cookieKind === 1) {
                    $good = $this->judgeFor($pass)->issue($this->config->judgeUntilUnix() - 10);
                    $this->assertNotNull($good);
                    $cookies = ['ps_judge' => $good];
                } elseif ($cookieKind === 2) {
                    $cookies = ['ps_judge' => $noise];
                }

                $now = $this->config->judgeUntilUnix() - 10;
                $r = $this->call($judge, $method, (string) json_encode(['pass' => $pass]), $now, $cookies);

                if ($method === 'POST') {
                    $this->assertSame(404, $r->status);
                    $this->assertSame('{"error":"not_found"}', $r->body);
                } else {
                    $this->assertSame(200, $r->status);
                    $this->assertSame('{"judge":false,"until":null}', $r->body);
                }
                $this->assertFalse($this->hasSetCookie($r));
            });
    }
}
