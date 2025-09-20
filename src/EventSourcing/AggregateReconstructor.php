<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing;

use LaravelModularDDD\Core\Domain\Contracts\AggregateIdInterface;
use LaravelModularDDD\Core\Domain\Contracts\AggregateRootInterface;
use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;
use LaravelModularDDD\EventSourcing\Contracts\AggregateSnapshotInterface;

/**
 * Reconstructs aggregates from events and snapshots
 */
class AggregateReconstructor
{
    /**
     * Reconstitute aggregate from events only
     *
     * @param AggregateIdInterface $aggregateId
     * @param string $aggregateClass
     * @param DomainEventInterface[] $events
     * @return AggregateRootInterface
     */
    public function reconstitute(
        AggregateIdInterface $aggregateId,
        string $aggregateClass,
        array $events
    ): AggregateRootInterface {
        $this->validateAggregateClass($aggregateClass);

        // Use the EventSourcedAggregateRoot's reconstitution method if available
        if (method_exists($aggregateClass, 'reconstituteFromEvents')) {
            return $aggregateClass::reconstituteFromEvents($aggregateId, $events);
        }

        // Fallback: create new instance and replay events
        $aggregate = new $aggregateClass($aggregateId);

        if (method_exists($aggregate, 'replay')) {
            $aggregate->replay($events);
        } else {
            // Manual event replay
            foreach ($events as $event) {
                $this->applyEventToAggregate($aggregate, $event);
            }
        }

        if (method_exists($aggregate, 'markEventsAsCommitted')) {
            $aggregate->markEventsAsCommitted();
        }

        return $aggregate;
    }

    /**
     * Reconstitute aggregate from snapshot
     */
    public function reconstituteFromSnapshot(
        AggregateSnapshotInterface $snapshot,
        string $aggregateClass
    ): AggregateRootInterface {
        $this->validateAggregateClass($aggregateClass);

        // Use snapshot's built-in method if available
        if (method_exists($snapshot, 'getAggregate')) {
            return $snapshot->getAggregate();
        }

        // Fallback: reconstruct from snapshot state
        $aggregateId = $snapshot->getAggregateId();
        $aggregate = new $aggregateClass($aggregateId);

        // Restore state from snapshot
        $this->restoreAggregateFromSnapshot($aggregate, $snapshot);

        return $aggregate;
    }

    /**
     * Apply additional events to an already reconstituted aggregate
     *
     * @param AggregateRootInterface $aggregate
     * @param DomainEventInterface[] $events
     * @return AggregateRootInterface
     */
    public function applyEvents(AggregateRootInterface $aggregate, array $events): AggregateRootInterface
    {
        if (method_exists($aggregate, 'replay')) {
            $aggregate->replay($events);
        } else {
            foreach ($events as $event) {
                $this->applyEventToAggregate($aggregate, $event);
            }
        }

        return $aggregate;
    }

    /**
     * Apply a single event to an aggregate
     */
    private function applyEventToAggregate(AggregateRootInterface $aggregate, DomainEventInterface $event): void
    {
        // Look for specific apply method based on event type
        $eventClass = get_class($event);
        $eventClassParts = explode('\\', $eventClass);
        $eventClassName = end($eventClassParts);

        $applyMethod = 'apply' . $eventClassName;

        if (method_exists($aggregate, $applyMethod)) {
            $aggregate->{$applyMethod}($event);
        } else {
            // Fallback to generic apply method
            if (method_exists($aggregate, 'apply')) {
                $aggregate->apply($event);
            }
        }

        // Increment version if the aggregate supports it
        if (method_exists($aggregate, 'incrementVersion')) {
            $aggregate->incrementVersion();
        } elseif (property_exists($aggregate, 'version')) {
            $reflection = new \ReflectionProperty($aggregate, 'version');
            $reflection->setAccessible(true);
            $currentVersion = $reflection->getValue($aggregate);
            $reflection->setValue($aggregate, $currentVersion + 1);
        }
    }

    /**
     * Restore aggregate state from snapshot
     */
    private function restoreAggregateFromSnapshot(
        AggregateRootInterface $aggregate,
        AggregateSnapshotInterface $snapshot
    ): void {
        $state = $snapshot->getState();
        $reflection = new \ReflectionClass($aggregate);

        // Restore properties from snapshot state
        foreach ($state as $property => $value) {
            try {
                if ($reflection->hasProperty($property)) {
                    $prop = $reflection->getProperty($property);
                    $prop->setAccessible(true);

                    // Handle special property restoration
                    $restoredValue = $this->restorePropertyValue($value);
                    $prop->setValue($aggregate, $restoredValue);
                }
            } catch (\ReflectionException $e) {
                // Property might not exist in current version, skip
                continue;
            }
        }

        // Set version from snapshot
        if ($reflection->hasProperty('version')) {
            $versionProp = $reflection->getProperty('version');
            $versionProp->setAccessible(true);
            $versionProp->setValue($aggregate, $snapshot->getVersion());
        }
    }

    /**
     * Restore property value from serialized state
     */
    private function restorePropertyValue($value)
    {
        if (is_string($value) && $this->isSerializedObject($value)) {
            try {
                return unserialize($value);
            } catch (\Exception $e) {
                // If unserialization fails, return as string
                return $value;
            }
        }

        // Handle DateTime strings
        if (is_string($value) && $this->isDateTimeString($value)) {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Exception $e) {
                return $value;
            }
        }

        return $value;
    }

    /**
     * Check if string is a serialized object
     */
    private function isSerializedObject(string $value): bool
    {
        return preg_match('/^[aOs]:[0-9]+:/', $value) === 1;
    }

    /**
     * Check if string is a DateTime string
     */
    private function isDateTimeString(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $value) === 1;
    }

    /**
     * Validate that the aggregate class exists and implements the required interface
     */
    private function validateAggregateClass(string $aggregateClass): void
    {
        if (!class_exists($aggregateClass)) {
            throw new \InvalidArgumentException("Aggregate class {$aggregateClass} does not exist");
        }

        if (!is_subclass_of($aggregateClass, AggregateRootInterface::class)) {
            throw new \InvalidArgumentException(
                "Class {$aggregateClass} must implement AggregateRootInterface"
            );
        }
    }
}