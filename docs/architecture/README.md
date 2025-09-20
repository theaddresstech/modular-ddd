# Architecture Overview

The Laravel Modular DDD package implements a sophisticated architecture that combines Domain-Driven Design (DDD) principles with Event Sourcing and Command Query Responsibility Segregation (CQRS) patterns. This architecture is designed to scale from small applications to enterprise-level systems.

## Table of Contents

- [Core Architecture Principles](#core-architecture-principles)
- [System Components](#system-components)
- [Event Sourcing Architecture](#event-sourcing-architecture)
- [CQRS Implementation](#cqrs-implementation)
- [Module Organization](#module-organization)
- [Data Flow](#data-flow)
- [Performance Considerations](#performance-considerations)

## Core Architecture Principles

### Domain-Driven Design (DDD)

The architecture follows DDD principles with clear separation of concerns:

```
Modules/
│
├── UserManagement/
│   ├── Domain/           # Business logic and rules
│   │   ├── Aggregates/
│   │   ├── Entities/
│   │   ├── ValueObjects/
│   │   ├── Events/
│   │   └── Services/
│   │
│   ├── Application/      # Use cases and orchestration
│   │   ├── Commands/
│   │   ├── Queries/
│   │   ├── Handlers/
│   │   └── Projectors/
│   │
│   ├── Infrastructure/   # Technical implementations
│   │   ├── Repositories/
│   │   ├── Services/
│   │   └── Projections/
│   │
│   └── Presentation/     # API controllers and views
│       ├── Controllers/
│       ├── Resources/
│       └── Requests/
│
└── OrderManagement/
    │ ...
```

### Event-Driven Architecture

Modules communicate through domain events, ensuring loose coupling:

```php
// When a user is created in UserManagement module
class UserCreated implements DomainEventInterface
{
    public function __construct(
        public readonly UserId $userId,
        public readonly string $email,
        public readonly \DateTimeImmutable $occurredAt
    ) {}
}

// Other modules can listen to this event
class OrderManagementSubscriber
{
    public function handle(UserCreated $event): void
    {
        // Create customer profile in order management
    }
}
```

## System Components

### 1. Event Sourcing Infrastructure

**Tiered Event Store**
- **Hot Tier (Redis)**: Recently accessed events for optimal performance
- **Warm Tier (MySQL)**: Long-term storage with full durability
- **Automatic Promotion**: Events move between tiers based on access patterns

```php
// Configuration example
'event_sourcing' => [
    'storage_tiers' => [
        'hot' => [
            'driver' => 'redis',
            'ttl' => 86400, // 24 hours
        ],
        'warm' => [
            'driver' => 'mysql',
            'table' => 'event_store',
        ],
    ],
],
```

**Event Ordering and Sequencing**
- Strict ordering guarantees per aggregate
- Global sequence numbers for projection ordering
- Optimistic concurrency control

### 2. CQRS Infrastructure

**Command Bus with Middleware Pipeline**

```php
$commandBus = app(CommandBusInterface::class);

// Middleware automatically applied:
// 1. Validation
// 2. Authorization
// 3. Transaction Management
// 4. Event Dispatching

$result = $commandBus->dispatch(new CreateUser(/* ... */));
```

**Multi-Tier Query Caching**

```
L1 Cache (Memory)  →  L2 Cache (Redis)  →  L3 Store (Database)
     │ 100 queries/sec      │ 1000 queries/sec      │ Authoritative
     │ 1ms latency          │ 5ms latency           │ 50ms latency
     └ Limited capacity     └ High capacity         └ Unlimited capacity
```

### 3. Transaction Management

**Distributed Transaction Support**

```php
class OrderHandler implements CommandHandlerInterface
{
    public function handle(CreateOrder $command): void
    {
        $this->transactionManager->transaction(function () use ($command) {
            // 1. Reserve inventory (Inventory module)
            // 2. Create order (Order module)
            // 3. Process payment (Payment module)
            // All operations committed together or rolled back
        });
    }
}
```

**Deadlock Recovery**
- Automatic retry with exponential backoff
- Configurable retry attempts and delays
- Transaction isolation level management

### 4. Module Communication Bus

**Inter-Module Messaging**

```php
// Send command to another module
$moduleBus = app(ModuleBusInterface::class);
$result = $moduleBus->sendCommand('inventory', new ReserveItems($items));

// Query another module
$customer = $moduleBus->sendQuery('customer', new GetCustomer($customerId));

// Publish event to all interested modules
$moduleBus->publishEvent(new OrderCreated($orderId));
```

## Event Sourcing Architecture

### Event Store Design

```sql
-- Event store table structure
CREATE TABLE event_store (
    sequence_number BIGINT AUTO_INCREMENT PRIMARY KEY,
    aggregate_id VARCHAR(255) NOT NULL,
    aggregate_type VARCHAR(255) NOT NULL,
    event_type VARCHAR(255) NOT NULL,
    event_data JSON NOT NULL,
    metadata JSON,
    version INT NOT NULL,
    occurred_at TIMESTAMP(6) NOT NULL,
    INDEX idx_aggregate (aggregate_id, version),
    INDEX idx_type_sequence (event_type, sequence_number),
    INDEX idx_occurred_at (occurred_at)
);
```

### Snapshot Strategy

Three snapshot strategies are available:

1. **Simple Strategy**: Take snapshot every N events (default: 10)
2. **Adaptive Strategy**: Intelligent thresholds based on aggregate complexity
3. **Time-based Strategy**: Snapshots based on time intervals

```php
// Configuration for different strategies
'snapshots' => [
    'strategy' => 'adaptive',
    'adaptive_config' => [
        'event_count_threshold' => 50,
        'time_threshold_seconds' => 3600,
        'complexity_multiplier' => 1.0,
    ],
],
```

### Projection System

**Multiple Projection Strategies**

```php
// Real-time projections
class UserProjector extends AbstractProjector
{
    public function whenUserCreated(UserCreated $event): void
    {
        DB::table('user_read_models')->insert([
            'id' => $event->userId->toString(),
            'email' => $event->email,
            'created_at' => $event->occurredAt,
        ]);
    }
}

// Async projections for heavy operations
class AnalyticsProjector extends AbstractProjector
{
    protected array $strategy = ['async'];
    
    public function whenOrderCreated(OrderCreated $event): void
    {
        // Heavy analytics processing
        $this->updateDashboards($event);
        $this->generateReports($event);
    }
}
```

## CQRS Implementation

### Command Flow

```
Controller → Command → Command Bus → Middleware Pipeline → Handler → Aggregate
    │           │         │            │                   │         │
    │           │         │            │                   │         └─ Domain Events
    │           │         │            │                   │
    │           │         │            │                   └─ Event Store
    │           │         │            │
    │           │         │            └─ Transaction Management
    │           │         │                 Authorization
    │           │         │                 Validation
    │           │         │
    │           │         └─ Route to Handler
    │           │
    │           └─ DTO with validation rules
    │
    └─ HTTP Request
```

### Query Flow

```
Controller → Query → Query Bus → Cache Check → Handler → Read Model
    │         │       │          │            │         │
    │         │       │          │            │         └─ Database/API
    │         │       │          │            │
    │         │       │          │            └─ Business Logic
    │         │       │          │
    │         │       │          └─ L1 → L2 → L3 Cache
    │         │       │
    │         │       └─ Authorization & Routing
    │         │
    │         └─ DTO with caching metadata
    │
    └─ HTTP Request
```

## Module Organization

### Bounded Contexts

Each module represents a bounded context with:
- Clear domain boundaries
- Independent data models
- Autonomous deployment capability
- Event-based integration

### Module Dependencies

```php
// Module manifest example
[
    'name' => 'OrderManagement',
    'version' => '1.0.0',
    'dependencies' => [
        'UserManagement' => '^1.0.0',
        'Inventory' => '^2.1.0',
    ],
    'provides' => [
        'services' => ['OrderService'],
        'events' => ['OrderCreated', 'OrderCancelled'],
        'commands' => ['CreateOrder', 'CancelOrder'],
    ],
    'listens_to' => [
        'UserManagement.UserCreated',
        'Inventory.ItemReserved',
    ],
]
```

## Data Flow

### Write Path (Commands)

1. **Request Reception**: Controller receives HTTP request
2. **Command Creation**: Request converted to command DTO
3. **Validation**: Command validated using Laravel validation
4. **Authorization**: User permissions checked
5. **Transaction Start**: Database transaction begins
6. **Handler Execution**: Business logic executed
7. **Event Generation**: Domain events created
8. **Event Storage**: Events persisted to event store
9. **Transaction Commit**: All changes committed atomically
10. **Event Publishing**: Events published for projections

### Read Path (Queries)

1. **Query Reception**: Controller receives query request
2. **Cache Check**: Multi-tier cache consulted
3. **Cache Hit**: Return cached result if valid
4. **Cache Miss**: Execute query handler
5. **Data Retrieval**: Fetch from read model/database
6. **Cache Population**: Store result in appropriate cache tier
7. **Response**: Return data to client

### Event Processing

1. **Event Publication**: Events published after command completion
2. **Projection Routing**: Events routed to appropriate projectors
3. **Projection Update**: Read models updated
4. **Cache Invalidation**: Affected cache entries invalidated
5. **Inter-Module Events**: Events published to interested modules

## Performance Considerations

### Scalability Patterns

**Horizontal Scaling**
- Stateless application servers
- Database read replicas for queries
- Redis clustering for hot tier storage
- Queue workers for async processing

**Vertical Scaling**
- Memory optimization for L1 cache
- Connection pooling for databases
- Batch processing for projections
- Snapshot optimization for aggregates

### Monitoring and Observability

**Health Checks**
- Database connectivity
- Event store status
- Cache availability
- Queue processing
- Module dependencies

**Performance Metrics**
- Command processing time
- Query response time
- Event throughput
- Cache hit ratios
- Memory usage

**Alerting Thresholds**
```php
'performance_thresholds' => [
    'command_processing_ms' => 200,
    'query_processing_ms' => 100,
    'event_processing_ms' => 50,
    'memory_usage_mb' => 256,
    'cpu_usage_percent' => 80,
],
```

## Next Steps

- [Getting Started Guide](../getting-started/README.md) - Install and configure the package
- [Event Sourcing Guide](../event-sourcing/README.md) - Deep dive into event sourcing implementation
- [CQRS Guide](../cqrs/README.md) - Understanding command and query patterns
- [Performance Guide](../performance/README.md) - Optimization and tuning strategies
