<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Snapshot;

use LaravelModularDDD\Core\Domain\Contracts\AggregateRootInterface;
use LaravelModularDDD\EventSourcing\Contracts\AggregateSnapshotInterface;

class TimeBasedSnapshotStrategy implements SnapshotStrategyInterface
{
    private int $timeInterval;

    public function __construct(int $timeInterval = 3600)
    {
        if ($timeInterval < 60) {
            throw new \InvalidArgumentException('Time interval must be at least 60 seconds');
        }

        $this->timeInterval = $timeInterval;
    }

    public function shouldSnapshot(
        AggregateRootInterface $aggregate,
        ?AggregateSnapshotInterface $lastSnapshot = null
    ): bool {
        if (!$lastSnapshot) {
            // No previous snapshot - always create first one
            return true;
        }

        $timeSinceSnapshot = time() - $lastSnapshot->getCreatedAt()->getTimestamp();
        return $timeSinceSnapshot >= $this->timeInterval;
    }

    public function getName(): string
    {
        return 'time_based';
    }

    public function getConfiguration(): array
    {
        return [
            'time_interval' => $this->timeInterval,
        ];
    }

    /**
     * Get the time interval for testing/debugging
     */
    public function getTimeInterval(): int
    {
        return $this->timeInterval;
    }
}