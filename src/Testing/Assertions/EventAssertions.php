<?php

declare(strict_types=1);

namespace LaravelModularDDD\Testing\Assertions;

use PHPUnit\Framework\TestCase;

/**
 * EventAssertions
 *
 * Event-specific assertions for testing event sourcing and CQRS patterns.
 * Provides assertions for event streams, projections, and event handling.
 */
trait EventAssertions
{
    /**
     * Assert that an event was dispatched.
     */
    public function assertEventDispatched(string $eventClass, array $eventData = null, string $message = ''): void
    {
        $dispatchedEvents = $this->getDispatchedEvents();
        $found = false;

        foreach ($dispatchedEvents as $event) {
            if ($event instanceof $eventClass || get_class($event) === $eventClass) {
                $found = true;

                if ($eventData !== null) {
                    $this->assertEventData($event, $eventData);
                }
                break;
            }
        }

        TestCase::assertTrue(
            $found,
            $message ?: "Event '{$eventClass}' was not dispatched"
        );
    }

    /**
     * Assert that an event was not dispatched.
     */
    public function assertEventNotDispatched(string $eventClass, string $message = ''): void
    {
        $dispatchedEvents = $this->getDispatchedEvents();

        foreach ($dispatchedEvents as $event) {
            if ($event instanceof $eventClass || get_class($event) === $eventClass) {
                TestCase::fail($message ?: "Event '{$eventClass}' should not have been dispatched");
            }
        }

        TestCase::assertTrue(true);
    }

    /**
     * Assert that events were dispatched in a specific order.
     */
    public function assertEventsDispatchedInOrder(array $eventClasses, string $message = ''): void
    {
        $dispatchedEvents = $this->getDispatchedEvents();
        $eventTypeOrder = array_map(fn($event) => get_class($event), $dispatchedEvents);

        $expectedOrder = [];
        $actualOrder = [];

        foreach ($eventClasses as $expectedClass) {
            $found = false;
            foreach ($eventTypeOrder as $index => $actualClass) {
                if ($actualClass === $expectedClass) {
                    $expectedOrder[] = $expectedClass;
                    $actualOrder[] = $index;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                TestCase::fail($message ?: "Event '{$expectedClass}' was not found in dispatched events");
            }
        }

        // Check if the found events are in ascending order
        TestCase::assertEquals(
            $actualOrder,
            array_values(array_sort($actualOrder)),
            $message ?: 'Events were not dispatched in the expected order'
        );
    }

    /**
     * Assert the number of dispatched events.
     */
    public function assertEventCount(int $expectedCount, string $eventClass = null, string $message = ''): void
    {
        $dispatchedEvents = $this->getDispatchedEvents();

        if ($eventClass) {
            $filteredEvents = array_filter(
                $dispatchedEvents,
                fn($event) => $event instanceof $eventClass || get_class($event) === $eventClass
            );
            $actualCount = count($filteredEvents);
            $message = $message ?: "Expected {$expectedCount} events of type '{$eventClass}', but got {$actualCount}";
        } else {
            $actualCount = count($dispatchedEvents);
            $message = $message ?: "Expected {$expectedCount} total events, but got {$actualCount}";
        }

        TestCase::assertEquals($expectedCount, $actualCount, $message);
    }

    /**
     * Assert that no events were dispatched.
     */
    public function assertNoEventsDispatched(string $message = ''): void
    {
        $this->assertEventCount(0, null, $message ?: 'Expected no events to be dispatched');
    }

    /**
     * Assert that an event contains specific data.
     */
    public function assertEventData(object $event, array $expectedData, string $message = ''): void
    {
        foreach ($expectedData as $key => $expectedValue) {
            $getter = 'get' . ucfirst($key);

            if (method_exists($event, $getter)) {
                $actualValue = $event->{$getter}();
            } elseif (property_exists($event, $key)) {
                $reflection = new \ReflectionProperty($event, $key);
                $reflection->setAccessible(true);
                $actualValue = $reflection->getValue($event);
            } else {
                TestCase::fail("Event property or getter '{$key}' not found on event");
            }

            TestCase::assertEquals(
                $expectedValue,
                $actualValue,
                $message ?: "Event data mismatch for key '{$key}'"
            );
        }
    }

    /**
     * Assert that an event occurred at a specific time.
     */
    public function assertEventOccurredAt(object $event, \DateTimeInterface $expectedTime, string $message = ''): void
    {
        $eventTime = $this->getEventTime($event);

        TestCase::assertEquals(
            $expectedTime->getTimestamp(),
            $eventTime->getTimestamp(),
            $message ?: 'Event did not occur at expected time'
        );
    }

    /**
     * Assert that an event occurred within a time range.
     */
    public function assertEventOccurredBetween(object $event, \DateTimeInterface $start, \DateTimeInterface $end, string $message = ''): void
    {
        $eventTime = $this->getEventTime($event);

        TestCase::assertGreaterThanOrEqual(
            $start->getTimestamp(),
            $eventTime->getTimestamp(),
            $message ?: 'Event occurred before expected start time'
        );

        TestCase::assertLessThanOrEqual(
            $end->getTimestamp(),
            $eventTime->getTimestamp(),
            $message ?: 'Event occurred after expected end time'
        );
    }

    /**
     * Assert that an event has specific metadata.
     */
    public function assertEventMetadata(object $event, array $expectedMetadata, string $message = ''): void
    {
        $metadata = $this->getEventMetadata($event);

        foreach ($expectedMetadata as $key => $expectedValue) {
            TestCase::assertArrayHasKey(
                $key,
                $metadata,
                $message ?: "Event metadata missing key '{$key}'"
            );

            TestCase::assertEquals(
                $expectedValue,
                $metadata[$key],
                $message ?: "Event metadata mismatch for key '{$key}'"
            );
        }
    }

    /**
     * Assert that an event stream contains specific events.
     */
    public function assertEventStreamContains(array $eventStream, array $expectedEventTypes, string $message = ''): void
    {
        $actualEventTypes = array_map(fn($event) => get_class($event), $eventStream);

        foreach ($expectedEventTypes as $expectedType) {
            TestCase::assertContains(
                $expectedType,
                $actualEventTypes,
                $message ?: "Event stream does not contain event of type '{$expectedType}'"
            );
        }
    }

    /**
     * Assert that an event stream has a specific length.
     */
    public function assertEventStreamLength(array $eventStream, int $expectedLength, string $message = ''): void
    {
        $actualLength = count($eventStream);

        TestCase::assertEquals(
            $expectedLength,
            $actualLength,
            $message ?: "Expected event stream length {$expectedLength}, but got {$actualLength}"
        );
    }

    /**
     * Assert that an event stream is empty.
     */
    public function assertEventStreamEmpty(array $eventStream, string $message = ''): void
    {
        $this->assertEventStreamLength($eventStream, 0, $message ?: 'Expected event stream to be empty');
    }

    /**
     * Assert that an event version is correct.
     */
    public function assertEventVersion(object $event, int $expectedVersion, string $message = ''): void
    {
        $version = $this->getEventVersion($event);

        TestCase::assertEquals(
            $expectedVersion,
            $version,
            $message ?: "Expected event version {$expectedVersion}, but got {$version}"
        );
    }

    /**
     * Assert that an event has a specific aggregate ID.
     */
    public function assertEventAggregateId(object $event, $expectedAggregateId, string $message = ''): void
    {
        $aggregateId = $this->getEventAggregateId($event);

        TestCase::assertEquals(
            $expectedAggregateId,
            $aggregateId,
            $message ?: "Event aggregate ID mismatch"
        );
    }

    /**
     * Assert that an event was caused by a specific command.
     */
    public function assertEventCausedBy(object $event, string $commandClass, string $message = ''): void
    {
        $causedBy = $this->getEventCausedBy($event);

        TestCase::assertEquals(
            $commandClass,
            $causedBy,
            $message ?: "Event was not caused by expected command '{$commandClass}'"
        );
    }

    /**
     * Assert that a projection was updated by an event.
     */
    public function assertProjectionUpdated(string $projectionClass, object $event, string $message = ''): void
    {
        $updatedProjections = $this->getUpdatedProjections();

        $found = false;
        foreach ($updatedProjections as $projection) {
            if ($projection['class'] === $projectionClass &&
                get_class($projection['event']) === get_class($event)) {
                $found = true;
                break;
            }
        }

        TestCase::assertTrue(
            $found,
            $message ?: "Projection '{$projectionClass}' was not updated by event"
        );
    }

    /**
     * Assert that an event handler was invoked.
     */
    public function assertEventHandlerInvoked(string $handlerClass, object $event, string $message = ''): void
    {
        $invokedHandlers = $this->getInvokedEventHandlers();

        $found = false;
        foreach ($invokedHandlers as $handler) {
            if ($handler['class'] === $handlerClass &&
                get_class($handler['event']) === get_class($event)) {
                $found = true;
                break;
            }
        }

        TestCase::assertTrue(
            $found,
            $message ?: "Event handler '{$handlerClass}' was not invoked for event"
        );
    }

    /**
     * Assert that an event replay produces expected state.
     */
    public function assertEventReplayProducesState(array $events, object $expectedState, string $message = ''): void
    {
        $replayedState = $this->replayEvents($events);

        TestCase::assertEquals(
            $expectedState,
            $replayedState,
            $message ?: 'Event replay did not produce expected state'
        );
    }

    /**
     * Assert that event serialization round-trip works correctly.
     */
    public function assertEventSerializationRoundTrip(object $event, string $message = ''): void
    {
        $serialized = $this->serializeEvent($event);
        $deserialized = $this->deserializeEvent($serialized, get_class($event));

        TestCase::assertEquals(
            $event,
            $deserialized,
            $message ?: 'Event serialization round-trip failed'
        );
    }

    /**
     * Get dispatched events (implementation depends on event tracking mechanism).
     */
    private function getDispatchedEvents(): array
    {
        // This would be implemented based on your event tracking mechanism
        // For example, using Laravel's Event::fake() or custom event collector
        if (property_exists($this, 'capturedEvents')) {
            return array_map(fn($captured) => $captured['payload'][0] ?? $captured, $this->capturedEvents);
        }

        if (method_exists($this, 'getEventCollector')) {
            return $this->getEventCollector()->getEvents();
        }

        return [];
    }

    /**
     * Get event timestamp.
     */
    private function getEventTime(object $event): \DateTimeInterface
    {
        $methods = ['getOccurredAt', 'getTimestamp', 'getCreatedAt', 'getTime'];

        foreach ($methods as $method) {
            if (method_exists($event, $method)) {
                $time = $event->{$method}();
                if ($time instanceof \DateTimeInterface) {
                    return $time;
                }
                if (is_string($time)) {
                    return new \DateTimeImmutable($time);
                }
                if (is_int($time)) {
                    return new \DateTimeImmutable('@' . $time);
                }
            }
        }

        return new \DateTimeImmutable();
    }

    /**
     * Get event metadata.
     */
    private function getEventMetadata(object $event): array
    {
        if (method_exists($event, 'getMetadata')) {
            return $event->getMetadata();
        }

        if (property_exists($event, 'metadata')) {
            $reflection = new \ReflectionProperty($event, 'metadata');
            $reflection->setAccessible(true);
            return $reflection->getValue($event) ?? [];
        }

        return [];
    }

    /**
     * Get event version.
     */
    private function getEventVersion(object $event): int
    {
        $methods = ['getVersion', 'getAggregateVersion'];

        foreach ($methods as $method) {
            if (method_exists($event, $method)) {
                return (int) $event->{$method}();
            }
        }

        return 1;
    }

    /**
     * Get event aggregate ID.
     */
    private function getEventAggregateId(object $event)
    {
        $methods = ['getAggregateId', 'getAggregateRootId', 'getId'];

        foreach ($methods as $method) {
            if (method_exists($event, $method)) {
                return $event->{$method}();
            }
        }

        return null;
    }

    /**
     * Get what caused the event.
     */
    private function getEventCausedBy(object $event): ?string
    {
        if (method_exists($event, 'getCausedBy')) {
            return $event->getCausedBy();
        }

        $metadata = $this->getEventMetadata($event);
        return $metadata['caused_by'] ?? null;
    }

    /**
     * Get updated projections (mock implementation).
     */
    private function getUpdatedProjections(): array
    {
        // This would be implemented based on your projection tracking mechanism
        return property_exists($this, 'updatedProjections') ? $this->updatedProjections : [];
    }

    /**
     * Get invoked event handlers (mock implementation).
     */
    private function getInvokedEventHandlers(): array
    {
        // This would be implemented based on your handler tracking mechanism
        return property_exists($this, 'invokedHandlers') ? $this->invokedHandlers : [];
    }

    /**
     * Replay events to reconstruct state (mock implementation).
     */
    private function replayEvents(array $events): object
    {
        // This would be implemented based on your event replay mechanism
        // For testing purposes, return a mock state
        return new class {
            public function __construct(public array $events = []) {
                $this->events = func_get_args();
            }
        };
    }

    /**
     * Serialize event (mock implementation).
     */
    private function serializeEvent(object $event): string
    {
        return serialize($event);
    }

    /**
     * Deserialize event (mock implementation).
     */
    private function deserializeEvent(string $serialized, string $eventClass): object
    {
        return unserialize($serialized);
    }
}