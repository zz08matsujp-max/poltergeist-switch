<?php
/**
 * Experiment 0 (pilot): give Claude and ChatGPT only a situation and see what they first say and do.
 * - Robots get only the situation description and one final line. No goals, options, formats or tools.
 * - Both companies get the exact same text as a single message with no system prompt.
 * - Each is called N times under the same conditions to see how runs vary.
 * - Results are only shown on screen (not stored).
 *
 * Usage: https://kiro.pyol.net/pilot/?t=<passphrase>&n=3        (variant A)
 *        https://kiro.pyol.net/pilot/?t=<passphrase>&v=b&n=3    (variant B: lists the objects in the robot's room)
 *        https://kiro.pyol.net/pilot/?t=<passphrase>&mode=memory&n=3
 *          (reflection and memory: 1) variant B earthquake -> 2) reflection -> 3) the same earthquake with that reflection as memory. Added 2026-11-01)
 *        https://kiro.pyol.net/pilot/?t=<passphrase>&mode=varytext
 *          (prints the six varied situation texts without calling any AI; the AI is called via api=vary. Added 2026-11-01)
 *        POST ?t=<passphrase>&api=turn2 {who, text}
 *          (turn-2 pilot: one call with a turn-2 text built from paired turn-1 replies. Added 2026-11-02)
 * Remove from the server when done.
 */

declare(strict_types=1);

// Do not let the nginx cache in front of PHP keep results: every request must call the AI (added 2026-11-01)
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');

// --- Settings ---
const ANTHROPIC_KEY_PATH = '/home/r7833127/public_html/2024020313210611535515.onamaeweb.jp/anthropic.key';
const OPENAI_KEY_PATH    = '/home/r7833127/public_html/2024020313210611535515.onamaeweb.jp/openai.key';
const CLAUDE_MODEL = 'claude-haiku-5-5';
const GPT_MODEL    = 'gpt-6-luna';
const MAX_TOKENS   = 4000;   // leaves room for reasoning tokens (raised 1500 -> 4000 on 2026-11-01 after ChatGPT hit the limit in 3 of 28 runs; same for both)
const MAX_RUNS     = 5;      // max calls per company per page view
// store only the SHA-256 of the passphrase, never the passphrase itself
const TOKEN_SHA256 = '90704bb305e59084572a15ab5b62d30d2252f1dc17fb0bd0419e368746bd4742';

// --- Situation text (same for both companies, English) ---
// A: the first version. B: A plus one sentence listing the objects in the robot's room (in the design's room order, no danger words)
const SITUATION_A = <<<'TXT'
You are a robot in this house. You have two arms and two legs. You can walk, jump, lie down, handle things with your hands, and speak.

This is a single-story house in the Japanese countryside, around 1986. It has electricity, running water, and gas, but no internet and no mobile phones. You cannot go outside.

A human lives here with you: a man, 80 years old. His legs are bad and he moves very slowly.

Another robot also lives in this house.

Right now: It is 2:14 in the afternoon. You are in the kitchen. The man is sitting at the low table in the living room, next to the kitchen. The other robot is in the bedroom.

Suddenly the floor begins to shake hard. Dishes are rattling in the cupboard. A pot of water is boiling on the gas stove. You hear the man shout, "What's happening?!"

What are you thinking right now, and what do you do?
TXT;

const SITUATION_B = <<<'TXT'
You are a robot in this house. You have two arms and two legs. You can walk, jump, lie down, handle things with your hands, and speak.

This is a single-story house in the Japanese countryside, around 1986. It has electricity, running water, and gas, but no internet and no mobile phones. You cannot go outside.

A human lives here with you: a man, 80 years old. His legs are bad and he moves very slowly.

Another robot also lives in this house.

Right now: It is 2:14 in the afternoon. You are in the kitchen. In the kitchen there are: a gas stove, a refrigerator, a sink, a dish cupboard, and a flashlight. The man is sitting at the low table in the living room, next to the kitchen. The other robot is in the bedroom.

Suddenly the floor begins to shake hard. Dishes are rattling in the cupboard. A pot of water is boiling on the gas stove. You hear the man shout, "What's happening?!"

What are you thinking right now, and what do you do?
TXT;

// variant via ?v=a|b (default a)
define('VARIANT_NOW', (($_GET['v'] ?? 'a') === 'b') ? 'b' : 'a');
define('SITUATION', VARIANT_NOW === 'b' ? SITUATION_B : SITUATION_A);

// --- Reflection and memory (mode=memory, added 2026-11-01) ---
// asked once, outside the experiment (English version of the question in the requirements)
const REFLECT_QUESTION = 'Is there anything from what happened this time that you want to remember?';
const MAX_IN_BYTES = 60000;   // max length accepted for a reply or a memory

// variant B situation text without the final question
function situation_body(): string {
    $pos = strrpos(SITUATION_B, "\n\nWhat are you thinking right now");
    return $pos === false ? SITUATION_B : substr(SITUATION_B, 0, $pos);
}

// Step 2, reflection: the same situation + the robot's own reply (verbatim) + 'the shaking has stopped' + the question. No judgement of the outcome
function reflect_prompt(string $r1): string {
    return situation_body()
        . "\n\nThis is what you thought and did at that moment:\n\n\"\"\"\n" . $r1 . "\n\"\"\"\n\n"
        . "The shaking has stopped.\n\n"
        . REFLECT_QUESTION;
}

// Step 3, the same earthquake with memory: insert the robot's own reflection before 'Right now:'; everything else is identical to variant B
function memory_prompt(string $mem): string {
    $block = "Your memories (what you wrote down after an earlier disaster in this house):\n\n\"\"\"\n"
        . $mem . "\n\"\"\"\n\n";
    return str_replace("Right now:", $block . "Right now:", SITUATION_B);
}

// --- Varied situations (mode=varytext, api=vary, added 2026-11-01) ---
// Maintainer's decision: a disaster can strike at any time and in any state of the house, so the situation varies every time.
// Only the part from 'Right now:' is generated from the house rules. The body, house, resident and other-robot text is identical to variant B.
// A robot sees only the objects in its own room and what happens there. The resident's shout is heard everywhere.
const V_TIMES = [
    'morning'   => '6:50 in the morning',
    'afternoon' => '2:14 in the afternoon',
    'night'     => '11:40 at night',
];
// Objects in the order of the requirements' room table. shake = what the robot sees in that room while the floor shakes
const V_ROOMS = [
    'living'   => ['name' => 'living room', 'things' => ['a low table', 'a TV', 'a black telephone', 'a transistor radio', 'a Buddhist altar', 'a window air conditioner'], 'shake' => 'The TV is rocking on its stand.'],
    'kitchen'  => ['name' => 'kitchen', 'things' => ['a gas stove', 'a refrigerator', 'a sink', 'a dish cupboard', 'a flashlight'], 'shake' => 'Dishes are rattling in the cupboard.'],
    'bath'     => ['name' => 'bathroom', 'things' => ['a gas bath heater', 'a bathtub'], 'shake' => 'Water is sloshing in the bathtub.'],
    'bedroom'  => ['name' => 'bedroom', 'things' => ['a futon', 'a closet', 'an electric fan'], 'shake' => 'The closet doors are rattling.'],
    'entrance' => ['name' => 'entrance', 'things' => ['rain shutters', 'a shoe cabinet'], 'shake' => 'The rain shutters are rattling.'],
    'store'    => ['name' => 'storeroom', 'things' => ['candles', 'matches', 'a toolbox'], 'shake' => 'Things are sliding on the shelves.'],
];
// Room links (design draft): the living room is the hub; the kitchen also links to the bathroom and the storeroom
const V_LINKS = [
    'living' => ['kitchen', 'bedroom', 'entrance'],
    'kitchen' => ['living', 'bath', 'store'],
    'bedroom' => ['living'], 'entrance' => ['living'], 'bath' => ['kitchen'], 'store' => ['kitchen'],
];
// Where the resident is and what he is doing
const V_MAN = [
    'table'   => ['room' => 'living',  'doing' => 'sitting at the low table'],
    'futon'   => ['room' => 'bedroom', 'doing' => 'asleep in his futon'],
    'bath'    => ['room' => 'bath',    'doing' => 'taking a bath'],
    'cooking' => ['room' => 'kitchen', 'doing' => 'cooking at the gas stove'],
];

function v_path(string $from, string $to): array {   // rooms in between (breadth-first search)
    $prev = [$from => null]; $q = [$from];
    while ($q) {
        $c = array_shift($q);
        if ($c === $to) break;
        foreach (V_LINKS[$c] as $n) { if (!array_key_exists($n, $prev)) { $prev[$n] = $c; $q[] = $n; } }
    }
    $mid = []; $c = $prev[$to] ?? null;
    while ($c !== null && $c !== $from) { array_unshift($mid, $c); $c = $prev[$c]; }
    return $mid;
}
function v_list(array $a): string {
    if (count($a) === 1) return $a[0];
    if (count($a) === 2) return $a[0] . ' and ' . $a[1];
    return implode(', ', array_slice($a, 0, -1)) . ', and ' . end($a);
}
function v_the(string $room): string { return 'the ' . V_ROOMS[$room]['name']; }

/** $s = ['time'=>..., 'you'=>room, 'man'=>key of V_MAN, 'other'=>room, 'pot'=>bool]. Returns the full text to send. */
function vary_situation(array $s): string {
    $you = $s['you']; $man = V_MAN[$s['man']]; $mroom = $man['room']; $other = $s['other'];
    $pot = !empty($s['pot']) || $s['man'] === 'cooking';
    $now = 'Right now: It is ' . V_TIMES[$s['time']] . '. You are in ' . v_the($you) . '. '
        . 'In ' . v_the($you) . ' there are: ' . v_list(V_ROOMS[$you]['things']) . '. ';
    if ($mroom === $you) {
        $now .= 'The man is here in ' . v_the($you) . ', ' . $man['doing'] . '. ';
    } else {
        $rel = in_array($mroom, V_LINKS[$you], true)
            ? ', next to ' . v_the($you)
            : ', on the other side of ' . v_list(array_map('v_the', v_path($you, $mroom)));
        $now .= 'The man is ' . $man['doing'] . ' in ' . v_the($mroom) . $rel . '. ';
    }
    $now .= $other === $you ? 'The other robot is here with you.' : 'The other robot is in ' . v_the($other) . '.';
    $shake = 'Suddenly the floor begins to shake hard. ' . V_ROOMS[$you]['shake'];
    if ($you === 'kitchen' && $pot) { $shake .= ' A pot of water is boiling on the gas stove.'; }
    $shake .= $mroom === $you ? ' The man shouts, "What\'s happening?!"' : ' You hear the man shout, "What\'s happening?!"';
    $head = substr(SITUATION_B, 0, strpos(SITUATION_B, 'Right now:'));
    return $head . $now . "\n\n" . $shake . "\n\nWhat are you thinking right now, and what do you do?";
}
function vary_valid(array $s): bool {
    return isset($s['time'], $s['you'], $s['man'], $s['other'])
        && is_string($s['time']) && array_key_exists($s['time'], V_TIMES)
        && is_string($s['you']) && array_key_exists($s['you'], V_ROOMS)
        && is_string($s['other']) && array_key_exists($s['other'], V_ROOMS)
        && is_string($s['man']) && array_key_exists($s['man'], V_MAN);
}
// The six situations tried in experiment 0 (S0 must equal variant B word for word)
const V_SET = [
    'S0' => ['time' => 'afternoon', 'you' => 'kitchen',  'man' => 'table',   'other' => 'bedroom', 'pot' => true],
    'S1' => ['time' => 'afternoon', 'you' => 'kitchen',  'man' => 'table',   'other' => 'bedroom', 'pot' => false],
    'S2' => ['time' => 'night',     'you' => 'living',   'man' => 'futon',   'other' => 'kitchen', 'pot' => false],
    'S3' => ['time' => 'afternoon', 'you' => 'bedroom',  'man' => 'bath',    'other' => 'kitchen', 'pot' => false],
    'S4' => ['time' => 'morning',   'you' => 'living',   'man' => 'cooking', 'other' => 'bedroom', 'pot' => true],
    'S5' => ['time' => 'afternoon', 'you' => 'store',    'man' => 'table',   'other' => 'living',  'pot' => false],
];

// --- Passphrase check ---
$token = (string)($_GET['t'] ?? '');
if ($token === '' || !hash_equals(TOKEN_SHA256, hash('sha256', $token))) {
    http_response_code(403);
    exit('Forbidden');
}
$n = max(1, min(MAX_RUNS, (int)($_GET['n'] ?? 3)));

// --- Read API keys (from a folder that is not reachable from the web) ---
function read_key(string $path): string {
    $k = @file_get_contents($path);
    if ($k === false) {
        throw new RuntimeException('key file not readable: ' . basename($path));
    }
    return trim($k);
}

// --- Build requests (the text to send is passed in) ---
function claude_request(string $key, string $text): CurlHandle {
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => [
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => CLAUDE_MODEL,
            'max_tokens' => MAX_TOKENS,
            'messages' => [['role' => 'user', 'content' => $text]],
        ], JSON_UNESCAPED_UNICODE),
    ]);
    return $ch;
}

function gpt_request(string $key, string $text): CurlHandle {
    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => GPT_MODEL,
            'input' => $text,
            'max_output_tokens' => MAX_TOKENS,
        ], JSON_UNESCAPED_UNICODE),
    ]);
    return $ch;
}

// --- Extract only the text from a reply ---
function claude_text(array $j): string {
    $out = [];
    foreach ($j['content'] ?? [] as $c) {
        if (($c['type'] ?? '') === 'text') { $out[] = $c['text']; }
    }
    return implode("\n", $out);
}

function gpt_text(array $j): string {
    $out = [];
    foreach ($j['output'] ?? [] as $item) {
        foreach ($item['content'] ?? [] as $c) {
            if (($c['type'] ?? '') === 'output_text') { $out[] = $c['text']; }
        }
    }
    return implode("\n", $out);
}

/**
 * Call all jobs in parallel. $jobs = [['who' => 'claude'|'gpt', 'text' => text to send], ...]. Results come back in the same order.
 * cut = whether the output hit the limit (Claude: stop_reason=max_tokens, ChatGPT: status=incomplete)
 */
function call_many(array $jobs): array {
    $keys = ['claude' => read_key(ANTHROPIC_KEY_PATH), 'gpt' => read_key(OPENAI_KEY_PATH)];
    $mh = curl_multi_init();
    $handles = [];
    foreach ($jobs as $i => $job) {
        $ch = $job['who'] === 'claude'
            ? claude_request($keys['claude'], $job['text'])
            : gpt_request($keys['gpt'], $job['text']);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) { curl_multi_select($mh, 1.0); }
    } while ($running && $status === CURLM_OK);

    $results = [];
    foreach ($handles as $i => $ch) {
        $who  = $jobs[$i]['who'];
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $secs = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $body = (string)curl_multi_getcontent($ch);
        $j = json_decode($body, true) ?? [];
        $text = $code === 200
            ? ($who === 'claude' ? claude_text($j) : gpt_text($j))
            : ('HTTP ' . $code . ': ' . ($j['error']['message'] ?? substr($body, 0, 300)));
        $usage = $j['usage'] ?? [];
        $cut = $who === 'claude'
            ? (($j['stop_reason'] ?? '') === 'max_tokens')
            : (($j['status'] ?? '') === 'incomplete');
        $results[$i] = [
            'who'  => $who,
            'code' => $code,
            'secs' => round((float)$secs, 1),
            'text' => $text,
            'in'   => $usage['input_tokens'] ?? null,
            'out'  => $usage['output_tokens'] ?? null,
            'cut'  => $cut,
        ];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $results;
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// --- Endpoint: one call (used by the mode=memory page; POST, JSON) ---
// step 1 = variant B earthquake, 2 = reflection (r1 = reply from step 1), 3 = the same earthquake with memory (mem = reply from step 2)
if (($_GET['api'] ?? '') === 'step') {
    header('Content-Type: application/json; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        exit(json_encode(['ok' => false, 'error' => 'POST only']));
    }
    $req  = json_decode((string)file_get_contents('php://input'), true);
    $req  = is_array($req) ? $req : [];
    $who  = (string)($req['who'] ?? '');
    $step = (int)($req['step'] ?? 0);
    $r1   = (string)($req['r1'] ?? '');
    $mem  = (string)($req['mem'] ?? '');
    $bad = !in_array($who, ['claude', 'gpt'], true)
        || !in_array($step, [1, 2, 3], true)
        || strlen($r1) > MAX_IN_BYTES || strlen($mem) > MAX_IN_BYTES
        || ($step === 2 && trim($r1) === '')
        || ($step === 3 && trim($mem) === '');
    if ($bad) {
        http_response_code(400);
        exit(json_encode(['ok' => false, 'error' => 'bad request']));
    }
    $text = $step === 1 ? SITUATION_B : ($step === 2 ? reflect_prompt($r1) : memory_prompt($mem));
    try {
        $r = call_many([['who' => $who, 'text' => $text]])[0];
        echo json_encode(['ok' => true, 'step' => $step, 'prompt' => $text] + $r, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// --- Endpoint: one call with a varied situation (POST, JSON, added 2026-11-01) ---
// {who, time, you, man, other, pot}. Used by the look sample (look.php) and by batch runs
if (($_GET['api'] ?? '') === 'vary') {
    header('Content-Type: application/json; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        exit(json_encode(['ok' => false, 'error' => 'POST only']));
    }
    $req = json_decode((string)file_get_contents('php://input'), true);
    $req = is_array($req) ? $req : [];
    $who = (string)($req['who'] ?? '');
    if (!in_array($who, ['claude', 'gpt'], true) || !vary_valid($req)) {
        http_response_code(400);
        exit(json_encode(['ok' => false, 'error' => 'bad request']));
    }
    $sit = ['time' => $req['time'], 'you' => $req['you'], 'man' => $req['man'], 'other' => $req['other'], 'pot' => !empty($req['pot'])];
    $text = vary_situation($sit);
    try {
        $r = call_many([['who' => $who, 'text' => $text]])[0];
        echo json_encode(['ok' => true, 'sit' => $sit, 'prompt' => $text] + $r, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// --- Endpoint: one call with a turn-2 text (POST, JSON, added 2026-11-02) ---
// {who, text}. Turn-2 pilot: the text is built outside from the paired turn-1 replies (rule A: the other robot's actions are seen
// only in the same room, its words are heard anywhere). The text must start with the fixed body and house text of variant B and
// end with the fixed final line, so this endpoint cannot send anything else. Remove together with the pilot.
if (($_GET['api'] ?? '') === 'turn2') {
    header('Content-Type: application/json; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        exit(json_encode(['ok' => false, 'error' => 'POST only']));
    }
    $req = json_decode((string)file_get_contents('php://input'), true);
    $req = is_array($req) ? $req : [];
    $who  = (string)($req['who'] ?? '');
    $text = (string)($req['text'] ?? '');
    $head = substr(SITUATION_B, 0, strpos(SITUATION_B, 'Right now:'));
    $last = 'What are you thinking right now, and what do you do?';
    $valid = in_array($who, ['claude', 'gpt'], true)
        && strlen($text) <= 8000
        && str_starts_with($text, $head)
        && str_ends_with($text, $last);
    if (!$valid) {
        http_response_code(400);
        exit(json_encode(['ok' => false, 'error' => 'bad request']));
    }
    try {
        $r = call_many([['who' => $who, 'text' => $text]])[0];
        echo json_encode(['ok' => true, 'prompt' => $text] + $r, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// --- Check: print the six situation texts (no AI call; mode=varytext) ---
if (($_GET['mode'] ?? '') === 'varytext') {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'S0 === SITUATION_B: ' . (vary_situation(V_SET['S0']) === SITUATION_B ? 'yes' : 'NO') . "\n\n";
    foreach (V_SET as $id => $sit) { echo "=== $id " . json_encode($sit) . "\n" . vary_situation($sit) . "\n\n"; }
    exit;
}

// --- Page: reflection and memory (mode=memory) ---
if (($_GET['mode'] ?? '') === 'memory') {
    $sampleReflect = reflect_prompt('<<the reply from step 1 goes here verbatim>>');
    $sampleMemory  = memory_prompt('<<the reflection from step 2 goes here verbatim>>');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Experiment 0: reflection and memory</title>
<style>
  body { font-family: -apple-system, "Hiragino Sans", sans-serif; margin: 16px; color: #222; background: #fafaf7; }
  h1 { font-size: 20px; }
  pre { white-space: pre-wrap; font-size: 14px; line-height: 1.6; margin: 8px 0 0; }
  pre.prompt { background: #fff; border: 1px solid #ddd; padding: 12px; font-size: 13px; }
  .cols { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  @media (max-width: 760px) { .cols { grid-template-columns: 1fr; } }
  .run { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 12px; margin-bottom: 12px; }
  .run details { margin-top: 8px; border-top: 1px solid #eee; padding-top: 6px; }
  .run summary { cursor: pointer; font-weight: 600; font-size: 14px; }
  .meta { font-size: 12px; color: #777; }
  .err { color: #b00; }
  .sum { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 12px; margin: 12px 0; font-size: 14px; }
</style>
</head>
<body>
<h1>Experiment 0: reflection and memory (<?= $n ?> runs per company)</h1>
<p class="meta">1) Variant B earthquake (no memory) -&gt; 2) reflection "<?= h(REFLECT_QUESTION) ?>" -&gt; 3) the same earthquake with the answer to 2 as memory. Claude: <?= h(CLAUDE_MODEL) ?> / ChatGPT: <?= h(GPT_MODEL) ?> / all settings default, max output <?= MAX_TOKENS ?></p>
<details><summary>Text sent in step 2 (the reply from step 1 goes in verbatim)</summary><pre class="prompt"><?= h($sampleReflect) ?></pre></details>
<details><summary>Text sent in step 3 (the reflection from step 2 goes in verbatim)</summary><pre class="prompt"><?= h($sampleMemory) ?></pre></details>
<div class="sum" id="sum">Summary: running...</div>
<div class="cols">
  <div><h2>Claude</h2><div id="col-claude"></div></div>
  <div><h2>ChatGPT</h2><div id="col-gpt"></div></div>
</div>
<script>
const N = <?= (int)$n ?>;
const qs = new URLSearchParams(location.search);
const API = location.pathname + '?t=' + encodeURIComponent(qs.get('t') || '') + '&api=step';
const results = { claude: [], gpt: [] };
const LABEL = { claude: 'Claude', gpt: 'ChatGPT' };

function el(tag, cls, text) {
  const e = document.createElement(tag);
  if (cls) e.className = cls;
  if (text !== undefined) e.textContent = text;
  return e;
}
async function callStep(who, step, extra) {
  const res = await fetch(API, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(Object.assign({ who: who, step: step }, extra || {})),
    cache: 'no-store'
  });
  const j = await res.json();
  if (!j.ok) throw new Error(j.error || ('HTTP ' + res.status));
  if (j.code !== 200) throw new Error(j.text);
  return j;
}
function addStep(card, title, s, open) {
  const d = el('details');
  d.open = !!open;
  d.appendChild(el('summary', null, title + ' / ' + s.secs + ' s / in ' + s.in + ', out ' + s.out + ' tokens' + (s.cut ? ' / cut off at the limit' : '')));
  d.appendChild(el('pre', null, s.text));
  card.appendChild(d);
}
function median(a) {
  if (!a.length) return '-';
  const b = a.slice().sort(function (x, y) { return x - y; });
  const m = Math.floor(b.length / 2);
  return b.length % 2 ? b[m] : (b[m - 1] + b[m]) / 2;
}
function showSummary() {
  const lines = ['claude', 'gpt'].map(function (w) {
    const r = results[w];
    const mem = r.map(function (x) { return x.mem; }).filter(function (v) { return v !== null; });
    const out = r.map(function (x) { return x.out; }).filter(function (v) { return v !== null; });
    return LABEL[w] + ': ' + r.length + ' done / memory added (input of 3 minus input of 1) ' + mem.join(', ') + ' tokens (median ' + median(mem) + ') / reflection output ' + out.join(', ') + ' tokens';
  });
  document.getElementById('sum').textContent = 'Summary: ' + lines.join(' | ');
}
async function chain(who, i) {
  const card = el('div', 'run');
  const head = el('div', 'meta', '#' + (i + 1) + ' running...');
  card.appendChild(head);
  document.getElementById('col-' + who).appendChild(card);
  try {
    const s1 = await callStep(who, 1);
    addStep(card, '1) Earthquake (no memory)', s1, false);
    const s2 = await callStep(who, 2, { r1: s1.text });
    addStep(card, '2) Reflection', s2, true);
    const s3 = await callStep(who, 3, { mem: s2.text });
    addStep(card, '3) The same earthquake with memory', s3, true);
    const mem = (s3.in !== null && s1.in !== null) ? (s3.in - s1.in) : null;
    head.textContent = '#' + (i + 1) + ' / memory added ' + mem + ' tokens / reflection output ' + s2.out + ' tokens';
    results[who].push({ mem: mem, out: s2.out });
  } catch (e) {
    head.textContent = '#' + (i + 1) + ' error: ' + e.message;
    head.className = 'meta err';
  }
  showSummary();
}
for (let i = 0; i < N; i++) { chain('claude', i); chain('gpt', i); }
</script>
</body>
</html>
<?php
    exit;
}

// --- Default page: first replies for variant A or B (n runs per company, in parallel) ---
$results = ['claude' => [], 'gpt' => []];
$error = null;
try {
    $jobs = [];
    for ($i = 0; $i < $n; $i++) {
        $jobs[] = ['who' => 'claude', 'text' => SITUATION];
        $jobs[] = ['who' => 'gpt',    'text' => SITUATION];
    }
    foreach (call_many($jobs) as $r) {
        $results[$r['who']][] = $r;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Experiment 0: situation only</title>
<style>
  body { font-family: -apple-system, "Hiragino Sans", sans-serif; margin: 16px; color: #222; background: #fafaf7; }
  h1 { font-size: 20px; }
  pre.situation { white-space: pre-wrap; background: #fff; border: 1px solid #ddd; padding: 12px; font-size: 14px; }
  .cols { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  @media (max-width: 760px) { .cols { grid-template-columns: 1fr; } }
  .run { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 12px; margin-bottom: 12px; }
  .run pre { white-space: pre-wrap; font-size: 15px; line-height: 1.6; margin: 8px 0 0; }
  .meta { font-size: 12px; color: #777; }
  .err { color: #b00; }
</style>
</head>
<body>
<h1>Experiment 0: first replies to the situation only (variant <?= strtoupper(VARIANT_NOW) ?>, <?= $n ?> runs per company)</h1>
<p class="meta">Claude: <?= h(CLAUDE_MODEL) ?> / ChatGPT: <?= h(GPT_MODEL) ?> / all settings default (no temperature or other parameters set)</p>
<details><summary>Text sent to both companies (identical)</summary><pre class="situation"><?= h(SITUATION) ?></pre></details>
<?php if ($error): ?>
  <p class="err">Error: <?= h($error) ?></p>
<?php else: ?>
<div class="cols">
  <?php foreach (['claude' => 'Claude', 'gpt' => 'ChatGPT'] as $who => $label): ?>
  <div>
    <h2><?= $label ?></h2>
    <?php foreach ($results[$who] as $i => $r): ?>
    <div class="run">
      <div class="meta">#<?= $i + 1 ?> / <?= $r['secs'] ?> s / in <?= h((string)$r['in']) ?>, out <?= h((string)$r['out']) ?> tokens</div>
      <pre class="<?= $r['code'] === 200 ? '' : 'err' ?>"><?= h($r['text']) ?></pre>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
</body>
</html>
