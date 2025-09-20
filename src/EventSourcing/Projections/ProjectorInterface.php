<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Projections;

use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;

interface ProjectorInterface
{
    /**
     * Get the name of this projector
     */
    public function getName(): string;

    /**
     * Get the events this projector handles
     *
     * @return string[] Array of event class names
     */
    public function getHandledEvents(): array;

    /**
     * Process a domain event
     */
    public function handle(DomainEventInterface $event): void;

    /**
     * Check if this projector can handle the given event
     */
    public function canHandle(DomainEventInterface $event): bool;

    /**
     * Reset the projection (delete all read model data)
     */
    public function reset(): void;

    /**
     * Get the current position/checkpoint of this projector
     */
    public function getPosition(): int;

    /**
     * Set the position/checkpoint of this projector
     */
    public function setPosition(int $position): void;

    /**
     * Check if the projector is enabled
     */
    public function isEnabled(): bool;

    /**
     * Enable or disable the projector
     */
    public function setEnabled(bool $enabled): void;
}