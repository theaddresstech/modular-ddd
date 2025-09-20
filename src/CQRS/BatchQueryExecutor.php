<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS;

use LaravelModularDDD\CQRS\Contracts\QueryBusInterface;
use LaravelModularDDD\CQRS\Contracts\QueryInterface;
use LaravelModularDDD\CQRS\Contracts\BatchQueryInterface;
use LaravelModularDDD\CQRS\Contracts\BatchOptimizableHandlerInterface;
use LaravelModularDDD\CQRS\Caching\MultiTierCacheManager;
use Illuminate\Support\Facades\Log;

class BatchQueryExecutor
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly MultiTierCacheManager $cacheManager
    ) {}

    /**
     * Execute multiple queries efficiently with batch optimization
     *
     * @param QueryInterface[] $queries
     * @return array Results keyed by query identifier
     */
    public function executeBatch(array $queries): array
    {
        if (empty($queries)) {
            return [];
        }

        $startTime = microtime(true);
        $results = [];
        $uncachedQueries = [];

        // Step 1: Check cache for all queries
        foreach ($queries as $key => $query) {
            if ($query->shouldCache()) {
                $cachedResult = $this->cacheManager->get($query);
                if ($cachedResult !== null) {
                    $results[$key] = $cachedResult;
                } else {
                    $uncachedQueries[$key] = $query;
                }
            } else {
                $uncachedQueries[$key] = $query;
            }
        }

        if (empty($uncachedQueries)) {
            Log::debug('All queries served from cache', [
                'total_queries' => count($queries),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);
            return $results;
        }

        // Step 2: Group queries by type for batch optimization
        $queriesByType = [];
        foreach ($uncachedQueries as $key => $query) {
            $queryClass = get_class($query);
            if (!isset($queriesByType[$queryClass])) {
                $queriesByType[$queryClass] = [];
            }
            $queriesByType[$queryClass][$key] = $query;
        }

        // Step 3: Execute queries with batch optimization where possible
        foreach ($queriesByType as $queryClass => $typeQueries) {
            $batchResults = $this->executeBatchByType($typeQueries);
            $results = array_merge($results, $batchResults);
        }

        // Step 4: Cache results
        $this->cacheResults($uncachedQueries, $results);

        Log::debug('Batch query execution completed', [
            'total_queries' => count($queries),
            'cached_queries' => count($queries) - count($uncachedQueries),
            'executed_queries' => count($uncachedQueries),
            'query_types' => count($queriesByType),
            'execution_time_ms' => (microtime(true) - $startTime) * 1000,
        ]);

        return $results;
    }

    /**
     * Execute batch queries that implement BatchQueryInterface
     */
    public function executeBatchQuery(BatchQueryInterface $batchQuery): array
    {
        $startTime = microtime(true);

        // Try cache first
        $cacheKeys = [];
        $cachedResults = [];
        $uncachedIds = [];

        foreach ($batchQuery->getIds() as $id) {
            $individualQuery = $batchQuery->createIndividualQuery($id);
            if ($individualQuery->shouldCache()) {
                $cachedResult = $this->cacheManager->get($individualQuery);
                if ($cachedResult !== null) {
                    $cachedResults[$id] = $cachedResult;
                } else {
                    $uncachedIds[] = $id;
                }
            } else {
                $uncachedIds[] = $id;
            }
        }

        if (empty($uncachedIds)) {
            return $cachedResults;
        }

        // Create batch query for uncached IDs only
        $optimizedBatchQuery = $batchQuery->withIds($uncachedIds);
        $handler = $this->queryBus->getHandler($optimizedBatchQuery);

        $batchResults = $handler->handle($optimizedBatchQuery);

        // Cache individual results
        foreach ($uncachedIds as $id) {
            if (isset($batchResults[$id])) {
                $individualQuery = $batchQuery->createIndividualQuery($id);
                if ($individualQuery->shouldCache()) {
                    $this->cacheManager->put($individualQuery, $batchResults[$id]);
                }
            }
        }

        // Merge cached and batch results
        $allResults = array_merge($cachedResults, $batchResults);

        Log::debug('Batch query executed', [
            'query_type' => get_class($batchQuery),
            'total_ids' => count($batchQuery->getIds()),
            'cached_results' => count($cachedResults),
            'batch_executed' => count($uncachedIds),
            'execution_time_ms' => (microtime(true) - $startTime) * 1000,
        ]);

        return $allResults;
    }

    private function executeBatchByType(array $queries): array
    {
        if (empty($queries)) {
            return [];
        }

        // Get the first query to determine if batch optimization is possible
        $firstQuery = reset($queries);
        $handler = $this->queryBus->getHandler($firstQuery);

        // Check if handler supports batch optimization
        if ($handler instanceof BatchOptimizableHandlerInterface) {
            return $this->executeBatchOptimized($queries, $handler);
        }

        // Fall back to individual execution
        return $this->executeIndividually($queries);
    }

    private function executeBatchOptimized(array $queries, BatchOptimizableHandlerInterface $handler): array
    {
        $startTime = microtime(true);
        $results = $handler->handleBatch(array_values($queries));

        // Map results back to original keys
        $mappedResults = [];
        $keys = array_keys($queries);
        foreach ($results as $index => $result) {
            if (isset($keys[$index])) {
                $mappedResults[$keys[$index]] = $result;
            }
        }

        Log::debug('Batch optimized execution completed', [
            'handler' => get_class($handler),
            'query_count' => count($queries),
            'execution_time_ms' => (microtime(true) - $startTime) * 1000,
        ]);

        return $mappedResults;
    }

    private function executeIndividually(array $queries): array
    {
        $results = [];
        $startTime = microtime(true);

        foreach ($queries as $key => $query) {
            try {
                $results[$key] = $this->queryBus->execute($query);
            } catch (\Exception $e) {
                Log::warning('Individual query execution failed', [
                    'query_key' => $key,
                    'query_class' => get_class($query),
                    'error' => $e->getMessage(),
                ]);

                // Continue with other queries, set null result
                $results[$key] = null;
            }
        }

        Log::debug('Individual query execution completed', [
            'query_count' => count($queries),
            'successful_queries' => count(array_filter($results, fn($r) => $r !== null)),
            'execution_time_ms' => (microtime(true) - $startTime) * 1000,
        ]);

        return $results;
    }

    private function cacheResults(array $queries, array $results): void
    {
        foreach ($queries as $key => $query) {
            if (isset($results[$key]) && $query->shouldCache()) {
                $this->cacheManager->put($query, $results[$key]);
            }
        }
    }

    /**
     * Pre-warm cache for anticipated queries
     *
     * @param QueryInterface[] $queries
     */
    public function preWarmCache(array $queries): void
    {
        $startTime = microtime(true);
        $preWarmed = 0;

        foreach ($queries as $query) {
            if ($query->shouldCache() && !$this->cacheManager->has($query)) {
                try {
                    $result = $this->queryBus->execute($query);
                    if ($result !== null) {
                        $this->cacheManager->put($query, $result);
                        $preWarmed++;
                    }
                } catch (\Exception $e) {
                    Log::warning('Cache pre-warming failed', [
                        'query_class' => get_class($query),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::info('Cache pre-warming completed', [
            'total_queries' => count($queries),
            'pre_warmed' => $preWarmed,
            'execution_time_ms' => (microtime(true) - $startTime) * 1000,
        ]);
    }

    /**
     * Get query execution statistics for optimization
     */
    public function getExecutionStatistics(): array
    {
        // This would typically be implemented with metrics collection
        // For now, return basic stats
        return [
            'total_executions' => 0,
            'cache_hit_rate' => 0.0,
            'average_execution_time_ms' => 0.0,
            'batch_optimization_rate' => 0.0,
        ];
    }
}