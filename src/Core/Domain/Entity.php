<?php

declare(strict_types=1);

namespace LaravelModularDDD\Core\Domain;

use LaravelModularDDD\Core\Domain\Contracts\EntityInterface;
use LaravelModularDDD\Core\Domain\Contracts\AggregateIdInterface;

abstract class Entity implements EntityInterface
{
    protected function __construct(
        protected readonly AggregateIdInterface $id
    ) {}

    public function getId(): AggregateIdInterface
    {
        return $this->id;
    }

    public function equals(EntityInterface $other): bool
    {
        return $this::class === $other::class &&
               $this->id->equals($other->getId());
    }
}