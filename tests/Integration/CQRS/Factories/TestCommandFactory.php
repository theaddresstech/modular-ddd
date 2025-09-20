<?php

declare(strict_types=1);

namespace LaravelModularDDD\Tests\Integration\CQRS\Factories;

use LaravelModularDDD\CQRS\Contracts\CommandInterface;
use Ramsey\Uuid\Uuid;

/**
 * Factory for creating test commands for CQRS integration tests.
 */
class TestCommandFactory
{
    /**
     * Create a simple update user command
     */
    public function createUpdateUserCommand(string $userId, array $data): CommandInterface
    {
        return new class($userId, $data) implements CommandInterface {
            public function __construct(
                private string $userId,
                private array $data
            ) {}

            public function getCommandName(): string
            {
                return 'UpdateUserCommand';
            }

            public function getCommandId(): string
            {
                return Uuid::uuid4()->toString();
            }

            public function getPayload(): array
            {
                return array_merge($this->data, ['user_id' => $this->userId]);
            }

            public function getMetadata(): array
            {
                return [
                    'user_id' => $this->userId,
                    'command_type' => 'user_update',
                    'created_at' => now()->toISOString(),
                    'test_command' => true,
                ];
            }

            public function isAsync(): bool
            {
                return false;
            }

            public function getPriority(): int
            {
                return 0;
            }

            public function requiresTransaction(): bool
            {
                return true;
            }

            public function getTimeout(): int
            {
                return 30;
            }

            public function getRetryPolicy(): array
            {
                return [
                    'max_attempts' => 3,
                    'delay_ms' => 1000,
                    'backoff_multiplier' => 2,
                ];
            }
        };
    }

    /**
     * Create a transactional command that can be configured to succeed or fail
     */
    public function createTransactionalCommand(string $transactionId, array $operations): CommandInterface
    {
        return new class($transactionId, $operations) implements CommandInterface {
            public function __construct(
                private string $transactionId,
                private array $operations
            ) {}

            public function getCommandName(): string
            {
                return 'TransactionalCommand';
            }

            public function getCommandId(): string
            {
                return $this->transactionId;
            }

            public function getPayload(): array
            {
                return [
                    'transaction_id' => $this->transactionId,
                    'operations' => $this->operations,
                ];
            }

            public function getMetadata(): array
            {
                return [
                    'transaction_id' => $this->transactionId,
                    'operation_count' => count($this->operations),
                    'requires_transaction' => true,
                    'created_at' => now()->toISOString(),
                ];
            }

            public function isAsync(): bool
            {
                return false;
            }

            public function getPriority(): int
            {
                return 1;
            }

            public function requiresTransaction(): bool
            {
                return true;
            }

            public function getTimeout(): int
            {
                return 60;
            }

            public function getRetryPolicy(): array
            {
                return [
                    'max_attempts' => 1, // No retries for transaction tests
                    'delay_ms' => 0,
                ];
            }
        };
    }

    /**
     * Create an async command for queue processing tests
     */
    public function createAsyncCommand(string $operationType, array $data): CommandInterface
    {
        return new class($operationType, $data) implements CommandInterface {
            public function __construct(
                private string $operationType,
                private array $data
            ) {}

            public function getCommandName(): string
            {
                return 'AsyncCommand';
            }

            public function getCommandId(): string
            {
                return Uuid::uuid4()->toString();
            }

            public function getPayload(): array
            {
                return array_merge($this->data, [
                    'operation_type' => $this->operationType,
                ]);
            }

            public function getMetadata(): array
            {
                return [
                    'operation_type' => $this->operationType,
                    'async' => true,
                    'queue_name' => 'commands',
                    'created_at' => now()->toISOString(),
                    'delay_seconds' => $this->data['delay_seconds'] ?? 0,
                ];
            }

            public function isAsync(): bool
            {
                return true;
            }

            public function getPriority(): int
            {
                return $this->data['priority'] ?? 0;
            }

            public function requiresTransaction(): bool
            {
                return false; // Async commands typically don't require immediate transactions
            }

            public function getTimeout(): int
            {
                return $this->data['timeout'] ?? 120;
            }

            public function getRetryPolicy(): array
            {
                return [
                    'max_attempts' => 5,
                    'delay_ms' => 2000,
                    'backoff_multiplier' => 1.5,
                    'max_delay_ms' => 30000,
                ];
            }

            public function getDelaySeconds(): int
            {
                return $this->data['delay_seconds'] ?? 0;
            }

            public function getQueueName(): string
            {
                return $this->data['queue'] ?? 'default';
            }
        };
    }

    /**
     * Create a simple command for middleware testing
     */
    public function createSimpleCommand(string $operation): CommandInterface
    {
        return new class($operation) implements CommandInterface {
            public function __construct(private string $operation) {}

            public function getCommandName(): string
            {
                return 'SimpleCommand';
            }

            public function getCommandId(): string
            {
                return Uuid::uuid4()->toString();
            }

            public function getPayload(): array
            {
                return ['operation' => $this->operation];
            }

            public function getMetadata(): array
            {
                return [
                    'operation' => $this->operation,
                    'simple_command' => true,
                    'created_at' => now()->toISOString(),
                ];
            }

            public function isAsync(): bool
            {
                return false;
            }

            public function getPriority(): int
            {
                return 0;
            }

            public function requiresTransaction(): bool
            {
                return false;
            }

            public function getTimeout(): int
            {
                return 10;
            }

            public function getRetryPolicy(): array
            {
                return [
                    'max_attempts' => 1,
                    'delay_ms' => 0,
                ];
            }
        };
    }

    /**
     * Create a command that will fail for error handling tests
     */
    public function createFailingCommand(): CommandInterface
    {
        return new class() implements CommandInterface {
            public function getCommandName(): string
            {
                return 'FailingCommand';
            }

            public function getCommandId(): string
            {
                return Uuid::uuid4()->toString();
            }

            public function getPayload(): array
            {
                return ['should_fail' => true];
            }

            public function getMetadata(): array
            {
                return [
                    'should_fail' => true,
                    'test_failure' => true,
                    'created_at' => now()->toISOString(),
                ];
            }

            public function isAsync(): bool
            {
                return false;
            }

            public function getPriority(): int
            {
                return 0;
            }

            public function requiresTransaction(): bool
            {
                return true;
            }

            public function getTimeout(): int
            {
                return 5;
            }

            public function getRetryPolicy(): array
            {
                return [
                    'max_attempts' => 1, // Don't retry failing commands in tests
                    'delay_ms' => 0,
                ];
            }
        };
    }

    /**
     * Create a bulk operation command for performance testing
     */
    public function createBulkOperationCommand(string $operationType, array $items): CommandInterface
    {
        return new class($operationType, $items) implements CommandInterface {
            public function __construct(
                private string $operationType,
                private array $items
            ) {}

            public function getCommandName(): string
            {
                return 'BulkOperationCommand';
            }

            public function getCommandId(): string
            {
                return Uuid::uuid4()->toString();
            }

            public function getPayload(): array
            {
                return [
                    'operation_type' => $this->operationType,
                    'items' => $this->items,
                    'item_count' => count($this->items),
                ];
            }

            public function getMetadata(): array
            {
                return [
                    'operation_type' => $this->operationType,
                    'item_count' => count($this->items),
                    'bulk_operation' => true,
                    'created_at' => now()->toISOString(),
                ];
            }

            public function isAsync(): bool
            {
                return count($this->items) > 100; // Large bulk operations should be async
            }

            public function getPriority(): int
            {
                return count($this->items) > 1000 ? -1 : 0; // Lower priority for very large operations
            }

            public function requiresTransaction(): bool
            {
                return true;
            }

            public function getTimeout(): int
            {
                return max(60, count($this->items) * 0.1); // Dynamic timeout based on item count
            }

            public function getRetryPolicy(): array
            {
                return [
                    'max_attempts' => 3,
                    'delay_ms' => 5000,
                    'backoff_multiplier' => 2,
                ];
            }
        };
    }

    /**
     * Create a command with custom validation rules
     */
    public function createValidatedCommand(string $entityId, array $data, array $validationRules = []): CommandInterface
    {
        return new class($entityId, $data, $validationRules) implements CommandInterface {
            public function __construct(
                private string $entityId,
                private array $data,
                private array $validationRules
            ) {}

            public function getCommandName(): string
            {
                return 'ValidatedCommand';
            }

            public function getCommandId(): string
            {
                return Uuid::uuid4()->toString();
            }

            public function getPayload(): array
            {
                return array_merge($this->data, ['entity_id' => $this->entityId]);
            }

            public function getMetadata(): array
            {
                return [
                    'entity_id' => $this->entityId,
                    'validation_rules' => $this->validationRules,
                    'requires_validation' => true,
                    'created_at' => now()->toISOString(),
                ];
            }

            public function isAsync(): bool
            {
                return false;
            }

            public function getPriority(): int
            {
                return 0;
            }

            public function requiresTransaction(): bool
            {
                return true;
            }

            public function getTimeout(): int
            {
                return 30;
            }

            public function getRetryPolicy(): array
            {
                return [
                    'max_attempts' => 2,
                    'delay_ms' => 1000,
                ];
            }

            public function getValidationRules(): array
            {
                return $this->validationRules;
            }
        };
    }

    /**
     * Create a command for saga/workflow testing
     */
    public function createSagaCommand(string $sagaId, string $step, array $data): CommandInterface
    {
        return new class($sagaId, $step, $data) implements CommandInterface {
            public function __construct(
                private string $sagaId,
                private string $step,
                private array $data
            ) {}

            public function getCommandName(): string
            {
                return 'SagaCommand';
            }

            public function getCommandId(): string
            {
                return $this->sagaId . '_' . $this->step;
            }

            public function getPayload(): array
            {
                return array_merge($this->data, [
                    'saga_id' => $this->sagaId,
                    'step' => $this->step,
                ]);
            }

            public function getMetadata(): array
            {
                return [
                    'saga_id' => $this->sagaId,
                    'step' => $this->step,
                    'saga_command' => true,
                    'created_at' => now()->toISOString(),
                ];
            }

            public function isAsync(): bool
            {
                return true; // Saga commands are typically async
            }

            public function getPriority(): int
            {
                return 1; // Higher priority for saga steps
            }

            public function requiresTransaction(): bool
            {
                return true;
            }

            public function getTimeout(): int
            {
                return 60;
            }

            public function getRetryPolicy(): array
            {
                return [
                    'max_attempts' => 5,
                    'delay_ms' => 3000,
                    'backoff_multiplier' => 1.5,
                ];
            }

            public function getSagaId(): string
            {
                return $this->sagaId;
            }

            public function getStep(): string
            {
                return $this->step;
            }

            public function getCompensationCommand(): ?CommandInterface
            {
                // Return a compensation command for saga rollback
                return new class($this->sagaId, $this->step) implements CommandInterface {
                    public function __construct(
                        private string $sagaId,
                        private string $step
                    ) {}

                    public function getCommandName(): string
                    {
                        return 'CompensateSagaCommand';
                    }

                    public function getCommandId(): string
                    {
                        return $this->sagaId . '_compensate_' . $this->step;
                    }

                    public function getPayload(): array
                    {
                        return [
                            'saga_id' => $this->sagaId,
                            'compensate_step' => $this->step,
                        ];
                    }

                    public function getMetadata(): array
                    {
                        return [
                            'saga_id' => $this->sagaId,
                            'compensate_step' => $this->step,
                            'compensation_command' => true,
                            'created_at' => now()->toISOString(),
                        ];
                    }

                    public function isAsync(): bool { return true; }
                    public function getPriority(): int { return 2; } // Higher priority for compensation
                    public function requiresTransaction(): bool { return true; }
                    public function getTimeout(): int { return 30; }
                    public function getRetryPolicy(): array { return ['max_attempts' => 1]; }
                };
            }
        };
    }
}