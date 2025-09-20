<?php

declare(strict_types=1);

namespace LaravelModularDDD\Core\Domain\Contracts;

interface AggregateIdInterface
{
    /**
     * Get the string representation of the ID
     */
    public function toString(): string;

    /**
     * Check if this ID equals another ID
     */
    public function equals(AggregateIdInterface $other): bool;

    /**
     * Generate a new unique ID
     */
    public static function generate(): static;

    /**
     * Create ID from string
     */
    public static function fromString(string $id): static;
}