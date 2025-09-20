<?php

declare(strict_types=1);

namespace LaravelModularDDD\Tests\Unit\EventSourcing;

use LaravelModularDDD\Tests\TestCase;
use LaravelModularDDD\EventSourcing\Ordering\EventSequencer;
use LaravelModularDDD\EventSourcing\Exceptions\EventOrderingException;
use LaravelModularDDD\Core\Domain\Contracts\AggregateIdInterface;
use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;

/**
 * Test suite for Event Sequencer.
 *
 * This validates that event ordering is enforced correctly,
 * which is critical for maintaining aggregate consistency.
 */
class EventSequencerTest extends TestCase
{
    /** @test */
    public function it_allows_correctly_ordered_events_in_strict_mode(): void
    {
        // Arrange
        $sequencer = new EventSequencer(true, 100); // strict mode
        $aggregateId = new TestSequenceAggregateId($this->createTestAggregateId());

        $events = [
            new TestSequenceEvent($aggregateId, 'Event1', 1),
            new TestSequenceEvent($aggregateId, 'Event2', 2),
            new TestSequenceEvent($aggregateId, 'Event3', 3),
        ];

        // Act
        $orderedEvents = $sequencer->enforceOrder($aggregateId, $events);

        // Assert
        $this->assertCount(3, $orderedEvents);
        $this->assertEquals($events[0], $orderedEvents[0]);
        $this->assertEquals($events[1], $orderedEvents[1]);
        $this->assertEquals($events[2], $orderedEvents[2]);
    }

    /** @test */
    public function it_throws_exception_for_out_of_order_events_in_strict_mode(): void
    {
        // Arrange
        $sequencer = new EventSequencer(true, 100); // strict mode
        $aggregateId = new TestSequenceAggregateId($this->createTestAggregateId());

        $events = [
            new TestSequenceEvent($aggregateId, 'Event1', 1),
            new TestSequenceEvent($aggregateId, 'Event3', 3), // Missing event 2
            new TestSequenceEvent($aggregateId, 'Event2', 2), // Out of order
        ];

        // Act & Assert
        $this->expectException(EventOrderingException::class);
        $sequencer->enforceOrder($aggregateId, $events);
    }

    /** @test */
    public function it_reorders_events_in_non_strict_mode(): void
    {
        // Arrange
        $sequencer = new EventSequencer(false, 100); // non-strict mode
        $aggregateId = new TestSequenceAggregateId($this->createTestAggregateId());

        $events = [
            new TestSequenceEvent($aggregateId, 'Event3', 3),
            new TestSequenceEvent($aggregateId, 'Event1', 1),
            new TestSequenceEvent($aggregateId, 'Event2', 2),
        ];

        // Act
        $orderedEvents = $sequencer->enforceOrder($aggregateId, $events);

        // Assert
        $this->assertCount(3, $orderedEvents);
        $this->assertEquals('Event1', $orderedEvents[0]->getEventType());
        $this->assertEquals('Event2', $orderedEvents[1]->getEventType());
        $this->assertEquals('Event3', $orderedEvents[2]->getEventType());
    }

    /** @test */
    public function it_handles_events_with_same_sequence_number(): void
    {
        // Arrange
        $sequencer = new EventSequencer(false, 100);
        $aggregateId = new TestSequenceAggregateId($this->createTestAggregateId());

        $events = [
            new TestSequenceEvent($aggregateId, 'Event1', 1),
            new TestSequenceEvent($aggregateId, 'Event2', 1), // Same sequence
            new TestSequenceEvent($aggregateId, 'Event3', 2),
        ];

        // Act
        $orderedEvents = $sequencer->enforceOrder($aggregateId, $events);

        // Assert - Should maintain original order for events with same sequence
        $this->assertCount(3, $orderedEvents);
        $this->assertEquals('Event1', $orderedEvents[0]->getEventType());
        $this->assertEquals('Event2', $orderedEvents[1]->getEventType());
        $this->assertEquals('Event3', $orderedEvents[2]->getEventType());
    }

    /** @test */
    public function it_validates_max_reorder_window(): void
    {
        // Arrange
        $sequencer = new EventSequencer(false, 3); // Small reorder window
        $aggregateId = new TestSequenceAggregateId($this->createTestAggregateId());

        $events = [
            new TestSequenceEvent($aggregateId, 'Event1', 1),
            new TestSequenceEvent($aggregateId, 'Event5', 5), // Gap of 4, exceeds window of 3
        ];

        // Act & Assert
        $this->expectException(EventOrderingException::class);
        $sequencer->enforceOrder($aggregateId, $events);
    }

    /** @test */
    public function it_allows_gaps_within_reorder_window(): void
    {
        // Arrange
        $sequencer = new EventSequencer(false, 5); // Window of 5
        $aggregateId = new TestSequenceAggregateId($this->createTestAggregateId());

        $events = [
            new TestSequenceEvent($aggregateId, 'Event1', 1),
            new TestSequenceEvent($aggregateId, 'Event4', 4), // Gap of 3, within window
        ];

        // Act
        $orderedEvents = $sequencer->enforceOrder($aggregateId, $events);

        // Assert
        $this->assertCount(2, $orderedEvents);
        $this->assertEquals('Event1', $orderedEvents[0]->getEventType());
        $this->assertEquals('Event4', $orderedEvents[1]->getEventType());
    }

    /** @test */
    public function it_handles_empty_event_list(): void
    {
        // Arrange
        $sequencer = new EventSequencer(true, 100);
        $aggregateId = new TestSequenceAggregateId($this->createTestAggregateId());

        // Act
        $orderedEvents = $sequencer->enforceOrder($aggregateId, []);

        // Assert
        $this->assertCount(0, $orderedEvents);
    }

    /** @test */
    public function it_handles_single_event(): void
    {
        // Arrange
        $sequencer = new EventSequencer(true, 100);
        $aggregateId = new TestSequenceAggregateId($this->createTestAggregateId());

        $events = [
            new TestSequenceEvent($aggregateId, 'SingleEvent', 42),
        ];

        // Act
        $orderedEvents = $sequencer->enforceOrder($aggregateId, $events);

        // Assert
        $this->assertCount(1, $orderedEvents);
        $this->assertEquals('SingleEvent', $orderedEvents[0]->getEventType());
    }

    /** @test */
    public function it_maintains_sequence_across_multiple_calls(): void
    {
        // Arrange
        $sequencer = new EventSequencer(true, 100);
        $aggregateId = new TestSequenceAggregateId($this->createTestAggregateId());

        // First batch
        $firstBatch = [
            new TestSequenceEvent($aggregateId, 'Event1', 1),
            new TestSequenceEvent($aggregateId, 'Event2', 2),
        ];

        // Second batch - should continue from where first batch left off
        $secondBatch = [
            new TestSequenceEvent($aggregateId, 'Event3', 3),
            new TestSequenceEvent($aggregateId, 'Event4', 4),
        ];

        // Act
        $firstOrdered = $sequencer->enforceOrder($aggregateId, $firstBatch);
        $secondOrdered = $sequencer->enforceOrder($aggregateId, $secondBatch);

        // Assert
        $this->assertCount(2, $firstOrdered);
        $this->assertCount(2, $secondOrdered);

        $this->assertEquals('Event1', $firstOrdered[0]->getEventType());
        $this->assertEquals('Event2', $firstOrdered[1]->getEventType());
        $this->assertEquals('Event3', $secondOrdered[0]->getEventType());
        $this->assertEquals('Event4', $secondOrdered[1]->getEventType());
    }

    /** @test */
    public function it_provides_helpful_error_messages(): void
    {
        // Arrange
        $sequencer = new EventSequencer(true, 100);
        $aggregateId = new TestSequenceAggregateId($this->createTestAggregateId());

        $events = [
            new TestSequenceEvent($aggregateId, 'Event1', 1),
            new TestSequenceEvent($aggregateId, 'Event3', 3), // Missing event 2
        ];

        // Act & Assert
        try {
            $sequencer->enforceOrder($aggregateId, $events);
            $this->fail('Expected EventOrderingException');
        } catch (EventOrderingException $e) {
            $this->assertStringContainsString('Event ordering violation', $e->getMessage());
            $this->assertStringContainsString($aggregateId->toString(), $e->getMessage());
        }
    }

    /** @test */
    public function it_performs_efficiently_with_large_event_batches(): void
    {
        // Arrange
        $sequencer = new EventSequencer(false, 1000);
        $aggregateId = new TestSequenceAggregateId($this->createTestAggregateId());

        // Create 1000 events in random order
        $events = [];
        $sequences = range(1, 1000);
        shuffle($sequences);

        foreach ($sequences as $seq) {
            $events[] = new TestSequenceEvent($aggregateId, "Event{$seq}", $seq);
        }

        // Act & Assert
        $this->assertExecutionTimeWithinLimits(function () use ($sequencer, $aggregateId, $events) {
            $orderedEvents = $sequencer->enforceOrder($aggregateId, $events);
            $this->assertCount(1000, $orderedEvents);

            // Verify they're in order
            for ($i = 0; $i < 1000; $i++) {
                $this->assertEquals("Event" . ($i + 1), $orderedEvents[$i]->getEventType());
            }
        }, 2000); // 2 seconds max for 1000 events
    }

    /** @test */
    public function it_handles_sequence_gaps_appropriately(): void
    {
        // Arrange
        $sequencer = new EventSequencer(false, 10);
        $aggregateId = new TestSequenceAggregateId($this->createTestAggregateId());

        // Events with gaps but within window
        $events = [
            new TestSequenceEvent($aggregateId, 'Event1', 1),
            new TestSequenceEvent($aggregateId, 'Event3', 3),
            new TestSequenceEvent($aggregateId, 'Event7', 7),
            new TestSequenceEvent($aggregateId, 'Event10', 10),
        ];

        // Act
        $orderedEvents = $sequencer->enforceOrder($aggregateId, $events);

        // Assert
        $this->assertCount(4, $orderedEvents);
        $this->assertEquals('Event1', $orderedEvents[0]->getEventType());
        $this->assertEquals('Event3', $orderedEvents[1]->getEventType());
        $this->assertEquals('Event7', $orderedEvents[2]->getEventType());
        $this->assertEquals('Event10', $orderedEvents[3]->getEventType());
    }
}

/**
 * Test aggregate ID for sequence testing.
 */
class TestSequenceAggregateId implements AggregateIdInterface
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
 * Test event with sequence number for sequence testing.
 */
class TestSequenceEvent implements DomainEventInterface
{
    private \DateTimeImmutable $occurredAt;

    public function __construct(
        private TestSequenceAggregateId $aggregateId,
        private string $eventType,
        private int $sequenceNumber
    ) {
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getAggregateId(): AggregateIdInterface
    {
        return $this->aggregateId;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getEventData(): array
    {
        return ['sequence' => $this->sequenceNumber];
    }

    public function getEventId(): string
    {
        return \Illuminate\Support\Str::uuid()->toString();
    }

    public function getEventVersion(): int
    {
        return 1;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getPayload(): array
    {
        return ['sequence' => $this->sequenceNumber];
    }

    public function getMetadata(): array
    {
        return [
            'aggregate_type' => 'TestAggregate',
            'event_version' => '1.0',
            'sequence_number' => $this->sequenceNumber,
        ];
    }

    public function getSequenceNumber(): int
    {
        return $this->sequenceNumber;
    }

    public function toArray(): array
    {
        return [
            'aggregate_id' => $this->aggregateId->toString(),
            'event_type' => $this->eventType,
            'sequence_number' => $this->sequenceNumber,
            'occurred_at' => $this->occurredAt->format('c'),
        ];
    }

    public static function fromArray(array $data): static
    {
        $aggregateId = new TestSequenceAggregateId($data['aggregate_id']);
        return new static(
            $aggregateId,
            $data['event_type'],
            $data['sequence_number']
        );
    }
}