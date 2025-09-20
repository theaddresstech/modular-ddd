<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Exceptions;

use Exception;
use LaravelModularDDD\Core\Domain\Contracts\AggregateIdInterface;

class ConcurrencyException extends Exception
{
    public function __construct(
        private readonly AggregateIdInterface $aggregateId,
        private readonly int $expectedVersion,
        private readonly int $actualVersion,
        string $message = null
    ) {
        $message = $message ?: sprintf(
            'Concurrency conflict for aggregate %s. Expected version %d, but actual version is %d',
            $this->aggregateId->toString(),
            $this->expectedVersion,
            $this->actualVersion
        );

        parent::__construct($message);
    }

    public function getAggregateId(): AggregateIdInterface
    {
        return $this->aggregateId;
    }

    public function getExpectedVersion(): int
    {
        return $this->expectedVersion;
    }

    public function getActualVersion(): int
    {
        return $this->actualVersion;
    }
}