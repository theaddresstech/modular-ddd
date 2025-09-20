<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Contracts;

use LaravelModularDDD\Core\Domain\Contracts\AggregateIdInterface;
use LaravelModularDDD\Core\Domain\Contracts\AggregateRootInterface;
use DateTimeImmutable;

interface AggregateSnapshotInterface
{
    /**
     * Get the aggregate ID
     */
    public function getAggregateId(): AggregateIdInterface;

    /**
     * Get the aggregate type
     */
    public function getAggregateType(): string;

    /**
     * Get the version this snapshot represents
     */
    public function getVersion(): int;

    /**
     * Get the serialized aggregate state
     */
    public function getState(): array;

    /**
     * Get when the snapshot was created
     */
    public function getCreatedAt(): DateTimeImmutable;

    /**
     * Get the snapshot hash for integrity checking
     */
    public function getHash(): string;

    /**
     * Reconstruct the aggregate from this snapshot
     */
    public function getAggregate(): AggregateRootInterface;

    /**
     * Verify the snapshot integrity
     */
    public function verifyIntegrity(): bool;
}