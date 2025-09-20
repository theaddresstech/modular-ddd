<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Contracts;

use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;
use IteratorAggregate;
use Countable;

interface EventStreamInterface extends IteratorAggregate, Countable
{
    /**
     * Get all events in the stream
     *
     * @return DomainEventInterface[]
     */
    public function getEvents(): array;

    /**
     * Check if the stream is empty
     */
    public function isEmpty(): bool;

    /**
     * Get the first event in the stream
     */
    public function first(): ?DomainEventInterface;

    /**
     * Get the last event in the stream
     */
    public function last(): ?DomainEventInterface;

    /**
     * Filter events by type
     */
    public function filterByType(string $eventType): EventStreamInterface;

    /**
     * Take a limited number of events
     */
    public function limit(int $limit): EventStreamInterface;

    /**
     * Skip a number of events
     */
    public function skip(int $offset): EventStreamInterface;

    /**
     * Reverse the order of events
     */
    public function reverse(): EventStreamInterface;
}