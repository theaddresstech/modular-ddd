<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\ReadModels\Persistence;

use LaravelModularDDD\CQRS\ReadModels\ReadModel;

interface ReadModelRepositoryInterface
{
    /**
     * Save read model to persistence
     */
    public function save(ReadModel $readModel): void;

    /**
     * Find read model by ID
     */
    public function findById(string $id): ?ReadModel;

    /**
     * Find read model by type and aggregate ID
     */
    public function findByTypeAndAggregateId(string $type, string $aggregateId): ?ReadModel;

    /**
     * Find all read models for an aggregate
     */
    public function findByAggregateId(string $aggregateId): array;

    /**
     * Find read models by type
     */
    public function findByType(string $type): array;

    /**
     * Search read models with criteria
     */
    public function search(array $criteria): array;

    /**
     * Delete read model by ID
     */
    public function delete(string $id): void;

    /**
     * Delete all read models for an aggregate
     */
    public function deleteByAggregateId(string $aggregateId): int;

    /**
     * Delete read models older than specified days
     */
    public function deleteOlderThan(int $daysOld): int;

    /**
     * Get repository statistics
     */
    public function getStatistics(): array;

    /**
     * Count read models by type
     */
    public function countByType(string $type): int;

    /**
     * Get read models with pagination
     */
    public function paginate(int $page = 1, int $perPage = 50): array;
}