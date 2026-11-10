<?php

declare(strict_types=1);

/**
 * Front controller for the turn-loop HTTP API (design "API Design";
 * Requirement 9).
 *
 * This is the ONLY api/ file a web server runs. It reads the real
 * superglobals, resolves the TurnService, hands everything to ps_dispatch()
 * (which parses the method + route and calls one TurnService method), and
 * emits the resulting response: the status, the headers (always including
 * Cache-Control: no-store), and the JSON body.
 *
 * The route is taken from the `route` query parameter when present
 * (index.php?route=episodes/{id}/...), so the API works even if mod_rewrite is
 * unavailable, and otherwise from the request path after /api/ (the .htaccess
 * rewrite maps clean paths to this controller). All parsing, dispatch, error
 * mapping, and response shaping live in router.php / bootstrap.php; this file
 * only translates between the superglobals and ps_dispatch().
 */

require __DIR__ . '/bootstrap.php';

use PoltergeistSwitch\TurnLoop\IpHasher;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Resolve the route: prefer the explicit ?route= query (works without
// mod_rewrite), else derive it from the path after '/api/'.
$route = '';
if (isset($_GET['route']) && is_string($_GET['route'])) {
    $route = $_GET['route'];
} else {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $path = is_string($path) ? $path : '';
    $pos = strpos($path, '/api/');
    if ($pos !== false) {
        $route = substr($path, $pos + strlen('/api/'));
    } else {
        $route = ltrim($path, '/');
    }
}

/** @var array<string,string> $cookies */
$cookies = [];
foreach ($_COOKIE as $name => $value) {
    if (is_string($name) && is_string($value)) {
        $cookies[$name] = $value;
    }
}

// The query parameters drive the ended-episode list route, which is recognised
// by `ended=1` on the bare episodes collection and reads `limit` (parsed and
// validated in src/). `route` also lives in $_GET; passing the full array is
// fine because the list handler reads only `ended`/`limit`.
/** @var array<string,mixed> $query */
$query = is_array($_GET) ? $_GET : [];

// Build the services inside the try so a missing config / secrets file surfaces
// as a safe 500 rather than a provider call or a leaked path.
try {
    $service = ps_build_turn_service();
    $listService = ps_build_episode_list_service();
    $memoryService = ps_build_memory_service();
    $reflectService = ps_build_reflect_service();

    // Daily limits (public-lab Requirement 13): the starter, the client IP and
    // the salted hasher. No salt means the IP scope is skipped and the Device
    // key falls back to a plain hash (see ps_usage_keys).
    $localConfig = ps_load_local_config();
    $starter = ps_build_episode_starter();
    $clientIp = ps_client_ip($_SERVER, $localConfig);
    $salt = ps_load_ip_salt($localConfig['secrets_dir']);
    $hasher = $salt !== null ? new IpHasher($salt) : null;
    // Allowed Origin values for POST (public-lab Requirement 19): used only
    // when it is a list of strings, else an empty list (every Origin refused).
    $origin = isset($_SERVER['HTTP_ORIGIN']) && is_string($_SERVER['HTTP_ORIGIN'])
        ? $_SERVER['HTTP_ORIGIN']
        : null;
    $allowedOrigins = [];
    $configured = $localConfig['allowed_origins'] ?? null;
    if (is_array($configured) && array_is_list($configured)) {
        $allStrings = true;
        foreach ($configured as $item) {
            if (!is_string($item)) {
                $allStrings = false;
                break;
            }
        }
        if ($allStrings) {
            $allowedOrigins = $configured;
        }
    }
    $response = ps_dispatch(
        $service,
        (string) $method,
        (string) $route,
        $cookies,
        $query,
        $listService,
        $memoryService,
        $reflectService,
        $starter,
        $clientIp,
        $hasher,
        $origin,
        $allowedOrigins,
    );
} catch (\Throwable) {
    // Any failure before/around dispatch (config, secrets, DB connection)
    // becomes a generic 500 with a short machine code only.
    $response = ps_json_response(500, ['error' => 'internal_error']);
}

// Emit the captured response.
http_response_code($response->status);
foreach ($response->headers as $name => $value) {
    // Set-Cookie must not replace a prior one; all our other headers are unique.
    header($name . ': ' . $value, $name !== 'Set-Cookie');
}
echo $response->body;
