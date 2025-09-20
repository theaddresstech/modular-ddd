<?php

declare(strict_types=1);

namespace LaravelModularDDD\Tests\Unit\EventSourcing;

use LaravelModularDDD\Tests\TestCase;
use LaravelModularDDD\EventSourcing\Snapshot\SnapshotStore;
use LaravelModularDDD\EventSourcing\Snapshot\SimpleSnapshotStrategy;
use LaravelModularDDD\EventSourcing\Snapshot\AdaptiveSnapshotStrategy;
use LaravelModularDDD\EventSourcing\Snapshot\TimeBasedSnapshotStrategy;
use LaravelModularDDD\EventSourcing\Snapshot\SnapshotStrategyFactory;
use LaravelModularDDD\EventSourcing\Snapshot\AggregateSnapshot;
use LaravelModularDDD\EventSourcing\Contracts\SnapshotStoreInterface;
use LaravelModularDDD\Core\Domain\Contracts\AggregateIdInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * Test suite for the Snapshot system.
 *
 * This validates that snapshots are created, stored, and retrieved correctly,
 * which is critical for performance in event sourcing systems.
 */
class SnapshotSystemTest extends TestCase
{
    private SnapshotStoreInterface $snapshotStore;
    private ConnectionInterface $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->app['db']->connection();
    }

    /** @test */
    public function it_can_create_and_retrieve_snapshots_with_simple_strategy(): void
    {
        // Arrange
        $strategy = new SimpleSnapshotStrategy(5); // Snapshot every 5 events
        $this->snapshotStore = new SnapshotStore($this->connection, $strategy);
        $aggregateId = new TestSnapshotAggregateId($this->createTestAggregateId());

        $aggregateData = [
            'id' => $aggregateId->toString(),
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'version' => 10,
        ];

        $snapshot = new AggregateSnapshot(
            $aggregateId->toString(),
            'TestAggregate',
            10,
            $aggregateData,
            ['created_by' => 'test']
        );

        // Act
        $this->snapshotStore->store($snapshot);
        $retrieved = $this->snapshotStore->load($aggregateId->toString(), 'TestAggregate');

        // Assert
        $this->assertNotNull($retrieved);
        $this->assertEquals($aggregateId->toString(), $retrieved->getAggregateId());
        $this->assertEquals('TestAggregate', $retrieved->getAggregateType());
        $this->assertEquals(10, $retrieved->getVersion());
        $this->assertEquals($aggregateData, $retrieved->getSnapshotData());
        $this->assertEquals(['created_by' => 'test'], $retrieved->getMetadata());
    }

    /** @test */
    public function simple_strategy_determines_snapshot_need_correctly(): void
    {
        // Arrange
        $strategy = new SimpleSnapshotStrategy(10); // Every 10 events

        // Act & Assert
        $this->assertFalse($strategy->shouldCreateSnapshot(5, 0, []));
        $this->assertTrue($strategy->shouldCreateSnapshot(10, 0, []));
        $this->assertFalse($strategy->shouldCreateSnapshot(15, 10, [])); // Last snapshot at version 10
        $this->assertTrue($strategy->shouldCreateSnapshot(20, 10, [])); // Need snapshot at version 20
    }

    /** @test */
    public function adaptive_strategy_adjusts_based_on_metrics(): void
    {
        // Arrange
        $config = [
            'event_count_threshold' => 50,
            'time_threshold_seconds' => 3600,
            'complexity_multiplier' => 1.0,
            'access_frequency_weight' => 0.3,
            'size_weight' => 0.2,
            'performance_weight' => 0.5,
            'min_threshold' => 10,
            'max_threshold' => 1000,
        ];
        $strategy = new AdaptiveSnapshotStrategy($config);

        // Act & Assert - High access frequency should trigger earlier snapshots
        $metrics = [
            'access_frequency' => 0.9, // High frequency
            'size_complexity' => 0.5,
            'performance_score' => 0.8,
        ];
        $this->assertTrue($strategy->shouldCreateSnapshot(30, 0, $metrics));

        // Low access frequency should wait longer
        $metrics['access_frequency'] = 0.1;
        $this->assertFalse($strategy->shouldCreateSnapshot(30, 0, $metrics));
    }

    /** @test */
    public function time_based_strategy_creates_snapshots_by_time(): void
    {
        // Arrange
        $strategy = new TimeBasedSnapshotStrategy(3600); // 1 hour interval
        $oneHourAgo = time() - 3600;

        // Act & Assert
        $this->assertFalse($strategy->shouldCreateSnapshot(5, 0, ['last_snapshot_time' => time()]));
        $this->assertTrue($strategy->shouldCreateSnapshot(5, 0, ['last_snapshot_time' => $oneHourAgo]));
        $this->assertTrue($strategy->shouldCreateSnapshot(5, 0, [])); // No previous snapshot
    }

    /** @test */
    public function snapshot_strategy_factory_creates_correct_strategies(): void
    {
        // Test Simple Strategy
        $simpleConfig = ['strategy' => 'simple', 'threshold' => 15];
        $simpleStrategy = SnapshotStrategyFactory::create($simpleConfig);
        $this->assertInstanceOf(SimpleSnapshotStrategy::class, $simpleStrategy);

        // Test Adaptive Strategy
        $adaptiveConfig = [
            'strategy' => 'adaptive',
            'adaptive_config' => [
                'event_count_threshold' => 50,
                'time_threshold_seconds' => 3600,
            ]
        ];
        $adaptiveStrategy = SnapshotStrategyFactory::create($adaptiveConfig);
        $this->assertInstanceOf(AdaptiveSnapshotStrategy::class, $adaptiveStrategy);

        // Test Time-based Strategy
        $timeConfig = ['strategy' => 'time_based', 'time_interval' => 7200];
        $timeStrategy = SnapshotStrategyFactory::create($timeConfig);
        $this->assertInstanceOf(TimeBasedSnapshotStrategy::class, $timeStrategy);
    }

    /** @test */
    public function snapshot_store_loads_latest_snapshot_by_version(): void
    {
        // Arrange
        $strategy = new SimpleSnapshotStrategy(5);
        $this->snapshotStore = new SnapshotStore($this->connection, $strategy);
        $aggregateId = new TestSnapshotAggregateId($this->createTestAggregateId());

        // Create multiple snapshots
        $snapshot1 = new AggregateSnapshot(
            $aggregateId->toString(),
            'TestAggregate',
            5,
            ['version' => 5, 'data' => 'first'],
            []
        );

        $snapshot2 = new AggregateSnapshot(
            $aggregateId->toString(),
            'TestAggregate',
            10,
            ['version' => 10, 'data' => 'second'],
            []
        );

        $snapshot3 = new AggregateSnapshot(
            $aggregateId->toString(),
            'TestAggregate',
            15,
            ['version' => 15, 'data' => 'third'],
            []
        );

        // Act
        $this->snapshotStore->store($snapshot1);
        $this->snapshotStore->store($snapshot2);
        $this->snapshotStore->store($snapshot3);

        $latest = $this->snapshotStore->load($aggregateId->toString(), 'TestAggregate');

        // Assert - Should get the latest snapshot (version 15)
        $this->assertNotNull($latest);
        $this->assertEquals(15, $latest->getVersion());
        $this->assertEquals(['version' => 15, 'data' => 'third'], $latest->getSnapshotData());
    }

    /** @test */
    public function snapshot_store_loads_snapshot_up_to_version(): void
    {
        // Arrange
        $strategy = new SimpleSnapshotStrategy(5);
        $this->snapshotStore = new SnapshotStore($this->connection, $strategy);
        $aggregateId = new TestSnapshotAggregateId($this->createTestAggregateId());

        // Create snapshots at versions 5, 10, 15
        $this->snapshotStore->store(new AggregateSnapshot(
            $aggregateId->toString(), 'TestAggregate', 5, ['version' => 5], []
        ));
        $this->snapshotStore->store(new AggregateSnapshot(
            $aggregateId->toString(), 'TestAggregate', 10, ['version' => 10], []
        ));
        $this->snapshotStore->store(new AggregateSnapshot(
            $aggregateId->toString(), 'TestAggregate', 15, ['version' => 15], []
        ));

        // Act - Load snapshot up to version 12
        $snapshot = $this->snapshotStore->load($aggregateId->toString(), 'TestAggregate', 12);

        // Assert - Should get snapshot at version 10 (latest before version 12)
        $this->assertNotNull($snapshot);
        $this->assertEquals(10, $snapshot->getVersion());
        $this->assertEquals(['version' => 10], $snapshot->getSnapshotData());
    }

    /** @test */
    public function snapshot_store_returns_null_for_non_existent_aggregate(): void
    {
        // Arrange
        $strategy = new SimpleSnapshotStrategy(5);
        $this->snapshotStore = new SnapshotStore($this->connection, $strategy);
        $nonExistentId = $this->createTestAggregateId();

        // Act
        $snapshot = $this->snapshotStore->load($nonExistentId, 'TestAggregate');

        // Assert
        $this->assertNull($snapshot);
    }

    /** @test */
    public function snapshot_store_handles_cleanup_of_old_snapshots(): void
    {
        // Arrange
        $strategy = new SimpleSnapshotStrategy(5);
        $this->snapshotStore = new SnapshotStore($this->connection, $strategy);
        $aggregateId = new TestSnapshotAggregateId($this->createTestAggregateId());

        // Create multiple snapshots
        for ($i = 1; $i <= 10; $i++) {
            $this->snapshotStore->store(new AggregateSnapshot(
                $aggregateId->toString(),
                'TestAggregate',
                $i * 5,
                ['version' => $i * 5],
                []
            ));
        }

        // Act - Cleanup keeping only last 3 snapshots
        $this->snapshotStore->cleanup($aggregateId->toString(), 'TestAggregate', 3);

        // Assert - Should only have snapshots for versions 40, 45, 50
        $remaining = $this->connection
            ->table('snapshots')
            ->where('aggregate_id', $aggregateId->toString())
            ->orderBy('version')
            ->get();

        $this->assertCount(3, $remaining);
        $this->assertEquals(40, $remaining[0]->version);
        $this->assertEquals(45, $remaining[1]->version);
        $this->assertEquals(50, $remaining[2]->version);
    }

    /** @test */
    public function it_performs_snapshot_operations_efficiently(): void
    {
        // Arrange
        $strategy = new SimpleSnapshotStrategy(10);
        $this->snapshotStore = new SnapshotStore($this->connection, $strategy);
        $aggregateId = new TestSnapshotAggregateId($this->createTestAggregateId());

        // Large snapshot data
        $largeData = [];
        for ($i = 0; $i < 1000; $i++) {
            $largeData["item_{$i}"] = str_repeat('x', 100); // 100 characters each
        }

        $snapshot = new AggregateSnapshot(
            $aggregateId->toString(),
            'TestAggregate',
            100,
            $largeData,
            ['size' => 'large']
        );

        // Act & Assert - Store should be fast
        $this->assertExecutionTimeWithinLimits(function () use ($snapshot) {
            $this->snapshotStore->store($snapshot);
        }, 1000); // 1 second max

        // Load should also be fast
        $this->assertExecutionTimeWithinLimits(function () use ($aggregateId) {
            $this->snapshotStore->load($aggregateId->toString(), 'TestAggregate');
        }, 500); // 500ms max
    }

    /** @test */
    public function snapshot_factory_validates_configuration(): void
    {
        // Valid configurations should work
        $validConfigs = [
            ['strategy' => 'simple', 'threshold' => 10],
            ['strategy' => 'adaptive', 'adaptive_config' => ['event_count_threshold' => 50]],
            ['strategy' => 'time_based', 'time_interval' => 3600],
        ];

        foreach ($validConfigs as $config) {
            $errors = SnapshotStrategyFactory::validateConfig($config);
            $this->assertEmpty($errors, "Config should be valid: " . json_encode($config));
        }

        // Invalid configurations should return errors
        $invalidConfigs = [
            [], // Missing strategy
            ['strategy' => 'invalid'], // Invalid strategy
            ['strategy' => 'simple'], // Missing threshold
            ['strategy' => 'simple', 'threshold' => 0], // Invalid threshold
            ['strategy' => 'time_based', 'time_interval' => -1], // Invalid interval
        ];

        foreach ($invalidConfigs as $config) {
            $errors = SnapshotStrategyFactory::validateConfig($config);
            $this->assertNotEmpty($errors, "Config should be invalid: " . json_encode($config));
        }
    }

    /** @test */
    public function it_handles_concurrent_snapshot_storage(): void
    {
        // Arrange
        $strategy = new SimpleSnapshotStrategy(5);
        $this->snapshotStore = new SnapshotStore($this->connection, $strategy);
        $aggregateId = new TestSnapshotAggregateId($this->createTestAggregateId());

        // Two snapshots for the same aggregate and version
        $snapshot1 = new AggregateSnapshot(
            $aggregateId->toString(),
            'TestAggregate',
            10,
            ['data' => 'first'],
            []
        );

        $snapshot2 = new AggregateSnapshot(
            $aggregateId->toString(),
            'TestAggregate',
            10,
            ['data' => 'second'],
            []
        );

        // Act - Store both snapshots
        $this->snapshotStore->store($snapshot1);
        $this->snapshotStore->store($snapshot2); // Should replace the first one

        // Assert - Only the latest should be available
        $retrieved = $this->snapshotStore->load($aggregateId->toString(), 'TestAggregate');
        $this->assertEquals(['data' => 'second'], $retrieved->getSnapshotData());
    }

    /** @test */
    public function it_preserves_snapshot_metadata_correctly(): void
    {
        // Arrange
        $strategy = new SimpleSnapshotStrategy(5);
        $this->snapshotStore = new SnapshotStore($this->connection, $strategy);
        $aggregateId = new TestSnapshotAggregateId($this->createTestAggregateId());

        $metadata = [
            'created_by' => 'user-123',
            'creation_context' => 'test-suite',
            'compression' => 'gzip',
            'checksum' => 'abc123',
        ];

        $snapshot = new AggregateSnapshot(
            $aggregateId->toString(),
            'TestAggregate',
            15,
            ['some' => 'data'],
            $metadata
        );

        // Act
        $this->snapshotStore->store($snapshot);
        $retrieved = $this->snapshotStore->load($aggregateId->toString(), 'TestAggregate');

        // Assert
        $this->assertEquals($metadata, $retrieved->getMetadata());
    }
}

/**
 * Test aggregate ID for snapshot testing.
 */
class TestSnapshotAggregateId implements AggregateIdInterface
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