<?php

declare(strict_types=1);

namespace LaravelModularDDD\Core\Domain\Contracts;

interface ValueObjectInterface
{
    /**
     * Check if this value object equals another value object
     */
    public function equals(ValueObjectInterface $other): bool;

    /**
     * Get string representation
     */
    public function toString(): string;

    /**
     * Convert to array representation
     */
    public function toArray(): array;
}