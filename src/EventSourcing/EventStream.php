<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing;

use LaravelModularDDD\EventSourcing\Contracts\EventStreamInterface;
use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;
use ArrayIterator;
use Traversable;

final class EventStream implements EventStreamInterface
{
    /**
     * @param DomainEventInterface[] $events
     */
    public function __construct(
        private readonly array $events = []
    ) {}

    public function getEvents(): array
    {
        return $this->events;
    }

    public function isEmpty(): bool
    {
        return empty($this->events);
    }

    public function first(): ?DomainEventInterface
    {
        return $this->events[0] ?? null;
    }

    public function last(): ?DomainEventInterface
    {
        return end($this->events) ?: null;
    }

    public function filterByType(string $eventType): EventStreamInterface
    {
        $filteredEvents = array_filter(
            $this->events,
            fn(DomainEventInterface $event) => $event instanceof $eventType
        );

        return new self(array_values($filteredEvents));
    }

    public function limit(int $limit): EventStreamInterface
    {
        return new self(array_slice($this->events, 0, $limit));
    }

    public function skip(int $offset): EventStreamInterface
    {
        return new self(array_slice($this->events, $offset));
    }

    public function reverse(): EventStreamInterface
    {
        return new self(array_reverse($this->events));
    }

    public function count(): int
    {
        return count($this->events);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->events);
    }

    /**
     * Create an empty event stream
     */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Create event stream from array of events
     */
    public static function fromArray(array $events): self
    {
        foreach ($events as $event) {
            if (!$event instanceof DomainEventInterface) {
                throw new \InvalidArgumentException(
                    'All items must implement DomainEventInterface'
                );
            }
        }

        return new self($events);
    }

    /**
     * Append events to this stream
     */
    public function append(DomainEventInterface ...$events): self
    {
        return new self([...$this->events, ...$events]);
    }

    /**
     * Get events as array (alias for getEvents)
     */
    public function toArray(): array
    {
        return $this->events;
    }
}