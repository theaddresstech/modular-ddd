<?php

declare(strict_types=1);

namespace LaravelModularDDD\Core\Domain;

use LaravelModularDDD\Core\Domain\Contracts\AggregateRootInterface;
use LaravelModularDDD\Core\Domain\Contracts\AggregateIdInterface;
use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;

abstract class AggregateRoot implements AggregateRootInterface
{
    private array $uncommittedEvents = [];
    private int $version = 0;

    protected function __construct(
        protected readonly AggregateIdInterface $aggregateId
    ) {}

    public function getAggregateId(): AggregateIdInterface
    {
        return $this->aggregateId;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function pullDomainEvents(): array
    {
        $events = $this->uncommittedEvents;
        $this->uncommittedEvents = [];
        return $events;
    }

    public function markEventsAsCommitted(): void
    {
        $this->uncommittedEvents = [];
    }

    /**
     * Record a domain event that occurred on this aggregate
     */
    protected function recordThat(DomainEventInterface $event): void
    {
        $this->uncommittedEvents[] = $event;
        $this->apply($event);
        $this->version++;
    }

    /**
     * Apply the event to the aggregate's state
     */
    protected function apply(DomainEventInterface $event): void
    {
        $method = $this->getApplyMethod($event);

        if (method_exists($this, $method)) {
            $this->$method($event);
        }
    }

    /**
     * Get the method name for applying an event
     */
    private function getApplyMethod(DomainEventInterface $event): string
    {
        $eventClass = (new \ReflectionClass($event))->getShortName();
        return 'apply' . $eventClass;
    }

    /**
     * Check if there are uncommitted events
     */
    public function hasUncommittedEvents(): bool
    {
        return !empty($this->uncommittedEvents);
    }

    /**
     * Get the number of uncommitted events
     */
    public function getUncommittedEventsCount(): int
    {
        return count($this->uncommittedEvents);
    }
}