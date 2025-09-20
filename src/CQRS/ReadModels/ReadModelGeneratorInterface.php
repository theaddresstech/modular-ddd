<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\ReadModels;

use LaravelModularDDD\Core\Events\DomainEventInterface;

interface ReadModelGeneratorInterface
{
    /**
     * Generate read model from event stream
     */
    public function generate(string $aggregateId, array $events): ReadModel;

    /**
     * Update existing read model with new events
     */
    public function update(ReadModel $readModel, array $events): ReadModel;

    /**
     * Check if generator supports this event type
     */
    public function supports(DomainEventInterface $event): bool;

    /**
     * Get the read model type this generator creates
     */
    public function getReadModelType(): string;

    /**
     * Get generator priority (higher = executed first)
     */
    public function getPriority(): int;

    /**
     * Validate read model integrity
     */
    public function validate(ReadModel $readModel): bool;

    /**
     * Get read model schema definition
     */
    public function getSchema(): array;
}