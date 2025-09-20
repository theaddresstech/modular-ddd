<?php

declare(strict_types=1);

namespace LaravelModularDDD\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use LaravelModularDDD\Core\Domain\AggregateRoot;
use LaravelModularDDD\Core\Domain\Contracts\AggregateIdInterface;
use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;

class AggregateRootTest extends TestCase
{
    private TestAggregateRoot $aggregate;
    private AggregateIdInterface $aggregateId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aggregateId = $this->createMock(AggregateIdInterface::class);
        $this->aggregateId->method('toString')->willReturn('test-id');

        $this->aggregate = new TestAggregateRoot($this->aggregateId);
    }

    /** @test */
    public function it_tracks_aggregate_id(): void
    {
        $this->assertSame($this->aggregateId, $this->aggregate->getAggregateId());
    }

    /** @test */
    public function it_starts_with_version_zero(): void
    {
        $this->assertEquals(0, $this->aggregate->getVersion());
    }

    /** @test */
    public function it_increments_version_when_events_are_recorded(): void
    {
        $event1 = $this->createMockEvent();
        $event2 = $this->createMockEvent();

        $this->aggregate->recordTestEvent($event1);
        $this->assertEquals(1, $this->aggregate->getVersion());

        $this->aggregate->recordTestEvent($event2);
        $this->assertEquals(2, $this->aggregate->getVersion());
    }

    /** @test */
    public function it_tracks_uncommitted_events(): void
    {
        $this->assertFalse($this->aggregate->hasUncommittedEvents());
        $this->assertEquals(0, $this->aggregate->getUncommittedEventsCount());

        $event = $this->createMockEvent();
        $this->aggregate->recordTestEvent($event);

        $this->assertTrue($this->aggregate->hasUncommittedEvents());
        $this->assertEquals(1, $this->aggregate->getUncommittedEventsCount());
    }

    /** @test */
    public function it_returns_uncommitted_events(): void
    {
        $event1 = $this->createMockEvent();
        $event2 = $this->createMockEvent();

        $this->aggregate->recordTestEvent($event1);
        $this->aggregate->recordTestEvent($event2);

        $events = $this->aggregate->pullDomainEvents();

        $this->assertCount(2, $events);
        $this->assertSame($event1, $events[0]);
        $this->assertSame($event2, $events[1]);
    }

    /** @test */
    public function it_clears_uncommitted_events_after_pulling(): void
    {
        $event = $this->createMockEvent();
        $this->aggregate->recordTestEvent($event);

        $this->assertTrue($this->aggregate->hasUncommittedEvents());

        $this->aggregate->pullDomainEvents();

        $this->assertFalse($this->aggregate->hasUncommittedEvents());
        $this->assertEquals(0, $this->aggregate->getUncommittedEventsCount());
    }

    /** @test */
    public function it_marks_events_as_committed(): void
    {
        $event = $this->createMockEvent();
        $this->aggregate->recordTestEvent($event);

        $this->assertTrue($this->aggregate->hasUncommittedEvents());

        $this->aggregate->markEventsAsCommitted();

        $this->assertFalse($this->aggregate->hasUncommittedEvents());
    }

    /** @test */
    public function it_applies_events_using_convention(): void
    {
        $event = $this->createMockEvent('TestEvent');
        $this->aggregate->recordTestEvent($event);

        // The TestAggregateRoot should have called applyTestEvent
        $this->assertTrue($this->aggregate->wasEventApplied());
    }

    /** @test */
    public function it_handles_missing_apply_methods_gracefully(): void
    {
        $event = $this->createMockEvent('NonExistentEvent');

        // Should not throw an exception
        $this->aggregate->recordTestEvent($event);

        $this->assertEquals(1, $this->aggregate->getVersion());
    }

    private function createMockEvent(string $eventType = 'TestEvent'): DomainEventInterface
    {
        $event = $this->createMock(DomainEventInterface::class);

        // Create a mock ReflectionClass that returns the short name
        $reflection = $this->createMock(\ReflectionClass::class);
        $reflection->method('getShortName')->willReturn($eventType);

        // Override the reflection creation in the aggregate
        return new class($eventType) implements DomainEventInterface {
            public function __construct(private string $eventType) {}

            public function getAggregateId(): AggregateIdInterface {
                return new class implements AggregateIdInterface {
                    public function toString(): string { return 'test-id'; }
                    public function equals(AggregateIdInterface $other): bool { return false; }
                    public static function generate(): static { return new static(); }
                    public static function fromString(string $id): static { return new static(); }
                };
            }
            public function getEventId(): string { return 'event-id'; }
            public function getEventType(): string { return $this->eventType; }
            public function getEventVersion(): int { return 1; }
            public function getOccurredAt(): \DateTimeImmutable { return new \DateTimeImmutable(); }
            public function getMetadata(): array { return []; }
            public function getPayload(): array { return []; }
            public function toArray(): array { return []; }
            public static function fromArray(array $data): static { return new static('TestEvent'); }
        };
    }
}

class TestAggregateRoot extends AggregateRoot
{
    private bool $eventApplied = false;

    public function recordTestEvent(DomainEventInterface $event): void
    {
        $this->recordThat($event);
    }

    public function wasEventApplied(): bool
    {
        return $this->eventApplied;
    }

    protected function applyTestEvent(DomainEventInterface $event): void
    {
        $this->eventApplied = true;
    }
}