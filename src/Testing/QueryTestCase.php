<?php

declare(strict_types=1);

namespace LaravelModularDDD\Testing;

use LaravelModularDDD\Testing\ModuleTestCase;

/**
 * QueryTestCase
 *
 * Base test case for testing CQRS queries and their handlers.
 * Provides utilities for query validation, execution, and result testing.
 */
abstract class QueryTestCase extends ModuleTestCase
{
    protected string $queryClass;
    protected string $handlerClass;

    /**
     * Set up query testing environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->queryClass) {
            throw new \RuntimeException('Query class must be defined in test class');
        }

        if (!class_exists($this->queryClass)) {
            throw new \RuntimeException("Query class {$this->queryClass} does not exist");
        }

        if ($this->handlerClass && !class_exists($this->handlerClass)) {
            throw new \RuntimeException("Handler class {$this->handlerClass} does not exist");
        }

        $this->setUpQueryHandler();
        $this->setUpTestData();
    }

    /**
     * Set up query handler if specified.
     */
    protected function setUpQueryHandler(): void
    {
        if ($this->handlerClass) {
            $this->queryBus->registerHandler($this->queryClass, $this->handlerClass);
        }
    }

    /**
     * Test query creation with valid data.
     */
    public function test_query_can_be_created_with_valid_data(): void
    {
        $validData = $this->getValidQueryData();
        $query = $this->createQueryWithData($validData);

        $this->assertInstanceOf($this->queryClass, $query);
        $this->assertQueryIsValid($query);
    }

    /**
     * Test query creation fails with invalid data.
     */
    public function test_query_creation_fails_with_invalid_data(): void
    {
        $invalidDataSets = $this->getInvalidQueryDataSets();

        foreach ($invalidDataSets as $description => $invalidData) {
            try {
                $query = $this->createQueryWithData($invalidData);
                $this->assertQueryIsInvalid($query, $description);
            } catch (\Exception $e) {
                $this->assertInstanceOf(\InvalidArgumentException::class, $e);
            }
        }
    }

    /**
     * Test query execution returns expected results.
     */
    public function test_query_execution_returns_expected_results(): void
    {
        if (!$this->handlerClass) {
            $this->markTestSkipped('No handler class specified for query testing');
        }

        $testCases = $this->getQueryTestCases();

        foreach ($testCases as $testName => $testCase) {
            $this->setUpTestCaseData($testCase);
            $query = $this->createQueryWithData($testCase['query_data']);
            $expectedResult = $testCase['expected_result'];

            $result = $this->dispatchQuery($query);

            $this->assertQueryResult($result, $expectedResult, $testName);
        }
    }

    /**
     * Test query with pagination.
     */
    public function test_query_pagination(): void
    {
        if (!$this->supportsPagination()) {
            $this->markTestSkipped('Query does not support pagination');
        }

        $this->setUpPaginationTestData();

        // Test first page
        $firstPageQuery = $this->createPaginatedQuery(1, 10);
        $firstPageResult = $this->dispatchQuery($firstPageQuery);
        $this->assertPaginatedResult($firstPageResult, 1, 10);

        // Test second page
        $secondPageQuery = $this->createPaginatedQuery(2, 10);
        $secondPageResult = $this->dispatchQuery($secondPageQuery);
        $this->assertPaginatedResult($secondPageResult, 2, 10);

        // Verify different results
        $this->assertDifferentPageResults($firstPageResult, $secondPageResult);
    }

    /**
     * Test query with filtering.
     */
    public function test_query_filtering(): void
    {
        if (!$this->supportsFiltering()) {
            $this->markTestSkipped('Query does not support filtering');
        }

        $this->setUpFilteringTestData();
        $filterTests = $this->getFilterTests();

        foreach ($filterTests as $testName => $filterTest) {
            $query = $this->createFilteredQuery($filterTest['filters']);
            $result = $this->dispatchQuery($query);

            $this->assertFilteredResult($result, $filterTest['filters'], $testName);
        }
    }

    /**
     * Test query with sorting.
     */
    public function test_query_sorting(): void
    {
        if (!$this->supportsSorting()) {
            $this->markTestSkipped('Query does not support sorting');
        }

        $this->setUpSortingTestData();
        $sortTests = $this->getSortTests();

        foreach ($sortTests as $testName => $sortTest) {
            $query = $this->createSortedQuery($sortTest['sort_field'], $sortTest['sort_direction']);
            $result = $this->dispatchQuery($query);

            $this->assertSortedResult($result, $sortTest['sort_field'], $sortTest['sort_direction'], $testName);
        }
    }

    /**
     * Test query caching behavior.
     */
    public function test_query_caching(): void
    {
        if (!$this->supportsCaching()) {
            $this->markTestSkipped('Query does not support caching');
        }

        $query = $this->createValidQuery();

        // First execution should hit the database
        $this->clearQueryCache();
        $result1 = $this->dispatchQuery($query);
        $this->assertCacheWasMissed();

        // Second execution should hit the cache
        $result2 = $this->dispatchQuery($query);
        $this->assertCacheWasHit();
        $this->assertEquals($result1, $result2);

        // Cache invalidation should force database hit
        $this->invalidateQueryCache();
        $result3 = $this->dispatchQuery($query);
        $this->assertCacheWasMissed();
    }

    /**
     * Test query performance.
     */
    public function test_query_performance(): void
    {
        $performanceTests = $this->getPerformanceTests();

        foreach ($performanceTests as $testName => $test) {
            $this->setUpPerformanceTestData($test);
            $query = $this->createQueryWithData($test['query_data']);

            $maxTime = $test['max_execution_time'] ?? $this->getMaxExecutionTime();
            $maxMemory = $test['max_memory_usage'] ?? $this->getMaxMemoryUsage();

            $this->assertExecutionTime(
                fn() => $this->dispatchQuery($query),
                $maxTime
            );

            $this->assertMemoryUsage(
                fn() => $this->dispatchQuery($query),
                $maxMemory
            );
        }
    }

    /**
     * Test query with large datasets.
     */
    public function test_query_with_large_dataset(): void
    {
        if (!$this->supportsLargeDatasets()) {
            $this->markTestSkipped('Query does not support large dataset testing');
        }

        $this->setUpLargeDataset();
        $query = $this->createValidQuery();

        $result = $this->dispatchQuery($query);

        $this->assertLargeDatasetResult($result);
        $this->assertQueryHandledLargeDatasetEfficiently($query);
    }

    /**
     * Test query authorization.
     */
    public function test_query_authorization(): void
    {
        $authorizationTests = $this->getAuthorizationTests();

        foreach ($authorizationTests as $testName => $testData) {
            $this->actingAs($testData['user']);
            $query = $this->createQueryWithData($testData['query_data']);

            if ($testData['should_be_authorized']) {
                try {
                    $result = $this->dispatchQuery($query);
                    $this->assertAuthorizedQueryResult($result, $testData, $testName);
                } catch (\Exception $e) {
                    $this->fail("Query should be authorized for {$testName}: {$e->getMessage()}");
                }
            } else {
                $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
                $this->dispatchQuery($query);
            }
        }
    }

    /**
     * Test query error handling.
     */
    public function test_query_error_handling(): void
    {
        $errorScenarios = $this->getErrorScenarios();

        foreach ($errorScenarios as $scenarioName => $scenario) {
            $this->setUpErrorScenario($scenario);
            $query = $this->createQueryWithData($scenario['query_data']);

            try {
                $this->dispatchQuery($query);
                $this->fail("Query should have failed for scenario: {$scenarioName}");
            } catch (\Exception $e) {
                $this->assertInstanceOf($scenario['expected_exception'], $e);
                $this->assertStringContainsString($scenario['expected_message'], $e->getMessage());
            }
        }
    }

    /**
     * Get valid query data - override in specific test classes.
     */
    abstract protected function getValidQueryData(): array;

    /**
     * Get invalid query data sets - override in specific test classes.
     */
    abstract protected function getInvalidQueryDataSets(): array;

    /**
     * Get query test cases - override in specific test classes.
     */
    abstract protected function getQueryTestCases(): array;

    /**
     * Create query with specific data.
     */
    protected function createQueryWithData(array $data): object
    {
        return $this->createTestQuery($this->queryClass, $data);
    }

    /**
     * Create a valid query instance.
     */
    protected function createValidQuery(): object
    {
        return $this->createQueryWithData($this->getValidQueryData());
    }

    /**
     * Assert that a query is valid.
     */
    protected function assertQueryIsValid(object $query, string $context = ''): void
    {
        if (method_exists($query, 'validate')) {
            try {
                $query->validate();
                $this->assertTrue(true);
            } catch (\Exception $e) {
                $this->fail("Query should be valid" . ($context ? " for {$context}" : '') . ": {$e->getMessage()}");
            }
        }
    }

    /**
     * Assert that a query is invalid.
     */
    protected function assertQueryIsInvalid(object $query, string $context = ''): void
    {
        if (method_exists($query, 'validate')) {
            try {
                $query->validate();
                $this->fail("Query should be invalid" . ($context ? " for {$context}" : ''));
            } catch (\Exception $e) {
                $this->assertInstanceOf(\InvalidArgumentException::class, $e);
            }
        }
    }

    /**
     * Set up test case data - override in specific test classes.
     */
    protected function setUpTestCaseData(array $testCase): void
    {
        // Override in specific test classes
    }

    /**
     * Assert query result - override in specific test classes.
     */
    protected function assertQueryResult(mixed $result, mixed $expectedResult, string $testName): void
    {
        $this->assertEquals($expectedResult, $result, "Query result mismatch for {$testName}");
    }

    /**
     * Check if query supports pagination - override in specific test classes.
     */
    protected function supportsPagination(): bool
    {
        return false;
    }

    /**
     * Set up pagination test data - override in specific test classes.
     */
    protected function setUpPaginationTestData(): void
    {
        // Override in specific test classes
    }

    /**
     * Create paginated query - override in specific test classes.
     */
    protected function createPaginatedQuery(int $page, int $perPage): object
    {
        return $this->createValidQuery();
    }

    /**
     * Assert paginated result - override in specific test classes.
     */
    protected function assertPaginatedResult(mixed $result, int $page, int $perPage): void
    {
        // Override in specific test classes
    }

    /**
     * Assert different page results - override in specific test classes.
     */
    protected function assertDifferentPageResults(mixed $firstPageResult, mixed $secondPageResult): void
    {
        $this->assertNotEquals($firstPageResult, $secondPageResult);
    }

    /**
     * Check if query supports filtering - override in specific test classes.
     */
    protected function supportsFiltering(): bool
    {
        return false;
    }

    /**
     * Set up filtering test data - override in specific test classes.
     */
    protected function setUpFilteringTestData(): void
    {
        // Override in specific test classes
    }

    /**
     * Get filter tests - override in specific test classes.
     */
    protected function getFilterTests(): array
    {
        return [];
    }

    /**
     * Create filtered query - override in specific test classes.
     */
    protected function createFilteredQuery(array $filters): object
    {
        return $this->createValidQuery();
    }

    /**
     * Assert filtered result - override in specific test classes.
     */
    protected function assertFilteredResult(mixed $result, array $filters, string $testName): void
    {
        // Override in specific test classes
    }

    /**
     * Check if query supports sorting - override in specific test classes.
     */
    protected function supportsSorting(): bool
    {
        return false;
    }

    /**
     * Set up sorting test data - override in specific test classes.
     */
    protected function setUpSortingTestData(): void
    {
        // Override in specific test classes
    }

    /**
     * Get sort tests - override in specific test classes.
     */
    protected function getSortTests(): array
    {
        return [];
    }

    /**
     * Create sorted query - override in specific test classes.
     */
    protected function createSortedQuery(string $field, string $direction): object
    {
        return $this->createValidQuery();
    }

    /**
     * Assert sorted result - override in specific test classes.
     */
    protected function assertSortedResult(mixed $result, string $field, string $direction, string $testName): void
    {
        // Override in specific test classes
    }

    /**
     * Check if query supports caching - override in specific test classes.
     */
    protected function supportsCaching(): bool
    {
        return false;
    }

    /**
     * Clear query cache - override in specific test classes.
     */
    protected function clearQueryCache(): void
    {
        // Override in specific test classes
    }

    /**
     * Invalidate query cache - override in specific test classes.
     */
    protected function invalidateQueryCache(): void
    {
        // Override in specific test classes
    }

    /**
     * Assert cache was missed - override in specific test classes.
     */
    protected function assertCacheWasMissed(): void
    {
        // Override in specific test classes
    }

    /**
     * Assert cache was hit - override in specific test classes.
     */
    protected function assertCacheWasHit(): void
    {
        // Override in specific test classes
    }

    /**
     * Get performance tests - override in specific test classes.
     */
    protected function getPerformanceTests(): array
    {
        return [];
    }

    /**
     * Set up performance test data - override in specific test classes.
     */
    protected function setUpPerformanceTestData(array $test): void
    {
        // Override in specific test classes
    }

    /**
     * Get maximum execution time - override in specific test classes.
     */
    protected function getMaxExecutionTime(): float
    {
        return 1.0; // 1 second
    }

    /**
     * Get maximum memory usage - override in specific test classes.
     */
    protected function getMaxMemoryUsage(): int
    {
        return 1024 * 1024; // 1MB
    }

    /**
     * Check if query supports large datasets - override in specific test classes.
     */
    protected function supportsLargeDatasets(): bool
    {
        return false;
    }

    /**
     * Set up large dataset - override in specific test classes.
     */
    protected function setUpLargeDataset(): void
    {
        // Override in specific test classes
    }

    /**
     * Assert large dataset result - override in specific test classes.
     */
    protected function assertLargeDatasetResult(mixed $result): void
    {
        // Override in specific test classes
    }

    /**
     * Assert query handled large dataset efficiently - override in specific test classes.
     */
    protected function assertQueryHandledLargeDatasetEfficiently(object $query): void
    {
        // Override in specific test classes
    }

    /**
     * Get authorization tests - override in specific test classes.
     */
    protected function getAuthorizationTests(): array
    {
        return [];
    }

    /**
     * Assert authorized query result - override in specific test classes.
     */
    protected function assertAuthorizedQueryResult(mixed $result, array $testData, string $testName): void
    {
        // Override in specific test classes
    }

    /**
     * Get error scenarios - override in specific test classes.
     */
    protected function getErrorScenarios(): array
    {
        return [];
    }

    /**
     * Set up error scenario - override in specific test classes.
     */
    protected function setUpErrorScenario(array $scenario): void
    {
        // Override in specific test classes
    }
}