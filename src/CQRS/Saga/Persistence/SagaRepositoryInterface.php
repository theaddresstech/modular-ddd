<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Saga\Persistence;

use LaravelModularDDD\CQRS\Saga\SagaInterface;
use LaravelModularDDD\CQRS\Saga\SagaState;

interface SagaRepositoryInterface
{
    /**
     * Save saga to persistence
     */
    public function save(SagaInterface $saga): void;

    /**
     * Find saga by ID
     */
    public function findById(string $sagaId): ?SagaInterface;

    /**
     * Find all active sagas
     */
    public function findActiveSagas(): array;

    /**
     * Find sagas by state
     */
    public function findByState(SagaState $state): array;

    /**
     * Find sagas by type
     */
    public function findByType(string $sagaType): array;

    /**
     * Find timed out sagas
     */
    public function findTimedOutSagas(): array;

    /**
     * Delete saga
     */
    public function delete(string $sagaId): void;

    /**
     * Get saga statistics
     */
    public function getStatistics(): array;

    /**
     * Clean up completed sagas older than specified days
     */
    public function cleanupOldSagas(int $daysOld = 30): int;
}