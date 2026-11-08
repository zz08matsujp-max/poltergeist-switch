<?php

declare(strict_types=1);

/**
 * Shared bootstrap for the api/ entry points (design "API Design"; Requirements
 * 9.1-9.6, 9.11, 2.6).
 *
 * The server (onamae.com RS) has no Composer and no vendor/ directory, so this
 * file registers its OWN small PSR-4 autoloader for the two production
 * namespace prefixes and never requires vendor/autoload.php. It also:
 *   - loads the local config (secrets_dir + db credentials, both read from
 *     files outside the web root — never from the repository);
 *   - builds a production TurnService (the two real robot clients, the recorder
 *     client, the PDO repository, and the replay engine factory);
 *   - defines the shared request parsing, JSON response writing, device-cookie
 *     handling, and routing used by the thin api/ handlers.
 *
 * Every response sends Cache-Control: no-store (Requirement 9.11) and never
 * carries a key, credential, filesystem path, or AI provider name
 * (Requirements 2.6, 10.7). Any unexpected failure becomes a generic 500 with
 * a short machine code only, so a missing config or secrets file surfaces as a
 * safe error and never leaks a path or triggers a provider call.
 */

use PoltergeistSwitch\TurnLoop\Clients\ClaudeClient;
use PoltergeistSwitch\TurnLoop\Clients\ClaudeRecorderClient;
use PoltergeistSwitch\TurnLoop\Clients\GptClient;
use PoltergeistSwitch\TurnLoop\Config;
use PoltergeistSwitch\TurnLoop\Http\ApiError;
use PoltergeistSwitch\TurnLoop\ReplayEngineFactory;
use PoltergeistSwitch\TurnLoop\Repository\PdoEpisodeRepository;
use PoltergeistSwitch\TurnLoop\SecretLoader;
use PoltergeistSwitch\TurnLoop\TurnService;
use PoltergeistSwitch\TurnLoop\Values\EpisodeView;
use PoltergeistSwitch\TurnLoop\Values\RobotFourLayers;
use PoltergeistSwitch\TurnLoop\Values\TurnView;

/*
 * -------------------------------------------------------------------------
 * 1. PSR-4 autoloader (no Composer / vendor on the server).
 * -------------------------------------------------------------------------
 * Maps the two production namespace prefixes to their directories under src/,
 * resolving nested sub-namespaces (e.g.
 * PoltergeistSwitch\TurnLoop\Clients\ClaudeClient ->
 * src/TurnLoop/Clients/ClaudeClient.php). Production code must NEVER require
 * vendor/autoload.php.
 */
spl_autoload_register(static function (string $class): void {
    static $prefixes = [
        'PoltergeistSwitch\\Engine\\'   => __DIR__ . '/../src/Engine/',
        'PoltergeistSwitch\\TurnLoop\\' => __DIR__ . '/../src/TurnLoop/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($class, $prefix, $len) !== 0) {
            continue;
        }

        $relative = substr($class, $len);                 // e.g. Clients\ClaudeClient
        $path = $baseDir . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require $path;
        }

        return;
    }
});

/*
 * -------------------------------------------------------------------------
 * 2. Config loading.
 * -------------------------------------------------------------------------
 */

/**
 * Load the uncommitted local config next to this file (api/config.local.php,
 * gitignored). It returns an array carrying at least `secrets_dir`. A missing
 * or malformed config throws ApiError (500) so the request stops before any
 * provider call and no path leaks (Requirements 2.4, 2.6).
 *
 * @return array{secrets_dir:string}
 */
function ps_load_local_config(): array
{
    $path = __DIR__ . '/config.local.php';
    if (!is_file($path) || !is_readable($path)) {
        throw ps_internal_error();
    }

    $config = @include $path;
    if (!is_array($config) || !isset($config['secrets_dir']) || !is_string($config['secrets_dir'])) {
        throw ps_internal_error();
    }

    return $config;
}

/**
 * Read the database credentials from a `db.php` file inside the secrets
 * directory (outside the web root, outside the repository). The file returns an
 * array with `dsn`, `user`, `password`. A missing or malformed file throws
 * ApiError (500) so no path or credential leaks (Requirements 2.3, 2.6).
 *
 * @return array{dsn:string,user:string,password:string}
 */
function ps_load_db_credentials(string $secretsDir): array
{
    $path = rtrim($secretsDir, '/\\') . DIRECTORY_SEPARATOR . 'db.php';
    if (!is_file($path) || !is_readable($path)) {
        throw ps_internal_error();
    }

    $db = @include $path;
    if (!is_array($db)
        || !isset($db['dsn'], $db['user'], $db['password'])
        || !is_string($db['dsn'])
        || !is_string($db['user'])
        || !is_string($db['password'])
    ) {
        throw ps_internal_error();
    }

    return $db;
}

/**
 * A generic 500 ApiError carrying a short machine code only. Used for any
 * configuration/secret/DB failure so the response never leaks a message, path,
 * stack, key, credential, or provider name (Requirements 2.6, 10.7).
 */
function ps_internal_error(): ApiError
{
    return new ApiError(500, 'internal_error');
}

/*
 * -------------------------------------------------------------------------
 * 3. Production TurnService builder.
 * -------------------------------------------------------------------------
 */

/**
 * Build the production TurnService: the two real robot clients, the recorder
 * client, the PDO/MySQL repository, and the replay engine factory. API keys are
 * read through SecretLoader; DB credentials from the secrets dir. Any failure
 * surfaces as a generic 500 ApiError (never a provider call, never a leak).
 */
function ps_build_turn_service(): TurnService
{
    $config = ps_load_local_config();
    $secretsDir = $config['secrets_dir'];

    // SecretLoader reads the secrets_dir from an uncommitted local Config_File
    // and each named key from a <name>.key file in that directory. Reuse it for
    // the API keys (task 3.2). We point it at this api/config.local.php.
    $secrets = new SecretLoader(__DIR__ . '/config.local.php');

    $db = ps_load_db_credentials($secretsDir);

    try {
        $pdo = new PDO(
            $db['dsn'],
            $db['user'],
            $db['password'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ],
        );
    } catch (\Throwable) {
        // Never leak the DSN, credential, or driver message.
        throw ps_internal_error();
    }

    $cfg = new Config();
    $repo = new PdoEpisodeRepository($pdo);

    // The key names match the <name>.key files in the secrets directory.
    $robotA = new ClaudeClient($secrets->readKey('anthropic'), $cfg);
    $robotB = new GptClient($secrets->readKey('openai'), $cfg);
    $recorder = new ClaudeRecorderClient($secrets->readKey('anthropic'), $cfg);

    return new TurnService(
        $robotA,
        $robotB,
        $recorder,
        new ReplayEngineFactory($repo),
        $repo,
        $cfg,
    );
}

/*
 * -------------------------------------------------------------------------
 * 4. Shared request / response / routing.
 * -------------------------------------------------------------------------
 * These functions keep the five api/ handlers thin. They are written so a test
 * can drive them without a real web server: the request is a plain array and
 * the response is returned as a value object instead of touching PHP's output
 * globals directly. index.php wires the real superglobals in.
 */

/**
 * An HTTP response the api/ layer produces: a status, headers (always including
 * Cache-Control: no-store), and a JSON-encoded body. Kept as a plain value so a
 * test can inspect the status, the headers, and the body without a web server.
 */
final class PsApiResponse
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {}
}

/**
 * The device cookie name and attributes. The id is a CSPRNG value; the cookie
 * is HttpOnly, Secure, SameSite=Lax, Path=/, with a 30-day lifetime.
 *
 * NOTE: the 30-day lifetime is a DRAFT — the product brief lists the device
 * cookie lifetime as an open question, so the maintainer may change it.
 */
const PS_DEVICE_COOKIE = 'ps_device';
const PS_DEVICE_COOKIE_MAX_AGE = 60 * 60 * 24 * 30; // 30 days (draft)

/**
 * Mint a new random device id using a CSPRNG (Requirement 9.6).
 */
function ps_new_device_id(): string
{
    return bin2hex(random_bytes(16));
}

/**
 * Build the Set-Cookie header value for a freshly minted device id with the
 * drafted attributes (HttpOnly, Secure, SameSite=Lax, Path=/, 30-day lifetime).
 */
function ps_device_cookie_header(string $deviceId): string
{
    return PS_DEVICE_COOKIE . '=' . $deviceId
        . '; Max-Age=' . PS_DEVICE_COOKIE_MAX_AGE
        . '; Path=/'
        . '; HttpOnly'
        . '; Secure'
        . '; SameSite=Lax';
}

/**
 * Encode a value as a JSON API response with the no-store header and, when a
 * device cookie must be set, the Set-Cookie header. Never carries a key,
 * credential, path, or provider name — the callers pass only EpisodeView
 * arrays or ApiError bodies.
 *
 * @param array<string,mixed> $body
 * @param string|null         $setDeviceId when non-null, a Set-Cookie for this id is added
 */
function ps_json_response(int $status, array $body, ?string $setDeviceId = null): PsApiResponse
{
    $headers = [
        'Content-Type'  => 'application/json; charset=utf-8',
        'Cache-Control' => 'no-store',
    ];
    if ($setDeviceId !== null) {
        $headers['Set-Cookie'] = ps_device_cookie_header($setDeviceId);
    }

    $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        // Encoding should never fail for our own view arrays; fall back to a
        // safe generic error rather than leaking anything.
        $json = '{"error":"internal_error"}';
        $headers['Cache-Control'] = 'no-store';
    }

    return new PsApiResponse($status, $headers, $json);
}

/**
 * Serialize an EpisodeView to the JSON response shape (design "Response shape
 * (EpisodeView)"). Only episode/turn data appears — never a key, credential,
 * path, or provider name.
 *
 * @return array<string,mixed>
 */
function ps_episode_view_to_array(EpisodeView $view): array
{
    $turns = [];
    foreach ($view->turns as $turn) {
        $turns[] = ps_turn_view_to_array($turn);
    }

    return [
        'episodeId'           => $view->episodeId,
        'disaster'            => $view->disaster,
        'currentExpectedTurn' => $view->currentExpectedTurn,
        'earthquakeStartTurn' => $view->earthquakeStartTurn,
        'ended'               => $view->ended,
        'turns'               => $turns,
    ];
}

/**
 * Serialize one TurnView (design "Response shape").
 *
 * @return array<string,mixed>
 */
function ps_turn_view_to_array(TurnView $turn): array
{
    $robots = [];
    foreach ($turn->robots as $robotId => $layers) {
        $robots[$robotId] = ps_robot_layers_to_array($layers);
    }

    return [
        'turnNumber'   => $turn->turnNumber,
        'state'        => $turn->state->value,
        'robots'       => $robots,
        'residentLine' => $turn->residentLine,
    ];
}

/**
 * Serialize one robot's Four_Layers (design "Response shape").
 *
 * @return array<string,mixed>
 */
function ps_robot_layers_to_array(RobotFourLayers $layers): array
{
    $action = $layers->reading->action;

    $reported = [];
    foreach ($action->reported as $item) {
        $reported[] = [
            'quote'   => $item->quote,
            'heardAs' => $item->heardAs,
            'ownForm' => $item->ownForm,
        ];
    }

    return [
        'situationText' => $layers->situationText,
        'reply'         => $layers->reply,
        'noReply'       => $layers->noReply,
        // Short machine token only — never a key, credential, path, provider
        // name, or provider message.
        'replyFailureReason' => $layers->replyFailureReason,
        'reading'       => [
            'operation'   => $action->operation,
            'targetId'    => $action->targetId,
            'targetSpot'  => $action->targetSpot,
            'actionQuote' => $action->actionQuote,
            'words'       => $action->words,
            'reported'    => $reported,
            'unknown'     => $layers->reading->unknown,
            // Short machine token only (same safety rule as above).
            'failureReason' => $layers->reading->failureReason,
        ],
        'judgement'     => [
            'possible'   => $layers->judgement->possible,
            'reason'     => $layers->judgement->reason,
            'dangerous'  => $layers->judgement->dangerous,
            'dangerCode' => $layers->judgement->dangerCode,
            'duration'   => $layers->judgement->duration,
        ],
        'outcome'       => [
            'success'          => $layers->outcome->success,
            'whatHappenedText' => $layers->outcome->whatHappenedText,
            'seenByOtherText'  => $layers->outcome->seenByOtherText,
        ],
    ];
}

require_once __DIR__ . '/router.php';
