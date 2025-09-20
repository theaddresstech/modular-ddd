<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Exceptions;

use Exception;

class EventStoreException extends Exception
{
    public static function aggregateNotFound(string $aggregateId): self
    {
        return new self("Aggregate with ID {$aggregateId} not found");
    }

    public static function eventSerializationFailed(string $reason): self
    {
        return new self("Event serialization failed: {$reason}");
    }

    public static function eventDeserializationFailed(string $reason): self
    {
        return new self("Event deserialization failed: {$reason}");
    }

    public static function invalidEventData(string $reason): self
    {
        return new self("Invalid event data: {$reason}");
    }
}