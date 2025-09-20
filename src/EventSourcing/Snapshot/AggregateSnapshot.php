<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Snapshot;

use LaravelModularDDD\EventSourcing\Contracts\AggregateSnapshotInterface;
use LaravelModularDDD\Core\Domain\Contracts\AggregateIdInterface;
use LaravelModularDDD\Core\Domain\Contracts\AggregateRootInterface;
use DateTimeImmutable;

class AggregateSnapshot implements AggregateSnapshotInterface
{
    private readonly string $hash;

    public function __construct(
        private readonly AggregateIdInterface $aggregateId,
        private readonly string $aggregateType,
        private readonly int $version,
        private readonly array $state,
        private readonly DateTimeImmutable $createdAt,
        ?string $hash = null
    ) {
        $this->hash = $hash ?? $this->calculateHash();
    }

    public function getAggregateId(): AggregateIdInterface
    {
        return $this->aggregateId;
    }

    public function getAggregateType(): string
    {
        return $this->aggregateType;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getState(): array
    {
        return $this->state;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    public function getAggregate(): AggregateRootInterface
    {
        $aggregateClass = $this->aggregateType;

        if (!class_exists($aggregateClass)) {
            throw new \InvalidArgumentException("Aggregate class {$aggregateClass} does not exist");
        }

        if (!is_subclass_of($aggregateClass, AggregateRootInterface::class)) {
            throw new \InvalidArgumentException(
                "Class {$aggregateClass} must implement AggregateRootInterface"
            );
        }

        // Use reflection to reconstruct the aggregate from snapshot
        $reflection = new \ReflectionClass($aggregateClass);

        // Create instance without calling constructor
        $aggregate = $reflection->newInstanceWithoutConstructor();

        // Restore state from snapshot
        $this->restoreAggregateState($aggregate, $reflection);

        return $aggregate;
    }

    public function verifyIntegrity(): bool
    {
        return $this->hash === $this->calculateHash();
    }

    /**
     * Create snapshot from aggregate
     */
    public static function fromAggregate(
        AggregateRootInterface $aggregate,
        ?DateTimeImmutable $createdAt = null
    ): self {
        $state = self::extractAggregateState($aggregate);

        return new self(
            $aggregate->getAggregateId(),
            get_class($aggregate),
            $aggregate->getVersion(),
            $state,
            $createdAt ?? new DateTimeImmutable()
        );
    }

    /**
     * Create snapshot from database record
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['aggregate_id'],
            $data['aggregate_type'],
            $data['version'],
            $data['state'],
            new DateTimeImmutable($data['created_at']),
            $data['hash'] ?? null
        );
    }

    /**
     * Convert snapshot to array for storage
     */
    public function toArray(): array
    {
        return [
            'aggregate_id' => $this->aggregateId->toString(),
            'aggregate_type' => $this->aggregateType,
            'version' => $this->version,
            'state' => $this->state,
            'hash' => $this->hash,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s.u'),
        ];
    }

    /**
     * Get compressed state for storage
     */
    public function getCompressedState(): string
    {
        $serialized = json_encode($this->state, JSON_THROW_ON_ERROR);

        if (function_exists('gzcompress')) {
            return gzcompress($serialized, 6);
        }

        return $serialized;
    }

    /**
     * Create snapshot from compressed state
     */
    public static function fromCompressedState(
        AggregateIdInterface $aggregateId,
        string $aggregateType,
        int $version,
        string $compressedState,
        DateTimeImmutable $createdAt,
        string $hash
    ): self {
        $state = self::decompressState($compressedState);

        return new self(
            $aggregateId,
            $aggregateType,
            $version,
            $state,
            $createdAt,
            $hash
        );
    }

    private function calculateHash(): string
    {
        $data = [
            'aggregate_id' => $this->aggregateId->toString(),
            'aggregate_type' => $this->aggregateType,
            'version' => $this->version,
            'state' => $this->state,
        ];

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function restoreAggregateState(
        AggregateRootInterface $aggregate,
        \ReflectionClass $reflection
    ): void {
        foreach ($this->state as $property => $value) {
            try {
                if ($reflection->hasProperty($property)) {
                    $prop = $reflection->getProperty($property);
                    $prop->setAccessible(true);
                    $prop->setValue($aggregate, $value);
                }
            } catch (\ReflectionException $e) {
                // Property might be private or protected in parent class
                continue;
            }
        }
    }

    private static function extractAggregateState(AggregateRootInterface $aggregate): array
    {
        $reflection = new \ReflectionClass($aggregate);
        $state = [];

        foreach ($reflection->getProperties() as $property) {
            $property->setAccessible(true);
            $value = $property->getValue($aggregate);

            // Skip non-serializable properties
            if (is_resource($value) || is_callable($value)) {
                continue;
            }

            // Handle objects that need special serialization
            if (is_object($value)) {
                if (method_exists($value, 'toArray')) {
                    $value = $value->toArray();
                } elseif (method_exists($value, '__toString')) {
                    $value = (string) $value;
                } elseif ($value instanceof \DateTimeInterface) {
                    $value = $value->format('Y-m-d H:i:s.u');
                } else {
                    // Try to serialize complex objects
                    try {
                        $value = serialize($value);
                    } catch (\Exception $e) {
                        // Skip properties that can't be serialized
                        continue;
                    }
                }
            }

            $state[$property->getName()] = $value;
        }

        return $state;
    }

    private static function decompressState(string $compressedState): array
    {
        if (function_exists('gzuncompress')) {
            $decompressed = gzuncompress($compressedState);
            if ($decompressed !== false) {
                return json_decode($decompressed, true, 512, JSON_THROW_ON_ERROR);
            }
        }

        // Fallback - assume it's not compressed
        return json_decode($compressedState, true, 512, JSON_THROW_ON_ERROR);
    }
}