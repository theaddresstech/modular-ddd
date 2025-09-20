<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\EventStore;

use LaravelModularDDD\EventSourcing\Contracts\EventStoreInterface;
use LaravelModularDDD\EventSourcing\Contracts\EventStreamInterface;
use LaravelModularDDD\EventSourcing\EventStream;
use LaravelModularDDD\EventSourcing\Exceptions\ConcurrencyException;
use LaravelModularDDD\EventSourcing\Exceptions\EventStoreException;
use LaravelModularDDD\Core\Domain\Contracts\AggregateIdInterface;
use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;
use Illuminate\Redis\Connections\Connection;

class RedisEventStore implements EventStoreInterface
{
    private Connection $redis;

    public function __construct(
        Connection $redis,
        private readonly EventSerializer $serializer,
        private readonly int $ttl = 86400 // 24 hours default TTL
    ) {
        $this->redis = $redis;
    }

    public function append(
        AggregateIdInterface $aggregateId,
        array $events,
        ?int $expectedVersion = null
    ): void {
        if (empty($events)) {
            return;
        }

        $key = $this->getAggregateKey($aggregateId);

        $this->redis->multi();

        try {
            // Check concurrency if expected version is provided
            if ($expectedVersion !== null) {
                $actualVersion = $this->getAggregateVersion($aggregateId);
                if ($actualVersion !== $expectedVersion) {
                    throw new ConcurrencyException($aggregateId, $expectedVersion, $actualVersion);
                }
            }

            // Add events to the stream
            foreach ($events as $event) {
                $serializedEvent = $this->serializer->serializeForRedis($event);
                $this->redis->xadd($key, '*', ['event' => $serializedEvent]);
            }

            // Set expiration
            $this->redis->expire($key, $this->ttl);

            // Update version counter
            $versionKey = $this->getVersionKey($aggregateId);
            $this->redis->incrby($versionKey, count($events));
            $this->redis->expire($versionKey, $this->ttl);

            $this->redis->exec();
        } catch (\Exception $e) {
            $this->redis->discard();
            throw new EventStoreException("Failed to append events to Redis: " . $e->getMessage());
        }
    }

    public function load(
        AggregateIdInterface $aggregateId,
        int $fromVersion = 1,
        ?int $toVersion = null
    ): EventStreamInterface {
        $key = $this->getAggregateKey($aggregateId);

        try {
            // Read all events from the stream
            $entries = $this->redis->xrange($key, '-', '+');

            if (empty($entries)) {
                return EventStream::empty();
            }

            $events = [];
            $currentVersion = 1;

            foreach ($entries as $entry) {
                if ($currentVersion < $fromVersion) {
                    $currentVersion++;
                    continue;
                }

                if ($toVersion !== null && $currentVersion > $toVersion) {
                    break;
                }

                if (isset($entry['event'])) {
                    try {
                        $event = $this->serializer->deserializeFromRedis($entry['event']);
                        $events[] = $event;
                    } catch (\Exception $e) {
                        // Log error but continue with other events
                        error_log("Failed to deserialize event: " . $e->getMessage());
                    }
                }

                $currentVersion++;
            }

            return EventStream::fromArray($events);
        } catch (\Exception $e) {
            throw new EventStoreException("Failed to load events from Redis: " . $e->getMessage());
        }
    }

    public function getAggregateVersion(AggregateIdInterface $aggregateId): int
    {
        $versionKey = $this->getVersionKey($aggregateId);
        $version = $this->redis->get($versionKey);

        return $version ? (int) $version : 0;
    }

    public function aggregateExists(AggregateIdInterface $aggregateId): bool
    {
        $key = $this->getAggregateKey($aggregateId);
        return $this->redis->exists($key) > 0;
    }

    public function loadEventsByType(string $eventType, int $limit = 100, int $offset = 0): array
    {
        // Redis Streams don't support efficient filtering by event type
        // This implementation is not optimal for Redis and should typically
        // delegate to the MySQL store for this type of query
        throw new EventStoreException(
            "Loading events by type is not efficiently supported by Redis store. Use MySQL store instead."
        );
    }

    public function loadEventsFromSequence(int $fromSequence, int $limit = 100): array
    {
        // Redis Streams don't have global sequence numbers in this implementation
        // This would typically be delegated to the MySQL store
        throw new EventStoreException(
            "Loading events from sequence is not supported by Redis store. Use MySQL store instead."
        );
    }

    public function loadBatch(array $aggregateIds, int $fromVersion = 1, ?int $toVersion = null): array
    {
        $results = [];

        foreach ($aggregateIds as $aggregateId) {
            try {
                $results[$aggregateId->toString()] = $this->load($aggregateId, $fromVersion, $toVersion);
            } catch (\Exception $e) {
                error_log("Failed to load aggregate {$aggregateId->toString()}: " . $e->getMessage());
                $results[$aggregateId->toString()] = EventStream::empty();
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
                error_log("Failed to check existence for aggregate {$aggregateId->toString()}: " . $e->getMessage());
                $exists[$aggregateId->toString()] = false;
            }
        }

        return $exists;
    }

    /**
     * Try to load events (returns null if not in cache)
     */
    public function tryLoad(
        AggregateIdInterface $aggregateId,
        int $fromVersion = 1,
        ?int $toVersion = null
    ): ?EventStreamInterface {
        if (!$this->aggregateExists($aggregateId)) {
            return null;
        }

        return $this->load($aggregateId, $fromVersion, $toVersion);
    }

    /**
     * Cache events from another store
     */
    public function cache(EventStreamInterface $eventStream): void
    {
        if ($eventStream->isEmpty()) {
            return;
        }

        $firstEvent = $eventStream->first();
        if (!$firstEvent) {
            return;
        }

        $aggregateId = $firstEvent->getAggregateId();
        $key = $this->getAggregateKey($aggregateId);

        $this->redis->multi();

        try {
            // Remove existing stream
            $this->redis->del($key);

            // Add all events
            foreach ($eventStream as $event) {
                $serializedEvent = $this->serializer->serializeForRedis($event);
                $this->redis->xadd($key, '*', ['event' => $serializedEvent]);
            }

            // Set expiration
            $this->redis->expire($key, $this->ttl);

            // Update version counter
            $versionKey = $this->getVersionKey($aggregateId);
            $this->redis->set($versionKey, $eventStream->count());
            $this->redis->expire($versionKey, $this->ttl);

            $this->redis->exec();
        } catch (\Exception $e) {
            $this->redis->discard();
            throw new EventStoreException("Failed to cache events in Redis: " . $e->getMessage());
        }
    }

    /**
     * Remove aggregate from cache
     */
    public function evict(AggregateIdInterface $aggregateId): void
    {
        $key = $this->getAggregateKey($aggregateId);
        $versionKey = $this->getVersionKey($aggregateId);

        $this->redis->del([$key, $versionKey]);
    }

    /**
     * Get cache statistics
     */
    public function getCacheStats(): array
    {
        return [
            'ttl' => $this->ttl,
            'memory_usage' => $this->redis->info('memory')['used_memory_human'] ?? 'unknown',
        ];
    }

    private function getAggregateKey(AggregateIdInterface $aggregateId): string
    {
        return "events:aggregate:{$aggregateId->toString()}";
    }

    private function getVersionKey(AggregateIdInterface $aggregateId): string
    {
        return "events:version:{$aggregateId->toString()}";
    }
}