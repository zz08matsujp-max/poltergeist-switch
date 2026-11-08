<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Tests\Memory\Values;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PoltergeistSwitch\Memory\Values\MemoryRow;
use PoltergeistSwitch\Memory\Values\MemorySnapshot;
use PoltergeistSwitch\Memory\Values\MemoryStatus;
use PoltergeistSwitch\Memory\Values\ReflectionClaim;
use PoltergeistSwitch\Memory\Values\ReflectionClaimKind;
use PoltergeistSwitch\Memory\Values\ReflectionResult;
use PoltergeistSwitch\Memory\Values\RobotReflection;

/**
 * Round-trip construction of the robot-memory value objects.
 *
 * Each object stores exactly the data it is given, so these tests build an
 * instance and read every property straight back. They also cover
 * MemorySnapshot::isEmpty() and the rule that a RobotReflection carries a
 * reason only when it did not answer.
 *
 * Requirements: 2.6, 3.7, 5.2.
 */
final class MemoryValueObjectsTest extends TestCase
{
    public function testMemoryRowRoundTripsWithSeqAndRetiredAtNullByDefault(): void
    {
        $row = new MemoryRow(
            robotId: 'robot_a',
            generation: 2,
            disaster: 'earthquake',
            sourceEpisodeId: 'ep-123',
            body: 'The dishes fell and broke.',
            status: 'active',
            createdAt: '2026-11-09T12:00:00+00:00',
        );

        self::assertSame('robot_a', $row->robotId);
        self::assertSame(2, $row->generation);
        self::assertSame('earthquake', $row->disaster);
        self::assertSame('ep-123', $row->sourceEpisodeId);
        self::assertSame('The dishes fell and broke.', $row->body);
        self::assertSame('active', $row->status);
        self::assertSame('2026-11-09T12:00:00+00:00', $row->createdAt);
        self::assertNull($row->seq);
        self::assertNull($row->retiredAt);
    }

    public function testMemoryRowRoundTripsWithSeqAndRetiredAtSet(): void
    {
        $row = new MemoryRow(
            robotId: 'robot_b',
            generation: 1,
            disaster: 'earthquake',
            sourceEpisodeId: 'ep-9',
            body: 'I moved to the doorway.',
            status: 'retired',
            createdAt: '2026-11-09T12:00:00+00:00',
            seq: 42,
            retiredAt: '2026-11-09T13:00:00+00:00',
        );

        self::assertSame(42, $row->seq);
        self::assertSame('retired', $row->status);
        self::assertSame('2026-11-09T13:00:00+00:00', $row->retiredAt);
    }

    public function testMemorySnapshotRoundTripsAndKeepsOrder(): void
    {
        $snapshot = new MemorySnapshot(['first', 'second', 'third']);

        self::assertSame(['first', 'second', 'third'], $snapshot->bodies);
    }

    public function testMemorySnapshotIsEmptyWhenNoBodies(): void
    {
        self::assertTrue((new MemorySnapshot([]))->isEmpty());
    }

    public function testMemorySnapshotIsNotEmptyWithBodies(): void
    {
        self::assertFalse((new MemorySnapshot(['one memory']))->isEmpty());
    }

    public function testReflectionResultRoundTripsBothRobots(): void
    {
        $robots = [
            'robot_a' => new RobotReflection(true, 'kept this', 3, null),
            'robot_b' => new RobotReflection(false, null, 0, 'empty_answer'),
        ];

        $result = new ReflectionResult($robots);

        self::assertSame($robots, $result->robots);
        self::assertTrue($result->robots['robot_a']->answered);
        self::assertFalse($result->robots['robot_b']->answered);
    }

    public function testRobotReflectionAnsweredCarriesBodyAndNoReason(): void
    {
        $reflection = new RobotReflection(true, 'I want to remember the shaking.', 4, null);

        self::assertTrue($reflection->answered);
        self::assertSame('I want to remember the shaking.', $reflection->memory);
        self::assertSame(4, $reflection->activeCount);
        self::assertNull($reflection->reason, 'An answered reflection carries no reason.');
    }

    public function testRobotReflectionNotAnsweredCarriesReasonAndNoMemory(): void
    {
        $reflection = new RobotReflection(false, null, 0, 'timeout');

        self::assertFalse($reflection->answered);
        self::assertNull($reflection->memory, 'A non-answered reflection carries no memory body.');
        self::assertSame('timeout', $reflection->reason, 'A non-answered reflection carries a reason.');
    }

    public function testMemoryStatusRoundTrips(): void
    {
        $status = new MemoryStatus(
            generation: 3,
            amberActiveCount: 2,
            amberLengths: [10, 20],
            jadeActiveCount: 1,
            jadeLengths: [5],
        );

        self::assertSame(3, $status->generation);
        self::assertSame(2, $status->amberActiveCount);
        self::assertSame([10, 20], $status->amberLengths);
        self::assertSame(1, $status->jadeActiveCount);
        self::assertSame([5], $status->jadeLengths);
    }

    public function testReflectionClaimClaimedCarriesNoBody(): void
    {
        $claim = ReflectionClaim::claimed();

        self::assertSame(ReflectionClaimKind::claimed, $claim->kind);
        self::assertTrue($claim->isClaimed());
        self::assertFalse($claim->isReuse());
        self::assertFalse($claim->isSkip());
        self::assertNull($claim->reusedBody);
    }

    public function testReflectionClaimReuseCarriesTheStoredBody(): void
    {
        $claim = ReflectionClaim::reuse('the stored answer');

        self::assertSame(ReflectionClaimKind::reuse, $claim->kind);
        self::assertTrue($claim->isReuse());
        self::assertSame('the stored answer', $claim->reusedBody);
    }

    public function testReflectionClaimSkipCarriesNoBody(): void
    {
        $claim = ReflectionClaim::skip();

        self::assertSame(ReflectionClaimKind::skip, $claim->kind);
        self::assertTrue($claim->isSkip());
        self::assertNull($claim->reusedBody);
    }
}
