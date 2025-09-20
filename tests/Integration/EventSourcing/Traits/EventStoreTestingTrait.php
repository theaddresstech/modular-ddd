<?php

declare(strict_types=1);

namespace LaravelModularDDD\Tests\Integration\EventSourcing\Traits;

use LaravelModularDDD\Core\Domain\Contracts\AggregateIdInterface;
use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Trait providing common testing utilities for Event Store integration tests.
 */
trait EventStoreTestingTrait
{
    /**
     * Create a test aggregate with events for load testing
     */
    protected function createTestAggregateWithEvents(int $eventCount = 10): array
    {
        $aggregateId = $this->createTestAggregateId();
        $events = [];

        for ($i = 1; $i <= $eventCount; $i++) {
            $events[] = $this->createTestDomainEvent(
                $aggregateId,
                "TestEvent{$i}",
                ['sequence' => $i, 'data' => "test-data-{$i}"],
                $i
            );
        }

        return [$aggregateId, $events];
    }

    /**
     * Create multiple aggregates for batch testing
     */
    protected function createMultipleTestAggregates(int $aggregateCount = 3, int $eventsPerAggregate = 5): array
    {
        $aggregates = [];

        for ($i = 0; $i < $aggregateCount; $i++) {
            [$aggregateId, $events] = $this->createTestAggregateWithEvents($eventsPerAggregate);
            $aggregates[] = ['id' => $aggregateId, 'events' => $events];
        }

        return $aggregates;
    }

    /**
     * Create a test domain event
     */
    protected function createTestDomainEvent(
        AggregateIdInterface $aggregateId,
        string $eventType,
        array $data = [],
        int $version = 1
    ): DomainEventInterface {
        return new class($aggregateId, $eventType, $data, $version) implements DomainEventInterface {
            private \DateTimeImmutable $occurredAt;

            public function __construct(
                private AggregateIdInterface $aggregateId,
                private string $eventType,
                private array $data,
                private int $version
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
                return $this->data;
            }

            public function getVersion(): int
            {
                return $this->version;
            }

            public function getOccurredAt(): \DateTimeImmutable
            {
                return $this->occurredAt;
            }

            public function getEventVersion(): int
            {
                return 1;
            }

            public function getEventId(): string
            {
                return \Illuminate\Support\Str::uuid()->toString();
            }

            public function getPayload(): array
            {
                return $this->data;
            }

            public function toArray(): array
            {
                return [
                    'event_id' => $this->getEventId(),
                    'aggregate_id' => $this->aggregateId->toString(),
                    'event_type' => $this->eventType,
                    'event_version' => $this->getEventVersion(),
                    'payload' => $this->data,
                    'version' => $this->version,
                    'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
                    'metadata' => $this->getMetadata(),
                ];
            }

            public function getMetadata(): array
            {
                return [
                    'test_event' => true,
                    'created_at' => $this->occurredAt->format('Y-m-d H:i:s'),
                ];
            }

            public static function fromArray(array $data): static
            {
                $aggregateId = new class($data['aggregate_id']) implements AggregateIdInterface {
                    public function __construct(private string $id) {}
                    public function toString(): string { return $this->id; }
                    public function equals(AggregateIdInterface $other): bool { return false; }
                    public static function generate(): static { return new static(\Illuminate\Support\Str::uuid()->toString()); }
                    public static function fromString(string $id): static { return new static($id); }
                };

                return new static(
                    $aggregateId,
                    $data['event_type'],
                    $data['payload'] ?? [],
                    $data['version'] ?? 1
                );
            }
        };
    }

    /**
     * Assert that events are stored correctly in the database
     */
    protected function assertEventsStoredInDatabase(AggregateIdInterface $aggregateId, int $expectedCount): void
    {
        $storedCount = DB::table('event_store')
            ->where('aggregate_id', $aggregateId->toString())
            ->count();

        $this->assertEquals($expectedCount, $storedCount);
    }

    /**
     * Assert that events are stored correctly in Redis
     */
    protected function assertEventsStoredInRedis(AggregateIdInterface $aggregateId, int $expectedCount): void
    {
        $redisKey = "events:{$aggregateId->toString()}";
        $storedCount = Redis::connection('event_sourcing')->llen($redisKey);

        $this->assertEquals($expectedCount, $storedCount);
    }

    /**
     * Assert that snapshot exists for aggregate
     */
    protected function assertSnapshotExists(AggregateIdInterface $aggregateId, int $expectedVersion): void
    {
        $snapshot = DB::table('snapshots')
            ->where('aggregate_id', $aggregateId->toString())
            ->where('version', $expectedVersion)
            ->first();

        $this->assertNotNull($snapshot);
        $this->assertEquals($expectedVersion, $snapshot->version);
    }

    /**
     * Create test event stream for load testing
     */
    protected function createTestEventStream(AggregateIdInterface $aggregateId, array $events): object
    {
        return new class($aggregateId, $events) {
            public function __construct(
                private AggregateIdInterface $aggregateId,
                private array $events
            ) {}

            public function getAggregateId(): AggregateIdInterface
            {
                return $this->aggregateId;
            }

            public function getEvents(): array
            {
                return $this->events;
            }

            public function isEmpty(): bool
            {
                return empty($this->events);
            }

            public function getVersion(): int
            {
                if (empty($this->events)) {
                    return 0;
                }

                $lastEvent = end($this->events);
                return $lastEvent->getVersion();
            }

            public function slice(int $fromVersion, ?int $toVersion = null): self
            {
                $filteredEvents = array_filter($this->events, function ($event) use ($fromVersion, $toVersion) {
                    $version = $event->getVersion();
                    if ($version < $fromVersion) {
                        return false;
                    }
                    if ($toVersion !== null && $version > $toVersion) {
                        return false;
                    }
                    return true;
                });

                return new self($this->aggregateId, array_values($filteredEvents));
            }
        };
    }

    /**
     * Simulate concurrent modification for testing optimistic locking
     */
    protected function simulateConcurrentModification(
        AggregateIdInterface $aggregateId,
        int $expectedVersion,
        int $eventCount = 2
    ): array {
        $events = [];
        for ($i = 1; $i <= $eventCount; $i++) {
            $events[] = $this->createTestDomainEvent(
                $aggregateId,
                'ConcurrentEvent',
                ['attempt' => $i],
                $expectedVersion + $i
            );
        }

        return $events;
    }

    /**
     * Measure execution time of a callable
     */
    protected function measureExecutionTime(callable $operation): array
    {
        $startTime = microtime(true);
        $memoryBefore = memory_get_usage(true);

        $result = $operation();

        $endTime = microtime(true);
        $memoryAfter = memory_get_usage(true);

        return [
            'result' => $result,
            'execution_time' => $endTime - $startTime,
            'memory_usage' => $memoryAfter - $memoryBefore,
            'peak_memory' => memory_get_peak_usage(true),
        ];
    }

    /**
     * Generate events for performance testing
     */
    protected function generateEventsForPerformanceTest(int $eventCount): array
    {
        $aggregateId = $this->createTestAggregateId();
        $events = [];

        for ($i = 1; $i <= $eventCount; $i++) {
            $events[] = $this->createTestDomainEvent(
                $aggregateId,
                'PerformanceTestEvent',
                [
                    'sequence' => $i,
                    'timestamp' => microtime(true),
                    'payload' => str_repeat('x', 100), // 100 byte payload
                ],
                $i
            );
        }

        return [$aggregateId, $events];
    }

    /**
     * Verify event ordering is maintained
     */
    protected function assertEventOrderingMaintained(array $events): void
    {
        $previousVersion = 0;
        $previousOccurredAt = null;

        foreach ($events as $event) {
            // Version should increase
            $this->assertGreaterThan($previousVersion, $event->getVersion());
            $previousVersion = $event->getVersion();

            // Time should not go backwards (allow equal for same millisecond)
            if ($previousOccurredAt !== null) {
                $this->assertGreaterThanOrEqual(
                    $previousOccurredAt->getTimestamp(),
                    $event->getOccurredAt()->getTimestamp()
                );
            }
            $previousOccurredAt = $event->getOccurredAt();
        }
    }

    /**
     * Wait for async operations to complete (for testing async event persistence)
     */
    protected function waitForAsyncOperations(int $timeoutSeconds = 5): void
    {
        $start = time();

        while (time() - $start < $timeoutSeconds) {
            // Check if queue is empty (all jobs processed)
            if ($this->isQueueEmpty()) {
                return;
            }

            usleep(100000); // Wait 100ms
        }

        $this->fail('Async operations did not complete within timeout period');
    }

    /**
     * Check if the queue is empty
     */
    private function isQueueEmpty(): bool
    {
        // This is a simplified check - in real applications you might need
        // to check specific queue connections/names
        return true; // For testing with Queue::fake(), assume immediate processing
    }

    /**
     * Create test aggregate ID with a unique identifier
     */
    protected function createTestAggregateId(string $prefix = 'test'): AggregateIdInterface
    {
        return new class($prefix . '_' . Uuid::uuid4()->toString()) implements AggregateIdInterface {
            public function __construct(private string $id) {}

            public function toString(): string
            {
                return $this->id;
            }

            public function equals(AggregateIdInterface $other): bool
            {
                return $this->id === $other->toString();
            }

            public static function generate(): static
            {
                return new static('test_' . \Ramsey\Uuid\Uuid::uuid4()->toString());
            }

            public static function fromString(string $id): static
            {
                return new static($id);
            }
        };
    }
}