<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Monitoring;

use LaravelModularDDD\CQRS\Contracts\CommandInterface;
use LaravelModularDDD\CQRS\Contracts\QueryInterface;
use Closure;

class MonitoringMiddleware
{
    public function __construct(
        private readonly PerformanceMonitor $performanceMonitor
    ) {}

    /**
     * Handle command execution with monitoring
     */
    public function handleCommand(CommandInterface $command, Closure $next): mixed
    {
        $operationId = $this->performanceMonitor->startCommandExecution($command);
        $successful = true;
        $result = null;

        try {
            $result = $next($command);
            return $result;
        } catch (\Throwable $e) {
            $successful = false;
            throw $e;
        } finally {
            $this->performanceMonitor->endCommandExecution($operationId, $successful, [
                'result_type' => $result ? gettype($result) : null,
            ]);
        }
    }

    /**
     * Handle query execution with monitoring
     */
    public function handleQuery(QueryInterface $query, Closure $next): mixed
    {
        $operationId = $this->performanceMonitor->startQueryExecution($query);
        $cacheHit = false;
        $result = null;

        try {
            $result = $next($query);

            // Try to determine if this was a cache hit
            $cacheHit = $this->detectCacheHit($result);

            return $result;
        } finally {
            $this->performanceMonitor->endQueryExecution($operationId, $cacheHit, [
                'result_type' => $result ? gettype($result) : null,
                'result_size' => $this->estimateResultSize($result),
            ]);
        }
    }

    private function detectCacheHit(mixed $result): bool
    {
        // This is a heuristic approach - in practice, you'd need cache-specific logic
        // or metadata from the query bus to accurately determine cache hits

        // Check if result has cache metadata
        if (is_array($result) && isset($result['_cache_hit'])) {
            return $result['_cache_hit'];
        }

        // Check for cache wrapper objects
        if (is_object($result) && method_exists($result, 'isCacheHit')) {
            return $result->isCacheHit();
        }

        // Default to false if we can't determine
        return false;
    }

    private function estimateResultSize(mixed $result): int
    {
        if ($result === null) {
            return 0;
        }

        if (is_string($result)) {
            return strlen($result);
        }

        if (is_array($result)) {
            return count($result);
        }

        if (is_object($result)) {
            // Rough estimation based on serialized size
            return strlen(serialize($result));
        }

        return 0;
    }
}