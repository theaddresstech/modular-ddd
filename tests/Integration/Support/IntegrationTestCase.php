<?php

declare(strict_types=1);

namespace LaravelModularDDD\Tests\Integration\Support;

use LaravelModularDDD\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;

/**
 * Base class for integration tests providing common setup and utilities.
 *
 * This class provides:
 * - Common database setup and cleanup
 * - Performance monitoring utilities
 * - Test data generation helpers
 * - Assertion helpers for integration scenarios
 * - Mock and stub utilities for external services
 */
abstract class IntegrationTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpIntegrationEnvironment();
    }

    protected function tearDown(): void
    {
        $this->cleanupIntegrationEnvironment();
        parent::tearDown();
    }

    /**
     * Set up the integration test environment
     */
    protected function setUpIntegrationEnvironment(): void
    {
        // Configure test environment
        $this->configureTestEnvironment();

        // Set up database schemas
        $this->setUpDatabaseSchemas();

        // Initialize monitoring
        $this->initializeTestMonitoring();
    }

    /**
     * Configure the test environment for integration testing
     */
    protected function configureTestEnvironment(): void
    {
        // Set testing-specific configuration
        config([
            'modular-ddd.event_sourcing.enabled' => true,
            'modular-ddd.event_sourcing.snapshots.strategy' => 'simple',
            'modular-ddd.event_sourcing.snapshots.threshold' => 10,
            'modular-ddd.cqrs.cache_enabled' => true,
            'modular-ddd.monitoring.enabled' => true,
            'queue.default' => 'sync', // Use sync for most integration tests
        ]);

        // Set up separate Redis database for testing
        config([
            'database.redis.testing' => [
                'host' => env('REDIS_HOST', '127.0.0.1'),
                'port' => env('REDIS_PORT', 6379),
                'database' => 15, // Use separate database for testing
            ],
        ]);
    }

    /**
     * Set up database schemas for integration testing
     */
    protected function setUpDatabaseSchemas(): void
    {
        // Event store table
        if (!DB::getSchemaBuilder()->hasTable('event_store')) {
            DB::getSchemaBuilder()->create('event_store', function ($table) {
                $table->id();
                $table->string('aggregate_id');
                $table->string('aggregate_type');
                $table->string('event_type');
                $table->integer('event_version')->default(1);
                $table->json('event_data');
                $table->json('metadata')->nullable();
                $table->integer('version');
                $table->timestamp('occurred_at');
                $table->timestamps();

                $table->unique(['aggregate_id', 'version']);
                $table->index(['aggregate_type', 'occurred_at']);
                $table->index(['event_type', 'occurred_at']);
            });
        }

        // Snapshots table
        if (!DB::getSchemaBuilder()->hasTable('snapshots')) {
            DB::getSchemaBuilder()->create('snapshots', function ($table) {
                $table->id();
                $table->string('aggregate_id');
                $table->string('aggregate_type');
                $table->integer('version');
                $table->json('snapshot_data');
                $table->json('metadata')->nullable();
                $table->timestamp('created_at');

                $table->unique(['aggregate_id', 'version']);
                $table->index(['aggregate_type', 'created_at']);
            });
        }

        // Module messages table
        if (!DB::getSchemaBuilder()->hasTable('module_messages')) {
            DB::getSchemaBuilder()->create('module_messages', function ($table) {
                $table->id();
                $table->string('message_id')->unique();
                $table->string('source_module');
                $table->string('target_module');
                $table->string('message_type');
                $table->json('payload');
                $table->json('metadata');
                $table->string('status');
                $table->integer('retry_count')->default(0);
                $table->timestamp('sent_at');
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();

                $table->index(['target_module', 'status']);
                $table->index(['message_type', 'sent_at']);
            });
        }

        // Module events table
        if (!DB::getSchemaBuilder()->hasTable('module_events')) {
            DB::getSchemaBuilder()->create('module_events', function ($table) {
                $table->id();
                $table->string('event_id')->unique();
                $table->string('event_type');
                $table->string('source_module');
                $table->json('payload');
                $table->json('metadata');
                $table->timestamp('occurred_at');
                $table->timestamp('published_at');
                $table->timestamps();

                $table->index(['event_type', 'occurred_at']);
                $table->index(['source_module', 'published_at']);
            });
        }

        // Test entities table for CQRS tests
        if (!DB::getSchemaBuilder()->hasTable('test_entities')) {
            DB::getSchemaBuilder()->create('test_entities', function ($table) {
                $table->string('id')->primary();
                $table->string('name');
                $table->json('data')->nullable();
                $table->timestamps();
            });
        }

        // Performance metrics table
        if (!DB::getSchemaBuilder()->hasTable('performance_metrics')) {
            DB::getSchemaBuilder()->create('performance_metrics', function ($table) {
                $table->id();
                $table->string('metric_type');
                $table->string('metric_name');
                $table->decimal('value', 10, 2);
                $table->json('metadata')->nullable();
                $table->timestamp('recorded_at');
                $table->timestamps();

                $table->index(['metric_type', 'metric_name', 'recorded_at']);
            });
        }
    }

    /**
     * Initialize test monitoring
     */
    protected function initializeTestMonitoring(): void
    {
        // Clear any existing metrics
        if (DB::getSchemaBuilder()->hasTable('performance_metrics')) {
            DB::table('performance_metrics')->truncate();
        }

        // Initialize performance tracking
        $this->startPerformanceTracking();
    }

    /**
     * Start performance tracking for the test
     */
    protected function startPerformanceTracking(): void
    {
        $this->testStartTime = microtime(true);
        $this->testStartMemory = memory_get_usage(true);
        $this->testStartPeakMemory = memory_get_peak_usage(true);
    }

    /**
     * Get performance metrics for the current test
     */
    protected function getTestPerformanceMetrics(): array
    {
        return [
            'execution_time_ms' => (microtime(true) - $this->testStartTime) * 1000,
            'memory_used_mb' => (memory_get_usage(true) - $this->testStartMemory) / 1024 / 1024,
            'peak_memory_mb' => memory_get_peak_usage(true) / 1024 / 1024,
            'final_memory_mb' => memory_get_usage(true) / 1024 / 1024,
        ];
    }

    /**
     * Assert that test performance is within acceptable limits
     */
    protected function assertPerformanceWithinLimits(array $limits = []): void
    {
        $metrics = $this->getTestPerformanceMetrics();

        $defaultLimits = [
            'max_execution_time_ms' => 5000,  // 5 seconds
            'max_memory_mb' => 128,           // 128 MB
            'max_peak_memory_mb' => 256,      // 256 MB
        ];

        $limits = array_merge($defaultLimits, $limits);

        $this->assertLessThan($limits['max_execution_time_ms'], $metrics['execution_time_ms'],
            "Test execution time exceeded limit: {$metrics['execution_time_ms']}ms > {$limits['max_execution_time_ms']}ms");

        $this->assertLessThan($limits['max_memory_mb'], $metrics['memory_used_mb'],
            "Test memory usage exceeded limit: {$metrics['memory_used_mb']}MB > {$limits['max_memory_mb']}MB");

        $this->assertLessThan($limits['max_peak_memory_mb'], $metrics['peak_memory_mb'],
            "Test peak memory exceeded limit: {$metrics['peak_memory_mb']}MB > {$limits['max_peak_memory_mb']}MB");
    }

    /**
     * Record a performance metric for later analysis
     */
    protected function recordPerformanceMetric(string $type, string $name, float $value, array $metadata = []): void
    {
        DB::table('performance_metrics')->insert([
            'metric_type' => $type,
            'metric_name' => $name,
            'value' => $value,
            'metadata' => json_encode($metadata),
            'recorded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Assert that a value is between two numbers (inclusive)
     */
    protected function assertBetween($min, $max, $actual, string $message = ''): void
    {
        $this->assertGreaterThanOrEqual($min, $actual, $message ?: "Value {$actual} should be >= {$min}");
        $this->assertLessThanOrEqual($max, $actual, $message ?: "Value {$actual} should be <= {$max}");
    }

    /**
     * Assert that a string contains any of the given substrings
     */
    protected function assertStringContainsAny(array $needles, string $haystack, string $message = ''): void
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                $this->assertTrue(true);
                return;
            }
        }

        $this->fail($message ?: "String '{$haystack}' does not contain any of: " . implode(', ', $needles));
    }

    /**
     * Assert that an array has all the given keys
     */
    protected function assertArrayHasKeys(array $keys, array $array, string $message = ''): void
    {
        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $array, $message ?: "Array missing key: {$key}");
        }
    }

    /**
     * Assert that a JSON response has the expected structure with optional validation
     */
    protected function assertJsonStructureWithValidation(array $structure, array $data, array $validations = []): void
    {
        // First check the structure
        $this->assertArrayHasKeys(array_keys($structure), $data);

        // Then validate specific values if provided
        foreach ($validations as $key => $validation) {
            if (isset($data[$key])) {
                if (is_callable($validation)) {
                    $this->assertTrue($validation($data[$key]),
                        "Validation failed for key '{$key}' with value: " . json_encode($data[$key]));
                } else {
                    $this->assertEquals($validation, $data[$key],
                        "Value mismatch for key '{$key}'");
                }
            }
        }
    }

    /**
     * Create test data in bulk for performance testing
     */
    protected function createBulkTestData(string $table, array $template, int $count): array
    {
        $data = [];

        for ($i = 1; $i <= $count; $i++) {
            $record = $template;

            // Replace placeholders in template
            array_walk_recursive($record, function (&$value) use ($i) {
                if (is_string($value)) {
                    $value = str_replace('{index}', $i, $value);
                    $value = str_replace('{timestamp}', now()->toISOString(), $value);
                    $value = str_replace('{uuid}', \Ramsey\Uuid\Uuid::uuid4()->toString(), $value);
                }
            });

            $data[] = $record;
        }

        DB::table($table)->insert($data);

        return $data;
    }

    /**
     * Wait for async operations to complete
     */
    protected function waitForAsyncOperations(int $timeoutSeconds = 10): void
    {
        $start = time();

        while (time() - $start < $timeoutSeconds) {
            // Check if there are pending jobs
            if ($this->hasNoPendingJobs()) {
                return;
            }

            usleep(100000); // Wait 100ms
        }

        $this->fail('Async operations did not complete within timeout period');
    }

    /**
     * Check if there are no pending jobs (simplified for testing)
     */
    protected function hasNoPendingJobs(): bool
    {
        // In a real implementation, this would check the actual queue status
        // For testing with sync queue, we assume jobs are processed immediately
        return true;
    }

    /**
     * Simulate network latency for testing resilience
     */
    protected function simulateNetworkLatency(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }

    /**
     * Generate random test data of specified size
     */
    protected function generateRandomData(int $sizeInBytes): string
    {
        return str_repeat('a', $sizeInBytes);
    }

    /**
     * Create a temporary file for testing file operations
     */
    protected function createTemporaryFile(string $content = '', string $extension = 'txt'): string
    {
        $filename = tempnam(sys_get_temp_dir(), 'integration_test_') . '.' . $extension;
        file_put_contents($filename, $content);

        // Register for cleanup
        $this->tempFiles[] = $filename;

        return $filename;
    }

    /**
     * Clean up the integration test environment
     */
    protected function cleanupIntegrationEnvironment(): void
    {
        // Clear caches
        Cache::flush();

        // Clear Redis test database
        try {
            Redis::connection('testing')->flushdb();
        } catch (\Exception $e) {
            // Redis might not be available in all test environments
        }

        // Clean up temporary files
        foreach ($this->tempFiles ?? [] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        // Record final performance metrics
        $this->recordFinalPerformanceMetrics();
    }

    /**
     * Record final performance metrics for the test
     */
    protected function recordFinalPerformanceMetrics(): void
    {
        if (isset($this->testStartTime)) {
            $metrics = $this->getTestPerformanceMetrics();

            foreach ($metrics as $name => $value) {
                $this->recordPerformanceMetric('test', $name, $value, [
                    'test_class' => static::class,
                    'test_method' => $this->getName(),
                ]);
            }
        }
    }

    /**
     * Properties for tracking test state
     */
    protected float $testStartTime;
    protected int $testStartMemory;
    protected int $testStartPeakMemory;
    protected array $tempFiles = [];
}