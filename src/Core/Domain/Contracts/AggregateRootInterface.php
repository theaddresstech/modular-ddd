<?php

declare(strict_types=1);

namespace LaravelModularDDD\Core\Domain\Contracts;

interface AggregateRootInterface
{
    /**
     * Get the aggregate identifier
     */
    public function getAggregateId(): AggregateIdInterface;

    /**
     * Get the current version of the aggregate
     */
    public function getVersion(): int;

    /**
     * Pull all uncommitted domain events
     *
     * @return DomainEventInterface[]
     */
    public function pullDomainEvents(): array;

    /**
     * Mark all events as committed
     */
    public function markEventsAsCommitted(): void;
}