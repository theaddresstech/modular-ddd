<?php

declare(strict_types=1);

namespace LaravelModularDDD\Tests\Unit\EventSourcing;

use LaravelModularDDD\Tests\TestCase;
use LaravelModularDDD\EventSourcing\EventStore\MySQLEventStore;
use LaravelModularDDD\EventSourcing\EventStore\EventSerializer;
use LaravelModularDDD\EventSourcing\Ordering\EventSequencer;
use LaravelModularDDD\EventSourcing\Exceptions\ConcurrencyException;
use LaravelModularDDD\EventSourcing\Exceptions\EventStoreException;
use LaravelModularDDD\Core\Domain\Contracts\AggregateIdInterface;
use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * Comprehensive test suite for the MySQL Event Store implementation.
 *
 * This tests the ACTUAL implementation without mocks to validate
 * that the event store really works as expected.
 */
class RealEventStoreTest extends TestCase
{
    private MySQLEventStore $eventStore;
    private EventSerializer $serializer;
    private EventSequencer $sequencer;
    private ConnectionInterface $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->app['db']->connection();
        $this->serializer = new EventSerializer();
        $this->sequencer = new EventSequencer(true, 100);
        $this->eventStore = new MySQLEventStore(
            $this->connection,
            $this->serializer,
            $this->sequencer
        );
    }

    /** @test */
    public function it_can_append_and_load_events_for_real(): void
    {
        // Arrange
        $aggregateId = new TestRealAggregateId($this->createTestAggregateId());
        $events = [
            new TestRealEvent($aggregateId->toString(), 'UserCreated', ['name' => 'John Doe', 'email' => 'john@example.com']),
            new TestRealEvent($aggregateId->toString(), 'UserUpdated', ['name' => 'John Smith']),
            new TestRealEvent($aggregateId->toString(), 'UserActivated', ['activated_at' => '2024-01-15 10:30:00']),
        ];

        // Act
        $this->eventStore->append($aggregateId, $events);
        $stream = $this->eventStore->load($aggregateId);

        // Assert
        $loadedEvents = $stream->getEvents();
        $this->assertCount(3, $loadedEvents);

        $this->assertEquals('UserCreated', $loadedEvents[0]->getEventType());
        $this->assertEquals('UserUpdated', $loadedEvents[1]->getEventType());
        $this->assertEquals('UserActivated', $loadedEvents[2]->getEventType());

        // Verify data integrity
        $this->assertEquals(['name' => 'John Doe', 'email' => 'john@example.com'], $loadedEvents[0]->getEventData());
        $this->assertEquals(['name' => 'John Smith'], $loadedEvents[1]->getEventData());
    }

    /** @test */
    public function it_enforces_optimistic_concurrency_control_for_real(): void
    {
        // Arrange
        $aggregateId = new TestRealAggregateId($this->createTestAggregateId());
        $initialEvents = [
            new TestRealEvent($aggregateId->toString(), 'UserCreated', ['name' => 'John']),
            new TestRealEvent($aggregateId->toString(), 'UserUpdated', ['name' => 'John Doe']),
        ];

        // Act - Append initial events
        $this->eventStore->append($aggregateId, $initialEvents);
        $this->assertEquals(2, $this->eventStore->getAggregateVersion($aggregateId));

        // Try to append with wrong expected version
        $conflictingEvent = [new TestRealEvent($aggregateId->toString(), 'UserDeleted', [])];

        // Assert - Should throw concurrency exception
        $this->expectException(ConcurrencyException::class);
        $this->eventStore->append($aggregateId, $conflictingEvent, 1); // Wrong expected version
    }

    /** @test */
    public function it_can_load_events_with_version_range_for_real(): void
    {
        // Arrange
        $aggregateId = new TestRealAggregateId($this->createTestAggregateId());
        $events = [
            new TestRealEvent($aggregateId->toString(), 'Event1', ['sequence' => 1]),
            new TestRealEvent($aggregateId->toString(), 'Event2', ['sequence' => 2]),
            new TestRealEvent($aggregateId->toString(), 'Event3', ['sequence' => 3]),
            new TestRealEvent($aggregateId->toString(), 'Event4', ['sequence' => 4]),
            new TestRealEvent($aggregateId->toString(), 'Event5', ['sequence' => 5]),
        ];

        // Act
        $this->eventStore->append($aggregateId, $events);

        // Load events 2-4
        $stream = $this->eventStore->load($aggregateId, 2, 4);

        // Assert
        $loadedEvents = $stream->getEvents();
        $this->assertCount(3, $loadedEvents); // Events 2, 3, 4

        $this->assertEquals('Event2', $loadedEvents[0]->getEventType());
        $this->assertEquals('Event3', $loadedEvents[1]->getEventType());
        $this->assertEquals('Event4', $loadedEvents[2]->getEventType());
    }

    /** @test */
    public function it_tracks_aggregate_version_correctly_for_real(): void
    {
        // Arrange
        $aggregateId = new TestRealAggregateId($this->createTestAggregateId());

        // Act & Assert - Initial version should be 0
        $this->assertEquals(0, $this->eventStore->getAggregateVersion($aggregateId));

        // Add some events
        $events = [
            new TestRealEvent($aggregateId->toString(), 'Event1', []),
            new TestRealEvent($aggregateId->toString(), 'Event2', []),
            new TestRealEvent($aggregateId->toString(), 'Event3', []),
        ];
        $this->eventStore->append($aggregateId, $events);

        // Version should now be 3
        $this->assertEquals(3, $this->eventStore->getAggregateVersion($aggregateId));

        // Add more events
        $moreEvents = [
            new TestRealEvent($aggregateId->toString(), 'Event4', []),
            new TestRealEvent($aggregateId->toString(), 'Event5', []),
        ];
        $this->eventStore->append($aggregateId, $moreEvents);

        // Version should now be 5
        $this->assertEquals(5, $this->eventStore->getAggregateVersion($aggregateId));
    }

    /** @test */
    public function it_can_check_if_aggregate_exists_for_real(): void
    {
        // Arrange
        $existingId = new TestRealAggregateId($this->createTestAggregateId());
        $nonExistentId = new TestRealAggregateId($this->createTestAggregateId());

        // Act & Assert - Initially both should not exist
        $this->assertFalse($this->eventStore->aggregateExists($existingId));
        $this->assertFalse($this->eventStore->aggregateExists($nonExistentId));

        // Add events for one aggregate
        $events = [new TestRealEvent($existingId->toString(), 'Created', [])];
        $this->eventStore->append($existingId, $events);

        // Now one should exist, the other should not
        $this->assertTrue($this->eventStore->aggregateExists($existingId));
        $this->assertFalse($this->eventStore->aggregateExists($nonExistentId));
    }

    /** @test */
    public function it_can_load_events_by_type_for_real(): void
    {
        // Arrange
        $aggregateId1 = new TestRealAggregateId($this->createTestAggregateId());
        $aggregateId2 = new TestRealAggregateId($this->createTestAggregateId());

        $events1 = [
            new TestRealEvent($aggregateId1->toString(), 'UserCreated', ['user_id' => 'user1']),
            new TestRealEvent($aggregateId1->toString(), 'UserUpdated', ['user_id' => 'user1']),
        ];

        $events2 = [
            new TestRealEvent($aggregateId2->toString(), 'UserCreated', ['user_id' => 'user2']),
            new TestRealEvent($aggregateId2->toString(), 'UserDeleted', ['user_id' => 'user2']),
        ];

        // Act
        $this->eventStore->append($aggregateId1, $events1);
        $this->eventStore->append($aggregateId2, $events2);

        $userCreatedEvents = $this->eventStore->loadEventsByType('UserCreated');

        // Assert
        $this->assertCount(2, $userCreatedEvents);

        // Both events should be UserCreated
        foreach ($userCreatedEvents as $event) {
            $this->assertEquals('UserCreated', $event->getEventType());
        }
    }

    /** @test */
    public function it_can_batch_load_multiple_aggregates_for_real(): void
    {
        // Arrange
        $aggregateId1 = new TestRealAggregateId($this->createTestAggregateId());
        $aggregateId2 = new TestRealAggregateId($this->createTestAggregateId());
        $aggregateId3 = new TestRealAggregateId($this->createTestAggregateId());

        // Add events for first two aggregates
        $this->eventStore->append($aggregateId1, [
            new TestRealEvent($aggregateId1->toString(), 'Event1', []),
            new TestRealEvent($aggregateId1->toString(), 'Event2', []),
        ]);

        $this->eventStore->append($aggregateId2, [
            new TestRealEvent($aggregateId2->toString(), 'Event1', []),
            new TestRealEvent($aggregateId2->toString(), 'Event2', []),
            new TestRealEvent($aggregateId2->toString(), 'Event3', []),
        ]);

        // aggregateId3 has no events

        // Act
        $eventStreams = $this->eventStore->loadBatch([$aggregateId1, $aggregateId2, $aggregateId3]);

        // Assert
        $this->assertCount(3, $eventStreams);
        $this->assertCount(2, $eventStreams[$aggregateId1->toString()]->getEvents());
        $this->assertCount(3, $eventStreams[$aggregateId2->toString()]->getEvents());
        $this->assertCount(0, $eventStreams[$aggregateId3->toString()]->getEvents());
    }

    /** @test */
    public function it_can_batch_get_aggregate_versions_for_real(): void
    {
        // Arrange
        $aggregateId1 = new TestRealAggregateId($this->createTestAggregateId());
        $aggregateId2 = new TestRealAggregateId($this->createTestAggregateId());
        $aggregateId3 = new TestRealAggregateId($this->createTestAggregateId());

        // Add different numbers of events
        $this->eventStore->append($aggregateId1, [
            new TestRealEvent($aggregateId1->toString(), 'Event1', []),
            new TestRealEvent($aggregateId1->toString(), 'Event2', []),
        ]);

        $this->eventStore->append($aggregateId2, [
            new TestRealEvent($aggregateId2->toString(), 'Event1', []),
            new TestRealEvent($aggregateId2->toString(), 'Event2', []),
            new TestRealEvent($aggregateId2->toString(), 'Event3', []),
            new TestRealEvent($aggregateId2->toString(), 'Event4', []),
            new TestRealEvent($aggregateId2->toString(), 'Event5', []),
        ]);

        // aggregateId3 has no events

        // Act
        $versions = $this->eventStore->getAggregateVersionsBatch([$aggregateId1, $aggregateId2, $aggregateId3]);

        // Assert
        $this->assertCount(3, $versions);
        $this->assertEquals(2, $versions[$aggregateId1->toString()]);
        $this->assertEquals(5, $versions[$aggregateId2->toString()]);
        $this->assertEquals(0, $versions[$aggregateId3->toString()]);
    }

    /** @test */
    public function it_can_batch_check_aggregate_existence_for_real(): void
    {
        // Arrange
        $aggregateId1 = new TestRealAggregateId($this->createTestAggregateId());
        $aggregateId2 = new TestRealAggregateId($this->createTestAggregateId());
        $aggregateId3 = new TestRealAggregateId($this->createTestAggregateId());

        // Add events for aggregate 1 and 3
        $this->eventStore->append($aggregateId1, [
            new TestRealEvent($aggregateId1->toString(), 'Event1', []),
        ]);

        $this->eventStore->append($aggregateId3, [
            new TestRealEvent($aggregateId3->toString(), 'Event1', []),
        ]);

        // aggregate 2 has no events

        // Act
        $exists = $this->eventStore->aggregateExistsBatch([$aggregateId1, $aggregateId2, $aggregateId3]);

        // Assert
        $this->assertCount(3, $exists);
        $this->assertTrue($exists[$aggregateId1->toString()]);
        $this->assertFalse($exists[$aggregateId2->toString()]);
        $this->assertTrue($exists[$aggregateId3->toString()]);
    }

    /** @test */
    public function it_handles_empty_events_gracefully_for_real(): void
    {
        // Arrange
        $aggregateId = new TestRealAggregateId($this->createTestAggregateId());

        // Act & Assert - Should not throw exception
        $this->eventStore->append($aggregateId, []);

        $stream = $this->eventStore->load($aggregateId);
        $this->assertCount(0, $stream->getEvents());
        $this->assertEquals(0, $this->eventStore->getAggregateVersion($aggregateId));
    }

    /** @test */
    public function it_maintains_event_order_through_sequencer_for_real(): void
    {
        // Arrange
        $aggregateId = new TestRealAggregateId($this->createTestAggregateId());
        $events = [
            new TestRealEvent($aggregateId->toString(), 'EventA', ['order' => 1]),
            new TestRealEvent($aggregateId->toString(), 'EventB', ['order' => 2]),
            new TestRealEvent($aggregateId->toString(), 'EventC', ['order' => 3]),
            new TestRealEvent($aggregateId->toString(), 'EventD', ['order' => 4]),
            new TestRealEvent($aggregateId->toString(), 'EventE', ['order' => 5]),
        ];

        // Act
        $this->eventStore->append($aggregateId, $events);
        $stream = $this->eventStore->load($aggregateId);

        // Assert
        $loadedEvents = $stream->getEvents();
        $this->assertCount(5, $loadedEvents);

        $this->assertEquals('EventA', $loadedEvents[0]->getEventType());
        $this->assertEquals('EventB', $loadedEvents[1]->getEventType());
        $this->assertEquals('EventC', $loadedEvents[2]->getEventType());
        $this->assertEquals('EventD', $loadedEvents[3]->getEventType());
        $this->assertEquals('EventE', $loadedEvents[4]->getEventType());

        // Verify order data
        $this->assertEquals(['order' => 1], $loadedEvents[0]->getEventData());
        $this->assertEquals(['order' => 2], $loadedEvents[1]->getEventData());
        $this->assertEquals(['order' => 5], $loadedEvents[4]->getEventData());
    }

    /** @test */
    public function it_performs_within_acceptable_time_limits_for_real(): void
    {
        // Arrange
        $aggregateId = new TestRealAggregateId($this->createTestAggregateId());
        $events = [];

        // Create 100 events for performance testing
        for ($i = 1; $i <= 100; $i++) {
            $events[] = new TestRealEvent($aggregateId->toString(), "Event{$i}", ['sequence' => $i]);
        }

        // Act & Assert - Append should be fast
        $this->assertExecutionTimeWithinLimits(function () use ($aggregateId, $events) {
            $this->eventStore->append($aggregateId, $events);
        }, 1000); // 1 second max

        // Load should also be fast
        $this->assertExecutionTimeWithinLimits(function () use ($aggregateId) {
            $this->eventStore->load($aggregateId);
        }, 500); // 500ms max
    }

    /** @test */
    public function it_uses_memory_efficiently_for_real(): void
    {
        // Arrange
        $aggregateId = new TestRealAggregateId($this->createTestAggregateId());
        $events = [];

        // Create many events with significant data
        for ($i = 1; $i <= 500; $i++) {
            $events[] = new TestRealEvent(
                $aggregateId->toString(),
                "LargeEvent{$i}",
                [
                    'sequence' => $i,
                    'data' => str_repeat("x", 1000), // 1KB of data per event
                    'metadata' => range(1, 100) // Some array data
                ]
            );
        }

        // Act
        $this->eventStore->append($aggregateId, $events);
        $stream = $this->eventStore->load($aggregateId);

        // Assert
        $this->assertMemoryUsageWithinLimits(64); // 64MB max for this test
        $this->assertCount(500, $stream->getEvents());
    }

    /** @test */
    public function it_handles_concurrent_transactions_correctly(): void
    {
        // This test simulates concurrent access patterns
        $aggregateId = new TestRealAggregateId($this->createTestAggregateId());

        // Initial events
        $initialEvents = [
            new TestRealEvent($aggregateId->toString(), 'UserCreated', ['name' => 'John']),
        ];
        $this->eventStore->append($aggregateId, $initialEvents);

        $version = $this->eventStore->getAggregateVersion($aggregateId);
        $this->assertEquals(1, $version);

        // Now try to append with the correct version
        $newEvents = [
            new TestRealEvent($aggregateId->toString(), 'UserUpdated', ['name' => 'John Doe']),
        ];

        // This should work
        $this->eventStore->append($aggregateId, $newEvents, $version);
        $this->assertEquals(2, $this->eventStore->getAggregateVersion($aggregateId));
    }

    /** @test */
    public function it_preserves_event_metadata_correctly(): void
    {
        // Arrange
        $aggregateId = new TestRealAggregateId($this->createTestAggregateId());
        $event = new TestRealEvent(
            $aggregateId->toString(),
            'TestEvent',
            ['data' => 'test'],
            [
                'correlation_id' => 'test-correlation-123',
                'causation_id' => 'test-causation-456',
                'user_id' => 'user-789'
            ]
        );

        // Act
        $this->eventStore->append($aggregateId, [$event]);
        $stream = $this->eventStore->load($aggregateId);

        // Assert
        $loadedEvent = $stream->getEvents()[0];
        $metadata = $loadedEvent->getMetadata();

        $this->assertEquals('test-correlation-123', $metadata['correlation_id']);
        $this->assertEquals('test-causation-456', $metadata['causation_id']);
        $this->assertEquals('user-789', $metadata['user_id']);
    }
}

/**
 * Test implementation of AggregateId for real testing.
 */
class TestRealAggregateId implements AggregateIdInterface
{
    public function __construct(private string $id)
    {
    }

    public function toString(): string
    {
        return $this->id;
    }

    public function equals(AggregateIdInterface $other): bool
    {
        return $other instanceof self && $this->id === $other->id;
    }

    public static function generate(): static
    {
        return new static(\Illuminate\Support\Str::uuid()->toString());
    }

    public static function fromString(string $id): static
    {
        return new static($id);
    }
}

/**
 * Test implementation of DomainEvent for real testing.
 */
class TestRealEvent implements DomainEventInterface
{
    private \DateTimeImmutable $occurredAt;
    private string $eventId;

    public function __construct(
        private string $aggregateId,
        private string $eventType,
        private array $eventData,
        private array $metadata = []
    ) {
        $this->occurredAt = new \DateTimeImmutable();
        $this->eventId = \Illuminate\Support\Str::uuid()->toString();

        // Add default metadata
        $this->metadata = array_merge([
            'aggregate_type' => 'TestAggregate',
            'event_version' => '1.0',
        ], $metadata);
    }

    public function getAggregateId(): AggregateIdInterface
    {
        return new TestRealAggregateId($this->aggregateId);
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getEventData(): array
    {
        return $this->eventData;
    }

    public function getEventVersion(): int
    {
        return 1;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getPayload(): array
    {
        return $this->eventData;
    }

    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'aggregate_id' => $this->aggregateId,
            'event_type' => $this->eventType,
            'event_version' => $this->getEventVersion(),
            'payload' => $this->eventData,
            'occurred_at' => $this->occurredAt->format('c'),
            'metadata' => $this->metadata,
        ];
    }

    public static function fromArray(array $data): static
    {
        $event = new static(
            $data['aggregate_id'],
            $data['event_type'],
            $data['payload'] ?? [],
            $data['metadata'] ?? []
        );

        if (isset($data['event_id'])) {
            $event->eventId = $data['event_id'];
        }

        if (isset($data['occurred_at'])) {
            $event->occurredAt = new \DateTimeImmutable($data['occurred_at']);
        }

        return $event;
    }
}