<?php

declare(strict_types=1);

namespace LaravelModularDDD\Tests\Integration\EventSourcing;

use LaravelModularDDD\Tests\TestCase;
use LaravelModularDDD\EventSourcing\EventStore\TieredEventStore;
use LaravelModularDDD\EventSourcing\EventStore\RedisEventStore;
use LaravelModularDDD\EventSourcing\EventStore\MySQLEventStore;
use LaravelModularDDD\EventSourcing\Snapshot\SnapshotStore;
use LaravelModularDDD\EventSourcing\Snapshot\SimpleSnapshotStrategy;
use LaravelModularDDD\EventSourcing\EventStream;
use LaravelModularDDD\Tests\Integration\EventSourcing\Traits\EventStoreTestingTrait;
use LaravelModularDDD\Tests\Integration\EventSourcing\Factories\TestAggregateFactory;
use LaravelModularDDD\Core\Domain\Contracts\AggregateIdInterface;
use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Integration tests for Event Sourcing core functionality.
 *
 * Tests the complete event store flow including:
 * - Event appending and loading
 * - Snapshot creation and recovery
 * - Tiered storage (Redis hot + MySQL warm)
 * - Concurrency handling
 * - Event ordering and sequencing
 *
 * @group integration
 * @group event-sourcing
 */
class EventSourcingIntegrationTest extends TestCase
{
    use RefreshDatabase, EventStoreTestingTrait;

    private TieredEventStore $eventStore;
    private RedisEventStore $redisStore;
    private MySQLEventStore $mysqlStore;
    private SnapshotStore $snapshotStore;
    private TestAggregateFactory $aggregateFactory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEventSourcingInfrastructure();
        $this->aggregateFactory = new TestAggregateFactory();
    }

    protected function tearDown(): void
    {
        $this->cleanupEventSourcingData();
        parent::tearDown();
    }

    /**
     * @test
     * @group event-store-flow
     */
    public function test_it_handles_complete_event_store_flow_with_tiered_storage(): void
    {
        // Arrange
        $aggregateId = $this->createTestAggregateId();
        $events = $this->aggregateFactory->createEventSequence($aggregateId, 5);

        // Act: Append events to tiered store
        $this->eventStore->append($aggregateId, $events);

        // Assert: Events are immediately available from hot storage (Redis)
        $hotStream = $this->redisStore->tryLoad($aggregateId);
        $this->assertNotNull($hotStream);
        $this->assertCount(5, $hotStream->getEvents());
        $this->assertEquals(5, $this->redisStore->getAggregateVersion($aggregateId));

        // Process async warm storage queue
        Queue::fake();
        $this->eventStore->append($aggregateId, $events);
        Queue::assertPushed(\LaravelModularDDD\EventSourcing\EventStore\PersistEventsJob::class);

        // Manually persist to warm storage for testing
        $this->mysqlStore->append($aggregateId, $events);

        // Assert: Events are also available from warm storage (MySQL)
        $warmStream = $this->mysqlStore->load($aggregateId);
        $this->assertCount(5, $warmStream->getEvents());
        $this->assertEquals(5, $this->mysqlStore->getAggregateVersion($aggregateId));

        // Assert: Tiered store returns correct version
        $this->assertEquals(5, $this->eventStore->getAggregateVersion($aggregateId));
        $this->assertTrue($this->eventStore->aggregateExists($aggregateId));
    }

    /**
     * @test
     * @group event-ordering
     */
    public function test_it_maintains_event_ordering_and_sequencing(): void
    {
        // Arrange
        $aggregateId = $this->createTestAggregateId();
        $batchSize = 10;

        // Act: Append events in batches to test ordering
        for ($batch = 0; $batch < 3; $batch++) {
            $events = $this->aggregateFactory->createEventSequence(
                $aggregateId,
                $batchSize,
                $batch * $batchSize + 1 // Start version for this batch
            );

            $expectedVersion = $batch * $batchSize;
            $this->eventStore->append($aggregateId, $events, $expectedVersion);
        }

        // Assert: Load events and verify ordering
        $eventStream = $this->eventStore->load($aggregateId);
        $events = $eventStream->getEvents();

        $this->assertCount(30, $events); // 3 batches * 10 events each

        // Verify sequential version numbers
        for ($i = 0; $i < 30; $i++) {
            $this->assertEquals($i + 1, $events[$i]->getVersion());
        }

        // Verify aggregate version
        $this->assertEquals(30, $this->eventStore->getAggregateVersion($aggregateId));
    }

    /**
     * @test
     * @group snapshots
     */
    public function test_it_creates_and_recovers_from_snapshots(): void
    {
        // Arrange
        $aggregateId = $this->createTestAggregateId();
        $events = $this->aggregateFactory->createEventSequence($aggregateId, 15);

        // Act: Append events
        $this->eventStore->append($aggregateId, $events);

        // Create snapshot at version 10
        $snapshotData = $this->aggregateFactory->createSnapshotData($aggregateId, 10);
        $this->snapshotStore->store($aggregateId, 10, $snapshotData);

        // Assert: Snapshot is stored
        $this->assertTrue($this->snapshotStore->has($aggregateId));
        $snapshot = $this->snapshotStore->load($aggregateId);
        $this->assertEquals(10, $snapshot->getVersion());
        $this->assertEquals($snapshotData, $snapshot->getData());

        // Load events from snapshot version
        $eventsFromSnapshot = $this->eventStore->load($aggregateId, 11);
        $this->assertCount(5, $eventsFromSnapshot->getEvents()); // Events 11-15
    }

    /**
     * @test
     * @group concurrency
     */
    public function test_it_handles_concurrency_with_optimistic_locking(): void
    {
        // Arrange
        $aggregateId = $this->createTestAggregateId();
        $initialEvents = $this->aggregateFactory->createEventSequence($aggregateId, 3);
        $this->eventStore->append($aggregateId, $initialEvents);

        // Simulate two concurrent operations
        $events1 = $this->aggregateFactory->createEventSequence($aggregateId, 2, 4);
        $events2 = $this->aggregateFactory->createEventSequence($aggregateId, 2, 4);

        // Act: First append should succeed
        $this->eventStore->append($aggregateId, $events1, 3);

        // Second append with same expected version should fail
        $this->expectException(\LaravelModularDDD\EventSourcing\Exceptions\ConcurrencyException::class);
        $this->eventStore->append($aggregateId, $events2, 3);
    }

    /**
     * @test
     * @group tiered-storage-failover
     */
    public function test_it_handles_hot_storage_failure_gracefully(): void
    {
        // Arrange
        $aggregateId = $this->createTestAggregateId();
        $events = $this->aggregateFactory->createEventSequence($aggregateId, 5);

        // Store events in warm storage only
        $this->mysqlStore->append($aggregateId, $events);

        // Simulate Redis being unavailable by flushing it
        Redis::flushall();

        // Act: Load should fall back to warm storage
        $eventStream = $this->eventStore->load($aggregateId);

        // Assert: Events are loaded from warm storage
        $this->assertCount(5, $eventStream->getEvents());
        $this->assertEquals(5, $this->eventStore->getAggregateVersion($aggregateId));

        // Events should be promoted to hot storage after load
        $hotStream = $this->redisStore->tryLoad($aggregateId);
        $this->assertNotNull($hotStream);
        $this->assertCount(5, $hotStream->getEvents());
    }

    /**
     * @test
     * @group storage-statistics
     */
    public function test_it_provides_accurate_storage_statistics(): void
    {
        // Arrange
        $aggregateIds = [
            $this->createTestAggregateId(),
            $this->createTestAggregateId(),
            $this->createTestAggregateId(),
        ];

        // Act: Store events for multiple aggregates
        foreach ($aggregateIds as $index => $aggregateId) {
            $events = $this->aggregateFactory->createEventSequence($aggregateId, $index + 3);
            $this->eventStore->append($aggregateId, $events);
        }

        // Assert: Statistics are accurate
        $stats = $this->eventStore->getStorageStats();

        $this->assertArrayHasKey('hot_storage', $stats);
        $this->assertArrayHasKey('warm_storage', $stats);

        $this->assertEquals('redis', $stats['hot_storage']['type']);
        $this->assertEquals('mysql', $stats['warm_storage']['type']);
        $this->assertTrue($stats['warm_storage']['async_writes']);

        // Verify cache stats are present
        $this->assertArrayHasKey('stats', $stats['hot_storage']);
    }

    /**
     * @test
     * @group event-types
     */
    public function test_it_loads_events_by_type_correctly(): void
    {
        // Arrange
        $aggregateId1 = $this->createTestAggregateId();
        $aggregateId2 = $this->createTestAggregateId();

        $userCreatedEvents = $this->aggregateFactory->createEventsOfType(
            [$aggregateId1, $aggregateId2],
            'UserCreated',
            2
        );

        $userUpdatedEvents = $this->aggregateFactory->createEventsOfType(
            [$aggregateId1],
            'UserUpdated',
            1
        );

        // Store all events
        $this->eventStore->append($aggregateId1, array_merge($userCreatedEvents, $userUpdatedEvents));
        $this->eventStore->append($aggregateId2, [$userCreatedEvents[1]]);

        // Persist to warm storage for type queries
        $this->eventStore->forceWarmPersistence($aggregateId1);
        $this->eventStore->forceWarmPersistence($aggregateId2);

        // Act & Assert: Load events by type
        $createdEvents = $this->eventStore->loadEventsByType('UserCreated', 10);
        $this->assertCount(2, $createdEvents);

        $updatedEvents = $this->eventStore->loadEventsByType('UserUpdated', 10);
        $this->assertCount(1, $updatedEvents);
    }

    /**
     * @test
     * @group event-replay
     */
    public function test_it_supports_event_replay_from_sequence(): void
    {
        // Arrange
        $aggregateIds = [];
        $totalEvents = 0;

        // Create multiple aggregates with events
        for ($i = 0; $i < 3; $i++) {
            $aggregateId = $this->createTestAggregateId();
            $aggregateIds[] = $aggregateId;

            $eventCount = $i + 3;
            $events = $this->aggregateFactory->createEventSequence($aggregateId, $eventCount);
            $this->eventStore->append($aggregateId, $events);
            $this->eventStore->forceWarmPersistence($aggregateId);

            $totalEvents += $eventCount;
        }

        // Act: Load events from sequence
        $replayEvents = $this->eventStore->loadEventsFromSequence(1, 100);

        // Assert: All events are loaded in sequence order
        $this->assertCount($totalEvents, $replayEvents);

        // Verify events are in chronological order
        $previousOccurredAt = null;
        foreach ($replayEvents as $event) {
            if ($previousOccurredAt !== null) {
                $this->assertGreaterThanOrEqual(
                    $previousOccurredAt,
                    $event->getOccurredAt()
                );
            }
            $previousOccurredAt = $event->getOccurredAt();
        }
    }

    /**
     * @test
     * @group warmup-eviction
     */
    public function test_it_supports_hot_storage_warmup_and_eviction(): void
    {
        // Arrange
        $aggregateIds = [
            $this->createTestAggregateId(),
            $this->createTestAggregateId(),
        ];

        // Store events in warm storage only
        foreach ($aggregateIds as $aggregateId) {
            $events = $this->aggregateFactory->createEventSequence($aggregateId, 3);
            $this->mysqlStore->append($aggregateId, $events);
        }

        // Act: Warm up hot storage
        $this->eventStore->warmUp($aggregateIds);

        // Assert: Events are now in hot storage
        foreach ($aggregateIds as $aggregateId) {
            $hotStream = $this->redisStore->tryLoad($aggregateId);
            $this->assertNotNull($hotStream);
            $this->assertCount(3, $hotStream->getEvents());
        }

        // Act: Evict from hot storage
        $this->eventStore->evictFromHotStorage($aggregateIds[0]);

        // Assert: First aggregate evicted, second still cached
        $this->assertNull($this->redisStore->tryLoad($aggregateIds[0]));
        $this->assertNotNull($this->redisStore->tryLoad($aggregateIds[1]));
    }

    /**
     * @test
     * @group stress-test
     */
    public function test_it_handles_high_volume_event_processing(): void
    {
        // Arrange
        $aggregateId = $this->createTestAggregateId();
        $batchSize = 100;
        $batchCount = 5;

        $startTime = microtime(true);

        // Act: Process multiple large batches
        for ($batch = 0; $batch < $batchCount; $batch++) {
            $events = $this->aggregateFactory->createEventSequence(
                $aggregateId,
                $batchSize,
                $batch * $batchSize + 1
            );

            $this->eventStore->append($aggregateId, $events, $batch * $batchSize);
        }

        $processingTime = microtime(true) - $startTime;

        // Assert: All events processed correctly
        $finalVersion = $this->eventStore->getAggregateVersion($aggregateId);
        $this->assertEquals($batchSize * $batchCount, $finalVersion);

        // Assert: Performance is acceptable (under 5 seconds for 500 events)
        $this->assertLessThan(5.0, $processingTime);

        // Verify event integrity
        $allEvents = $this->eventStore->load($aggregateId);
        $this->assertCount($batchSize * $batchCount, $allEvents->getEvents());
    }

    /**
     * @test
     * @group snapshot-strategy
     */
    public function test_it_applies_snapshot_strategy_automatically(): void
    {
        // Arrange: Configure simple snapshot strategy with threshold of 10
        $this->app['config']->set('modular-ddd.event_sourcing.snapshots.threshold', 10);

        $aggregateId = $this->createTestAggregateId();

        // Act: Add events that should trigger snapshot
        $events = $this->aggregateFactory->createEventSequence($aggregateId, 15);
        $this->eventStore->append($aggregateId, $events);

        // Simulate snapshot strategy application
        $strategy = new SimpleSnapshotStrategy($this->snapshotStore, 10);
        $eventStream = $this->eventStore->load($aggregateId);

        if ($strategy->shouldCreateSnapshot($eventStream)) {
            $snapshotData = $this->aggregateFactory->createSnapshotData($aggregateId, 10);
            $strategy->createSnapshot($aggregateId, 10, $snapshotData);
        }

        // Assert: Snapshot was created
        $this->assertTrue($this->snapshotStore->has($aggregateId));
        $snapshot = $this->snapshotStore->load($aggregateId);
        $this->assertEquals(10, $snapshot->getVersion());
    }

    private function setUpEventSourcingInfrastructure(): void
    {
        // Set up Redis connection for testing
        $redisConfig = [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'database' => 15, // Use separate database for testing
        ];

        $this->app['config']->set('database.redis.event_sourcing', $redisConfig);

        // Initialize stores
        $this->redisStore = new RedisEventStore(
            Redis::connection('event_sourcing'),
            new \LaravelModularDDD\EventSourcing\EventStore\EventSerializer()
        );

        $this->mysqlStore = new MySQLEventStore(
            DB::connection(),
            new \LaravelModularDDD\EventSourcing\EventStore\EventSerializer()
        );

        $this->eventStore = new TieredEventStore(
            $this->redisStore,
            $this->mysqlStore,
            Queue::getFacadeRoot(),
            false // Disable async for testing
        );

        $this->snapshotStore = new SnapshotStore(
            DB::connection(),
            new \LaravelModularDDD\EventSourcing\Snapshot\SnapshotCompression()
        );
    }

    private function cleanupEventSourcingData(): void
    {
        // Clear Redis test database
        Redis::connection('event_sourcing')->flushdb();

        // Clear database tables
        DB::table('event_store')->truncate();
        DB::table('snapshots')->truncate();

        // Clear Laravel caches
        Cache::flush();
    }

    private function createTestAggregateId(): AggregateIdInterface
    {
        return new class(Uuid::uuid4()->toString()) implements AggregateIdInterface {
            public function __construct(private string $id) {}
            public function toString(): string { return $this->id; }
            public function equals(AggregateIdInterface $other): bool {
                return $this->id === $other->toString();
            }
            public static function generate(): static {
                return new static(Uuid::uuid4()->toString());
            }
            public static function fromString(string $id): static {
                return new static($id);
            }
        };
    }
}