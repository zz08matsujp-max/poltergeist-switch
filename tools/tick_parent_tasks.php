<?php

declare(strict_types=1);

/**
 * tick_parent_tasks.php — tick a spec's parent tasks once their sub-tasks are
 * done (maintainer decision, 2026-11-08).
 *
 * In every spec run so far the parent task lines in tasks.md stayed unticked
 * after all their sub-tasks were finished, and a person had to tick them by
 * hand. This script does that step, driven by the "Tick parent tasks" agent
 * hook after each spec task (see .kiro/hooks/).
 *
 * It is a dev tool: it lives under tools/, never under src/ or public/, so it
 * is never deployed. It runs on PHP 8.2 and 8.5 and uses only 8.2 features.
 *
 * Behaviour (per the decision):
 *   - Scan every .kiro/specs/<spec>/tasks.md.
 *   - A PARENT is a top-level task line `- [ ] N. ...` at zero indent (the box
 *     may also be `[-]` or `[~]`). Its SUB-TASKS are every checkbox line
 *     indented under it, down to the next top-level task line.
 *   - Tick the parent to `[x]` when it has at least one sub-task and every
 *     sub-task that is not optional is `[x]`. An OPTIONAL sub-task is a `[ ]*`
 *     box (an asterisk immediately after the `]`); an unticked optional
 *     sub-task never blocks the parent. A top-level task with no sub-tasks is
 *     left alone.
 *   - Nothing else in the file changes: the same line endings, and no other
 *     line is rewritten (only the parent's box character is flipped to `x`).
 *   - The file is written only when something changed, so an open editor tab
 *     is not disturbed for nothing.
 *
 * Output: one line per newly ticked parent, e.g. `screens: ticked task 19`, or
 * `no parent tasks to tick` when nothing changed anywhere. Exit 0 on success;
 * non-zero with a message on stderr when a file cannot be read or written.
 *
 * The decision logic lives in the pure function tick_parent_tasks(); the CLI
 * wrapper below only does the file I/O and printing, so the logic is tested in
 * tests/Tools/TickParentTasksTest.php without touching the filesystem.
 */

namespace PoltergeistSwitch\Tools;

/**
 * Apply the parent-ticking rule to one tasks.md document.
 *
 * Pure: it takes the file content and returns the new content plus the list of
 * parent task numbers that were newly ticked. It performs no I/O. Line endings
 * are preserved exactly — each line keeps its own trailing "\r" when the file
 * uses CRLF — because only the single box character of a parent line is
 * changed and every other byte is copied through unchanged.
 *
 * @param string $content the full text of a tasks.md file
 * @return array{content: string, ticked: list<string>} the rewritten content
 *         and the task numbers (e.g. "19") of the parents that were ticked
 */
function tick_parent_tasks(string $content): array
{
    // Split on "\n" but keep each line's own trailing "\r" (if any) attached,
    // so a CRLF file round-trips byte-for-byte. explode never drops data, so
    // imploding with "\n" rebuilds the original separators exactly.
    $lines = explode("\n", $content);

    // Find every top-level parent task line and the half-open range of lines
    // that belong to it (its sub-task block runs up to the next top-level
    // task, or the end of file).
    $parents = [];
    foreach ($lines as $i => $line) {
        if (parent_task_number(rtrim_cr($line)) !== null) {
            $parents[] = $i;
        }
    }

    $ticked = [];
    foreach ($parents as $pIndex => $lineIndex) {
        $blockEnd = $parents[$pIndex + 1] ?? count($lines);

        $bare = rtrim_cr($lines[$lineIndex]);
        $number = parent_task_number($bare);
        if ($number === null) {
            continue; // not reached; parent_task_number already matched above
        }

        // Already ticked parents are left alone.
        if (is_checked_box($bare)) {
            continue;
        }

        [$required, $open] = count_subtasks($lines, $lineIndex + 1, $blockEnd);

        // Needs at least one sub-task, and no required sub-task left open.
        if ($required === 0 || $open > 0) {
            continue;
        }

        // Flip only this parent line's box to [x], preserving any trailing
        // "\r" and everything after the box.
        $lines[$lineIndex] = set_box_checked($lines[$lineIndex]);
        $ticked[] = $number;
    }

    return [
        'content' => implode("\n", $lines),
        'ticked'  => $ticked,
    ];
}

/**
 * Strip a single trailing carriage return so CRLF and LF lines are compared the
 * same way. The original line (with its "\r") is what gets stored back.
 */
function rtrim_cr(string $line): string
{
    return (substr($line, -1) === "\r") ? substr($line, 0, -1) : $line;
}

/**
 * The task number of a TOP-LEVEL task line, or null when the line is not one.
 *
 * A top-level task line has NO leading indentation, an unordered-list checkbox,
 * and a `N.` number after the box: `- [ ] 19. ...`, `- [x] 7. ...`, and the
 * in-progress boxes `- [-] 5. ...` / `- [~] 12. ...`. The returned number is
 * the "N" string (e.g. "19"). Indented lines (sub-tasks) return null.
 */
function parent_task_number(string $line): ?string
{
    if (preg_match('/^- \[[ x\-~]\]\*? (\d+)\. /', $line, $m) === 1) {
        return $m[1];
    }

    return null;
}

/**
 * Whether a checkbox line's box is checked (`[x]`). Works for any checkbox line
 * (parent or sub-task) at any indent, with or without a trailing `*`.
 */
function is_checked_box(string $line): bool
{
    return preg_match('/^\s*- \[x\]/', $line) === 1;
}

/**
 * Whether a line is any checkbox line (a parent or a sub-task at any indent).
 */
function is_checkbox_line(string $line): bool
{
    return preg_match('/^\s*- \[[ x\-~]\]/', $line) === 1;
}

/**
 * Whether a checkbox line is OPTIONAL: its box is immediately followed by `*`,
 * as in `- [ ]* 3.5 ...`. An unticked optional sub-task never blocks a parent.
 */
function is_optional_box(string $line): bool
{
    return preg_match('/^\s*- \[[ x\-~]\]\*/', $line) === 1;
}

/**
 * Count the sub-tasks of a parent across the line range [$from, $to).
 *
 * Every indented checkbox line in the range is a sub-task, including deeper
 * nested boxes — they count as required sub-tasks too. Non-checkbox lines
 * (prose detail bullets, blank lines, headings) are ignored.
 *
 * Returns [requiredCount, openRequiredCount]: how many non-optional sub-tasks
 * there are, and how many of those are not yet `[x]`. Optional (`[ ]*`)
 * sub-tasks are excluded from both counts, so they never affect the parent.
 *
 * @param list<string> $lines
 * @return array{0:int,1:int}
 */
function count_subtasks(array $lines, int $from, int $to): array
{
    $required = 0;
    $open = 0;

    for ($i = $from; $i < $to; $i++) {
        $line = rtrim_cr($lines[$i]);

        if (!is_checkbox_line($line)) {
            continue;
        }

        // Optional sub-tasks never count toward the parent.
        if (is_optional_box($line)) {
            continue;
        }

        $required++;
        if (!is_checked_box($line)) {
            $open++;
        }
    }

    return [$required, $open];
}

/**
 * Return the line with its checkbox flipped to `[x]`, preserving the leading
 * indentation (there is none for a parent), any trailing `*` is irrelevant for
 * a parent, the rest of the line text, and any trailing "\r". Only the single
 * box character changes.
 */
function set_box_checked(string $line): string
{
    // Replace the first "[ ]", "[-]" or "[~]" box with "[x]". Limit to one
    // replacement so only the box (never later bracketed text) is touched.
    return preg_replace('/\[[ \-~]\]/', '[x]', $line, 1) ?? $line;
}

// ---------------------------------------------------------------------------
// CLI wrapper. Skipped when this file is included (e.g. by the test), so the
// pure functions above can be unit-tested without running the I/O.
// ---------------------------------------------------------------------------

if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    exit(run_cli());
}

/**
 * Scan every .kiro/specs/<spec>/tasks.md under the repository root, tick the
 * parents whose sub-tasks are done, and print one line per newly ticked parent.
 * Returns the process exit code.
 */
function run_cli(): int
{
    // The repo root is the parent of this tools/ directory.
    $root = dirname(__DIR__);
    $pattern = $root . '/.kiro/specs/*/tasks.md';

    $files = glob($pattern);
    if ($files === false) {
        fwrite(STDERR, "tick_parent_tasks: could not scan {$pattern}\n");

        return 1;
    }
    sort($files);

    $anyTicked = false;

    foreach ($files as $file) {
        $content = @file_get_contents($file);
        if ($content === false) {
            fwrite(STDERR, "tick_parent_tasks: cannot read {$file}\n");

            return 1;
        }

        $result = tick_parent_tasks($content);
        if ($result['ticked'] === []) {
            continue;
        }

        if (@file_put_contents($file, $result['content']) === false) {
            fwrite(STDERR, "tick_parent_tasks: cannot write {$file}\n");

            return 1;
        }

        $spec = basename(dirname($file));
        foreach ($result['ticked'] as $number) {
            echo "{$spec}: ticked task {$number}\n";
            $anyTicked = true;
        }
    }

    if (!$anyTicked) {
        echo "no parent tasks to tick\n";
    }

    return 0;
}
