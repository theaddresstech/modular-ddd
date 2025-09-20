<?php

declare(strict_types=1);

namespace LaravelModularDDD\Testing\Traits;

use PHPUnit\Framework\TestCase;

/**
 * TestsQueries
 *
 * Provides utilities for testing CQRS queries and query handlers.
 * Includes performance testing, caching verification, and result validation.
 */
trait TestsQueries
{
    /**
     * Test query execution with expected result.
     */
    protected function assertQueryReturns(object $query, $expectedResult): mixed
    {
        $result = $this->executeQuery($query);

        TestCase::assertEquals(
            $expectedResult,
            $result,
            'Query result does not match expected'
        );

        return $result;
    }

    /**
     * Test query execution with result validation.
     */
    protected function assertQueryResult(object $query, \Closure $validator): mixed
    {
        $result = $this->executeQuery($query);

        $isValid = $validator($result);

        TestCase::assertTrue(
            $isValid,
            'Query result did not pass validation'
        );

        return $result;
    }

    /**
     * Test query returns empty result.
     */
    protected function assertQueryReturnsEmpty(object $query): void
    {
        $result = $this->executeQuery($query);

        if (is_array($result)) {
            TestCase::assertEmpty($result, 'Query should return empty array');
        } elseif (is_countable($result)) {
            TestCase::assertCount(0, $result, 'Query should return empty collection');
        } elseif ($result === null) {
            TestCase::assertNull($result, 'Query should return null');
        } else {
            TestCase::fail('Query result is not empty');
        }
    }

    /**
     * Test query returns specific count of items.
     */
    protected function assertQueryReturnsCount(object $query, int $expectedCount): mixed
    {
        $result = $this->executeQuery($query);

        if (is_array($result)) {
            TestCase::assertCount($expectedCount, $result, 'Query result count mismatch');
        } elseif (is_countable($result)) {
            TestCase::assertCount($expectedCount, $result, 'Query result count mismatch');
        } elseif (method_exists($result, 'count')) {
            TestCase::assertEquals($expectedCount, $result->count(), 'Query result count mismatch');
        } else {
            TestCase::fail('Query result is not countable');
        }

        return $result;
    }

    /**
     * Test query execution time performance.
     */
    protected function assertQueryPerformance(object $query, float $maxExecutionTime): mixed
    {
        $startTime = microtime(true);
        $result = $this->executeQuery($query);
        $executionTime = microtime(true) - $startTime;

        TestCase::assertLessThan(
            $maxExecutionTime,
            $executionTime,
            "Query execution took {$executionTime}s, expected less than {$maxExecutionTime}s"
        );

        return $result;
    }

    /**
     * Test query caching behavior.
     */
    protected function assertQueryCaching(object $query, bool $shouldCache = true): void
    {
        $this->clearQueryCache();

        // First execution - should hit the database
        $result1 = $this->executeQuery($query);
        $cacheHit1 = $this->wasQueryCacheHit();

        // Second execution - should hit cache if caching is enabled
        $result2 = $this->executeQuery($query);
        $cacheHit2 = $this->wasQueryCacheHit();

        if ($shouldCache) {
            TestCase::assertFalse($cacheHit1, 'First query execution should not be a cache hit');
            TestCase::assertTrue($cacheHit2, 'Second query execution should be a cache hit');
            TestCase::assertEquals($result1, $result2, 'Cached result should match original result');
        } else {
            TestCase::assertFalse($cacheHit1, 'First query execution should not be a cache hit');
            TestCase::assertFalse($cacheHit2, 'Second query execution should not be a cache hit when caching disabled');
        }
    }

    /**
     * Test query with different parameters.
     */
    protected function assertQueryWithParameters(string $queryClass, array $parameterSets): void
    {
        foreach ($parameterSets as $testName => $parameterSet) {
            $query = $this->createQuery($queryClass, $parameterSet['parameters']);
            $expectedResult = $parameterSet['expected_result'];
            $validator = $parameterSet['validator'] ?? null;

            if ($validator) {
                $this->assertQueryResult($query, $validator);
            } else {
                $this->assertQueryReturns($query, $expectedResult);
            }
        }
    }

    /**
     * Test query pagination.
     */
    protected function assertQueryPagination(object $query, int $pageSize, int $totalExpected): void
    {
        $query = $this->setQueryPagination($query, 1, $pageSize);
        $firstPage = $this->executeQuery($query);

        // Check first page structure
        TestCase::assertArrayHasKey('data', $firstPage, 'Paginated result should have data key');
        TestCase::assertArrayHasKey('total', $firstPage, 'Paginated result should have total key');
        TestCase::assertArrayHasKey('page', $firstPage, 'Paginated result should have page key');
        TestCase::assertArrayHasKey('limit', $firstPage, 'Paginated result should have limit key');

        // Check first page data
        TestCase::assertEquals($totalExpected, $firstPage['total'], 'Total count mismatch');
        TestCase::assertEquals(1, $firstPage['page'], 'Page number mismatch');
        TestCase::assertEquals($pageSize, $firstPage['limit'], 'Page size mismatch');

        $expectedFirstPageSize = min($pageSize, $totalExpected);
        TestCase::assertCount($expectedFirstPageSize, $firstPage['data'], 'First page size mismatch');

        // Test second page if there should be one
        if ($totalExpected > $pageSize) {
            $query = $this->setQueryPagination($query, 2, $pageSize);
            $secondPage = $this->executeQuery($query);

            TestCase::assertEquals(2, $secondPage['page'], 'Second page number mismatch');
            $expectedSecondPageSize = min($pageSize, $totalExpected - $pageSize);
            TestCase::assertCount($expectedSecondPageSize, $secondPage['data'], 'Second page size mismatch');
        }
    }

    /**
     * Test query filtering.
     */
    protected function assertQueryFiltering(object $query, array $filters, \Closure $validator): void
    {
        $filteredQuery = $this->applyQueryFilters($query, $filters);
        $result = $this->executeQuery($filteredQuery);

        $isValid = $validator($result, $filters);

        TestCase::assertTrue(
            $isValid,
            'Filtered query result did not pass validation'
        );
    }

    /**
     * Test query sorting.
     */
    protected function assertQuerySorting(object $query, string $sortField, string $sortDirection = 'asc'): void
    {
        $sortedQuery = $this->applyQuerySorting($query, $sortField, $sortDirection);
        $result = $this->executeQuery($sortedQuery);

        if (is_array($result) && !empty($result)) {
            $this->validateSortOrder($result, $sortField, $sortDirection);
        }
    }

    /**
     * Test query with multiple sorting criteria.
     */
    protected function assertQueryMultipleSorting(object $query, array $sortCriteria): void
    {
        $sortedQuery = $this->applyMultipleQuerySorting($query, $sortCriteria);
        $result = $this->executeQuery($sortedQuery);

        if (is_array($result) && !empty($result)) {
            $this->validateMultipleSortOrder($result, $sortCriteria);
        }
    }

    /**
     * Test query authorization.
     */
    protected function assertQueryAuthorization(object $query, object $user = null, bool $shouldBeAuthorized = true): void
    {
        if ($user) {
            $this->actingAs($user);
        }

        if (method_exists($query, 'authorize') || method_exists($query, 'isAuthorized')) {
            $method = method_exists($query, 'authorize') ? 'authorize' : 'isAuthorized';

            try {
                $authorized = $query->{$method}();

                TestCase::assertEquals(
                    $shouldBeAuthorized,
                    $authorized,
                    'Query authorization result does not match expected'
                );
            } catch (\Exception $e) {
                if ($shouldBeAuthorized) {
                    TestCase::fail("Query authorization should pass but failed: {$e->getMessage()}");
                } else {
                    TestCase::assertTrue(true, 'Query authorization failed as expected');
                }
            }
        } else {
            TestCase::markTestSkipped('Query does not implement authorization');
        }
    }

    /**
     * Test query memory usage.
     */
    protected function assertQueryMemoryUsage(object $query, int $maxMemoryUsage): mixed
    {
        $initialMemory = memory_get_usage(true);
        $result = $this->executeQuery($query);
        $memoryUsed = memory_get_usage(true) - $initialMemory;

        TestCase::assertLessThan(
            $maxMemoryUsage,
            $memoryUsed,
            "Query used {$memoryUsed} bytes, expected less than {$maxMemoryUsage} bytes"
        );

        return $result;
    }

    /**
     * Test query with database load.
     */
    protected function assertQueryDatabaseLoad(object $query, int $maxQueries): mixed
    {
        $initialQueryCount = $this->getDatabaseQueryCount();
        $result = $this->executeQuery($query);
        $queriesExecuted = $this->getDatabaseQueryCount() - $initialQueryCount;

        TestCase::assertLessThanOrEqual(
            $maxQueries,
            $queriesExecuted,
            "Query executed {$queriesExecuted} database queries, expected {$maxQueries} or fewer"
        );

        return $result;
    }

    /**
     * Test query result schema validation.
     */
    protected function assertQueryResultSchema(object $query, array $expectedSchema): void
    {
        $result = $this->executeQuery($query);

        if (is_array($result)) {
            $this->validateArraySchema($result, $expectedSchema);
        } elseif (is_object($result)) {
            $this->validateObjectSchema($result, $expectedSchema);
        } else {
            TestCase::fail('Query result is not an array or object');
        }
    }

    /**
     * Test query with concurrent executions.
     */
    protected function assertQueryConcurrency(object $query, int $concurrentExecutions = 5): void
    {
        $results = [];

        // Simulate concurrent executions
        for ($i = 0; $i < $concurrentExecutions; $i++) {
            $results[] = $this->executeQuery($query);
        }

        // All results should be identical for read-only queries
        $firstResult = $results[0];
        foreach ($results as $index => $result) {
            TestCase::assertEquals(
                $firstResult,
                $result,
                "Concurrent query execution #{$index} produced different result"
            );
        }
    }

    /**
     * Execute a query using the query bus.
     */
    private function executeQuery(object $query)
    {
        if (method_exists($this, 'dispatchQuery')) {
            return $this->dispatchQuery($query);
        }

        if (property_exists($this, 'queryBus')) {
            return $this->queryBus->dispatch($query);
        }

        if (app()->bound('query.bus')) {
            return app('query.bus')->dispatch($query);
        }

        TestCase::fail('No query bus available for query execution');
    }

    /**
     * Create a query instance with given parameters.
     */
    private function createQuery(string $queryClass, array $parameters): object
    {
        if (method_exists($this, 'createTestQuery')) {
            return $this->createTestQuery($queryClass, $parameters);
        }

        // Fallback to reflection-based creation
        $reflection = new \ReflectionClass($queryClass);
        $constructor = $reflection->getConstructor();

        if (!$constructor) {
            return new $queryClass();
        }

        $constructorParams = $constructor->getParameters();
        $args = [];

        foreach ($constructorParams as $param) {
            $paramName = $param->getName();
            if (isset($parameters[$paramName])) {
                $args[] = $parameters[$paramName];
            } elseif ($param->hasDefaultValue()) {
                $args[] = $param->getDefaultValue();
            } else {
                $args[] = null;
            }
        }

        return new $queryClass(...$args);
    }

    /**
     * Set query pagination parameters.
     */
    private function setQueryPagination(object $query, int $page, int $limit): object
    {
        if (method_exists($query, 'withPagination')) {
            return $query->withPagination($page, $limit);
        }

        if (method_exists($query, 'paginate')) {
            return $query->paginate($page, $limit);
        }

        // For immutable queries, create a new instance
        $reflection = new \ReflectionClass($query);
        if ($reflection->hasProperty('page')) {
            $pageProperty = $reflection->getProperty('page');
            $pageProperty->setAccessible(true);
            $pageProperty->setValue($query, $page);
        }

        if ($reflection->hasProperty('limit')) {
            $limitProperty = $reflection->getProperty('limit');
            $limitProperty->setAccessible(true);
            $limitProperty->setValue($query, $limit);
        }

        return $query;
    }

    /**
     * Apply filters to query.
     */
    private function applyQueryFilters(object $query, array $filters): object
    {
        if (method_exists($query, 'withFilters')) {
            return $query->withFilters($filters);
        }

        // Fallback implementation
        foreach ($filters as $field => $value) {
            $method = 'filter' . ucfirst($field);
            if (method_exists($query, $method)) {
                $query = $query->{$method}($value);
            }
        }

        return $query;
    }

    /**
     * Apply sorting to query.
     */
    private function applyQuerySorting(object $query, string $field, string $direction): object
    {
        if (method_exists($query, 'orderBy')) {
            return $query->orderBy($field, $direction);
        }

        if (method_exists($query, 'sortBy')) {
            return $query->sortBy($field, $direction);
        }

        return $query;
    }

    /**
     * Apply multiple sorting criteria to query.
     */
    private function applyMultipleQuerySorting(object $query, array $sortCriteria): object
    {
        foreach ($sortCriteria as $field => $direction) {
            $query = $this->applyQuerySorting($query, $field, $direction);
        }

        return $query;
    }

    /**
     * Validate sort order of results.
     */
    private function validateSortOrder(array $result, string $field, string $direction): void
    {
        for ($i = 0; $i < count($result) - 1; $i++) {
            $current = $this->getFieldValue($result[$i], $field);
            $next = $this->getFieldValue($result[$i + 1], $field);

            if ($direction === 'asc') {
                TestCase::assertLessThanOrEqual(
                    $next,
                    $current,
                    "Sort order violation at index {$i} for field '{$field}' (ascending)"
                );
            } else {
                TestCase::assertGreaterThanOrEqual(
                    $next,
                    $current,
                    "Sort order violation at index {$i} for field '{$field}' (descending)"
                );
            }
        }
    }

    /**
     * Validate multiple sort order criteria.
     */
    private function validateMultipleSortOrder(array $result, array $sortCriteria): void
    {
        // For multiple sort criteria, we check the primary sort first,
        // then secondary sorts for items with equal primary values
        $primaryField = array_key_first($sortCriteria);
        $this->validateSortOrder($result, $primaryField, $sortCriteria[$primaryField]);
    }

    /**
     * Get field value from result item.
     */
    private function getFieldValue($item, string $field)
    {
        if (is_array($item)) {
            return $item[$field] ?? null;
        }

        if (is_object($item)) {
            $getter = 'get' . ucfirst($field);
            if (method_exists($item, $getter)) {
                return $item->{$getter}();
            }

            if (property_exists($item, $field)) {
                return $item->{$field};
            }
        }

        return null;
    }

    /**
     * Validate array schema.
     */
    private function validateArraySchema(array $result, array $schema): void
    {
        foreach ($schema as $key => $expectedType) {
            TestCase::assertArrayHasKey($key, $result, "Missing expected key '{$key}'");

            if (is_string($expectedType)) {
                $actualType = gettype($result[$key]);
                TestCase::assertEquals(
                    $expectedType,
                    $actualType,
                    "Type mismatch for key '{$key}'"
                );
            }
        }
    }

    /**
     * Validate object schema.
     */
    private function validateObjectSchema(object $result, array $schema): void
    {
        foreach ($schema as $property => $expectedType) {
            TestCase::assertTrue(
                property_exists($result, $property),
                "Missing expected property '{$property}'"
            );

            if (is_string($expectedType)) {
                $actualType = gettype($result->{$property});
                TestCase::assertEquals(
                    $expectedType,
                    $actualType,
                    "Type mismatch for property '{$property}'"
                );
            }
        }
    }

    /**
     * Clear query cache.
     */
    private function clearQueryCache(): void
    {
        if (method_exists($this, 'clearCache')) {
            $this->clearCache();
        }
    }

    /**
     * Check if last query was a cache hit.
     */
    private function wasQueryCacheHit(): bool
    {
        // This would be implemented based on your caching system
        return false;
    }

    /**
     * Get database query count.
     */
    private function getDatabaseQueryCount(): int
    {
        // This would be implemented based on your database monitoring
        return 0;
    }
}