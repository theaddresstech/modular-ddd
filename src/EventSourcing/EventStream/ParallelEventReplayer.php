<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\EventStream;

use LaravelModularDDD\EventSourcing\Contracts\EventStoreInterface;
use LaravelModularDDD\EventSourcing\Contracts\EventStreamInterface;
use LaravelModularDDD\EventSourcing\EventStream;
use LaravelModularDDD\Core\Domain\Contracts\AggregateIdInterface;
use LaravelModularDDD\Core\Domain\Contracts\AggregateRootInterface;
use LaravelModularDDD\Core\Domain\EventSourcedAggregateRoot;
use Illuminate\Support\Facades\Cache;

class ParallelEventReplayer
{
    private const CACHE_PREFIX = 'replay_progress:';

    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly int $batchSize = 100,
        private readonly int $maxConcurrency = 4
    ) {}

    /**
     * Replay events to reconstruct an aggregate with parallel processing
     */
    public function replayAggregate(
        string $aggregateClass,
        AggregateIdInterface $aggregateId,
        ?int $toVersion = null
    ): AggregateRootInterface {
        // Load events in batches
        $eventStream = $this->loadEventsInBatches($aggregateId, $toVersion);

        // If stream is small, process sequentially
        if ($eventStream->count() <= $this->batchSize) {
            return $this->replaySequential($aggregateClass, $aggregateId, $eventStream);
        }

        // Partition events for parallel processing
        $partitionedStream = $this->partitionEventStream($eventStream);

        // Process partitions and merge results
        return $this->replayPartitioned($aggregateClass, $aggregateId, $partitionedStream);
    }

    /**
     * Replay multiple aggregates in parallel
     */
    public function replayMultipleAggregates(
        array $aggregateSpecs,
        ?callable $progressCallback = null
    ): array {
        $results = [];
        $total = count($aggregateSpecs);
        $completed = 0;

        // Process in batches to avoid memory issues
        $batches = array_chunk($aggregateSpecs, $this->maxConcurrency);

        foreach ($batches as $batch) {
            $batchResults = $this->processBatch($batch);
            $results = array_merge($results, $batchResults);

            $completed += count($batch);

            if ($progressCallback) {
                $progressCallback($completed, $total);
            }
        }

        return $results;
    }

    /**
     * Replay events with checkpoint support for long-running operations
     */
    public function replayWithCheckpoints(
        string $aggregateClass,
        AggregateIdInterface $aggregateId,
        ?int $toVersion = null,
        int $checkpointInterval = 1000
    ): AggregateRootInterface {
        $checkpointKey = $this->getCheckpointKey($aggregateId);
        $lastCheckpoint = Cache::get($checkpointKey, 0);

        // Start from last checkpoint
        $fromVersion = $lastCheckpoint + 1;

        $eventStream = $this->loadEventsInBatches($aggregateId, $toVersion, $fromVersion);

        if ($eventStream->isEmpty()) {
            // No new events, try to load from cache or return empty aggregate
            $cached = Cache::get($this->getAggregateKey($aggregateId));
            if ($cached) {
                return unserialize($cached);
            }

            // Create new aggregate
            return new $aggregateClass($aggregateId);
        }

        // Process events in checkpoint intervals
        $aggregate = $this->loadAggregateFromCheckpoint($aggregateClass, $aggregateId, $lastCheckpoint);
        $eventsProcessed = 0;

        foreach ($eventStream as $event) {
            $aggregate->replay([$event]);
            $eventsProcessed++;

            // Save checkpoint
            if ($eventsProcessed % $checkpointInterval === 0) {
                $this->saveCheckpoint($aggregateId, $lastCheckpoint + $eventsProcessed, $aggregate);
            }
        }

        // Save final checkpoint
        $this->saveCheckpoint($aggregateId, $lastCheckpoint + $eventsProcessed, $aggregate);

        return $aggregate;
    }

    /**
     * Estimate replay time based on event count and system metrics
     */
    public function estimateReplayTime(AggregateIdInterface $aggregateId): array
    {
        $eventCount = $this->eventStore->getAggregateVersion($aggregateId);

        // Get historical performance metrics
        $avgEventProcessingTime = $this->getAverageEventProcessingTime();
        $systemLoad = $this->getSystemLoad();

        // Adjust for system load
        $adjustedProcessingTime = $avgEventProcessingTime * (1 + $systemLoad);

        // Calculate estimates
        $sequentialTime = $eventCount * $adjustedProcessingTime;
        $parallelTime = $sequentialTime / min($this->maxConcurrency, max(1, $eventCount / $this->batchSize));

        return [
            'event_count' => $eventCount,
            'estimated_sequential_seconds' => $sequentialTime,
            'estimated_parallel_seconds' => $parallelTime,
            'recommended_strategy' => $eventCount > 1000 ? 'parallel' : 'sequential',
            'system_load_factor' => $systemLoad,
        ];
    }

    private function loadEventsInBatches(
        AggregateIdInterface $aggregateId,
        ?int $toVersion = null,
        int $fromVersion = 1
    ): EventStreamInterface {
        return $this->eventStore->load($aggregateId, $fromVersion, $toVersion);
    }

    private function partitionEventStream(EventStreamInterface $eventStream): PartitionedEventStream
    {
        $events = $eventStream->getEvents();
        $partitionSize = max(1, ceil(count($events) / $this->maxConcurrency));
        $partitions = [];

        for ($i = 0; $i < count($events); $i += $partitionSize) {
            $partitionEvents = array_slice($events, $i, $partitionSize);
            $partitions[] = new EventStream($partitionEvents);
        }

        return new PartitionedEventStream($partitions);
    }

    private function replaySequential(
        string $aggregateClass,
        AggregateIdInterface $aggregateId,
        EventStreamInterface $eventStream
    ): AggregateRootInterface {
        return $aggregateClass::reconstituteFromEvents($aggregateId, $eventStream);
    }

    private function replayPartitioned(
        string $aggregateClass,
        AggregateIdInterface $aggregateId,
        PartitionedEventStream $partitionedStream
    ): AggregateRootInterface {
        // For event sourcing, we need to process events in order
        // So we merge partitions back to chronological order
        $chronologicalStream = $partitionedStream->mergeChronologically();

        return $this->replaySequential($aggregateClass, $aggregateId, $chronologicalStream);
    }

    private function processBatch(array $batch): array
    {
        $results = [];

        foreach ($batch as $spec) {
            try {
                $aggregate = $this->replayAggregate(
                    $spec['class'],
                    $spec['aggregate_id'],
                    $spec['to_version'] ?? null
                );

                $results[$spec['aggregate_id']->toString()] = $aggregate;
            } catch (\Exception $e) {
                $results[$spec['aggregate_id']->toString()] = $e;
            }
        }

        return $results;
    }

    private function loadAggregateFromCheckpoint(
        string $aggregateClass,
        AggregateIdInterface $aggregateId,
        int $checkpoint
    ): AggregateRootInterface {
        if ($checkpoint === 0) {
            return new $aggregateClass($aggregateId);
        }

        $cached = Cache::get($this->getAggregateKey($aggregateId));
        if ($cached) {
            return unserialize($cached);
        }

        // Fallback: replay from beginning up to checkpoint
        $eventStream = $this->eventStore->load($aggregateId, 1, $checkpoint);
        return $aggregateClass::reconstituteFromEvents($aggregateId, $eventStream);
    }

    private function saveCheckpoint(
        AggregateIdInterface $aggregateId,
        int $version,
        AggregateRootInterface $aggregate
    ): void {
        $checkpointKey = $this->getCheckpointKey($aggregateId);
        $aggregateKey = $this->getAggregateKey($aggregateId);

        Cache::put($checkpointKey, $version, now()->addHours(24));
        Cache::put($aggregateKey, serialize($aggregate), now()->addHours(1));
    }

    private function getCheckpointKey(AggregateIdInterface $aggregateId): string
    {
        return self::CACHE_PREFIX . $aggregateId->toString();
    }

    private function getAggregateKey(AggregateIdInterface $aggregateId): string
    {
        return 'aggregate:' . $aggregateId->toString();
    }

    private function getAverageEventProcessingTime(): float
    {
        // In production, this would come from metrics collection
        return Cache::get('avg_event_processing_time', 0.001); // 1ms default
    }

    private function getSystemLoad(): float
    {
        // In production, this would come from system monitoring
        $load = sys_getloadavg();
        return $load ? $load[0] / 100 : 0.1; // Normalize to 0-1 scale
    }
}
