<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Versioning;

interface EventUpcasterInterface
{
    /**
     * Get the event type this upcaster handles
     */
    public function getEventType(): string;

    /**
     * Get the source version this upcaster converts from
     */
    public function getFromVersion(): int;

    /**
     * Get the target version this upcaster converts to
     */
    public function getToVersion(): int;

    /**
     * Check if this upcaster can handle the given event data
     */
    public function canUpcast(array $eventData): bool;

    /**
     * Upcast the event data to the new version
     */
    public function upcast(array $eventData): array;

    /**
     * Get the priority of this upcaster (higher = processed first)
     */
    public function getPriority(): int;
}