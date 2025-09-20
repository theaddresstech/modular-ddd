<?php

declare(strict_types=1);

namespace LaravelModularDDD\Tests\Integration\Support;

use LaravelModularDDD\Core\Domain\Contracts\AggregateIdInterface;
use LaravelModularDDD\Core\Domain\Contracts\DomainEventInterface;
use LaravelModularDDD\CQRS\Contracts\CommandInterface;
use LaravelModularDDD\CQRS\Contracts\QueryInterface;
use Ramsey\Uuid\Uuid;
use Faker\Factory as FakerFactory;
use Faker\Generator as Faker;

/**
 * Centralized test data generator for integration tests.
 *
 * Provides realistic test data generation for:
 * - Domain events and aggregates
 * - Commands and queries
 * - Messages and notifications
 * - Performance test datasets
 * - Error scenarios and edge cases
 */
class TestDataGenerator
{
    private Faker $faker;

    public function __construct()
    {
        $this->faker = FakerFactory::create();
    }

    /**
     * Generate a realistic user aggregate with events
     */
    public function generateUserAggregate(array $overrides = []): array
    {
        $userId = $overrides['user_id'] ?? $this->generateAggregateId('user');
        $email = $overrides['email'] ?? $this->faker->email;
        $name = $overrides['name'] ?? $this->faker->name;

        $events = [
            $this->generateDomainEvent($userId, 'UserRegistered', [
                'email' => $email,
                'name' => $name,
                'registered_at' => now()->toISOString(),
                'registration_source' => 'web',
                'email_verified' => false,
            ], 1),

            $this->generateDomainEvent($userId, 'UserEmailVerified', [
                'email' => $email,
                'verified_at' => now()->addMinutes(15)->toISOString(),
                'verification_token' => bin2hex(random_bytes(16)),
            ], 2),

            $this->generateDomainEvent($userId, 'UserProfileCompleted', [
                'profile_data' => [
                    'bio' => $this->faker->paragraph,
                    'avatar_url' => $this->faker->imageUrl(200, 200, 'people'),
                    'timezone' => $this->faker->timezone,
                    'language' => 'en',
                ],
                'completed_at' => now()->addHours(1)->toISOString(),
            ], 3),
        ];

        return [
            'aggregate_id' => $userId,
            'aggregate_type' => 'User',
            'events' => $events,
            'current_version' => count($events),
            'snapshot_data' => $this->generateUserSnapshotData($userId, $email, $name),
        ];
    }

    /**
     * Generate a realistic order aggregate with events
     */
    public function generateOrderAggregate(array $overrides = []): array
    {
        $orderId = $overrides['order_id'] ?? $this->generateAggregateId('order');
        $customerId = $overrides['customer_id'] ?? $this->generateAggregateId('user');
        $items = $overrides['items'] ?? $this->generateOrderItems();

        $totalAmount = array_sum(array_map(fn($item) => $item['total_price'], $items));

        $events = [
            $this->generateDomainEvent($orderId, 'OrderCreated', [
                'customer_id' => $customerId->toString(),
                'order_number' => 'ORD-' . strtoupper(bin2hex(random_bytes(4))),
                'currency' => 'USD',
                'created_at' => now()->toISOString(),
            ], 1),
        ];

        // Add item events
        $version = 2;
        foreach ($items as $item) {
            $events[] = $this->generateDomainEvent($orderId, 'OrderItemAdded', $item, $version++);
        }

        // Add shipping and payment events
        $events[] = $this->generateDomainEvent($orderId, 'OrderShippingAddressSet', [
            'address' => $this->generateShippingAddress(),
        ], $version++);

        $events[] = $this->generateDomainEvent($orderId, 'OrderPaymentProcessed', [
            'payment_method' => 'credit_card',
            'amount' => $totalAmount,
            'currency' => 'USD',
            'transaction_id' => 'txn_' . bin2hex(random_bytes(8)),
            'processed_at' => now()->addMinutes(5)->toISOString(),
        ], $version++);

        $events[] = $this->generateDomainEvent($orderId, 'OrderConfirmed', [
            'confirmed_at' => now()->addMinutes(6)->toISOString(),
            'estimated_delivery' => now()->addDays(3)->toISOString(),
        ], $version++);

        return [
            'aggregate_id' => $orderId,
            'aggregate_type' => 'Order',
            'events' => $events,
            'current_version' => count($events),
            'total_amount' => $totalAmount,
            'customer_id' => $customerId,
            'items' => $items,
        ];
    }

    /**
     * Generate test commands for CQRS testing
     */
    public function generateTestCommands(int $count = 10): array
    {
        $commands = [];

        for ($i = 0; $i < $count; $i++) {
            $commands[] = $this->generateCommand($this->faker->randomElement([
                'CreateUser',
                'UpdateUser',
                'CreateOrder',
                'UpdateOrder',
                'ProcessPayment',
                'SendNotification',
            ]));
        }

        return $commands;
    }

    /**
     * Generate test queries for CQRS testing
     */
    public function generateTestQueries(int $count = 10): array
    {
        $queries = [];

        for ($i = 0; $i < $count; $i++) {
            $queries[] = $this->generateQuery($this->faker->randomElement([
                'GetUser',
                'GetOrder',
                'SearchUsers',
                'SearchOrders',
                'GetAnalytics',
                'GetReports',
            ]));
        }

        return $queries;
    }

    /**
     * Generate performance test dataset
     */
    public function generatePerformanceDataset(int $userCount = 1000, int $ordersPerUser = 5): array
    {
        $dataset = [
            'users' => [],
            'orders' => [],
            'events' => [],
            'metrics' => [
                'total_users' => $userCount,
                'total_orders' => $userCount * $ordersPerUser,
                'estimated_events' => $userCount * 3 + ($userCount * $ordersPerUser * 6),
            ],
        ];

        // Generate users
        for ($i = 0; $i < $userCount; $i++) {
            $user = $this->generateUserAggregate([
                'email' => "testuser{$i}@example.com",
                'name' => "Test User {$i}",
            ]);

            $dataset['users'][] = $user;
            $dataset['events'] = array_merge($dataset['events'], $user['events']);

            // Generate orders for this user
            for ($j = 0; $j < $ordersPerUser; $j++) {
                $order = $this->generateOrderAggregate([
                    'customer_id' => $user['aggregate_id'],
                ]);

                $dataset['orders'][] = $order;
                $dataset['events'] = array_merge($dataset['events'], $order['events']);
            }
        }

        return $dataset;
    }

    /**
     * Generate error scenarios for testing error handling
     */
    public function generateErrorScenarios(): array
    {
        return [
            'validation_error' => [
                'type' => 'ValidationException',
                'message' => 'Invalid input data',
                'data' => ['field' => '', 'errors' => ['field is required']],
            ],
            'not_found_error' => [
                'type' => 'NotFoundException',
                'message' => 'Resource not found',
                'data' => ['resource_id' => $this->generateAggregateId()->toString()],
            ],
            'concurrency_error' => [
                'type' => 'ConcurrencyException',
                'message' => 'Optimistic lock failed',
                'data' => ['expected_version' => 5, 'actual_version' => 7],
            ],
            'timeout_error' => [
                'type' => 'TimeoutException',
                'message' => 'Operation timed out',
                'data' => ['timeout_ms' => 5000, 'operation' => 'database_query'],
            ],
            'service_unavailable' => [
                'type' => 'ServiceUnavailableException',
                'message' => 'External service is unavailable',
                'data' => ['service' => 'payment_gateway', 'retry_after' => 60],
            ],
        ];
    }

    /**
     * Generate realistic message patterns for module communication
     */
    public function generateModuleMessages(string $sourceModule, string $targetModule, int $count = 5): array
    {
        $messages = [];

        $messageTypes = [
            'user' => ['UserCreated', 'UserUpdated', 'UserDeleted', 'UserEmailVerified'],
            'order' => ['OrderCreated', 'OrderConfirmed', 'OrderShipped', 'OrderDelivered'],
            'notification' => ['EmailSent', 'SMSSent', 'PushNotificationSent'],
            'payment' => ['PaymentProcessed', 'PaymentFailed', 'RefundProcessed'],
        ];

        $availableTypes = $messageTypes[$sourceModule] ?? ['GenericMessage'];

        for ($i = 0; $i < $count; $i++) {
            $messageType = $this->faker->randomElement($availableTypes);

            $messages[] = [
                'message_id' => Uuid::uuid4()->toString(),
                'source_module' => $sourceModule,
                'target_module' => $targetModule,
                'message_type' => $messageType,
                'payload' => $this->generateMessagePayload($messageType),
                'metadata' => [
                    'correlation_id' => Uuid::uuid4()->toString(),
                    'causation_id' => Uuid::uuid4()->toString(),
                    'timestamp' => now()->toISOString(),
                    'version' => '1.0',
                ],
                'priority' => $this->faker->randomElement(['low', 'normal', 'high']),
                'retry_policy' => [
                    'max_attempts' => 3,
                    'delay_ms' => 1000,
                    'backoff_multiplier' => 2,
                ],
            ];
        }

        return $messages;
    }

    /**
     * Generate a domain event
     */
    public function generateDomainEvent(
        AggregateIdInterface $aggregateId,
        string $eventType,
        array $data,
        int $version
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
                    'source' => 'test-generator',
                    'version' => '1.0',
                ];
            }

            public function getAggregateId(): AggregateIdInterface { return $this->aggregateId; }
            public function getEventId(): string { return $this->metadata['event_id']; }
            public function getEventType(): string { return $this->eventType; }
            public function getEventData(): array { return $this->data; }
            public function getPayload(): array { return $this->data; }
            public function getVersion(): int { return $this->version; }
            public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
            public function getEventVersion(): int { return 1; }
            public function getMetadata(): array { return $this->metadata; }

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
        };
    }

    /**
     * Generate an aggregate ID
     */
    public function generateAggregateId(string $prefix = 'test'): AggregateIdInterface
    {
        return new class($prefix . '_' . Uuid::uuid4()->toString()) implements AggregateIdInterface {
            public function __construct(private string $id) {}
            public function toString(): string { return $this->id; }
            public function equals(AggregateIdInterface $other): bool {
                return $this->id === $other->toString();
            }
            public static function generate(): static {
                return new static('generated_' . Uuid::uuid4()->toString());
            }
            public static function fromString(string $id): static {
                return new static($id);
            }
        };
    }

    /**
     * Generate a command
     */
    private function generateCommand(string $commandType): CommandInterface
    {
        return new class($commandType, $this->generateCommandPayload($commandType)) implements CommandInterface {
            public function __construct(
                private string $commandType,
                private array $payload
            ) {}

            public function getCommandName(): string { return $this->commandType; }
            public function getCommandId(): string { return Uuid::uuid4()->toString(); }
            public function getPayload(): array { return $this->payload; }
            public function getMetadata(): array {
                return [
                    'created_at' => now()->toISOString(),
                    'source' => 'test-generator',
                ];
            }
            public function isAsync(): bool { return false; }
            public function getPriority(): int { return 0; }
        };
    }

    /**
     * Generate a query
     */
    private function generateQuery(string $queryType): QueryInterface
    {
        return new class($queryType, $this->generateQueryParameters($queryType)) implements QueryInterface {
            public function __construct(
                private string $queryType,
                private array $parameters
            ) {}

            public function getQueryName(): string { return $this->queryType; }
            public function getQueryId(): string { return Uuid::uuid4()->toString(); }
            public function getParameters(): array { return $this->parameters; }
            public function shouldCache(): bool { return true; }
            public function getCacheKey(): string { return $this->queryType . '_' . md5(serialize($this->parameters)); }
            public function getCacheTags(): array { return [strtolower($this->queryType)]; }
            public function getCacheTtl(): int { return 3600; }
            public function getComplexity(): int { return rand(1, 5); }
            public function isPaginated(): bool { return isset($this->parameters['page']); }
            public function getPerPage(): int { return $this->parameters['per_page'] ?? 20; }
        };
    }

    private function generateUserSnapshotData(AggregateIdInterface $userId, string $email, string $name): array
    {
        return [
            'id' => $userId->toString(),
            'email' => $email,
            'name' => $name,
            'email_verified' => true,
            'profile' => [
                'bio' => $this->faker->paragraph,
                'avatar_url' => $this->faker->imageUrl(200, 200, 'people'),
                'timezone' => $this->faker->timezone,
            ],
            'preferences' => [
                'language' => 'en',
                'theme' => 'light',
                'notifications' => true,
            ],
            'statistics' => [
                'login_count' => rand(1, 100),
                'last_login' => now()->subDays(rand(1, 30))->toISOString(),
            ],
        ];
    }

    private function generateOrderItems(): array
    {
        $itemCount = rand(1, 5);
        $items = [];

        for ($i = 0; $i < $itemCount; $i++) {
            $quantity = rand(1, 3);
            $unitPrice = $this->faker->randomFloat(2, 10, 100);

            $items[] = [
                'product_id' => 'prod_' . bin2hex(random_bytes(4)),
                'product_name' => $this->faker->words(3, true),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $quantity * $unitPrice,
                'sku' => strtoupper(bin2hex(random_bytes(3))),
            ];
        }

        return $items;
    }

    private function generateShippingAddress(): array
    {
        return [
            'street_address' => $this->faker->streetAddress,
            'city' => $this->faker->city,
            'state' => $this->faker->state,
            'postal_code' => $this->faker->postcode,
            'country' => $this->faker->countryCode,
            'recipient_name' => $this->faker->name,
            'phone' => $this->faker->phoneNumber,
        ];
    }

    private function generateCommandPayload(string $commandType): array
    {
        $payloads = [
            'CreateUser' => [
                'email' => $this->faker->email,
                'name' => $this->faker->name,
                'password' => 'password123',
            ],
            'UpdateUser' => [
                'user_id' => $this->generateAggregateId()->toString(),
                'name' => $this->faker->name,
                'bio' => $this->faker->paragraph,
            ],
            'CreateOrder' => [
                'customer_id' => $this->generateAggregateId()->toString(),
                'items' => $this->generateOrderItems(),
            ],
        ];

        return $payloads[$commandType] ?? ['data' => 'test_data'];
    }

    private function generateQueryParameters(string $queryType): array
    {
        $parameters = [
            'GetUser' => [
                'user_id' => $this->generateAggregateId()->toString(),
            ],
            'SearchUsers' => [
                'query' => $this->faker->word,
                'page' => 1,
                'per_page' => 20,
            ],
            'GetAnalytics' => [
                'start_date' => now()->subDays(30)->toDateString(),
                'end_date' => now()->toDateString(),
                'metrics' => ['revenue', 'orders', 'users'],
            ],
        ];

        return $parameters[$queryType] ?? ['filter' => 'all'];
    }

    private function generateMessagePayload(string $messageType): array
    {
        $payloads = [
            'UserCreated' => [
                'user_id' => $this->generateAggregateId()->toString(),
                'email' => $this->faker->email,
                'name' => $this->faker->name,
            ],
            'OrderCreated' => [
                'order_id' => $this->generateAggregateId()->toString(),
                'customer_id' => $this->generateAggregateId()->toString(),
                'total_amount' => $this->faker->randomFloat(2, 10, 500),
            ],
            'PaymentProcessed' => [
                'payment_id' => $this->generateAggregateId()->toString(),
                'order_id' => $this->generateAggregateId()->toString(),
                'amount' => $this->faker->randomFloat(2, 10, 500),
                'status' => 'completed',
            ],
        ];

        return $payloads[$messageType] ?? ['data' => 'test_message_data'];
    }
}