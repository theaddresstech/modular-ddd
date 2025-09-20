<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\Exceptions;

use Exception;

/**
 * EventOrderingException
 *
 * Thrown when event ordering violations are detected.
 * Indicates that events are not in the expected sequence for an aggregate.
 */
class EventOrderingException extends Exception
{
    public static function sequenceViolation(string $aggregateId, int $expected, int $actual): self
    {
        return new self(
            "Event sequence violation for aggregate {$aggregateId}. Expected {$expected}, got {$actual}"
        );
    }

    public static function outOfOrderEvent(string $eventId, string $aggregateId): self
    {
        return new self(
            "Out of order event {$eventId} detected for aggregate {$aggregateId}"
        );
    }

    public static function missingSequenceInformation(string $eventId): self
    {
        return new self(
            "Event {$eventId} is missing sequence information"
        );
    }

    public static function gapInSequence(string $aggregateId, int $fromSequence, int $toSequence): self
    {
        return new self(
            "Gap in event sequence for aggregate {$aggregateId} from {$fromSequence} to {$toSequence}"
        );
    }
}