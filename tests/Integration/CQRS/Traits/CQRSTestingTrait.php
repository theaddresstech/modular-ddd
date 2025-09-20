<?php

declare(strict_types=1);

namespace LaravelModularDDD\Tests\Integration\CQRS\Traits;

use LaravelModularDDD\CQRS\Contracts\CommandInterface;
use LaravelModularDDD\CQRS\Contracts\QueryInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Trait providing common testing utilities for CQRS integration tests.
 */
trait CQRSTestingTrait
{
    /**
     * Assert that a command was processed successfully
     */
    protected function assertCommandProcessedSuccessfully(array $result): void
    {
        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('timestamp', $result);
    }

    /**
     * Assert that a query result has the expected structure
     */
    protected function assertQueryResultValid(array $result, array $expectedKeys = []): void
    {
        $this->assertIsArray($result);

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result);
        }

        // Common query result fields
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertArrayHasKey('source', $result);
    }

    /**
     * Assert cache hit metrics are recorded correctly
     */
    protected function assertCacheHitRecorded(array $stats): void
    {
        $this->assertArrayHasKey('cache_hits', $stats);
        $this->assertArrayHasKey('cache_misses', $stats);
        $this->assertArrayHasKey('cache_performance', $stats);

        $this->assertGreaterThan(0, $stats['cache_hits']);
        $this->assertGreaterThan(0, $stats['cache_performance']['hit_rate']);
    }

    /**
     * Assert command execution time is within acceptable limits
     */
    protected function assertCommandExecutionTime(float $executionTime, float $maxSeconds = 1.0): void
    {
        $this->assertLessThan($maxSeconds, $executionTime,
            "Command execution took {$executionTime}s, which exceeds {$maxSeconds}s limit");
    }

    /**
     * Assert query execution time is within acceptable limits
     */
    protected function assertQueryExecutionTime(float $executionTime, float $maxSeconds = 0.5): void
    {
        $this->assertLessThan($maxSeconds, $executionTime,
            "Query execution took {$executionTime}s, which exceeds {$maxSeconds}s limit");
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
     * Create test user data in database
     */
    protected function createTestUser(string $userId, array $data = []): array
    {
        $userData = array_merge([
            'id' => $userId,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'data' => json_encode([
                'profile' => ['bio' => 'Test user bio'],
                'preferences' => ['theme' => 'dark'],
            ]),
        ], $data);

        DB::table('test_entities')->insert($userData);

        return $userData;
    }

    /**
     * Assert that database state matches expected values
     */
    protected function assertDatabaseState(string $table, array $expectedData): void
    {
        foreach ($expectedData as $id => $data) {
            $record = DB::table($table)->where('id', $id)->first();
            $this->assertNotNull($record, "Record with ID {$id} not found in {$table}");

            foreach ($data as $field => $value) {
                $this->assertEquals($value, $record->$field,
                    "Field {$field} mismatch for record {$id}");
            }
        }
    }

    /**
     * Clear all CQRS-related caches
     */
    protected function clearCQRSCaches(): void
    {
        // Clear Laravel cache
        Cache::flush();

        // Clear specific CQRS cache tags
        $cacheTags = [
            'queries',
            'commands',
            'users',
            'handlers',
            'statistics',
        ];

        foreach ($cacheTags as $tag) {
            Cache::tags($tag)->flush();
        }
    }

    /**
     * Simulate cache miss by clearing specific cache entries
     */
    protected function simulateCacheMiss(QueryInterface $query): void
    {
        $cacheKey = $query->getCacheKey();
        Cache::forget($cacheKey);
    }

    /**
     * Verify cache contains expected entry
     */
    protected function assertCacheContains(QueryInterface $query, $expectedValue = null): void
    {
        $cacheKey = $query->getCacheKey();
        $this->assertTrue(Cache::has($cacheKey), "Cache key {$cacheKey} not found");

        if ($expectedValue !== null) {
            $cachedValue = Cache::get($cacheKey);
            $this->assertEquals($expectedValue, $cachedValue);
        }
    }

    /**
     * Verify cache does not contain entry
     */
    protected function assertCacheNotContains(QueryInterface $query): void
    {
        $cacheKey = $query->getCacheKey();
        $this->assertFalse(Cache::has($cacheKey), "Cache key {$cacheKey} should not exist");
    }

    /**
     * Create multiple test commands for batch processing
     */
    protected function createCommandBatch(int $count, string $commandType = 'update'): array
    {
        $commands = [];

        for ($i = 1; $i <= $count; $i++) {
            $commands[] = $this->createTestCommand($commandType, [
                'id' => "test_{$i}",
                'batch_index' => $i,
                'batch_size' => $count,
            ]);
        }

        return $commands;
    }

    /**
     * Create multiple test queries for batch processing
     */
    protected function createQueryBatch(int $count, string $queryType = 'user'): array
    {
        $queries = [];

        for ($i = 1; $i <= $count; $i++) {
            $queries[] = $this->createTestQuery($queryType, [
                'id' => "test_{$i}",
                'batch_index' => $i,
                'batch_size' => $count,
            ]);
        }

        return $queries;
    }

    /**
     * Assert that batch processing was more efficient than individual processing
     */
    protected function assertBatchEfficiency(
        float $batchTime,
        float $individualTime,
        float $efficiencyThreshold = 0.7
    ): void {
        $efficiency = $batchTime / $individualTime;

        $this->assertLessThan($efficiencyThreshold, $efficiency,
            "Batch processing should be at least " . (1 - $efficiencyThreshold) * 100 . "% more efficient. " .
            "Batch: {$batchTime}s, Individual: {$individualTime}s, Efficiency: " . round($efficiency * 100, 2) . "%"
        );
    }

    /**
     * Verify middleware execution order
     */
    protected function assertMiddlewareExecutionOrder(array $actualOrder, array $expectedOrder): void
    {
        $this->assertEquals($expectedOrder, $actualOrder,
            "Middleware execution order mismatch. Expected: " . implode(' -> ', $expectedOrder) .
            ", Actual: " . implode(' -> ', $actualOrder)
        );
    }

    /**
     * Create test command instance
     */
    protected function createTestCommand(string $type, array $data = []): CommandInterface
    {
        return new class($type, $data) implements CommandInterface {
            public function __construct(
                private string $type,
                private array $data
            ) {}

            public function getCommandName(): string
            {
                return "Test{$this->type}Command";
            }

            public function getCommandId(): string
            {
                return $this->data['id'] ?? uniqid();
            }

            public function getPayload(): array
            {
                return $this->data;
            }

            public function getMetadata(): array
            {
                return [
                    'created_at' => now()->toISOString(),
                    'test_command' => true,
                ];
            }

            public function isAsync(): bool
            {
                return $this->data['async'] ?? false;
            }

            public function getPriority(): int
            {
                return $this->data['priority'] ?? 0;
            }
        };
    }

    /**
     * Create test query instance
     */
    protected function createTestQuery(string $type, array $data = []): QueryInterface
    {
        return new class($type, $data) implements QueryInterface {
            public function __construct(
                private string $type,
                private array $data
            ) {}

            public function getQueryName(): string
            {
                return "Test{$this->type}Query";
            }

            public function getQueryId(): string
            {
                return $this->data['id'] ?? uniqid();
            }

            public function getParameters(): array
            {
                return $this->data;
            }

            public function shouldCache(): bool
            {
                return $this->data['cache'] ?? true;
            }

            public function getCacheKey(): string
            {
                return 'query:' . $this->getQueryName() . ':' . md5(serialize($this->data));
            }

            public function getCacheTags(): array
            {
                return ['queries', $this->type];
            }

            public function getCacheTtl(): int
            {
                return $this->data['cache_ttl'] ?? 3600;
            }

            public function getComplexity(): int
            {
                return $this->data['complexity'] ?? 1;
            }

            public function isPaginated(): bool
            {
                return isset($this->data['page']) || isset($this->data['per_page']);
            }

            public function getPerPage(): int
            {
                return $this->data['per_page'] ?? 20;
            }
        };
    }

    /**
     * Create test aggregate for command/query operations
     */
    protected function createTestAggregate(string $id, array $data = []): array
    {
        return array_merge([
            'id' => $id,
            'name' => 'Test Aggregate',
            'status' => 'active',
            'created_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
        ], $data);
    }

    /**
     * Simulate database transaction failure
     */
    protected function simulateTransactionFailure(): void
    {
        DB::statement('SET foreign_key_checks = 0');
        throw new \Exception('Simulated transaction failure');
    }

    /**
     * Assert transaction rollback occurred
     */
    protected function assertTransactionRolledBack(string $table, string $id): void
    {
        $record = DB::table($table)->where('id', $id)->first();
        $this->assertNull($record, "Record {$id} should not exist after transaction rollback");
    }

    /**
     * Generate load testing data
     */
    protected function generateLoadTestData(int $entityCount): array
    {
        $entities = [];

        for ($i = 1; $i <= $entityCount; $i++) {
            $entities[] = [
                'id' => "load_test_{$i}",
                'name' => "Load Test Entity {$i}",
                'data' => json_encode([
                    'index' => $i,
                    'random' => bin2hex(random_bytes(8)),
                    'created' => now()->toISOString(),
                ]),
            ];
        }

        return $entities;
    }
}