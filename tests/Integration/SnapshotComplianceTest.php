<?php

declare(strict_types=1);

namespace Tests\Integration;

use LaravelModularDDD\Tests\TestCase;
use LaravelModularDDD\Core\Infrastructure\Repository\EventSourcedAggregateRepository;
use LaravelModularDDD\EventSourcing\Snapshot\SimpleSnapshotStrategy;
use LaravelModularDDD\EventSourcing\Snapshot\SnapshotStore;
use LaravelModularDDD\EventSourcing\EventStore\MySQLEventStore;
use LaravelModularDDD\EventSourcing\EventStore\EventSerializer;
use LaravelModularDDD\Core\Domain\AggregateRoot;
use LaravelModularDDD\Core\Domain\DomainEvent;
use LaravelModularDDD\Core\Shared\AggregateId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use DateTimeImmutable;

/**
 * PRD Compliance Test for Snapshot Strategy
 *
 * PRD Requirement: "System must automatically create snapshots every 10 events"
 */
class SnapshotComplianceTest extends TestCase
{
    use RefreshDatabase;

    private EventSourcedAggregateRepository $repository;
    private SimpleSnapshotStrategy $strategy;
    private SnapshotStore $snapshotStore;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure database tables exist
        $this->artisan('migrate');

        // Create PRD-compliant strategy (exactly 10 events)
        $this->strategy = new SimpleSnapshotStrategy(10);

        // Create snapshot store
        $this->snapshotStore = new SnapshotStore(
            DB::connection(),
            $this->strategy
        );

        // Create event store
        $eventStore = new MySQLEventStore(
            DB::connection(),
            new EventSerializer()
        );

        // Create repository with PRD-compliant configuration
        $this->repository = new EventSourcedAggregateRepository(
            $eventStore,
            $this->snapshotStore,
            $this->strategy
        );
    }

    /**
     * @test
     * PRD REQUIREMENT TEST: System must automatically create snapshots every 10 events
     */
    public function it_automatically_creates_snapshot_exactly_every_10_events(): void
    {
        // Create test aggregate
        $aggregateId = new TestAggregateId('test-aggregate-prd-compliance');
        $aggregate = TestAggregate::create($aggregateId);

        // Add exactly 9 events - NO snapshot should be created yet
        for ($i = 1; $i <= 9; $i++) {
            $aggregate->doSomething("action_{$i}");
        }

        $this->repository->save($aggregate);

        // Verify NO snapshot exists yet (before 10 events)
        $snapshot = $this->snapshotStore->load($aggregateId);
        $this->assertNull($snapshot, 'Snapshot should NOT exist before 10 events (PRD compliance check)');

        // Add the 10th event - snapshot MUST be created automatically
        $aggregate->doSomething("action_10");
        $this->repository->save($aggregate);

        // CRITICAL PRD COMPLIANCE CHECK: Snapshot MUST exist after exactly 10 events
        $snapshot = $this->snapshotStore->load($aggregateId);
        $this->assertNotNull($snapshot, 'PRD VIOLATION: Snapshot MUST be created after exactly 10 events');
        $this->assertEquals(10, $snapshot->getVersion(), 'Snapshot must be at version 10 per PRD requirement');

        // Add 9 more events - NO new snapshot should be created yet
        for ($i = 11; $i <= 19; $i++) {
            $aggregate->doSomething("action_{$i}");
        }
        $this->repository->save($aggregate);

        // Snapshot should still be at version 10
        $snapshot = $this->snapshotStore->load($aggregateId);
        $this->assertEquals(10, $snapshot->getVersion(), 'Snapshot should remain at version 10');

        // Add the 20th event - second snapshot MUST be created
        $aggregate->doSomething("action_20");
        $this->repository->save($aggregate);

        // CRITICAL: Second snapshot MUST exist at version 20
        $snapshot = $this->snapshotStore->load($aggregateId);
        $this->assertEquals(20, $snapshot->getVersion(), 'PRD VIOLATION: Second snapshot MUST be at version 20');
    }

    /**
     * @test
     * Verify repository can detect PRD compliance
     */
    public function it_correctly_identifies_prd_compliance(): void
    {
        $this->assertTrue($this->repository->isPrdCompliant(), 'Repository must be PRD compliant');

        $report = $this->repository->getPrdComplianceReport();
        $this->assertEquals('COMPLIANT', $report['compliance_status']);
        $this->assertEquals('simple', $report['current_strategy']);
        $this->assertEquals(10, $report['current_threshold']);
    }

    /**
     * @test
     * Verify non-compliant configuration is detected
     */
    public function it_detects_non_prd_compliant_configuration(): void
    {
        // Create non-compliant strategy (15 events instead of required 10)
        $nonCompliantStrategy = new SimpleSnapshotStrategy(15);

        $nonCompliantRepository = new EventSourcedAggregateRepository(
            app(\LaravelModularDDD\EventSourcing\Contracts\EventStoreInterface::class),
            $this->snapshotStore,
            $nonCompliantStrategy
        );

        $this->assertFalse($nonCompliantRepository->isPrdCompliant(), 'Must detect non-compliant configuration');

        $report = $nonCompliantRepository->getPrdComplianceReport();
        $this->assertEquals('NON-COMPLIANT', $report['compliance_status']);
        $this->assertEquals(15, $report['current_threshold']);
        $this->assertStringContains('Switch to SimpleSnapshotStrategy with threshold=10', $report['recommendation']);
    }

    /**
     * @test
     * Verify snapshot-based loading works correctly with PRD snapshots
     */
    public function it_loads_aggregates_correctly_using_prd_snapshots(): void
    {
        // Create and save aggregate with exactly 10 events (triggering PRD snapshot)
        $aggregateId = new TestAggregateId('test-load-with-prd-snapshot');
        $aggregate = TestAggregate::create($aggregateId);

        for ($i = 1; $i <= 10; $i++) {
            $aggregate->doSomething("setup_action_{$i}");
        }
        $this->repository->save($aggregate);

        // Verify snapshot was created per PRD
        $snapshot = $this->snapshotStore->load($aggregateId);
        $this->assertNotNull($snapshot);
        $this->assertEquals(10, $snapshot->getVersion());

        // Add 5 more events after snapshot
        for ($i = 11; $i <= 15; $i++) {
            $aggregate->doSomething("post_snapshot_action_{$i}");
        }
        $this->repository->save($aggregate);

        // Load aggregate fresh from repository
        $loadedAggregate = $this->repository->load($aggregateId, TestAggregate::class);

        // Verify loaded aggregate has correct final state
        $this->assertEquals(15, $loadedAggregate->getVersion());
        $this->assertEquals($aggregateId->toString(), $loadedAggregate->getAggregateId()->toString());

        // Verify the aggregate contains both snapshot data and post-snapshot events
        $actions = $loadedAggregate->getActionHistory();
        $this->assertCount(15, $actions);
        $this->assertEquals('setup_action_10', $actions[9]);    // From snapshot
        $this->assertEquals('post_snapshot_action_15', $actions[14]); // Post-snapshot
    }

    /**
     * @test
     * Performance test: verify PRD snapshots improve loading performance
     */
    public function it_demonstrates_prd_snapshot_performance_benefit(): void
    {
        $aggregateId = new TestAggregateId('performance-test-aggregate');
        $aggregate = TestAggregate::create($aggregateId);

        // Create 100 events (will trigger 10 PRD-compliant snapshots)
        for ($i = 1; $i <= 100; $i++) {
            $aggregate->doSomething("performance_action_{$i}");
        }
        $this->repository->save($aggregate);

        // Verify snapshots were created at version 10, 20, 30, ... 100
        $snapshots = [];
        for ($version = 10; $version <= 100; $version += 10) {
            $snapshot = $this->snapshotStore->loadVersion($aggregateId, $version);
            if ($snapshot) {
                $snapshots[] = $snapshot->getVersion();
            }
        }

        $this->assertContains(10, $snapshots, 'PRD snapshot missing at version 10');
        $this->assertContains(20, $snapshots, 'PRD snapshot missing at version 20');
        $this->assertContains(100, $snapshots, 'PRD snapshot missing at version 100');

        // Loading should use the most recent snapshot (version 100)
        $startTime = microtime(true);
        $loadedAggregate = $this->repository->load($aggregateId, TestAggregate::class);
        $loadTime = microtime(true) - $startTime;

        $this->assertEquals(100, $loadedAggregate->getVersion());

        // With PRD snapshots, loading should be fast even with 100 events
        $this->assertLessThan(0.1, $loadTime, 'PRD snapshots should enable fast loading');
    }
}

/**
 * Test aggregate for PRD compliance testing
 */
class TestAggregate extends AggregateRoot
{
    private array $actionHistory = [];

    public static function create(TestAggregateId $id): self
    {
        $aggregate = new self($id);
        $aggregate->recordThat(new TestAggregateCreated($id));
        return $aggregate;
    }

    public function doSomething(string $action): void
    {
        $this->recordThat(new TestActionPerformed($this->getAggregateId(), $action));
    }

    public function getActionHistory(): array
    {
        return $this->actionHistory;
    }

    protected function applyTestAggregateCreated(TestAggregateCreated $event): void
    {
        // Aggregate created
    }

    protected function applyTestActionPerformed(TestActionPerformed $event): void
    {
        $this->actionHistory[] = $event->getAction();
    }
}

class TestAggregateId extends AggregateId {}

class TestAggregateCreated extends DomainEvent
{
    public function __construct(TestAggregateId $aggregateId)
    {
        parent::__construct($aggregateId, []);
    }

    public function getEventType(): string
    {
        return 'test.aggregate.created';
    }
}

class TestActionPerformed extends DomainEvent
{
    private string $action;

    public function __construct(TestAggregateId $aggregateId, string $action)
    {
        $this->action = $action;
        parent::__construct($aggregateId, ['action' => $action]);
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getEventType(): string
    {
        return 'test.action.performed';
    }
}