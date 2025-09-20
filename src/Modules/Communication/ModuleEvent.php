<?php

declare(strict_types=1);

namespace LaravelModularDDD\Modules\Communication;

use Illuminate\Support\Str;

final class ModuleEvent
{
    private string $id;
    private string $sourceModule;
    private string $eventType;
    private array $payload;
    private array $metadata;
    private \DateTimeImmutable $occurredAt;

    public function __construct(
        string $sourceModule,
        string $eventType,
        array $payload = [],
        array $metadata = []
    ) {
        $this->id = Str::uuid()->toString();
        $this->sourceModule = $sourceModule;
        $this->eventType = $eventType;
        $this->payload = $payload;
        $this->metadata = $metadata;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public static function create(
        string $sourceModule,
        string $eventType,
        array $payload = [],
        array $metadata = []
    ): self {
        return new self($sourceModule, $eventType, $payload, $metadata);
    }

    public static function domain(
        string $sourceModule,
        string $eventName,
        array $payload = [],
        array $metadata = []
    ): self {
        return new self(
            $sourceModule,
            'domain.' . $eventName,
            $payload,
            array_merge(['type' => 'domain'], $metadata)
        );
    }

    public static function integration(
        string $sourceModule,
        string $eventName,
        array $payload = [],
        array $metadata = []
    ): self {
        return new self(
            $sourceModule,
            'integration.' . $eventName,
            $payload,
            array_merge(['type' => 'integration'], $metadata)
        );
    }

    public static function system(
        string $sourceModule,
        string $eventName,
        array $payload = [],
        array $metadata = []
    ): self {
        return new self(
            $sourceModule,
            'system.' . $eventName,
            $payload,
            array_merge(['type' => 'system'], $metadata)
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSourceModule(): string
    {
        return $this->sourceModule;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function withMetadata(array $metadata): self
    {
        $event = clone $this;
        $event->metadata = array_merge($this->metadata, $metadata);
        return $event;
    }

    public function withPayload(array $payload): self
    {
        $event = clone $this;
        $event->payload = array_merge($this->payload, $payload);
        return $event;
    }

    public function isDomainEvent(): bool
    {
        return str_starts_with($this->eventType, 'domain.');
    }

    public function isIntegrationEvent(): bool
    {
        return str_starts_with($this->eventType, 'integration.');
    }

    public function isSystemEvent(): bool
    {
        return str_starts_with($this->eventType, 'system.');
    }

    public function getVersion(): string
    {
        return $this->metadata['version'] ?? '1.0';
    }

    public function getCorrelationId(): ?string
    {
        return $this->metadata['correlation_id'] ?? null;
    }

    public function getCausationId(): ?string
    {
        return $this->metadata['causation_id'] ?? null;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source_module' => $this->sourceModule,
            'event_type' => $this->eventType,
            'payload' => $this->payload,
            'metadata' => $this->metadata,
            'occurred_at' => $this->occurredAt->format('c'),
        ];
    }

    public static function fromArray(array $data): self
    {
        $event = new self(
            $data['source_module'],
            $data['event_type'],
            $data['payload'] ?? [],
            $data['metadata'] ?? []
        );

        $event->id = $data['id'];
        $event->occurredAt = new \DateTimeImmutable($data['occurred_at']);

        return $event;
    }

    public function __toString(): string
    {
        return sprintf(
            '%s:%s[%s]',
            $this->eventType,
            $this->sourceModule,
            $this->id
        );
    }
}