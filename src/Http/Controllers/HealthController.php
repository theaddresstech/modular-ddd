<?php

declare(strict_types=1);

namespace LaravelModularDDD\Http\Controllers;

use LaravelModularDDD\EventSourcing\Contracts\EventStoreInterface;
use LaravelModularDDD\EventSourcing\Contracts\SnapshotStoreInterface;
use LaravelModularDDD\EventSourcing\Projections\ProjectionManager;
use LaravelModularDDD\EventSourcing\Listeners\ProjectionEventBridge;
use LaravelModularDDD\CQRS\Contracts\CommandBusInterface;
use LaravelModularDDD\CQRS\Contracts\QueryBusInterface;
use LaravelModularDDD\CQRS\Caching\MultiTierCacheManager;
use LaravelModularDDD\Core\Application\Contracts\TransactionManagerInterface;
use LaravelModularDDD\Support\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Log;

/**
 * HealthController
 *
 * Provides comprehensive health check endpoints for production monitoring.
 * Monitors all critical system components including event store, projections,
 * caching, queues, and database connectivity.
 */
final class HealthController
{
    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly SnapshotStoreInterface $snapshotStore,
        private readonly ProjectionEventBridge $projectionBridge,
        private readonly CommandBusInterface $commandBus,
        private readonly QueryBusInterface $queryBus,
        private readonly MultiTierCacheManager $cacheManager,
        private readonly TransactionManagerInterface $transactionManager,
        private readonly ModuleRegistry $moduleRegistry
    ) {}

    /**
     * Overall system health check.
     */
    public function index(): JsonResponse
    {
        $startTime = microtime(true);
        $checks = [];
        $status = 'healthy';

        try {
            // Check all critical components
            $checks['database'] = $this->checkDatabase();
            $checks['event_store'] = $this->checkEventStore();
            $checks['snapshots'] = $this->checkSnapshotStore();
            $checks['projections'] = $this->checkProjections();
            $checks['cache'] = $this->checkCache();
            $checks['queues'] = $this->checkQueues();
            $checks['transactions'] = $this->checkTransactions();
            $checks['modules'] = $this->checkModules();

            // Determine overall status
            foreach ($checks as $check) {
                if ($check['status'] === 'unhealthy') {
                    $status = 'unhealthy';
                    break;
                } elseif ($check['status'] === 'degraded' && $status === 'healthy') {
                    $status = 'degraded';
                }
            }

        } catch (\Exception $e) {
            $status = 'unhealthy';
            $checks['error'] = [
                'status' => 'unhealthy',
                'message' => 'Health check failed: ' . $e->getMessage(),
                'timestamp' => now()->toISOString(),
            ];

            Log::error('Health check failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }

        $responseTime = round((microtime(true) - $startTime) * 1000, 2);

        return response()->json([
            'status' => $status,
            'timestamp' => now()->toISOString(),
            'response_time_ms' => $responseTime,
            'checks' => $checks,
            'system_info' => [
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            ],
        ], $status === 'healthy' ? 200 : ($status === 'degraded' ? 200 : 503));
    }

    /**
     * Database connectivity health check.
     */
    public function database(): JsonResponse
    {
        $check = $this->checkDatabase();

        return response()->json($check, $check['status'] === 'healthy' ? 200 : 503);
    }

    /**
     * Event store health check.
     */
    public function eventStore(): JsonResponse
    {
        $check = $this->checkEventStore();

        return response()->json($check, $check['status'] === 'healthy' ? 200 : 503);
    }

    /**
     * Snapshot store health check.
     */
    public function snapshots(): JsonResponse
    {
        $check = $this->checkSnapshotStore();

        return response()->json($check, $check['status'] === 'healthy' ? 200 : 503);
    }

    /**
     * Projections health check.
     */
    public function projections(): JsonResponse
    {
        $check = $this->checkProjections();

        return response()->json($check, $check['status'] === 'healthy' ? 200 : 503);
    }

    /**
     * Cache system health check.
     */
    public function cache(): JsonResponse
    {
        $check = $this->checkCache();

        return response()->json($check, $check['status'] === 'healthy' ? 200 : 503);
    }

    /**
     * Queue system health check.
     */
    public function queues(): JsonResponse
    {
        $check = $this->checkQueues();

        return response()->json($check, $check['status'] === 'healthy' ? 200 : 503);
    }

    /**
     * Transaction manager health check.
     */
    public function transactions(): JsonResponse
    {
        $check = $this->checkTransactions();

        return response()->json($check, $check['status'] === 'healthy' ? 200 : 503);
    }

    /**
     * Modules health check.
     */
    public function modules(): JsonResponse
    {
        $check = $this->checkModules();

        return response()->json($check, $check['status'] === 'healthy' ? 200 : 503);
    }

    /**
     * System metrics endpoint.
     */
    public function metrics(): JsonResponse
    {
        return response()->json([
            'timestamp' => now()->toISOString(),
            'command_bus' => $this->commandBus->getStatistics(),
            'query_bus' => $this->queryBus->getStatistics(),
            'cache' => $this->cacheManager->getStatistics(),
            'transactions' => $this->transactionManager->getStatistics(),
            'projections' => $this->projectionBridge->getStatistics(),
            'memory' => [
                'current_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                'limit_mb' => ini_get('memory_limit'),
            ],
            'system' => [
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'timezone' => config('app.timezone'),
                'environment' => app()->environment(),
            ],
        ]);
    }

    private function checkDatabase(): array
    {
        $startTime = microtime(true);

        try {
            // Test basic connectivity
            DB::connection()->getPdo();

            // Test a simple query
            $result = DB::select('SELECT 1 as test');

            if (empty($result) || $result[0]->test !== 1) {
                throw new \RuntimeException('Database query returned unexpected result');
            }

            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'status' => 'healthy',
                'message' => 'Database connection is working',
                'response_time_ms' => $responseTime,
                'details' => [
                    'driver' => DB::connection()->getDriverName(),
                    'database' => DB::connection()->getDatabaseName(),
                ],
                'timestamp' => now()->toISOString(),
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'message' => 'Database connection failed: ' . $e->getMessage(),
                'timestamp' => now()->toISOString(),
            ];
        }
    }

    private function checkEventStore(): array
    {
        $startTime = microtime(true);

        try {
            // Test event store connectivity by checking if we can query the event store table
            $testResult = DB::table('event_store')->count();

            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'status' => 'healthy',
                'message' => 'Event store is accessible',
                'response_time_ms' => $responseTime,
                'details' => [
                    'total_events' => $testResult,
                ],
                'timestamp' => now()->toISOString(),
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'message' => 'Event store check failed: ' . $e->getMessage(),
                'timestamp' => now()->toISOString(),
            ];
        }
    }

    private function checkSnapshotStore(): array
    {
        $startTime = microtime(true);

        try {
            // Test snapshot store connectivity
            $testResult = DB::table('snapshots')->count();

            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'status' => 'healthy',
                'message' => 'Snapshot store is accessible',
                'response_time_ms' => $responseTime,
                'details' => [
                    'total_snapshots' => $testResult,
                ],
                'timestamp' => now()->toISOString(),
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'message' => 'Snapshot store check failed: ' . $e->getMessage(),
                'timestamp' => now()->toISOString(),
            ];
        }
    }

    private function checkProjections(): array
    {
        $startTime = microtime(true);

        try {
            $stats = $this->projectionBridge->getStatistics();
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            $status = $stats['strategies_registered'] > 0 ? 'healthy' : 'degraded';
            $message = $stats['strategies_registered'] > 0
                ? 'Projection system is working'
                : 'No projection strategies registered';

            return [
                'status' => $status,
                'message' => $message,
                'response_time_ms' => $responseTime,
                'details' => $stats,
                'timestamp' => now()->toISOString(),
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'message' => 'Projection check failed: ' . $e->getMessage(),
                'timestamp' => now()->toISOString(),
            ];
        }
    }

    private function checkCache(): array
    {
        $startTime = microtime(true);

        try {
            // Test cache connectivity
            $testKey = 'health_check_' . uniqid();
            $testValue = 'test_value_' . time();

            Cache::put($testKey, $testValue, 60);
            $retrievedValue = Cache::get($testKey);
            Cache::forget($testKey);

            if ($retrievedValue !== $testValue) {
                throw new \RuntimeException('Cache test failed: values do not match');
            }

            $stats = $this->cacheManager->getStatistics();
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'status' => 'healthy',
                'message' => 'Cache system is working',
                'response_time_ms' => $responseTime,
                'details' => $stats,
                'timestamp' => now()->toISOString(),
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'message' => 'Cache check failed: ' . $e->getMessage(),
                'timestamp' => now()->toISOString(),
            ];
        }
    }

    private function checkQueues(): array
    {
        $startTime = microtime(true);

        try {
            // Get queue connection info
            $connection = Queue::connection();
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'status' => 'healthy',
                'message' => 'Queue system is accessible',
                'response_time_ms' => $responseTime,
                'details' => [
                    'driver' => config('queue.default'),
                    'connection' => get_class($connection),
                ],
                'timestamp' => now()->toISOString(),
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'message' => 'Queue check failed: ' . $e->getMessage(),
                'timestamp' => now()->toISOString(),
            ];
        }
    }

    private function checkTransactions(): array
    {
        $startTime = microtime(true);

        try {
            $stats = $this->transactionManager->getStatistics();
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'status' => 'healthy',
                'message' => 'Transaction manager is working',
                'response_time_ms' => $responseTime,
                'details' => $stats,
                'timestamp' => now()->toISOString(),
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'message' => 'Transaction manager check failed: ' . $e->getMessage(),
                'timestamp' => now()->toISOString(),
            ];
        }
    }

    private function checkModules(): array
    {
        $startTime = microtime(true);

        try {
            $modules = $this->moduleRegistry->getRegisteredModules();
            $enabledModules = $modules->filter(fn($module) => $module['enabled'] ?? true);
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'status' => 'healthy',
                'message' => 'Module system is working',
                'response_time_ms' => $responseTime,
                'details' => [
                    'total_modules' => $modules->count(),
                    'enabled_modules' => $enabledModules->count(),
                    'disabled_modules' => $modules->count() - $enabledModules->count(),
                ],
                'timestamp' => now()->toISOString(),
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'message' => 'Module check failed: ' . $e->getMessage(),
                'timestamp' => now()->toISOString(),
            ];
        }
    }
}