<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Contracts;

use LaravelModularDDD\Core\Domain\Contracts\AggregateIdInterface;
use LaravelModularDDD\Core\Domain\Contracts\AggregateRootInterface;

interface SnapshotStoreInterface
{
    /**
     * Save an aggregate snapshot
     */
    public function save(
        AggregateIdInterface $aggregateId,
        AggregateRootInterface $aggregate,
        int $version
    ): void;

    /**
     * Load the latest snapshot for an aggregate
     */
    public function load(AggregateIdInterface $aggregateId): ?AggregateSnapshotInterface;

    /**
     * Load a specific version snapshot
     */
    public function loadVersion(
        AggregateIdInterface $aggregateId,
        int $version
    ): ?AggregateSnapshotInterface;

    /**
     * Check if a snapshot exists for an aggregate
     */
    public function exists(AggregateIdInterface $aggregateId): bool;

    /**
     * Remove old snapshots (keep only the latest N)
     */
    public function pruneSnapshots(AggregateIdInterface $aggregateId, int $keepCount = 3): void;

    /**
     * Remove all snapshots for an aggregate
     */
    public function removeAll(AggregateIdInterface $aggregateId): void;
}