<?php

declare(strict_types=1);

namespace LaravelModularDDD\Tests\Integration\CQRS;

use LaravelModularDDD\Tests\TestCase;
use LaravelModularDDD\CQRS\QueryBus;
use LaravelModularDDD\CQRS\CommandBus;
use LaravelModularDDD\CQRS\Caching\MultiTierCacheManager;
use LaravelModularDDD\CQRS\Security\CommandAuthorizationManager;
use LaravelModularDDD\Tests\Integration\CQRS\Factories\TestCommandFactory;
use LaravelModularDDD\Tests\Integration\CQRS\Factories\TestQueryFactory;
use LaravelModularDDD\Tests\Integration\CQRS\Traits\CQRSTestingTrait;
use LaravelModularDDD\Tests\Integration\CQRS\Handlers\TestCommandHandler;
use LaravelModularDDD\Tests\Integration\CQRS\Handlers\TestQueryHandler;
use LaravelModularDDD\Tests\Integration\CQRS\Handlers\BatchOptimizedQueryHandler;
use LaravelModularDDD\Tests\Integration\CQRS\Commands\TestTransactionalCommand;
use LaravelModularDDD\Tests\Integration\CQRS\Queries\TestCacheableQuery;
use LaravelModularDDD\Tests\Integration\CQRS\Queries\TestBatchQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Integration tests for CQRS (Command Query Responsibility Segregation) functionality.
 *
 * Tests the complete CQRS flow including:
 * - Command processing with middleware chain
 * - Query processing with multi-tier caching
 * - Transaction boundaries and rollback scenarios
 * - Async command processing via queues
 * - Batch query optimization
 * - Cache invalidation strategies
 *
 * @group integration
 * @group cqrs
 */
class CQRSIntegrationTest extends TestCase
{
    use RefreshDatabase, CQRSTestingTrait;

    private CommandBus $commandBus;
    private QueryBus $queryBus;
    private MultiTierCacheManager $cacheManager;
    private TestCommandFactory $commandFactory;
    private TestQueryFactory $queryFactory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCQRSInfrastructure();
        $this->commandFactory = new TestCommandFactory();
        $this->queryFactory = new TestQueryFactory();
    }

    protected function tearDown(): void
    {
        $this->cleanupCQRSData();
        parent::tearDown();
    }

    /**
     * @test
     * @group command-processing
     */
    public function test_it_processes_commands_through_complete_middleware_chain(): void
    {
        // Arrange
        $command = $this->commandFactory->createUpdateUserCommand('user_123', [
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
        ]);

        $handler = new TestCommandHandler();
        $this->commandBus->registerHandler($handler);

        // Act
        $result = $this->commandBus->execute($command);

        // Assert
        $this->assertTrue($result['success']);
        $this->assertEquals('user_123', $result['user_id']);
        $this->assertEquals('Updated Name', $result['data']['name']);

        // Verify middleware was executed
        $this->assertArrayHasKey('middleware_executed', $result);
        $this->assertTrue($result['middleware_executed']);

        // Verify metrics were recorded
        $stats = $this->commandBus->getStatistics();
        $this->assertEquals(1, $stats['total_commands']);
        $this->assertEquals(1, $stats['successful_commands']);
    }

    /**
     * @test
     * @group query-caching
     */
    public function test_it_handles_query_processing_with_multi_tier_caching(): void
    {
        // Arrange
        $query = $this->queryFactory->createUserQuery('user_123');
        $handler = new TestQueryHandler();
        $this->queryBus->registerHandler($handler);

        // Act: First execution (cache miss)
        $startTime = microtime(true);
        $result1 = $this->queryBus->execute($query);
        $firstExecutionTime = microtime(true) - $startTime;

        // Act: Second execution (cache hit)
        $startTime = microtime(true);
        $result2 = $this->queryBus->execute($query);
        $secondExecutionTime = microtime(true) - $startTime;

        // Assert: Results are identical
        $this->assertEquals($result1, $result2);

        // Assert: Second execution is faster (cache hit)
        $this->assertLessThan($firstExecutionTime, $secondExecutionTime);

        // Verify cache statistics
        $stats = $this->queryBus->getStatistics();
        $this->assertEquals(2, $stats['total_queries']);
        $this->assertEquals(1, $stats['cache_hits']);
        $this->assertEquals(1, $stats['cache_misses']);
        $this->assertGreaterThan(0, $stats['cache_performance']['hit_rate']);
    }

    /**
     * @test
     * @group transactions
     */
    public function test_it_handles_transaction_boundaries_and_rollback_scenarios(): void
    {
        // Arrange
        $command = $this->commandFactory->createTransactionalCommand('test_transaction', [
            'operations' => [
                ['type' => 'create', 'data' => ['name' => 'Test 1']],
                ['type' => 'create', 'data' => ['name' => 'Test 2']],
                ['type' => 'fail'], // This should cause rollback
            ],
        ]);

        $handler = new TestCommandHandler();
        $this->commandBus->registerHandler($handler);

        // Act & Assert: Command should fail and rollback
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Simulated failure for testing');

        try {
            $this->commandBus->execute($command);
        } catch (\Exception $e) {
            // Verify transaction was rolled back
            $this->assertEquals(0, DB::table('test_entities')->count());
            throw $e;
        }
    }

    /**
     * @test
     * @group async-commands
     */
    public function test_it_handles_async_command_processing_via_queues(): void
    {
        // Arrange
        Queue::fake();

        $command = $this->commandFactory->createAsyncCommand('async_operation', [
            'data' => 'async processing test',
            'delay_seconds' => 0,
        ]);

        $handler = new TestCommandHandler();
        $this->commandBus->registerHandler($handler);

        // Act: Execute async command
        $result = $this->commandBus->executeAsync($command);

        // Assert: Command was queued
        $this->assertArrayHasKey('job_id', $result);
        Queue::assertPushed(\LaravelModularDDD\CQRS\Jobs\ProcessCommandJob::class);

        // Simulate queue processing
        $handler->handle($command);

        // Verify command was processed
        $this->assertTrue($handler->hasProcessed($command->getCommandId()));
    }

    /**
     * @test
     * @group batch-optimization
     */
    public function test_it_optimizes_batch_query_execution(): void
    {
        // Arrange
        $userIds = ['user_1', 'user_2', 'user_3', 'user_4', 'user_5'];
        $queries = [];

        foreach ($userIds as $userId) {
            $queries[] = $this->queryFactory->createUserQuery($userId);
        }

        $batchHandler = new BatchOptimizedQueryHandler();
        $this->queryBus->registerHandler($batchHandler);

        // Act: Execute batch queries
        $startTime = microtime(true);
        $results = $this->queryBus->executeBatch($queries);
        $batchExecutionTime = microtime(true) - $startTime;

        // Act: Execute individual queries for comparison
        $startTime = microtime(true);
        $individualResults = [];
        foreach ($queries as $key => $query) {
            $individualResults[$key] = $this->queryBus->execute($query);
        }
        $individualExecutionTime = microtime(true) - $startTime;

        // Assert: Batch execution is more efficient
        $this->assertCount(count($userIds), $results);
        $this->assertLessThan($individualExecutionTime * 0.8, $batchExecutionTime);

        // Verify batch handler was used
        $this->assertTrue($batchHandler->wasBatchUsed());
    }

    /**
     * @test
     * @group cache-invalidation
     */
    public function test_it_handles_cache_invalidation_strategies(): void
    {
        // Arrange
        $userId = 'user_123';
        $query = $this->queryFactory->createUserQuery($userId);
        $updateCommand = $this->commandFactory->createUpdateUserCommand($userId, [
            'name' => 'New Name',
        ]);

        $queryHandler = new TestQueryHandler();
        $commandHandler = new TestCommandHandler();

        $this->queryBus->registerHandler($queryHandler);
        $this->commandBus->registerHandler($commandHandler);

        // Act: Execute query to populate cache
        $initialResult = $this->queryBus->execute($query);
        $this->assertTrue($this->cacheManager->has($query));

        // Act: Execute command that should invalidate cache
        $this->commandBus->execute($updateCommand);

        // Assert: Cache should be invalidated
        $this->assertFalse($this->cacheManager->has($query));

        // Execute query again - should hit database, not cache
        $updatedResult = $this->queryBus->execute($query);
        $this->assertNotEquals($initialResult['name'], $updatedResult['name']);
        $this->assertEquals('New Name', $updatedResult['name']);
    }

    /**
     * @test
     * @group command-middleware
     */
    public function test_it_applies_command_middleware_chain_correctly(): void
    {
        // Arrange
        $middlewareResults = [];

        $this->commandBus->addMiddleware(function ($command, $next) use (&$middlewareResults) {
            $middlewareResults[] = 'before_auth';
            $result = $next($command);
            $middlewareResults[] = 'after_auth';
            return $result;
        });

        $this->commandBus->addMiddleware(function ($command, $next) use (&$middlewareResults) {
            $middlewareResults[] = 'before_validation';
            $result = $next($command);
            $middlewareResults[] = 'after_validation';
            return $result;
        });

        $command = $this->commandFactory->createSimpleCommand('test_middleware');
        $handler = new TestCommandHandler();
        $this->commandBus->registerHandler($handler);

        // Act
        $this->commandBus->execute($command);

        // Assert: Middleware executed in correct order
        $expectedOrder = [
            'before_auth',
            'before_validation',
            'after_validation',
            'after_auth',
        ];

        $this->assertEquals($expectedOrder, $middlewareResults);
    }

    /**
     * @test
     * @group query-complexity
     */
    public function test_it_analyzes_query_complexity_and_provides_optimization_suggestions(): void
    {
        // Arrange
        $simpleQuery = $this->queryFactory->createSimpleQuery();
        $complexQuery = $this->queryFactory->createComplexQuery();

        $handler = new TestQueryHandler();
        $this->queryBus->registerHandler($handler);

        // Act
        $simpleAnalysis = $this->queryBus->analyzeQuery($simpleQuery);
        $complexAnalysis = $this->queryBus->analyzeQuery($complexQuery);

        // Assert: Simple query analysis
        $this->assertLessThan(5, $simpleAnalysis['complexity_score']);
        $this->assertEmpty($simpleAnalysis['optimization_suggestions']);

        // Assert: Complex query analysis
        $this->assertGreaterThan(7, $complexAnalysis['complexity_score']);
        $this->assertContains(
            'Consider breaking down into smaller queries',
            $complexAnalysis['optimization_suggestions']
        );
        $this->assertContains(
            'Enable caching for this complex query',
            $complexAnalysis['optimization_suggestions']
        );
    }

    /**
     * @test
     * @group cache-warming
     */
    public function test_it_supports_cache_warming_strategies(): void
    {
        // Arrange
        $queries = [
            $this->queryFactory->createUserQuery('user_1'),
            $this->queryFactory->createUserQuery('user_2'),
            $this->queryFactory->createUserQuery('user_3'),
        ];

        $handler = new TestQueryHandler();
        $this->queryBus->registerHandler($handler);

        // Act: Warm cache with multiple queries
        $results = $this->queryBus->preloadQueries($queries);

        // Assert: All queries cached
        $this->assertCount(3, $results);

        foreach ($queries as $query) {
            $this->assertTrue($this->cacheManager->has($query));
        }

        // Subsequent executions should be cache hits
        foreach ($queries as $query) {
            $startTime = microtime(true);
            $this->queryBus->execute($query);
            $executionTime = microtime(true) - $startTime;

            // Cache hits should be very fast
            $this->assertLessThan(0.01, $executionTime); // Less than 10ms
        }
    }

    /**
     * @test
     * @group error-handling
     */
    public function test_it_handles_command_and_query_errors_gracefully(): void
    {
        // Arrange
        $failingCommand = $this->commandFactory->createFailingCommand();
        $failingQuery = $this->queryFactory->createFailingQuery();

        $handler = new TestCommandHandler();
        $this->commandBus->registerHandler($handler);
        $this->queryBus->registerHandler($handler);

        // Act & Assert: Command error handling
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Simulated command failure');

        try {
            $this->commandBus->execute($failingCommand);
        } catch (\Exception $e) {
            // Verify error metrics
            $commandStats = $this->commandBus->getStatistics();
            $this->assertEquals(1, $commandStats['failed_commands']);
            throw $e;
        }
    }

    /**
     * @test
     * @group performance
     */
    public function test_it_maintains_performance_under_load(): void
    {
        // Arrange
        $commandCount = 50;
        $queryCount = 100;

        $handler = new TestCommandHandler();
        $this->commandBus->registerHandler($handler);
        $this->queryBus->registerHandler($handler);

        // Act: Execute high volume of commands
        $commandStartTime = microtime(true);
        for ($i = 0; $i < $commandCount; $i++) {
            $command = $this->commandFactory->createUpdateUserCommand("user_{$i}", [
                'name' => "User {$i}",
            ]);
            $this->commandBus->execute($command);
        }
        $commandDuration = microtime(true) - $commandStartTime;

        // Act: Execute high volume of queries (some should hit cache)
        $queryStartTime = microtime(true);
        for ($i = 0; $i < $queryCount; $i++) {
            $userId = "user_" . ($i % 25); // Reuse some user IDs for cache hits
            $query = $this->queryFactory->createUserQuery($userId);
            $this->queryBus->execute($query);
        }
        $queryDuration = microtime(true) - $queryStartTime;

        // Assert: Performance is acceptable
        $this->assertLessThan(5.0, $commandDuration); // Commands should complete in under 5 seconds
        $this->assertLessThan(3.0, $queryDuration);   // Queries should be faster due to caching

        // Verify statistics
        $commandStats = $this->commandBus->getStatistics();
        $queryStats = $this->queryBus->getStatistics();

        $this->assertEquals($commandCount, $commandStats['successful_commands']);
        $this->assertEquals($queryCount, $queryStats['total_queries']);
        $this->assertGreaterThan(0, $queryStats['cache_hits']); // Some cache hits expected
    }

    /**
     * @test
     * @group concurrent-access
     */
    public function test_it_handles_concurrent_command_and_query_access(): void
    {
        // Arrange
        $userId = 'concurrent_user';
        $iterations = 10;

        $handler = new TestCommandHandler();
        $this->commandBus->registerHandler($handler);
        $this->queryBus->registerHandler($handler);

        // Act: Simulate concurrent reads and writes
        $processes = [];

        for ($i = 0; $i < $iterations; $i++) {
            // Alternate between commands and queries
            if ($i % 2 === 0) {
                $command = $this->commandFactory->createUpdateUserCommand($userId, [
                    'name' => "Concurrent Update {$i}",
                    'updated_at' => now()->toISOString(),
                ]);
                $processes[] = ['type' => 'command', 'operation' => $command];
            } else {
                $query = $this->queryFactory->createUserQuery($userId);
                $processes[] = ['type' => 'query', 'operation' => $query];
            }
        }

        // Execute all operations
        $results = [];
        foreach ($processes as $process) {
            if ($process['type'] === 'command') {
                $results[] = $this->commandBus->execute($process['operation']);
            } else {
                $results[] = $this->queryBus->execute($process['operation']);
            }
        }

        // Assert: All operations completed successfully
        $this->assertCount($iterations, $results);

        // Verify final state consistency
        $finalQuery = $this->queryFactory->createUserQuery($userId);
        $finalResult = $this->queryBus->execute($finalQuery);
        $this->assertArrayHasKey('name', $finalResult);
        $this->assertStringContains('Concurrent Update', $finalResult['name']);
    }

    private function setUpCQRSInfrastructure(): void
    {
        // Set up cache manager
        $this->cacheManager = new MultiTierCacheManager([
            'default_ttl' => 3600,
            'tiers' => [
                'memory' => ['driver' => 'array', 'ttl' => 300],
                'redis' => ['driver' => 'redis', 'ttl' => 3600],
            ],
        ]);

        // Set up query bus
        $this->queryBus = new QueryBus($this->cacheManager);

        // Set up command bus
        $this->commandBus = new CommandBus();

        // Create test database table
        $this->createTestEntityTable();
    }

    private function createTestEntityTable(): void
    {
        DB::statement('CREATE TABLE IF NOT EXISTS test_entities (
            id VARCHAR(255) PRIMARY KEY,
            name VARCHAR(255),
            data JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )');
    }

    private function cleanupCQRSData(): void
    {
        // Clear caches
        Cache::flush();

        // Clear test data
        DB::table('test_entities')->truncate();

        // Clear statistics
        $this->commandBus->clearStatistics();
        $this->queryBus->clearStatistics();
    }
}