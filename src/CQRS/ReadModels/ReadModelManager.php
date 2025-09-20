<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\ReadModels;

use LaravelModularDDD\CQRS\ReadModels\Persistence\ReadModelRepositoryInterface;
use LaravelModularDDD\Core\Events\DomainEventInterface;
use LaravelModularDDD\EventSourcing\EventStore\EventStoreInterface;
use Illuminate\Support\Facades\Log;

class ReadModelManager
{
    private array $generators = [];

    public function __construct(
        private readonly ReadModelRepositoryInterface $repository,
        private readonly EventStoreInterface $eventStore
    ) {}

    /**
     * Register a read model generator
     */
    public function registerGenerator(ReadModelGeneratorInterface $generator): void
    {
        $this->generators[] = $generator;

        // Sort by priority (highest first)
        usort($this->generators, fn($a, $b) => $b->getPriority() <=> $a->getPriority());
    }

    /**
     * Generate read models for an aggregate
     */
    public function generateForAggregate(string $aggregateId, ?int $fromVersion = null): array
    {
        $events = $this->eventStore->getEventsForAggregate($aggregateId, $fromVersion);
        $readModels = [];

        foreach ($this->generators as $generator) {
            $supportedEvents = array_filter($events, fn($event) => $generator->supports($event));

            if (empty($supportedEvents)) {
                continue;
            }

            try {
                $readModel = $this->generateReadModel($generator, $aggregateId, $supportedEvents);
                $readModels[] = $readModel;

                Log::debug('Read model generated', [
                    'aggregate_id' => $aggregateId,
                    'read_model_type' => $generator->getReadModelType(),
                    'events_processed' => count($supportedEvents),
                    'version' => $readModel->getVersion(),
                ]);

            } catch (\Throwable $e) {
                Log::error('Failed to generate read model', [
                    'aggregate_id' => $aggregateId,
                    'generator' => get_class($generator),
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }

        return $readModels;
    }

    /**
     * Update read models with new events
     */
    public function updateWithEvents(array $events): void
    {
        $eventsByAggregate = $this->groupEventsByAggregate($events);

        foreach ($eventsByAggregate as $aggregateId => $aggregateEvents) {
            $this->updateReadModelsForAggregate($aggregateId, $aggregateEvents);
        }
    }

    /**
     * Rebuild all read models for an aggregate from scratch
     */
    public function rebuildForAggregate(string $aggregateId): array
    {
        // Delete existing read models
        $this->repository->deleteByAggregateId($aggregateId);

        // Generate fresh read models
        return $this->generateForAggregate($aggregateId);
    }

    /**
     * Get read model by type and aggregate ID
     */
    public function getReadModel(string $type, string $aggregateId): ?ReadModel
    {
        return $this->repository->findByTypeAndAggregateId($type, $aggregateId);
    }

    /**
     * Get all read models for an aggregate
     */
    public function getReadModelsForAggregate(string $aggregateId): array
    {
        return $this->repository->findByAggregateId($aggregateId);
    }

    /**
     * Validate read model integrity
     */
    public function validateReadModel(ReadModel $readModel): bool
    {
        $generator = $this->findGeneratorForType($readModel->getType());

        if (!$generator) {
            Log::warning('No generator found for read model type', [
                'type' => $readModel->getType(),
                'read_model_id' => $readModel->getId(),
            ]);
            return false;
        }

        return $generator->validate($readModel);
    }

    /**
     * Get read model statistics
     */
    public function getStatistics(): array
    {
        $stats = $this->repository->getStatistics();

        return [
            'total_read_models' => $stats['total'] ?? 0,
            'by_type' => $stats['by_type'] ?? [],
            'average_size' => $stats['average_size'] ?? 0,
            'oldest_updated' => $stats['oldest_updated'] ?? null,
            'newest_updated' => $stats['newest_updated'] ?? null,
            'registered_generators' => count($this->generators),
            'generator_types' => array_map(
                fn($g) => $g->getReadModelType(),
                $this->generators
            ),
        ];
    }

    /**
     * Clean up old read models
     */
    public function cleanup(int $daysOld = 90): int
    {
        return $this->repository->deleteOlderThan($daysOld);
    }

    private function generateReadModel(
        ReadModelGeneratorInterface $generator,
        string $aggregateId,
        array $events
    ): ReadModel {
        // Check if read model already exists
        $existingReadModel = $this->repository->findByTypeAndAggregateId(
            $generator->getReadModelType(),
            $aggregateId
        );

        if ($existingReadModel) {
            $readModel = $generator->update($existingReadModel, $events);
        } else {
            $readModel = $generator->generate($aggregateId, $events);
        }

        // Validate before saving
        if (!$generator->validate($readModel)) {
            throw new \RuntimeException(
                "Generated read model failed validation for type: {$generator->getReadModelType()}"
            );
        }

        // Save to repository
        $this->repository->save($readModel);

        return $readModel;
    }

    private function updateReadModelsForAggregate(string $aggregateId, array $events): void
    {
        foreach ($this->generators as $generator) {
            $supportedEvents = array_filter($events, fn($event) => $generator->supports($event));

            if (empty($supportedEvents)) {
                continue;
            }

            try {
                $this->generateReadModel($generator, $aggregateId, $supportedEvents);
            } catch (\Throwable $e) {
                Log::error('Failed to update read model', [
                    'aggregate_id' => $aggregateId,
                    'generator' => get_class($generator),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function groupEventsByAggregate(array $events): array
    {
        $grouped = [];

        foreach ($events as $event) {
            if (!$event instanceof DomainEventInterface) {
                continue;
            }

            $aggregateId = $event->getAggregateId();
            $grouped[$aggregateId][] = $event;
        }

        return $grouped;
    }

    private function findGeneratorForType(string $type): ?ReadModelGeneratorInterface
    {
        foreach ($this->generators as $generator) {
            if ($generator->getReadModelType() === $type) {
                return $generator;
            }
        }

        return null;
    }
}