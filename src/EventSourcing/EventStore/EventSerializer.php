<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing\EventStore;

use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;
use LaravelModularDDD\EventSourcing\Exceptions\EventStoreException;

class EventSerializer
{
    /**
     * Serialize a domain event to array format
     */
    public function serialize(DomainEventInterface $event): array
    {
        try {
            return [
                'event_id' => $event->getEventId(),
                'aggregate_id' => $event->getAggregateId()->toString(),
                'event_type' => $event->getEventType(),
                'event_version' => $event->getEventVersion(),
                'occurred_at' => $event->getOccurredAt()->format('Y-m-d H:i:s.u'),
                'data' => $event->getPayload(),
                'metadata' => $event->getMetadata(),
            ];
        } catch (\Exception $e) {
            throw EventStoreException::eventSerializationFailed($e->getMessage());
        }
    }

    /**
     * Deserialize array data back to domain event
     */
    public function deserialize(array $data): DomainEventInterface
    {
        try {
            $eventClass = $data['event_type'];

            if (!class_exists($eventClass)) {
                throw new \InvalidArgumentException("Event class {$eventClass} does not exist");
            }

            if (!is_subclass_of($eventClass, DomainEventInterface::class)) {
                throw new \InvalidArgumentException(
                    "Event class {$eventClass} must implement DomainEventInterface"
                );
            }

            // Use the fromArray method that should be implemented by concrete events
            return $eventClass::fromArray($data);
        } catch (\Exception $e) {
            throw EventStoreException::eventDeserializationFailed($e->getMessage());
        }
    }

    /**
     * Serialize event for Redis storage (compact format)
     */
    public function serializeForRedis(DomainEventInterface $event): string
    {
        $serialized = $this->serialize($event);
        return json_encode($serialized, JSON_THROW_ON_ERROR);
    }

    /**
     * Deserialize event from Redis storage
     */
    public function deserializeFromRedis(string $data): DomainEventInterface
    {
        try {
            $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            return $this->deserialize($decoded);
        } catch (\JsonException $e) {
            throw EventStoreException::eventDeserializationFailed("JSON decode error: " . $e->getMessage());
        }
    }

    /**
     * Batch serialize multiple events
     */
    public function serializeBatch(array $events): array
    {
        $serialized = [];
        foreach ($events as $event) {
            if (!$event instanceof DomainEventInterface) {
                throw new \InvalidArgumentException('All events must implement DomainEventInterface');
            }
            $serialized[] = $this->serialize($event);
        }
        return $serialized;
    }

    /**
     * Batch deserialize multiple events
     */
    public function deserializeBatch(array $data): array
    {
        $events = [];
        foreach ($data as $eventData) {
            $events[] = $this->deserialize($eventData);
        }
        return $events;
    }
}