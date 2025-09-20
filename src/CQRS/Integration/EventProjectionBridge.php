<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Integration;

use LaravelModularDDD\CQRS\ReadModels\ReadModelManager;
use LaravelModularDDD\EventSourcing\Projections\ProjectionInterface;
use LaravelModularDDD\Core\Events\DomainEventInterface;
use Illuminate\Support\Facades\Log;

class EventProjectionBridge
{
    public function __construct(
        private readonly ReadModelManager $readModelManager
    ) {}

    /**
     * Bridge projection updates to read model generation
     */
    public function bridgeProjectionUpdate(ProjectionInterface $projection, DomainEventInterface $event): void
    {
        try {
            // Extract aggregate ID from event or projection
            $aggregateId = $event->getAggregateId();

            // Trigger read model updates for this aggregate
            $this->readModelManager->updateWithEvents([$event]);

            Log::debug('Bridged projection update to read models', [
                'projection_name' => $projection->getName(),
                'event_type' => get_class($event),
                'aggregate_id' => $aggregateId,
            ]);

        } catch (\Throwable $e) {
            Log::error('Failed to bridge projection update to read models', [
                'projection_name' => $projection->getName(),
                'event_type' => get_class($event),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Synchronize projection state with read models
     */
    public function synchronizeProjectionWithReadModels(ProjectionInterface $projection): array
    {
        $results = [];

        try {
            // Get projection data
            $projectionData = $projection->getData();

            // Find related aggregates (this is projection-specific logic)
            $relatedAggregates = $this->extractAggregateIds($projectionData);

            foreach ($relatedAggregates as $aggregateId) {
                // Regenerate read models for this aggregate
                $readModels = $this->readModelManager->rebuildForAggregate($aggregateId);
                $results[$aggregateId] = count($readModels);
            }

            Log::info('Synchronized projection with read models', [
                'projection_name' => $projection->getName(),
                'aggregates_processed' => count($relatedAggregates),
                'read_models_generated' => array_sum($results),
            ]);

        } catch (\Throwable $e) {
            Log::error('Failed to synchronize projection with read models', [
                'projection_name' => $projection->getName(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $results;
    }

    /**
     * Validate consistency between projections and read models
     */
    public function validateConsistency(ProjectionInterface $projection): array
    {
        $issues = [];

        try {
            $projectionData = $projection->getData();
            $relatedAggregates = $this->extractAggregateIds($projectionData);

            foreach ($relatedAggregates as $aggregateId) {
                $readModels = $this->readModelManager->getReadModelsForAggregate($aggregateId);

                // Check if read models exist for aggregates in projection
                if (empty($readModels)) {
                    $issues[] = "No read models found for aggregate {$aggregateId} referenced in projection {$projection->getName()}";
                    continue;
                }

                // Check version consistency
                $projectionVersion = $projection->getVersion();
                foreach ($readModels as $readModel) {
                    if ($readModel->getVersion() !== $projectionVersion) {
                        $issues[] = "Version mismatch: projection {$projection->getName()} v{$projectionVersion} vs read model {$readModel->getType()} v{$readModel->getVersion()}";
                    }
                }
            }

        } catch (\Throwable $e) {
            $issues[] = "Validation failed: {$e->getMessage()}";
        }

        return [
            'issues' => $issues,
            'is_consistent' => empty($issues),
            'projection_name' => $projection->getName(),
            'aggregates_checked' => count($relatedAggregates ?? []),
        ];
    }

    /**
     * Get bridge statistics
     */
    public function getStatistics(): array
    {
        return [
            'bridge_operations_today' => $this->getBridgeOperationsToday(),
            'consistency_checks_today' => $this->getConsistencyChecksToday(),
            'synchronization_operations_today' => $this->getSynchronizationOperationsToday(),
        ];
    }

    private function extractAggregateIds(array $projectionData): array
    {
        $aggregateIds = [];

        // Extract aggregate IDs from projection data
        // This is a simplified implementation - real implementation would be projection-specific
        foreach ($projectionData as $key => $value) {
            if (str_contains($key, 'aggregate_id') && is_string($value)) {
                $aggregateIds[] = $value;
            } elseif (is_array($value)) {
                // Recursively search in nested arrays
                $nestedIds = $this->extractAggregateIds($value);
                $aggregateIds = array_merge($aggregateIds, $nestedIds);
            }
        }

        return array_unique($aggregateIds);
    }

    private function getBridgeOperationsToday(): int
    {
        // This would typically come from metrics/logging
        return 0;
    }

    private function getConsistencyChecksToday(): int
    {
        // This would typically come from metrics/logging
        return 0;
    }

    private function getSynchronizationOperationsToday(): int
    {
        // This would typically come from metrics/logging
        return 0;
    }
}