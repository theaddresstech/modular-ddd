<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Saga;

use LaravelModularDDD\CQRS\Contracts\CommandInterface;
use LaravelModularDDD\Core\Events\DomainEventInterface;

interface SagaInterface
{
    /**
     * Get unique saga identifier
     */
    public function getSagaId(): string;

    /**
     * Get saga type/name
     */
    public function getSagaType(): string;

    /**
     * Get current saga state
     */
    public function getState(): SagaState;

    /**
     * Check if saga can handle the given event
     */
    public function canHandle(DomainEventInterface $event): bool;

    /**
     * Handle domain event and return next commands
     */
    public function handle(DomainEventInterface $event): array;

    /**
     * Get compensation commands for rollback
     */
    public function getCompensationCommands(): array;

    /**
     * Check if saga is completed
     */
    public function isCompleted(): bool;

    /**
     * Check if saga has failed
     */
    public function hasFailed(): bool;

    /**
     * Get saga timeout in seconds
     */
    public function getTimeout(): int;

    /**
     * Get saga metadata
     */
    public function getMetadata(): array;

    /**
     * Set saga metadata
     */
    public function setMetadata(array $metadata): void;

    /**
     * Hydrate saga from persisted state
     */
    public function hydrate(string $sagaId, SagaState $state, array $metadata): void;
}