<?php

declare(strict_types=1);

namespace LaravelModularDDD\Core\Application\Contracts;

use Closure;

/**
 * TransactionManagerInterface
 *
 * Manages transaction boundaries for command operations.
 * Supports both single database and distributed transactions.
 */
interface TransactionManagerInterface
{
    /**
     * Execute operation within a transaction boundary.
     */
    public function executeInTransaction(Closure $operation, array $options = []): mixed;

    /**
     * Begin a new distributed transaction.
     */
    public function beginDistributed(string $transactionId, array $resources = []): void;

    /**
     * Commit a distributed transaction.
     */
    public function commitDistributed(string $transactionId): void;

    /**
     * Rollback a distributed transaction.
     */
    public function rollbackDistributed(string $transactionId): void;

    /**
     * Check if currently within a transaction.
     */
    public function inTransaction(): bool;

    /**
     * Get current transaction isolation level.
     */
    public function getIsolationLevel(): string;

    /**
     * Set transaction isolation level for next transaction.
     */
    public function setIsolationLevel(string $level): void;

    /**
     * Add a callback to execute after transaction commit.
     */
    public function afterCommit(Closure $callback): void;

    /**
     * Add a callback to execute after transaction rollback.
     */
    public function afterRollback(Closure $callback): void;

    /**
     * Check if distributed transaction is active.
     */
    public function hasDistributedTransaction(string $transactionId): bool;

    /**
     * Get transaction statistics.
     */
    public function getStatistics(): array;
}