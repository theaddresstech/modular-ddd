<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Exceptions;

use LaravelModularDDD\Core\Domain\Contracts\AggregateIdInterface;

class AggregateNotFoundException extends \Exception
{
    public function __construct(AggregateIdInterface $aggregateId, ?\Throwable $previous = null)
    {
        $message = "Aggregate with ID '{$aggregateId->toString()}' not found";
        parent::__construct($message, 0, $previous);
    }
}