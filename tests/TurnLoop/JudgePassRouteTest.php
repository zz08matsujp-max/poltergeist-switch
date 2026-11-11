<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\TurnLoop;

use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\TurnLoop\Clients\FakeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\FakeRobotClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\Http\SecurityHeaders;
use PoltergeistSwitch\TurnLoop\Judge\JudgeCookie;
use PoltergeistSwitch\TurnLoop\Judge\JudgePass;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\TurnService;

require_once __DIR__ . '/../../api/bootstrap.php';

/**
 * The judge-pass route through ps_dispatch (public-lab Requirements 21.3,
 * 21.8-21.13, 21.19, 21.32, 14.35). All values are generated; no real pass,
 * hash or key is used. No AI call, no DB.
 */
class JudgePassRouteTest extends TestCase
{
    private const ALLOWED = 'https://kiro.pyol.net';
    private const FOREIGN = 'https://evil.example';

    private string $dir;
    private string $pass;
    private string $hash;
    private int $until;
    private TurnService $turns;
    private ?JudgeCookie $judge;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ps_judge_route_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
        file_put_contents($this->dir . '/judge_key.key', bin2hex(random_bytes(32)));

        $this->pass = JudgePass::generate();
        $this->hash = JudgePass::hash($this->pass);
        $config = new Config();
        $this->until = $config->judgeUntilUnix();

        $repo = new InMemoryEpisodeRepository();
        $this->turns = new TurnService(
            new FakeRobotClient(),
            new FakeRobotClient(),
            new FakeRecorderClient(),
            new ReplayEngineFactory($repo),
            $repo,
            $config,
        );
        $this->judge = ps_build_judge_cookie(
            ['judge_pass_sha256' => $this->hash, 'secrets_dir' => $this->dir],
            $config,
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

    /** @param array<string,string> $cookies */
    private function call(
        string $method,
        string $body = '',
        ?int $now = null,
        array $cookies = [],
        ?string $origin = null,
        ?JudgeCookie $judge = null,
        bool $useDefaultJudge = true,
    ): \PsApiResponse {
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
            $origin,
            [self::ALLOWED],
            $useDefaultJudge ? $this->judge : $judge,
            $body,
            $now ?? ($this->until - 3600),
        );
    }

    private function passBody(string $pass): string
    {
        return (string) json_encode(['pass' => $pass]);
    }

    private function assertCommonHeaders(\PsApiResponse $r): void
    {
        $this->assertSame('no-store', $r->headers['Cache-Control'] ?? null);
        foreach (SecurityHeaders::all() as $name => $value) {
            $this->assertSame($value, $r->headers[$name] ?? null, $name);
        }
    }

    private function assertNoSecrets(\PsApiResponse $r): void
    {
        $all = $r->body . "\n" . implode("\n", array_keys($r->headers)) . "\n" . implode("\n", $r->headers);
        $this->assertStringNotContainsString($this->pass, $all);
        $this->assertStringNotContainsString($this->hash, $all);
    }

    /** @return array<string,array{array<string,mixed>}> */
    public static function offConfigs(): array
    {
        $hash = hash('sha256', bin2hex(random_bytes(8)));

        return [
            'no hash' => [[]],
            'uppercase hash' => [['judge_pass_sha256' => strtoupper($hash)]],
            'short hash' => [['judge_pass_sha256' => substr($hash, 1)]],
            'non-hex hash' => [['judge_pass_sha256' => str_repeat('z', 64)]],
            'non-string hash' => [['judge_pass_sha256' => 12345]],
            'empty hash' => [['judge_pass_sha256' => '']],
        ];
    }

    /**
     * @dataProvider offConfigs
     * @param array<string,mixed> $local
     */
    public function testMalformedOrMissingHashTurnsJudgeModeOff(array $local): void
    {
        $local['secrets_dir'] = $this->dir;
        $this->assertNull(ps_build_judge_cookie($local, new Config()));
    }

    public function testMissingKeyTurnsJudgeModeOff(): void
    {
        $empty = sys_get_temp_dir() . '/ps_judge_nokey_' . bin2hex(random_bytes(6));
        mkdir($empty, 0500);
        $prev = ini_set('error_log', '/dev/null');
        try {
            $this->assertNull(ps_build_judge_cookie(
                ['judge_pass_sha256' => $this->hash, 'secrets_dir' => $empty],
                new Config(),
            ));
        } finally {
            ini_set('error_log', (string) $prev);
            chmod($empty, 0700);
            rmdir($empty);
        }
    }

    public function testJudgeModeOffGivesPost404AndGetFalse(): void
    {
        $post = $this->call('POST', $this->passBody($this->pass), null, [], null, null, false);
        $this->assertSame(404, $post->status);
        $this->assertSame('{"error":"not_found"}', $post->body);
        $this->assertArrayNotHasKey('Set-Cookie', $post->headers);
        $this->assertCommonHeaders($post);

        $get = $this->call('GET', '', null, [], null, null, false);
        $this->assertSame(200, $get->status);
        $this->assertSame('{"judge":false,"until":null}', $get->body);
        $this->assertCommonHeaders($get);
        $this->assertNoSecrets($post);
        $this->assertNoSecrets($get);
    }

    public function testForeignOriginIsRefusedWithoutCookie(): void
    {
        $r = $this->call('POST', $this->passBody($this->pass), null, [], self::FOREIGN);

        $this->assertSame(403, $r->status);
        $this->assertSame('{"error":"cross_site"}', $r->body);
        $this->assertArrayNotHasKey('Set-Cookie', $r->headers);
        $this->assertCommonHeaders($r);
        $this->assertNoSecrets($r);
    }

    public function testRightPassBeforeJudgingEndIssuesOneCookie(): void
    {
        $r = $this->call('POST', $this->passBody($this->pass), null, [], self::ALLOWED);

        $this->assertSame(200, $r->status);
        $this->assertSame('{"judge":true,"until":"2027-01-29T21:00:00Z"}', $r->body);
        $this->assertCommonHeaders($r);

        $setCookies = array_filter(array_keys($r->headers), static fn ($n): bool => strtolower((string) $n) === 'set-cookie');
        $this->assertCount(1, $setCookies);
        $cookie = $r->headers['Set-Cookie'];
        $this->assertMatchesRegularExpression(
            '/\Aps_judge=' . $this->until . '\.[0-9a-f]{64}; Expires=[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT; Path=\/; HttpOnly; Secure; SameSite=Strict\z/',
            $cookie
        );
        $this->assertStringContainsString('Expires=Fri, 29 Jan 2027 21:00:00 GMT', $cookie);
        $this->assertNoSecrets($r);
    }

    public function testWrongPassIsRefused(): void
    {
        $r = $this->call('POST', $this->passBody(JudgePass::generate()));

        $this->assertSame(403, $r->status);
        $this->assertSame('{"error":"judge_pass_invalid"}', $r->body);
        $this->assertArrayNotHasKey('Set-Cookie', $r->headers);
        $this->assertCommonHeaders($r);
        $this->assertNoSecrets($r);
    }

    public function testRightPassAtJudgingEndIsExpired(): void
    {
        foreach ([$this->until, $this->until + 1] as $now) {
            $r = $this->call('POST', $this->passBody($this->pass), $now);

            $this->assertSame(403, $r->status);
            $this->assertSame('{"error":"judge_pass_expired"}', $r->body);
            $this->assertArrayNotHasKey('Set-Cookie', $r->headers);
            $this->assertCommonHeaders($r);
            $this->assertNoSecrets($r);
        }
    }

    public function testBadBodiesGive400(): void
    {
        $padLen = 513 - strlen((string) json_encode(['pass' => $this->pass, 'x' => '']));
        $big = (string) json_encode(['pass' => $this->pass, 'x' => str_repeat('a', $padLen)]);
        $this->assertSame(513, strlen($big));

        $bodies = [
            'not json' => bin2hex(random_bytes(6)),
            'empty' => '',
            'no pass' => (string) json_encode(['other' => $this->pass]),
            'numeric pass' => (string) json_encode(['pass' => random_int(1, 999999)]),
            'array pass' => (string) json_encode(['pass' => [$this->pass]]),
            'json string' => (string) json_encode($this->pass),
            '513 bytes' => $big,
        ];
        foreach ($bodies as $label => $body) {
            $r = $this->call('POST', $body);

            $this->assertSame(400, $r->status, $label);
            $this->assertSame('{"error":"bad_request"}', $r->body, $label);
            $this->assertArrayNotHasKey('Set-Cookie', $r->headers, $label);
            $this->assertCommonHeaders($r);
            $this->assertNoSecrets($r);
        }
    }

    public function testGetWithIssuedCookieIsJudge(): void
    {
        $post = $this->call('POST', $this->passBody($this->pass));
        preg_match('/\Aps_judge=([^;]+);/', $post->headers['Set-Cookie'], $m);

        $r = $this->call('GET', '', null, ['ps_judge' => $m[1]]);

        $this->assertSame(200, $r->status);
        $this->assertSame('{"judge":true,"until":"2027-01-29T21:00:00Z"}', $r->body);
        $this->assertCommonHeaders($r);
        $this->assertNoSecrets($r);
    }

    public function testGetWithTamperedExpiredOrNoCookieIsNotJudge(): void
    {
        $post = $this->call('POST', $this->passBody($this->pass));
        preg_match('/\Aps_judge=([^;]+);/', $post->headers['Set-Cookie'], $m);
        $good = $m[1];
        $last = substr($good, -1);
        $tampered = substr($good, 0, -1) . ($last === 'a' ? 'b' : 'a');

        $cases = [
            'tampered' => [['ps_judge' => $tampered], null],
            'expired' => [['ps_judge' => $good], $this->until],
            'after end' => [['ps_judge' => $good], $this->until + 10],
            'no cookie' => [[], null],
            'garbage' => [['ps_judge' => bin2hex(random_bytes(8))], null],
        ];
        foreach ($cases as $label => [$cookies, $now]) {
            $r = $this->call('GET', '', $now, $cookies);

            $this->assertSame(200, $r->status, $label);
            $this->assertSame('{"judge":false,"until":null}', $r->body, $label);
            $this->assertCommonHeaders($r);
            $this->assertNoSecrets($r);
        }
    }
}
