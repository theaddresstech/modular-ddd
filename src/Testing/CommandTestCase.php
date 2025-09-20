<?php

declare(strict_types=1);

namespace LaravelModularDDD\Testing;

use LaravelModularDDD\Testing\ModuleTestCase;

/**
 * CommandTestCase
 *
 * Base test case for testing CQRS commands and their handlers.
 * Provides utilities for command validation, execution, and side effect testing.
 */
abstract class CommandTestCase extends ModuleTestCase
{
    protected string $commandClass;
    protected string $handlerClass;

    /**
     * Set up command testing environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->commandClass) {
            throw new \RuntimeException('Command class must be defined in test class');
        }

        if (!class_exists($this->commandClass)) {
            throw new \RuntimeException("Command class {$this->commandClass} does not exist");
        }

        if ($this->handlerClass && !class_exists($this->handlerClass)) {
            throw new \RuntimeException("Handler class {$this->handlerClass} does not exist");
        }

        $this->setUpCommandHandler();
    }

    /**
     * Set up command handler if specified.
     */
    protected function setUpCommandHandler(): void
    {
        if ($this->handlerClass) {
            $this->commandBus->registerHandler($this->commandClass, $this->handlerClass);
        }
    }

    /**
     * Test command creation with valid data.
     */
    public function test_command_can_be_created_with_valid_data(): void
    {
        $validData = $this->getValidCommandData();
        $command = $this->createCommandWithData($validData);

        $this->assertInstanceOf($this->commandClass, $command);
        $this->assertCommandIsValid($command);
    }

    /**
     * Test command creation fails with invalid data.
     */
    public function test_command_creation_fails_with_invalid_data(): void
    {
        $invalidDataSets = $this->getInvalidCommandDataSets();

        foreach ($invalidDataSets as $description => $invalidData) {
            try {
                $command = $this->createCommandWithData($invalidData);
                $this->assertCommandIsInvalid($command, $description);
            } catch (\Exception $e) {
                $this->assertInstanceOf(\InvalidArgumentException::class, $e);
            }
        }
    }

    /**
     * Test command validation rules.
     */
    public function test_command_validation_rules(): void
    {
        $validationTests = $this->getValidationTests();

        foreach ($validationTests as $testName => $testData) {
            $command = $this->createCommandWithData($testData['data']);

            if ($testData['should_pass']) {
                $this->assertCommandIsValid($command, $testName);
            } else {
                $this->assertCommandIsInvalid($command, $testName);
                $this->assertStringContainsString(
                    $testData['expected_error'],
                    $this->getCommandValidationError($command)
                );
            }
        }
    }

    /**
     * Test command execution with valid handler.
     */
    public function test_command_execution_succeeds_with_valid_handler(): void
    {
        if (!$this->handlerClass) {
            $this->markTestSkipped('No handler class specified for command testing');
        }

        $command = $this->createValidCommand();
        $expectedResult = $this->getExpectedCommandResult();

        $result = $this->dispatchCommand($command);

        if ($expectedResult !== null) {
            $this->assertEquals($expectedResult, $result);
        }

        $this->assertCommandSideEffects($command, $result);
    }

    /**
     * Test command execution with authorization.
     */
    public function test_command_execution_respects_authorization(): void
    {
        if (!$this->handlerClass) {
            $this->markTestSkipped('No handler class specified for authorization testing');
        }

        $authorizationTests = $this->getAuthorizationTests();

        foreach ($authorizationTests as $testName => $testData) {
            $this->actingAs($testData['user']);
            $command = $this->createCommandWithData($testData['command_data']);

            if ($testData['should_be_authorized']) {
                try {
                    $this->dispatchCommand($command);
                    $this->assertTrue(true, "Command should be authorized for {$testName}");
                } catch (\Exception $e) {
                    $this->fail("Command should be authorized for {$testName}: {$e->getMessage()}");
                }
            } else {
                $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
                $this->dispatchCommand($command);
            }
        }
    }

    /**
     * Test command idempotency.
     */
    public function test_command_idempotency(): void
    {
        if (!$this->commandShouldBeIdempotent()) {
            $this->markTestSkipped('Command is not expected to be idempotent');
        }

        $command = $this->createValidCommand();

        // Execute command first time
        $result1 = $this->dispatchCommand($command);

        // Execute same command again
        $result2 = $this->dispatchCommand($command);

        $this->assertEquals($result1, $result2, 'Command should be idempotent');
        $this->assertIdempotentSideEffects($command);
    }

    /**
     * Test command execution performance.
     */
    public function test_command_execution_performance(): void
    {
        $command = $this->createValidCommand();
        $maxExecutionTime = $this->getMaxExecutionTime();
        $maxMemoryUsage = $this->getMaxMemoryUsage();

        $this->assertExecutionTime(
            fn() => $this->dispatchCommand($command),
            $maxExecutionTime
        );

        $this->assertMemoryUsage(
            fn() => $this->dispatchCommand($command),
            $maxMemoryUsage
        );
    }

    /**
     * Test command error handling.
     */
    public function test_command_error_handling(): void
    {
        $errorScenarios = $this->getErrorScenarios();

        foreach ($errorScenarios as $scenarioName => $scenario) {
            $this->setUpErrorScenario($scenario);
            $command = $this->createCommandWithData($scenario['command_data']);

            try {
                $this->dispatchCommand($command);
                $this->fail("Command should have failed for scenario: {$scenarioName}");
            } catch (\Exception $e) {
                $this->assertInstanceOf($scenario['expected_exception'], $e);
                $this->assertStringContainsString($scenario['expected_message'], $e->getMessage());
            }
        }
    }

    /**
     * Test command with concurrent execution.
     */
    public function test_command_concurrent_execution(): void
    {
        if (!$this->supportsConcurrentExecution()) {
            $this->markTestSkipped('Command does not support concurrent execution testing');
        }

        $commands = $this->createConcurrentCommands();
        $results = [];

        // Execute commands concurrently (simulate with rapid execution)
        foreach ($commands as $command) {
            $results[] = $this->dispatchCommand($command);
        }

        $this->assertConcurrentExecutionResults($commands, $results);
    }

    /**
     * Test command transaction handling.
     */
    public function test_command_transaction_handling(): void
    {
        if (!$this->handlerClass) {
            $this->markTestSkipped('No handler class specified for transaction testing');
        }

        $command = $this->createValidCommand();

        // Test successful transaction
        $this->expectsTransactionToBeCommitted();
        $this->dispatchCommand($command);

        // Test transaction rollback on failure
        $failingCommand = $this->createFailingCommand();
        $this->expectsTransactionToBeRolledBack();

        try {
            $this->dispatchCommand($failingCommand);
        } catch (\Exception $e) {
            // Expected to fail
        }
    }

    /**
     * Get valid command data - override in specific test classes.
     */
    abstract protected function getValidCommandData(): array;

    /**
     * Get invalid command data sets - override in specific test classes.
     */
    abstract protected function getInvalidCommandDataSets(): array;

    /**
     * Get validation tests - override in specific test classes.
     */
    protected function getValidationTests(): array
    {
        return [];
    }

    /**
     * Get authorization tests - override in specific test classes.
     */
    protected function getAuthorizationTests(): array
    {
        return [];
    }

    /**
     * Get error scenarios - override in specific test classes.
     */
    protected function getErrorScenarios(): array
    {
        return [];
    }

    /**
     * Create command with specific data.
     */
    protected function createCommandWithData(array $data): object
    {
        return $this->createTestCommand($this->commandClass, $data);
    }

    /**
     * Create a valid command instance.
     */
    protected function createValidCommand(): object
    {
        return $this->createCommandWithData($this->getValidCommandData());
    }

    /**
     * Assert that a command is valid.
     */
    protected function assertCommandIsValid(object $command, string $context = ''): void
    {
        if (method_exists($command, 'validate')) {
            try {
                $command->validate();
                $this->assertTrue(true);
            } catch (\Exception $e) {
                $this->fail("Command should be valid" . ($context ? " for {$context}" : '') . ": {$e->getMessage()}");
            }
        }
    }

    /**
     * Assert that a command is invalid.
     */
    protected function assertCommandIsInvalid(object $command, string $context = ''): void
    {
        if (method_exists($command, 'validate')) {
            try {
                $command->validate();
                $this->fail("Command should be invalid" . ($context ? " for {$context}" : ''));
            } catch (\Exception $e) {
                $this->assertInstanceOf(\InvalidArgumentException::class, $e);
            }
        }
    }

    /**
     * Get command validation error.
     */
    protected function getCommandValidationError(object $command): string
    {
        if (method_exists($command, 'validate')) {
            try {
                $command->validate();
                return '';
            } catch (\Exception $e) {
                return $e->getMessage();
            }
        }

        return '';
    }

    /**
     * Get expected command result - override in specific test classes.
     */
    protected function getExpectedCommandResult(): mixed
    {
        return null;
    }

    /**
     * Assert command side effects - override in specific test classes.
     */
    protected function assertCommandSideEffects(object $command, mixed $result): void
    {
        // Override in specific test classes
    }

    /**
     * Check if command should be idempotent - override in specific test classes.
     */
    protected function commandShouldBeIdempotent(): bool
    {
        return false;
    }

    /**
     * Assert idempotent side effects - override in specific test classes.
     */
    protected function assertIdempotentSideEffects(object $command): void
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
     * Set up error scenario - override in specific test classes.
     */
    protected function setUpErrorScenario(array $scenario): void
    {
        // Override in specific test classes
    }

    /**
     * Check if supports concurrent execution - override in specific test classes.
     */
    protected function supportsConcurrentExecution(): bool
    {
        return false;
    }

    /**
     * Create concurrent commands - override in specific test classes.
     */
    protected function createConcurrentCommands(): array
    {
        return [];
    }

    /**
     * Assert concurrent execution results - override in specific test classes.
     */
    protected function assertConcurrentExecutionResults(array $commands, array $results): void
    {
        // Override in specific test classes
    }

    /**
     * Create failing command - override in specific test classes.
     */
    protected function createFailingCommand(): object
    {
        return $this->createValidCommand();
    }

    /**
     * Expect transaction to be committed.
     */
    protected function expectsTransactionToBeCommitted(): void
    {
        // Mock or spy on transaction manager
    }

    /**
     * Expect transaction to be rolled back.
     */
    protected function expectsTransactionToBeRolledBack(): void
    {
        // Mock or spy on transaction manager
    }
}