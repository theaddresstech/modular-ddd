<?php

declare(strict_types=1);

namespace LaravelModularDDD\Core\Infrastructure\Repository;

use LaravelModularDDD\Core\Domain\Contracts\AggregateIdInterface;
use LaravelModularDDD\Core\Domain\Contracts\AggregateRootInterface;
use LaravelModularDDD\EventSourcing\Contracts\EventStoreInterface;
use LaravelModularDDD\EventSourcing\Contracts\SnapshotStoreInterface;
use LaravelModularDDD\EventSourcing\Snapshot\SnapshotStrategyInterface;
use LaravelModularDDD\EventSourcing\AggregateReconstructor;
use LaravelModularDDD\EventSourcing\Exceptions\AggregateNotFoundException;
use Illuminate\Support\Facades\Log;

/**
 * Event-sourced aggregate repository that enforces PRD snapshot requirement
 */
class EventSourcedAggregateRepository
{
    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly SnapshotStoreInterface $snapshotStore,
        private readonly SnapshotStrategyInterface $snapshotStrategy,
        private readonly ?AggregateReconstructor $reconstructor = null
    ) {}

    /**
     * Save aggregate and create snapshot if PRD requirement is met
     *
     * PRD Requirement: System must automatically create snapshots every 10 events
     */
    public function save(AggregateRootInterface $aggregate): void
    {
        $uncommittedEvents = $aggregate->getUncommittedEvents();

        if (empty($uncommittedEvents)) {
            return; // No events to save
        }

        $aggregateId = $aggregate->getAggregateId();
        $currentVersion = $aggregate->getVersion() - count($uncommittedEvents);

        // Save events to event store
        $this->eventStore->append(
            $aggregateId,
            $uncommittedEvents,
            $currentVersion
        );

        // Mark events as committed
        $aggregate->markEventsAsCommitted();

        // PRD COMPLIANCE: Check if snapshot should be created
        $this->enforceSnapshotRequirement($aggregate);

        Log::debug('Aggregate saved', [
            'aggregate_id' => $aggregateId->toString(),
            'aggregate_type' => get_class($aggregate),
            'events_count' => count($uncommittedEvents),
            'new_version' => $aggregate->getVersion(),
        ]);
    }

    /**
     * Load aggregate from snapshot and events
     */
    public function load(AggregateIdInterface $aggregateId, string $aggregateClass): AggregateRootInterface
    {
        if (!$this->exists($aggregateId)) {
            throw new AggregateNotFoundException($aggregateId);
        }

        $reconstructor = $this->reconstructor ?? new AggregateReconstructor();

        // Try to load from snapshot first
        $snapshot = $this->snapshotStore->load($aggregateId);

        if ($snapshot !== null) {
            // Reconstitute from snapshot
            $aggregate = $reconstructor->reconstituteFromSnapshot($snapshot, $aggregateClass);

            // Load and apply events since snapshot
            $eventStream = $this->eventStore->load(
                $aggregateId,
                $snapshot->getVersion() + 1
            );

            if (!$eventStream->isEmpty()) {
                $aggregate = $reconstructor->applyEvents($aggregate, $eventStream->getEvents());
            }

            Log::debug('Aggregate loaded from snapshot', [
                'aggregate_id' => $aggregateId->toString(),
                'snapshot_version' => $snapshot->getVersion(),
                'final_version' => $aggregate->getVersion(),
            ]);

            return $aggregate;
        }

        // No snapshot available, load from events only
        $eventStream = $this->eventStore->load($aggregateId);

        if ($eventStream->isEmpty()) {
            throw new AggregateNotFoundException($aggregateId);
        }

        $aggregate = $reconstructor->reconstitute(
            $aggregateId,
            $aggregateClass,
            $eventStream->getEvents()
        );

        Log::debug('Aggregate loaded from events', [
            'aggregate_id' => $aggregateId->toString(),
            'final_version' => $aggregate->getVersion(),
        ]);

        return $aggregate;
    }

    /**
     * Check if aggregate exists
     */
    public function exists(AggregateIdInterface $aggregateId): bool
    {
        return $this->eventStore->aggregateExists($aggregateId);
    }

    /**
     * Get current version of aggregate without loading all events
     */
    public function getVersion(AggregateIdInterface $aggregateId): int
    {
        return $this->eventStore->getAggregateVersion($aggregateId);
    }

    /**
     * Force create a snapshot (for manual snapshot creation)
     */
    public function createSnapshot(AggregateRootInterface $aggregate): void
    {
        $this->snapshotStore->save(
            $aggregate->getAggregateId(),
            $aggregate,
            $aggregate->getVersion()
        );

        Log::info('Manual snapshot created', [
            'aggregate_id' => $aggregate->getAggregateId()->toString(),
            'version' => $aggregate->getVersion(),
        ]);
    }

    /**
     * CORE PRD REQUIREMENT ENFORCEMENT
     *
     * This method ensures that snapshots are automatically created every 10 events
     * as required by the PRD. This is critical for system compliance.
     */
    private function enforceSnapshotRequirement(AggregateRootInterface $aggregate): void
    {
        // Load the most recent snapshot (if any)
        $lastSnapshot = $this->snapshotStore->load($aggregate->getAggregateId());

        // Check if snapshot should be created based on strategy
        if ($this->snapshotStrategy->shouldSnapshot($aggregate, $lastSnapshot)) {

            // Create snapshot to meet PRD requirement
            $this->snapshotStore->save(
                $aggregate->getAggregateId(),
                $aggregate,
                $aggregate->getVersion()
            );

            Log::info('PRD Compliance: Automatic snapshot created', [
                'aggregate_id' => $aggregate->getAggregateId()->toString(),
                'aggregate_type' => get_class($aggregate),
                'version' => $aggregate->getVersion(),
                'strategy' => $this->snapshotStrategy->getName(),
                'events_since_last_snapshot' => $lastSnapshot ?
                    $aggregate->getVersion() - $lastSnapshot->getVersion() :
                    $aggregate->getVersion(),
                'prd_requirement' => 'Every 10 events snapshot creation enforced'
            ]);
        }
    }

    /**
     * Get repository statistics
     */
    public function getStatistics(): array
    {
        $stats = $this->snapshotStore->getStatistics();

        return [
            'strategy' => $this->snapshotStrategy->getName(),
            'strategy_config' => $this->snapshotStrategy->getConfiguration(),
            'prd_compliant' => $this->snapshotStrategy->getName() === 'simple' &&
                              ($this->snapshotStrategy->getConfiguration()['event_threshold'] ?? 0) === 10,
            'snapshot_stats' => $stats,
        ];
    }

    /**
     * Validate PRD compliance
     *
     * Returns true if the repository is configured to meet PRD requirements
     */
    public function isPrdCompliant(): bool
    {
        if ($this->snapshotStrategy->getName() !== 'simple') {
            return false;
        }

        $config = $this->snapshotStrategy->getConfiguration();
        return ($config['event_threshold'] ?? 0) === 10;
    }

    /**
     * Get PRD compliance report
     */
    public function getPrdComplianceReport(): array
    {
        $isCompliant = $this->isPrdCompliant();
        $config = $this->snapshotStrategy->getConfiguration();

        return [
            'prd_compliant' => $isCompliant,
            'current_strategy' => $this->snapshotStrategy->getName(),
            'current_threshold' => $config['event_threshold'] ?? null,
            'required_strategy' => 'simple',
            'required_threshold' => 10,
            'prd_requirement' => 'System must automatically create snapshots every 10 events',
            'compliance_status' => $isCompliant ? 'COMPLIANT' : 'NON-COMPLIANT',
            'recommendation' => $isCompliant ?
                'Configuration meets PRD requirements' :
                'Switch to SimpleSnapshotStrategy with threshold=10 to meet PRD requirements'
        ];
    }
}