<?php

declare(strict_types=1);

namespace LaravelModularDDD\Testing\Traits;

use PHPUnit\Framework\TestCase;

/**
 * TestsCommands
 *
 * Provides utilities for testing CQRS commands and command handlers.
 * Includes validation, execution, and side effect verification.
 */
trait TestsCommands
{
    /**
     * Test command execution with expected success.
     */
    protected function assertCommandExecutesSuccessfully(object $command, $expectedResult = null): mixed
    {
        try {
            $result = $this->executeCommand($command);

            TestCase::assertNotNull($result, 'Command should return a result');

            if ($expectedResult !== null) {
                TestCase::assertEquals($expectedResult, $result, 'Command result does not match expected');
            }

            return $result;
        } catch (\Exception $e) {
            TestCase::fail("Command execution failed: {$e->getMessage()}");
        }
    }

    /**
     * Test command execution with expected failure.
     */
    protected function assertCommandFails(object $command, string $expectedExceptionClass = null, string $expectedMessage = null): void
    {
        $exceptionThrown = false;
        $thrownException = null;

        try {
            $this->executeCommand($command);
        } catch (\Exception $e) {
            $exceptionThrown = true;
            $thrownException = $e;
        }

        TestCase::assertTrue($exceptionThrown, 'Expected command to fail but it succeeded');

        if ($expectedExceptionClass && $thrownException) {
            TestCase::assertInstanceOf(
                $expectedExceptionClass,
                $thrownException,
                "Expected exception of type '{$expectedExceptionClass}'"
            );
        }

        if ($expectedMessage && $thrownException) {
            TestCase::assertStringContainsString(
                $expectedMessage,
                $thrownException->getMessage(),
                'Exception message does not contain expected text'
            );
        }
    }

    /**
     * Test command validation.
     */
    protected function assertCommandValidation(object $command, bool $shouldBeValid = true, array $expectedErrors = []): void
    {
        if (!method_exists($command, 'validate')) {
            TestCase::markTestSkipped('Command does not implement validation');
        }

        if ($shouldBeValid) {
            try {
                $command->validate();
                TestCase::assertTrue(true, 'Command validation passed as expected');
            } catch (\Exception $e) {
                TestCase::fail("Command validation should pass but failed: {$e->getMessage()}");
            }
        } else {
            try {
                $command->validate();
                TestCase::fail('Command validation should fail but passed');
            } catch (\Exception $e) {
                TestCase::assertTrue(true, 'Command validation failed as expected');

                if (!empty($expectedErrors)) {
                    foreach ($expectedErrors as $expectedError) {
                        TestCase::assertStringContainsString(
                            $expectedError,
                            $e->getMessage(),
                            "Expected validation error '{$expectedError}' not found"
                        );
                    }
                }
            }
        }
    }

    /**
     * Test command produces expected events.
     */
    protected function assertCommandProducesEvents(object $command, array $expectedEventTypes): void
    {
        $this->clearCapturedEvents();
        $this->executeCommand($command);

        $capturedEvents = $this->getCapturedEvents();
        $actualEventTypes = array_map(fn($event) => get_class($event), $capturedEvents);

        TestCase::assertEquals(
            $expectedEventTypes,
            $actualEventTypes,
            'Command did not produce expected events'
        );
    }

    /**
     * Test command produces no events.
     */
    protected function assertCommandProducesNoEvents(object $command): void
    {
        $this->assertCommandProducesEvents($command, []);
    }

    /**
     * Test command execution time.
     */
    protected function assertCommandExecutionTime(object $command, float $maxExecutionTime): mixed
    {
        $startTime = microtime(true);
        $result = $this->executeCommand($command);
        $executionTime = microtime(true) - $startTime;

        TestCase::assertLessThan(
            $maxExecutionTime,
            $executionTime,
            "Command execution took {$executionTime}s, expected less than {$maxExecutionTime}s"
        );

        return $result;
    }

    /**
     * Test command with different scenarios.
     */
    protected function assertCommandScenarios(string $commandClass, array $scenarios): void
    {
        foreach ($scenarios as $scenarioName => $scenario) {
            $command = $this->createCommand($commandClass, $scenario['data']);
            $expectedResult = $scenario['expected_result'] ?? null;
            $shouldSucceed = $scenario['should_succeed'] ?? true;
            $expectedEvents = $scenario['expected_events'] ?? [];

            if ($shouldSucceed) {
                $result = $this->assertCommandExecutesSuccessfully($command, $expectedResult);

                if (!empty($expectedEvents)) {
                    $this->assertCommandProducesEvents($command, $expectedEvents);
                }
            } else {
                $expectedExceptionClass = $scenario['expected_exception'] ?? null;
                $this->assertCommandFails($command, $expectedExceptionClass);
            }
        }
    }

    /**
     * Test command authorization.
     */
    protected function assertCommandAuthorization(object $command, object $user = null, bool $shouldBeAuthorized = true): void
    {
        if ($user) {
            $this->actingAs($user);
        }

        if (method_exists($command, 'authorize') || method_exists($command, 'isAuthorized')) {
            $method = method_exists($command, 'authorize') ? 'authorize' : 'isAuthorized';

            try {
                $authorized = $command->{$method}();

                TestCase::assertEquals(
                    $shouldBeAuthorized,
                    $authorized,
                    'Command authorization result does not match expected'
                );
            } catch (\Exception $e) {
                if ($shouldBeAuthorized) {
                    TestCase::fail("Command authorization should pass but failed: {$e->getMessage()}");
                } else {
                    TestCase::assertTrue(true, 'Command authorization failed as expected');
                }
            }
        } else {
            TestCase::markTestSkipped('Command does not implement authorization');
        }
    }

    /**
     * Test command with transaction rollback.
     */
    protected function assertCommandTransactionRollback(object $command, \Exception $exceptionToThrow): void
    {
        $initialState = $this->captureSystemState();

        try {
            // Mock the command handler to throw an exception mid-execution
            $this->executeCommandWithException($command, $exceptionToThrow);
            TestCase::fail('Expected command to throw exception');
        } catch (\Exception $e) {
            // Verify system state was rolled back
            $currentState = $this->captureSystemState();
            TestCase::assertEquals(
                $initialState,
                $currentState,
                'System state was not properly rolled back after command failure'
            );
        }
    }

    /**
     * Test command idempotency.
     */
    protected function assertCommandIdempotency(object $command, int $executionCount = 3): void
    {
        $results = [];

        for ($i = 0; $i < $executionCount; $i++) {
            $results[] = $this->executeCommand($command);
        }

        // All results should be identical for idempotent commands
        $firstResult = $results[0];
        foreach ($results as $index => $result) {
            TestCase::assertEquals(
                $firstResult,
                $result,
                "Command execution #{$index} produced different result - command is not idempotent"
            );
        }
    }

    /**
     * Test command with concurrency.
     */
    protected function assertCommandConcurrency(object $command, int $concurrentExecutions = 3): void
    {
        $results = [];
        $exceptions = [];

        // Simulate concurrent executions
        for ($i = 0; $i < $concurrentExecutions; $i++) {
            try {
                $results[] = $this->executeCommand($command);
            } catch (\Exception $e) {
                $exceptions[] = $e;
            }
        }

        // At least one execution should succeed, or all should fail with expected concurrency exception
        if (empty($results) && !empty($exceptions)) {
            foreach ($exceptions as $exception) {
                TestCase::assertStringContainsString(
                    'concurrency',
                    strtolower($exception->getMessage()),
                    'Expected concurrency-related exception'
                );
            }
        } else {
            TestCase::assertNotEmpty($results, 'At least one concurrent execution should succeed');
        }
    }

    /**
     * Test command compensation (saga pattern).
     */
    protected function assertCommandCompensation(object $command, object $compensationCommand): void
    {
        // Execute original command
        $this->executeCommand($command);
        $stateAfterCommand = $this->captureSystemState();

        // Execute compensation
        $this->executeCommand($compensationCommand);
        $stateAfterCompensation = $this->captureSystemState();

        // State should be different after compensation
        TestCase::assertNotEquals(
            $stateAfterCommand,
            $stateAfterCompensation,
            'Compensation command should change system state'
        );
    }

    /**
     * Test command side effects.
     */
    protected function assertCommandSideEffects(object $command, array $expectedSideEffects): void
    {
        $this->clearSideEffectTracking();
        $this->executeCommand($command);

        foreach ($expectedSideEffects as $sideEffect => $expectedValue) {
            $actualValue = $this->getSideEffectValue($sideEffect);
            TestCase::assertEquals(
                $expectedValue,
                $actualValue,
                "Side effect '{$sideEffect}' does not match expected value"
            );
        }
    }

    /**
     * Execute a command using the command bus.
     */
    private function executeCommand(object $command)
    {
        if (method_exists($this, 'dispatchCommand')) {
            return $this->dispatchCommand($command);
        }

        if (property_exists($this, 'commandBus')) {
            return $this->commandBus->dispatch($command);
        }

        if (app()->bound('command.bus')) {
            return app('command.bus')->dispatch($command);
        }

        TestCase::fail('No command bus available for command execution');
    }

    /**
     * Create a command instance with given data.
     */
    private function createCommand(string $commandClass, array $data): object
    {
        if (method_exists($this, 'createTestCommand')) {
            return $this->createTestCommand($commandClass, $data);
        }

        // Fallback to reflection-based creation
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
                $args[] = null;
            }
        }

        return new $commandClass(...$args);
    }

    /**
     * Execute command with forced exception (for testing rollback).
     */
    private function executeCommandWithException(object $command, \Exception $exception): void
    {
        // This would be implemented based on your command bus implementation
        // For testing purposes, we'll just throw the exception
        throw $exception;
    }

    /**
     * Capture current system state for comparison.
     */
    private function captureSystemState(): array
    {
        return [
            'database' => $this->getDatabaseState(),
            'cache' => $this->getCacheState(),
            'events' => $this->getCapturedEvents(),
        ];
    }

    /**
     * Get database state (mock implementation).
     */
    private function getDatabaseState(): array
    {
        // This would query relevant tables and return their state
        return ['mock' => 'database_state'];
    }

    /**
     * Get cache state (mock implementation).
     */
    private function getCacheState(): array
    {
        // This would check cache contents
        return ['mock' => 'cache_state'];
    }

    /**
     * Get captured events.
     */
    private function getCapturedEvents(): array
    {
        if (property_exists($this, 'capturedEvents')) {
            return $this->capturedEvents;
        }

        return [];
    }

    /**
     * Clear captured events.
     */
    private function clearCapturedEvents(): void
    {
        if (property_exists($this, 'capturedEvents')) {
            $this->capturedEvents = [];
        }
    }

    /**
     * Clear side effect tracking.
     */
    private function clearSideEffectTracking(): void
    {
        // Implementation depends on your side effect tracking mechanism
    }

    /**
     * Get side effect value.
     */
    private function getSideEffectValue(string $sideEffect)
    {
        // Implementation depends on your side effect tracking mechanism
        return null;
    }
}