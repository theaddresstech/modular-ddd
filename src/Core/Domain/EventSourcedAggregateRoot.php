<?php

declare(strict_types=1);

namespace LaravelModularDDD\Core\Domain;

use LaravelModularDDD\Core\Domain\Contracts\AggregateIdInterface;
use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;

abstract class EventSourcedAggregateRoot extends AggregateRoot
{
    private int $originalVersion = 0;

    /**
     * Reconstitute an aggregate from its event history
     */
    public static function reconstituteFromEvents(
        AggregateIdInterface $aggregateId,
        iterable $events
    ): static {
        $aggregate = new static($aggregateId);
        $aggregate->replay($events);
        $aggregate->markEventsAsCommitted();
        $aggregate->originalVersion = $aggregate->getVersion();

        return $aggregate;
    }

    /**
     * Replay events to rebuild the aggregate state
     */
    public function replay(iterable $events): void
    {
        foreach ($events as $event) {
            if (!$event instanceof DomainEventInterface) {
                throw new \InvalidArgumentException(
                    'All events must implement DomainEventInterface'
                );
            }

            $this->apply($event);
            $this->version++;
        }
    }

    /**
     * Create a new aggregate instance (factory method)
     */
    protected static function createNew(AggregateIdInterface $aggregateId): static
    {
        return new static($aggregateId);
    }

    /**
     * Get the version when the aggregate was loaded
     */
    public function getOriginalVersion(): int
    {
        return $this->originalVersion;
    }

    /**
     * Get only the new events since loading
     */
    public function getNewEvents(): array
    {
        return array_slice(
            $this->pullDomainEvents(),
            $this->originalVersion
        );
    }

    /**
     * Check if the aggregate has been modified since loading
     */
    public function hasBeenModified(): bool
    {
        return $this->getVersion() > $this->originalVersion;
    }

    /**
     * Reset to original state (useful for testing)
     */
    protected function resetToOriginalState(): void
    {
        $this->version = $this->originalVersion;
        $this->markEventsAsCommitted();
    }

    /**
     * Get events count since the original version
     */
    public function getNewEventsCount(): int
    {
        return $this->getVersion() - $this->originalVersion;
    }
}