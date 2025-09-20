<?php

declare(strict_types=1);

namespace LaravelModularDDD\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use LaravelModularDDD\ModularDddServiceProvider;
use LaravelModularDDD\EventSourcing\Contracts\EventStoreInterface;
use LaravelModularDDD\CQRS\Contracts\CommandBusInterface;
use LaravelModularDDD\CQRS\Contracts\QueryBusInterface;
use LaravelModularDDD\Modules\Communication\Contracts\ModuleBusInterface;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();
        $this->configureTestingEnvironment();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestingEnvironment();
        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            ModularDddServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Setup default database to use sqlite in memory
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        // Setup cache to use array driver for testing
        $app['config']->set('cache.default', 'array');

        // Setup queue for testing
        $app['config']->set('queue.default', 'sync');

        // Setup modular-ddd configuration for testing
        $app['config']->set('modular-ddd.event_sourcing.enabled', true);
        $app['config']->set('modular-ddd.event_sourcing.snapshots.strategy', 'simple');
        $app['config']->set('modular-ddd.event_sourcing.snapshots.threshold', 5);
        $app['config']->set('modular-ddd.cqrs.query_bus.cache_enabled', true);
        $app['config']->set('modular-ddd.cqrs.query_bus.cache_ttl', 300);
        $app['config']->set('modular-ddd.performance.monitoring.enabled', true);
        $app['config']->set('modular-ddd.module_communication.enabled', true);
        $app['config']->set('modular-ddd.async.strategy', 'sync');
    }

    /**
     * Configure the testing environment after setup.
     */
    private function configureTestingEnvironment(): void
    {
        // Clear caches
        if (app()->bound('cache')) {
            Cache::flush();
        }

        // Clear queue if supported
        $queueConnection = Queue::connection();
        if (method_exists($queueConnection, 'purge')) {
            $queueConnection->purge();
        }
    }

    /**
     * Clean up the testing environment.
     */
    private function cleanupTestingEnvironment(): void
    {
        // Clear any test artifacts
        if (app()->bound('cache')) {
            Cache::flush();
        }

        // Clear queue if supported
        $queueConnection = Queue::connection();
        if (method_exists($queueConnection, 'purge')) {
            $queueConnection->purge();
        }
    }

    protected function setUpDatabase(): void
    {
        // Load and run package migrations automatically
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    /**
     * Get the Event Store instance for testing.
     */
    protected function getEventStore(): EventStoreInterface
    {
        return app(EventStoreInterface::class);
    }

    /**
     * Get the Command Bus instance for testing.
     */
    protected function getCommandBus(): CommandBusInterface
    {
        return app(CommandBusInterface::class);
    }

    /**
     * Get the Query Bus instance for testing.
     */
    protected function getQueryBus(): QueryBusInterface
    {
        return app(QueryBusInterface::class);
    }

    /**
     * Get the Module Bus instance for testing.
     */
    protected function getModuleBus(): ModuleBusInterface
    {
        return app(ModuleBusInterface::class);
    }

    /**
     * Create a test aggregate ID.
     */
    protected function createTestAggregateId(): string
    {
        return \Illuminate\Support\Str::uuid()->toString();
    }

    /**
     * Create test events for an aggregate.
     */
    protected function createTestEvents(string $aggregateId, int $count = 3): array
    {
        $events = [];

        for ($i = 1; $i <= $count; $i++) {
            $events[] = $this->createTestEvent(
                $aggregateId,
                "TestEvent{$i}",
                ['sequence' => $i, 'data' => "Event data {$i}"],
                $i
            );
        }

        return $events;
    }

    /**
     * Assert that events were stored correctly.
     */
    protected function assertEventsStored(string $aggregateId, int $expectedCount): void
    {
        $eventStore = $this->getEventStore();
        $stream = $eventStore->load($aggregateId);

        $this->assertCount($expectedCount, $stream->getEvents());
    }

    /**
     * Assert that a snapshot was created.
     */
    protected function assertSnapshotCreated(string $aggregateId): void
    {
        $this->assertDatabaseHas('snapshots', [
            'aggregate_id' => $aggregateId
        ]);
    }

    /**
     * Assert memory usage is within acceptable limits.
     */
    protected function assertMemoryUsageWithinLimits(int $maxMB = 128): void
    {
        $currentMemoryMB = memory_get_peak_usage(true) / 1024 / 1024;
        $this->assertLessThan($maxMB, $currentMemoryMB,
            "Memory usage ({$currentMemoryMB}MB) exceeded limit ({$maxMB}MB)");
    }

    /**
     * Assert execution time is within acceptable limits.
     */
    protected function assertExecutionTimeWithinLimits(callable $operation, int $maxMs = 1000): void
    {
        $start = microtime(true);
        $operation();
        $executionTime = (microtime(true) - $start) * 1000;

        $this->assertLessThan($maxMs, $executionTime,
            "Execution time ({$executionTime}ms) exceeded limit ({$maxMs}ms)");
    }

    /**
     * Simulate high load for performance testing.
     */
    protected function simulateHighLoad(callable $operation, int $iterations = 100): array
    {
        $results = [];
        $startMemory = memory_get_usage(true);
        $startTime = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $iterationStart = microtime(true);
            $operation($i);
            $iterationTime = (microtime(true) - $iterationStart) * 1000;

            $results[] = [
                'iteration' => $i,
                'time_ms' => $iterationTime,
                'memory_mb' => memory_get_usage(true) / 1024 / 1024
            ];
        }

        $totalTime = (microtime(true) - $startTime) * 1000;
        $memoryUsed = (memory_get_usage(true) - $startMemory) / 1024 / 1024;

        return [
            'iterations' => $iterations,
            'total_time_ms' => $totalTime,
            'avg_time_ms' => $totalTime / $iterations,
            'memory_used_mb' => $memoryUsed,
            'peak_memory_mb' => memory_get_peak_usage(true) / 1024 / 1024,
            'results' => $results
        ];
    }

    /**
     * Create a test module for testing generators.
     */
    protected function createTestModule(string $name = 'TestModule'): string
    {
        $path = base_path("Modules/{$name}");

        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        return $path;
    }

    /**
     * Clean up test module.
     */
    protected function cleanupTestModule(string $name = 'TestModule'): void
    {
        $path = base_path("Modules/{$name}");

        if (is_dir($path)) {
            $this->removeDirectory($path);
        }
    }

    /**
     * Recursively remove directory.
     */
    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = scandir($path);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $filePath = $path . DIRECTORY_SEPARATOR . $file;
            if (is_dir($filePath)) {
                $this->removeDirectory($filePath);
            } else {
                unlink($filePath);
            }
        }

        rmdir($path);
    }

    protected function createTestEvent(string $aggregateId, string $eventType, array $data = [], int $version = 1): object
    {
        return new class($aggregateId, $eventType, $data, $version) {
            public function __construct(
                private string $aggregateId,
                private string $eventType,
                private array $data,
                private int $version
            ) {}

            public function getAggregateId(): string { return $this->aggregateId; }
            public function getEventType(): string { return $this->eventType; }
            public function getEventData(): array { return $this->data; }
            public function getVersion(): int { return $this->version; }
            public function getOccurredAt(): \DateTimeImmutable { return new \DateTimeImmutable(); }
            public function getEventVersion(): int { return 1; }
            public function getMetadata(): array {
                return [
                    'aggregate_type' => 'TestAggregate',
                    'event_version' => '1.0',
                ];
            }
            public function toArray(): array { return $this->data; }
        };
    }
}