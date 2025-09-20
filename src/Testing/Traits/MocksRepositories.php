<?php

declare(strict_types=1);

namespace LaravelModularDDD\Testing\Traits;

use Mockery;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * MocksRepositories
 *
 * Provides utilities for mocking repositories in tests.
 * Handles common repository patterns and provides fluent mocking interface.
 */
trait MocksRepositories
{
    /**
     * Mock a repository with common CRUD operations.
     */
    protected function mockRepository(string $repositoryInterface, array $methods = []): MockObject
    {
        $mock = $this->createMock($repositoryInterface);

        // Set up default behavior for common repository methods
        $this->setupDefaultRepositoryBehavior($mock);

        // Apply custom method behaviors
        foreach ($methods as $method => $returnValue) {
            if (is_callable($returnValue)) {
                $mock->method($method)->willReturnCallback($returnValue);
            } else {
                $mock->method($method)->willReturn($returnValue);
            }
        }

        // Register mock in container
        $this->app->instance($repositoryInterface, $mock);

        return $mock;
    }

    /**
     * Mock an aggregate repository with event sourcing behavior.
     */
    protected function mockAggregateRepository(string $repositoryInterface, array $aggregates = []): MockObject
    {
        $mock = $this->createMock($repositoryInterface);

        // Store for find operations
        $storage = [];
        foreach ($aggregates as $aggregate) {
            $id = $this->getAggregateId($aggregate);
            $storage[$id] = $aggregate;
        }

        // Mock find method
        $mock->method('find')
            ->willReturnCallback(function ($id) use ($storage) {
                return $storage[$id] ?? null;
            });

        // Mock save method
        $mock->method('save')
            ->willReturnCallback(function ($aggregate) use (&$storage) {
                $id = $this->getAggregateId($aggregate);
                $storage[$id] = $aggregate;

                // Clear uncommitted events if method exists
                if (method_exists($aggregate, 'markEventsAsCommitted')) {
                    $aggregate->markEventsAsCommitted();
                }

                return $aggregate;
            });

        // Mock exists method
        $mock->method('exists')
            ->willReturnCallback(function ($id) use ($storage) {
                return isset($storage[$id]);
            });

        // Register mock in container
        $this->app->instance($repositoryInterface, $mock);

        return $mock;
    }

    /**
     * Mock a read model repository.
     */
    protected function mockReadModelRepository(string $repositoryInterface, array $readModels = []): MockObject
    {
        $mock = $this->createMock($repositoryInterface);

        // Store read models
        $storage = $readModels;

        // Mock findAll method
        $mock->method('findAll')
            ->willReturn($storage);

        // Mock findBy method
        $mock->method('findBy')
            ->willReturnCallback(function (array $criteria) use ($storage) {
                return array_filter($storage, function ($model) use ($criteria) {
                    foreach ($criteria as $field => $value) {
                        $getter = 'get' . ucfirst($field);
                        if (method_exists($model, $getter) && $model->{$getter}() !== $value) {
                            return false;
                        }
                    }
                    return true;
                });
            });

        // Mock findOneBy method
        $mock->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use ($storage) {
                $results = array_filter($storage, function ($model) use ($criteria) {
                    foreach ($criteria as $field => $value) {
                        $getter = 'get' . ucfirst($field);
                        if (method_exists($model, $getter) && $model->{$getter}() !== $value) {
                            return false;
                        }
                    }
                    return true;
                });
                return reset($results) ?: null;
            });

        // Mock paginate method
        $mock->method('paginate')
            ->willReturnCallback(function (int $page = 1, int $limit = 10) use ($storage) {
                $offset = ($page - 1) * $limit;
                return new \stdClass([
                    'data' => array_slice($storage, $offset, $limit),
                    'total' => count($storage),
                    'page' => $page,
                    'limit' => $limit,
                ]);
            });

        // Register mock in container
        $this->app->instance($repositoryInterface, $mock);

        return $mock;
    }

    /**
     * Mock repository with Mockery for more advanced behavior.
     */
    protected function mockRepositoryWithMockery(string $repositoryInterface): \Mockery\MockInterface
    {
        $mock = Mockery::mock($repositoryInterface);

        // Set up default expectations
        $mock->shouldReceive('find')->andReturn(null)->byDefault();
        $mock->shouldReceive('save')->andReturnUsing(function ($aggregate) {
            return $aggregate;
        })->byDefault();
        $mock->shouldReceive('exists')->andReturn(false)->byDefault();

        // Register mock in container
        $this->app->instance($repositoryInterface, $mock);

        return $mock;
    }

    /**
     * Set up a repository to always return specific aggregates.
     */
    protected function repositoryReturns(string $repositoryInterface, string $method, $returnValue): MockObject
    {
        $mock = $this->mockRepository($repositoryInterface);
        $mock->method($method)->willReturn($returnValue);

        return $mock;
    }

    /**
     * Set up a repository to throw an exception.
     */
    protected function repositoryThrows(string $repositoryInterface, string $method, \Exception $exception): MockObject
    {
        $mock = $this->mockRepository($repositoryInterface);
        $mock->method($method)->willThrowException($exception);

        return $mock;
    }

    /**
     * Verify that a repository method was called with specific arguments.
     */
    protected function assertRepositoryMethodCalled(MockObject $repository, string $method, array $expectedArgs = []): void
    {
        if (empty($expectedArgs)) {
            $repository->expects($this->once())
                ->method($method);
        } else {
            $repository->expects($this->once())
                ->method($method)
                ->with(...$expectedArgs);
        }
    }

    /**
     * Verify that a repository method was called a specific number of times.
     */
    protected function assertRepositoryMethodCalledTimes(MockObject $repository, string $method, int $times): void
    {
        $repository->expects($this->exactly($times))
            ->method($method);
    }

    /**
     * Verify that a repository method was never called.
     */
    protected function assertRepositoryMethodNotCalled(MockObject $repository, string $method): void
    {
        $repository->expects($this->never())
            ->method($method);
    }

    /**
     * Create a spy repository that records all method calls.
     */
    protected function spyRepository(string $repositoryInterface): object
    {
        return new class($repositoryInterface) {
            private array $calls = [];
            private string $interface;

            public function __construct(string $interface)
            {
                $this->interface = $interface;
            }

            public function __call(string $method, array $args)
            {
                $this->calls[] = [
                    'method' => $method,
                    'args' => $args,
                    'timestamp' => microtime(true),
                ];

                // Return sensible defaults based on method name
                return match (true) {
                    str_starts_with($method, 'find') => null,
                    str_starts_with($method, 'save') => $args[0] ?? null,
                    str_starts_with($method, 'exists') => false,
                    str_starts_with($method, 'count') => 0,
                    default => null,
                };
            }

            public function getCalls(): array
            {
                return $this->calls;
            }

            public function getCallsFor(string $method): array
            {
                return array_filter($this->calls, fn($call) => $call['method'] === $method);
            }

            public function wasCalledWith(string $method, array $expectedArgs): bool
            {
                $calls = $this->getCallsFor($method);
                foreach ($calls as $call) {
                    if ($call['args'] === $expectedArgs) {
                        return true;
                    }
                }
                return false;
            }
        };
    }

    /**
     * Set up default behavior for common repository methods.
     */
    private function setupDefaultRepositoryBehavior(MockObject $mock): void
    {
        // Default find behavior
        if (method_exists($mock, 'method')) {
            $mock->method('find')->willReturn(null);
            $mock->method('findAll')->willReturn([]);
            $mock->method('exists')->willReturn(false);
            $mock->method('count')->willReturn(0);

            // Save method returns the saved entity
            $mock->method('save')->willReturnCallback(function ($entity) {
                return $entity;
            });
        }
    }

    /**
     * Get aggregate ID using common patterns.
     */
    private function getAggregateId(object $aggregate)
    {
        $methods = ['getId', 'getAggregateId', 'id'];

        foreach ($methods as $method) {
            if (method_exists($aggregate, $method)) {
                return $aggregate->{$method}();
            }
        }

        // Try property access
        if (property_exists($aggregate, 'id')) {
            $reflection = new \ReflectionProperty($aggregate, 'id');
            $reflection->setAccessible(true);
            return $reflection->getValue($aggregate);
        }

        return uniqid();
    }

    /**
     * Clean up Mockery after each test.
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}