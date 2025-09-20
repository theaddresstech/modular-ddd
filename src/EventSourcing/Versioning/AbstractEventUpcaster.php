<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Versioning;

abstract class AbstractEventUpcaster implements EventUpcasterInterface
{
    public function __construct(
        protected readonly string $eventType,
        protected readonly int $fromVersion,
        protected readonly int $toVersion,
        protected readonly int $priority = 0
    ) {}

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getFromVersion(): int
    {
        return $this->fromVersion;
    }

    public function getToVersion(): int
    {
        return $this->toVersion;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function canUpcast(array $eventData): bool
    {
        return ($eventData['event_type'] ?? '') === $this->eventType &&
               ($eventData['event_version'] ?? 1) === $this->fromVersion;
    }

    /**
     * Helper method to add a field with default value
     */
    protected function addField(array $eventData, string $field, mixed $defaultValue): array
    {
        if (!isset($eventData['data'])) {
            $eventData['data'] = [];
        }

        $eventData['data'][$field] = $defaultValue;

        return $eventData;
    }

    /**
     * Helper method to remove a field
     */
    protected function removeField(array $eventData, string $field): array
    {
        if (isset($eventData['data'][$field])) {
            unset($eventData['data'][$field]);
        }

        return $eventData;
    }

    /**
     * Helper method to rename a field
     */
    protected function renameField(array $eventData, string $oldField, string $newField): array
    {
        if (isset($eventData['data'][$oldField])) {
            $eventData['data'][$newField] = $eventData['data'][$oldField];
            unset($eventData['data'][$oldField]);
        }

        return $eventData;
    }

    /**
     * Helper method to transform a field value
     */
    protected function transformField(array $eventData, string $field, callable $transformer): array
    {
        if (isset($eventData['data'][$field])) {
            $eventData['data'][$field] = $transformer($eventData['data'][$field]);
        }

        return $eventData;
    }

    /**
     * Helper method to update the event version
     */
    protected function updateVersion(array $eventData, int $newVersion): array
    {
        $eventData['event_version'] = $newVersion;

        return $eventData;
    }

    /**
     * Helper method to add metadata
     */
    protected function addMetadata(array $eventData, string $key, mixed $value): array
    {
        if (!isset($eventData['metadata'])) {
            $eventData['metadata'] = [];
        }

        $eventData['metadata'][$key] = $value;

        return $eventData;
    }
}