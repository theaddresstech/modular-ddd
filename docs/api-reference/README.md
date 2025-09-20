# API Reference

Comprehensive API documentation for the Laravel Modular DDD package interfaces and classes.

## Table of Contents

- [Event Sourcing APIs](#event-sourcing-apis)
- [CQRS APIs](#cqrs-apis)
- [Module Communication APIs](#module-communication-apis)
- [Health Check APIs](#health-check-apis)
- [Performance Monitoring APIs](#performance-monitoring-apis)
- [Configuration APIs](#configuration-apis)
- [Testing APIs](#testing-apis)

## Event Sourcing APIs

### EventStoreInterface

Core interface for event storage operations.

```php
interface EventStoreInterface
{
    /**
     * Append events to the event store
     * 
     * @param AggregateIdInterface $aggregateId Target aggregate
     * @param DomainEventInterface[] $events Events to append
     * @param int|null $expectedVersion Expected version for concurrency control
     * @throws ConcurrencyException When version conflict occurs
     */
    public function append(
        AggregateIdInterface $aggregateId,
        array $events,
        ?int $expectedVersion = null
    ): void;

    /**
     * Load events for an aggregate
     * 
     * @param AggregateIdInterface $aggregateId Target aggregate
     * @param int $fromVersion Load from this version (inclusive)
     * @param int|null $toVersion Load up to this version (inclusive)
     * @return EventStreamInterface Stream of events
     */
    public function load(
        AggregateIdInterface $aggregateId,
        int $fromVersion = 1,
        ?int $toVersion = null
    ): EventStreamInterface;

    /**
     * Get current version of aggregate
     * 
     * @param AggregateIdInterface $aggregateId Target aggregate
     * @return int Current version (0 if not found)
     */
    public function getAggregateVersion(AggregateIdInterface $aggregateId): int;

    /**
     * Check if aggregate exists
     * 
     * @param AggregateIdInterface $aggregateId Target aggregate
     * @return bool True if exists
     */
    public function aggregateExists(AggregateIdInterface $aggregateId): bool;

    /**
     * Load events by type for projections
     * 
     * @param string $eventType Event class name
     * @param int $limit Maximum events to return
     * @param int $offset Skip this many events
     * @return DomainEventInterface[] Array of events
     */
    public function loadEventsByType(string $eventType, int $limit = 100, int $offset = 0): array;

    /**
     * Load events from sequence number
     * 
     * @param int $fromSequence Starting sequence number
     * @param int $limit Maximum events to return
     * @return array Events with sequence numbers
     */
    public function loadEventsFromSequence(int $fromSequence, int $limit = 100): array;
    
    /**
     * Batch operations for performance
     */
    public function loadBatch(array $aggregateIds, int $fromVersion = 1, ?int $toVersion = null): array;
    public function getAggregateVersionsBatch(array $aggregateIds): array;
    public function aggregateExistsBatch(array $aggregateIds): array;
}
```

### SnapshotStoreInterface

Interface for aggregate snapshot operations.

```php
interface SnapshotStoreInterface
{
    /**
     * Save aggregate snapshot
     * 
     * @param AggregateSnapshotInterface $snapshot Snapshot to save
     */
    public function save(AggregateSnapshotInterface $snapshot): void;

    /**
     * Load latest snapshot for aggregate
     * 
     * @param AggregateIdInterface $aggregateId Target aggregate
     * @return AggregateSnapshotInterface|null Latest snapshot or null
     */
    public function load(AggregateIdInterface $aggregateId): ?AggregateSnapshotInterface;

    /**
     * Load snapshot at specific version
     * 
     * @param AggregateIdInterface $aggregateId Target aggregate
     * @param int $version Maximum version
     * @return AggregateSnapshotInterface|null Snapshot or null
     */
    public function loadAtVersion(AggregateIdInterface $aggregateId, int $version): ?AggregateSnapshotInterface;

    /**
     * Remove old snapshots based on retention policy
     * 
     * @param AggregateIdInterface $aggregateId Target aggregate
     * @param int $keepCount Number of snapshots to keep
     */
    public function cleanup(AggregateIdInterface $aggregateId, int $keepCount = 3): void;
}
```

### EventStreamInterface

Interface for event stream operations.

```php
interface EventStreamInterface extends \Iterator, \Countable
{
    /**
     * Get all events as array
     * 
     * @return DomainEventInterface[]
     */
    public function getEvents(): array;

    /**
     * Check if stream is empty
     * 
     * @return bool
     */
    public function isEmpty(): bool;

    /**
     * Get first event
     * 
     * @return DomainEventInterface|null
     */
    public function first(): ?DomainEventInterface;

    /**
     * Get last event
     * 
     * @return DomainEventInterface|null
     */
    public function last(): ?DomainEventInterface;

    /**
     * Filter events by type
     * 
     * @param string $eventType Event class name
     * @return EventStreamInterface Filtered stream
     */
    public function filterByType(string $eventType): EventStreamInterface;

    /**
     * Slice events by version range
     * 
     * @param int $fromVersion Start version (inclusive)
     * @param int|null $toVersion End version (inclusive)
     * @return EventStreamInterface Sliced stream
     */
    public function slice(int $fromVersion, ?int $toVersion = null): EventStreamInterface;
}
```

## CQRS APIs

### CommandBusInterface

Interface for command dispatching and handling.

```php
interface CommandBusInterface
{
    /**
     * Dispatch command synchronously
     * 
     * @param CommandInterface $command Command to execute
     * @return mixed Command result
     * @throws ValidationException When command is invalid
     * @throws AuthorizationException When not authorized
     */
    public function dispatch(CommandInterface $command): mixed;

    /**
     * Dispatch command asynchronously
     * 
     * @param CommandInterface $command Command to execute
     * @return string Job ID for tracking
     */
    public function dispatchAsync(CommandInterface $command): string;

    /**
     * Queue command for later processing
     * 
     * @param CommandInterface $command Command to queue
     * @param string|null $queue Queue name (null for default)
     * @return string Job ID for tracking
     */
    public function queue(CommandInterface $command, ?string $queue = null): string;

    /**
     * Register command handler
     * 
     * @param CommandHandlerInterface $handler Handler instance
     */
    public function registerHandler(CommandHandlerInterface $handler): void;

    /**
     * Add middleware to processing pipeline
     * 
     * @param MiddlewareInterface $middleware Middleware instance
     */
    public function addMiddleware(MiddlewareInterface $middleware): void;

    /**
     * Get handler for command
     * 
     * @param CommandInterface $command Command instance
     * @return CommandHandlerInterface Handler instance
     * @throws HandlerNotFoundException When no handler found
     */
    public function getHandler(CommandInterface $command): CommandHandlerInterface;

    /**
     * Check if command can be handled
     * 
     * @param CommandInterface $command Command instance
     * @return bool True if handler exists
     */
    public function canHandle(CommandInterface $command): bool;
}
```

### QueryBusInterface

Interface for query execution with caching support.

```php
interface QueryBusInterface
{
    /**
     * Execute query with caching support
     * 
     * @param QueryInterface $query Query to execute
     * @return mixed Query result
     * @throws AuthorizationException When not authorized
     */
    public function ask(QueryInterface $query): mixed;

    /**
     * Execute query bypassing cache
     * 
     * @param QueryInterface $query Query to execute
     * @return mixed Fresh query result
     */
    public function askFresh(QueryInterface $query): mixed;

    /**
     * Register query handler
     * 
     * @param QueryHandlerInterface $handler Handler instance
     */
    public function registerHandler(QueryHandlerInterface $handler): void;

    /**
     * Invalidate cache for query
     * 
     * @param QueryInterface $query Query to invalidate
     */
    public function invalidateCache(QueryInterface $query): void;

    /**
     * Invalidate cache by tags
     * 
     * @param array $tags Cache tags to invalidate
     */
    public function invalidateCacheTags(array $tags): void;

    /**
     * Get cache statistics
     * 
     * @return array Cache statistics
     */
    public function getCacheStats(): array;
}
```

### CommandInterface

Base interface for all commands.

```php
interface CommandInterface
{
    // Marker interface - commands may optionally implement:
    
    /**
     * Validation rules (optional)
     * 
     * @return array Laravel validation rules
     */
    // public function rules(): array;
    
    /**
     * Authorization check (optional)
     * 
     * @return bool True if authorized
     */
    // public function authorize(): bool;
}
```

### QueryInterface

Base interface for all queries.

```php
interface QueryInterface
{
    // Marker interface - queries may optionally implement CacheableInterface
}

interface CacheableInterface
{
    /**
     * Get cache key for this query
     * 
     * @return string Unique cache key
     */
    public function getCacheKey(): string;

    /**
     * Get cache TTL in seconds
     * 
     * @return int TTL in seconds
     */
    public function getCacheTtl(): int;

    /**
     * Get cache tags for invalidation
     * 
     * @return array Cache tags
     */
    public function getCacheTags(): array;
}
```

## Module Communication APIs

### ModuleBusInterface

Interface for inter-module communication.

```php
interface ModuleBusInterface
{
    /**
     * Send command to another module
     * 
     * @param string $module Target module name
     * @param CommandInterface $command Command to send
     * @return mixed Command result
     * @throws ModuleNotFoundException When module not found
     * @throws ModuleUnavailableException When module unavailable
     */
    public function sendCommand(string $module, CommandInterface $command): mixed;

    /**
     * Send command asynchronously
     * 
     * @param string $module Target module name
     * @param CommandInterface $command Command to send
     * @return string Job ID for tracking
     */
    public function sendCommandAsync(string $module, CommandInterface $command): string;

    /**
     * Send query to another module
     * 
     * @param string $module Target module name
     * @param QueryInterface $query Query to send
     * @return mixed Query result
     * @throws ModuleNotFoundException When module not found
     * @throws ModuleUnavailableException When module unavailable
     */
    public function sendQuery(string $module, QueryInterface $query): mixed;

    /**
     * Publish event to interested modules
     * 
     * @param DomainEventInterface $event Event to publish
     */
    public function publishEvent(DomainEventInterface $event): void;

    /**
     * Subscribe to events from other modules
     * 
     * @param string $eventClass Event class name or pattern
     * @param callable $handler Event handler
     */
    public function subscribe(string $eventClass, callable $handler): void;

    /**
     * Get module health status
     * 
     * @param string $module Module name
     * @return ModuleHealthStatus Health status
     */
    public function getModuleHealth(string $module): ModuleHealthStatus;
}
```

## Health Check APIs

### Health Check Endpoints

**GET** `/health`

Returns overall system health status.

**Response:**
```json
{
  "status": "healthy|degraded|unhealthy",
  "timestamp": "2024-01-15T10:30:00Z",
  "version": "1.0.0",
  "environment": "production",
  "components": {
    "database": "healthy",
    "event_store": "healthy",
    "cache": "healthy",
    "queues": "healthy",
    "modules": "healthy"
  },
  "metrics": {
    "uptime_seconds": 86400,
    "memory_usage_mb": 256,
    "cpu_usage_percent": 45
  }
}
```

**GET** `/health/database`

Checks database connectivity and performance.

**Response:**
```json
{
  "status": "healthy",
  "details": {
    "write_connection": "healthy",
    "read_connections": ["healthy", "healthy"],
    "query_time_ms": 15,
    "active_connections": 25,
    "max_connections": 100
  }
}
```

**GET** `/health/event-store`

Checks event store health and performance.

**Response:**
```json
{
  "status": "healthy",
  "details": {
    "hot_tier": {
      "status": "healthy",
      "memory_usage_mb": 512,
      "hit_rate_percent": 95
    },
    "warm_tier": {
      "status": "healthy",
      "query_time_ms": 25,
      "disk_usage_percent": 60
    },
    "event_count": 1000000,
    "last_event_timestamp": "2024-01-15T10:29:45Z"
  }
}
```

**GET** `/health/queues`

Checks queue status and backlog.

**Response:**
```json
{
  "status": "healthy",
  "details": {
    "queues": {
      "default": {
        "size": 15,
        "workers": 8,
        "processing_rate_per_minute": 120
      },
      "projections": {
        "size": 5,
        "workers": 4,
        "processing_rate_per_minute": 200
      }
    },
    "failed_jobs": 2,
    "total_processed_today": 15000
  }
}
```

## Performance Monitoring APIs

### Metrics Endpoints

**GET** `/metrics`

Returns comprehensive system metrics.

**Response:**
```json
{
  "timestamp": "2024-01-15T10:30:00Z",
  "period": "last_hour",
  "command_metrics": {
    "total_executed": 1500,
    "average_duration_ms": 125,
    "success_rate_percent": 98.5,
    "slowest_commands": [
      {
        "command": "ProcessOrderCommand",
        "average_duration_ms": 450,
        "count": 50
      }
    ]
  },
  "query_metrics": {
    "total_executed": 5000,
    "average_duration_ms": 45,
    "cache_hit_rate_percent": 85,
    "slowest_queries": [
      {
        "query": "SearchProductsQuery",
        "average_duration_ms": 120,
        "count": 200
      }
    ]
  },
  "event_store_metrics": {
    "events_appended": 800,
    "events_loaded": 2000,
    "average_append_time_ms": 15,
    "average_load_time_ms": 25
  },
  "memory_usage": {
    "current_mb": 256,
    "peak_mb": 512,
    "limit_mb": 1024
  }
}
```

**GET** `/metrics/realtime`

Returns real-time performance metrics (WebSocket or Server-Sent Events).

**GET** `/metrics/prometheus`

Returns metrics in Prometheus format for monitoring systems.

## Configuration APIs

### Configuration Structure

Complete configuration reference with descriptions and defaults:

```php
return [
    // Module settings
    'modules_path' => base_path('modules'),
    'module_namespace' => 'Modules',
    
    // Event sourcing configuration
    'event_sourcing' => [
        'enabled' => env('EVENT_SOURCING_ENABLED', true),
        
        // Tiered storage configuration
        'storage_tiers' => [
            'hot' => [
                'driver' => 'redis',
                'connection' => env('EVENT_STORE_REDIS_CONNECTION', 'default'),
                'ttl' => env('EVENT_STORE_HOT_TTL', 86400),
                'enabled' => env('EVENT_STORE_HOT_ENABLED', true),
            ],
            'warm' => [
                'driver' => env('EVENT_STORE_WARM_DRIVER', 'mysql'),
                'connection' => env('EVENT_STORE_WARM_CONNECTION', 'mysql'),
                'table' => 'event_store',
                'snapshots_table' => 'snapshots',
            ],
        ],
        
        // Snapshot configuration
        'snapshots' => [
            'enabled' => env('EVENT_SOURCING_SNAPSHOTS_ENABLED', true),
            'strategy' => env('SNAPSHOT_STRATEGY', 'simple'), // simple, adaptive, time_based
            'threshold' => env('SNAPSHOT_THRESHOLD', 10),
            
            // Strategy-specific configurations...
        ],
        
        // Performance settings
        'performance' => [
            'batch_size' => 100,
            'connection_pool_size' => 10,
            'query_timeout' => 30,
        ],
        
        // Event ordering
        'ordering' => [
            'strict_ordering' => env('EVENT_STRICT_ORDERING', true),
            'max_reorder_window' => env('EVENT_MAX_REORDER_WINDOW', 100),
        ],
    ],
    
    // CQRS configuration
    'cqrs' => [
        'command_bus' => [
            'default_mode' => env('CQRS_DEFAULT_MODE', 'sync'),
            'timeout' => env('COMMAND_TIMEOUT', 30),
            'retry_attempts' => 3,
            'middleware' => [
                'validation' => true,
                'authorization' => true,
                'logging' => env('CQRS_LOGGING', true),
                'transactions' => true,
            ],
        ],
        
        'query_bus' => [
            'cache_enabled' => env('QUERY_CACHE_ENABLED', true),
            'cache_ttl' => env('QUERY_CACHE_TTL', 900),
            'cache_stores' => [
                'l2' => env('QUERY_L2_CACHE_STORE', 'redis'),
                'l3' => env('QUERY_L3_CACHE_STORE', 'database'),
            ],
            'memory_limits' => [
                'l1_max_entries' => env('QUERY_L1_MAX_ENTRIES', 1000),
                'max_memory_mb' => env('QUERY_MAX_MEMORY_MB', 128),
            ],
        ],
    ],
    
    // Module communication
    'module_communication' => [
        'enabled' => env('MODULE_COMMUNICATION_ENABLED', true),
        'default_timeout' => env('MODULE_MESSAGE_TIMEOUT', 30),
        'default_retries' => env('MODULE_MESSAGE_RETRIES', 3),
        'async_processing' => [
            'enabled' => env('MODULE_ASYNC_ENABLED', true),
            'queue' => env('MODULE_COMMUNICATION_QUEUE', 'modules'),
        ],
    ],
    
    // Performance monitoring
    'performance' => [
        'monitoring' => [
            'enabled' => env('DDD_MONITORING_ENABLED', true),
            'metrics_collector' => env('METRICS_COLLECTOR', 'memory'),
            'performance_thresholds' => [
                'command_processing_ms' => env('PERFORMANCE_COMMAND_THRESHOLD', 200),
                'query_processing_ms' => env('PERFORMANCE_QUERY_THRESHOLD', 100),
                'event_processing_ms' => env('PERFORMANCE_EVENT_THRESHOLD', 50),
            ],
        ],
    ],
];
```

## Testing APIs

### Testing Traits

```php
// Event sourcing testing support
trait EventStoreTestingTrait
{
    /**
     * Assert that event was recorded
     */
    public function assertEventWasRecorded(string $eventClass): void;
    
    /**
     * Assert specific event data
     */
    public function assertEventRecorded(string $eventClass, array $expectedData): void;
    
    /**
     * Get recorded events
     */
    public function getRecordedEvents(): array;
    
    /**
     * Clear recorded events
     */
    public function clearRecordedEvents(): void;
}

// CQRS testing support
trait CQRSTestingTrait
{
    /**
     * Mock command handler
     */
    public function mockCommandHandler(string $commandClass, $result): void;
    
    /**
     * Mock query handler
     */
    public function mockQueryHandler(string $queryClass, $result): void;
    
    /**
     * Assert command was dispatched
     */
    public function assertCommandDispatched(string $commandClass): void;
    
    /**
     * Assert query was asked
     */
    public function assertQueryAsked(string $queryClass): void;
}
```

This API reference provides comprehensive documentation for all public interfaces and endpoints in the Laravel Modular DDD package. Each interface includes detailed parameter descriptions, return types, and exception handling information.
