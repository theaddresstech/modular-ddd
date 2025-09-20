<?php

declare(strict_types=1);

namespace Tests\Unit\CQRS;

use LaravelModularDDD\Tests\TestCase;
use LaravelModularDDD\CQRS\QueryBus;
use LaravelModularDDD\CQRS\Query;
use LaravelModularDDD\CQRS\Contracts\QueryInterface;
use LaravelModularDDD\CQRS\Contracts\QueryHandlerInterface;
use LaravelModularDDD\CQRS\Contracts\BatchOptimizableHandlerInterface;
use LaravelModularDDD\CQRS\Exceptions\QueryHandlerNotFoundException;
use LaravelModularDDD\CQRS\Caching\MultiTierCacheManager;
use Mockery;

class QueryBusTest extends TestCase
{
    private QueryBus $queryBus;
    private MultiTierCacheManager $cacheManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheManager = Mockery::mock(MultiTierCacheManager::class);
        $this->queryBus = new QueryBus($this->cacheManager);
    }

    /** @test */
    public function it_can_execute_query_with_registered_handler(): void
    {
        $query = new TestQuery('test-param');
        $handler = new TestQueryHandler();
        $expectedResult = 'result for: test-param';

        $this->queryBus->registerHandler($handler);

        // Query is not cacheable
        $query->shouldReceive('shouldCache')->andReturn(false);

        $result = $this->queryBus->execute($query);

        $this->assertEquals($expectedResult, $result);
    }

    /** @test */
    public function it_throws_exception_when_no_handler_found(): void
    {
        $query = new TestQuery('test-param');

        $this->expectException(QueryHandlerNotFoundException::class);

        $this->queryBus->execute($query);
    }

    /** @test */
    public function it_can_check_if_query_can_be_handled(): void
    {
        $query = new TestQuery('test-param');
        $handler = new TestQueryHandler();

        $this->assertFalse($this->queryBus->canHandle($query));

        $this->queryBus->registerHandler($handler);

        $this->assertTrue($this->queryBus->canHandle($query));
    }

    /** @test */
    public function it_uses_cache_for_cacheable_queries(): void
    {
        $query = new CacheableTestQuery('cached-param');
        $handler = new TestQueryHandler();
        $cachedResult = 'cached result';

        $this->queryBus->registerHandler($handler);

        $query->shouldReceive('shouldCache')->andReturn(true);
        $this->cacheManager->shouldReceive('get')
            ->once()
            ->with($query)
            ->andReturn($cachedResult);

        $result = $this->queryBus->execute($query);

        $this->assertEquals($cachedResult, $result);
    }

    /** @test */
    public function it_executes_handler_when_cache_miss(): void
    {
        $query = new CacheableTestQuery('uncached-param');
        $handler = new TestQueryHandler();
        $handlerResult = 'handler result for: uncached-param';

        $this->queryBus->registerHandler($handler);

        $query->shouldReceive('shouldCache')->andReturn(true);
        $this->cacheManager->shouldReceive('get')
            ->once()
            ->with($query)
            ->andReturn(null); // Cache miss

        $this->cacheManager->shouldReceive('put')
            ->once()
            ->with($query, $handlerResult);

        $result = $this->queryBus->execute($query);

        $this->assertEquals($handlerResult, $result);
    }

    /** @test */
    public function it_can_execute_batch_queries(): void
    {
        $query1 = new TestQuery('param1');
        $query2 = new TestQuery('param2');
        $handler = new TestQueryHandler();

        $this->queryBus->registerHandler($handler);

        $query1->shouldReceive('shouldCache')->andReturn(false);
        $query2->shouldReceive('shouldCache')->andReturn(false);

        $results = $this->queryBus->executeBatch([$query1, $query2]);

        $this->assertCount(2, $results);
        $this->assertEquals('result for: param1', $results[0]);
        $this->assertEquals('result for: param2', $results[1]);
    }

    /** @test */
    public function it_uses_batch_optimization_when_available(): void
    {
        $query1 = new TestQuery('param1');
        $query2 = new TestQuery('param2');
        $handler = Mockery::mock(BatchOptimizableHandlerInterface::class);

        $this->queryBus->registerHandler($handler);

        $query1->shouldReceive('shouldCache')->andReturn(false);
        $query2->shouldReceive('shouldCache')->andReturn(false);

        $handler->shouldReceive('getQueryClass')->andReturn(TestQuery::class);
        $handler->shouldReceive('shouldUseBatchOptimization')
            ->once()
            ->with(Mockery::type('array'))
            ->andReturn(true);

        $handler->shouldReceive('handleBatch')
            ->once()
            ->with([$query1, $query2])
            ->andReturn(['batch result 1', 'batch result 2']);

        $results = $this->queryBus->executeBatch([$query1, $query2]);

        $this->assertCount(2, $results);
        $this->assertEquals('batch result 1', $results[0]);
        $this->assertEquals('batch result 2', $results[1]);
    }

    /** @test */
    public function it_can_invalidate_cache_by_tags(): void
    {
        $tags = ['user', 'profile'];

        $this->cacheManager->shouldReceive('invalidateByTags')
            ->once()
            ->with($tags);

        $this->queryBus->invalidateCache($tags);
    }

    /** @test */
    public function it_can_warm_cache_with_query(): void
    {
        $query = new CacheableTestQuery('warm-param');
        $handler = new TestQueryHandler();

        $this->queryBus->registerHandler($handler);

        $query->shouldReceive('shouldCache')->andReturn(true);
        $query->shouldReceive('getQueryName')->andReturn('CacheableTestQuery');
        $query->shouldReceive('getQueryId')->andReturn('warm-param');
        $query->shouldReceive('getCacheKey')->andReturn('cache-key-warm');

        $this->cacheManager->shouldReceive('put')
            ->once()
            ->with($query, 'result for: warm-param');

        $this->queryBus->warmCache($query);
    }

    /** @test */
    public function it_handles_batch_queries_with_mixed_cache_status(): void
    {
        $cachedQuery = new CacheableTestQuery('cached');
        $uncachedQuery = new TestQuery('uncached');
        $handler = new TestQueryHandler();

        $this->queryBus->registerHandler($handler);

        $cachedQuery->shouldReceive('shouldCache')->andReturn(true);
        $uncachedQuery->shouldReceive('shouldCache')->andReturn(false);

        $this->cacheManager->shouldReceive('get')
            ->once()
            ->with($cachedQuery)
            ->andReturn('cached result');

        $results = $this->queryBus->executeBatch([$cachedQuery, $uncachedQuery]);

        $this->assertCount(2, $results);
        $this->assertEquals('cached result', $results[0]);
        $this->assertEquals('result for: uncached', $results[1]);
    }

    /** @test */
    public function it_returns_empty_array_for_empty_batch(): void
    {
        $results = $this->queryBus->executeBatch([]);

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }
}

// Test implementations
class TestQuery extends Query
{
    public function __construct(private string $param)
    {
        parent::__construct();
    }

    public function getParam(): string
    {
        return $this->param;
    }

    public function getQueryName(): string
    {
        return 'TestQuery';
    }

    public function getCacheKey(): string
    {
        return 'test_query_' . $this->param;
    }

    public function getCacheTtl(): int
    {
        return 3600;
    }

    public function getCacheTags(): array
    {
        return ['test'];
    }

    public function shouldCache(): bool
    {
        return false;
    }
}

class CacheableTestQuery extends TestQuery
{
    public function shouldCache(): bool
    {
        return true;
    }
}

class TestQueryHandler implements QueryHandlerInterface
{
    public function handle(QueryInterface $query): mixed
    {
        if ($query instanceof TestQuery) {
            return 'result for: ' . $query->getParam();
        }

        throw new \InvalidArgumentException('Unsupported query type');
    }

    public function getHandledQueryType(): string
    {
        return TestQuery::class;
    }

    public function canHandle(QueryInterface $query): bool
    {
        return $query instanceof TestQuery;
    }

    public function getEstimatedExecutionTime(QueryInterface $query): int
    {
        return 100; // 100ms
    }
}