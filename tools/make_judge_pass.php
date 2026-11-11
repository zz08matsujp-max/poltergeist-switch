<?php

declare(strict_types=1);

/**
 * Maintainer tool: make a new judge pass (public-lab Requirement 21).
 *
 *   php tools/make_judge_pass.php <output-file>
 *
 * Writes the Judge_Link (one line) to <output-file> (mode 0600, never
 * overwrites, must be outside the repository) and prints only
 * `judge_pass_sha256: <hex>` to stdout. The pass itself is never printed.
 */

use PoltergeistSwitch\TurnLoop\Judge\JudgePass;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}

require __DIR__ . '/../src/TurnLoop/Judge/JudgePass.php';
require __DIR__ . '/../src/TurnLoop/Judge/JudgeCookie.php';

/** Print a fixed message (never a secret) to stderr and exit 1. */
function ps_judge_tool_refuse(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

if ($argc !== 2) {
    ps_judge_tool_refuse('usage: php tools/make_judge_pass.php <output-file>');
}

$path = (string) $argv[1];
if ($path === '' || str_contains($path, "\0")) {
    ps_judge_tool_refuse('refused: invalid output file');
}
if (file_exists($path) || is_link($path)) {
    ps_judge_tool_refuse('refused: the output file already exists');
}

$dir = realpath(dirname($path));
$repoRoot = realpath(__DIR__ . '/..');
if ($dir === false || !is_dir($dir)) {
    ps_judge_tool_refuse('refused: the output directory does not exist');
}
if ($repoRoot !== false
    && ($dir === $repoRoot || str_starts_with($dir . DIRECTORY_SEPARATOR, $repoRoot . DIRECTORY_SEPARATOR))
) {
    ps_judge_tool_refuse('refused: the output file must be outside the repository');
}

umask(0177);
$handle = @fopen($dir . DIRECTORY_SEPARATOR . basename($path), 'x');
if ($handle === false) {
    ps_judge_tool_refuse('refused: cannot create the output file');
}

$pass = JudgePass::generate();
$written = fwrite($handle, 'https://kiro.pyol.net/#judge=' . $pass . "\n");
$closed = fclose($handle);
@chmod($dir . DIRECTORY_SEPARATOR . basename($path), 0600);
if ($written === false || !$closed) {
    ps_judge_tool_refuse('failed: cannot write the output file');
}

fwrite(STDOUT, 'judge_pass_sha256: ' . JudgePass::hash($pass) . "\n");
exit(0);
