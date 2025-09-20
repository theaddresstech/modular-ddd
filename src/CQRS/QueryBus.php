<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS;

use LaravelModularDDD\CQRS\Contracts\QueryBusInterface;
use LaravelModularDDD\CQRS\Contracts\QueryInterface;
use LaravelModularDDD\CQRS\Contracts\QueryHandlerInterface;
use LaravelModularDDD\CQRS\Exceptions\QueryHandlerNotFoundException;
use LaravelModularDDD\CQRS\Caching\MultiTierCacheManager;
use LaravelModularDDD\CQRS\Security\CommandAuthorizationManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class QueryBus implements QueryBusInterface
{
    /** @var QueryHandlerInterface[] */
    private array $handlers = [];

    private const HANDLER_CACHE_TTL = 3600;
    private const METRICS_CACHE_TTL = 300;

    private ?CommandAuthorizationManager $authManager = null;

    public function __construct(
        private readonly MultiTierCacheManager $cacheManager,
        ?CommandAuthorizationManager $authManager = null
    ) {
        $this->authManager = $authManager;
    }

    public function execute(QueryInterface $query): mixed
    {
        $startTime = microtime(true);

        try {
            // Authorization check
            if ($this->authManager) {
                $this->authManager->authorizeQuery($query);
            }

            // Try to get result from cache first
            if ($query->shouldCache()) {
                $cachedResult = $this->cacheManager->get($query);
                if ($cachedResult !== null) {
                    $this->recordMetrics($query, microtime(true) - $startTime, true, true);
                    return $cachedResult;
                }
            }

            // Execute query
            $handler = $this->getHandler($query);
            $result = $handler->handle($query);

            // Cache the result
            if ($query->shouldCache() && $result !== null) {
                $this->cacheManager->put($query, $result);
            }

            $this->recordMetrics($query, microtime(true) - $startTime, true, false);

            return $result;
        } catch (\Exception $e) {
            $this->recordMetrics($query, microtime(true) - $startTime, false, false, $e);
            throw $e;
        }
    }

    public function registerHandler(QueryHandlerInterface $handler): void
    {
        $queryType = $handler->getHandledQueryType();

        if (!isset($this->handlers[$queryType])) {
            $this->handlers[$queryType] = [];
        }

        $this->handlers[$queryType][] = $handler;

        // Clear handler cache
        Cache::forget("query_handler:{$queryType}");
    }

    public function getHandler(QueryInterface $query): QueryHandlerInterface
    {
        $queryType = $query->getQueryName();
        $cacheKey = "query_handler:{$queryType}";

        return Cache::remember($cacheKey, self::HANDLER_CACHE_TTL, function () use ($query, $queryType) {
            if (!isset($this->handlers[$queryType])) {
                throw new QueryHandlerNotFoundException($queryType);
            }

            // Find the best handler for this query
            $bestHandler = null;
            $shortestExecutionTime = PHP_INT_MAX;

            foreach ($this->handlers[$queryType] as $handler) {
                if ($handler->canHandle($query)) {
                    $estimatedTime = $handler->getEstimatedExecutionTime($query);
                    if ($estimatedTime < $shortestExecutionTime) {
                        $shortestExecutionTime = $estimatedTime;
                        $bestHandler = $handler;
                    }
                }
            }

            if (!$bestHandler) {
                throw new QueryHandlerNotFoundException($queryType);
            }

            return $bestHandler;
        });
    }

    public function canHandle(QueryInterface $query): bool
    {
        try {
            $this->getHandler($query);
            return true;
        } catch (QueryHandlerNotFoundException) {
            return false;
        }
    }

    public function invalidateCache(array $tags): void
    {
        $this->cacheManager->invalidateTags($tags);

        Log::info('Cache invalidated for tags', [
            'tags' => $tags,
        ]);
    }

    public function warmCache(QueryInterface $query): void
    {
        if (!$query->shouldCache()) {
            return;
        }

        // Check if already cached
        if ($this->cacheManager->has($query)) {
            return;
        }

        try {
            $result = $this->execute($query);

            Log::info('Cache warmed for query', [
                'query_type' => $query->getQueryName(),
                'query_id' => $query->getQueryId(),
                'cache_key' => $query->getCacheKey(),
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to warm cache for query', [
                'query_type' => $query->getQueryName(),
                'query_id' => $query->getQueryId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

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
            Log::debug('All batch queries served from cache', [
                'total_queries' => count($queries),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);
            return $results;
        }

        // Step 2: Group queries by type for potential batch optimization
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
        foreach ($uncachedQueries as $key => $query) {
            if (isset($results[$key]) && $query->shouldCache()) {
                $this->cacheManager->put($query, $results[$key]);
            }
        }

        Log::debug('Batch query execution completed', [
            'total_queries' => count($queries),
            'cached_queries' => count($queries) - count($uncachedQueries),
            'executed_queries' => count($uncachedQueries),
            'query_types' => count($queriesByType),
            'execution_time_ms' => (microtime(true) - $startTime) * 1000,
        ]);

        return $results;
    }

    private function executeBatchByType(array $queries): array
    {
        if (empty($queries)) {
            return [];
        }

        // Get the first query to determine if batch optimization is possible
        $firstQuery = reset($queries);
        $handler = $this->getHandler($firstQuery);

        // Check if handler supports batch optimization
        if ($handler instanceof \LaravelModularDDD\CQRS\Contracts\BatchOptimizableHandlerInterface) {
            return $this->executeBatchOptimized($queries, $handler);
        }

        // Fall back to individual execution
        return $this->executeIndividually($queries);
    }

    private function executeBatchOptimized(array $queries, $handler): array
    {
        $startTime = microtime(true);

        if ($handler->shouldUseBatchOptimization(array_values($queries))) {
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

        return $this->executeIndividually($queries);
    }

    private function executeIndividually(array $queries): array
    {
        $results = [];
        $startTime = microtime(true);
        $successCount = 0;

        foreach ($queries as $key => $query) {
            $queryStartTime = microtime(true);
            try {
                $results[$key] = $this->execute($query);
                $this->recordMetrics($query, microtime(true) - $queryStartTime, true, false);
                $successCount++;
            } catch (\Exception $e) {
                Log::warning('Individual query execution failed in batch', [
                    'query_key' => $key,
                    'query_class' => get_class($query),
                    'error' => $e->getMessage(),
                ]);

                $this->recordMetrics($query, microtime(true) - $queryStartTime, false, false);
                $results[$key] = null;
            }
        }

        Log::debug('Individual query batch execution completed', [
            'query_count' => count($queries),
            'successful_queries' => $successCount,
            'execution_time_ms' => (microtime(true) - $startTime) * 1000,
        ]);

        return $results;
    }

    /**
     * Get query processing statistics
     */
    public function getStatistics(): array
    {
        $stats = Cache::get('query_bus_stats', [
            'total_queries' => 0,
            'successful_queries' => 0,
            'failed_queries' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'avg_execution_time_ms' => 0,
            'queries_by_type' => [],
            'cache_performance' => [
                'hit_rate' => 0,
                'avg_hit_time_ms' => 0,
                'avg_miss_time_ms' => 0,
            ],
        ]);

        $totalQueries = $stats['total_queries'];

        $stats['success_rate'] = $totalQueries > 0
            ? ($stats['successful_queries'] / $totalQueries) * 100
            : 0;

        $stats['cache_performance']['hit_rate'] = $totalQueries > 0
            ? ($stats['cache_hits'] / $totalQueries) * 100
            : 0;

        return $stats;
    }

    /**
     * Clear all query bus statistics
     */
    public function clearStatistics(): void
    {
        Cache::forget('query_bus_stats');
    }

    /**
     * Analyze query complexity and suggest optimizations
     */
    public function analyzeQuery(QueryInterface $query): array
    {
        $analysis = [
            'complexity_score' => $query->getComplexity(),
            'estimated_execution_time_ms' => 0,
            'cache_recommendation' => $this->getCacheRecommendation($query),
            'optimization_suggestions' => [],
        ];

        try {
            $handler = $this->getHandler($query);
            $analysis['estimated_execution_time_ms'] = $handler->getEstimatedExecutionTime($query);
        } catch (QueryHandlerNotFoundException) {
            $analysis['optimization_suggestions'][] = 'No handler found for this query type';
        }

        // Add optimization suggestions based on complexity
        if ($query->getComplexity() > 7) {
            $analysis['optimization_suggestions'][] = 'Consider breaking down into smaller queries';
            $analysis['optimization_suggestions'][] = 'Enable caching for this complex query';
        }

        if ($query->isPaginated() && $query->getPerPage() > 100) {
            $analysis['optimization_suggestions'][] = 'Consider reducing page size for better performance';
        }

        if (!$query->shouldCache() && $query->getComplexity() > 5) {
            $analysis['optimization_suggestions'][] = 'Enable caching for this complex query';
        }

        return $analysis;
    }

    /**
     * Preload multiple queries for better performance
     */
    public function preloadQueries(array $queries): array
    {
        $results = [];

        foreach ($queries as $query) {
            if (!$query instanceof QueryInterface) {
                continue;
            }

            try {
                $results[$query->getQueryId()] = $this->execute($query);
            } catch (\Exception $e) {
                Log::error('Failed to preload query', [
                    'query_type' => $query->getQueryName(),
                    'query_id' => $query->getQueryId(),
                    'error' => $e->getMessage(),
                ]);

                $results[$query->getQueryId()] = null;
            }
        }

        return $results;
    }

    private function getCacheRecommendation(QueryInterface $query): string
    {
        if (!$query->shouldCache()) {
            if ($query->getComplexity() > 5) {
                return 'enable_caching_for_complex_query';
            }
            return 'caching_disabled';
        }

        if ($query->getCacheTtl() < 60 && $query->getComplexity() > 7) {
            return 'increase_cache_ttl';
        }

        if ($query->getCacheTtl() > 3600 && $query->getComplexity() < 3) {
            return 'decrease_cache_ttl';
        }

        return 'optimal';
    }

    private function recordMetrics(
        QueryInterface $query,
        float $executionTime,
        bool $success,
        bool $fromCache,
        ?\Exception $exception = null
    ): void {
        $stats = Cache::get('query_bus_stats', [
            'total_queries' => 0,
            'successful_queries' => 0,
            'failed_queries' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'avg_execution_time_ms' => 0,
            'queries_by_type' => [],
            'cache_performance' => [
                'hit_rate' => 0,
                'avg_hit_time_ms' => 0,
                'avg_miss_time_ms' => 0,
            ],
        ]);

        $queryType = $query->getQueryName();
        $executionTimeMs = $executionTime * 1000;

        // Update counters
        $stats['total_queries']++;
        if ($success) {
            $stats['successful_queries']++;
        } else {
            $stats['failed_queries']++;
        }

        if ($fromCache) {
            $stats['cache_hits']++;
        } else {
            $stats['cache_misses']++;
        }

        // Update average execution time
        $totalTime = $stats['avg_execution_time_ms'] * ($stats['total_queries'] - 1);
        $stats['avg_execution_time_ms'] = ($totalTime + $executionTimeMs) / $stats['total_queries'];

        // Update cache performance metrics
        if ($fromCache) {
            $cacheHits = $stats['cache_hits'];
            $totalHitTime = $stats['cache_performance']['avg_hit_time_ms'] * ($cacheHits - 1);
            $stats['cache_performance']['avg_hit_time_ms'] = ($totalHitTime + $executionTimeMs) / $cacheHits;
        } else {
            $cacheMisses = $stats['cache_misses'];
            $totalMissTime = $stats['cache_performance']['avg_miss_time_ms'] * ($cacheMisses - 1);
            $stats['cache_performance']['avg_miss_time_ms'] = ($totalMissTime + $executionTimeMs) / $cacheMisses;
        }

        // Update query type stats
        if (!isset($stats['queries_by_type'][$queryType])) {
            $stats['queries_by_type'][$queryType] = [
                'count' => 0,
                'success_count' => 0,
                'cache_hits' => 0,
                'avg_time_ms' => 0,
            ];
        }

        $typeStats = &$stats['queries_by_type'][$queryType];
        $typeStats['count']++;
        if ($success) {
            $typeStats['success_count']++;
        }
        if ($fromCache) {
            $typeStats['cache_hits']++;
        }

        $typeTotalTime = $typeStats['avg_time_ms'] * ($typeStats['count'] - 1);
        $typeStats['avg_time_ms'] = ($typeTotalTime + $executionTimeMs) / $typeStats['count'];

        Cache::put('query_bus_stats', $stats, now()->addSeconds(self::METRICS_CACHE_TTL));

        // Log detailed metrics
        Log::info('Query executed', [
            'query_id' => $query->getQueryId(),
            'query_type' => $queryType,
            'execution_time_ms' => round($executionTimeMs, 2),
            'success' => $success,
            'from_cache' => $fromCache,
            'complexity' => $query->getComplexity(),
            'error' => $exception?->getMessage(),
        ]);
    }
}