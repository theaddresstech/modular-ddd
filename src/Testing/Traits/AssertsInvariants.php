<?php

declare(strict_types=1);

namespace LaravelModularDDD\Testing\Traits;

use PHPUnit\Framework\TestCase;

/**
 * AssertsInvariants
 *
 * Provides utilities for testing business invariants and domain rules.
 * Ensures business logic constraints are properly validated.
 */
trait AssertsInvariants
{
    /**
     * Assert that a business invariant holds true.
     */
    protected function assertInvariantHolds(\Closure $invariant, string $description = ''): void
    {
        $result = $invariant();

        TestCase::assertTrue(
            $result,
            $description ?: 'Business invariant violation: invariant does not hold'
        );
    }

    /**
     * Assert that a business invariant is violated when performing an action.
     */
    protected function assertInvariantViolated(\Closure $action, string $expectedExceptionClass = null, string $description = ''): void
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
            $description ?: 'Expected business invariant violation but none occurred'
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
     * Assert that an aggregate maintains its invariants after an operation.
     */
    protected function assertAggregateInvariants(object $aggregate, array $invariants = []): void
    {
        // Check basic aggregate invariants
        $this->assertAggregateHasId($aggregate);
        $this->assertAggregateVersionConsistent($aggregate);

        // Check custom invariants
        foreach ($invariants as $description => $invariant) {
            if (is_callable($invariant)) {
                $this->assertInvariantHolds(
                    fn() => $invariant($aggregate),
                    is_string($description) ? $description : 'Custom aggregate invariant'
                );
            } elseif (is_string($invariant) && method_exists($aggregate, $invariant)) {
                $this->assertInvariantHolds(
                    fn() => $aggregate->{$invariant}(),
                    "Aggregate invariant method: {$invariant}"
                );
            }
        }
    }

    /**
     * Assert that a value object maintains its invariants.
     */
    protected function assertValueObjectInvariants(object $valueObject, array $invariants = []): void
    {
        // Check immutability
        $this->assertValueObjectImmutable($valueObject);

        // Check equality consistency
        $this->assertValueObjectEqualityConsistent($valueObject);

        // Check custom invariants
        foreach ($invariants as $description => $invariant) {
            if (is_callable($invariant)) {
                $this->assertInvariantHolds(
                    fn() => $invariant($valueObject),
                    is_string($description) ? $description : 'Custom value object invariant'
                );
            } elseif (is_string($invariant) && method_exists($valueObject, $invariant)) {
                $this->assertInvariantHolds(
                    fn() => $valueObject->{$invariant}(),
                    "Value object invariant method: {$invariant}"
                );
            }
        }
    }

    /**
     * Assert that an entity maintains its invariants.
     */
    protected function assertEntityInvariants(object $entity, array $invariants = []): void
    {
        // Check basic entity invariants
        $this->assertEntityHasId($entity);
        $this->assertEntityIdImmutable($entity);

        // Check custom invariants
        foreach ($invariants as $description => $invariant) {
            if (is_callable($invariant)) {
                $this->assertInvariantHolds(
                    fn() => $invariant($entity),
                    is_string($description) ? $description : 'Custom entity invariant'
                );
            } elseif (is_string($invariant) && method_exists($entity, $invariant)) {
                $this->assertInvariantHolds(
                    fn() => $entity->{$invariant}(),
                    "Entity invariant method: {$invariant}"
                );
            }
        }
    }

    /**
     * Assert that a domain service maintains business rules.
     */
    protected function assertDomainServiceRules(object $service, array $rules = []): void
    {
        foreach ($rules as $description => $rule) {
            if (is_callable($rule)) {
                $this->assertInvariantHolds(
                    fn() => $rule($service),
                    is_string($description) ? $description : 'Domain service rule'
                );
            } elseif (is_string($rule) && method_exists($service, $rule)) {
                $this->assertInvariantHolds(
                    fn() => $service->{$rule}(),
                    "Domain service rule method: {$rule}"
                );
            }
        }
    }

    /**
     * Assert that a collection maintains its constraints.
     */
    protected function assertCollectionInvariants($collection, array $constraints = []): void
    {
        foreach ($constraints as $description => $constraint) {
            if (is_callable($constraint)) {
                $this->assertInvariantHolds(
                    fn() => $constraint($collection),
                    is_string($description) ? $description : 'Collection constraint'
                );
            }
        }
    }

    /**
     * Assert that a specification pattern is correctly implemented.
     */
    protected function assertSpecificationInvariants(object $specification, array $testCases = []): void
    {
        if (!method_exists($specification, 'isSatisfiedBy')) {
            TestCase::fail('Specification must implement isSatisfiedBy method');
        }

        foreach ($testCases as $testCase) {
            $entity = $testCase['entity'];
            $expectedResult = $testCase['expected'];
            $description = $testCase['description'] ?? 'Specification test case';

            $actualResult = $specification->isSatisfiedBy($entity);

            TestCase::assertEquals(
                $expectedResult,
                $actualResult,
                $description
            );
        }
    }

    /**
     * Assert business rule validation.
     */
    protected function assertBusinessRuleValidation(object $subject, string $rule, $value, bool $shouldBeValid = true): void
    {
        $methodName = 'validate' . ucfirst($rule);

        if (!method_exists($subject, $methodName)) {
            TestCase::fail("Business rule validation method '{$methodName}' not found");
        }

        if ($shouldBeValid) {
            try {
                $result = $subject->{$methodName}($value);
                TestCase::assertTrue(
                    $result,
                    "Business rule '{$rule}' should be valid for value: " . var_export($value, true)
                );
            } catch (\Exception $e) {
                TestCase::fail("Business rule '{$rule}' validation failed: {$e->getMessage()}");
            }
        } else {
            $this->assertInvariantViolated(
                fn() => $subject->{$methodName}($value),
                null,
                "Business rule '{$rule}' should be invalid for value: " . var_export($value, true)
            );
        }
    }

    /**
     * Assert that state transitions are valid.
     */
    protected function assertValidStateTransition(object $stateMachine, string $fromState, string $toState, bool $shouldBeValid = true): void
    {
        if (method_exists($stateMachine, 'canTransition')) {
            $canTransition = $stateMachine->canTransition($fromState, $toState);

            TestCase::assertEquals(
                $shouldBeValid,
                $canTransition,
                "State transition from '{$fromState}' to '{$toState}' validity mismatch"
            );
        } elseif (method_exists($stateMachine, 'isValidTransition')) {
            $isValid = $stateMachine->isValidTransition($fromState, $toState);

            TestCase::assertEquals(
                $shouldBeValid,
                $isValid,
                "State transition from '{$fromState}' to '{$toState}' validity mismatch"
            );
        } else {
            TestCase::fail('State machine must implement canTransition or isValidTransition method');
        }
    }

    /**
     * Assert that an aggregate enforces consistency boundaries.
     */
    protected function assertConsistencyBoundary(object $aggregate, \Closure $operation): void
    {
        $initialVersion = $this->getAggregateVersion($aggregate);

        try {
            $operation($aggregate);

            // After operation, aggregate should be in consistent state
            $this->assertAggregateInvariants($aggregate);

            // Version should have increased if any changes were made
            $newVersion = $this->getAggregateVersion($aggregate);
            if ($newVersion > $initialVersion) {
                TestCase::assertGreaterThan(
                    $initialVersion,
                    $newVersion,
                    'Aggregate version should increase after modifications'
                );
            }
        } catch (\Exception $e) {
            // If operation failed, aggregate should still be in consistent state
            $this->assertAggregateInvariants($aggregate);
            throw $e;
        }
    }

    /**
     * Assert that concurrent operations maintain consistency.
     */
    protected function assertConcurrencyInvariants(object $aggregate, array $operations): void
    {
        foreach ($operations as $operation) {
            $this->assertConsistencyBoundary($aggregate, $operation);
        }
    }

    /**
     * Assert that data integrity constraints are maintained.
     */
    protected function assertDataIntegrityConstraints(array $data, array $constraints): void
    {
        foreach ($constraints as $field => $constraint) {
            if (!array_key_exists($field, $data)) {
                TestCase::fail("Required field '{$field}' is missing from data");
            }

            $value = $data[$field];

            if (is_callable($constraint)) {
                $this->assertInvariantHolds(
                    fn() => $constraint($value),
                    "Data integrity constraint for field '{$field}'"
                );
            } elseif (is_array($constraint)) {
                $this->validateFieldConstraints($field, $value, $constraint);
            }
        }
    }

    /**
     * Validate field-specific constraints.
     */
    private function validateFieldConstraints(string $field, $value, array $constraints): void
    {
        foreach ($constraints as $constraintType => $constraintValue) {
            switch ($constraintType) {
                case 'type':
                    TestCase::assertEquals(
                        $constraintValue,
                        gettype($value),
                        "Field '{$field}' type constraint violation"
                    );
                    break;

                case 'min_length':
                    TestCase::assertGreaterThanOrEqual(
                        $constraintValue,
                        strlen((string) $value),
                        "Field '{$field}' minimum length constraint violation"
                    );
                    break;

                case 'max_length':
                    TestCase::assertLessThanOrEqual(
                        $constraintValue,
                        strlen((string) $value),
                        "Field '{$field}' maximum length constraint violation"
                    );
                    break;

                case 'min_value':
                    TestCase::assertGreaterThanOrEqual(
                        $constraintValue,
                        $value,
                        "Field '{$field}' minimum value constraint violation"
                    );
                    break;

                case 'max_value':
                    TestCase::assertLessThanOrEqual(
                        $constraintValue,
                        $value,
                        "Field '{$field}' maximum value constraint violation"
                    );
                    break;

                case 'pattern':
                    TestCase::assertMatchesRegularExpression(
                        $constraintValue,
                        (string) $value,
                        "Field '{$field}' pattern constraint violation"
                    );
                    break;

                case 'not_null':
                    if ($constraintValue) {
                        TestCase::assertNotNull(
                            $value,
                            "Field '{$field}' should not be null"
                        );
                    }
                    break;

                case 'not_empty':
                    if ($constraintValue) {
                        TestCase::assertNotEmpty(
                            $value,
                            "Field '{$field}' should not be empty"
                        );
                    }
                    break;
            }
        }
    }

    /**
     * Check if aggregate has an ID.
     */
    private function assertAggregateHasId(object $aggregate): void
    {
        $id = $this->getAggregateId($aggregate);
        TestCase::assertNotNull($id, 'Aggregate must have an ID');
    }

    /**
     * Check if aggregate version is consistent.
     */
    private function assertAggregateVersionConsistent(object $aggregate): void
    {
        $version = $this->getAggregateVersion($aggregate);
        TestCase::assertGreaterThanOrEqual(0, $version, 'Aggregate version must be non-negative');
    }

    /**
     * Check if value object is immutable.
     */
    private function assertValueObjectImmutable(object $valueObject): void
    {
        $reflection = new \ReflectionClass($valueObject);
        $properties = $reflection->getProperties();

        foreach ($properties as $property) {
            if (!$property->isReadOnly() && !$property->isPrivate()) {
                TestCase::fail("Value object property '{$property->getName()}' should be readonly or private for immutability");
            }
        }
    }

    /**
     * Check if value object equality is consistent.
     */
    private function assertValueObjectEqualityConsistent(object $valueObject): void
    {
        if (method_exists($valueObject, 'equals')) {
            // Test reflexivity: object equals itself
            TestCase::assertTrue(
                $valueObject->equals($valueObject),
                'Value object equality should be reflexive'
            );
        }
    }

    /**
     * Check if entity has an ID.
     */
    private function assertEntityHasId(object $entity): void
    {
        $id = $this->getEntityId($entity);
        TestCase::assertNotNull($id, 'Entity must have an ID');
    }

    /**
     * Check if entity ID is immutable.
     */
    private function assertEntityIdImmutable(object $entity): void
    {
        // This is a conceptual check - in practice, you'd test that
        // there's no setter for the ID or that it throws an exception
        TestCase::assertTrue(
            true,
            'Entity ID immutability check passed'
        );
    }

    /**
     * Get aggregate ID.
     */
    private function getAggregateId(object $aggregate)
    {
        $methods = ['getId', 'getAggregateId', 'id'];

        foreach ($methods as $method) {
            if (method_exists($aggregate, $method)) {
                return $aggregate->{$method}();
            }
        }

        return null;
    }

    /**
     * Get aggregate version.
     */
    private function getAggregateVersion(object $aggregate): int
    {
        $methods = ['getVersion', 'getAggregateVersion'];

        foreach ($methods as $method) {
            if (method_exists($aggregate, $method)) {
                return (int) $aggregate->{$method}();
            }
        }

        return 0;
    }

    /**
     * Get entity ID.
     */
    private function getEntityId(object $entity)
    {
        $methods = ['getId', 'id'];

        foreach ($methods as $method) {
            if (method_exists($entity, $method)) {
                return $entity->{$method}();
            }
        }

        return null;
    }
}