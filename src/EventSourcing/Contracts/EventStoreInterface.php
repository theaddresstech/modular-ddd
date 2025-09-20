<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Contracts;

use LaravelModularDDD\Core\Domain\Contracts\AggregateIdInterface;
use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;

interface EventStoreInterface
{
    /**
     * Append events to the event store
     *
     * @param AggregateIdInterface $aggregateId
     * @param DomainEventInterface[] $events
     * @param int|null $expectedVersion Expected version for optimistic concurrency control
     * @throws ConcurrencyException
     */
    public function append(
        AggregateIdInterface $aggregateId,
        array $events,
        ?int $expectedVersion = null
    ): void;

    /**
     * Load events for an aggregate
     *
     * @param AggregateIdInterface $aggregateId
     * @param int $fromVersion Load events from this version (inclusive)
     * @param int|null $toVersion Load events up to this version (inclusive)
     * @return EventStreamInterface
     */
    public function load(
        AggregateIdInterface $aggregateId,
        int $fromVersion = 1,
        ?int $toVersion = null
    ): EventStreamInterface;

    /**
     * Get the current version of an aggregate
     */
    public function getAggregateVersion(AggregateIdInterface $aggregateId): int;

    /**
     * Check if aggregate exists
     */
    public function aggregateExists(AggregateIdInterface $aggregateId): bool;

    /**
     * Load all events of a specific type
     *
     * @param string $eventType
     * @param int $limit
     * @param int $offset
     * @return DomainEventInterface[]
     */
    public function loadEventsByType(string $eventType, int $limit = 100, int $offset = 0): array;

    /**
     * Load events from a specific sequence number (for projections)
     */
    public function loadEventsFromSequence(int $fromSequence, int $limit = 100): array;

    /**
     * Batch load events for multiple aggregates to prevent N+1 queries
     *
     * @param AggregateIdInterface[] $aggregateIds
     * @param int $fromVersion Load events from this version (inclusive)
     * @param int|null $toVersion Load events up to this version (inclusive)
     * @return EventStreamInterface[] Keyed by aggregate ID string
     */
    public function loadBatch(
        array $aggregateIds,
        int $fromVersion = 1,
        ?int $toVersion = null
    ): array;

    /**
     * Get aggregate versions for multiple aggregates in a single query
     *
     * @param AggregateIdInterface[] $aggregateIds
     * @return int[] Keyed by aggregate ID string
     */
    public function getAggregateVersionsBatch(array $aggregateIds): array;

    /**
     * Check if multiple aggregates exist in a single query
     *
     * @param AggregateIdInterface[] $aggregateIds
     * @return bool[] Keyed by aggregate ID string
     */
    public function aggregateExistsBatch(array $aggregateIds): array;
}