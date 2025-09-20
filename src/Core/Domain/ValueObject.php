<?php

declare(strict_types=1);

namespace LaravelModularDDD\Core\Domain;

use LaravelModularDDD\Core\Domain\Contracts\ValueObjectInterface;

abstract class ValueObject implements ValueObjectInterface
{
    public function equals(ValueObjectInterface $other): bool
    {
        return $this::class === $other::class &&
               $this->toArray() === $other->toArray();
    }

    public function toString(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    abstract public function toArray(): array;
}