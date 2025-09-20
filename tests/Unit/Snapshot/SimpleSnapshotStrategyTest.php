<?php

declare(strict_types=1);

namespace LaravelModularDDD\Tests\Unit\Snapshot;

use PHPUnit\Framework\TestCase;
use LaravelModularDDD\EventSourcing\Snapshot\SimpleSnapshotStrategy;
use LaravelModularDDD\Core\Domain\Contracts\AggregateRootInterface;
use LaravelModularDDD\EventSourcing\Contracts\AggregateSnapshotInterface;

class SimpleSnapshotStrategyTest extends TestCase
{
    private SimpleSnapshotStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new SimpleSnapshotStrategy(10);
    }

    /** @test */
    public function it_creates_snapshot_every_10_events_by_default(): void
    {
        $aggregate = $this->createMockAggregate(10);

        $this->assertTrue($this->strategy->shouldSnapshot($aggregate));
    }

    /** @test */
    public function it_does_not_create_snapshot_before_threshold(): void
    {
        $aggregate = $this->createMockAggregate(9);

        $this->assertFalse($this->strategy->shouldSnapshot($aggregate));
    }

    /** @test */
    public function it_creates_snapshot_at_exact_threshold(): void
    {
        $aggregate = $this->createMockAggregate(10);

        $this->assertTrue($this->strategy->shouldSnapshot($aggregate));
    }

    /** @test */
    public function it_creates_snapshot_after_threshold(): void
    {
        $aggregate = $this->createMockAggregate(15);

        $this->assertTrue($this->strategy->shouldSnapshot($aggregate));
    }

    /** @test */
    public function it_respects_custom_threshold(): void
    {
        $strategy = new SimpleSnapshotStrategy(20);
        $aggregate = $this->createMockAggregate(19);

        $this->assertFalse($strategy->shouldSnapshot($aggregate));

        $aggregate = $this->createMockAggregate(20);
        $this->assertTrue($strategy->shouldSnapshot($aggregate));
    }

    /** @test */
    public function it_calculates_events_since_last_snapshot(): void
    {
        $aggregate = $this->createMockAggregate(25);
        $lastSnapshot = $this->createMockSnapshot(20);

        // 25 - 20 = 5 events since last snapshot (below threshold of 10)
        $this->assertFalse($this->strategy->shouldSnapshot($aggregate, $lastSnapshot));

        $aggregate = $this->createMockAggregate(30);
        // 30 - 20 = 10 events since last snapshot (at threshold)
        $this->assertTrue($this->strategy->shouldSnapshot($aggregate, $lastSnapshot));
    }

    /** @test */
    public function it_returns_correct_name(): void
    {
        $this->assertEquals('simple', $this->strategy->getName());
    }

    /** @test */
    public function it_returns_configuration(): void
    {
        $config = $this->strategy->getConfiguration();

        $this->assertEquals(['event_threshold' => 10], $config);
    }

    /** @test */
    public function it_throws_exception_for_invalid_threshold(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Event threshold must be at least 1');

        new SimpleSnapshotStrategy(0);
    }

    /** @test */
    public function it_checks_uncommitted_events(): void
    {
        $aggregate = $this->createMockAggregateWithUncommittedEvents(10);

        $this->assertTrue($this->strategy->shouldSnapshotUncommittedEvents($aggregate));

        $aggregate = $this->createMockAggregateWithUncommittedEvents(5);
        $this->assertFalse($this->strategy->shouldSnapshotUncommittedEvents($aggregate));
    }

    /** @test */
    public function it_handles_aggregate_without_uncommitted_events_method(): void
    {
        $aggregate = $this->createMockAggregate(10);

        $this->assertFalse($this->strategy->shouldSnapshotUncommittedEvents($aggregate));
    }

    /**
     * Test the exact PRD requirement: "Automatic snapshots every 10 events"
     *
     * @test
     */
    public function it_meets_prd_requirement_for_automatic_snapshots_every_10_events(): void
    {
        // Test the exact sequence from 1 to 20 events
        for ($version = 1; $version <= 20; $version++) {
            $aggregate = $this->createMockAggregate($version);
            $shouldSnapshot = $this->strategy->shouldSnapshot($aggregate);

            if ($version % 10 === 0) {
                $this->assertTrue(
                    $shouldSnapshot,
                    "Should create snapshot at version {$version} (every 10 events)"
                );
            } else {
                $this->assertFalse(
                    $shouldSnapshot,
                    "Should NOT create snapshot at version {$version} (not every 10 events)"
                );
            }
        }
    }

    private function createMockAggregate(int $version): AggregateRootInterface
    {
        $mock = $this->createMock(AggregateRootInterface::class);
        $mock->method('getVersion')->willReturn($version);

        return $mock;
    }

    private function createMockAggregateWithUncommittedEvents(int $count): AggregateRootInterface
    {
        $mock = $this->createMock(AggregateRootInterface::class);
        $mock->method('getUncommittedEventsCount')->willReturn($count);

        return $mock;
    }

    private function createMockSnapshot(int $version): AggregateSnapshotInterface
    {
        $mock = $this->createMock(AggregateSnapshotInterface::class);
        $mock->method('getVersion')->willReturn($version);

        return $mock;
    }
}