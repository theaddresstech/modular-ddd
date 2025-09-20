<?php

declare(strict_types=1);

namespace Tests\Unit\CQRS;

use LaravelModularDDD\Tests\TestCase;
use LaravelModularDDD\CQRS\Query\RealQueryBus;
use LaravelModularDDD\CQRS\Query;
use LaravelModularDDD\CQRS\Contracts\QueryInterface;
use LaravelModularDDD\CQRS\Contracts\QueryHandlerInterface;
use LaravelModularDDD\CQRS\Contracts\QueryBusInterface;
use LaravelModularDDD\CQRS\Exceptions\QueryNotFoundException;
use LaravelModularDDD\CQRS\Exceptions\QueryValidationException;
use LaravelModularDDD\CQRS\Exceptions\QueryAuthorizationException;
use LaravelModularDDD\CQRS\Middleware\QueryAuthorizationMiddleware;
use LaravelModularDDD\CQRS\Middleware\QueryCacheMiddleware;
use LaravelModularDDD\CQRS\Middleware\QueryValidationMiddleware;
use LaravelModularDDD\CQRS\Middleware\QueryPerformanceMiddleware;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;

/**
 * Test suite for Real Query Bus implementation.
 *
 * This validates that queries are processed correctly with caching,
 * authorization, validation, and performance monitoring.
 */
class RealQueryBusTest extends TestCase
{
    private QueryBusInterface $queryBus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queryBus = new RealQueryBus();

        // Setup middleware chain
        $this->queryBus->addMiddleware(new QueryPerformanceMiddleware());
        $this->queryBus->addMiddleware(new QueryAuthorizationMiddleware());
        $this->queryBus->addMiddleware(new QueryValidationMiddleware());
        $this->queryBus->addMiddleware(new QueryCacheMiddleware());
    }

    /** @test */
    public function it_can_handle_basic_query_successfully(): void
    {
        // Arrange
        $query = new TestRealQuery('user-123');
        $handler = new TestRealQueryHandler();
        $this->queryBus->registerHandler(TestRealQuery::class, $handler);

        // Act
        $result = $this->queryBus->handle($query);

        // Assert
        $this->assertEquals([
            'id' => 'user-123',
            'name' => 'John Doe',
            'email' => 'john@example.com'
        ], $result);
    }

    /** @test */
    public function it_throws_exception_for_unregistered_query(): void
    {
        // Arrange
        $query = new TestRealQuery('user-123');

        // Act & Assert
        $this->expectException(QueryNotFoundException::class);
        $this->queryBus->handle($query);
    }

    /** @test */
    public function it_validates_queries_through_middleware(): void
    {
        // Arrange
        $query = new TestInvalidQuery(''); // Empty ID should fail validation
        $handler = new TestInvalidQueryHandler();
        $this->queryBus->registerHandler(TestInvalidQuery::class, $handler);

        // Act & Assert
        $this->expectException(QueryValidationException::class);
        $this->queryBus->handle($query);
    }

    /** @test */
    public function it_caches_query_results_correctly(): void
    {
        // Arrange
        $query = new TestCacheableQuery('cached-user-123');
        $handler = new TestCacheableQueryHandler();
        $this->queryBus->registerHandler(TestCacheableQuery::class, $handler);

        // Act - First call should hit handler
        $result1 = $this->queryBus->handle($query);

        // Act - Second call should use cache
        $result2 = $this->queryBus->handle($query);

        // Assert
        $this->assertEquals($result1, $result2);
        $this->assertEquals(1, $handler->getCallCount()); // Handler called only once

        // Verify cache contains the result
        $cacheKey = 'query:' . md5(serialize($query));
        $this->assertTrue(Cache::has($cacheKey));
    }

    /** @test */
    public function it_respects_cache_ttl_configuration(): void
    {
        // Arrange
        $query = new TestShortCacheQuery('short-cache-123');
        $handler = new TestShortCacheQueryHandler();
        $this->queryBus->registerHandler(TestShortCacheQuery::class, $handler);

        // Act
        $result = $this->queryBus->handle($query);

        // Assert
        $cacheKey = 'query:' . md5(serialize($query));
        $this->assertTrue(Cache::has($cacheKey));

        // Sleep longer than TTL and verify cache is gone
        sleep(2);
        $this->assertFalse(Cache::has($cacheKey));
    }

    /** @test */
    public function it_bypasses_cache_for_non_cacheable_queries(): void
    {
        // Arrange
        $query = new TestNonCacheableQuery('non-cacheable-123');
        $handler = new TestNonCacheableQueryHandler();
        $this->queryBus->registerHandler(TestNonCacheableQuery::class, $handler);

        // Act
        $result1 = $this->queryBus->handle($query);
        $result2 = $this->queryBus->handle($query);

        // Assert
        $this->assertEquals(2, $handler->getCallCount()); // Handler called twice

        // Verify no cache entry exists
        $cacheKey = 'query:' . md5(serialize($query));
        $this->assertFalse(Cache::has($cacheKey));
    }

    /** @test */
    public function it_handles_query_authorization_correctly(): void
    {
        // Arrange
        $query = new TestAuthorizedQuery('secure-data-123');
        $handler = new TestAuthorizedQueryHandler();
        $this->queryBus->registerHandler(TestAuthorizedQuery::class, $handler);

        // Mock authentication
        $user = new \stdClass();
        $user->id = 'admin-user';
        $user->role = 'admin';
        Auth::shouldReceive('user')->andReturn($user);

        // Act
        $result = $this->queryBus->handle($query);

        // Assert
        $this->assertNotNull($result);
    }

    /** @test */
    public function it_throws_authorization_exception_for_unauthorized_queries(): void
    {
        // Arrange
        $query = new TestAuthorizedQuery('secure-data-123');
        $handler = new TestAuthorizedQueryHandler();
        $this->queryBus->registerHandler(TestAuthorizedQuery::class, $handler);

        // Mock unauthorized user
        $user = new \stdClass();
        $user->id = 'regular-user';
        $user->role = 'user';
        Auth::shouldReceive('user')->andReturn($user);

        // Act & Assert
        $this->expectException(QueryAuthorizationException::class);
        $this->queryBus->handle($query);
    }

    /** @test */
    public function it_monitors_query_performance(): void
    {
        // Arrange
        $query = new TestSlowQuery('slow-query-123');
        $handler = new TestSlowQueryHandler();
        $this->queryBus->registerHandler(TestSlowQuery::class, $handler);

        // Act
        $start = microtime(true);
        $result = $this->queryBus->handle($query);
        $executionTime = (microtime(true) - $start) * 1000;

        // Assert
        $this->assertGreaterThan(100, $executionTime); // Should take at least 100ms
        $this->assertNotNull($result);
    }

    /** @test */
    public function it_handles_multiple_queries_efficiently(): void
    {
        // Arrange
        $handler = new TestBulkQueryHandler();
        $this->queryBus->registerHandler(TestBulkQuery::class, $handler);

        $queries = [];
        for ($i = 1; $i <= 100; $i++) {
            $queries[] = new TestBulkQuery("bulk-item-{$i}");
        }

        // Act & Assert
        $this->assertExecutionTimeWithinLimits(function () use ($queries) {
            foreach ($queries as $query) {
                $result = $this->queryBus->handle($query);
                $this->assertNotNull($result);
            }
        }, 2000); // 100 queries should complete within 2 seconds
    }

    /** @test */
    public function it_handles_complex_nested_queries(): void
    {
        // Arrange
        $query = new TestNestedQuery('parent-123', ['child-1', 'child-2', 'child-3']);
        $handler = new TestNestedQueryHandler();
        $this->queryBus->registerHandler(TestNestedQuery::class, $handler);

        // Act
        $result = $this->queryBus->handle($query);

        // Assert
        $this->assertArrayHasKey('parent', $result);
        $this->assertArrayHasKey('children', $result);
        $this->assertCount(3, $result['children']);
    }

    /** @test */
    public function it_preserves_query_metadata_through_middleware(): void
    {
        // Arrange
        $query = new TestMetadataQuery('metadata-test-123');
        $handler = new TestMetadataQueryHandler();
        $this->queryBus->registerHandler(TestMetadataQuery::class, $handler);

        // Act
        $result = $this->queryBus->handle($query);

        // Assert
        $this->assertArrayHasKey('query_metadata', $result);
        $this->assertEquals('test-value', $result['query_metadata']['test_key']);
    }

    /** @test */
    public function it_handles_query_dependencies_correctly(): void
    {
        // Arrange
        $query = new TestDependentQuery('dependent-123');
        $handler = new TestDependentQueryHandler();
        $this->queryBus->registerHandler(TestDependentQuery::class, $handler);

        // Act
        $result = $this->queryBus->handle($query);

        // Assert
        $this->assertArrayHasKey('main_data', $result);
        $this->assertArrayHasKey('dependency_data', $result);
    }

    /** @test */
    public function it_performs_efficiently_with_large_result_sets(): void
    {
        // Arrange
        $query = new TestLargeResultQuery('large-result-123', 10000);
        $handler = new TestLargeResultQueryHandler();
        $this->queryBus->registerHandler(TestLargeResultQuery::class, $handler);

        // Act & Assert
        $this->assertExecutionTimeWithinLimits(function () use ($query) {
            $result = $this->queryBus->handle($query);
            $this->assertCount(10000, $result['items']);
        }, 3000); // Large result set should complete within 3 seconds

        $this->assertMemoryUsageWithinLimits(64); // Should not exceed 64MB
    }

    /** @test */
    public function it_handles_concurrent_cache_access(): void
    {
        // Arrange
        $query = new TestConcurrentQuery('concurrent-123');
        $handler = new TestConcurrentQueryHandler();
        $this->queryBus->registerHandler(TestConcurrentQuery::class, $handler);

        // Act - Simulate concurrent access
        $results = [];
        for ($i = 0; $i < 10; $i++) {
            $results[] = $this->queryBus->handle($query);
        }

        // Assert
        $this->assertEquals(1, $handler->getCallCount()); // Handler called only once due to caching

        foreach ($results as $result) {
            $this->assertEquals($results[0], $result); // All results should be identical
        }
    }

    /** @test */
    public function it_invalidates_cache_when_needed(): void
    {
        // Arrange
        $query = new TestInvalidatableQuery('invalidatable-123');
        $handler = new TestInvalidatableQueryHandler();
        $this->queryBus->registerHandler(TestInvalidatableQuery::class, $handler);

        // Act
        $result1 = $this->queryBus->handle($query);

        // Invalidate cache
        $cacheKey = 'query:' . md5(serialize($query));
        Cache::forget($cacheKey);

        $result2 = $this->queryBus->handle($query);

        // Assert
        $this->assertEquals(2, $handler->getCallCount()); // Handler called twice
    }
}

// Test Query Classes
class TestRealQuery extends Query
{
    public function __construct(private string $userId)
    {
        parent::__construct();
    }

    public function getUserId(): string { return $this->userId; }
    public function validate(): array { return $this->userId ? [] : ['User ID is required']; }
    public function getCacheKey(): string { return "user:{$this->userId}"; }
    public function getCacheTtl(): int { return 300; }
    public function authorize($user): bool { return true; }
}

class TestRealQueryHandler implements QueryHandlerInterface
{
    public function handle(QueryInterface $query): array
    {
        return [
            'id' => $query->getUserId(),
            'name' => 'John Doe',
            'email' => 'john@example.com'
        ];
    }

    public function getHandledQueryType(): string
    {
        return TestRealQuery::class;
    }

    public function canHandle(QueryInterface $query): bool
    {
        return $query instanceof TestRealQuery;
    }

    public function getEstimatedExecutionTime(QueryInterface $query): int
    {
        return 150; // 150ms
    }
}

class TestInvalidQuery extends Query
{
    public function __construct(private string $userId)
    {
        parent::__construct();
    }

    public function getUserId(): string { return $this->userId; }
    public function validate(): array { return $this->userId ? [] : ['User ID is required']; }
    public function getCacheKey(): string { return parent::getCacheKey() . ':invalid:' . $this->userId; }
    public function shouldCache(): bool { return false; }
    public function authorize($user): bool { return true; }
}

class TestInvalidQueryHandler implements QueryHandlerInterface
{
    public function handle(QueryInterface $query): array
    {
        return ['id' => $query->getUserId()];
    }

    public function getHandledQueryType(): string
    {
        return TestInvalidQuery::class;
    }

    public function canHandle(QueryInterface $query): bool
    {
        return $query instanceof TestInvalidQuery;
    }

    public function getEstimatedExecutionTime(QueryInterface $query): int
    {
        return 100; // 100ms
    }
}

class TestCacheableQuery extends Query
{
    public function __construct(private string $userId)
    {
        parent::__construct();
    }

    public function getUserId(): string { return $this->userId; }
    public function validate(): array { return []; }
    public function getCacheKey(): string { return "cacheable_user:{$this->userId}"; }
    public function getCacheTtl(): int { return 300; }
    public function authorize($user): bool { return true; }
}

class TestCacheableQueryHandler implements QueryHandlerInterface
{
    private int $callCount = 0;

    public function handle(QueryInterface $query): array
    {
        $this->callCount++;
        return ['id' => $query->getUserId(), 'cached' => true];
    }

    public function getCallCount(): int { return $this->callCount; }

    public function getHandledQueryType(): string
    {
        return TestCacheableQuery::class;
    }

    public function canHandle(QueryInterface $query): bool
    {
        return $query instanceof TestCacheableQuery;
    }

    public function getEstimatedExecutionTime(QueryInterface $query): int
    {
        return 200; // 200ms
    }
}

class TestShortCacheQuery extends Query
{
    public function __construct(private string $userId)
    {
        parent::__construct();
    }

    public function getUserId(): string { return $this->userId; }
    public function validate(): array { return []; }
    public function getCacheKey(): string { return "short_cache_user:{$this->userId}"; }
    public function getCacheTtl(): int { return 1; } // 1 second TTL
    public function authorize($user): bool { return true; }
}

class TestShortCacheQueryHandler implements QueryHandlerInterface
{
    public function handle(QueryInterface $query): array
    {
        return ['id' => $query->getUserId(), 'short_cached' => true];
    }

    public function getHandledQueryType(): string
    {
        return TestShortCacheQuery::class;
    }

    public function canHandle(QueryInterface $query): bool
    {
        return $query instanceof TestShortCacheQuery;
    }

    public function getEstimatedExecutionTime(QueryInterface $query): int
    {
        return 150; // 150ms
    }
}

class TestNonCacheableQuery extends Query
{
    public function __construct(private string $userId)
    {
        parent::__construct();
    }

    public function getUserId(): string { return $this->userId; }
    public function validate(): array { return []; }
    public function shouldCache(): bool { return false; } // No caching
    public function authorize($user): bool { return true; }
}

class TestNonCacheableQueryHandler implements QueryHandlerInterface
{
    private int $callCount = 0;

    public function handle(QueryInterface $query): array
    {
        $this->callCount++;
        return ['id' => $query->getUserId(), 'non_cached' => true, 'call' => $this->callCount];
    }

    public function getCallCount(): int { return $this->callCount; }

    public function getHandledQueryType(): string
    {
        return TestNonCacheableQuery::class;
    }

    public function canHandle(QueryInterface $query): bool
    {
        return $query instanceof TestNonCacheableQuery;
    }

    public function getEstimatedExecutionTime(QueryInterface $query): int
    {
        return 180; // 180ms
    }
}

class TestAuthorizedQuery extends Query
{
    public function __construct(private string $dataId)
    {
        parent::__construct();
    }

    public function getDataId(): string { return $this->dataId; }
    public function validate(): array { return []; }
    public function getCacheKey(): string { return "secure_data:{$this->dataId}"; }
    public function getCacheTtl(): int { return 300; }
    public function authorize($user): bool { return $user && $user->role === 'admin'; }
}

class TestAuthorizedQueryHandler implements QueryHandlerInterface
{
    public function handle(QueryInterface $query): array
    {
        return ['id' => $query->getDataId(), 'sensitive_data' => 'classified'];
    }

    public function getHandledQueryType(): string
    {
        return TestAuthorizedQuery::class;
    }

    public function canHandle(QueryInterface $query): bool
    {
        return $query instanceof TestAuthorizedQuery;
    }

    public function getEstimatedExecutionTime(QueryInterface $query): int
    {
        return 250; // 250ms for authorization checks
    }
}

class TestSlowQuery extends Query
{
    public function __construct(private string $id)
    {
        parent::__construct();
    }

    public function getId(): string { return $this->id; }
    public function validate(): array { return []; }
    public function shouldCache(): bool { return false; }
    public function authorize($user): bool { return true; }
}

class TestSlowQueryHandler implements QueryHandlerInterface
{
    public function handle(QueryInterface $query): array
    {
        usleep(100000); // 100ms delay
        return ['id' => $query->getId(), 'slow_result' => true];
    }

    public function getHandledQueryType(): string
    {
        return TestSlowQuery::class;
    }

    public function canHandle(QueryInterface $query): bool
    {
        return $query instanceof TestSlowQuery;
    }

    public function getEstimatedExecutionTime(QueryInterface $query): int
    {
        return 1000; // 1000ms (1 second) for slow operations
    }
}

class TestBulkQuery extends Query
{
    public function __construct(private string $itemId)
    {
        parent::__construct();
    }

    public function getItemId(): string { return $this->itemId; }
    public function validate(): array { return []; }
    public function getCacheKey(): string { return "bulk_item:{$this->itemId}"; }
    public function getCacheTtl(): int { return 60; }
    public function authorize($user): bool { return true; }
}

class TestBulkQueryHandler implements QueryHandlerInterface
{
    public function handle(QueryInterface $query): array
    {
        return ['id' => $query->getItemId(), 'bulk_processed' => true];
    }

    public function getHandledQueryType(): string
    {
        return TestBulkQuery::class;
    }

    public function canHandle(QueryInterface $query): bool
    {
        return $query instanceof TestBulkQuery;
    }

    public function getEstimatedExecutionTime(QueryInterface $query): int
    {
        return 50; // 50ms for bulk operations
    }
}

class TestNestedQuery extends Query
{
    public function __construct(private string $parentId, private array $childIds)
    {
        parent::__construct();
    }

    public function getParentId(): string { return $this->parentId; }
    public function getChildIds(): array { return $this->childIds; }
    public function validate(): array { return []; }
    public function getCacheKey(): string { return "nested:{$this->parentId}"; }
    public function getCacheTtl(): int { return 300; }
    public function authorize($user): bool { return true; }
}

class TestNestedQueryHandler implements QueryHandlerInterface
{
    public function handle(QueryInterface $query): array
    {
        $children = [];
        foreach ($query->getChildIds() as $childId) {
            $children[] = ['id' => $childId, 'name' => "Child {$childId}"];
        }

        return [
            'parent' => ['id' => $query->getParentId(), 'name' => 'Parent'],
            'children' => $children
        ];
    }

    public function getHandledQueryType(): string
    {
        return TestNestedQuery::class;
    }

    public function canHandle(QueryInterface $query): bool
    {
        return $query instanceof TestNestedQuery;
    }

    public function getEstimatedExecutionTime(QueryInterface $query): int
    {
        return 300 + (count($query->getChildIds()) * 50); // 300ms base + 50ms per child
    }
}

class TestMetadataQuery extends Query
{
    public function __construct(private string $id)
    {
        parent::__construct();
    }

    public function getId(): string { return $this->id; }
    public function validate(): array { return []; }
    public function getCacheKey(): string { return "metadata:{$this->id}"; }
    public function getCacheTtl(): int { return 300; }
    public function authorize($user): bool { return true; }
    public function getMetadata(): array { return ['test_key' => 'test_value']; }
}

class TestMetadataQueryHandler implements QueryHandlerInterface
{
    public function handle(QueryInterface $query): array
    {
        return [
            'id' => $query->getId(),
            'query_metadata' => $query->getMetadata()
        ];
    }

    public function getHandledQueryType(): string
    {
        return TestMetadataQuery::class;
    }

    public function canHandle(QueryInterface $query): bool
    {
        return $query instanceof TestMetadataQuery;
    }

    public function getEstimatedExecutionTime(QueryInterface $query): int
    {
        return 120; // 120ms for metadata processing
    }
}

class TestDependentQuery extends Query
{
    public function __construct(private string $id)
    {
        parent::__construct();
    }

    public function getId(): string { return $this->id; }
    public function validate(): array { return []; }
    public function getCacheKey(): string { return "dependent:{$this->id}"; }
    public function getCacheTtl(): int { return 300; }
    public function authorize($user): bool { return true; }
}

class TestDependentQueryHandler implements QueryHandlerInterface
{
    public function handle(QueryInterface $query): array
    {
        // Simulate dependency resolution
        $dependencyData = $this->resolveDependency($query->getId());

        return [
            'main_data' => ['id' => $query->getId(), 'name' => 'Main Item'],
            'dependency_data' => $dependencyData
        ];
    }

    private function resolveDependency(string $id): array
    {
        return ['dependency_id' => "dep-{$id}", 'resolved' => true];
    }

    public function getHandledQueryType(): string
    {
        return TestDependentQuery::class;
    }

    public function canHandle(QueryInterface $query): bool
    {
        return $query instanceof TestDependentQuery;
    }

    public function getEstimatedExecutionTime(QueryInterface $query): int
    {
        return 400; // 400ms for dependency resolution
    }
}

class TestLargeResultQuery extends Query
{
    public function __construct(private string $id, private int $itemCount)
    {
        parent::__construct();
    }

    public function getId(): string { return $this->id; }
    public function getItemCount(): int { return $this->itemCount; }
    public function validate(): array { return []; }
    public function shouldCache(): bool { return false; } // Don't cache large results
    public function authorize($user): bool { return true; }
}

class TestLargeResultQueryHandler implements QueryHandlerInterface
{
    public function handle(QueryInterface $query): array
    {
        $items = [];
        for ($i = 1; $i <= $query->getItemCount(); $i++) {
            $items[] = ['id' => "item-{$i}", 'data' => str_repeat('x', 100)];
        }

        return ['items' => $items];
    }

    public function getHandledQueryType(): string
    {
        return TestLargeResultQuery::class;
    }

    public function canHandle(QueryInterface $query): bool
    {
        return $query instanceof TestLargeResultQuery;
    }

    public function getEstimatedExecutionTime(QueryInterface $query): int
    {
        return 1000 + ($query->getItemCount() / 100); // 1000ms base + 1ms per 100 items
    }
}

class TestConcurrentQuery extends Query
{
    public function __construct(private string $id)
    {
        parent::__construct();
    }

    public function getId(): string { return $this->id; }
    public function validate(): array { return []; }
    public function getCacheKey(): string { return "concurrent:{$this->id}"; }
    public function getCacheTtl(): int { return 300; }
    public function authorize($user): bool { return true; }
}

class TestConcurrentQueryHandler implements QueryHandlerInterface
{
    private static int $callCount = 0;

    public function handle(QueryInterface $query): array
    {
        self::$callCount++;
        usleep(50000); // 50ms delay to simulate processing
        return ['id' => $query->getId(), 'concurrent_result' => true];
    }

    public function getCallCount(): int { return self::$callCount; }

    public function getHandledQueryType(): string
    {
        return TestConcurrentQuery::class;
    }

    public function canHandle(QueryInterface $query): bool
    {
        return $query instanceof TestConcurrentQuery;
    }

    public function getEstimatedExecutionTime(QueryInterface $query): int
    {
        return 200; // 200ms for concurrent operations
    }
}

class TestInvalidatableQuery extends Query
{
    public function __construct(private string $id)
    {
        parent::__construct();
    }

    public function getId(): string { return $this->id; }
    public function validate(): array { return []; }
    public function getCacheKey(): string { return "invalidatable:{$this->id}"; }
    public function getCacheTtl(): int { return 300; }
    public function authorize($user): bool { return true; }
}

class TestInvalidatableQueryHandler implements QueryHandlerInterface
{
    private int $callCount = 0;

    public function handle(QueryInterface $query): array
    {
        $this->callCount++;
        return ['id' => $query->getId(), 'invalidatable_result' => true, 'call' => $this->callCount];
    }

    public function getCallCount(): int { return $this->callCount; }

    public function getHandledQueryType(): string
    {
        return TestInvalidatableQuery::class;
    }

    public function canHandle(QueryInterface $query): bool
    {
        return $query instanceof TestInvalidatableQuery;
    }

    public function getEstimatedExecutionTime(QueryInterface $query): int
    {
        return 160; // 160ms for invalidatable operations
    }
}