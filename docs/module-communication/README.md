# Module Communication Guide

This guide covers inter-module communication patterns, event-driven architecture, and the module bus implementation in the Laravel Modular DDD package.

## Table of Contents

- [Communication Patterns](#communication-patterns)
- [Module Bus Implementation](#module-bus-implementation)
- [Event-Driven Communication](#event-driven-communication)
- [Direct Module Messaging](#direct-module-messaging)
- [Async Processing](#async-processing)
- [Circuit Breaker Pattern](#circuit-breaker-pattern)
- [Error Handling and Resilience](#error-handling-and-resilience)
- [Performance Considerations](#performance-considerations)
- [Best Practices](#best-practices)

## Communication Patterns

The package supports multiple communication patterns between modules:

### 1. Event-Driven Communication (Recommended)

**When to use**: Loose coupling, eventual consistency, scalable architectures

```php
// UserManagement module publishes event
class UserCreated implements DomainEventInterface
{
    public function __construct(
        public readonly UserId $userId,
        public readonly Email $email,
        public readonly string $name,
        public readonly \DateTimeImmutable $occurredAt
    ) {}
}

// OrderManagement module listens to event
class CreateCustomerProfileListener
{
    public function handle(UserCreated $event): void
    {
        // Create customer profile when user is created
        $this->customerService->createProfile(
            $event->userId,
            $event->email,
            $event->name
        );
    }
}
```

### 2. Direct Module Messaging

**When to use**: Immediate consistency, synchronous operations, request-response patterns

```php
// Send command to another module
$moduleBus = app(ModuleBusInterface::class);

$result = $moduleBus->sendCommand(
    'inventory', 
    new ReserveItems([
        'product_id' => 'PROD-123',
        'quantity' => 2
    ])
);

// Query another module
$customer = $moduleBus->sendQuery(
    'customer',
    new GetCustomer($customerId)
);
```

### 3. Saga Pattern for Complex Workflows

**When to use**: Long-running processes, distributed transactions, error recovery

```php
class OrderProcessingSaga
{
    public function handle(OrderCreated $event): void
    {
        $this->step1_ReserveInventory($event);
    }
    
    public function handle(InventoryReserved $event): void
    {
        $this->step2_ProcessPayment($event);
    }
    
    public function handle(PaymentProcessed $event): void
    {
        $this->step3_ShipOrder($event);
    }
    
    // Compensation handlers
    public function handle(PaymentFailed $event): void
    {
        $this->compensate_ReleaseInventory($event);
    }
}
```

## Module Bus Implementation

The Module Bus provides a unified interface for inter-module communication:

### Module Bus Interface

```php
interface ModuleBusInterface
{
    /**
     * Send a command to another module
     */
    public function sendCommand(string $module, CommandInterface $command): mixed;
    
    /**
     * Send a command asynchronously
     */
    public function sendCommandAsync(string $module, CommandInterface $command): string;
    
    /**
     * Send a query to another module
     */
    public function sendQuery(string $module, QueryInterface $query): mixed;
    
    /**
     * Publish an event to all interested modules
     */
    public function publishEvent(DomainEventInterface $event): void;
    
    /**
     * Subscribe to events from other modules
     */
    public function subscribe(string $eventClass, callable $handler): void;
    
    /**
     * Get module health status
     */
    public function getModuleHealth(string $module): ModuleHealthStatus;
}
```

### Module Bus Implementation

```php
class ModuleBus implements ModuleBusInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private EventDispatcher $eventDispatcher,
        private QueueManager $queueManager,
        private LoggerInterface $logger,
        private array $config
    ) {}
    
    public function sendCommand(string $module, CommandInterface $command): mixed
    {
        try {
            $this->validateModuleAccess($module, $command);
            
            // Log the inter-module communication
            $this->logger->info('Sending command to module', [
                'target_module' => $module,
                'command' => get_class($command),
                'source_module' => $this->getCurrentModule(),
            ]);
            
            // Route to the target module's command bus
            return $this->routeCommand($module, $command);
            
        } catch (\Throwable $e) {
            $this->handleCommunicationError($module, $command, $e);
            throw $e;
        }
    }
    
    public function sendQuery(string $module, QueryInterface $query): mixed
    {
        try {
            $this->validateModuleAccess($module, $query);
            
            // Check circuit breaker
            if ($this->isCircuitOpen($module)) {
                throw new ModuleUnavailableException(
                    "Module {$module} is currently unavailable"
                );
            }
            
            return $this->routeQuery($module, $query);
            
        } catch (\Throwable $e) {
            $this->recordFailure($module);
            throw $e;
        }
    }
    
    public function publishEvent(DomainEventInterface $event): void
    {
        // Get all modules interested in this event
        $interestedModules = $this->getInterestedModules($event);
        
        foreach ($interestedModules as $module) {
            if ($this->config['async_processing']['enabled']) {
                // Async processing
                $this->dispatchEventAsync($module, $event);
            } else {
                // Sync processing
                $this->dispatchEventSync($module, $event);
            }
        }
    }
    
    private function routeCommand(string $module, CommandInterface $command): mixed
    {
        // Get module's command bus instance
        $moduleCommandBus = $this->getModuleCommandBus($module);
        
        // Execute with timeout
        return $this->executeWithTimeout(
            fn() => $moduleCommandBus->dispatch($command),
            $this->config['default_timeout']
        );
    }
    
    private function routeQuery(string $module, QueryInterface $query): mixed
    {
        // Get module's query bus instance
        $moduleQueryBus = $this->getModuleQueryBus($module);
        
        // Execute with timeout
        return $this->executeWithTimeout(
            fn() => $moduleQueryBus->ask($query),
            $this->config['default_timeout']
        );
    }
}
```

### Module Registration

```php
class ModuleRegistry
{
    private array $modules = [];
    
    public function register(string $name, ModuleDefinition $module): void
    {
        $this->modules[$name] = $module;
    }
    
    public function getModule(string $name): ?ModuleDefinition
    {
        return $this->modules[$name] ?? null;
    }
    
    public function getInterestedModules(string $eventClass): array
    {
        $interested = [];
        
        foreach ($this->modules as $name => $module) {
            if ($module->isInterestedInEvent($eventClass)) {
                $interested[] = $name;
            }
        }
        
        return $interested;
    }
}

class ModuleDefinition
{
    public function __construct(
        private string $name,
        private array $eventSubscriptions,
        private array $providedServices,
        private array $dependencies
    ) {}
    
    public function isInterestedInEvent(string $eventClass): bool
    {
        return in_array($eventClass, $this->eventSubscriptions) ||
               $this->hasWildcardSubscription($eventClass);
    }
    
    private function hasWildcardSubscription(string $eventClass): bool
    {
        foreach ($this->eventSubscriptions as $subscription) {
            if (str_ends_with($subscription, '*')) {
                $pattern = str_replace('*', '', $subscription);
                if (str_starts_with($eventClass, $pattern)) {
                    return true;
                }
            }
        }
        
        return false;
    }
}
```

## Event-Driven Communication

### Event Publishing

```php
class EventPublisher
{
    public function publish(DomainEventInterface $event): void
    {
        // Local event dispatch (same application)
        event($event);
        
        // Inter-module event dispatch
        $this->moduleBus->publishEvent($event);
        
        // External event dispatch (if configured)
        if ($this->shouldPublishExternally($event)) {
            $this->publishToExternalBus($event);
        }
    }
    
    private function shouldPublishExternally(DomainEventInterface $event): bool
    {
        return in_array(get_class($event), [
            UserCreated::class,
            OrderCompleted::class,
            PaymentProcessed::class,
        ]);
    }
    
    private function publishToExternalBus(DomainEventInterface $event): void
    {
        // Publish to message broker (RabbitMQ, AWS SQS, etc.)
        $this->externalBus->publish([
            'event_type' => get_class($event),
            'event_data' => $this->serializer->serialize($event),
            'occurred_at' => $event->occurredAt()->format('c'),
            'source_module' => $this->getCurrentModule(),
        ]);
    }
}
```

### Event Subscription

```php
class ModuleEventSubscriber
{
    public function __construct(
        private ModuleBusInterface $moduleBus
    ) {
        $this->registerSubscriptions();
    }
    
    private function registerSubscriptions(): void
    {
        // Subscribe to events from other modules
        $this->moduleBus->subscribe(
            UserCreated::class,
            [$this, 'handleUserCreated']
        );
        
        $this->moduleBus->subscribe(
            OrderCreated::class,
            [$this, 'handleOrderCreated']
        );
        
        // Wildcard subscriptions
        $this->moduleBus->subscribe(
            'App\\UserManagement\\Events\\*',
            [$this, 'handleUserEvents']
        );
    }
    
    public function handleUserCreated(UserCreated $event): void
    {
        // Handle user creation in this module
        Log::info('User created in another module', [
            'user_id' => $event->userId->toString(),
            'email' => $event->email->toString(),
        ]);
        
        // Trigger local business logic
        $this->createCustomerProfile($event);
    }
    
    public function handleOrderCreated(OrderCreated $event): void
    {
        // Update analytics when order is created
        $this->updateSalesMetrics($event);
        $this->updateCustomerInsights($event);
    }
}
```

### Event Routing Configuration

```php
'module_communication' => [
    'events' => [
        'routing' => [
            // Map events to interested modules
            UserCreated::class => ['customer', 'analytics', 'notification'],
            OrderCreated::class => ['inventory', 'billing', 'shipping'],
            PaymentProcessed::class => ['accounting', 'analytics'],
        ],
        
        'wildcard_routing' => [
            'App\\UserManagement\\Events\\*' => ['analytics'],
            'App\\OrderManagement\\Events\\*' => ['analytics', 'reporting'],
        ],
    ],
],
```

## Direct Module Messaging

### Command Routing

```php
class InterModuleCommandExample
{
    public function __construct(
        private ModuleBusInterface $moduleBus
    ) {}
    
    public function processOrder(ProcessOrderCommand $command): void
    {
        // Step 1: Reserve inventory
        $reservationResult = $this->moduleBus->sendCommand(
            'inventory',
            new ReserveItems([
                'order_id' => $command->orderId,
                'items' => $command->items,
            ])
        );
        
        if (!$reservationResult->isSuccess()) {
            throw new InsufficientInventoryException();
        }
        
        // Step 2: Process payment
        try {
            $paymentResult = $this->moduleBus->sendCommand(
                'payment',
                new ProcessPayment(
                    $command->paymentDetails,
                    $command->amount
                )
            );
            
            if (!$paymentResult->isSuccess()) {
                // Compensate: release reserved inventory
                $this->moduleBus->sendCommand(
                    'inventory',
                    new ReleaseReservation($reservationResult->reservationId)
                );
                
                throw new PaymentFailedException();
            }
            
        } catch (\Throwable $e) {
            // Always compensate on failure
            $this->moduleBus->sendCommand(
                'inventory',
                new ReleaseReservation($reservationResult->reservationId)
            );
            
            throw $e;
        }
        
        // Step 3: Confirm order
        $this->confirmOrder($command->orderId, $paymentResult, $reservationResult);
    }
}
```

### Query Federation

```php
class CustomerDashboardQuery implements QueryInterface
{
    public function __construct(
        public readonly CustomerId $customerId
    ) {}
}

class CustomerDashboardHandler implements QueryHandlerInterface
{
    public function __construct(
        private ModuleBusInterface $moduleBus
    ) {}
    
    public function handle(CustomerDashboardQuery $query): CustomerDashboard
    {
        // Gather data from multiple modules
        $customer = $this->moduleBus->sendQuery(
            'customer',
            new GetCustomer($query->customerId)
        );
        
        $orders = $this->moduleBus->sendQuery(
            'order',
            new GetCustomerOrders($query->customerId, limit: 10)
        );
        
        $loyaltyPoints = $this->moduleBus->sendQuery(
            'loyalty',
            new GetLoyaltyPoints($query->customerId)
        );
        
        $recommendations = $this->moduleBus->sendQuery(
            'recommendation',
            new GetProductRecommendations($query->customerId)
        );
        
        return new CustomerDashboard(
            customer: $customer,
            recentOrders: $orders,
            loyaltyPoints: $loyaltyPoints,
            recommendations: $recommendations
        );
    }
}
```

## Async Processing

### Async Event Processing

```php
class AsyncEventProcessor
{
    public function __construct(
        private QueueManager $queueManager,
        private ModuleBusInterface $moduleBus
    ) {}
    
    public function processEventAsync(string $module, DomainEventInterface $event): void
    {
        $job = new ProcessModuleEvent($module, $event);
        
        $this->queueManager
            ->connection($this->getQueueConnection($module))
            ->pushOn($this->getQueueName($module), $job);
    }
    
    private function getQueueConnection(string $module): string
    {
        return config("modules.{$module}.queue_connection", 'default');
    }
    
    private function getQueueName(string $module): string
    {
        return config('modular-ddd.module_communication.async_processing.queue', 'modules');
    }
}

class ProcessModuleEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public function __construct(
        private string $targetModule,
        private DomainEventInterface $event
    ) {}
    
    public function handle(ModuleBusInterface $moduleBus): void
    {
        try {
            $moduleBus->routeEventToModule($this->targetModule, $this->event);
        } catch (\Throwable $e) {
            Log::error('Failed to process module event', [
                'target_module' => $this->targetModule,
                'event' => get_class($this->event),
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }
    
    public function failed(\Throwable $exception): void
    {
        Log::error('Module event processing failed permanently', [
            'target_module' => $this->targetModule,
            'event' => get_class($this->event),
            'error' => $exception->getMessage(),
        ]);
    }
}
```

### Async Command Processing

```php
class AsyncModuleCommand
{
    public function __construct(
        private ModuleBusInterface $moduleBus
    ) {}
    
    public function sendCommandAsync(
        string $module,
        CommandInterface $command
    ): string {
        $jobId = Str::uuid()->toString();
        
        // Store command status
        $this->storeCommandStatus($jobId, 'pending', $module, $command);
        
        // Dispatch to queue
        ProcessModuleCommand::dispatch($module, $command, $jobId)
            ->onQueue($this->getModuleQueue($module));
        
        return $jobId;
    }
    
    public function getCommandStatus(string $jobId): AsyncCommandStatus
    {
        return $this->retrieveCommandStatus($jobId);
    }
}

class ProcessModuleCommand implements ShouldQueue
{
    public function handle(
        ModuleBusInterface $moduleBus,
        AsyncCommandRepository $repository
    ): void {
        try {
            $repository->updateStatus($this->jobId, 'processing');
            
            $result = $moduleBus->sendCommand($this->module, $this->command);
            
            $repository->complete($this->jobId, $result);
        } catch (\Throwable $e) {
            $repository->fail($this->jobId, $e->getMessage());
            throw $e;
        }
    }
}
```

## Circuit Breaker Pattern

### Circuit Breaker Implementation

```php
class ModuleCircuitBreaker
{
    private array $failures = [];
    private array $lastFailureTime = [];
    private array $circuitState = []; // closed, open, half-open
    
    public function __construct(
        private int $failureThreshold = 5,
        private int $timeoutSeconds = 60,
        private int $halfOpenMaxCalls = 3
    ) {}
    
    public function call(string $module, callable $callback): mixed
    {
        $state = $this->getCircuitState($module);
        
        switch ($state) {
            case 'open':
                if ($this->shouldTryHalfOpen($module)) {
                    $this->circuitState[$module] = 'half-open';
                    return $this->executeHalfOpen($module, $callback);
                }
                
                throw new CircuitBreakerOpenException(
                    "Circuit breaker is open for module: {$module}"
                );
                
            case 'half-open':
                return $this->executeHalfOpen($module, $callback);
                
            default: // closed
                return $this->executeClosed($module, $callback);
        }
    }
    
    private function executeClosed(string $module, callable $callback): mixed
    {
        try {
            $result = $callback();
            $this->recordSuccess($module);
            return $result;
        } catch (\Throwable $e) {
            $this->recordFailure($module);
            
            if ($this->shouldOpenCircuit($module)) {
                $this->openCircuit($module);
            }
            
            throw $e;
        }
    }
    
    private function executeHalfOpen(string $module, callable $callback): mixed
    {
        try {
            $result = $callback();
            $this->closeCircuit($module);
            return $result;
        } catch (\Throwable $e) {
            $this->openCircuit($module);
            throw $e;
        }
    }
    
    private function recordFailure(string $module): void
    {
        $this->failures[$module] = ($this->failures[$module] ?? 0) + 1;
        $this->lastFailureTime[$module] = time();
    }
    
    private function recordSuccess(string $module): void
    {
        $this->failures[$module] = 0;
    }
    
    private function shouldOpenCircuit(string $module): bool
    {
        return ($this->failures[$module] ?? 0) >= $this->failureThreshold;
    }
    
    private function shouldTryHalfOpen(string $module): bool
    {
        $lastFailure = $this->lastFailureTime[$module] ?? 0;
        return (time() - $lastFailure) >= $this->timeoutSeconds;
    }
    
    private function openCircuit(string $module): void
    {
        $this->circuitState[$module] = 'open';
        $this->lastFailureTime[$module] = time();
        
        Log::warning("Circuit breaker opened for module: {$module}");
    }
    
    private function closeCircuit(string $module): void
    {
        $this->circuitState[$module] = 'closed';
        $this->failures[$module] = 0;
        
        Log::info("Circuit breaker closed for module: {$module}");
    }
}
```

### Circuit Breaker Integration

```php
class ResilientModuleBus implements ModuleBusInterface
{
    public function __construct(
        private ModuleBus $moduleBus,
        private ModuleCircuitBreaker $circuitBreaker
    ) {}
    
    public function sendCommand(string $module, CommandInterface $command): mixed
    {
        return $this->circuitBreaker->call(
            $module,
            fn() => $this->moduleBus->sendCommand($module, $command)
        );
    }
    
    public function sendQuery(string $module, QueryInterface $query): mixed
    {
        return $this->circuitBreaker->call(
            $module,
            fn() => $this->moduleBus->sendQuery($module, $query)
        );
    }
}
```

## Error Handling and Resilience

### Retry Mechanism

```php
class RetryableModuleBus implements ModuleBusInterface
{
    public function __construct(
        private ModuleBusInterface $moduleBus,
        private int $maxRetries = 3,
        private int $retryDelayMs = 100
    ) {}
    
    public function sendCommand(string $module, CommandInterface $command): mixed
    {
        $attempt = 0;
        
        while ($attempt <= $this->maxRetries) {
            try {
                return $this->moduleBus->sendCommand($module, $command);
            } catch (TemporaryException $e) {
                $attempt++;
                
                if ($attempt > $this->maxRetries) {
                    throw $e;
                }
                
                // Exponential backoff
                $delay = $this->retryDelayMs * (2 ** ($attempt - 1));
                usleep($delay * 1000);
                
                Log::info("Retrying command to module {$module}", [
                    'attempt' => $attempt,
                    'delay_ms' => $delay,
                    'command' => get_class($command),
                ]);
            } catch (PermanentException $e) {
                // Don't retry permanent failures
                throw $e;
            }
        }
    }
}
```

### Dead Letter Queue

```php
class DeadLetterHandler
{
    public function handleFailedEvent(
        string $module,
        DomainEventInterface $event,
        \Throwable $exception
    ): void {
        // Store in dead letter queue for manual review
        DB::table('dead_letter_events')->insert([
            'target_module' => $module,
            'event_type' => get_class($event),
            'event_data' => json_encode($this->serializer->serialize($event)),
            'error_message' => $exception->getMessage(),
            'error_trace' => $exception->getTraceAsString(),
            'failed_at' => now(),
            'retry_count' => 0,
        ]);
        
        // Alert operations team
        $this->alerting->sendAlert([
            'type' => 'dead_letter_event',
            'module' => $module,
            'event' => get_class($event),
            'error' => $exception->getMessage(),
        ]);
    }
    
    public function retryDeadLetterEvents(): void
    {
        $events = DB::table('dead_letter_events')
            ->where('retry_count', '<', 3)
            ->where('created_at', '>', now()->subHours(24))
            ->get();
        
        foreach ($events as $deadEvent) {
            try {
                $event = $this->serializer->deserialize(
                    json_decode($deadEvent->event_data, true)
                );
                
                $this->moduleBus->publishEvent($event);
                
                // Remove from dead letter queue
                DB::table('dead_letter_events')
                    ->where('id', $deadEvent->id)
                    ->delete();
                    
            } catch (\Throwable $e) {
                // Increment retry count
                DB::table('dead_letter_events')
                    ->where('id', $deadEvent->id)
                    ->increment('retry_count');
            }
        }
    }
}
```

## Performance Considerations

### Connection Pooling

```php
class ModuleConnectionPool
{
    private array $connections = [];
    private int $maxConnections = 10;
    
    public function getConnection(string $module): ModuleConnection
    {
        if (!isset($this->connections[$module])) {
            $this->connections[$module] = new \SplQueue();
        }
        
        $pool = $this->connections[$module];
        
        if (!$pool->isEmpty()) {
            return $pool->dequeue();
        }
        
        if (count($this->connections[$module]) < $this->maxConnections) {
            return $this->createConnection($module);
        }
        
        // Wait for available connection
        return $this->waitForConnection($module);
    }
    
    public function releaseConnection(string $module, ModuleConnection $connection): void
    {
        if ($connection->isHealthy()) {
            $this->connections[$module]->enqueue($connection);
        }
    }
}
```

### Batching Operations

```php
class BatchModuleOperations
{
    public function batchCommands(array $commands): array
    {
        $groupedByModule = [];
        
        // Group commands by target module
        foreach ($commands as $command) {
            $module = $this->getTargetModule($command);
            $groupedByModule[$module][] = $command;
        }
        
        $results = [];
        
        // Execute batches in parallel
        foreach ($groupedByModule as $module => $moduleCommands) {
            $batchResult = $this->executeBatch($module, $moduleCommands);
            $results = array_merge($results, $batchResult);
        }
        
        return $results;
    }
    
    private function executeBatch(string $module, array $commands): array
    {
        // Create batch command
        $batchCommand = new BatchCommand($commands);
        
        return $this->moduleBus->sendCommand($module, $batchCommand);
    }
}
```

## Best Practices

### 1. Event Design for Inter-Module Communication

```php
// ✅ Good: Self-contained, immutable, versioned
class OrderCompletedV2 implements DomainEventInterface
{
    public function __construct(
        public readonly OrderId $orderId,
        public readonly CustomerId $customerId,
        public readonly Money $totalAmount,
        public readonly array $items, // Complete item information
        public readonly \DateTimeImmutable $completedAt,
        public readonly int $version = 2
    ) {}
    
    // Include all data that other modules might need
    public function getCustomerEmail(): Email
    {
        return $this->customerEmail;
    }
    
    public function getShippingAddress(): Address
    {
        return $this->shippingAddress;
    }
}
```

### 2. Module Interface Contracts

```php
// Define clear contracts between modules
interface InventoryModuleInterface
{
    public function reserveItems(ReserveItemsCommand $command): ReservationResult;
    public function releaseReservation(ReleaseReservationCommand $command): void;
    public function checkAvailability(CheckAvailabilityQuery $query): AvailabilityResult;
}

// Implement in each module
class InventoryModule implements InventoryModuleInterface
{
    public function reserveItems(ReserveItemsCommand $command): ReservationResult
    {
        // Implementation
    }
}
```

### 3. Error Handling Strategies

```php
class RobustModuleCommunication
{
    public function sendCommandWithFallback(
        string $module,
        CommandInterface $command,
        ?callable $fallback = null
    ): mixed {
        try {
            return $this->moduleBus->sendCommand($module, $command);
        } catch (ModuleUnavailableException $e) {
            if ($fallback) {
                Log::warning("Module {$module} unavailable, using fallback");
                return $fallback($command);
            }
            throw $e;
        } catch (TemporaryException $e) {
            // Queue for later retry
            $this->queueForRetry($module, $command);
            throw $e;
        }
    }
}
```

### 4. Configuration Management

```php
'module_communication' => [
    'enabled' => env('MODULE_COMMUNICATION_ENABLED', true),
    'default_timeout' => env('MODULE_MESSAGE_TIMEOUT', 30),
    'default_retries' => env('MODULE_MESSAGE_RETRIES', 3),
    
    'modules' => [
        'user' => [
            'timeout' => 10,
            'circuit_breaker' => [
                'failure_threshold' => 5,
                'timeout_seconds' => 60,
            ],
        ],
        'inventory' => [
            'timeout' => 5,
            'retries' => 5, // Critical for order processing
        ],
        'payment' => [
            'timeout' => 30, // Payment processing can be slow
            'circuit_breaker' => [
                'failure_threshold' => 3, // Low threshold for payments
            ],
        ],
    ],
    
    'routing' => [
        'strict_mode' => env('MODULE_ROUTING_STRICT', false),
        'allow_wildcards' => true,
        'log_undelivered' => true,
    ],
],
```

### 5. Testing Inter-Module Communication

```php
class ModuleCommunicationTest extends TestCase
{
    public function test_order_processing_saga(): void
    {
        // Mock modules
        $this->mockModule('inventory')
            ->shouldReceive('reserveItems')
            ->andReturn(new ReservationResult(true, 'RES-123'));
            
        $this->mockModule('payment')
            ->shouldReceive('processPayment')
            ->andReturn(new PaymentResult(true, 'PAY-456'));
        
        // Execute saga
        $result = $this->moduleBus->sendCommand(
            'order',
            new ProcessOrder($this->orderData)
        );
        
        // Verify interactions
        $this->assertModuleCommandSent('inventory', ReserveItems::class);
        $this->assertModuleCommandSent('payment', ProcessPayment::class);
        $this->assertTrue($result->isSuccess());
    }
    
    public function test_handles_module_failure_gracefully(): void
    {
        $this->mockModule('inventory')
            ->shouldReceive('reserveItems')
            ->andThrow(new ModuleUnavailableException());
        
        $this->expectException(OrderProcessingException::class);
        
        $this->moduleBus->sendCommand(
            'order',
            new ProcessOrder($this->orderData)
        );
    }
}
```

This comprehensive guide covers all aspects of module communication in the Laravel Modular DDD package, from basic event-driven patterns to advanced resilience mechanisms.
