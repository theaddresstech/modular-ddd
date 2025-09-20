<?php

declare(strict_types=1);

namespace LaravelModularDDD\Core\Domain\Contracts;

use DateTimeImmutable;

interface DomainEventInterface
{
    /**
     * Get the aggregate ID this event belongs to
     */
    public function getAggregateId(): AggregateIdInterface;

    /**
     * Get the event ID
     */
    public function getEventId(): string;

    /**
     * Get when the event occurred
     */
    public function getOccurredAt(): DateTimeImmutable;

    /**
     * Get the event type/name
     */
    public function getEventType(): string;

    /**
     * Get the event version
     */
    public function getEventVersion(): int;

    /**
     * Get event metadata
     */
    public function getMetadata(): array;

    /**
     * Get event payload
     */
    public function getPayload(): array;

    /**
     * Serialize the event to array
     */
    public function toArray(): array;

    /**
     * Create event from array
     */
    public static function fromArray(array $data): static;
}