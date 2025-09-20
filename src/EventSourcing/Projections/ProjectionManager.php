<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Projections;

use LaravelModularDDD\EventSourcing\Contracts\EventStoreInterface;
use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ProjectionManager
{
    /** @var ProjectorInterface[] */
    private array $projectors = [];

    private const BATCH_SIZE = 100;
    private const LOCK_TIMEOUT = 300; // 5 minutes

    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly bool $asyncProcessing = false
    ) {}

    /**
     * Register a projector
     */
    public function register(ProjectorInterface $projector): void
    {
        $this->projectors[$projector->getName()] = $projector;
    }

    /**
     * Unregister a projector
     */
    public function unregister(string $name): void
    {
        unset($this->projectors[$name]);
    }

    /**
     * Get all registered projectors
     */
    public function getProjectors(): array
    {
        return $this->projectors;
    }

    /**
     * Get a specific projector by name
     */
    public function getProjector(string $name): ?ProjectorInterface
    {
        return $this->projectors[$name] ?? null;
    }

    /**
     * Process new events through all projectors
     */
    public function processNewEvents(): void
    {
        foreach ($this->projectors as $projector) {
            if (!$projector->isEnabled()) {
                continue;
            }

            $this->processProjector($projector);
        }
    }

    /**
     * Process events for a specific projector
     */
    public function processProjector(ProjectorInterface $projector): void
    {
        // Try to acquire lock
        if (!$projector->lock(self::LOCK_TIMEOUT)) {
            logger()->info("Projector {$projector->getName()} is already locked, skipping");
            return;
        }

        try {
            $this->processProjectorEvents($projector);
        } finally {
            $projector->unlock();
        }
    }

    /**
     * Process a single event through all applicable projectors
     */
    public function processEvent(DomainEventInterface $event): void
    {
        foreach ($this->projectors as $projector) {
            if ($projector->isEnabled() && $projector->canHandle($event)) {
                try {
                    $projector->handle($event);
                } catch (\Exception $e) {
                    logger()->error("Error processing event in projector {$projector->getName()}", [
                        'event_type' => get_class($event),
                        'event_id' => $event->getEventId(),
                        'error' => $e->getMessage(),
                    ]);
                    // Continue with other projectors
                }
            }
        }
    }

    /**
     * Replay all events for a specific projector
     */
    public function replayProjector(string $projectorName, ?int $fromSequence = null): void
    {
        $projector = $this->getProjector($projectorName);

        if (!$projector) {
            throw new \InvalidArgumentException("Projector '{$projectorName}' not found");
        }

        // Lock projector
        if (!$projector->lock(self::LOCK_TIMEOUT)) {
            throw new \RuntimeException("Could not acquire lock for projector '{$projectorName}'");
        }

        try {
            // Reset if starting from beginning
            if ($fromSequence === null) {
                $projector->reset();
                $fromSequence = 1;
            }

            $this->replayEventsForProjector($projector, $fromSequence);
        } finally {
            $projector->unlock();
        }
    }

    /**
     * Replay all events for all projectors
     */
    public function replayAll(?int $fromSequence = null): void
    {
        foreach ($this->projectors as $projector) {
            if ($projector->isEnabled()) {
                $this->replayProjector($projector->getName(), $fromSequence);
            }
        }
    }

    /**
     * Get projection statistics
     */
    public function getStatistics(): array
    {
        $stats = [
            'total_projectors' => count($this->projectors),
            'enabled_projectors' => 0,
            'disabled_projectors' => 0,
            'locked_projectors' => 0,
            'projectors' => [],
        ];

        foreach ($this->projectors as $projector) {
            $projectorStats = $projector->getStatistics();
            $stats['projectors'][$projector->getName()] = $projectorStats;

            if ($projector->isEnabled()) {
                $stats['enabled_projectors']++;
            } else {
                $stats['disabled_projectors']++;
            }

            if ($projector->isLocked()) {
                $stats['locked_projectors']++;
            }
        }

        return $stats;
    }

    /**
     * Reset all projectors
     */
    public function resetAll(): void
    {
        foreach ($this->projectors as $projector) {
            if ($projector->lock(self::LOCK_TIMEOUT)) {
                try {
                    $projector->reset();
                } finally {
                    $projector->unlock();
                }
            }
        }
    }

    /**
     * Enable/disable a projector
     */
    public function setProjectorEnabled(string $name, bool $enabled): void
    {
        $projector = $this->getProjector($name);

        if (!$projector) {
            throw new \InvalidArgumentException("Projector '{$name}' not found");
        }

        $projector->setEnabled($enabled);
    }

    /**
     * Get projectors that can handle a specific event type
     */
    public function getProjectorsForEvent(string $eventType): array
    {
        return array_filter(
            $this->projectors,
            fn(ProjectorInterface $projector) => in_array($eventType, $projector->getHandledEvents(), true)
        );
    }

    /**
     * Check the health of all projectors
     */
    public function healthCheck(): array
    {
        $health = [
            'status' => 'healthy',
            'issues' => [],
            'lag_summary' => [],
        ];

        foreach ($this->projectors as $projector) {
            $projectorHealth = $this->checkProjectorHealth($projector);

            if ($projectorHealth['status'] !== 'healthy') {
                $health['status'] = 'degraded';
                $health['issues'][] = $projectorHealth;
            }

            $health['lag_summary'][$projector->getName()] = $projectorHealth['lag_events'];
        }

        return $health;
    }

    private function processProjectorEvents(ProjectorInterface $projector): void
    {
        $position = $projector->getPosition();
        $processed = 0;

        do {
            $events = $this->eventStore->loadEventsFromSequence($position + 1, self::BATCH_SIZE);

            if (empty($events)) {
                break;
            }

            foreach ($events as $eventData) {
                $event = $eventData['event'];
                $sequence = $eventData['sequence_number'];

                if ($projector->canHandle($event)) {
                    $projector->handle($event);
                }

                $projector->setPosition($sequence);
                $position = $sequence;
                $processed++;
            }

        } while (count($events) === self::BATCH_SIZE);

        if ($processed > 0) {
            logger()->info("Processed {$processed} events for projector {$projector->getName()}");
        }
    }

    private function replayEventsForProjector(ProjectorInterface $projector, int $fromSequence): void
    {
        $position = $fromSequence;
        $processed = 0;

        do {
            $events = $this->eventStore->loadEventsFromSequence($position, self::BATCH_SIZE);

            if (empty($events)) {
                break;
            }

            foreach ($events as $eventData) {
                $event = $eventData['event'];
                $sequence = $eventData['sequence_number'];

                if ($projector->canHandle($event)) {
                    $projector->handle($event);
                }

                $projector->setPosition($sequence);
                $position = $sequence + 1;
                $processed++;
            }

        } while (count($events) === self::BATCH_SIZE);

        logger()->info("Replayed {$processed} events for projector {$projector->getName()}");
    }

    private function checkProjectorHealth(ProjectorInterface $projector): array
    {
        $position = $projector->getPosition();
        $stats = $projector->getStatistics();

        // Get latest sequence number from event store
        $latestEvents = $this->eventStore->loadEventsFromSequence(0, 1);
        $latestSequence = !empty($latestEvents) ? max(array_column($latestEvents, 'sequence_number')) : 0;

        $lag = $latestSequence - $position;
        $status = 'healthy';

        // Define health thresholds
        if ($lag > 10000) {
            $status = 'critical';
        } elseif ($lag > 1000) {
            $status = 'warning';
        }

        // Check for recent errors
        $metrics = $stats['performance_metrics'] ?? [];
        if (($metrics['errors_count'] ?? 0) > 0) {
            $lastError = $metrics['last_error'] ?? null;
            if ($lastError && time() - strtotime($lastError['occurred_at']) < 3600) {
                $status = 'degraded';
            }
        }

        return [
            'projector' => $projector->getName(),
            'status' => $status,
            'position' => $position,
            'latest_sequence' => $latestSequence,
            'lag_events' => $lag,
            'enabled' => $projector->isEnabled(),
            'locked' => $projector->isLocked(),
            'last_error' => $metrics['last_error'] ?? null,
        ];
    }
}