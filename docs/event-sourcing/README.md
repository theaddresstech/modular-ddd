# Event Sourcing Implementation Guide

This guide provides a comprehensive overview of the event sourcing implementation in the Laravel Modular DDD package, including tiered storage, snapshots, and advanced patterns.

## Table of Contents

- [Event Sourcing Fundamentals](#event-sourcing-fundamentals)
- [Tiered Storage Architecture](#tiered-storage-architecture)
- [Event Store Implementation](#event-store-implementation)
- [Snapshot Strategies](#snapshot-strategies)
- [Event Ordering and Sequencing](#event-ordering-and-sequencing)
- [Projections and Read Models](#projections-and-read-models)
- [Performance Optimization](#performance-optimization)
- [Advanced Patterns](#advanced-patterns)
- [Best Practices](#best-practices)

## Event Sourcing Fundamentals

### What is Event Sourcing?

Event Sourcing is a pattern where state changes are stored as a sequence of events. Instead of storing the current state, we store all the events that led to that state.

**Traditional Approach:**
```sql
-- User table stores current state
SELECT name, email, status FROM users WHERE id = 123;
```

**Event Sourcing Approach:**
```sql
-- Event store contains all state changes
SELECT * FROM event_store WHERE aggregate_id = '123' ORDER BY version;
-- UserCreated: {name: "John", email: "john@example.com"}
-- UserEmailChanged: {old_email: "john@example.com", new_email: "john.doe@example.com"}
-- UserActivated: {}
```

### Benefits

1. **Complete Audit Trail**: Every change is recorded
2. **Temporal Queries**: Query state at any point in time
3. **Event Replay**: Rebuild state from events
4. **Integration**: Events can trigger other systems
5. **Debugging**: Full history for troubleshooting

### Key Components

```php
// Domain Event
class UserEmailChanged implements DomainEventInterface
{
    public function __construct(
        public readonly UserId $userId,
        public readonly Email $oldEmail,
        public readonly Email $newEmail,
        public readonly \DateTimeImmutable $occurredAt
    ) {}
}

// Aggregate Root
class User extends AggregateRoot
{
    public function changeEmail(Email $newEmail): void
    {
        if ($this->email->equals($newEmail)) {
            return;
        }

        $oldEmail = $this->email;
        $this->email = $newEmail;

        $this->recordEvent(new UserEmailChanged(
            $this->id,
            $oldEmail,
            $newEmail,
            new \DateTimeImmutable()
        ));
    }
}

// Event Store
interface EventStoreInterface
{
    public function append(
        AggregateIdInterface $aggregateId,
        array $events,
        ?int $expectedVersion = null
    ): void;

    public function load(
        AggregateIdInterface $aggregateId,
        int $fromVersion = 1,
        ?int $toVersion = null
    ): EventStreamInterface;
}
```

## Tiered Storage Architecture

The package implements a sophisticated tiered storage system for optimal performance:

### Hot Tier (Redis)

**Purpose**: Recently accessed events for ultra-fast retrieval

```php
'storage_tiers' => [
    'hot' => [
        'driver' => 'redis',
        'connection' => 'default',
        'ttl' => 86400, // 24 hours
        'enabled' => true,
    ],
],
```

**Characteristics:**
- Sub-millisecond access times
- Automatic TTL-based expiration
- Memory-optimized storage
- High-frequency read optimization

### Warm Tier (MySQL)

**Purpose**: Persistent storage with full durability

```php
'storage_tiers' => [
    'warm' => [
        'driver' => 'mysql',
        'connection' => 'mysql',
        'table' => 'event_store',
        'snapshots_table' => 'snapshots',
    ],
],
```

**Characteristics:**
- ACID compliance
- Horizontal scalability
- Full-text search capabilities
- Long-term retention

### Tiered Event Store Implementation

```php
class TieredEventStore implements EventStoreInterface
{
    public function __construct(
        private RedisEventStore $hotTier,
        private MySQLEventStore $warmTier,
        private int $hotTierTtl = 86400
    ) {}

    public function load(
        AggregateIdInterface $aggregateId,
        int $fromVersion = 1,
        ?int $toVersion = null
    ): EventStreamInterface {
        // Try hot tier first
        $events = $this->hotTier->load($aggregateId, $fromVersion, $toVersion);
        
        if ($events->isEmpty()) {
            // Fallback to warm tier
            $events = $this->warmTier->load($aggregateId, $fromVersion, $toVersion);
            
            // Promote to hot tier for future access
            $this->promoteToHotTier($aggregateId, $events);
        }
        
        return $events;
    }

    public function append(
        AggregateIdInterface $aggregateId,
        array $events,
        ?int $expectedVersion = null
    ): void {
        // Always write to warm tier first (durability)
        $this->warmTier->append($aggregateId, $events, $expectedVersion);
        
        // Then write to hot tier (performance)
        $this->hotTier->append($aggregateId, $events, $expectedVersion);
    }
}
```

## Event Store Implementation

### Database Schema

```sql
CREATE TABLE event_store (
    sequence_number BIGINT AUTO_INCREMENT PRIMARY KEY,
    aggregate_id VARCHAR(255) NOT NULL,
    aggregate_type VARCHAR(255) NOT NULL,
    event_type VARCHAR(255) NOT NULL,
    event_data JSON NOT NULL,
    metadata JSON,
    version INT NOT NULL,
    occurred_at TIMESTAMP(6) NOT NULL,
    
    -- Performance indexes
    INDEX idx_aggregate (aggregate_id, version),
    INDEX idx_type_sequence (event_type, sequence_number),
    INDEX idx_occurred_at (occurred_at),
    
    -- Concurrency control
    UNIQUE KEY uk_aggregate_version (aggregate_id, version)
);

CREATE TABLE snapshots (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    aggregate_id VARCHAR(255) NOT NULL,
    aggregate_type VARCHAR(255) NOT NULL,
    data JSON NOT NULL,
    version INT NOT NULL,
    created_at TIMESTAMP(6) NOT NULL,
    
    INDEX idx_aggregate (aggregate_id),
    INDEX idx_version (aggregate_id, version DESC)
);
```

### Event Serialization

```php
class EventSerializer
{
    public function serialize(DomainEventInterface $event): array
    {
        return [
            'event_type' => get_class($event),
            'event_data' => $this->serializeEventData($event),
            'metadata' => $this->extractMetadata($event),
            'occurred_at' => $event->occurredAt()->format('Y-m-d H:i:s.u'),
        ];
    }

    public function deserialize(array $data): DomainEventInterface
    {
        $eventClass = $data['event_type'];
        $eventData = $data['event_data'];
        $metadata = $data['metadata'] ?? [];

        return $this->instantiateEvent($eventClass, $eventData, $metadata);
    }

    private function serializeEventData(DomainEventInterface $event): array
    {
        $reflection = new \ReflectionClass($event);
        $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);
        
        $data = [];
        foreach ($properties as $property) {
            $value = $property->getValue($event);
            $data[$property->getName()] = $this->serializeValue($value);
        }
        
        return $data;
    }
}
```

### Optimistic Concurrency Control

```php
class MySQLEventStore implements EventStoreInterface
{
    public function append(
        AggregateIdInterface $aggregateId,
        array $events,
        ?int $expectedVersion = null
    ): void {
        $this->connection->transaction(function () use ($aggregateId, $events, $expectedVersion) {
            // Check current version for concurrency control
            if ($expectedVersion !== null) {
                $currentVersion = $this->getAggregateVersion($aggregateId);
                if ($currentVersion !== $expectedVersion) {
                    throw new ConcurrencyException(
                        "Expected version {$expectedVersion}, but current version is {$currentVersion}"
                    );
                }
            }

            // Insert events
            foreach ($events as $index => $event) {
                $version = ($expectedVersion ?? 0) + $index + 1;
                $this->insertEvent($aggregateId, $event, $version);
            }
        });
    }
}
```

## Snapshot Strategies

Snapshots are periodic captures of aggregate state to avoid replaying all events:

### Simple Snapshot Strategy

**When to use**: Predictable event volumes, simple aggregates

```php
class SimpleSnapshotStrategy implements SnapshotStrategyInterface
{
    public function __construct(
        private int $threshold = 10
    ) {}

    public function shouldCreateSnapshot(
        AggregateIdInterface $aggregateId,
        int $currentVersion,
        int $eventsSinceSnapshot
    ): bool {
        return $eventsSinceSnapshot >= $this->threshold;
    }
}
```

**Configuration:**
```php
'snapshots' => [
    'strategy' => 'simple',
    'threshold' => 10, // Snapshot every 10 events
],
```

### Adaptive Snapshot Strategy

**When to use**: Variable event complexity, high-performance requirements

```php
class AdaptiveSnapshotStrategy implements SnapshotStrategyInterface
{
    public function shouldCreateSnapshot(
        AggregateIdInterface $aggregateId,
        int $currentVersion,
        int $eventsSinceSnapshot
    ): bool {
        $metrics = $this->getAggregateMetrics($aggregateId);
        
        $complexityScore = $this->calculateComplexityScore($metrics);
        $accessFrequency = $this->getAccessFrequency($aggregateId);
        $timeSinceSnapshot = $this->getTimeSinceLastSnapshot($aggregateId);
        
        $threshold = $this->calculateAdaptiveThreshold(
            $complexityScore,
            $accessFrequency,
            $timeSinceSnapshot
        );
        
        return $eventsSinceSnapshot >= $threshold;
    }

    private function calculateAdaptiveThreshold(
        float $complexityScore,
        float $accessFrequency,
        int $timeSinceSnapshot
    ): int {
        $baseThreshold = $this->config['event_count_threshold'];
        $timeWeight = $this->config['time_threshold_seconds'];
        
        // More complex aggregates get lower thresholds
        $complexityAdjustment = $complexityScore * $this->config['complexity_multiplier'];
        
        // Frequently accessed aggregates get lower thresholds
        $accessAdjustment = $accessFrequency * $this->config['access_frequency_weight'];
        
        // Time-based adjustment
        $timeAdjustment = ($timeSinceSnapshot / $timeWeight) * 0.5;
        
        $threshold = $baseThreshold - $complexityAdjustment - $accessAdjustment - $timeAdjustment;
        
        return max(
            $this->config['min_threshold'],
            min($this->config['max_threshold'], (int) $threshold)
        );
    }
}
```

**Configuration:**
```php
'snapshots' => [
    'strategy' => 'adaptive',
    'adaptive_config' => [
        'event_count_threshold' => 50,
        'time_threshold_seconds' => 3600,
        'complexity_multiplier' => 1.0,
        'access_frequency_weight' => 0.3,
        'size_weight' => 0.2,
        'performance_weight' => 0.5,
        'min_threshold' => 10,
        'max_threshold' => 1000,
    ],
],
```

### Time-Based Snapshot Strategy

**When to use**: Time-sensitive applications, scheduled processing

```php
class TimeBasedSnapshotStrategy implements SnapshotStrategyInterface
{
    public function shouldCreateSnapshot(
        AggregateIdInterface $aggregateId,
        int $currentVersion,
        int $eventsSinceSnapshot
    ): bool {
        $lastSnapshot = $this->getLastSnapshotTime($aggregateId);
        $timeSinceSnapshot = time() - $lastSnapshot;
        
        return $timeSinceSnapshot >= $this->config['time_interval'];
    }
}
```

### Snapshot Compression

```php
class SnapshotCompression
{
    public function compress(array $data): string
    {
        $json = json_encode($data);
        return gzcompress($json, 6);
    }

    public function decompress(string $compressed): array
    {
        $json = gzuncompress($compressed);
        return json_decode($json, true);
    }
}
```

## Event Ordering and Sequencing

### Global Event Sequencing

```php
class EventSequencer
{
    public function __construct(
        private bool $strictOrdering = true,
        private int $maxReorderWindow = 100
    ) {}

    public function getNextSequenceNumber(): int
    {
        return $this->connection->transaction(function () {
            // Use database sequence or atomic increment
            return $this->connection->table('event_sequences')
                ->lockForUpdate()
                ->increment('current_sequence');
        });
    }

    public function validateSequence(int $sequence): bool
    {
        if (!$this->strictOrdering) {
            return true;
        }

        $lastSequence = $this->getLastProcessedSequence();
        $gap = $sequence - $lastSequence;

        if ($gap > $this->maxReorderWindow) {
            throw new EventOrderingException(
                "Sequence gap too large: {$gap} > {$this->maxReorderWindow}"
            );
        }

        return true;
    }
}
```

### Per-Aggregate Ordering

```php
class AggregateEventOrdering
{
    public function ensureEventOrder(
        AggregateIdInterface $aggregateId,
        array $events
    ): array {
        $lastVersion = $this->getLastVersion($aggregateId);
        
        foreach ($events as $index => $event) {
            $expectedVersion = $lastVersion + $index + 1;
            $events[$index] = $this->setEventVersion($event, $expectedVersion);
        }
        
        return $events;
    }
}
```

## Projections and Read Models

### Real-time Projections

```php
class UserProjector extends AbstractProjector
{
    protected array $strategy = ['realtime'];

    public function whenUserCreated(UserCreated $event): void
    {
        DB::table('user_read_models')->insert([
            'id' => $event->userId->toString(),
            'email' => $event->email->toString(),
            'name' => $event->name,
            'status' => 'active',
            'created_at' => $event->occurredAt,
            'updated_at' => $event->occurredAt,
        ]);
    }

    public function whenUserEmailChanged(UserEmailChanged $event): void
    {
        DB::table('user_read_models')
            ->where('id', $event->userId->toString())
            ->update([
                'email' => $event->newEmail->toString(),
                'updated_at' => $event->occurredAt,
            ]);
    }
}
```

### Async Projections

```php
class AnalyticsProjector extends AbstractProjector
{
    protected array $strategy = ['async'];
    
    public function whenOrderCreated(OrderCreated $event): void
    {
        // This will be processed asynchronously
        $this->updateSalesMetrics($event);
        $this->updateCustomerInsights($event);
        $this->generateReports($event);
    }
    
    private function updateSalesMetrics(OrderCreated $event): void
    {
        // Heavy computation that doesn't block the main flow
        $metrics = $this->calculateComplexMetrics($event);
        $this->storeMetrics($metrics);
    }
}
```

### Batched Projections

```php
class ReportingProjector extends AbstractProjector
{
    protected array $strategy = ['batched'];
    protected int $batchSize = 100;
    
    public function projectBatch(array $events): void
    {
        $reportData = [];
        
        foreach ($events as $event) {
            $reportData[] = $this->extractReportData($event);
        }
        
        // Bulk insert for performance
        DB::table('reporting_data')->insert($reportData);
    }
}
```

## Performance Optimization

### Batch Event Loading

```php
class OptimizedEventLoader
{
    public function loadMultipleAggregates(array $aggregateIds): array
    {
        // Single query instead of N queries
        $events = DB::table('event_store')
            ->whereIn('aggregate_id', array_map('strval', $aggregateIds))
            ->orderBy('aggregate_id')
            ->orderBy('version')
            ->get();
        
        // Group by aggregate
        $grouped = $events->groupBy('aggregate_id');
        
        $streams = [];
        foreach ($aggregateIds as $aggregateId) {
            $aggregateEvents = $grouped->get($aggregateId->toString(), collect());
            $streams[$aggregateId->toString()] = new EventStream($aggregateEvents->all());
        }
        
        return $streams;
    }
}
```

### Event Store Partitioning

```php
class PartitionedEventStream
{
    public function __construct(
        private string $partitionKey,
        private int $partitionCount = 10
    ) {}
    
    public function getPartition(AggregateIdInterface $aggregateId): int
    {
        return crc32($aggregateId->toString()) % $this->partitionCount;
    }
    
    public function getTableName(AggregateIdInterface $aggregateId): string
    {
        $partition = $this->getPartition($aggregateId);
        return "event_store_partition_{$partition}";
    }
}
```

### Memory-Optimized Event Replay

```php
class MemoryOptimizedReplayer
{
    public function replayEvents(
        AggregateIdInterface $aggregateId,
        callable $handler,
        int $batchSize = 1000
    ): void {
        $version = 1;
        
        do {
            $events = $this->eventStore->load(
                $aggregateId,
                $version,
                $version + $batchSize - 1
            );
            
            foreach ($events as $event) {
                $handler($event);
            }
            
            $version += $batchSize;
            
            // Force garbage collection for long replays
            if ($version % 10000 === 0) {
                gc_collect_cycles();
            }
            
        } while (!$events->isEmpty());
    }
}
```

## Advanced Patterns

### Event Upcasting (Schema Evolution)

```php
class EventUpcaster
{
    private array $upcasters = [
        'UserCreated' => [
            1 => 'upcastUserCreatedV1ToV2',
            2 => 'upcastUserCreatedV2ToV3',
        ],
    ];
    
    public function upcast(array $eventData): array
    {
        $eventType = $eventData['event_type'];
        $version = $eventData['version'] ?? 1;
        $currentVersion = $this->getCurrentVersion($eventType);
        
        while ($version < $currentVersion) {
            $upcaster = $this->upcasters[$eventType][$version] ?? null;
            if ($upcaster) {
                $eventData = $this->$upcaster($eventData);
                $version++;
            } else {
                break;
            }
        }
        
        return $eventData;
    }
    
    private function upcastUserCreatedV1ToV2(array $eventData): array
    {
        // Add new field with default value
        $eventData['data']['timezone'] = 'UTC';
        $eventData['version'] = 2;
        return $eventData;
    }
}
```

### Event Archival

```php
class EventArchival
{
    public function archiveOldEvents(int $retentionDays = 365): void
    {
        $cutoffDate = now()->subDays($retentionDays);
        
        // Move old events to archive table
        DB::statement("
            INSERT INTO event_store_archive 
            SELECT * FROM event_store 
            WHERE occurred_at < ?
        ", [$cutoffDate]);
        
        // Remove from main table
        DB::table('event_store')
            ->where('occurred_at', '<', $cutoffDate)
            ->delete();
    }
}
```

### Event Encryption

```php
class EventEncryption
{
    public function encryptSensitiveData(array $eventData): array
    {
        $sensitiveFields = $this->getSensitiveFields($eventData['event_type']);
        
        foreach ($sensitiveFields as $field) {
            if (isset($eventData['data'][$field])) {
                $eventData['data'][$field] = $this->encrypt($eventData['data'][$field]);
            }
        }
        
        return $eventData;
    }
    
    private function getSensitiveFields(string $eventType): array
    {
        return [
            'UserCreated' => ['email', 'phone'],
            'PaymentProcessed' => ['card_number', 'cvv'],
        ][$eventType] ?? [];
    }
}
```

## Best Practices

### 1. Event Design

**Good Event Design:**
```php
// ✅ Good: Immutable, self-contained, past tense
class UserEmailChanged implements DomainEventInterface
{
    public function __construct(
        public readonly UserId $userId,
        public readonly Email $oldEmail,
        public readonly Email $newEmail,
        public readonly \DateTimeImmutable $occurredAt
    ) {}
}
```

**Poor Event Design:**
```php
// ❌ Bad: Mutable, incomplete information
class UserEvent
{
    public string $action; // Unclear what happened
    public array $data;    // Mutable, unclear structure
}
```

### 2. Aggregate Design

```php
// ✅ Good: Clear boundaries, event-driven
class Order extends AggregateRoot
{
    public function cancel(string $reason): void
    {
        if ($this->status === OrderStatus::CANCELLED) {
            return; // Idempotent
        }
        
        if ($this->status === OrderStatus::SHIPPED) {
            throw new OrderAlreadyShippedException();
        }
        
        $this->status = OrderStatus::CANCELLED;
        $this->recordEvent(new OrderCancelled(
            $this->id,
            $reason,
            new \DateTimeImmutable()
        ));
    }
}
```

### 3. Projection Strategies

```php
// ✅ Good: Separate concerns by strategy
class UserProjector extends AbstractProjector
{
    // Real-time for critical read models
    protected array $strategy = ['realtime'];
}

class AnalyticsProjector extends AbstractProjector
{
    // Async for heavy processing
    protected array $strategy = ['async'];
}

class ReportingProjector extends AbstractProjector
{
    // Batched for bulk operations
    protected array $strategy = ['batched'];
}
```

### 4. Error Handling

```php
class RobustEventHandler
{
    public function handle(DomainEventInterface $event): void
    {
        try {
            $this->processEvent($event);
        } catch (TemporaryException $e) {
            // Retry later
            throw $e;
        } catch (PermanentException $e) {
            // Log and skip
            Log::error('Permanent error processing event', [
                'event' => get_class($event),
                'error' => $e->getMessage(),
            ]);
            
            // Don't rethrow - event is poison
        }
    }
}
```

### 5. Testing

```php
class EventSourcingTest extends TestCase
{
    public function test_user_email_can_be_changed(): void
    {
        // Given
        $user = User::create(
            UserId::generate(),
            new Email('old@example.com'),
            'John Doe'
        );
        
        // When
        $user->changeEmail(new Email('new@example.com'));
        
        // Then
        $this->assertEventRecorded(UserEmailChanged::class);
        $this->assertEquals('new@example.com', $user->getEmail()->toString());
    }
}
```

## Configuration Examples

### Production Configuration

```php
'event_sourcing' => [
    'enabled' => true,
    
    'storage_tiers' => [
        'hot' => [
            'driver' => 'redis',
            'connection' => 'events',
            'ttl' => 172800, // 48 hours
            'enabled' => true,
        ],
        'warm' => [
            'driver' => 'mysql',
            'connection' => 'events_db',
            'table' => 'event_store',
        ],
    ],
    
    'snapshots' => [
        'enabled' => true,
        'strategy' => 'adaptive',
        'retention' => [
            'keep_count' => 5,
            'max_age_days' => 90,
        ],
    ],
    
    'performance' => [
        'batch_size' => 500,
        'connection_pool_size' => 20,
        'query_timeout' => 30,
    ],
],
```

### Development Configuration

```php
'event_sourcing' => [
    'enabled' => true,
    
    'storage_tiers' => [
        'hot' => [
            'enabled' => false, // Simpler for development
        ],
        'warm' => [
            'driver' => 'mysql',
            'table' => 'event_store',
        ],
    ],
    
    'snapshots' => [
        'enabled' => true,
        'strategy' => 'simple',
        'threshold' => 5, // Lower threshold for testing
    ],
],
```

This comprehensive guide covers the event sourcing implementation in the Laravel Modular DDD package. The next section will cover the CQRS implementation and how it integrates with event sourcing.
