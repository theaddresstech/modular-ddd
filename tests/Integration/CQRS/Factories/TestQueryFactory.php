<?php

declare(strict_types=1);

namespace LaravelModularDDD\Tests\Integration\CQRS\Factories;

use LaravelModularDDD\CQRS\Contracts\QueryInterface;
use Ramsey\Uuid\Uuid;

/**
 * Factory for creating test queries for CQRS integration tests.
 */
class TestQueryFactory
{
    /**
     * Create a simple user query
     */
    public function createUserQuery(string $userId): QueryInterface
    {
        return new class($userId) implements QueryInterface {
            public function __construct(private string $userId) {}

            public function getQueryName(): string
            {
                return 'UserQuery';
            }

            public function getQueryId(): string
            {
                return 'user_query_' . $this->userId;
            }

            public function getParameters(): array
            {
                return ['user_id' => $this->userId];
            }

            public function shouldCache(): bool
            {
                return true;
            }

            public function getCacheKey(): string
            {
                return "user:{$this->userId}";
            }

            public function getCacheTags(): array
            {
                return ['users', "user:{$this->userId}"];
            }

            public function getCacheTtl(): int
            {
                return 3600; // 1 hour
            }

            public function getComplexity(): int
            {
                return 2;
            }

            public function isPaginated(): bool
            {
                return false;
            }

            public function getPerPage(): int
            {
                return 20;
            }

            public function getUserId(): string
            {
                return $this->userId;
            }
        };
    }

    /**
     * Create a simple query for basic testing
     */
    public function createSimpleQuery(): QueryInterface
    {
        return new class() implements QueryInterface {
            public function getQueryName(): string
            {
                return 'SimpleQuery';
            }

            public function getQueryId(): string
            {
                return 'simple_' . Uuid::uuid4()->toString();
            }

            public function getParameters(): array
            {
                return ['simple' => true];
            }

            public function shouldCache(): bool
            {
                return true;
            }

            public function getCacheKey(): string
            {
                return 'simple_query';
            }

            public function getCacheTags(): array
            {
                return ['simple', 'test'];
            }

            public function getCacheTtl(): int
            {
                return 300; // 5 minutes
            }

            public function getComplexity(): int
            {
                return 1;
            }

            public function isPaginated(): bool
            {
                return false;
            }

            public function getPerPage(): int
            {
                return 20;
            }
        };
    }

    /**
     * Create a complex query for optimization testing
     */
    public function createComplexQuery(): QueryInterface
    {
        return new class() implements QueryInterface {
            public function getQueryName(): string
            {
                return 'ComplexQuery';
            }

            public function getQueryId(): string
            {
                return 'complex_' . Uuid::uuid4()->toString();
            }

            public function getParameters(): array
            {
                return [
                    'filters' => [
                        'status' => ['active', 'pending'],
                        'created_after' => '2023-01-01',
                        'tags' => ['important', 'urgent'],
                    ],
                    'joins' => ['profiles', 'permissions', 'activities'],
                    'aggregations' => ['count', 'sum', 'avg'],
                    'complex_logic' => true,
                ];
            }

            public function shouldCache(): bool
            {
                return false; // Complex queries might not be cacheable by default
            }

            public function getCacheKey(): string
            {
                return 'complex_query_' . md5(serialize($this->getParameters()));
            }

            public function getCacheTags(): array
            {
                return ['complex', 'analytics', 'heavy'];
            }

            public function getCacheTtl(): int
            {
                return 1800; // 30 minutes for complex queries
            }

            public function getComplexity(): int
            {
                return 9; // High complexity
            }

            public function isPaginated(): bool
            {
                return true;
            }

            public function getPerPage(): int
            {
                return 50; // Larger page size for complex queries
            }
        };
    }

    /**
     * Create a cacheable query for cache testing
     */
    public function createCacheableQuery(string $entityId, int $cacheTtl = 3600): QueryInterface
    {
        return new class($entityId, $cacheTtl) implements QueryInterface {
            public function __construct(
                private string $entityId,
                private int $cacheTtl
            ) {}

            public function getQueryName(): string
            {
                return 'CacheableQuery';
            }

            public function getQueryId(): string
            {
                return 'cacheable_' . $this->entityId;
            }

            public function getParameters(): array
            {
                return ['entity_id' => $this->entityId];
            }

            public function shouldCache(): bool
            {
                return true;
            }

            public function getCacheKey(): string
            {
                return "entity:{$this->entityId}";
            }

            public function getCacheTags(): array
            {
                return ['entities', "entity:{$this->entityId}"];
            }

            public function getCacheTtl(): int
            {
                return $this->cacheTtl;
            }

            public function getComplexity(): int
            {
                return 3;
            }

            public function isPaginated(): bool
            {
                return false;
            }

            public function getPerPage(): int
            {
                return 20;
            }

            public function getEntityId(): string
            {
                return $this->entityId;
            }
        };
    }

    /**
     * Create a batch query for batch optimization testing
     */
    public function createBatchQuery(array $ids): QueryInterface
    {
        return new class($ids) implements QueryInterface {
            public function __construct(private array $ids) {}

            public function getQueryName(): string
            {
                return 'BatchQuery';
            }

            public function getQueryId(): string
            {
                return 'batch_' . md5(implode(',', $this->ids));
            }

            public function getParameters(): array
            {
                return [
                    'ids' => $this->ids,
                    'batch_size' => count($this->ids),
                ];
            }

            public function shouldCache(): bool
            {
                return true;
            }

            public function getCacheKey(): string
            {
                return 'batch_' . md5(serialize($this->ids));
            }

            public function getCacheTags(): array
            {
                return ['batch', 'entities'];
            }

            public function getCacheTtl(): int
            {
                return 1800; // 30 minutes for batch results
            }

            public function getComplexity(): int
            {
                return min(10, count($this->ids)); // Complexity scales with batch size
            }

            public function isPaginated(): bool
            {
                return false; // Batch queries return all requested items
            }

            public function getPerPage(): int
            {
                return count($this->ids);
            }

            public function getIds(): array
            {
                return $this->ids;
            }

            public function getBatchSize(): int
            {
                return count($this->ids);
            }

            public function supportsBatchOptimization(): bool
            {
                return true;
            }
        };
    }

    /**
     * Create a failing query for error handling tests
     */
    public function createFailingQuery(): QueryInterface
    {
        return new class() implements QueryInterface {
            public function getQueryName(): string
            {
                return 'FailingQuery';
            }

            public function getQueryId(): string
            {
                return 'failing_' . Uuid::uuid4()->toString();
            }

            public function getParameters(): array
            {
                return ['should_fail' => true];
            }

            public function shouldCache(): bool
            {
                return false; // Don't cache failing queries
            }

            public function getCacheKey(): string
            {
                return 'failing_query';
            }

            public function getCacheTags(): array
            {
                return ['failing', 'test'];
            }

            public function getCacheTtl(): int
            {
                return 0;
            }

            public function getComplexity(): int
            {
                return 1;
            }

            public function isPaginated(): bool
            {
                return false;
            }

            public function getPerPage(): int
            {
                return 20;
            }
        };
    }

    /**
     * Create a paginated query for pagination testing
     */
    public function createPaginatedQuery(int $page = 1, int $perPage = 20, array $filters = []): QueryInterface
    {
        return new class($page, $perPage, $filters) implements QueryInterface {
            public function __construct(
                private int $page,
                private int $perPage,
                private array $filters
            ) {}

            public function getQueryName(): string
            {
                return 'PaginatedQuery';
            }

            public function getQueryId(): string
            {
                return 'paginated_' . md5(serialize([$this->page, $this->perPage, $this->filters]));
            }

            public function getParameters(): array
            {
                return [
                    'page' => $this->page,
                    'per_page' => $this->perPage,
                    'filters' => $this->filters,
                ];
            }

            public function shouldCache(): bool
            {
                return true;
            }

            public function getCacheKey(): string
            {
                return "paginated:{$this->page}:{$this->perPage}:" . md5(serialize($this->filters));
            }

            public function getCacheTags(): array
            {
                return ['paginated', 'listings'];
            }

            public function getCacheTtl(): int
            {
                return 600; // 10 minutes for paginated results
            }

            public function getComplexity(): int
            {
                return 2 + count($this->filters); // Complexity increases with filters
            }

            public function isPaginated(): bool
            {
                return true;
            }

            public function getPerPage(): int
            {
                return $this->perPage;
            }

            public function getPage(): int
            {
                return $this->page;
            }

            public function getFilters(): array
            {
                return $this->filters;
            }
        };
    }

    /**
     * Create an analytics query for heavy computation testing
     */
    public function createAnalyticsQuery(array $parameters): QueryInterface
    {
        return new class($parameters) implements QueryInterface {
            public function __construct(private array $parameters) {}

            public function getQueryName(): string
            {
                return 'AnalyticsQuery';
            }

            public function getQueryId(): string
            {
                return 'analytics_' . md5(serialize($this->parameters));
            }

            public function getParameters(): array
            {
                return $this->parameters;
            }

            public function shouldCache(): bool
            {
                return true; // Analytics results should be cached
            }

            public function getCacheKey(): string
            {
                return 'analytics_' . md5(serialize($this->parameters));
            }

            public function getCacheTags(): array
            {
                return ['analytics', 'reports', 'heavy'];
            }

            public function getCacheTtl(): int
            {
                return 7200; // 2 hours for analytics
            }

            public function getComplexity(): int
            {
                return 8; // Analytics queries are typically complex
            }

            public function isPaginated(): bool
            {
                return isset($this->parameters['page']);
            }

            public function getPerPage(): int
            {
                return $this->parameters['per_page'] ?? 100;
            }

            public function getDateRange(): array
            {
                return [
                    'start' => $this->parameters['start_date'] ?? null,
                    'end' => $this->parameters['end_date'] ?? null,
                ];
            }

            public function getMetrics(): array
            {
                return $this->parameters['metrics'] ?? [];
            }

            public function getGroupBy(): array
            {
                return $this->parameters['group_by'] ?? [];
            }
        };
    }

    /**
     * Create a real-time query that shouldn't be cached
     */
    public function createRealTimeQuery(string $resourceId): QueryInterface
    {
        return new class($resourceId) implements QueryInterface {
            public function __construct(private string $resourceId) {}

            public function getQueryName(): string
            {
                return 'RealTimeQuery';
            }

            public function getQueryId(): string
            {
                return 'realtime_' . $this->resourceId . '_' . microtime(true);
            }

            public function getParameters(): array
            {
                return [
                    'resource_id' => $this->resourceId,
                    'realtime' => true,
                    'timestamp' => microtime(true),
                ];
            }

            public function shouldCache(): bool
            {
                return false; // Real-time data shouldn't be cached
            }

            public function getCacheKey(): string
            {
                return 'realtime_' . $this->resourceId;
            }

            public function getCacheTags(): array
            {
                return ['realtime', 'live'];
            }

            public function getCacheTtl(): int
            {
                return 0;
            }

            public function getComplexity(): int
            {
                return 5; // Real-time queries can be moderately complex
            }

            public function isPaginated(): bool
            {
                return false;
            }

            public function getPerPage(): int
            {
                return 20;
            }

            public function getResourceId(): string
            {
                return $this->resourceId;
            }

            public function isRealTime(): bool
            {
                return true;
            }
        };
    }
}