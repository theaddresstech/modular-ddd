<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Listeners;

use LaravelModularDDD\EventSourcing\Projections\ProjectionStrategyInterface;
use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;
use Illuminate\Support\Facades\Log;

class ProjectionEventBridge
{
    /** @var ProjectionStrategyInterface[] */
    private array $strategies = [];

    public function __construct()
    {
        // Strategies will be injected via registerStrategy() method
    }

    /**
     * Register a projection strategy
     */
    public function registerStrategy(ProjectionStrategyInterface $strategy): void
    {
        $this->strategies[] = $strategy;

        // Sort by priority (highest first)
        usort($this->strategies, function (ProjectionStrategyInterface $a, ProjectionStrategyInterface $b) {
            return $b->getPriority() <=> $a->getPriority();
        });

        Log::debug('Projection strategy registered', [
            'strategy' => $strategy->getName(),
            'priority' => $strategy->getPriority(),
            'total_strategies' => count($this->strategies),
        ]);
    }

    /**
     * Handle any domain event
     */
    public function handle($event): void
    {
        // Only process domain events
        if (!$event instanceof DomainEventInterface) {
            return;
        }

        if (empty($this->strategies)) {
            Log::warning('No projection strategies registered', [
                'event_type' => $event->getEventType(),
            ]);
            return;
        }

        Log::debug('Processing event through projection strategies', [
            'event_type' => $event->getEventType(),
            'aggregate_id' => $event->getAggregateId(),
            'strategies_count' => count($this->strategies),
        ]);

        foreach ($this->strategies as $strategy) {
            try {
                // Each strategy decides if it should handle this event
                if ($strategy->shouldHandle($event)) {
                    $delay = $strategy->getDelay();

                    if ($delay > 0) {
                        // Delay execution if strategy requires it
                        dispatch(function () use ($strategy, $event) {
                            $strategy->handle($event);
                        })->delay(now()->addMilliseconds($delay));
                    } else {
                        // Execute immediately
                        $strategy->handle($event);
                    }

                    Log::debug('Event processed by projection strategy', [
                        'event_type' => $event->getEventType(),
                        'strategy' => $strategy->getName(),
                        'delay' => $delay,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('Projection strategy failed to handle event', [
                    'event_type' => $event->getEventType(),
                    'aggregate_id' => $event->getAggregateId(),
                    'strategy' => $strategy->getName(),
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);

                // Continue with other strategies even if one fails
                // This ensures projection resilience
            }
        }
    }

    /**
     * Get registered strategies
     */
    public function getStrategies(): array
    {
        return $this->strategies;
    }

    /**
     * Get strategy by name
     */
    public function getStrategy(string $name): ?ProjectionStrategyInterface
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->getName() === $name) {
                return $strategy;
            }
        }

        return null;
    }

    /**
     * Remove a strategy by name
     */
    public function removeStrategy(string $name): bool
    {
        $originalCount = count($this->strategies);

        $this->strategies = array_filter(
            $this->strategies,
            fn(ProjectionStrategyInterface $strategy) => $strategy->getName() !== $name
        );

        $removed = count($this->strategies) < $originalCount;

        if ($removed) {
            Log::info('Projection strategy removed', [
                'strategy' => $name,
                'remaining_strategies' => count($this->strategies),
            ]);
        }

        return $removed;
    }

    /**
     * Get statistics about projection processing
     */
    public function getStatistics(): array
    {
        return [
            'strategies_registered' => count($this->strategies),
            'strategies' => array_map(function (ProjectionStrategyInterface $strategy) {
                return [
                    'name' => $strategy->getName(),
                    'priority' => $strategy->getPriority(),
                    'delay' => $strategy->getDelay(),
                ];
            }, $this->strategies),
        ];
    }
}