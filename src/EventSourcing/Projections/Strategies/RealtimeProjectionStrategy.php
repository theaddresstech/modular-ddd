<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Projections\Strategies;

use LaravelModularDDD\EventSourcing\Projections\ProjectionStrategyInterface;
use LaravelModularDDD\EventSourcing\Projections\ProjectionManager;
use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;
use Illuminate\Support\Facades\Log;

class RealtimeProjectionStrategy implements ProjectionStrategyInterface
{
    public function __construct(
        private readonly ProjectionManager $projectionManager,
        private readonly array $eventPatterns = ['*'] // Which events to handle
    ) {}

    public function handle(DomainEventInterface $event): void
    {
        if (!$this->shouldHandle($event)) {
            return;
        }

        try {
            // Process projection updates immediately
            $this->projectionManager->processEvent($event);

            Log::debug('Realtime projection updated', [
                'event_type' => $event->getEventType(),
                'aggregate_id' => $event->getAggregateId(),
                'strategy' => $this->getName(),
            ]);

        } catch (\Throwable $e) {
            Log::error('Realtime projection update failed', [
                'event_type' => $event->getEventType(),
                'aggregate_id' => $event->getAggregateId(),
                'error' => $e->getMessage(),
                'strategy' => $this->getName(),
            ]);

            throw $e;
        }
    }

    public function rebuild(string $projectionName): void
    {
        try {
            $this->projectionManager->rebuildProjection($projectionName);

            Log::info('Projection rebuilt via realtime strategy', [
                'projection' => $projectionName,
                'strategy' => $this->getName(),
            ]);

        } catch (\Throwable $e) {
            Log::error('Projection rebuild failed', [
                'projection' => $projectionName,
                'error' => $e->getMessage(),
                'strategy' => $this->getName(),
            ]);

            throw $e;
        }
    }

    public function getDelay(): int
    {
        return 0; // No delay for realtime
    }

    public function shouldHandle(DomainEventInterface $event): bool
    {
        $eventType = $event->getEventType();

        foreach ($this->eventPatterns as $pattern) {
            if ($pattern === '*' || fnmatch($pattern, $eventType)) {
                return true;
            }
        }

        return false;
    }

    public function getName(): string
    {
        return 'realtime';
    }

    public function getPriority(): int
    {
        return 100; // High priority for realtime updates
    }
}