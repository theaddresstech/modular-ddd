<?php

declare(strict_types=1);

namespace LaravelModularDDD\Core\Application\Repository;

use LaravelModularDDD\Core\Domain\Contracts\AggregateIdInterface;
use LaravelModularDDD\Core\Domain\Contracts\AggregateRootInterface;
use LaravelModularDDD\EventSourcing\Contracts\EventStoreInterface;
use LaravelModularDDD\EventSourcing\Contracts\SnapshotStoreInterface;
use LaravelModularDDD\EventSourcing\AggregateReconstructor;

class BatchAggregateRepository
{
    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly ?SnapshotStoreInterface $snapshotStore = null,
        private readonly ?AggregateReconstructor $reconstructor = null
    ) {}

    /**
     * Load multiple aggregates efficiently using batch operations
     *
     * @param AggregateIdInterface[] $aggregateIds
     * @param string $aggregateClass
     * @return AggregateRootInterface[] Keyed by aggregate ID string
     */
    public function loadBatch(array $aggregateIds, string $aggregateClass): array
    {
        if (empty($aggregateIds)) {
            return [];
        }

        $aggregates = [];
        $reconstructor = $this->reconstructor ?? new AggregateReconstructor();

        // Step 1: Load snapshots if snapshot store is available
        $snapshots = [];
        if ($this->snapshotStore !== null) {
            $snapshots = $this->snapshotStore->loadBatch($aggregateIds);
        }

        // Step 2: Determine which aggregates need event loading
        $aggregatesNeedingEvents = [];
        $fromVersions = [];

        foreach ($aggregateIds as $aggregateId) {
            $stringId = $aggregateId->toString();
            $snapshot = $snapshots[$stringId] ?? null;

            if ($snapshot !== null) {
                // Start from snapshot and load events after snapshot version
                $aggregate = $reconstructor->reconstituteFromSnapshot($snapshot, $aggregateClass);
                $aggregates[$stringId] = $aggregate;
                $aggregatesNeedingEvents[] = $aggregateId;
                $fromVersions[$stringId] = $snapshot->getVersion() + 1;
            } else {
                // Need to load all events from version 1
                $aggregatesNeedingEvents[] = $aggregateId;
                $fromVersions[$stringId] = 1;
            }
        }

        // Step 3: Batch load events for all aggregates that need them
        if (!empty($aggregatesNeedingEvents)) {
            $eventStreamsByAggregate = $this->eventStore->loadBatch($aggregatesNeedingEvents);

            foreach ($aggregatesNeedingEvents as $aggregateId) {
                $stringId = $aggregateId->toString();
                $eventStream = $eventStreamsByAggregate[$stringId] ?? null;

                if ($eventStream !== null && !$eventStream->isEmpty()) {
                    if (isset($aggregates[$stringId])) {
                        // Apply events to existing aggregate (from snapshot)
                        $aggregates[$stringId] = $reconstructor->applyEvents(
                            $aggregates[$stringId],
                            $eventStream->getEvents()
                        );
                    } else {
                        // Reconstitute aggregate from events only
                        $aggregates[$stringId] = $reconstructor->reconstitute(
                            $aggregateId,
                            $aggregateClass,
                            $eventStream->getEvents()
                        );
                    }
                }
            }
        }

        return $aggregates;
    }

    /**
     * Check which aggregates exist in batch
     *
     * @param AggregateIdInterface[] $aggregateIds
     * @return bool[] Keyed by aggregate ID string
     */
    public function existsBatch(array $aggregateIds): array
    {
        return $this->eventStore->aggregateExistsBatch($aggregateIds);
    }

    /**
     * Get aggregate versions in batch
     *
     * @param AggregateIdInterface[] $aggregateIds
     * @return int[] Keyed by aggregate ID string
     */
    public function getVersionsBatch(array $aggregateIds): array
    {
        return $this->eventStore->getAggregateVersionsBatch($aggregateIds);
    }

    /**
     * Save multiple aggregates efficiently
     *
     * @param AggregateRootInterface[] $aggregates Keyed by aggregate ID string
     */
    public function saveBatch(array $aggregates): void
    {
        foreach ($aggregates as $aggregate) {
            $uncommittedEvents = $aggregate->getUncommittedEvents();

            if (!empty($uncommittedEvents)) {
                $this->eventStore->append(
                    $aggregate->getId(),
                    $uncommittedEvents,
                    $aggregate->getVersion() - count($uncommittedEvents)
                );

                $aggregate->markEventsAsCommitted();

                // Create snapshot if needed
                if ($this->snapshotStore !== null) {
                    // This would typically be handled by a snapshot manager
                    // that decides when to create snapshots based on strategy
                }
            }
        }
    }

    /**
     * Load aggregates with projection data to prevent additional queries
     *
     * @param AggregateIdInterface[] $aggregateIds
     * @param string $aggregateClass
     * @param array $projectionData Pre-loaded projection data keyed by aggregate ID
     * @return AggregateRootInterface[] Keyed by aggregate ID string
     */
    public function loadBatchWithProjections(
        array $aggregateIds,
        string $aggregateClass,
        array $projectionData = []
    ): array {
        $aggregates = $this->loadBatch($aggregateIds, $aggregateClass);

        // Enrich aggregates with projection data if available
        foreach ($aggregates as $stringId => $aggregate) {
            if (isset($projectionData[$stringId]) && method_exists($aggregate, 'enrichWithProjectionData')) {
                $aggregate->enrichWithProjectionData($projectionData[$stringId]);
            }
        }

        return $aggregates;
    }

    /**
     * Preload related aggregates to prevent N+1 queries in complex operations
     *
     * @param AggregateIdInterface[] $parentIds
     * @param callable $relationExtractor Function to extract related aggregate IDs
     * @param string $relatedAggregateClass
     * @return array Multi-dimensional array: parent_id => [related_aggregates]
     */
    public function preloadRelatedAggregates(
        array $parentIds,
        callable $relationExtractor,
        string $relatedAggregateClass
    ): array {
        $parentAggregates = $this->loadBatch($parentIds, $relatedAggregateClass);

        // Extract all related aggregate IDs
        $allRelatedIds = [];
        $relatedIdsByParent = [];

        foreach ($parentAggregates as $parentStringId => $parentAggregate) {
            $relatedIds = $relationExtractor($parentAggregate);
            $relatedIdsByParent[$parentStringId] = $relatedIds;
            $allRelatedIds = array_merge($allRelatedIds, $relatedIds);
        }

        // Remove duplicates
        $allRelatedIds = array_unique($allRelatedIds, SORT_REGULAR);

        // Batch load all related aggregates
        $relatedAggregates = $this->loadBatch($allRelatedIds, $relatedAggregateClass);

        // Group by parent
        $result = [];
        foreach ($relatedIdsByParent as $parentStringId => $relatedIds) {
            $result[$parentStringId] = [];
            foreach ($relatedIds as $relatedId) {
                $relatedStringId = $relatedId->toString();
                if (isset($relatedAggregates[$relatedStringId])) {
                    $result[$parentStringId][] = $relatedAggregates[$relatedStringId];
                }
            }
        }

        return $result;
    }
}