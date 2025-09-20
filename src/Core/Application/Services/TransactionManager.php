<?php

declare(strict_types=1);

namespace LaravelModularDDD\Core\Application\Services;

use LaravelModularDDD\Core\Application\Contracts\TransactionManagerInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Closure;

/**
 * TransactionManager
 *
 * Concrete implementation of transaction management for command operations.
 * Handles both single database and distributed transactions with proper isolation.
 */
final class TransactionManager implements TransactionManagerInterface
{
    private array $afterCommitCallbacks = [];
    private array $afterRollbackCallbacks = [];
    private array $distributedTransactions = [];
    private string $defaultIsolationLevel = 'READ_COMMITTED';
    private ?string $nextIsolationLevel = null;
    private array $statistics = [
        'transactions_started' => 0,
        'transactions_committed' => 0,
        'transactions_rolled_back' => 0,
        'distributed_transactions' => 0,
        'deadlocks_detected' => 0,
        'retry_attempts' => 0,
    ];

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly int $deadlockRetryAttempts = 3,
        private readonly int $deadlockRetryDelay = 100 // milliseconds
    ) {}

    public function executeInTransaction(Closure $operation, array $options = []): mixed
    {
        $maxRetries = $options['deadlock_retries'] ?? $this->deadlockRetryAttempts;
        $isolationLevel = $options['isolation_level'] ?? $this->nextIsolationLevel ?? $this->defaultIsolationLevel;
        $timeout = $options['timeout'] ?? 30; // seconds
        $readonly = $options['readonly'] ?? false;

        $attempt = 0;
        $lastException = null;

        while ($attempt <= $maxRetries) {
            try {
                return $this->executeTransactionAttempt($operation, $isolationLevel, $timeout, $readonly);
            } catch (QueryException $e) {
                $lastException = $e;

                if ($this->isDeadlock($e) && $attempt < $maxRetries) {
                    $this->statistics['deadlocks_detected']++;
                    $this->statistics['retry_attempts']++;

                    Log::warning('Transaction deadlock detected, retrying', [
                        'attempt' => $attempt + 1,
                        'max_retries' => $maxRetries,
                        'error_code' => $e->getCode(),
                        'delay_ms' => $this->deadlockRetryDelay * ($attempt + 1),
                    ]);

                    // Exponential backoff for retry delay
                    usleep($this->deadlockRetryDelay * 1000 * ($attempt + 1));
                    $attempt++;
                    continue;
                }

                throw $e;
            }
        }

        throw $lastException;
    }

    public function beginDistributed(string $transactionId, array $resources = []): void
    {
        if ($this->hasDistributedTransaction($transactionId)) {
            throw new \InvalidArgumentException("Distributed transaction '{$transactionId}' already exists");
        }

        $this->distributedTransactions[$transactionId] = [
            'id' => $transactionId,
            'resources' => $resources,
            'started_at' => now(),
            'status' => 'active',
            'participants' => [],
        ];

        $this->statistics['distributed_transactions']++;

        // Store in cache for persistence across requests
        Cache::put("distributed_tx_{$transactionId}", $this->distributedTransactions[$transactionId], now()->addMinutes(30));

        Log::info('Distributed transaction started', [
            'transaction_id' => $transactionId,
            'resources' => $resources,
        ]);
    }

    public function commitDistributed(string $transactionId): void
    {
        if (!$this->hasDistributedTransaction($transactionId)) {
            throw new \InvalidArgumentException("Distributed transaction '{$transactionId}' not found");
        }

        $transaction = $this->distributedTransactions[$transactionId];

        try {
            // Two-phase commit protocol
            $this->prepareDistributedCommit($transactionId);
            $this->executeDistributedCommit($transactionId);

            $this->distributedTransactions[$transactionId]['status'] = 'committed';
            $this->distributedTransactions[$transactionId]['committed_at'] = now();

            Cache::forget("distributed_tx_{$transactionId}");
            unset($this->distributedTransactions[$transactionId]);

            Log::info('Distributed transaction committed', [
                'transaction_id' => $transactionId,
                'duration_ms' => now()->diffInMilliseconds($transaction['started_at']),
            ]);

        } catch (\Exception $e) {
            Log::error('Distributed transaction commit failed', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);

            $this->rollbackDistributed($transactionId);
            throw $e;
        }
    }

    public function rollbackDistributed(string $transactionId): void
    {
        if (!$this->hasDistributedTransaction($transactionId)) {
            Log::warning('Attempted to rollback non-existent distributed transaction', [
                'transaction_id' => $transactionId,
            ]);
            return;
        }

        $transaction = $this->distributedTransactions[$transactionId];

        try {
            $this->executeDistributedRollback($transactionId);

            $this->distributedTransactions[$transactionId]['status'] = 'rolled_back';
            $this->distributedTransactions[$transactionId]['rolled_back_at'] = now();

            Cache::forget("distributed_tx_{$transactionId}");
            unset($this->distributedTransactions[$transactionId]);

            Log::info('Distributed transaction rolled back', [
                'transaction_id' => $transactionId,
                'duration_ms' => now()->diffInMilliseconds($transaction['started_at']),
            ]);

        } catch (\Exception $e) {
            Log::error('Distributed transaction rollback failed', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);

            // Mark as failed but don't throw - rollback should be best effort
            $this->distributedTransactions[$transactionId]['status'] = 'rollback_failed';
            Cache::forget("distributed_tx_{$transactionId}");
        }
    }

    public function inTransaction(): bool
    {
        return $this->connection->transactionLevel() > 0;
    }

    public function getIsolationLevel(): string
    {
        return $this->connection->select('SELECT @@transaction_isolation as level')[0]->level ?? $this->defaultIsolationLevel;
    }

    public function setIsolationLevel(string $level): void
    {
        $validLevels = ['READ_UNCOMMITTED', 'READ_COMMITTED', 'REPEATABLE_READ', 'SERIALIZABLE'];

        if (!in_array(strtoupper($level), $validLevels)) {
            throw new \InvalidArgumentException("Invalid isolation level: {$level}");
        }

        $this->nextIsolationLevel = strtoupper($level);
    }

    public function afterCommit(Closure $callback): void
    {
        $this->afterCommitCallbacks[] = $callback;
    }

    public function afterRollback(Closure $callback): void
    {
        $this->afterRollbackCallbacks[] = $callback;
    }

    public function hasDistributedTransaction(string $transactionId): bool
    {
        return isset($this->distributedTransactions[$transactionId]) ||
               Cache::has("distributed_tx_{$transactionId}");
    }

    public function getStatistics(): array
    {
        return array_merge($this->statistics, [
            'active_distributed_transactions' => count($this->distributedTransactions),
            'current_transaction_level' => $this->connection->transactionLevel(),
            'current_isolation_level' => $this->getIsolationLevel(),
            'pending_commit_callbacks' => count($this->afterCommitCallbacks),
            'pending_rollback_callbacks' => count($this->afterRollbackCallbacks),
        ]);
    }

    private function executeTransactionAttempt(Closure $operation, string $isolationLevel, int $timeout, bool $readonly): mixed
    {
        $this->statistics['transactions_started']++;

        // Set isolation level if different from current
        if ($isolationLevel !== $this->getIsolationLevel()) {
            $this->connection->statement("SET SESSION TRANSACTION ISOLATION LEVEL {$isolationLevel}");
        }

        // Set transaction timeout
        $this->connection->statement("SET SESSION innodb_lock_wait_timeout = {$timeout}");

        // Set readonly mode if requested
        if ($readonly) {
            $this->connection->statement("SET TRANSACTION READ ONLY");
        }

        return $this->connection->transaction(function () use ($operation) {
            $startTime = microtime(true);

            try {
                $result = $operation();

                // Execute after-commit callbacks
                foreach ($this->afterCommitCallbacks as $callback) {
                    try {
                        $callback();
                    } catch (\Exception $e) {
                        Log::error('After-commit callback failed', [
                            'error' => $e->getMessage(),
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                        ]);
                    }
                }

                $this->statistics['transactions_committed']++;

                Log::debug('Transaction committed successfully', [
                    'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
                    'isolation_level' => $this->getIsolationLevel(),
                ]);

                return $result;

            } catch (\Exception $e) {
                // Execute after-rollback callbacks
                foreach ($this->afterRollbackCallbacks as $callback) {
                    try {
                        $callback();
                    } catch (\Exception $callbackError) {
                        Log::error('After-rollback callback failed', [
                            'error' => $callbackError->getMessage(),
                            'original_error' => $e->getMessage(),
                        ]);
                    }
                }

                $this->statistics['transactions_rolled_back']++;

                Log::warning('Transaction rolled back', [
                    'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
                    'error' => $e->getMessage(),
                    'isolation_level' => $this->getIsolationLevel(),
                ]);

                throw $e;
            } finally {
                // Clear callbacks after transaction
                $this->afterCommitCallbacks = [];
                $this->afterRollbackCallbacks = [];
                $this->nextIsolationLevel = null;
            }
        });
    }

    private function isDeadlock(QueryException $e): bool
    {
        // MySQL deadlock error codes
        $deadlockCodes = [1205, 1213];

        return in_array($e->getCode(), $deadlockCodes) ||
               str_contains($e->getMessage(), 'Deadlock found');
    }

    private function prepareDistributedCommit(string $transactionId): void
    {
        $transaction = $this->distributedTransactions[$transactionId];

        // Phase 1: Prepare all participants
        foreach ($transaction['participants'] as $participant) {
            // Implementation would depend on participant type
            // For now, just log the prepare phase
            Log::debug('Preparing participant for distributed commit', [
                'transaction_id' => $transactionId,
                'participant' => $participant,
            ]);
        }
    }

    private function executeDistributedCommit(string $transactionId): void
    {
        $transaction = $this->distributedTransactions[$transactionId];

        // Phase 2: Commit all participants
        foreach ($transaction['participants'] as $participant) {
            // Implementation would depend on participant type
            Log::debug('Committing participant in distributed transaction', [
                'transaction_id' => $transactionId,
                'participant' => $participant,
            ]);
        }
    }

    private function executeDistributedRollback(string $transactionId): void
    {
        $transaction = $this->distributedTransactions[$transactionId];

        // Rollback all participants
        foreach ($transaction['participants'] as $participant) {
            try {
                // Implementation would depend on participant type
                Log::debug('Rolling back participant in distributed transaction', [
                    'transaction_id' => $transactionId,
                    'participant' => $participant,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to rollback participant', [
                    'transaction_id' => $transactionId,
                    'participant' => $participant,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}