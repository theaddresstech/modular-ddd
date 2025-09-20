<?php

declare(strict_types=1);

namespace LaravelModularDDD\Core\Shared;

use LaravelModularDDD\Core\Domain\Contracts\AggregateIdInterface;
use Ramsey\Uuid\Uuid;
use InvalidArgumentException;

abstract class AggregateId implements AggregateIdInterface
{
    protected function __construct(private readonly string $value)
    {
        if (empty($value)) {
            throw new InvalidArgumentException('Aggregate ID cannot be empty');
        }

        if (!Uuid::isValid($value)) {
            throw new InvalidArgumentException('Aggregate ID must be a valid UUID');
        }
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(AggregateIdInterface $other): bool
    {
        return $this::class === $other::class && $this->value === $other->toString();
    }

    public static function generate(): static
    {
        return new static(Uuid::uuid4()->toString());
    }

    public static function fromString(string $id): static
    {
        return new static($id);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}