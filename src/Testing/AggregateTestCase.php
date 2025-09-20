<?php

declare(strict_types=1);

namespace LaravelModularDDD\Testing;

use LaravelModularDDD\Testing\ModuleTestCase;

/**
 * AggregateTestCase
 *
 * Base test case specifically for testing domain aggregates.
 * Provides utilities for aggregate lifecycle testing and business rule validation.
 */
abstract class AggregateTestCase extends ModuleTestCase
{
    protected string $aggregateClass;
    protected object $aggregate;

    /**
     * Set up aggregate testing environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->aggregateClass) {
            throw new \RuntimeException('Aggregate class must be defined in test class');
        }

        if (!class_exists($this->aggregateClass)) {
            throw new \RuntimeException("Aggregate class {$this->aggregateClass} does not exist");
        }

        $this->setUpAggregate();
    }

    /**
     * Set up aggregate instance - override in specific test classes.
     */
    protected function setUpAggregate(): void
    {
        // Override in specific test classes to create aggregate instance
    }

    /**
     * Test aggregate creation with valid data.
     */
    public function test_aggregate_can_be_created_with_valid_data(): void
    {
        $validData = $this->getValidAggregateData();
        $aggregate = $this->createAggregateWithData($validData);

        $this->assertInstanceOf($this->aggregateClass, $aggregate);
        $this->assertAggregateHasUncommittedEvents($aggregate);
    }

    /**
     * Test aggregate creation fails with invalid data.
     */
    public function test_aggregate_creation_fails_with_invalid_data(): void
    {
        $invalidDataSets = $this->getInvalidAggregateDataSets();

        foreach ($invalidDataSets as $description => $invalidData) {
            try {
                $this->createAggregateWithData($invalidData);
                $this->fail("Aggregate creation should have failed with: {$description}");
            } catch (\Exception $e) {
                $this->assertInstanceOf(\DomainException::class, $e);
            }
        }
    }

    /**
     * Test aggregate state changes through business methods.
     */
    public function test_aggregate_state_changes(): void
    {
        $aggregate = $this->createValidAggregate();
        $initialState = $this->captureAggregateState($aggregate);

        $this->performStateChangingOperations($aggregate);

        $finalState = $this->captureAggregateState($aggregate);
        $this->assertAggregateStateChanged($initialState, $finalState);
    }

    /**
     * Test aggregate business rule enforcement.
     */
    public function test_aggregate_enforces_business_rules(): void
    {
        $aggregate = $this->createValidAggregate();
        $businessRuleViolations = $this->getBusinessRuleViolations();

        foreach ($businessRuleViolations as $description => $violation) {
            try {
                $violation($aggregate);
                $this->fail("Business rule violation should have been caught: {$description}");
            } catch (\DomainException $e) {
                $this->assertStringContainsString('business rule', strtolower($e->getMessage()));
            }
        }
    }

    /**
     * Test aggregate event emission.
     */
    public function test_aggregate_emits_expected_events(): void
    {
        $aggregate = $this->createValidAggregate();
        $expectedEvents = $this->getExpectedEvents();

        $this->performEventTriggeringOperations($aggregate);

        $events = $aggregate->getUncommittedEvents();

        foreach ($expectedEvents as $eventClass) {
            $found = false;
            foreach ($events as $event) {
                if (is_a($event, $eventClass)) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, "Expected event {$eventClass} was not emitted");
        }
    }

    /**
     * Test aggregate version tracking.
     */
    public function test_aggregate_tracks_version(): void
    {
        $aggregate = $this->createValidAggregate();

        if (!method_exists($aggregate, 'getVersion')) {
            $this->markTestSkipped('Aggregate does not implement version tracking');
        }

        $initialVersion = $aggregate->getVersion();
        $this->assertIsInt($initialVersion);
        $this->assertGreaterThanOrEqual(1, $initialVersion);

        // Perform operations that should increment version
        $this->performVersionIncrementingOperations($aggregate);

        $newVersion = $aggregate->getVersion();
        $this->assertGreaterThan($initialVersion, $newVersion);
    }

    /**
     * Test aggregate equality comparison.
     */
    public function test_aggregate_equality(): void
    {
        $aggregate1 = $this->createValidAggregate();
        $aggregate2 = $this->createValidAggregate();

        if (!method_exists($aggregate1, 'getId')) {
            $this->markTestSkipped('Aggregate does not implement getId method');
        }

        // Different aggregates should not be equal
        $this->assertFalse($aggregate1->getId()->equals($aggregate2->getId()));

        // Same aggregate should be equal to itself
        $this->assertTrue($aggregate1->getId()->equals($aggregate1->getId()));
    }

    /**
     * Test aggregate serialization and reconstruction.
     */
    public function test_aggregate_can_be_reconstructed_from_events(): void
    {
        $originalAggregate = $this->createValidAggregate();
        $this->performStateChangingOperations($originalAggregate);

        $events = $originalAggregate->getUncommittedEvents();
        $this->assertNotEmpty($events);

        // Reconstruct aggregate from events
        $reconstructedAggregate = $this->reconstructAggregateFromEvents($events);

        $this->assertAggregateStatesEqual($originalAggregate, $reconstructedAggregate);
    }

    /**
     * Test aggregate invariant validation.
     */
    public function test_aggregate_maintains_invariants(): void
    {
        $aggregate = $this->createValidAggregate();

        // Perform various operations
        $operations = $this->getInvariantTestingOperations();

        foreach ($operations as $description => $operation) {
            $operation($aggregate);
            $this->assertAggregateInvariantsValid($aggregate, $description);
        }
    }

    /**
     * Test aggregate performance characteristics.
     */
    public function test_aggregate_performance(): void
    {
        $performanceTests = $this->getPerformanceTests();

        foreach ($performanceTests as $testName => $testConfig) {
            $operation = $testConfig['operation'];
            $maxTime = $testConfig['max_time'] ?? 1.0;
            $maxMemory = $testConfig['max_memory'] ?? 1024 * 1024; // 1MB

            $result = $this->assertExecutionTime($operation, $maxTime);
            $this->assertMemoryUsage($operation, $maxMemory);
        }
    }

    /**
     * Get valid data for aggregate creation - override in specific test classes.
     */
    abstract protected function getValidAggregateData(): array;

    /**
     * Get invalid data sets for testing - override in specific test classes.
     */
    abstract protected function getInvalidAggregateDataSets(): array;

    /**
     * Get business rule violations for testing - override in specific test classes.
     */
    abstract protected function getBusinessRuleViolations(): array;

    /**
     * Get expected events for testing - override in specific test classes.
     */
    protected function getExpectedEvents(): array
    {
        return [];
    }

    /**
     * Create aggregate with specific data.
     */
    protected function createAggregateWithData(array $data): object
    {
        return $this->createTestAggregate($this->aggregateClass, $data);
    }

    /**
     * Create a valid aggregate instance.
     */
    protected function createValidAggregate(): object
    {
        return $this->createAggregateWithData($this->getValidAggregateData());
    }

    /**
     * Perform state changing operations - override in specific test classes.
     */
    protected function performStateChangingOperations(object $aggregate): void
    {
        // Override in specific test classes
    }

    /**
     * Perform event triggering operations - override in specific test classes.
     */
    protected function performEventTriggeringOperations(object $aggregate): void
    {
        $this->performStateChangingOperations($aggregate);
    }

    /**
     * Perform version incrementing operations - override in specific test classes.
     */
    protected function performVersionIncrementingOperations(object $aggregate): void
    {
        $this->performStateChangingOperations($aggregate);
    }

    /**
     * Capture current aggregate state.
     */
    protected function captureAggregateState(object $aggregate): array
    {
        $state = [];
        $reflection = new \ReflectionClass($aggregate);

        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            if (str_starts_with($method->getName(), 'get') && $method->getNumberOfParameters() === 0) {
                $property = lcfirst(substr($method->getName(), 3));
                try {
                    $state[$property] = $method->invoke($aggregate);
                } catch (\Exception $e) {
                    // Ignore methods that can't be called
                }
            }
        }

        return $state;
    }

    /**
     * Assert that aggregate state has changed.
     */
    protected function assertAggregateStateChanged(array $initialState, array $finalState): void
    {
        $changed = false;

        foreach ($initialState as $property => $initialValue) {
            if (isset($finalState[$property]) && $finalState[$property] !== $initialValue) {
                $changed = true;
                break;
            }
        }

        $this->assertTrue($changed, 'Aggregate state should have changed');
    }

    /**
     * Reconstruct aggregate from events.
     */
    protected function reconstructAggregateFromEvents(array $events): object
    {
        if (empty($events)) {
            throw new \InvalidArgumentException('Cannot reconstruct aggregate from empty event list');
        }

        $firstEvent = $events[0];
        $aggregateId = $firstEvent->getAggregateId();

        // Create aggregate from first event
        $reflection = new \ReflectionClass($this->aggregateClass);

        if ($reflection->hasMethod('reconstituteFromEvents')) {
            return $this->aggregateClass::reconstituteFromEvents($aggregateId, $events);
        }

        // Fallback: apply events one by one
        $aggregate = $this->createEmptyAggregate($aggregateId);

        foreach ($events as $event) {
            $this->applyEventToAggregate($aggregate, $event);
        }

        return $aggregate;
    }

    /**
     * Create empty aggregate for reconstruction.
     */
    protected function createEmptyAggregate($aggregateId): object
    {
        $reflection = new \ReflectionClass($this->aggregateClass);

        if ($reflection->hasMethod('createEmpty')) {
            return $this->aggregateClass::createEmpty($aggregateId);
        }

        throw new \RuntimeException('Aggregate does not support empty creation for reconstruction');
    }

    /**
     * Apply event to aggregate.
     */
    protected function applyEventToAggregate(object $aggregate, object $event): void
    {
        $eventClass = get_class($event);
        $methodName = 'apply' . class_basename($eventClass);

        if (method_exists($aggregate, $methodName)) {
            $aggregate->{$methodName}($event);
        }
    }

    /**
     * Assert that two aggregates have equal states.
     */
    protected function assertAggregateStatesEqual(object $aggregate1, object $aggregate2): void
    {
        $state1 = $this->captureAggregateState($aggregate1);
        $state2 = $this->captureAggregateState($aggregate2);

        foreach ($state1 as $property => $value1) {
            if (isset($state2[$property])) {
                $this->assertEquals(
                    $value1,
                    $state2[$property],
                    "Property {$property} differs between aggregates"
                );
            }
        }
    }

    /**
     * Assert that aggregate invariants are valid.
     */
    protected function assertAggregateInvariantsValid(object $aggregate, string $context = ''): void
    {
        $invariants = $this->getAggregateInvariants();

        foreach ($invariants as $invariantName => $invariantCheck) {
            $this->assertTrue(
                $invariantCheck($aggregate),
                "Invariant '{$invariantName}' violated" . ($context ? " in context: {$context}" : '')
            );
        }
    }

    /**
     * Get aggregate invariants - override in specific test classes.
     */
    protected function getAggregateInvariants(): array
    {
        return [];
    }

    /**
     * Get operations for invariant testing - override in specific test classes.
     */
    protected function getInvariantTestingOperations(): array
    {
        return [];
    }

    /**
     * Get performance tests - override in specific test classes.
     */
    protected function getPerformanceTests(): array
    {
        return [];
    }
}