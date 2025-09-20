<?php

declare(strict_types=1);

namespace LaravelModularDDD\Core\Domain\Contracts;

interface EntityInterface
{
    /**
     * Get the entity identifier
     */
    public function getId(): AggregateIdInterface;

    /**
     * Check if this entity equals another entity
     */
    public function equals(EntityInterface $other): bool;
}