<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Snapshot;

use LaravelModularDDD\Core\Domain\Contracts\AggregateRootInterface;
use LaravelModularDDD\EventSourcing\Contracts\AggregateSnapshotInterface;

interface SnapshotStrategyInterface
{
    /**
     * Determine if a snapshot should be taken
     */
    public function shouldSnapshot(
        AggregateRootInterface $aggregate,
        ?AggregateSnapshotInterface $lastSnapshot = null
    ): bool;

    /**
     * Get the strategy name
     */
    public function getName(): string;

    /**
     * Get strategy configuration
     */
    public function getConfiguration(): array;

    /**
     * Update strategy based on performance metrics
     */
    public function updateFromMetrics(array $metrics): void;
}