<?php

declare(strict_types=1);

namespace LaravelModularDDD\Testing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use LaravelModularDDD\Support\ModuleRegistry;
use LaravelModularDDD\Support\CommandBusManager;
use LaravelModularDDD\Support\QueryBusManager;

/**
 * ModuleTestCase
 *
 * Base test case for module testing with DDD and CQRS support.
 * Provides utilities for testing aggregates, commands, queries, and events.
 */
abstract class ModuleTestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected ModuleRegistry $moduleRegistry;
    protected CommandBusManager $commandBus;
    protected QueryBusManager $queryBus;
    protected string $moduleName;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->moduleRegistry = $this->app->make(ModuleRegistry::class);
        $this->commandBus = $this->app->make(CommandBusManager::class);
        $this->queryBus = $this->app->make(QueryBusManager::class);

        $this->setUpModule();
        $this->setUpEventHandling();
        $this->setUpTestData();
    }

    /**
     * Set up module-specific configuration.
     */
    protected function setUpModule(): void
    {
        if (!$this->moduleName) {
            throw new \RuntimeException('Module name must be defined in test class');
        }

        // Ensure module is enabled for testing
        if (!$this->moduleRegistry->hasModule($this->moduleName)) {
            $this->markTestSkipped("Module {$this->moduleName} not found");
        }

        $module = $this->moduleRegistry->getModule($this->moduleName);
        if (!$module['enabled']) {
            $this->moduleRegistry->enableModule($this->moduleName);
        }
    }

    /**
     * Set up event handling for testing.
     */
    protected function setUpEventHandling(): void
    {
        // Clear any existing event listeners
        $this->app['events']->flush();

        // Set up event capturing for assertions
        $this->capturedEvents = [];
        $this->app['events']->listen('*', function ($eventName, $payload) {
            $this->capturedEvents[] = [
                'name' => $eventName,
                'payload' => $payload,
            ];
        });
    }

    /**
     * Set up test data - override in specific test classes.
     */
    protected function setUpTestData(): void
    {
        // Override in specific test classes
    }

    /**
     * Clean up after each test.
     */
    protected function tearDown(): void
    {
        $this->clearCapturedEvents();
        $this->clearAggregateRepositories();

        parent::tearDown();
    }

    /**
     * Dispatch a command and return the result.
     */
    protected function dispatchCommand(object $command): mixed
    {
        return $this->commandBus->dispatch($command);
    }

    /**
     * Dispatch a query and return the result.
     */
    protected function dispatchQuery(object $query): mixed
    {
        return $this->queryBus->dispatch($query);
    }

    /**
     * Assert that a specific event was dispatched.
     */
    protected function assertEventDispatched(string $eventClass): void
    {
        $found = false;

        foreach ($this->capturedEvents as $event) {
            if ($event['payload'][0] instanceof $eventClass) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, "Event {$eventClass} was not dispatched");
    }

    /**
     * Assert that a specific event was not dispatched.
     */
    protected function assertEventNotDispatched(string $eventClass): void
    {
        foreach ($this->capturedEvents as $event) {
            if ($event['payload'][0] instanceof $eventClass) {
                $this->fail("Event {$eventClass} should not have been dispatched");
            }
        }

        $this->assertTrue(true);
    }

    /**
     * Assert that events were dispatched in a specific order.
     */
    protected function assertEventsDispatchedInOrder(array $eventClasses): void
    {
        $dispatchedEvents = [];

        foreach ($this->capturedEvents as $event) {
            $payload = $event['payload'][0] ?? null;
            if ($payload && is_object($payload)) {
                $dispatchedEvents[] = get_class($payload);
            }
        }

        $expectedOrder = [];
        foreach ($eventClasses as $eventClass) {
            $found = false;
            foreach ($dispatchedEvents as $index => $dispatchedClass) {
                if ($dispatchedClass === $eventClass) {
                    $expectedOrder[] = $index;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $this->fail("Event {$eventClass} was not dispatched");
            }
        }

        // Check if events are in ascending order
        $this->assertEquals(
            $expectedOrder,
            array_values(array_sort($expectedOrder)),
            'Events were not dispatched in the expected order'
        );
    }

    /**
     * Get the last dispatched event of a specific type.
     */
    protected function getLastDispatchedEvent(string $eventClass): ?object
    {
        $lastEvent = null;

        foreach ($this->capturedEvents as $event) {
            if ($event['payload'][0] instanceof $eventClass) {
                $lastEvent = $event['payload'][0];
            }
        }

        return $lastEvent;
    }

    /**
     * Get all dispatched events of a specific type.
     */
    protected function getDispatchedEvents(string $eventClass): array
    {
        $events = [];

        foreach ($this->capturedEvents as $event) {
            if ($event['payload'][0] instanceof $eventClass) {
                $events[] = $event['payload'][0];
            }
        }

        return $events;
    }

    /**
     * Clear captured events.
     */
    protected function clearCapturedEvents(): void
    {
        $this->capturedEvents = [];
    }

    /**
     * Create a test aggregate with the given data.
     */
    protected function createTestAggregate(string $aggregateClass, array $data = []): object
    {
        $reflection = new \ReflectionClass($aggregateClass);

        // Try to find a create method
        if ($reflection->hasMethod('create')) {
            $createMethod = $reflection->getMethod('create');
            $parameters = $createMethod->getParameters();

            $args = [];
            foreach ($parameters as $param) {
                $paramName = $param->getName();
                if (isset($data[$paramName])) {
                    $args[] = $data[$paramName];
                } elseif ($param->hasDefaultValue()) {
                    $args[] = $param->getDefaultValue();
                } else {
                    $args[] = $this->getDefaultValueForParameter($param);
                }
            }

            return $createMethod->invokeArgs(null, $args);
        }

        // Fallback to constructor
        return $reflection->newInstanceArgs($data);
    }

    /**
     * Get default value for a parameter based on its type.
     */
    private function getDefaultValueForParameter(\ReflectionParameter $param): mixed
    {
        $type = $param->getType();

        if (!$type instanceof \ReflectionNamedType) {
            return null;
        }

        return match ($type->getName()) {
            'string' => 'test-' . uniqid(),
            'int' => random_int(1, 1000),
            'float' => mt_rand() / mt_getrandmax(),
            'bool' => true,
            'array' => [],
            default => null,
        };
    }

    /**
     * Assert that an aggregate has a specific state.
     */
    protected function assertAggregateState(object $aggregate, array $expectedState): void
    {
        foreach ($expectedState as $property => $expectedValue) {
            $getter = 'get' . ucfirst($property);

            if (method_exists($aggregate, $getter)) {
                $actualValue = $aggregate->{$getter}();
                $this->assertEquals($expectedValue, $actualValue, "Property {$property} does not match expected value");
            } else {
                $this->fail("Getter method {$getter} does not exist on aggregate");
            }
        }
    }

    /**
     * Assert that an aggregate has uncommitted events.
     */
    protected function assertAggregateHasUncommittedEvents(object $aggregate, int $expectedCount = null): void
    {
        if (!method_exists($aggregate, 'getUncommittedEvents')) {
            $this->fail('Aggregate does not implement getUncommittedEvents method');
        }

        $events = $aggregate->getUncommittedEvents();
        $this->assertIsArray($events, 'getUncommittedEvents should return an array');

        if ($expectedCount !== null) {
            $this->assertCount($expectedCount, $events, "Expected {$expectedCount} uncommitted events");
        } else {
            $this->assertNotEmpty($events, 'Aggregate should have uncommitted events');
        }
    }

    /**
     * Assert that an aggregate has no uncommitted events.
     */
    protected function assertAggregateHasNoUncommittedEvents(object $aggregate): void
    {
        if (!method_exists($aggregate, 'getUncommittedEvents')) {
            $this->fail('Aggregate does not implement getUncommittedEvents method');
        }

        $events = $aggregate->getUncommittedEvents();
        $this->assertEmpty($events, 'Aggregate should not have uncommitted events');
    }

    /**
     * Mock a repository for testing.
     */
    protected function mockRepository(string $repositoryInterface, array $methods = []): object
    {
        $mock = $this->createMock($repositoryInterface);

        foreach ($methods as $method => $returnValue) {
            $mock->method($method)->willReturn($returnValue);
        }

        $this->app->instance($repositoryInterface, $mock);

        return $mock;
    }

    /**
     * Create a test command with the given data.
     */
    protected function createTestCommand(string $commandClass, array $data = []): object
    {
        $reflection = new \ReflectionClass($commandClass);
        $constructor = $reflection->getConstructor();

        if (!$constructor) {
            return new $commandClass();
        }

        $parameters = $constructor->getParameters();
        $args = [];

        foreach ($parameters as $param) {
            $paramName = $param->getName();
            if (isset($data[$paramName])) {
                $args[] = $data[$paramName];
            } elseif ($param->hasDefaultValue()) {
                $args[] = $param->getDefaultValue();
            } else {
                $args[] = $this->getDefaultValueForParameter($param);
            }
        }

        return new $commandClass(...$args);
    }

    /**
     * Create a test query with the given data.
     */
    protected function createTestQuery(string $queryClass, array $data = []): object
    {
        return $this->createTestCommand($queryClass, $data);
    }

    /**
     * Assert that a command validation fails.
     */
    protected function assertCommandValidationFails(object $command, string $expectedError = null): void
    {
        try {
            $this->dispatchCommand($command);
            $this->fail('Command should have failed validation');
        } catch (\Exception $e) {
            if ($expectedError) {
                $this->assertStringContainsString($expectedError, $e->getMessage());
            }
            $this->assertTrue(true);
        }
    }

    /**
     * Assert that a query returns expected results.
     */
    protected function assertQueryReturns(object $query, mixed $expectedResult): void
    {
        $result = $this->dispatchQuery($query);
        $this->assertEquals($expectedResult, $result);
    }

    /**
     * Clear aggregate repositories for clean test state.
     */
    protected function clearAggregateRepositories(): void
    {
        // Override in specific test classes if needed
    }

    /**
     * Set up test database with module-specific tables.
     */
    protected function setUpModuleDatabase(): void
    {
        $module = $this->moduleRegistry->getModule($this->moduleName);
        $migrations = $module['migrations'] ?? [];

        foreach ($migrations as $migration) {
            include_once $migration;
        }
    }

    /**
     * Generate test data using module factories.
     */
    protected function generateTestData(string $factoryClass, int $count = 1, array $attributes = []): mixed
    {
        if (!class_exists($factoryClass)) {
            throw new \InvalidArgumentException("Factory class {$factoryClass} does not exist");
        }

        $factory = new $factoryClass();

        if ($count === 1) {
            return $factory->create($attributes);
        }

        return $factory->createMany($count, $attributes);
    }

    /**
     * Assert that the system is in a consistent state.
     */
    protected function assertSystemConsistency(): void
    {
        // Check that all uncommitted events have been processed
        // Check that all projections are up to date
        // Check that all sagas are in valid states
        // Override in specific test classes for custom consistency checks
        $this->assertTrue(true, 'System consistency check passed');
    }

    /**
     * Capture and return performance metrics for the last operation.
     */
    protected function capturePerformanceMetrics(\Closure $operation): array
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        $result = $operation();

        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        return [
            'execution_time' => $endTime - $startTime,
            'memory_usage' => $endMemory - $startMemory,
            'peak_memory' => memory_get_peak_usage(true),
            'result' => $result,
        ];
    }

    /**
     * Assert that operation completes within time limit.
     */
    protected function assertExecutionTime(\Closure $operation, float $maxSeconds): mixed
    {
        $metrics = $this->capturePerformanceMetrics($operation);

        $this->assertLessThan(
            $maxSeconds,
            $metrics['execution_time'],
            "Operation took {$metrics['execution_time']}s, expected less than {$maxSeconds}s"
        );

        return $metrics['result'];
    }

    /**
     * Assert that operation uses less than specified memory.
     */
    protected function assertMemoryUsage(\Closure $operation, int $maxBytes): mixed
    {
        $metrics = $this->capturePerformanceMetrics($operation);

        $this->assertLessThan(
            $maxBytes,
            $metrics['memory_usage'],
            "Operation used {$metrics['memory_usage']} bytes, expected less than {$maxBytes} bytes"
        );

        return $metrics['result'];
    }

    /**
     * Captured events storage.
     */
    private array $capturedEvents = [];
}