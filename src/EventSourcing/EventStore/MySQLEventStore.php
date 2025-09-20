<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\EventStore;

use LaravelModularDDD\EventSourcing\Contracts\EventStoreInterface;
use LaravelModularDDD\EventSourcing\Contracts\EventStreamInterface;
use LaravelModularDDD\EventSourcing\EventStream;
use LaravelModularDDD\EventSourcing\Exceptions\ConcurrencyException;
use LaravelModularDDD\EventSourcing\Exceptions\EventStoreException;
use LaravelModularDDD\EventSourcing\Ordering\EventSequencer;
use LaravelModularDDD\Core\Domain\Contracts\AggregateIdInterface;
use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;

class MySQLEventStore implements EventStoreInterface
{
    private const TABLE_NAME = 'event_store';

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly EventSerializer $serializer,
        private readonly EventSequencer $eventSequencer
    ) {}

    public function append(
        AggregateIdInterface $aggregateId,
        array $events,
        ?int $expectedVersion = null
    ): void {
        if (empty($events)) {
            return;
        }

        // Enforce event ordering before appending
        $orderedEvents = $this->eventSequencer->enforceOrder($aggregateId, $events);

        $this->connection->transaction(function () use ($aggregateId, $orderedEvents, $expectedVersion) {
            // Check concurrency if expected version is provided
            if ($expectedVersion !== null) {
                $this->checkConcurrency($aggregateId, $expectedVersion);
            }

            $currentVersion = $this->getAggregateVersion($aggregateId);

            foreach ($orderedEvents as $index => $event) {
                $this->insertEvent($aggregateId, $event, $currentVersion + $index + 1);
            }
        });
    }

    public function load(
        AggregateIdInterface $aggregateId,
        int $fromVersion = 1,
        ?int $toVersion = null
    ): EventStreamInterface {
        $query = $this->connection
            ->table(self::TABLE_NAME)
            ->where('aggregate_id', $aggregateId->toString())
            ->where('version', '>=', $fromVersion)
            ->orderBy('version');

        if ($toVersion !== null) {
            $query->where('version', '<=', $toVersion);
        }

        $records = $query->get();

        if ($records->isEmpty()) {
            return EventStream::empty();
        }

        $events = [];
        foreach ($records as $record) {
            try {
                $events[] = $this->serializer->deserialize([
                    'event_type' => $record->event_type,
                    'event_data' => json_decode($record->event_data, true),
                    'metadata' => json_decode($record->metadata ?? '{}', true),
                    'occurred_at' => $record->occurred_at,
                    'version' => $record->version,
                ]);
            } catch (\Exception $e) {
                throw EventStoreException::eventDeserializationFailed($e->getMessage());
            }
        }

        return EventStream::fromArray($events);
    }

    public function getAggregateVersion(AggregateIdInterface $aggregateId): int
    {
        $version = $this->connection
            ->table(self::TABLE_NAME)
            ->where('aggregate_id', $aggregateId->toString())
            ->max('version');

        return $version ?? 0;
    }

    public function aggregateExists(AggregateIdInterface $aggregateId): bool
    {
        return $this->connection
            ->table(self::TABLE_NAME)
            ->where('aggregate_id', $aggregateId->toString())
            ->exists();
    }

    public function loadEventsByType(string $eventType, int $limit = 100, int $offset = 0): array
    {
        $records = $this->connection
            ->table(self::TABLE_NAME)
            ->where('event_type', $eventType)
            ->orderBy('sequence_number')
            ->limit($limit)
            ->offset($offset)
            ->get();

        $events = [];
        foreach ($records as $record) {
            try {
                $events[] = $this->serializer->deserialize([
                    'event_type' => $record->event_type,
                    'event_data' => json_decode($record->event_data, true),
                    'metadata' => json_decode($record->metadata ?? '{}', true),
                    'occurred_at' => $record->occurred_at,
                    'version' => $record->version,
                ]);
            } catch (\Exception $e) {
                // Log error but continue with other events
                continue;
            }
        }

        return $events;
    }

    public function loadEventsFromSequence(int $fromSequence, int $limit = 100): array
    {
        $records = $this->connection
            ->table(self::TABLE_NAME)
            ->where('sequence_number', '>=', $fromSequence)
            ->orderBy('sequence_number')
            ->limit($limit)
            ->get();

        $events = [];
        foreach ($records as $record) {
            try {
                $event = $this->serializer->deserialize([
                    'event_type' => $record->event_type,
                    'event_data' => json_decode($record->event_data, true),
                    'metadata' => json_decode($record->metadata ?? '{}', true),
                    'occurred_at' => $record->occurred_at,
                    'version' => $record->version,
                ]);

                $events[] = [
                    'sequence_number' => $record->sequence_number,
                    'event' => $event,
                ];
            } catch (\Exception $e) {
                // Log error but continue
                continue;
            }
        }

        return $events;
    }

    /**
     * Batch load events for multiple aggregates to prevent N+1 queries
     */
    public function loadBatch(
        array $aggregateIds,
        int $fromVersion = 1,
        ?int $toVersion = null
    ): array {
        if (empty($aggregateIds)) {
            return [];
        }

        $stringIds = array_map(fn($id) => $id->toString(), $aggregateIds);

        $query = $this->connection
            ->table(self::TABLE_NAME)
            ->whereIn('aggregate_id', $stringIds)
            ->where('version', '>=', $fromVersion)
            ->orderBy('aggregate_id')
            ->orderBy('version');

        if ($toVersion !== null) {
            $query->where('version', '<=', $toVersion);
        }

        $records = $query->get();

        // Group events by aggregate ID
        $eventsByAggregate = [];
        foreach ($records as $record) {
            try {
                $event = $this->serializer->deserialize([
                    'event_type' => $record->event_type,
                    'event_data' => json_decode($record->event_data, true),
                    'metadata' => json_decode($record->metadata ?? '{}', true),
                    'occurred_at' => $record->occurred_at,
                    'version' => $record->version,
                ]);

                $aggregateId = $record->aggregate_id;
                if (!isset($eventsByAggregate[$aggregateId])) {
                    $eventsByAggregate[$aggregateId] = [];
                }
                $eventsByAggregate[$aggregateId][] = $event;
            } catch (\Exception $e) {
                // Log error but continue
                continue;
            }
        }

        // Convert to EventStreams
        $eventStreams = [];
        foreach ($aggregateIds as $aggregateId) {
            $stringId = $aggregateId->toString();
            $events = $eventsByAggregate[$stringId] ?? [];
            $eventStreams[$stringId] = empty($events) ? EventStream::empty() : EventStream::fromArray($events);
        }

        return $eventStreams;
    }

    /**
     * Get aggregate versions for multiple aggregates in a single query
     */
    public function getAggregateVersionsBatch(array $aggregateIds): array
    {
        if (empty($aggregateIds)) {
            return [];
        }

        $stringIds = array_map(fn($id) => $id->toString(), $aggregateIds);

        $records = $this->connection
            ->table(self::TABLE_NAME)
            ->select('aggregate_id', $this->connection->raw('MAX(version) as max_version'))
            ->whereIn('aggregate_id', $stringIds)
            ->groupBy('aggregate_id')
            ->get();

        $versions = [];
        foreach ($stringIds as $stringId) {
            $versions[$stringId] = 0; // Default version for non-existing aggregates
        }

        foreach ($records as $record) {
            $versions[$record->aggregate_id] = $record->max_version;
        }

        return $versions;
    }

    /**
     * Check if multiple aggregates exist in a single query
     */
    public function aggregateExistsBatch(array $aggregateIds): array
    {
        if (empty($aggregateIds)) {
            return [];
        }

        $stringIds = array_map(fn($id) => $id->toString(), $aggregateIds);

        $existingIds = $this->connection
            ->table(self::TABLE_NAME)
            ->select('aggregate_id')
            ->whereIn('aggregate_id', $stringIds)
            ->distinct()
            ->get()
            ->pluck('aggregate_id')
            ->toArray();

        $exists = [];
        foreach ($stringIds as $stringId) {
            $exists[$stringId] = in_array($stringId, $existingIds);
        }

        return $exists;
    }

    private function checkConcurrency(AggregateIdInterface $aggregateId, int $expectedVersion): void
    {
        $actualVersion = $this->getAggregateVersion($aggregateId);

        if ($actualVersion !== $expectedVersion) {
            throw new ConcurrencyException($aggregateId, $expectedVersion, $actualVersion);
        }
    }

    private function insertEvent(
        AggregateIdInterface $aggregateId,
        DomainEventInterface $event,
        int $version
    ): void {
        try {
            $serializedEvent = $this->serializer->serialize($event);

            $this->connection->table(self::TABLE_NAME)->insert([
                'aggregate_id' => $aggregateId->toString(),
                'aggregate_type' => $this->getAggregateType($event),
                'event_type' => $event->getEventType(),
                'event_version' => $event->getEventVersion(),
                'event_data' => json_encode($serializedEvent['data']),
                'metadata' => json_encode($serializedEvent['metadata']),
                'version' => $version,
                'occurred_at' => $event->getOccurredAt()->format('Y-m-d H:i:s.u'),
            ]);
        } catch (QueryException $e) {
            if ($this->isDuplicateKeyError($e)) {
                // Re-check for concurrency conflict
                $actualVersion = $this->getAggregateVersion($aggregateId);
                throw new ConcurrencyException($aggregateId, $version - 1, $actualVersion);
            }

            throw new EventStoreException("Failed to insert event: " . $e->getMessage());
        }
    }

    private function getAggregateType(DomainEventInterface $event): string
    {
        $metadata = $event->getMetadata();
        return $metadata['aggregate_type'] ?? 'unknown';
    }

    private function isDuplicateKeyError(QueryException $e): bool
    {
        return $e->getCode() === '23000' && str_contains($e->getMessage(), 'Duplicate entry');
    }
}