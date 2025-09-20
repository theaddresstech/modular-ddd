<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Integration;

use LaravelModularDDD\CQRS\Contracts\CommandBusInterface;
use LaravelModularDDD\CQRS\Contracts\QueryBusInterface;
use LaravelModularDDD\CQRS\ReadModels\ReadModelManager;
use LaravelModularDDD\CQRS\Saga\SagaManager;
use LaravelModularDDD\EventSourcing\EventStore\EventStoreInterface;
use LaravelModularDDD\EventSourcing\Projections\ProjectionManager;
use LaravelModularDDD\Core\Events\DomainEventInterface;
use Illuminate\Support\Facades\Log;

class CQRSEventStoreIntegration
{
    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly ProjectionManager $projectionManager,
        private readonly CommandBusInterface $commandBus,
        private readonly QueryBusInterface $queryBus,
        private readonly ReadModelManager $readModelManager,
        private readonly SagaManager $sagaManager
    ) {}

    /**
     * Process domain events through CQRS components
     */
    public function processEvents(array $events): void
    {
        foreach ($events as $event) {
            if (!$event instanceof DomainEventInterface) {
                continue;
            }

            try {
                // Update projections
                $this->projectionManager->project($event);

                // Update read models
                $this->readModelManager->updateWithEvents([$event]);

                // Handle sagas
                $this->sagaManager->handleEvent($event);

                Log::debug('Event processed through CQRS integration', [
                    'event_type' => get_class($event),
                    'aggregate_id' => $event->getAggregateId(),
                    'event_id' => $event->getEventId(),
                ]);

            } catch (\Throwable $e) {
                Log::error('Failed to process event through CQRS integration', [
                    'event_type' => get_class($event),
                    'aggregate_id' => $event->getAggregateId(),
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }
    }

    /**
     * Rebuild projections and read models for an aggregate
     */
    public function rebuildForAggregate(string $aggregateId): array
    {
        // Get all events for the aggregate
        $events = $this->eventStore->getEventsForAggregate($aggregateId);

        // Rebuild projections
        $this->projectionManager->rebuildForAggregate($aggregateId, $events);

        // Rebuild read models
        $readModels = $this->readModelManager->rebuildForAggregate($aggregateId);

        Log::info('Rebuilt CQRS components for aggregate', [
            'aggregate_id' => $aggregateId,
            'events_processed' => count($events),
            'read_models_generated' => count($readModels),
        ]);

        return [
            'events_processed' => count($events),
            'read_models_generated' => count($readModels),
            'projections_updated' => $this->projectionManager->getActiveProjectionCount(),
        ];
    }

    /**
     * Get comprehensive view of aggregate data
     */
    public function getAggregateView(string $aggregateId): array
    {
        // Get events
        $events = $this->eventStore->getEventsForAggregate($aggregateId);

        // Get projections
        $projections = $this->projectionManager->getProjectionsForAggregate($aggregateId);

        // Get read models
        $readModels = $this->readModelManager->getReadModelsForAggregate($aggregateId);

        // Get active sagas
        $activeSagas = array_filter(
            $this->sagaManager->getActiveSagas(),
            fn($saga) => str_contains($saga->getMetadata()['aggregate_id'] ?? '', $aggregateId)
        );

        return [
            'aggregate_id' => $aggregateId,
            'events' => [
                'count' => count($events),
                'latest' => $events ? end($events)->toArray() : null,
            ],
            'projections' => array_map(fn($p) => $p->toArray(), $projections),
            'read_models' => array_map(fn($rm) => $rm->toArray(), $readModels),
            'active_sagas' => array_map(fn($s) => [
                'saga_id' => $s->getSagaId(),
                'type' => $s->getSagaType(),
                'state' => $s->getState()->value,
            ], $activeSagas),
        ];
    }

    /**
     * Validate consistency across CQRS components
     */
    public function validateConsistency(string $aggregateId): array
    {
        $issues = [];

        try {
            // Check if events exist
            $events = $this->eventStore->getEventsForAggregate($aggregateId);
            if (empty($events)) {
                $issues[] = 'No events found for aggregate';
                return ['issues' => $issues];
            }

            // Validate projections
            $projections = $this->projectionManager->getProjectionsForAggregate($aggregateId);
            foreach ($projections as $projection) {
                if ($projection->getVersion() < count($events)) {
                    $issues[] = "Projection {$projection->getName()} is behind (v{$projection->getVersion()} vs {count($events)} events)";
                }
            }

            // Validate read models
            $readModels = $this->readModelManager->getReadModelsForAggregate($aggregateId);
            foreach ($readModels as $readModel) {
                if (!$this->readModelManager->validateReadModel($readModel)) {
                    $issues[] = "Read model {$readModel->getType()} failed validation";
                }

                if ($readModel->getVersion() < count($events)) {
                    $issues[] = "Read model {$readModel->getType()} is behind (v{$readModel->getVersion()} vs {count($events)} events)";
                }
            }

            return [
                'issues' => $issues,
                'is_consistent' => empty($issues),
                'events_count' => count($events),
                'projections_count' => count($projections),
                'read_models_count' => count($readModels),
            ];

        } catch (\Throwable $e) {
            $issues[] = "Validation failed: {$e->getMessage()}";
            return ['issues' => $issues, 'is_consistent' => false];
        }
    }

    /**
     * Get integration statistics
     */
    public function getStatistics(): array
    {
        return [
            'event_store' => $this->eventStore->getStatistics(),
            'projections' => $this->projectionManager->getStatistics(),
            'read_models' => $this->readModelManager->getStatistics(),
            'sagas' => $this->sagaManager->getStatistics(),
            'integration' => [
                'processed_events_today' => $this->getProcessedEventsToday(),
                'active_components' => $this->getActiveComponentsCount(),
            ],
        ];
    }

    /**
     * Perform maintenance operations
     */
    public function performMaintenance(): array
    {
        $results = [];

        // Clean up old projections
        $results['projections_cleaned'] = $this->projectionManager->cleanupOldCheckpoints();

        // Clean up old read models
        $results['read_models_cleaned'] = $this->readModelManager->cleanup();

        // Handle saga timeouts
        $this->sagaManager->handleTimeouts();

        Log::info('CQRS integration maintenance completed', $results);

        return $results;
    }

    private function getProcessedEventsToday(): int
    {
        // This would typically come from metrics/logging
        // Simplified implementation
        return 0;
    }

    private function getActiveComponentsCount(): array
    {
        return [
            'active_projections' => $this->projectionManager->getActiveProjectionCount(),
            'active_sagas' => count($this->sagaManager->getActiveSagas()),
        ];
    }
}