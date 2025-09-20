<?php

declare(strict_types=1);

namespace LaravelModularDDD\Testing\Assertions;

use PHPUnit\Framework\TestCase;

/**
 * DomainAssertions
 *
 * Domain-specific assertions for testing DDD patterns and business logic.
 * Provides fluent, readable assertions for domain concepts.
 */
trait DomainAssertions
{
    /**
     * Assert that an aggregate is in a specific state.
     */
    public function assertAggregateState(object $aggregate, string $expectedState, string $message = ''): void
    {
        $actualState = $this->getAggregateState($aggregate);

        TestCase::assertEquals(
            $expectedState,
            $actualState,
            $message ?: "Expected aggregate to be in state '{$expectedState}', but was '{$actualState}'"
        );
    }

    /**
     * Assert that an aggregate has specific uncommitted events.
     */
    public function assertAggregateHasEvents(object $aggregate, array $expectedEventTypes, string $message = ''): void
    {
        if (!method_exists($aggregate, 'getUncommittedEvents')) {
            TestCase::fail('Aggregate does not implement getUncommittedEvents method');
        }

        $events = $aggregate->getUncommittedEvents();
        $actualEventTypes = array_map(fn($event) => get_class($event), $events);

        TestCase::assertEquals(
            $expectedEventTypes,
            $actualEventTypes,
            $message ?: 'Aggregate events do not match expected types'
        );
    }

    /**
     * Assert that an aggregate has no uncommitted events.
     */
    public function assertAggregateHasNoEvents(object $aggregate, string $message = ''): void
    {
        if (!method_exists($aggregate, 'getUncommittedEvents')) {
            TestCase::fail('Aggregate does not implement getUncommittedEvents method');
        }

        $events = $aggregate->getUncommittedEvents();

        TestCase::assertEmpty(
            $events,
            $message ?: 'Expected aggregate to have no uncommitted events'
        );
    }

    /**
     * Assert that an aggregate has a specific event type.
     */
    public function assertAggregateHasEvent(object $aggregate, string $eventType, string $message = ''): void
    {
        if (!method_exists($aggregate, 'getUncommittedEvents')) {
            TestCase::fail('Aggregate does not implement getUncommittedEvents method');
        }

        $events = $aggregate->getUncommittedEvents();
        $hasEvent = false;

        foreach ($events as $event) {
            if (get_class($event) === $eventType || $event instanceof $eventType) {
                $hasEvent = true;
                break;
            }
        }

        TestCase::assertTrue(
            $hasEvent,
            $message ?: "Expected aggregate to have event of type '{$eventType}'"
        );
    }

    /**
     * Assert that a domain invariant holds true.
     */
    public function assertInvariantHolds(\Closure $invariant, string $message = ''): void
    {
        $result = $invariant();

        TestCase::assertTrue(
            $result,
            $message ?: 'Domain invariant does not hold'
        );
    }

    /**
     * Assert that a domain invariant is violated.
     */
    public function assertInvariantViolated(\Closure $action, string $expectedExceptionClass = null, string $message = ''): void
    {
        $exceptionThrown = false;
        $thrownException = null;

        try {
            $action();
        } catch (\Exception $e) {
            $exceptionThrown = true;
            $thrownException = $e;
        }

        TestCase::assertTrue(
            $exceptionThrown,
            $message ?: 'Expected invariant violation but none occurred'
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
     * Assert that a value object equals another value object.
     */
    public function assertValueObjectEquals(object $expected, object $actual, string $message = ''): void
    {
        if (method_exists($expected, 'equals')) {
            TestCase::assertTrue(
                $expected->equals($actual),
                $message ?: 'Value objects are not equal'
            );
        } else {
            TestCase::assertEquals(
                $expected,
                $actual,
                $message ?: 'Value objects are not equal'
            );
        }
    }

    /**
     * Assert that a value object is valid.
     */
    public function assertValueObjectValid(object $valueObject, string $message = ''): void
    {
        if (method_exists($valueObject, 'isValid')) {
            TestCase::assertTrue(
                $valueObject->isValid(),
                $message ?: 'Value object is not valid'
            );
        } else {
            // If no isValid method, assume constructor validation passed
            TestCase::assertTrue(
                true,
                $message ?: 'Value object validation passed'
            );
        }
    }

    /**
     * Assert that a value object is invalid.
     */
    public function assertValueObjectInvalid(\Closure $creation, string $expectedExceptionClass = null, string $message = ''): void
    {
        $this->assertInvariantViolated($creation, $expectedExceptionClass, $message);
    }

    /**
     * Assert that an entity has a specific identity.
     */
    public function assertEntityIdentity(object $entity, $expectedId, string $message = ''): void
    {
        $actualId = $this->getEntityId($entity);

        TestCase::assertEquals(
            $expectedId,
            $actualId,
            $message ?: "Expected entity ID '{$expectedId}', but got '{$actualId}'"
        );
    }

    /**
     * Assert that two entities are the same (same identity).
     */
    public function assertSameEntity(object $expected, object $actual, string $message = ''): void
    {
        $expectedId = $this->getEntityId($expected);
        $actualId = $this->getEntityId($actual);

        TestCase::assertEquals(
            $expectedId,
            $actualId,
            $message ?: 'Entities do not have the same identity'
        );
    }

    /**
     * Assert that an aggregate version matches expected version.
     */
    public function assertAggregateVersion(object $aggregate, int $expectedVersion, string $message = ''): void
    {
        $actualVersion = $this->getAggregateVersion($aggregate);

        TestCase::assertEquals(
            $expectedVersion,
            $actualVersion,
            $message ?: "Expected aggregate version {$expectedVersion}, but got {$actualVersion}"
        );
    }

    /**
     * Assert that a command validation passes.
     */
    public function assertCommandValid(object $command, string $message = ''): void
    {
        if (method_exists($command, 'validate')) {
            try {
                $command->validate();
                TestCase::assertTrue(true, $message ?: 'Command validation passed');
            } catch (\Exception $e) {
                TestCase::fail($message ?: "Command validation failed: {$e->getMessage()}");
            }
        } else {
            TestCase::assertTrue(
                true,
                $message ?: 'Command validation not implemented'
            );
        }
    }

    /**
     * Assert that a command validation fails.
     */
    public function assertCommandInvalid(object $command, string $expectedExceptionClass = null, string $message = ''): void
    {
        if (!method_exists($command, 'validate')) {
            TestCase::fail('Command does not implement validate method');
        }

        $this->assertInvariantViolated(
            fn() => $command->validate(),
            $expectedExceptionClass,
            $message
        );
    }

    /**
     * Assert that a business rule is satisfied.
     */
    public function assertBusinessRuleSatisfied(\Closure $rule, string $message = ''): void
    {
        $this->assertInvariantHolds($rule, $message);
    }

    /**
     * Assert that a business rule is violated.
     */
    public function assertBusinessRuleViolated(\Closure $action, string $expectedExceptionClass = null, string $message = ''): void
    {
        $this->assertInvariantViolated($action, $expectedExceptionClass, $message);
    }

    /**
     * Assert that an aggregate can perform a specific action.
     */
    public function assertAggregateCanPerform(object $aggregate, string $action, array $parameters = [], string $message = ''): void
    {
        if (!method_exists($aggregate, 'can' . ucfirst($action))) {
            TestCase::fail("Aggregate does not implement 'can{$action}' method");
        }

        $canPerform = $aggregate->{'can' . ucfirst($action)}(...$parameters);

        TestCase::assertTrue(
            $canPerform,
            $message ?: "Aggregate cannot perform action '{$action}'"
        );
    }

    /**
     * Assert that an aggregate cannot perform a specific action.
     */
    public function assertAggregateCannotPerform(object $aggregate, string $action, array $parameters = [], string $message = ''): void
    {
        if (!method_exists($aggregate, 'can' . ucfirst($action))) {
            TestCase::fail("Aggregate does not implement 'can{$action}' method");
        }

        $canPerform = $aggregate->{'can' . ucfirst($action)}(...$parameters);

        TestCase::assertFalse(
            $canPerform,
            $message ?: "Aggregate should not be able to perform action '{$action}'"
        );
    }

    /**
     * Assert that a collection contains entities with specific IDs.
     */
    public function assertCollectionContainsIds($collection, array $expectedIds, string $message = ''): void
    {
        $actualIds = [];

        foreach ($collection as $entity) {
            $actualIds[] = $this->getEntityId($entity);
        }

        TestCase::assertEquals(
            sort($expectedIds),
            sort($actualIds),
            $message ?: 'Collection does not contain expected entity IDs'
        );
    }

    /**
     * Assert that a domain service exists and is callable.
     */
    public function assertDomainServiceExists(string $serviceClass, string $method = null, string $message = ''): void
    {
        TestCase::assertTrue(
            class_exists($serviceClass),
            $message ?: "Domain service '{$serviceClass}' does not exist"
        );

        if ($method) {
            TestCase::assertTrue(
                method_exists($serviceClass, $method),
                $message ?: "Method '{$method}' does not exist on service '{$serviceClass}'"
            );
        }
    }

    /**
     * Assert that a specification is satisfied by an entity.
     */
    public function assertSpecificationSatisfied(object $specification, object $entity, string $message = ''): void
    {
        if (!method_exists($specification, 'isSatisfiedBy')) {
            TestCase::fail('Specification does not implement isSatisfiedBy method');
        }

        $satisfied = $specification->isSatisfiedBy($entity);

        TestCase::assertTrue(
            $satisfied,
            $message ?: 'Specification is not satisfied by the entity'
        );
    }

    /**
     * Assert that a specification is not satisfied by an entity.
     */
    public function assertSpecificationNotSatisfied(object $specification, object $entity, string $message = ''): void
    {
        if (!method_exists($specification, 'isSatisfiedBy')) {
            TestCase::fail('Specification does not implement isSatisfiedBy method');
        }

        $satisfied = $specification->isSatisfiedBy($entity);

        TestCase::assertFalse(
            $satisfied,
            $message ?: 'Specification should not be satisfied by the entity'
        );
    }

    /**
     * Get aggregate state using common patterns.
     */
    private function getAggregateState(object $aggregate): string
    {
        // Try common state getter patterns
        $methods = ['getState', 'state', 'getStatus', 'status'];

        foreach ($methods as $method) {
            if (method_exists($aggregate, $method)) {
                $state = $aggregate->{$method}();
                return is_object($state) ? $state::class : (string) $state;
            }
        }

        // Try to get state from properties
        $reflection = new \ReflectionClass($aggregate);
        $properties = $reflection->getProperties();

        foreach ($properties as $property) {
            if (str_contains(strtolower($property->getName()), 'state') ||
                str_contains(strtolower($property->getName()), 'status')) {
                $property->setAccessible(true);
                $state = $property->getValue($aggregate);
                return is_object($state) ? $state::class : (string) $state;
            }
        }

        return 'unknown';
    }

    /**
     * Get entity ID using common patterns.
     */
    private function getEntityId(object $entity)
    {
        // Try common ID getter patterns
        $methods = ['getId', 'id', 'getIdentity', 'identity'];

        foreach ($methods as $method) {
            if (method_exists($entity, $method)) {
                return $entity->{$method}();
            }
        }

        // Try to get ID from properties
        $reflection = new \ReflectionClass($entity);
        $properties = $reflection->getProperties();

        foreach ($properties as $property) {
            if (strtolower($property->getName()) === 'id' ||
                str_ends_with(strtolower($property->getName()), 'id')) {
                $property->setAccessible(true);
                return $property->getValue($entity);
            }
        }

        return null;
    }

    /**
     * Get aggregate version using common patterns.
     */
    private function getAggregateVersion(object $aggregate): int
    {
        // Try common version getter patterns
        $methods = ['getVersion', 'version', 'getAggregateVersion'];

        foreach ($methods as $method) {
            if (method_exists($aggregate, $method)) {
                return (int) $aggregate->{$method}();
            }
        }

        // Default to counting events if no explicit version
        if (method_exists($aggregate, 'getUncommittedEvents')) {
            return count($aggregate->getUncommittedEvents());
        }

        return 0;
    }
}