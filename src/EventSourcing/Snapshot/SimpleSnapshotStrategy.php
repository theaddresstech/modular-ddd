<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Snapshot;

use LaravelModularDDD\Core\Domain\Contracts\AggregateRootInterface;
use LaravelModularDDD\EventSourcing\Contracts\AggregateSnapshotInterface;

class SimpleSnapshotStrategy implements SnapshotStrategyInterface
{
    private int $eventThreshold;

    public function __construct(int $eventThreshold = 10)
    {
        if ($eventThreshold < 1) {
            throw new \InvalidArgumentException('Event threshold must be at least 1');
        }

        $this->eventThreshold = $eventThreshold;
    }

    public function shouldSnapshot(
        AggregateRootInterface $aggregate,
        ?AggregateSnapshotInterface $lastSnapshot = null
    ): bool {
        if (!$lastSnapshot) {
            // No previous snapshot - check if we've reached the threshold
            return $aggregate->getVersion() >= $this->eventThreshold;
        }

        // Check events since last snapshot
        $eventsSinceSnapshot = $aggregate->getVersion() - $lastSnapshot->getVersion();
        return $eventsSinceSnapshot >= $this->eventThreshold;
    }

    public function getName(): string
    {
        return 'simple';
    }

    public function getConfiguration(): array
    {
        return [
            'event_threshold' => $this->eventThreshold,
        ];
    }

    /**
     * Get the event threshold for testing/debugging
     */
    public function getEventThreshold(): int
    {
        return $this->eventThreshold;
    }

    /**
     * Check if aggregate has uncommitted events that warrant a snapshot
     */
    public function shouldSnapshotUncommittedEvents(AggregateRootInterface $aggregate): bool
    {
        if (!method_exists($aggregate, 'getUncommittedEventsCount')) {
            return false;
        }

        return $aggregate->getUncommittedEventsCount() >= $this->eventThreshold;
    }
}