<?php

declare(strict_types=1);

namespace LaravelModularDDD\Tests\Integration\Performance;

use LaravelModularDDD\Tests\TestCase;
use LaravelModularDDD\Tests\Integration\Performance\Traits\PerformanceTestingTrait;
use LaravelModularDDD\Http\Controllers\HealthController;
use LaravelModularDDD\Http\Controllers\MetricsController;
use LaravelModularDDD\Monitoring\PerformanceMetricsCollector;
use LaravelModularDDD\CQRS\Monitoring\InMemoryMetricsCollector;
use LaravelModularDDD\Console\Commands\ModuleHealthCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * Integration tests for performance monitoring and production readiness.
 *
 * Tests the complete production infrastructure including:
 * - Health check endpoints and monitoring
 * - Metrics collection and aggregation
 * - Database connection resilience
 * - Memory leak detection in long-running operations
 * - Performance benchmarks and thresholds
 * - System resource monitoring
 *
 * @group integration
 * @group performance
 * @group production
 */
class PerformanceIntegrationTest extends TestCase
{
    use RefreshDatabase, PerformanceTestingTrait;

    private PerformanceMetricsCollector $metricsCollector;
    private HealthController $healthController;
    private MetricsController $metricsController;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPerformanceInfrastructure();
    }

    protected function tearDown(): void
    {
        $this->cleanupPerformanceData();
        parent::tearDown();
    }

    /**
     * @test
     * @group health-checks
     */
    public function test_it_provides_comprehensive_health_check_endpoints(): void
    {
        // Act: Check basic health endpoint
        $basicHealth = $this->get('/api/health');

        // Assert: Basic health check passes
        $basicHealth->assertOk()
            ->assertJsonStructure([
                'status',
                'timestamp',
                'version',
                'environment',
            ])
            ->assertJson(['status' => 'healthy']);

        // Act: Check detailed health endpoint
        $detailedHealth = $this->get('/api/health/detailed');

        // Assert: Detailed health check provides comprehensive information
        $detailedHealth->assertOk()
            ->assertJsonStructure([
                'status',
                'timestamp',
                'version',
                'environment',
                'services' => [
                    'database',
                    'cache',
                    'redis',
                    'queue',
                ],
                'metrics' => [
                    'response_time_ms',
                    'memory_usage_mb',
                    'cpu_usage_percent',
                ],
                'dependencies',
            ]);

        $healthData = $detailedHealth->json();

        // Verify all services are healthy
        foreach ($healthData['services'] as $service => $status) {
            $this->assertEquals('healthy', $status['status'],
                "Service {$service} should be healthy");
        }
    }

    /**
     * @test
     * @group metrics-collection
     */
    public function test_it_collects_and_aggregates_performance_metrics(): void
    {
        // Arrange: Generate some metrics
        $this->generateTestMetrics();

        // Act: Collect metrics
        $response = $this->get('/api/metrics');

        // Assert: Metrics endpoint returns comprehensive data
        $response->assertOk()
            ->assertJsonStructure([
                'timestamp',
                'metrics' => [
                    'system' => [
                        'memory_usage',
                        'cpu_usage',
                        'disk_usage',
                    ],
                    'application' => [
                        'requests_per_second',
                        'average_response_time',
                        'error_rate',
                    ],
                    'database' => [
                        'active_connections',
                        'query_time_avg',
                        'slow_query_count',
                    ],
                    'cache' => [
                        'hit_rate',
                        'memory_usage',
                        'operations_per_second',
                    ],
                ],
            ]);

        $metrics = $response->json();

        // Verify metric values are within expected ranges
        $this->assertGreaterThan(0, $metrics['metrics']['system']['memory_usage']);
        $this->assertGreaterThanOrEqual(0, $metrics['metrics']['application']['error_rate']);
        $this->assertLessThanOrEqual(100, $metrics['metrics']['cache']['hit_rate']);
    }

    /**
     * @test
     * @group database-resilience
     */
    public function test_it_handles_database_connection_resilience(): void
    {
        // Test with normal database connection
        $this->assertDatabaseConnectionHealthy();

        // Test connection pooling under load
        $this->simulateDatabaseLoad(50); // 50 concurrent connections

        // Verify connections are properly managed
        $connectionStats = $this->getDatabaseConnectionStats();
        $this->assertLessThan(50, $connectionStats['active_connections']);
        $this->assertEquals(0, $connectionStats['failed_connections']);

        // Test connection recovery after temporary failure
        $this->simulateDatabaseDisconnection();

        // Verify health check detects the issue
        $health = $this->get('/api/health/detailed');
        $healthData = $health->json();
        $this->assertEquals('unhealthy', $healthData['services']['database']['status']);

        // Restore connection and verify recovery
        $this->restoreDatabaseConnection();

        $health = $this->get('/api/health/detailed');
        $healthData = $health->json();
        $this->assertEquals('healthy', $healthData['services']['database']['status']);
    }

    /**
     * @test
     * @group memory-monitoring
     */
    public function test_it_detects_memory_leaks_in_long_running_operations(): void
    {
        // Arrange: Record initial memory usage
        $initialMemory = memory_get_usage(true);

        // Act: Simulate long-running operation with potential memory leaks
        $this->simulateLongRunningOperation(1000); // Process 1000 items

        // Measure memory after operation
        $finalMemory = memory_get_usage(true);
        $memoryIncrease = $finalMemory - $initialMemory;

        // Assert: Memory increase should be reasonable (less than 50MB for test data)
        $this->assertLessThan(50 * 1024 * 1024, $memoryIncrease,
            "Memory usage increased by " . round($memoryIncrease / 1024 / 1024, 2) . "MB, which may indicate a memory leak");

        // Verify garbage collection helps
        gc_collect_cycles();
        $afterGcMemory = memory_get_usage(true);

        $this->assertLessThan($finalMemory, $afterGcMemory,
            "Garbage collection should reduce memory usage");

        // Check for circular references
        $this->assertNoCircularReferences();
    }

    /**
     * @test
     * @group performance-benchmarks
     */
    public function test_it_meets_performance_benchmarks(): void
    {
        // Test API response times
        $this->assertApiResponseTimes();

        // Test database query performance
        $this->assertDatabaseQueryPerformance();

        // Test cache performance
        $this->assertCachePerformance();

        // Test event processing performance
        $this->assertEventProcessingPerformance();
    }

    /**
     * @test
     * @group system-monitoring
     */
    public function test_it_monitors_system_resources_effectively(): void
    {
        // Act: Get system resource metrics
        $systemMetrics = $this->metricsCollector->getSystemMetrics();

        // Assert: All expected metrics are present
        $expectedMetrics = [
            'cpu_usage_percent',
            'memory_usage_mb',
            'memory_usage_percent',
            'disk_usage_percent',
            'load_average',
            'network_io',
            'disk_io',
        ];

        foreach ($expectedMetrics as $metric) {
            $this->assertArrayHasKey($metric, $systemMetrics,
                "System metric '{$metric}' should be present");
        }

        // Verify metric values are reasonable
        $this->assertBetween(0, 100, $systemMetrics['cpu_usage_percent']);
        $this->assertBetween(0, 100, $systemMetrics['memory_usage_percent']);
        $this->assertBetween(0, 100, $systemMetrics['disk_usage_percent']);
        $this->assertGreaterThan(0, $systemMetrics['memory_usage_mb']);
    }

    /**
     * @test
     * @group alerting
     */
    public function test_it_triggers_alerts_for_performance_issues(): void
    {
        // Simulate high CPU usage
        $this->simulateHighCpuUsage();

        // Check if alert is triggered
        $alerts = $this->metricsCollector->getActiveAlerts();
        $this->assertContains('high_cpu_usage', array_column($alerts, 'type'));

        // Simulate memory pressure
        $this->simulateMemoryPressure();

        // Check for memory alert
        $alerts = $this->metricsCollector->getActiveAlerts();
        $this->assertContains('high_memory_usage', array_column($alerts, 'type'));

        // Simulate slow database queries
        $this->simulateSlowQueries();

        // Check for database performance alert
        $alerts = $this->metricsCollector->getActiveAlerts();
        $this->assertContains('slow_database_queries', array_column($alerts, 'type'));
    }

    /**
     * @test
     * @group error-tracking
     */
    public function test_it_tracks_and_reports_errors_effectively(): void
    {
        // Generate different types of errors
        $this->generateTestErrors();

        // Get error metrics
        $errorMetrics = $this->metricsCollector->getErrorMetrics();

        // Assert error tracking
        $this->assertArrayHasKey('total_errors', $errorMetrics);
        $this->assertArrayHasKey('error_rate', $errorMetrics);
        $this->assertArrayHasKey('errors_by_type', $errorMetrics);
        $this->assertArrayHasKey('errors_by_severity', $errorMetrics);

        // Verify error categorization
        $errorsByType = $errorMetrics['errors_by_type'];
        $this->assertArrayHasKey('database_error', $errorsByType);
        $this->assertArrayHasKey('validation_error', $errorsByType);
        $this->assertArrayHasKey('timeout_error', $errorsByType);

        // Check error rate calculation
        $this->assertBetween(0, 100, $errorMetrics['error_rate']);
    }

    /**
     * @test
     * @group load-testing
     */
    public function test_it_handles_load_testing_scenarios(): void
    {
        // Simulate moderate load
        $results = $this->simulateLoad([
            'concurrent_users' => 50,
            'requests_per_user' => 10,
            'duration_seconds' => 30,
        ]);

        // Assert performance under load
        $this->assertLessThan(500, $results['average_response_time_ms']);
        $this->assertLessThan(1, $results['error_rate_percent']);
        $this->assertGreaterThan(10, $results['requests_per_second']);

        // Test spike handling
        $spikeResults = $this->simulateTrafficSpike([
            'normal_load' => 10,
            'spike_load' => 100,
            'spike_duration_seconds' => 10,
        ]);

        // Assert system recovers from spike
        $this->assertLessThan(2, $spikeResults['recovery_time_seconds']);
        $this->assertLessThan(5, $spikeResults['error_rate_during_spike']);
    }

    /**
     * @test
     * @group module-health
     */
    public function test_it_monitors_individual_module_health(): void
    {
        // Use the module health command
        $command = new ModuleHealthCommand();

        $output = $this->artisan('module:health', ['--format' => 'json'])
            ->expectsOutput('Module health check completed')
            ->assertExitCode(0);

        // Verify module health data structure
        $healthData = json_decode($output->getOutput(), true);

        $this->assertArrayHasKey('modules', $healthData);
        $this->assertArrayHasKey('overall_status', $healthData);
        $this->assertArrayHasKey('timestamp', $healthData);

        // Check individual module health
        foreach ($healthData['modules'] as $moduleName => $moduleHealth) {
            $this->assertArrayHasKey('status', $moduleHealth);
            $this->assertArrayHasKey('response_time_ms', $moduleHealth);
            $this->assertArrayHasKey('last_check', $moduleHealth);

            $this->assertContains($moduleHealth['status'], ['healthy', 'warning', 'critical']);
        }
    }

    /**
     * @test
     * @group cache-monitoring
     */
    public function test_it_monitors_cache_performance_and_health(): void
    {
        // Generate cache activity
        $this->generateCacheActivity();

        // Get cache metrics
        $cacheMetrics = $this->get('/api/metrics/cache')->json();

        // Assert cache metrics structure
        $this->assertArrayHasKey('hit_rate', $cacheMetrics);
        $this->assertArrayHasKey('miss_rate', $cacheMetrics);
        $this->assertArrayHasKey('eviction_rate', $cacheMetrics);
        $this->assertArrayHasKey('memory_usage', $cacheMetrics);
        $this->assertArrayHasKey('operations_per_second', $cacheMetrics);

        // Verify cache performance
        $this->assertBetween(0, 100, $cacheMetrics['hit_rate']);
        $this->assertBetween(0, 100, $cacheMetrics['miss_rate']);
        $this->assertEquals(100, $cacheMetrics['hit_rate'] + $cacheMetrics['miss_rate']);

        // Test cache health under load
        $this->simulateCacheLoad();

        $loadMetrics = $this->get('/api/metrics/cache')->json();
        $this->assertGreaterThan(0, $loadMetrics['operations_per_second']);
    }

    /**
     * @test
     * @group queue-monitoring
     */
    public function test_it_monitors_queue_performance_and_health(): void
    {
        // Generate queue activity
        $this->generateQueueActivity();

        // Get queue metrics
        $queueMetrics = $this->get('/api/metrics/queues')->json();

        // Assert queue metrics
        $this->assertArrayHasKey('total_jobs', $queueMetrics);
        $this->assertArrayHasKey('pending_jobs', $queueMetrics);
        $this->assertArrayHasKey('failed_jobs', $queueMetrics);
        $this->assertArrayHasKey('processed_jobs', $queueMetrics);
        $this->assertArrayHasKey('average_processing_time', $queueMetrics);

        // Verify queue health
        $this->assertGreaterThanOrEqual(0, $queueMetrics['pending_jobs']);
        $this->assertGreaterThanOrEqual(0, $queueMetrics['failed_jobs']);
        $this->assertGreaterThan(0, $queueMetrics['processed_jobs']);
    }

    private function setUpPerformanceInfrastructure(): void
    {
        $this->metricsCollector = new PerformanceMetricsCollector();
        $this->healthController = new HealthController($this->metricsCollector);
        $this->metricsController = new MetricsController($this->metricsCollector);

        // Set up routes for testing
        $this->setupTestRoutes();
    }

    private function setupTestRoutes(): void
    {
        $router = $this->app['router'];

        $router->get('/api/health', [$this->healthController, 'basic']);
        $router->get('/api/health/detailed', [$this->healthController, 'detailed']);
        $router->get('/api/metrics', [$this->metricsController, 'index']);
        $router->get('/api/metrics/cache', [$this->metricsController, 'cache']);
        $router->get('/api/metrics/queues', [$this->metricsController, 'queues']);
    }

    private function generateTestMetrics(): void
    {
        // Simulate application activity that generates metrics
        for ($i = 0; $i < 100; $i++) {
            $this->metricsCollector->recordRequest('GET', '/api/test', 150 + rand(-50, 50));
            $this->metricsCollector->recordDatabaseQuery('SELECT * FROM users', 10 + rand(-5, 5));
            $this->metricsCollector->recordCacheOperation('get', 'user_123', rand(0, 1) === 1);
        }
    }

    private function assertApiResponseTimes(): void
    {
        $endpoints = [
            '/api/health',
            '/api/health/detailed',
            '/api/metrics',
        ];

        foreach ($endpoints as $endpoint) {
            $startTime = microtime(true);
            $response = $this->get($endpoint);
            $responseTime = (microtime(true) - $startTime) * 1000;

            $response->assertOk();
            $this->assertLessThan(200, $responseTime,
                "Endpoint {$endpoint} response time should be under 200ms, got {$responseTime}ms");
        }
    }

    private function assertDatabaseQueryPerformance(): void
    {
        $startTime = microtime(true);

        // Execute a set of common queries
        DB::table('event_store')->count();
        DB::table('snapshots')->count();

        $queryTime = (microtime(true) - $startTime) * 1000;

        $this->assertLessThan(100, $queryTime,
            "Database queries should complete under 100ms, got {$queryTime}ms");
    }

    private function assertCachePerformance(): void
    {
        $operations = 1000;
        $startTime = microtime(true);

        for ($i = 0; $i < $operations; $i++) {
            Cache::put("test_key_{$i}", "test_value_{$i}", 60);
            Cache::get("test_key_{$i}");
        }

        $totalTime = (microtime(true) - $startTime) * 1000;
        $avgTimePerOp = $totalTime / ($operations * 2); // 2 operations per iteration

        $this->assertLessThan(1, $avgTimePerOp,
            "Cache operations should average under 1ms, got {$avgTimePerOp}ms");
    }

    private function assertEventProcessingPerformance(): void
    {
        $events = 100;
        $startTime = microtime(true);

        for ($i = 0; $i < $events; $i++) {
            $event = $this->createTestEvent();
            // Simulate event processing
            $this->metricsCollector->recordEventProcessing($event['type'], 5 + rand(-2, 2));
        }

        $totalTime = (microtime(true) - $startTime) * 1000;
        $avgTimePerEvent = $totalTime / $events;

        $this->assertLessThan(10, $avgTimePerEvent,
            "Event processing should average under 10ms per event, got {$avgTimePerEvent}ms");
    }

    private function cleanupPerformanceData(): void
    {
        Cache::flush();

        // Clear test metrics
        $this->metricsCollector->reset();
    }
}