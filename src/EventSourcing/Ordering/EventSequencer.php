<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Ordering;

use LaravelModularDDD\Core\Domain\Contracts\AggregateIdInterface;
use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;
use LaravelModularDDD\EventSourcing\EventStream;
use LaravelModularDDD\EventSourcing\Exceptions\EventOrderingException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * EventSequencer
 *
 * Ensures proper event ordering per aggregate to maintain consistency.
 * Detects out-of-order events and provides reordering capabilities.
 */
final class EventSequencer
{
    private const SEQUENCE_CACHE_PREFIX = 'event_sequence:';
    private const SEQUENCE_CACHE_TTL = 3600; // 1 hour

    public function __construct(
        private readonly bool $strictOrdering = true,
        private readonly int $maxReorderWindow = 100 // Maximum events to consider for reordering
    ) {}

    /**
     * Enforce event ordering for an aggregate.
     */
    public function enforceOrder(AggregateIdInterface $aggregateId, array $events): array
    {
        if (empty($events)) {
            return $events;
        }

        // Get the current sequence for this aggregate
        $currentSequence = $this->getCurrentSequence($aggregateId);

        // Validate and order events
        $orderedEvents = [];
        $expectedSequence = $currentSequence + 1;

        foreach ($events as $event) {
            if (!$event instanceof DomainEventInterface) {
                throw new EventOrderingException("Invalid event type provided");
            }

            $eventSequence = $this->getEventSequence($event);

            if ($eventSequence !== $expectedSequence) {
                if ($this->strictOrdering) {
                    throw new EventOrderingException(
                        "Event sequence violation for aggregate {$aggregateId->toString()}. " .
                        "Expected sequence {$expectedSequence}, got {$eventSequence}"
                    );
                } else {
                    Log::warning('Event sequence violation detected', [
                        'aggregate_id' => $aggregateId->toString(),
                        'expected_sequence' => $expectedSequence,
                        'actual_sequence' => $eventSequence,
                        'event_type' => $event->getEventType(),
                    ]);
                }
            }

            $orderedEvents[] = $event;
            $expectedSequence++;
        }

        // Update the sequence for this aggregate
        $this->updateSequence($aggregateId, $expectedSequence - 1);

        return $orderedEvents;
    }

    /**
     * Detect out-of-order events in a stream.
     */
    public function detectOutOfOrder(EventStream $stream): array
    {
        $violations = [];
        $events = $stream->getEvents();
        $expectedSequence = 1;

        foreach ($events as $index => $event) {
            $eventSequence = $this->getEventSequence($event);

            if ($eventSequence !== $expectedSequence) {
                $violations[] = [
                    'index' => $index,
                    'event_id' => $event->getEventId(),
                    'event_type' => $event->getEventType(),
                    'expected_sequence' => $expectedSequence,
                    'actual_sequence' => $eventSequence,
                    'gap' => $eventSequence - $expectedSequence,
                ];
            }

            $expectedSequence = $eventSequence + 1;
        }

        return $violations;
    }

    /**
     * Reorder events to maintain proper sequence.
     */
    public function reorderEvents(array $events): array
    {
        if (empty($events)) {
            return $events;
        }

        // Group events by aggregate
        $eventsByAggregate = [];
        foreach ($events as $event) {
            $aggregateId = $event->getAggregateId();
            $eventsByAggregate[$aggregateId][] = $event;
        }

        $reorderedEvents = [];

        // Process each aggregate's events separately
        foreach ($eventsByAggregate as $aggregateId => $aggregateEvents) {
            $orderedAggregateEvents = $this->reorderAggregateEvents($aggregateEvents);
            $reorderedEvents = array_merge($reorderedEvents, $orderedAggregateEvents);
        }

        return $reorderedEvents;
    }

    /**
     * Check if events are properly ordered.
     */
    public function isProperlyOrdered(array $events): bool
    {
        if (empty($events)) {
            return true;
        }

        // Group by aggregate and check ordering within each group
        $eventsByAggregate = [];
        foreach ($events as $event) {
            $aggregateId = $event->getAggregateId();
            $eventsByAggregate[$aggregateId][] = $event;
        }

        foreach ($eventsByAggregate as $aggregateEvents) {
            if (!$this->isAggregateEventsOrdered($aggregateEvents)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the next expected sequence number for an aggregate.
     */
    public function getNextSequence(AggregateIdInterface $aggregateId): int
    {
        return $this->getCurrentSequence($aggregateId) + 1;
    }

    /**
     * Reset sequence for an aggregate (useful for testing or rebuilds).
     */
    public function resetSequence(AggregateIdInterface $aggregateId): void
    {
        $cacheKey = self::SEQUENCE_CACHE_PREFIX . $aggregateId->toString();
        Cache::forget($cacheKey);

        Log::info('Event sequence reset for aggregate', [
            'aggregate_id' => $aggregateId->toString(),
        ]);
    }

    /**
     * Get sequence statistics for monitoring.
     */
    public function getSequenceStatistics(): array
    {
        // This would typically query a persistent store for sequence information
        // For now, return basic statistics
        return [
            'total_aggregates_tracked' => 0, // Would need persistent storage
            'average_sequence_length' => 0,
            'max_sequence_length' => 0,
            'sequence_violations_today' => 0,
            'last_violation' => null,
        ];
    }

    /**
     * Create a sequence checkpoint for recovery purposes.
     */
    public function createCheckpoint(AggregateIdInterface $aggregateId): array
    {
        $currentSequence = $this->getCurrentSequence($aggregateId);

        $checkpoint = [
            'aggregate_id' => $aggregateId->toString(),
            'sequence_number' => $currentSequence,
            'timestamp' => now()->toISOString(),
            'checksum' => md5($aggregateId->toString() . $currentSequence),
        ];

        // Store checkpoint (in a real implementation, this would go to a persistent store)
        $checkpointKey = 'sequence_checkpoint:' . $aggregateId->toString();
        Cache::put($checkpointKey, $checkpoint, now()->addHours(24));

        Log::info('Sequence checkpoint created', [
            'aggregate_id' => $aggregateId->toString(),
            'sequence_number' => $currentSequence,
        ]);

        return $checkpoint;
    }

    /**
     * Restore sequence from a checkpoint.
     */
    public function restoreFromCheckpoint(AggregateIdInterface $aggregateId, array $checkpoint): bool
    {
        // Validate checkpoint
        $expectedChecksum = md5($aggregateId->toString() . $checkpoint['sequence_number']);
        if ($checkpoint['checksum'] !== $expectedChecksum) {
            Log::error('Invalid checkpoint checksum', [
                'aggregate_id' => $aggregateId->toString(),
                'expected' => $expectedChecksum,
                'actual' => $checkpoint['checksum'],
            ]);
            return false;
        }

        // Restore sequence
        $this->updateSequence($aggregateId, $checkpoint['sequence_number']);

        Log::info('Sequence restored from checkpoint', [
            'aggregate_id' => $aggregateId->toString(),
            'sequence_number' => $checkpoint['sequence_number'],
            'checkpoint_timestamp' => $checkpoint['timestamp'],
        ]);

        return true;
    }

    private function getCurrentSequence(AggregateIdInterface $aggregateId): int
    {
        $cacheKey = self::SEQUENCE_CACHE_PREFIX . $aggregateId->toString();
        return Cache::get($cacheKey, 0);
    }

    private function updateSequence(AggregateIdInterface $aggregateId, int $sequence): void
    {
        $cacheKey = self::SEQUENCE_CACHE_PREFIX . $aggregateId->toString();
        Cache::put($cacheKey, $sequence, now()->addSeconds(self::SEQUENCE_CACHE_TTL));
    }

    private function getEventSequence(DomainEventInterface $event): int
    {
        // Try to get sequence from event metadata first
        $metadata = $event->getMetadata();
        if (isset($metadata['sequence'])) {
            return (int) $metadata['sequence'];
        }

        // Fallback to event version if available
        if (method_exists($event, 'getVersion')) {
            return $event->getVersion();
        }

        // Default fallback - this should not happen in a well-designed system
        Log::warning('Event missing sequence information', [
            'event_id' => $event->getEventId(),
            'event_type' => $event->getEventType(),
            'aggregate_id' => $event->getAggregateId(),
        ]);

        return 1;
    }

    private function reorderAggregateEvents(array $events): array
    {
        // Sort events by their sequence number
        usort($events, function (DomainEventInterface $a, DomainEventInterface $b) {
            return $this->getEventSequence($a) <=> $this->getEventSequence($b);
        });

        // Validate that we can properly reorder (no gaps)
        $expectedSequence = $this->getEventSequence($events[0]);
        foreach ($events as $event) {
            $actualSequence = $this->getEventSequence($event);
            if ($actualSequence !== $expectedSequence) {
                Log::warning('Gap detected in event sequence during reordering', [
                    'aggregate_id' => $event->getAggregateId(),
                    'expected_sequence' => $expectedSequence,
                    'actual_sequence' => $actualSequence,
                ]);
            }
            $expectedSequence = $actualSequence + 1;
        }

        return $events;
    }

    private function isAggregateEventsOrdered(array $events): bool
    {
        if (count($events) <= 1) {
            return true;
        }

        $previousSequence = $this->getEventSequence($events[0]);

        for ($i = 1; $i < count($events); $i++) {
            $currentSequence = $this->getEventSequence($events[$i]);
            if ($currentSequence <= $previousSequence) {
                return false;
            }
            $previousSequence = $currentSequence;
        }

        return true;
    }
}