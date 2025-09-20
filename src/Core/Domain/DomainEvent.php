<?php

declare(strict_types=1);

namespace LaravelModularDDD\Core\Domain;

use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;
use LaravelModularDDD\Core\Domain\Contracts\AggregateIdInterface;
use DateTimeImmutable;
use Ramsey\Uuid\Uuid;

abstract class DomainEvent implements DomainEventInterface
{
    private readonly string $eventId;
    private readonly DateTimeImmutable $occurredAt;
    private readonly int $eventVersion;
    private readonly array $metadata;

    public function __construct(
        private readonly AggregateIdInterface $aggregateId,
        array $metadata = [],
        DateTimeImmutable $occurredAt = null
    ) {
        $this->eventId = Uuid::uuid4()->toString();
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable();
        $this->eventVersion = 1;
        $this->metadata = array_merge([
            'event_class' => static::class,
            'aggregate_type' => $this->getAggregateType(),
        ], $metadata);
    }

    public function getAggregateId(): AggregateIdInterface
    {
        return $this->aggregateId;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getEventType(): string
    {
        return static::class;
    }

    public function getEventVersion(): int
    {
        return $this->eventVersion;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'aggregate_id' => $this->aggregateId->toString(),
            'event_type' => $this->getEventType(),
            'event_version' => $this->eventVersion,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s.u'),
            'payload' => $this->getPayload(),
            'metadata' => $this->metadata,
        ];
    }

    public static function fromArray(array $data): static
    {
        // This method should be implemented by concrete event classes
        // as they know how to reconstruct their specific payload
        throw new \LogicException('fromArray must be implemented by concrete event classes');
    }

    /**
     * Get the aggregate type this event belongs to
     */
    abstract protected function getAggregateType(): string;

    /**
     * Get the event payload - to be implemented by concrete events
     */
    abstract public function getPayload(): array;
}