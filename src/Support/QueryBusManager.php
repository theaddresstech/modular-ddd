<?php

declare(strict_types=1);

namespace LaravelModularDDD\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Cache\Repository as CacheRepository;

/**
 * QueryBusManager
 *
 * Manages CQRS query bus operations across modules.
 * Handles query routing, handler registration, caching, and middleware.
 */
final class QueryBusManager
{
    private array $handlers = [];
    private array $middleware = [];
    private array $cacheConfig = [];
    private array $routes = [];

    public function __construct(
        private readonly Application $app
    ) {}

    /**
     * Register a query handler.
     */
    public function registerHandler(string $query, string $handler, array $cacheConfig = []): void
    {
        $this->handlers[$query] = $handler;
        $this->routes[$query] = $handler;

        if (!empty($cacheConfig)) {
            $this->cacheConfig[$query] = $cacheConfig;
        }
    }

    /**
     * Register multiple handlers.
     */
    public function registerHandlers(array $handlers): void
    {
        foreach ($handlers as $query => $config) {
            if (is_string($config)) {
                $this->registerHandler($query, $config);
            } elseif (is_array($config)) {
                $handler = $config['handler'] ?? $config[0] ?? null;
                $cacheConfig = $config['cache'] ?? [];

                if ($handler) {
                    $this->registerHandler($query, $handler, $cacheConfig);
                }
            }
        }
    }

    /**
     * Get handler for a query.
     */
    public function getHandler(string $query): ?string
    {
        return $this->handlers[$query] ?? null;
    }

    /**
     * Check if query has a registered handler.
     */
    public function hasHandler(string $query): bool
    {
        return isset($this->handlers[$query]);
    }

    /**
     * Get all registered handlers.
     */
    public function getHandlers(): array
    {
        return $this->handlers;
    }

    /**
     * Register global middleware.
     */
    public function addMiddleware(string $middleware): void
    {
        if (!in_array($middleware, $this->middleware, true)) {
            $this->middleware[] = $middleware;
        }
    }

    /**
     * Register middleware for specific query.
     */
    public function addQueryMiddleware(string $query, string $middleware): void
    {
        if (!isset($this->middleware[$query])) {
            $this->middleware[$query] = [];
        }

        if (!in_array($middleware, $this->middleware[$query], true)) {
            $this->middleware[$query][] = $middleware;
        }
    }

    /**
     * Get middleware for query.
     */
    public function getMiddleware(string $query): array
    {
        $global = array_filter($this->middleware, 'is_string');
        $specific = $this->middleware[$query] ?? [];

        return array_merge($global, $specific);
    }

    /**
     * Set cache configuration for query.
     */
    public function setCacheConfig(string $query, array $config): void
    {
        $this->cacheConfig[$query] = $config;
    }

    /**
     * Get cache configuration for query.
     */
    public function getCacheConfig(string $query): array
    {
        return $this->cacheConfig[$query] ?? [];
    }

    /**
     * Dispatch a query.
     */
    public function dispatch(object $query): mixed
    {
        $queryClass = get_class($query);
        $handler = $this->getHandler($queryClass);

        if (!$handler) {
            throw new \InvalidArgumentException("No handler registered for query: {$queryClass}");
        }

        $cacheConfig = $this->getCacheConfig($queryClass);

        if (!empty($cacheConfig) && $this->shouldCache($query, $cacheConfig)) {
            return $this->executeWithCache($query, $handler, $cacheConfig);
        }

        return $this->executeQuery($query, $handler);
    }

    /**
     * Execute query without caching.
     */
    private function executeQuery(object $query, string $handler): mixed
    {
        $handlerInstance = $this->app->make($handler);
        $middleware = $this->getMiddleware(get_class($query));

        return $this->executeWithMiddleware($query, $handlerInstance, $middleware);
    }

    /**
     * Execute query with caching.
     */
    private function executeWithCache(object $query, string $handler, array $cacheConfig): mixed
    {
        $cache = $this->app->make(CacheRepository::class);
        $cacheKey = $this->generateCacheKey($query, $cacheConfig);
        $ttl = $cacheConfig['ttl'] ?? 3600;

        if ($cache->has($cacheKey)) {
            return $cache->get($cacheKey);
        }

        $result = $this->executeQuery($query, $handler);

        $cache->put($cacheKey, $result, $ttl);

        return $result;
    }

    /**
     * Execute query with middleware pipeline.
     */
    private function executeWithMiddleware(object $query, object $handler, array $middleware): mixed
    {
        $pipeline = array_reduce(
            array_reverse($middleware),
            function ($carry, $middlewareClass) {
                return function ($query) use ($carry, $middlewareClass) {
                    $middleware = $this->app->make($middlewareClass);
                    return $middleware->handle($query, $carry);
                };
            },
            function ($query) use ($handler) {
                return $handler->handle($query);
            }
        );

        return $pipeline($query);
    }

    /**
     * Check if query should be cached.
     */
    private function shouldCache(object $query, array $cacheConfig): bool
    {
        if (!($cacheConfig['enabled'] ?? true)) {
            return false;
        }

        // Check if query has cache invalidation conditions
        if (isset($cacheConfig['conditions'])) {
            foreach ($cacheConfig['conditions'] as $condition) {
                if (!$this->evaluateCondition($query, $condition)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Generate cache key for query.
     */
    private function generateCacheKey(object $query, array $cacheConfig): string
    {
        $prefix = $cacheConfig['prefix'] ?? 'query';
        $queryClass = get_class($query);
        $queryData = serialize($query);

        $key = $prefix . ':' . md5($queryClass . $queryData);

        if (isset($cacheConfig['tags'])) {
            $tags = implode(':', $cacheConfig['tags']);
            $key = $prefix . ':' . $tags . ':' . md5($queryClass . $queryData);
        }

        return $key;
    }

    /**
     * Evaluate cache condition.
     */
    private function evaluateCondition(object $query, array $condition): bool
    {
        // Simple condition evaluation
        // In a real implementation, this could be more sophisticated
        $property = $condition['property'] ?? null;
        $operator = $condition['operator'] ?? '=';
        $value = $condition['value'] ?? null;

        if (!$property || !property_exists($query, $property)) {
            return true;
        }

        $queryValue = $query->{$property};

        return match ($operator) {
            '=' => $queryValue === $value,
            '!=' => $queryValue !== $value,
            '>' => $queryValue > $value,
            '<' => $queryValue < $value,
            '>=' => $queryValue >= $value,
            '<=' => $queryValue <= $value,
            'in' => in_array($queryValue, (array) $value, true),
            'not_in' => !in_array($queryValue, (array) $value, true),
            default => true,
        };
    }

    /**
     * Invalidate cache for specific tags.
     */
    public function invalidateCache(array $tags = []): void
    {
        $cache = $this->app->make(CacheRepository::class);

        if (empty($tags)) {
            // Clear all query cache
            $cache->flush();
            return;
        }

        // In a real implementation with tagged cache support:
        // $cache->tags($tags)->flush();

        // For basic cache implementation, we'd need to track keys by tags
        foreach ($this->cacheConfig as $query => $config) {
            $queryTags = $config['tags'] ?? [];
            if (array_intersect($tags, $queryTags)) {
                $this->invalidateQueryCache($query);
            }
        }
    }

    /**
     * Invalidate cache for specific query.
     */
    public function invalidateQueryCache(string $queryClass): void
    {
        $cache = $this->app->make(CacheRepository::class);
        $cacheConfig = $this->getCacheConfig($queryClass);

        if (empty($cacheConfig)) {
            return;
        }

        $prefix = $cacheConfig['prefix'] ?? 'query';

        // Remove all keys with this prefix
        // Note: This is a simplified implementation
        // In production, you'd want more sophisticated cache key tracking
        $cache->forget("{$prefix}:*");
    }

    /**
     * Auto-discover query handlers in modules.
     */
    public function discoverHandlers(): void
    {
        $registry = $this->app->make(ModuleRegistry::class);
        $modules = $registry->getEnabledModules();

        foreach ($modules as $module) {
            $this->discoverModuleHandlers($module);
        }
    }

    /**
     * Discover handlers in a specific module.
     */
    private function discoverModuleHandlers(array $module): void
    {
        $moduleName = $module['name'];
        $queryHandlers = $module['components']['handlers']['query_handlers'] ?? [];

        foreach ($queryHandlers as $handlerInfo) {
            $handlerClass = $handlerInfo['class'] ?? null;

            if (!$handlerClass || !class_exists($handlerClass)) {
                continue;
            }

            $queryClass = $this->extractQueryFromHandler($handlerClass);

            if ($queryClass && class_exists($queryClass)) {
                // Check for cache configuration in module config
                $cacheConfig = $this->getModuleQueryCacheConfig($moduleName, $queryClass);
                $this->registerHandler($queryClass, $handlerClass, $cacheConfig);
            }
        }
    }

    /**
     * Extract query class from handler.
     */
    private function extractQueryFromHandler(string $handlerClass): ?string
    {
        // Try to determine query from handler name
        // e.g., GetUserQueryHandler -> GetUserQuery
        if (preg_match('/(.+)Handler$/', $handlerClass, $matches)) {
            $queryName = $matches[1];
            $queryClass = str_replace('\\Queries\\Handlers\\', '\\Queries\\', $handlerClass);
            $queryClass = str_replace($handlerClass, $queryName, $queryClass);

            return $queryClass;
        }

        // Try reflection to find handle method parameter type
        try {
            $reflection = new \ReflectionClass($handlerClass);
            $handleMethod = $reflection->getMethod('handle');
            $parameters = $handleMethod->getParameters();

            if (count($parameters) > 0) {
                $firstParam = $parameters[0];
                $type = $firstParam->getType();

                if ($type instanceof \ReflectionNamedType) {
                    return $type->getName();
                }
            }
        } catch (\ReflectionException $e) {
            // Ignore reflection errors
        }

        return null;
    }

    /**
     * Get module-specific query cache configuration.
     */
    private function getModuleQueryCacheConfig(string $moduleName, string $queryClass): array
    {
        $config = config("modular-monolith.modules.{$moduleName}.queries.cache", []);
        $queryName = class_basename($queryClass);

        return $config[$queryName] ?? $config['default'] ?? [];
    }

    /**
     * Get query bus statistics.
     */
    public function getStatistics(): array
    {
        return [
            'total_handlers' => count($this->handlers),
            'cached_queries' => count($this->cacheConfig),
            'global_middleware' => count(array_filter($this->middleware, 'is_string')),
            'queries_with_specific_middleware' => count(array_filter($this->middleware, 'is_array')),
            'modules_with_handlers' => $this->getModulesWithHandlers(),
        ];
    }

    /**
     * Get modules that have query handlers.
     */
    private function getModulesWithHandlers(): int
    {
        $modules = [];

        foreach ($this->handlers as $query => $handler) {
            if (preg_match('/Modules\\\\(\w+)\\\\/', $handler, $matches)) {
                $modules[$matches[1]] = true;
            }
        }

        return count($modules);
    }

    /**
     * Validate query bus configuration.
     */
    public function validate(): array
    {
        $issues = [];

        // Check for handlers without corresponding queries
        foreach ($this->handlers as $query => $handler) {
            if (!class_exists($query)) {
                $issues[] = [
                    'type' => 'missing_query',
                    'message' => "Query class does not exist: {$query}",
                    'handler' => $handler,
                ];
            }

            if (!class_exists($handler)) {
                $issues[] = [
                    'type' => 'missing_handler',
                    'message' => "Handler class does not exist: {$handler}",
                    'query' => $query,
                ];
            }
        }

        // Check cache configuration
        foreach ($this->cacheConfig as $query => $config) {
            if (isset($config['ttl']) && (!is_int($config['ttl']) || $config['ttl'] < 0)) {
                $issues[] = [
                    'type' => 'invalid_cache_ttl',
                    'message' => "Invalid cache TTL for query: {$query}",
                    'ttl' => $config['ttl'],
                ];
            }
        }

        return $issues;
    }

    /**
     * Export query bus configuration.
     */
    public function export(): array
    {
        return [
            'handlers' => $this->handlers,
            'middleware' => $this->middleware,
            'cache_config' => $this->cacheConfig,
            'routes' => $this->routes,
            'statistics' => $this->getStatistics(),
            'validation_issues' => $this->validate(),
        ];
    }

    /**
     * Clear all registered handlers and middleware.
     */
    public function clear(): void
    {
        $this->handlers = [];
        $this->middleware = [];
        $this->cacheConfig = [];
        $this->routes = [];
    }
}