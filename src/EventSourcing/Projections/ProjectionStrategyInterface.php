<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Projections;

use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;

interface ProjectionStrategyInterface
{
    /**
     * Handle a domain event for projection updates
     */
    public function handle(DomainEventInterface $event): void;

    /**
     * Rebuild a specific projection from scratch
     */
    public function rebuild(string $projectionName): void;

    /**
     * Get the delay in milliseconds before processing the event
     */
    public function getDelay(): int;

    /**
     * Check if this strategy should handle the given event
     */
    public function shouldHandle(DomainEventInterface $event): bool;

    /**
     * Get strategy name for identification
     */
    public function getName(): string;

    /**
     * Get the priority of this strategy (higher priority executed first)
     */
    public function getPriority(): int;
}