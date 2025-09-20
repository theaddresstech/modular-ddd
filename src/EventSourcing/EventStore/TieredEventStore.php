<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\EventStore;

use LaravelModularDDD\EventSourcing\Contracts\EventStoreInterface;
use LaravelModularDDD\EventSourcing\Contracts\EventStreamInterface;
use LaravelModularDDD\EventSourcing\EventStream;
use LaravelModularDDD\Core\Domain\Contracts\AggregateIdInterface;
use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\QueueManager;

class TieredEventStore implements EventStoreInterface
{
    private readonly Queue $queue;

    public function __construct(
        private readonly RedisEventStore $hot,
        private readonly MySQLEventStore $warm,
        Queue|QueueManager $queue,
        private readonly bool $asyncWarmStorage = true
    ) {
        $this->queue = $queue instanceof QueueManager ? $queue->connection() : $queue;
    }

    public function append(
        AggregateIdInterface $aggregateId,
        array $events,
        ?int $expectedVersion = null
    ): void {
        if (empty($events)) {
            return;
        }

        // Write to hot storage (Redis) first for immediate availability
        $this->hot->append($aggregateId, $events, $expectedVersion);

        // Write to warm storage (MySQL) either sync or async
        if ($this->asyncWarmStorage) {
            // Dispatch job to persist to MySQL asynchronously
            $this->queue->push(new PersistEventsJob($aggregateId, $events, $expectedVersion));
        } else {
            // Persist to MySQL synchronously
            $this->warm->append($aggregateId, $events, $expectedVersion);
        }
    }

    public function load(
        AggregateIdInterface $aggregateId,
        int $fromVersion = 1,
        ?int $toVersion = null
    ): EventStreamInterface {
        // Try hot storage first (Redis)
        $hotStream = $this->hot->tryLoad($aggregateId, $fromVersion, $toVersion);

        if ($hotStream !== null && !$hotStream->isEmpty()) {
            return $hotStream;
        }

        // Fall back to warm storage (MySQL)
        $warmStream = $this->warm->load($aggregateId, $fromVersion, $toVersion);

        if (!$warmStream->isEmpty()) {
            // Promote to hot storage for future reads
            $this->promoteToHotStorage($warmStream);
        }

        return $warmStream;
    }

    public function getAggregateVersion(AggregateIdInterface $aggregateId): int
    {
        // Try hot storage first
        if ($this->hot->aggregateExists($aggregateId)) {
            return $this->hot->getAggregateVersion($aggregateId);
        }

        // Fall back to warm storage
        return $this->warm->getAggregateVersion($aggregateId);
    }

    public function aggregateExists(AggregateIdInterface $aggregateId): bool
    {
        return $this->hot->aggregateExists($aggregateId) ||
               $this->warm->aggregateExists($aggregateId);
    }

    public function loadEventsByType(string $eventType, int $limit = 100, int $offset = 0): array
    {
        // This type of query is only supported by the warm storage (MySQL)
        return $this->warm->loadEventsByType($eventType, $limit, $offset);
    }

    public function loadEventsFromSequence(int $fromSequence, int $limit = 100): array
    {
        // This type of query is only supported by the warm storage (MySQL)
        return $this->warm->loadEventsFromSequence($fromSequence, $limit);
    }

    public function loadBatch(array $aggregateIds, int $fromVersion = 1, ?int $toVersion = null): array
    {
        $results = [];

        foreach ($aggregateIds as $aggregateId) {
            try {
                $results[$aggregateId->toString()] = $this->load($aggregateId, $fromVersion, $toVersion);
            } catch (\Exception $e) {
                // Log error but continue with other aggregates
                error_log("Failed to load aggregate {$aggregateId->toString()}: " . $e->getMessage());
                $results[$aggregateId->toString()] = new EventStream($aggregateId, []);
            }
        }

        return $results;
    }

    public function getAggregateVersionsBatch(array $aggregateIds): array
    {
        $versions = [];

        foreach ($aggregateIds as $aggregateId) {
            try {
                $versions[$aggregateId->toString()] = $this->getAggregateVersion($aggregateId);
            } catch (\Exception $e) {
                // Log error but continue with other aggregates
                error_log("Failed to get version for aggregate {$aggregateId->toString()}: " . $e->getMessage());
                $versions[$aggregateId->toString()] = 0;
            }
        }

        return $versions;
    }

    public function aggregateExistsBatch(array $aggregateIds): array
    {
        $exists = [];

        foreach ($aggregateIds as $aggregateId) {
            try {
                $exists[$aggregateId->toString()] = $this->aggregateExists($aggregateId);
            } catch (\Exception $e) {
                // Log error but continue with other aggregates
                error_log("Failed to check existence for aggregate {$aggregateId->toString()}: " . $e->getMessage());
                $exists[$aggregateId->toString()] = false;
            }
        }

        return $exists;
    }

    /**
     * Force synchronization of an aggregate from warm to hot storage
     */
    public function promoteToHotStorage(EventStreamInterface $eventStream): void
    {
        try {
            $this->hot->cache($eventStream);
        } catch (\Exception $e) {
            // Log error but don't fail - warm storage is still available
            error_log("Failed to promote events to hot storage: " . $e->getMessage());
        }
    }

    /**
     * Force an aggregate to be written to warm storage immediately
     */
    public function forceWarmPersistence(AggregateIdInterface $aggregateId): void
    {
        $events = $this->hot->load($aggregateId);

        if (!$events->isEmpty()) {
            $this->warm->append($aggregateId, $events->getEvents());
        }
    }

    /**
     * Evict an aggregate from hot storage
     */
    public function evictFromHotStorage(AggregateIdInterface $aggregateId): void
    {
        $this->hot->evict($aggregateId);
    }

    /**
     * Get statistics about the tiered storage
     */
    public function getStorageStats(): array
    {
        return [
            'hot_storage' => [
                'type' => 'redis',
                'stats' => $this->hot->getCacheStats(),
            ],
            'warm_storage' => [
                'type' => 'mysql',
                'async_writes' => $this->asyncWarmStorage,
            ],
        ];
    }

    /**
     * Warm up hot storage by preloading frequently accessed aggregates
     */
    public function warmUp(array $aggregateIds): void
    {
        foreach ($aggregateIds as $aggregateId) {
            try {
                // Load from warm storage and promote to hot
                $events = $this->warm->load($aggregateId);
                if (!$events->isEmpty()) {
                    $this->hot->cache($events);
                }
            } catch (\Exception $e) {
                // Log error but continue with other aggregates
                error_log("Failed to warm up aggregate {$aggregateId}: " . $e->getMessage());
            }
        }
    }

    /**
     * Archive old events from hot storage
     */
    public function archiveOldEvents(int $olderThanDays = 7): void
    {
        // Implementation would depend on specific requirements
        // This is a placeholder for the archival logic

        // For now, we can just let Redis TTL handle the cleanup
        // In a production system, you might want to:
        // 1. Identify aggregates not accessed recently
        // 2. Verify they exist in warm storage
        // 3. Remove from hot storage
    }
}