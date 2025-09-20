<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Middleware;

use LaravelModularDDD\CQRS\Contracts\MiddlewareInterface;
use LaravelModularDDD\CQRS\Contracts\CommandInterface;
use LaravelModularDDD\Core\Application\Contracts\TransactionManagerInterface;
use Closure;
use Illuminate\Support\Facades\Log;

class TransactionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly TransactionManagerInterface $transactionManager
    ) {}

    public function handle(mixed $message, Closure $next): mixed
    {
        if (!$message instanceof CommandInterface) {
            return $next($message);
        }

        // Check if command requires transaction
        if (!$this->requiresTransaction($message)) {
            return $next($message);
        }

        $commandId = $message->getCommandId();
        $options = $this->getTransactionOptions($message);

        Log::debug('Starting transaction for command', [
            'command_id' => $commandId,
            'command_type' => $message->getCommandName(),
            'isolation_level' => $options['isolation_level'] ?? 'default',
            'timeout' => $options['timeout'] ?? 'default',
        ]);

        return $this->transactionManager->executeInTransaction(
            function () use ($next, $message, $commandId) {
                try {
                    $result = $next($message);

                    Log::debug('Transaction committed for command', [
                        'command_id' => $commandId,
                        'transaction_stats' => $this->transactionManager->getStatistics(),
                    ]);

                    return $result;
                } catch (\Exception $e) {
                    Log::error('Transaction rolled back for command', [
                        'command_id' => $commandId,
                        'error' => $e->getMessage(),
                        'transaction_stats' => $this->transactionManager->getStatistics(),
                    ]);

                    throw $e;
                }
            },
            $options
        );
    }

    public function getPriority(): int
    {
        return 50; // Medium priority - wrap handlers in transaction
    }

    public function shouldProcess(mixed $message): bool
    {
        return $message instanceof CommandInterface;
    }

    private function requiresTransaction(CommandInterface $command): bool
    {
        $metadata = $command->getMetadata();

        // Check explicit transaction flag
        if (isset($metadata['transaction'])) {
            return (bool) $metadata['transaction'];
        }

        // Check if command implements TransactionAware interface
        if (method_exists($command, 'requiresTransaction')) {
            return $command->requiresTransaction();
        }

        // Default: all commands require transactions unless specified otherwise
        return true;
    }

    private function getTransactionOptions(CommandInterface $command): array
    {
        $metadata = $command->getMetadata();
        $options = [];

        // Isolation level
        if (isset($metadata['isolation_level'])) {
            $options['isolation_level'] = $metadata['isolation_level'];
        }

        // Transaction timeout
        if (isset($metadata['transaction_timeout'])) {
            $options['timeout'] = (int) $metadata['transaction_timeout'];
        } elseif ($command->getTimeout() > 0) {
            // Use command timeout as transaction timeout
            $options['timeout'] = $command->getTimeout();
        }

        // Readonly transactions for queries that need consistency
        if (isset($metadata['readonly_transaction'])) {
            $options['readonly'] = (bool) $metadata['readonly_transaction'];
        }

        // Deadlock retry configuration
        if (isset($metadata['deadlock_retries'])) {
            $options['deadlock_retries'] = (int) $metadata['deadlock_retries'];
        }

        // Distributed transaction ID
        if (isset($metadata['distributed_transaction_id'])) {
            $options['distributed_transaction_id'] = $metadata['distributed_transaction_id'];
        }

        return $options;
    }
}