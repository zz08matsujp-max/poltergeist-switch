<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Tools;

use PHPUnit\Framework\TestCase;

use function PoltergeistSwitch\Tools\tick_parent_tasks;

// The tool is plain PHP under tools/ (never deployed) with no Composer
// autoload entry, so load its pure functions directly. The file's CLI wrapper
// only runs when the script is executed as the main program, so including it
// here is side-effect free.
require_once __DIR__ . '/../../tools/tick_parent_tasks.php';

/**
 * Tests for the parent-ticking rule in tools/tick_parent_tasks.php
 * (maintainer decision, 2026-11-08).
 *
 * The cases mirror the real tasks.md shapes called out in the decision:
 *   - screens 13–19 (all sub-tasks done → the parent is ticked);
 *   - screens 3 with its optional sub-task 3.5 unticked (→ still ticked);
 *   - a parent with one required sub-task still open (→ not ticked);
 *   - deeper nested boxes (like the two under screens 4.2) count as required;
 *   - a top-level task with no sub-tasks (→ left unchanged);
 *   - running twice changes nothing the second time (idempotent).
 */
final class TickParentTasksTest extends TestCase
{
    public function testAllSubtasksDoneTicksTheParent(): void
    {
        // screens 13: a finished parent whose single sub-task is [x], as it
        // looked before the parent was ticked by hand.
        $content = <<<MD
        - [ ] 13. Fix hidden elements showing on Replay
          - [x] 13.1 Add `[hidden] { display: none !important; }` to `public/css/screens.css`
            - _Requirements: 8.2, 8.7_

        MD;

        $result = tick_parent_tasks($content);

        $this->assertSame(['13'], $result['ticked']);
        $this->assertStringContainsString('- [x] 13. Fix hidden elements showing on Replay', $result['content']);
    }

    public function testMultipleFinishedParentsAreAllTicked(): void
    {
        // screens 13–19 shape: several finished parents in one file.
        $content = <<<MD
        - [ ] 13. Fix hidden elements showing on Replay
          - [x] 13.1 Add the rule
            - _Requirements: 8.2_

        - [ ] 16. Build viewer-facing log and End_Card text
          - [x] 16.1 third person
            - _Requirements: 5.10_
          - [x] 16.2 spoken line
            - _Requirements: 5.10_
          - [x] 16.3 truncate
            - _Requirements: 5.10_

        - [ ] 19. Replay usability and the turn count
          - [x] 19.1 Turn N of M
            - _Requirements: 9.1_
          - [x] 19.2 controls under the canvas
            - _Requirements: 9.1_
          - [x] 19.3 Choose another episode
            - _Requirements: 8.2_
          - [x] 19.4 count only turns after turn 0
            - _Requirements: 11.3_

        MD;

        $result = tick_parent_tasks($content);

        $this->assertSame(['13', '16', '19'], $result['ticked']);
        $this->assertStringContainsString('- [x] 13.', $result['content']);
        $this->assertStringContainsString('- [x] 16.', $result['content']);
        $this->assertStringContainsString('- [x] 19.', $result['content']);
        // The sub-task lines are untouched.
        $this->assertStringContainsString('  - [x] 16.1 third person', $result['content']);
    }

    public function testParentWithOnlyAnUntickedOptionalSubtaskIsStillTicked(): void
    {
        // screens 3: every required sub-task done, the optional 3.5 still open.
        $content = <<<MD
        - [ ] 3. Store, return, and backfill world_json
          - [x] 3.1 Extend the repository
            - _Requirements: 10.1, 10.2_
          - [x] 3.4 Write the required backfill property tests
            - _Requirements: 10.7, 10.8_
          - [ ]* 3.5 Write unit/example tests for the write and read paths
            - _Requirements: 10.1, 10.2_

        MD;

        $result = tick_parent_tasks($content);

        $this->assertSame(['3'], $result['ticked']);
        $this->assertStringContainsString('- [x] 3. Store, return, and backfill world_json', $result['content']);
        // The optional sub-task is left exactly as it was.
        $this->assertStringContainsString('  - [ ]* 3.5 Write unit/example tests for the write and read paths', $result['content']);
    }

    public function testParentWithAnOpenRequiredSubtaskIsNotTicked(): void
    {
        $content = <<<MD
        - [ ] 9. Observe screen
          - [x] 9.1 api client + css
            - _Requirements: 12.9_
          - [ ] 9.2 layout and setup
            - _Requirements: 4.1_
          - [ ]* 9.9 optional extra
            - _Requirements: 4.1_

        MD;

        $result = tick_parent_tasks($content);

        $this->assertSame([], $result['ticked']);
        $this->assertStringContainsString('- [ ] 9. Observe screen', $result['content']);
        // Nothing changed at all.
        $this->assertSame($content, $result['content']);
    }

    public function testDeeperNestedBoxesCountAsRequired(): void
    {
        // screens 4.2 shape: a sub-task with its own deeper checkbox lines.
        // The deeper boxes count as required sub-tasks of the parent. When one
        // is open the parent is not ticked; when all are done it is.
        $openContent = <<<MD
        - [ ] 4. Ended-episode list endpoint
          - [x] 4.1 Implement EpisodeListService
            - _Requirements: 11.1_
          - [x] 4.2 Implement the thin handler and wire the route
            - [x] Parse ended and limit from the query
            - [ ] Send Cache-Control: no-store on the response
            - _Requirements: 11.9_

        MD;

        $openResult = tick_parent_tasks($openContent);
        $this->assertSame([], $openResult['ticked'], 'a deeper box left open blocks the parent');

        $doneContent = str_replace(
            '    - [ ] Send Cache-Control: no-store on the response',
            '    - [x] Send Cache-Control: no-store on the response',
            $openContent
        );
        $this->assertNotSame($openContent, $doneContent, 'guard: the deeper box was actually flipped in the fixture');

        $doneResult = tick_parent_tasks($doneContent);
        $this->assertSame(['4'], $doneResult['ticked'], 'all boxes done, including deeper ones → parent ticked');
        $this->assertStringContainsString('- [x] 4. Ended-episode list endpoint', $doneResult['content']);
    }

    public function testTopLevelTaskWithNoSubtasksIsLeftAlone(): void
    {
        // screens 5 / 12: a checkpoint parent with no sub-tasks.
        $content = <<<MD
        - [ ] 5. Checkpoint - API additions
          - Ensure all tests pass, ask the user if questions arise.

        - [ ] 12. Final checkpoint
          - Ensure all tests pass, ask the user if questions arise.

        MD;

        $result = tick_parent_tasks($content);

        $this->assertSame([], $result['ticked']);
        $this->assertSame($content, $result['content']);
    }

    public function testInProgressBoxesAreTickedAndSubtaskBoxesOtherThanXDoNotCount(): void
    {
        // A parent whose box is the in-progress [~] (or [-]) is still ticked
        // once its sub-tasks are all done — the box is flipped to [x].
        $content = <<<MD
        - [~] 7. House_Renderer
          - [x] 7.1 Implement house-renderer.js
            - _Requirements: 1.1_

        MD;

        $result = tick_parent_tasks($content);

        $this->assertSame(['7'], $result['ticked']);
        $this->assertStringContainsString('- [x] 7. House_Renderer', $result['content']);
        $this->assertStringNotContainsString('- [~] 7.', $result['content']);
    }

    public function testRunningTwiceChangesNothingTheSecondTime(): void
    {
        $content = <<<MD
        - [ ] 13. Fix hidden elements showing on Replay
          - [x] 13.1 Add the rule
            - _Requirements: 8.2_

        - [ ] 9. Observe screen
          - [ ] 9.2 still open
            - _Requirements: 4.1_

        MD;

        $first = tick_parent_tasks($content);
        $this->assertSame(['13'], $first['ticked']);

        // A second pass over the already-ticked output ticks nothing more and
        // returns byte-for-byte identical content (idempotent).
        $second = tick_parent_tasks($first['content']);
        $this->assertSame([], $second['ticked']);
        $this->assertSame($first['content'], $second['content']);
    }

    public function testCrlfLineEndingsArePreserved(): void
    {
        // A CRLF file must round-trip with CRLF: only the parent box flips.
        $content = "- [ ] 13. Fix hidden elements\r\n  - [x] 13.1 the rule\r\n    - _Requirements: 8.2_\r\n";

        $result = tick_parent_tasks($content);

        $this->assertSame(['13'], $result['ticked']);
        $this->assertStringContainsString("- [x] 13. Fix hidden elements\r\n", $result['content']);
        // Every CRLF is intact; no bare LF was introduced.
        $this->assertSame(
            substr_count($content, "\r\n"),
            substr_count($result['content'], "\r\n"),
            'the CRLF count is unchanged',
        );
        $this->assertSame(
            substr_count($content, "\n"),
            substr_count($result['content'], "\n"),
            'no extra line was added or removed',
        );
    }
}
