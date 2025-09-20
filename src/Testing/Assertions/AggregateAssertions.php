<?php

declare(strict_types=1);

namespace LaravelModularDDD\Testing\Assertions;

use PHPUnit\Framework\TestCase;

/**
 * AggregateAssertions
 *
 * Aggregate-specific assertions for testing aggregate roots, their state,
 * and behavior in event-sourced systems.
 */
trait AggregateAssertions
{
    /**
     * Assert that an aggregate was created successfully.
     */
    public function assertAggregateCreated(object $aggregate, string $message = ''): void
    {
        TestCase::assertNotNull(
            $aggregate,
            $message ?: 'Aggregate was not created'
        );

        // Check if aggregate has an ID
        $id = $this->getAggregateId($aggregate);
        TestCase::assertNotNull(
            $id,
            $message ?: 'Created aggregate has no ID'
        );
    }

    /**
     * Assert that an aggregate is in its initial state.
     */
    public function assertAggregateInInitialState(object $aggregate, string $message = ''): void
    {
        $version = $this->getAggregateVersion($aggregate);

        TestCase::assertEquals(
            0,
            $version,
            $message ?: 'Aggregate is not in initial state (version should be 0)'
        );

        if (method_exists($aggregate, 'getUncommittedEvents')) {
            $events = $aggregate->getUncommittedEvents();
            TestCase::assertEmpty(
                $events,
                $message ?: 'Initial aggregate should have no uncommitted events'
            );
        }
    }

    /**
     * Assert that an aggregate has been modified.
     */
    public function assertAggregateModified(object $aggregate, int $expectedVersion = null, string $message = ''): void
    {
        $version = $this->getAggregateVersion($aggregate);

        if ($expectedVersion !== null) {
            TestCase::assertEquals(
                $expectedVersion,
                $version,
                $message ?: "Expected aggregate version {$expectedVersion}, but got {$version}"
            );
        } else {
            TestCase::assertGreaterThan(
                0,
                $version,
                $message ?: 'Aggregate should have been modified (version > 0)'
            );
        }

        if (method_exists($aggregate, 'getUncommittedEvents')) {
            $events = $aggregate->getUncommittedEvents();
            TestCase::assertNotEmpty(
                $events,
                $message ?: 'Modified aggregate should have uncommitted events'
            );
        }
    }

    /**
     * Assert that an aggregate has a specific number of uncommitted events.
     */
    public function assertAggregateEventCount(object $aggregate, int $expectedCount, string $message = ''): void
    {
        if (!method_exists($aggregate, 'getUncommittedEvents')) {
            TestCase::fail('Aggregate does not implement getUncommittedEvents method');
        }

        $events = $aggregate->getUncommittedEvents();
        $actualCount = count($events);

        TestCase::assertEquals(
            $expectedCount,
            $actualCount,
            $message ?: "Expected {$expectedCount} uncommitted events, but got {$actualCount}"
        );
    }

    /**
     * Assert that an aggregate can be reconstructed from events.
     */
    public function assertAggregateCanBeReconstructed(string $aggregateClass, array $events, string $message = ''): void
    {
        try {
            $aggregate = $this->reconstructAggregateFromEvents($aggregateClass, $events);
            TestCase::assertNotNull(
                $aggregate,
                $message ?: 'Aggregate could not be reconstructed from events'
            );
        } catch (\Exception $e) {
            TestCase::fail($message ?: "Failed to reconstruct aggregate: {$e->getMessage()}");
        }
    }

    /**
     * Assert that aggregate reconstruction produces expected state.
     */
    public function assertAggregateReconstructionState(string $aggregateClass, array $events, array $expectedState, string $message = ''): void
    {
        $aggregate = $this->reconstructAggregateFromEvents($aggregateClass, $events);

        foreach ($expectedState as $property => $expectedValue) {
            $actualValue = $this->getAggregateProperty($aggregate, $property);
            TestCase::assertEquals(
                $expectedValue,
                $actualValue,
                $message ?: "Reconstructed aggregate property '{$property}' does not match expected value"
            );
        }
    }

    /**
     * Assert that an aggregate applies events correctly.
     */
    public function assertAggregateAppliesEvent(object $aggregate, object $event, string $message = ''): void
    {
        $initialVersion = $this->getAggregateVersion($aggregate);

        // Apply the event
        if (method_exists($aggregate, 'apply')) {
            $aggregate->apply($event);
        } elseif (method_exists($aggregate, 'applyEvent')) {
            $aggregate->applyEvent($event);
        } else {
            TestCase::fail('Aggregate does not have an apply method');
        }

        $newVersion = $this->getAggregateVersion($aggregate);

        TestCase::assertGreaterThan(
            $initialVersion,
            $newVersion,
            $message ?: 'Aggregate version should increase after applying event'
        );
    }

    /**
     * Assert that an aggregate produces specific events when performing an action.
     */
    public function assertAggregateProducesEvents(object $aggregate, \Closure $action, array $expectedEventTypes, string $message = ''): void
    {
        $initialEventCount = 0;
        if (method_exists($aggregate, 'getUncommittedEvents')) {
            $initialEventCount = count($aggregate->getUncommittedEvents());
        }

        // Perform the action
        $action($aggregate);

        if (method_exists($aggregate, 'getUncommittedEvents')) {
            $events = $aggregate->getUncommittedEvents();
            $newEvents = array_slice($events, $initialEventCount);
            $actualEventTypes = array_map(fn($event) => get_class($event), $newEvents);

            TestCase::assertEquals(
                $expectedEventTypes,
                $actualEventTypes,
                $message ?: 'Aggregate did not produce expected events'
            );
        } else {
            TestCase::fail('Cannot verify events - aggregate does not implement getUncommittedEvents');
        }
    }

    /**
     * Assert that an aggregate maintains consistency after operations.
     */
    public function assertAggregateConsistency(object $aggregate, array $invariants = [], string $message = ''): void
    {
        // Check basic consistency
        $id = $this->getAggregateId($aggregate);
        TestCase::assertNotNull($id, 'Aggregate must have an ID');

        $version = $this->getAggregateVersion($aggregate);
        TestCase::assertGreaterThanOrEqual(0, $version, 'Aggregate version must be non-negative');

        // Check custom invariants
        foreach ($invariants as $invariant) {
            if (is_callable($invariant)) {
                $result = $invariant($aggregate);
                TestCase::assertTrue(
                    $result,
                    $message ?: 'Aggregate invariant violation'
                );
            } elseif (is_string($invariant) && method_exists($aggregate, $invariant)) {
                $result = $aggregate->{$invariant}();
                TestCase::assertTrue(
                    $result,
                    $message ?: "Aggregate invariant '{$invariant}' violation"
                );
            }
        }
    }

    /**
     * Assert that an aggregate can perform a business operation.
     */
    public function assertAggregateCanPerformOperation(object $aggregate, string $operation, array $parameters = [], string $message = ''): void
    {
        if (!method_exists($aggregate, $operation)) {
            TestCase::fail("Aggregate does not have method '{$operation}'");
        }

        try {
            $aggregate->{$operation}(...$parameters);
            TestCase::assertTrue(true, $message ?: "Aggregate performed operation '{$operation}' successfully");
        } catch (\Exception $e) {
            TestCase::fail($message ?: "Aggregate failed to perform operation '{$operation}': {$e->getMessage()}");
        }
    }

    /**
     * Assert that an aggregate cannot perform a business operation.
     */
    public function assertAggregateCannotPerformOperation(object $aggregate, string $operation, array $parameters = [], string $expectedExceptionClass = null, string $message = ''): void
    {
        if (!method_exists($aggregate, $operation)) {
            TestCase::fail("Aggregate does not have method '{$operation}'");
        }

        $exceptionThrown = false;
        $thrownException = null;

        try {
            $aggregate->{$operation}(...$parameters);
        } catch (\Exception $e) {
            $exceptionThrown = true;
            $thrownException = $e;
        }

        TestCase::assertTrue(
            $exceptionThrown,
            $message ?: "Expected operation '{$operation}' to fail but it succeeded"
        );

        if ($expectedExceptionClass && $thrownException) {
            TestCase::assertInstanceOf(
                $expectedExceptionClass,
                $thrownException,
                "Expected exception of type '{$expectedExceptionClass}'"
            );
        }
    }

    /**
     * Assert that an aggregate has specific child entities.
     */
    public function assertAggregateHasEntities(object $aggregate, string $entityCollection, int $expectedCount, string $message = ''): void
    {
        $entities = $this->getAggregateProperty($aggregate, $entityCollection);

        if (is_array($entities)) {
            $actualCount = count($entities);
        } elseif (is_object($entities) && method_exists($entities, 'count')) {
            $actualCount = $entities->count();
        } else {
            TestCase::fail("Cannot count entities in collection '{$entityCollection}'");
        }

        TestCase::assertEquals(
            $expectedCount,
            $actualCount,
            $message ?: "Expected {$expectedCount} entities in '{$entityCollection}', but got {$actualCount}"
        );
    }

    /**
     * Assert that an aggregate has no uncommitted events after commit.
     */
    public function assertAggregateCommitted(object $aggregate, string $message = ''): void
    {
        if (!method_exists($aggregate, 'getUncommittedEvents')) {
            TestCase::fail('Aggregate does not implement getUncommittedEvents method');
        }

        $events = $aggregate->getUncommittedEvents();

        TestCase::assertEmpty(
            $events,
            $message ?: 'Committed aggregate should have no uncommitted events'
        );
    }

    /**
     * Assert that an aggregate has proper event ordering.
     */
    public function assertAggregateEventOrdering(object $aggregate, string $message = ''): void
    {
        if (!method_exists($aggregate, 'getUncommittedEvents')) {
            TestCase::fail('Aggregate does not implement getUncommittedEvents method');
        }

        $events = $aggregate->getUncommittedEvents();
        $baseVersion = $this->getAggregateVersion($aggregate) - count($events);

        foreach ($events as $index => $event) {
            $expectedVersion = $baseVersion + $index + 1;
            $actualVersion = $this->getEventVersion($event);

            TestCase::assertEquals(
                $expectedVersion,
                $actualVersion,
                $message ?: "Event at index {$index} has incorrect version"
            );
        }
    }

    /**
     * Assert that aggregate snapshots work correctly.
     */
    public function assertAggregateSnapshotting(object $aggregate, string $message = ''): void
    {
        if (!method_exists($aggregate, 'createSnapshot')) {
            TestCase::markTestSkipped('Aggregate does not support snapshots');
        }

        $snapshot = $aggregate->createSnapshot();

        TestCase::assertNotNull(
            $snapshot,
            $message ?: 'Aggregate snapshot should not be null'
        );

        // Verify snapshot contains aggregate state
        TestCase::assertEquals(
            $this->getAggregateId($aggregate),
            $this->getSnapshotAggregateId($snapshot),
            'Snapshot should contain correct aggregate ID'
        );

        TestCase::assertEquals(
            $this->getAggregateVersion($aggregate),
            $this->getSnapshotVersion($snapshot),
            'Snapshot should contain correct version'
        );
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

        return $this->getAggregateProperty($aggregate, 'id');
    }

    /**
     * Get aggregate version.
     */
    private function getAggregateVersion(object $aggregate): int
    {
        $methods = ['getVersion', 'getAggregateVersion', 'version'];

        foreach ($methods as $method) {
            if (method_exists($aggregate, $method)) {
                return (int) $aggregate->{$method}();
            }
        }

        // Try to get version from events
        if (method_exists($aggregate, 'getUncommittedEvents')) {
            return count($aggregate->getUncommittedEvents());
        }

        return 0;
    }

    /**
     * Get aggregate property value.
     */
    private function getAggregateProperty(object $aggregate, string $property)
    {
        // Try getter methods
        $getter = 'get' . ucfirst($property);
        if (method_exists($aggregate, $getter)) {
            return $aggregate->{$getter}();
        }

        // Try direct property access
        if (property_exists($aggregate, $property)) {
            $reflection = new \ReflectionProperty($aggregate, $property);
            $reflection->setAccessible(true);
            return $reflection->getValue($aggregate);
        }

        return null;
    }

    /**
     * Get event version from event.
     */
    private function getEventVersion(object $event): int
    {
        $methods = ['getVersion', 'getAggregateVersion'];

        foreach ($methods as $method) {
            if (method_exists($event, $method)) {
                return (int) $event->{$method}();
            }
        }

        return 1;
    }

    /**
     * Reconstruct aggregate from events (mock implementation).
     */
    private function reconstructAggregateFromEvents(string $aggregateClass, array $events): object
    {
        // This would be implemented using your event sourcing infrastructure
        // For now, return a mock aggregate
        if (class_exists($aggregateClass)) {
            try {
                return new $aggregateClass();
            } catch (\Exception $e) {
                throw new \RuntimeException("Cannot reconstruct aggregate: {$e->getMessage()}");
            }
        }

        throw new \RuntimeException("Aggregate class {$aggregateClass} does not exist");
    }

    /**
     * Get snapshot aggregate ID.
     */
    private function getSnapshotAggregateId(object $snapshot)
    {
        $methods = ['getAggregateId', 'getId'];

        foreach ($methods as $method) {
            if (method_exists($snapshot, $method)) {
                return $snapshot->{$method}();
            }
        }

        return null;
    }

    /**
     * Get snapshot version.
     */
    private function getSnapshotVersion(object $snapshot): int
    {
        $methods = ['getVersion', 'getAggregateVersion'];

        foreach ($methods as $method) {
            if (method_exists($snapshot, $method)) {
                return (int) $snapshot->{$method}();
            }
        }

        return 0;
    }
}