<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\EventStream;

use LaravelModularDDD\EventSourcing\Contracts\EventStreamInterface;
use LaravelModularDDD\EventSourcing\EventStream;
use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;
use ArrayIterator;
use Traversable;

class PartitionedEventStream implements EventStreamInterface
{
    private array $partitions = [];
    private int $totalCount = 0;

    /**
     * @param array $partitions Array of EventStreamInterface partitions
     */
    public function __construct(array $partitions = [])
    {
        foreach ($partitions as $partition) {
            $this->addPartition($partition);
        }
    }

    public function addPartition(EventStreamInterface $partition): void
    {
        $this->partitions[] = $partition;
        $this->totalCount += $partition->count();
    }

    public function getEvents(): array
    {
        $allEvents = [];

        foreach ($this->partitions as $partition) {
            $allEvents = array_merge($allEvents, $partition->getEvents());
        }

        // Sort by occurred_at timestamp to maintain chronological order
        usort($allEvents, function (DomainEventInterface $a, DomainEventInterface $b) {
            return $a->getOccurredAt() <=> $b->getOccurredAt();
        });

        return $allEvents;
    }

    public function getPartitions(): array
    {
        return $this->partitions;
    }

    public function getPartitionCount(): int
    {
        return count($this->partitions);
    }

    public function isEmpty(): bool
    {
        return $this->totalCount === 0;
    }

    public function first(): ?DomainEventInterface
    {
        if (empty($this->partitions)) {
            return null;
        }

        $firstEvents = [];
        foreach ($this->partitions as $partition) {
            if (!$partition->isEmpty()) {
                $firstEvents[] = $partition->first();
            }
        }

        if (empty($firstEvents)) {
            return null;
        }

        // Return the earliest event across all partitions
        usort($firstEvents, function (DomainEventInterface $a, DomainEventInterface $b) {
            return $a->getOccurredAt() <=> $b->getOccurredAt();
        });

        return $firstEvents[0];
    }

    public function last(): ?DomainEventInterface
    {
        if (empty($this->partitions)) {
            return null;
        }

        $lastEvents = [];
        foreach ($this->partitions as $partition) {
            if (!$partition->isEmpty()) {
                $lastEvents[] = $partition->last();
            }
        }

        if (empty($lastEvents)) {
            return null;
        }

        // Return the latest event across all partitions
        usort($lastEvents, function (DomainEventInterface $a, DomainEventInterface $b) {
            return $b->getOccurredAt() <=> $a->getOccurredAt();
        });

        return $lastEvents[0];
    }

    public function filterByType(string $eventType): EventStreamInterface
    {
        $filteredPartitions = [];

        foreach ($this->partitions as $partition) {
            $filtered = $partition->filterByType($eventType);
            if (!$filtered->isEmpty()) {
                $filteredPartitions[] = $filtered;
            }
        }

        return new self($filteredPartitions);
    }

    public function limit(int $limit): EventStreamInterface
    {
        $events = $this->getEvents();
        return new EventStream(array_slice($events, 0, $limit));
    }

    public function skip(int $offset): EventStreamInterface
    {
        $events = $this->getEvents();
        return new EventStream(array_slice($events, $offset));
    }

    public function reverse(): EventStreamInterface
    {
        $events = array_reverse($this->getEvents());
        return new EventStream($events);
    }

    public function count(): int
    {
        return $this->totalCount;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->getEvents());
    }

    /**
     * Process partitions in parallel using multiple processes/threads
     */
    public function processParallel(callable $processor, int $maxConcurrency = 4): array
    {
        $partitionCount = count($this->partitions);

        if ($partitionCount === 0) {
            return [];
        }

        // If we have fewer partitions than max concurrency, process sequentially
        if ($partitionCount <= $maxConcurrency) {
            $results = [];
            foreach ($this->partitions as $index => $partition) {
                $results[$index] = $processor($partition);
            }
            return $results;
        }

        // For PHP environments without threading, use sequential processing
        // In production, this could be replaced with amphp/parallel or ReactPHP
        return $this->processSequential($processor);
    }

    /**
     * Process partitions sequentially (fallback for environments without threading)
     */
    private function processSequential(callable $processor): array
    {
        $results = [];

        foreach ($this->partitions as $index => $partition) {
            $results[$index] = $processor($partition);
        }

        return $results;
    }

    /**
     * Merge events from multiple partitions in chronological order
     */
    public function mergeChronologically(): EventStreamInterface
    {
        return new EventStream($this->getEvents());
    }

    /**
     * Split into smaller partitions based on time windows
     */
    public function repartitionByTime(int $windowSizeSeconds): PartitionedEventStream
    {
        $events = $this->getEvents();
        $partitions = [];
        $currentPartition = [];
        $currentWindowStart = null;

        foreach ($events as $event) {
            $eventTime = $event->getOccurredAt()->getTimestamp();

            if ($currentWindowStart === null) {
                $currentWindowStart = $eventTime;
            }

            // If event is outside current window, start new partition
            if ($eventTime >= ($currentWindowStart + $windowSizeSeconds)) {
                if (!empty($currentPartition)) {
                    $partitions[] = new EventStream($currentPartition);
                }
                $currentPartition = [$event];
                $currentWindowStart = $eventTime;
            } else {
                $currentPartition[] = $event;
            }
        }

        // Add final partition
        if (!empty($currentPartition)) {
            $partitions[] = new EventStream($currentPartition);
        }

        return new self($partitions);
    }

    /**
     * Get partition statistics
     */
    public function getPartitionStats(): array
    {
        $stats = [
            'total_partitions' => count($this->partitions),
            'total_events' => $this->totalCount,
            'partition_sizes' => [],
            'avg_partition_size' => 0,
            'min_partition_size' => 0,
            'max_partition_size' => 0,
        ];

        if (empty($this->partitions)) {
            return $stats;
        }

        $sizes = [];
        foreach ($this->partitions as $partition) {
            $size = $partition->count();
            $sizes[] = $size;
            $stats['partition_sizes'][] = $size;
        }

        $stats['avg_partition_size'] = array_sum($sizes) / count($sizes);
        $stats['min_partition_size'] = min($sizes);
        $stats['max_partition_size'] = max($sizes);

        return $stats;
    }
}