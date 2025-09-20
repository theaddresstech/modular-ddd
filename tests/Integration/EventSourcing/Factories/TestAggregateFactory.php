<?php

declare(strict_types=1);

namespace LaravelModularDDD\Tests\Integration\EventSourcing\Factories;

use LaravelModularDDD\Core\Domain\Contracts\AggregateIdInterface;
use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;
use Ramsey\Uuid\Uuid;

/**
 * Factory for creating test aggregates, events, and snapshots for Event Sourcing integration tests.
 */
class TestAggregateFactory
{
    /**
     * Create a sequence of events for testing
     */
    public function createEventSequence(
        AggregateIdInterface $aggregateId,
        int $count,
        int $startVersion = 1
    ): array {
        $events = [];

        for ($i = 0; $i < $count; $i++) {
            $version = $startVersion + $i;
            $events[] = $this->createDomainEvent(
                $aggregateId,
                "TestEvent{$version}",
                [
                    'sequence' => $version,
                    'timestamp' => microtime(true),
                    'random_data' => bin2hex(random_bytes(8)),
                ],
                $version
            );
        }

        return $events;
    }

    /**
     * Create events of a specific type for multiple aggregates
     */
    public function createEventsOfType(
        array $aggregateIds,
        string $eventType,
        int $eventsPerAggregate = 1
    ): array {
        $events = [];

        foreach ($aggregateIds as $aggregateId) {
            for ($i = 1; $i <= $eventsPerAggregate; $i++) {
                $events[] = $this->createDomainEvent(
                    $aggregateId,
                    $eventType,
                    [
                        'aggregate_id' => $aggregateId->toString(),
                        'event_number' => $i,
                        'created_at' => now()->toISOString(),
                    ],
                    $i
                );
            }
        }

        return $events;
    }

    /**
     * Create domain event with realistic data
     */
    public function createDomainEvent(
        AggregateIdInterface $aggregateId,
        string $eventType,
        array $data = [],
        int $version = 1
    ): DomainEventInterface {
        return new class($aggregateId, $eventType, $data, $version) implements DomainEventInterface {
            private \DateTimeImmutable $occurredAt;
            private array $metadata;

            public function __construct(
                private AggregateIdInterface $aggregateId,
                private string $eventType,
                private array $data,
                private int $version
            ) {
                $this->occurredAt = new \DateTimeImmutable();
                $this->metadata = [
                    'event_id' => Uuid::uuid4()->toString(),
                    'correlation_id' => Uuid::uuid4()->toString(),
                    'causation_id' => Uuid::uuid4()->toString(),
                    'source' => 'test-factory',
                    'created_at' => $this->occurredAt->format('Y-m-d H:i:s.u'),
                ];
            }

            public function getAggregateId(): AggregateIdInterface
            {
                return $this->aggregateId;
            }

            public function getEventType(): string
            {
                return $this->eventType;
            }

            public function getEventData(): array
            {
                return $this->data;
            }

            public function getVersion(): int
            {
                return $this->version;
            }

            public function getOccurredAt(): \DateTimeImmutable
            {
                return $this->occurredAt;
            }

            public function getEventVersion(): int
            {
                return 1;
            }

            public function getEventId(): string
            {
                return $this->metadata['event_id'];
            }

            public function getPayload(): array
            {
                return $this->data;
            }

            public function toArray(): array
            {
                return [
                    'event_id' => $this->getEventId(),
                    'aggregate_id' => $this->aggregateId->toString(),
                    'event_type' => $this->eventType,
                    'event_version' => $this->getEventVersion(),
                    'payload' => $this->data,
                    'version' => $this->version,
                    'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s.u'),
                    'metadata' => $this->metadata,
                ];
            }

            public function getMetadata(): array
            {
                return $this->metadata;
            }

            public static function fromArray(array $data): static
            {
                $aggregateId = new class($data['aggregate_id']) implements AggregateIdInterface {
                    public function __construct(private string $id) {}
                    public function toString(): string { return $this->id; }
                    public function equals(AggregateIdInterface $other): bool { return false; }
                    public static function generate(): static { return new static(Uuid::uuid4()->toString()); }
                    public static function fromString(string $id): static { return new static($id); }
                };

                return new static(
                    $aggregateId,
                    $data['event_type'],
                    $data['payload'] ?? [],
                    $data['version'] ?? 1
                );
            }

            public function withMetadata(array $metadata): self
            {
                $clone = clone $this;
                $clone->metadata = array_merge($this->metadata, $metadata);
                return $clone;
            }
        };
    }

    /**
     * Create snapshot data for testing
     */
    public function createSnapshotData(AggregateIdInterface $aggregateId, int $version): array
    {
        return [
            'aggregate_id' => $aggregateId->toString(),
            'version' => $version,
            'state' => [
                'id' => $aggregateId->toString(),
                'status' => 'active',
                'properties' => [
                    'name' => 'Test Aggregate',
                    'created_at' => now()->toISOString(),
                    'updated_at' => now()->toISOString(),
                    'counters' => [
                        'events_applied' => $version,
                        'snapshots_created' => 1,
                    ],
                ],
                'computed_values' => [
                    'checksum' => md5($aggregateId->toString() . $version),
                    'snapshot_created_at' => now()->toISOString(),
                ],
            ],
            'metadata' => [
                'snapshot_version' => '1.0',
                'compression' => 'none',
                'created_by' => 'test-factory',
                'created_at' => now()->toISOString(),
            ],
        ];
    }

    /**
     * Create events with realistic user aggregate patterns
     */
    public function createUserAggregateEvents(AggregateIdInterface $userId): array
    {
        return [
            $this->createDomainEvent(
                $userId,
                'UserRegistered',
                [
                    'email' => 'user@example.com',
                    'name' => 'Test User',
                    'registered_at' => now()->toISOString(),
                ],
                1
            ),
            $this->createDomainEvent(
                $userId,
                'UserEmailVerified',
                [
                    'email' => 'user@example.com',
                    'verified_at' => now()->addMinutes(5)->toISOString(),
                ],
                2
            ),
            $this->createDomainEvent(
                $userId,
                'UserProfileUpdated',
                [
                    'name' => 'Updated Test User',
                    'bio' => 'This is a test user profile',
                    'updated_at' => now()->addHours(1)->toISOString(),
                ],
                3
            ),
        ];
    }

    /**
     * Create events with realistic order aggregate patterns
     */
    public function createOrderAggregateEvents(AggregateIdInterface $orderId): array
    {
        return [
            $this->createDomainEvent(
                $orderId,
                'OrderCreated',
                [
                    'customer_id' => Uuid::uuid4()->toString(),
                    'total_amount' => 99.99,
                    'currency' => 'USD',
                    'created_at' => now()->toISOString(),
                ],
                1
            ),
            $this->createDomainEvent(
                $orderId,
                'OrderItemAdded',
                [
                    'product_id' => Uuid::uuid4()->toString(),
                    'quantity' => 2,
                    'unit_price' => 29.99,
                    'total_price' => 59.98,
                ],
                2
            ),
            $this->createDomainEvent(
                $orderId,
                'OrderItemAdded',
                [
                    'product_id' => Uuid::uuid4()->toString(),
                    'quantity' => 1,
                    'unit_price' => 39.99,
                    'total_price' => 39.99,
                ],
                3
            ),
            $this->createDomainEvent(
                $orderId,
                'OrderShippingAddressSet',
                [
                    'address_line_1' => '123 Test Street',
                    'city' => 'Test City',
                    'postal_code' => '12345',
                    'country' => 'US',
                ],
                4
            ),
            $this->createDomainEvent(
                $orderId,
                'OrderConfirmed',
                [
                    'confirmed_at' => now()->addMinutes(10)->toISOString(),
                    'confirmation_number' => 'ORD-' . strtoupper(bin2hex(random_bytes(4))),
                ],
                5
            ),
        ];
    }

    /**
     * Create events that represent a complex business workflow
     */
    public function createWorkflowEvents(AggregateIdInterface $workflowId): array
    {
        return [
            $this->createDomainEvent(
                $workflowId,
                'WorkflowStarted',
                [
                    'workflow_type' => 'order_fulfillment',
                    'initiator' => 'user_123',
                    'context' => ['order_id' => Uuid::uuid4()->toString()],
                ],
                1
            ),
            $this->createDomainEvent(
                $workflowId,
                'WorkflowStepCompleted',
                [
                    'step_name' => 'payment_processing',
                    'step_result' => 'success',
                    'duration_ms' => 1250,
                ],
                2
            ),
            $this->createDomainEvent(
                $workflowId,
                'WorkflowStepStarted',
                [
                    'step_name' => 'inventory_allocation',
                    'depends_on' => ['payment_processing'],
                ],
                3
            ),
            $this->createDomainEvent(
                $workflowId,
                'WorkflowStepCompleted',
                [
                    'step_name' => 'inventory_allocation',
                    'step_result' => 'success',
                    'duration_ms' => 850,
                ],
                4
            ),
            $this->createDomainEvent(
                $workflowId,
                'WorkflowCompleted',
                [
                    'total_duration_ms' => 2100,
                    'completed_at' => now()->addMinutes(5)->toISOString(),
                    'final_status' => 'success',
                ],
                5
            ),
        ];
    }

    /**
     * Create events with large payloads for performance testing
     */
    public function createLargePayloadEvents(AggregateIdInterface $aggregateId, int $count = 5): array
    {
        $events = [];

        for ($i = 1; $i <= $count; $i++) {
            // Create large payload (simulating real-world complex events)
            $largePayload = [
                'document_content' => str_repeat('Lorem ipsum dolor sit amet. ', 100),
                'attachments' => array_fill(0, 10, [
                    'id' => Uuid::uuid4()->toString(),
                    'filename' => 'document_' . $i . '.pdf',
                    'content_type' => 'application/pdf',
                    'size_bytes' => random_int(10000, 100000),
                    'metadata' => array_fill(0, 20, 'data_' . bin2hex(random_bytes(4))),
                ]),
                'processing_history' => array_fill(0, 25, [
                    'step' => 'processing_step_' . bin2hex(random_bytes(2)),
                    'timestamp' => now()->subMinutes(rand(1, 60))->toISOString(),
                    'result' => 'success',
                    'details' => str_repeat('x', 50),
                ]),
            ];

            $events[] = $this->createDomainEvent(
                $aggregateId,
                'LargeDataEvent',
                $largePayload,
                $i
            );
        }

        return $events;
    }

    /**
     * Create a test aggregate ID
     */
    public function createAggregateId(string $prefix = 'test'): AggregateIdInterface
    {
        return new class($prefix . '_' . Uuid::uuid4()->toString()) implements AggregateIdInterface {
            public function __construct(private string $id) {}

            public function toString(): string
            {
                return $this->id;
            }

            public function equals(AggregateIdInterface $other): bool
            {
                return $this->id === $other->toString();
            }

            public static function generate(): static
            {
                return new static('generated_' . Uuid::uuid4()->toString());
            }

            public static function fromString(string $id): static
            {
                return new static($id);
            }
        };
    }
}