<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS;

use LaravelModularDDD\CQRS\Contracts\CommandInterface;
use Ramsey\Uuid\Uuid;

abstract class Command implements CommandInterface
{
    private readonly string $commandId;
    private readonly array $metadata;
    private readonly int $createdAt;

    public function __construct(array $metadata = [])
    {
        $this->commandId = Uuid::uuid4()->toString();
        $this->createdAt = time();
        $this->metadata = array_merge([
            'created_at' => $this->createdAt,
            'command_class' => static::class,
            'user_id' => auth()->id(),
            'request_id' => request()->header('X-Request-ID'),
        ], $metadata);
    }

    public function getCommandId(): string
    {
        return $this->commandId;
    }

    public function getCommandName(): string
    {
        return static::class;
    }

    public function getValidationRules(): array
    {
        return [];
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getPriority(): int
    {
        return 0; // Normal priority
    }

    public function getTimeout(): int
    {
        return 30; // 30 seconds default
    }

    public function shouldRetry(): bool
    {
        return true;
    }

    public function getMaxRetries(): int
    {
        return 3;
    }

    public function toArray(): array
    {
        $reflection = new \ReflectionClass($this);
        $properties = [];

        foreach ($reflection->getProperties() as $property) {
            $property->setAccessible(true);
            $value = $property->getValue($this);

            // Handle serialization of objects
            if (is_object($value)) {
                if (method_exists($value, 'toArray')) {
                    $value = $value->toArray();
                } elseif (method_exists($value, '__toString')) {
                    $value = (string) $value;
                } else {
                    $value = serialize($value);
                }
            }

            $properties[$property->getName()] = $value;
        }

        return [
            'command_class' => static::class,
            'command_id' => $this->commandId,
            'metadata' => $this->metadata,
            'properties' => $properties,
        ];
    }

    /**
     * Create command from array (for deserialization)
     */
    public static function fromArray(array $data): static
    {
        $reflection = new \ReflectionClass(static::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        // Restore properties
        foreach ($data['properties'] as $propertyName => $value) {
            if ($reflection->hasProperty($propertyName)) {
                $property = $reflection->getProperty($propertyName);
                $property->setAccessible(true);
                $property->setValue($instance, $value);
            }
        }

        return $instance;
    }

    /**
     * Add metadata to command
     */
    protected function addMetadata(string $key, mixed $value): void
    {
        $this->metadata[$key] = $value;
    }

    /**
     * Get specific metadata value
     */
    protected function getMetadataValue(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }
}