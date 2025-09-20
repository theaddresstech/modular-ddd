# Performance Optimization Guide

This guide covers performance optimization strategies, profiling, and tuning for the Laravel Modular DDD package.

## Table of Contents

- [Performance Profiles](#performance-profiles)
- [Event Store Optimization](#event-store-optimization)
- [CQRS Performance Tuning](#cqrs-performance-tuning)
- [Cache Optimization](#cache-optimization)
- [Database Performance](#database-performance)
- [Memory Management](#memory-management)
- [Profiling and Benchmarking](#profiling-and-benchmarking)
- [Scaling Strategies](#scaling-strategies)

## Performance Profiles

The package includes four pre-configured performance profiles for different application scales:

### Startup Profile
**Target**: Small applications, minimal resource usage

```php
'startup' => [
    'description' => 'Minimal resource usage for small applications',
    'event_sourcing' => [
        'snapshots' => ['strategy' => 'simple', 'threshold' => 10],
        'hot_storage_ttl' => 3600, // 1 hour
    ],
    'cqrs' => [
        'query_cache_ttl' => 300, // 5 minutes
        'memory_limits' => ['l1_max_entries' => 100, 'max_memory_mb' => 32],
    ],
    'async' => ['strategy' => 'sync'],
    'projections' => ['strategies' => ['realtime']],
],
```

### Growth Profile
**Target**: Growing applications with moderate load

```php
'growth' => [
    'description' => 'Balanced performance for growing applications',
    'event_sourcing' => [
        'snapshots' => ['strategy' => 'simple', 'threshold' => 10],
        'hot_storage_ttl' => 86400, // 24 hours
    ],
    'cqrs' => [
        'query_cache_ttl' => 900, // 15 minutes
        'memory_limits' => ['l1_max_entries' => 500, 'max_memory_mb' => 64],
    ],
    'async' => ['strategy' => 'laravel_queue'],
    'projections' => ['strategies' => ['realtime', 'async']],
],
```

### Scale Profile
**Target**: High-traffic applications

```php
'scale' => [
    'description' => 'High performance for large-scale applications',
    'event_sourcing' => [
        'snapshots' => ['strategy' => 'adaptive'],
        'hot_storage_ttl' => 86400, // 24 hours
        'storage_tiers' => ['hot' => ['enabled' => true]],
    ],
    'cqrs' => [
        'query_cache_ttl' => 1800, // 30 minutes
        'memory_limits' => ['l1_max_entries' => 2000, 'max_memory_mb' => 256],
    ],
    'async' => ['strategy' => 'laravel_queue'],
    'projections' => ['strategies' => ['async', 'batched']],
],
```

### Enterprise Profile
**Target**: Enterprise-level applications with maximum performance

```php
'enterprise' => [
    'description' => 'Maximum performance for enterprise applications',
    'event_sourcing' => [
        'snapshots' => ['strategy' => 'adaptive'],
        'hot_storage_ttl' => 172800, // 48 hours
        'storage_tiers' => ['hot' => ['enabled' => true]],
    ],
    'cqrs' => [
        'query_cache_ttl' => 3600, // 1 hour
        'memory_limits' => ['l1_max_entries' => 5000, 'max_memory_mb' => 512],
    ],
    'async' => ['strategy' => 'laravel_queue', 'queue' => 'high-priority'],
    'projections' => ['strategies' => ['async', 'batched']],
    'monitoring' => ['metrics_collector' => 'redis'],
],
```

## Event Store Optimization

### Tiered Storage Performance

**Hot Tier (Redis) Optimization:**

```php
// Optimize Redis configuration for event storage
'storage_tiers' => [
    'hot' => [
        'driver' => 'redis',
        'connection' => 'events', // Dedicated connection
        'ttl' => 172800, // 48 hours for high-traffic
        'enabled' => true,
        'serialization' => 'msgpack', // More efficient than JSON
        'compression' => true, // Enable for large events
    ],
],
```

**Warm Tier (MySQL) Optimization:**

```sql
-- Optimize MySQL for event store
CREATE TABLE event_store (
    sequence_number BIGINT AUTO_INCREMENT PRIMARY KEY,
    aggregate_id VARCHAR(36) NOT NULL,
    aggregate_type VARCHAR(100) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    event_data JSON NOT NULL,
    metadata JSON,
    version INT NOT NULL,
    occurred_at TIMESTAMP(6) NOT NULL,
    
    -- Optimized indexes
    INDEX idx_aggregate_version (aggregate_id, version),
    INDEX idx_sequence_type (sequence_number, event_type),
    INDEX idx_occurred_type (occurred_at, event_type),
    INDEX idx_aggregate_type (aggregate_type, aggregate_id),
    
    -- Partitioning for large datasets
    UNIQUE KEY uk_aggregate_version (aggregate_id, version)
) ENGINE=InnoDB 
  ROW_FORMAT=COMPRESSED 
  KEY_BLOCK_SIZE=8
  PARTITION BY RANGE (sequence_number) (
    PARTITION p0 VALUES LESS THAN (1000000),
    PARTITION p1 VALUES LESS THAN (2000000),
    PARTITION p2 VALUES LESS THAN (3000000),
    PARTITION p3 VALUES LESS THAN MAXVALUE
  );
```

### Batch Loading Optimization

```php
class OptimizedEventStore implements EventStoreInterface
{
    public function loadBatch(
        array $aggregateIds,
        int $fromVersion = 1,
        ?int $toVersion = null
    ): array {
        // Use single query instead of N queries
        $query = $this->connection
            ->table($this->table)
            ->whereIn('aggregate_id', array_map('strval', $aggregateIds))
            ->where('version', '>=', $fromVersion)
            ->orderBy('aggregate_id')
            ->orderBy('version');
            
        if ($toVersion !== null) {
            $query->where('version', '<=', $toVersion);
        }
        
        $events = $query->get();
        
        // Group by aggregate_id for efficient processing
        $grouped = $events->groupBy('aggregate_id');
        
        $streams = [];
        foreach ($aggregateIds as $aggregateId) {
            $aggregateEvents = $grouped->get($aggregateId->toString(), collect());
            $streams[$aggregateId->toString()] = new EventStream(
                $aggregateEvents->map([$this, 'deserializeEvent'])->all()
            );
        }
        
        return $streams;
    }
    
    public function appendBatch(array $operations): void
    {
        $this->connection->transaction(function () use ($operations) {
            $insertData = [];
            
            foreach ($operations as $operation) {
                foreach ($operation['events'] as $index => $event) {
                    $insertData[] = [
                        'aggregate_id' => $operation['aggregate_id']->toString(),
                        'aggregate_type' => $operation['aggregate_type'],
                        'event_type' => get_class($event),
                        'event_data' => json_encode($this->serializer->serialize($event)),
                        'metadata' => json_encode($operation['metadata'] ?? []),
                        'version' => $operation['base_version'] + $index + 1,
                        'occurred_at' => $event->occurredAt(),
                    ];
                }
            }
            
            // Single bulk insert
            $this->connection->table($this->table)->insert($insertData);
        });
    }
}
```

### Snapshot Optimization

```php
class OptimizedAdaptiveSnapshotStrategy implements SnapshotStrategyInterface
{
    public function shouldCreateSnapshot(
        AggregateIdInterface $aggregateId,
        int $currentVersion,
        int $eventsSinceSnapshot
    ): bool {
        // Cache metrics to avoid repeated calculations
        static $metricsCache = [];
        
        $cacheKey = $aggregateId->toString();
        
        if (!isset($metricsCache[$cacheKey]) || 
            time() - $metricsCache[$cacheKey]['timestamp'] > 300) {
            
            $metricsCache[$cacheKey] = [
                'metrics' => $this->calculateMetrics($aggregateId),
                'timestamp' => time(),
            ];
        }
        
        $metrics = $metricsCache[$cacheKey]['metrics'];
        
        return $this->shouldSnapshot($metrics, $eventsSinceSnapshot);
    }
    
    private function calculateMetrics(AggregateIdInterface $aggregateId): array
    {
        // Use Redis for fast metrics retrieval
        $cacheKey = "snapshot_metrics:{$aggregateId->toString()}";
        
        return Cache::remember($cacheKey, 300, function () use ($aggregateId) {
            return [
                'complexity_score' => $this->calculateComplexityScore($aggregateId),
                'access_frequency' => $this->getAccessFrequency($aggregateId),
                'event_size_avg' => $this->getAverageEventSize($aggregateId),
                'load_time_avg' => $this->getAverageLoadTime($aggregateId),
            ];
        });
    }
}
```

## CQRS Performance Tuning

### Command Bus Optimization

```php
class HighPerformanceCommandBus implements CommandBusInterface
{
    private array $handlerCache = [];
    private array $middlewareStack = [];
    
    public function dispatch(CommandInterface $command): mixed
    {
        $commandClass = get_class($command);
        
        // Cache handler resolution
        if (!isset($this->handlerCache[$commandClass])) {
            $this->handlerCache[$commandClass] = $this->resolveHandler($command);
        }
        
        $handler = $this->handlerCache[$commandClass];
        
        // Optimized middleware pipeline
        return $this->executeWithMiddleware($command, $handler);
    }
    
    private function executeWithMiddleware(
        CommandInterface $command,
        CommandHandlerInterface $handler
    ): mixed {
        // Pre-compiled middleware stack for performance
        $pipeline = array_reduce(
            array_reverse($this->middlewareStack),
            function ($carry, $middleware) {
                return function ($command) use ($carry, $middleware) {
                    return $middleware->handle($command, $carry);
                };
            },
            function ($command) use ($handler) {
                return $handler->handle($command);
            }
        );
        
        return $pipeline($command);
    }
}
```

### Query Bus Cache Optimization

```php
class OptimizedMultiTierCacheManager
{
    private array $l1Cache = [];
    private int $l1MaxEntries;
    private int $l1CurrentEntries = 0;
    
    public function get(string $key): mixed
    {
        // L1 Cache (in-memory)
        if (isset($this->l1Cache[$key])) {
            $entry = $this->l1Cache[$key];
            
            if ($entry['expires_at'] > time()) {
                // Update access time for LRU
                $entry['last_accessed'] = time();
                $this->l1Cache[$key] = $entry;
                
                return $entry['value'];
            }
            
            unset($this->l1Cache[$key]);
            $this->l1CurrentEntries--;
        }
        
        // L2 Cache (Redis)
        $value = $this->l2Cache->get($key);
        if ($value !== null) {
            $this->promoteToL1($key, $value, 300); // 5 min L1 TTL
            return $value;
        }
        
        // L3 Cache (Database)
        $value = $this->l3Cache->get($key);
        if ($value !== null) {
            $this->promoteToL2($key, $value, 900); // 15 min L2 TTL
            $this->promoteToL1($key, $value, 300); // 5 min L1 TTL
            return $value;
        }
        
        return null;
    }
    
    private function promoteToL1(string $key, mixed $value, int $ttl): void
    {
        if ($this->l1CurrentEntries >= $this->l1MaxEntries) {
            $this->evictLeastRecentlyUsed();
        }
        
        $this->l1Cache[$key] = [
            'value' => $value,
            'expires_at' => time() + $ttl,
            'last_accessed' => time(),
        ];
        
        $this->l1CurrentEntries++;
    }
    
    private function evictLeastRecentlyUsed(): void
    {
        $oldestKey = null;
        $oldestTime = time();
        
        foreach ($this->l1Cache as $key => $entry) {
            if ($entry['last_accessed'] < $oldestTime) {
                $oldestTime = $entry['last_accessed'];
                $oldestKey = $key;
            }
        }
        
        if ($oldestKey) {
            unset($this->l1Cache[$oldestKey]);
            $this->l1CurrentEntries--;
        }
    }
}
```

## Cache Optimization

### Redis Configuration for Performance

```ini
# redis.conf optimizations

# Memory management
maxmemory 8gb
maxmemory-policy allkeys-lru

# Network optimizations
tcp-keepalive 300
tcp-nodelay yes

# Persistence optimization
save 900 1
save 300 10
save 60 10000

# AOF for durability
appendonly yes
appendfsync everysec
auto-aof-rewrite-percentage 100
auto-aof-rewrite-min-size 64mb

# Client connection
timeout 0
tcp-keepalive 300

# Memory optimization
hash-max-ziplist-entries 512
hash-max-ziplist-value 64
list-max-ziplist-size -2
set-max-intset-entries 512
zset-max-ziplist-entries 128
zset-max-ziplist-value 64

# Disable dangerous commands in production
rename-command FLUSHDB ""
rename-command FLUSHALL ""
rename-command EVAL ""
```

### Cache Key Strategies

```php
class OptimizedCacheKeyGenerator
{
    public function generateQueryKey(QueryInterface $query): string
    {
        $queryClass = get_class($query);
        
        // Use reflection cache to avoid repeated operations
        static $propertyCache = [];
        
        if (!isset($propertyCache[$queryClass])) {
            $reflection = new \ReflectionClass($query);
            $propertyCache[$queryClass] = $reflection->getProperties(
                \ReflectionProperty::IS_PUBLIC
            );
        }
        
        $data = [];
        foreach ($propertyCache[$queryClass] as $property) {
            $value = $property->getValue($query);
            $data[$property->getName()] = $this->serializeValue($value);
        }
        
        // Use consistent hashing for even distribution
        $hash = hash('xxh3', serialize($data));
        
        return "query:{$queryClass}:{$hash}";
    }
    
    private function serializeValue(mixed $value): string
    {
        if (is_object($value)) {
            if (method_exists($value, 'toString')) {
                return $value->toString();
            }
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }
            return serialize($value);
        }
        
        return (string) $value;
    }
}
```

## Database Performance

### Query Optimization

```sql
-- Event store query optimization

-- Composite index for aggregate loading
CREATE INDEX idx_event_store_aggregate_load 
ON event_store (aggregate_id, version) 
INCLUDE (event_type, event_data, occurred_at);

-- Index for projection queries
CREATE INDEX idx_event_store_projection 
ON event_store (sequence_number, event_type) 
INCLUDE (aggregate_id, event_data, occurred_at);

-- Index for time-based queries
CREATE INDEX idx_event_store_temporal 
ON event_store (occurred_at, event_type) 
INCLUDE (aggregate_id, sequence_number);

-- Snapshot optimization
CREATE INDEX idx_snapshots_latest 
ON snapshots (aggregate_id, version DESC) 
INCLUDE (data, created_at);

-- Query cache optimization
CREATE INDEX idx_query_cache_lookup 
ON query_cache (cache_key, expires_at) 
INCLUDE (data, tags);
```

### Connection Pool Optimization

```php
class OptimizedConnectionPool
{
    private \SplQueue $availableConnections;
    private array $activeConnections = [];
    private int $maxConnections;
    private int $currentConnections = 0;
    
    public function getConnection(): \PDO
    {
        if (!$this->availableConnections->isEmpty()) {
            $connection = $this->availableConnections->dequeue();
            
            // Validate connection health
            if ($this->isConnectionHealthy($connection)) {
                $this->activeConnections[spl_object_id($connection)] = $connection;
                return $connection;
            }
        }
        
        if ($this->currentConnections < $this->maxConnections) {
            $connection = $this->createConnection();
            $this->activeConnections[spl_object_id($connection)] = $connection;
            $this->currentConnections++;
            return $connection;
        }
        
        // Wait for available connection
        return $this->waitForConnection();
    }
    
    public function releaseConnection(\PDO $connection): void
    {
        $id = spl_object_id($connection);
        
        if (isset($this->activeConnections[$id])) {
            unset($this->activeConnections[$id]);
            
            if ($this->isConnectionHealthy($connection)) {
                $this->availableConnections->enqueue($connection);
            } else {
                $this->currentConnections--;
            }
        }
    }
    
    private function isConnectionHealthy(\PDO $connection): bool
    {
        try {
            $connection->query('SELECT 1');
            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }
}
```

## Memory Management

### Aggregate Memory Optimization

```php
class MemoryOptimizedAggregateRepository
{
    private array $aggregateCache = [];
    private int $maxCacheSize = 1000;
    
    public function load(AggregateIdInterface $aggregateId): ?AggregateRoot
    {
        $cacheKey = $aggregateId->toString();
        
        // Check memory cache first
        if (isset($this->aggregateCache[$cacheKey])) {
            return clone $this->aggregateCache[$cacheKey];
        }
        
        $aggregate = $this->loadFromEventStore($aggregateId);
        
        if ($aggregate && $this->shouldCache($aggregate)) {
            $this->cacheAggregate($cacheKey, $aggregate);
        }
        
        return $aggregate;
    }
    
    private function cacheAggregate(string $key, AggregateRoot $aggregate): void
    {
        if (count($this->aggregateCache) >= $this->maxCacheSize) {
            // Remove oldest entry (simple FIFO)
            $oldestKey = array_key_first($this->aggregateCache);
            unset($this->aggregateCache[$oldestKey]);
        }
        
        // Store immutable copy
        $this->aggregateCache[$key] = clone $aggregate;
    }
    
    private function shouldCache(AggregateRoot $aggregate): bool
    {
        // Cache frequently accessed, stable aggregates
        return $aggregate->getVersion() > 5 && 
               $this->getAggregateAccessFrequency($aggregate->getId()) > 0.1;
    }
    
    public function clearCache(): void
    {
        $this->aggregateCache = [];
        gc_collect_cycles(); // Force garbage collection
    }
}
```

### Event Stream Memory Management

```php
class MemoryEfficientEventStream implements EventStreamInterface
{
    private \Generator $eventGenerator;
    private int $totalCount;
    
    public function __construct(callable $eventLoader, int $totalCount)
    {
        $this->eventGenerator = $eventLoader();
        $this->totalCount = $totalCount;
    }
    
    public function getIterator(): \Iterator
    {
        // Use generator to avoid loading all events into memory
        foreach ($this->eventGenerator as $event) {
            yield $event;
        }
    }
    
    public function chunk(int $size): \Generator
    {
        $chunk = [];
        $count = 0;
        
        foreach ($this->eventGenerator as $event) {
            $chunk[] = $event;
            $count++;
            
            if ($count >= $size) {
                yield $chunk;
                $chunk = [];
                $count = 0;
            }
        }
        
        if (!empty($chunk)) {
            yield $chunk;
        }
    }
    
    public function count(): int
    {
        return $this->totalCount;
    }
}
```

## Profiling and Benchmarking

### Built-in Performance Monitoring

```php
class PerformanceProfiler
{
    private array $timers = [];
    private array $memoryUsage = [];
    private array $queryLog = [];
    
    public function startTimer(string $name): void
    {
        $this->timers[$name] = [
            'start' => microtime(true),
            'memory_start' => memory_get_usage(true),
        ];
    }
    
    public function endTimer(string $name): array
    {
        if (!isset($this->timers[$name])) {
            throw new \InvalidArgumentException("Timer '{$name}' not started");
        }
        
        $timer = $this->timers[$name];
        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);
        
        $result = [
            'duration_ms' => ($endTime - $timer['start']) * 1000,
            'memory_used_mb' => ($endMemory - $timer['memory_start']) / 1024 / 1024,
            'peak_memory_mb' => memory_get_peak_usage(true) / 1024 / 1024,
        ];
        
        unset($this->timers[$name]);
        
        return $result;
    }
    
    public function profileCommand(CommandInterface $command, callable $handler): mixed
    {
        $commandClass = get_class($command);
        
        $this->startTimer($commandClass);
        
        try {
            $result = $handler();
            
            $metrics = $this->endTimer($commandClass);
            $this->recordCommandMetrics($commandClass, $metrics, true);
            
            return $result;
        } catch (\Throwable $e) {
            $metrics = $this->endTimer($commandClass);
            $this->recordCommandMetrics($commandClass, $metrics, false);
            
            throw $e;
        }
    }
    
    private function recordCommandMetrics(string $command, array $metrics, bool $success): void
    {
        app(MetricsCollectorInterface::class)->recordCommandExecution(
            $command,
            $metrics['duration_ms'],
            $success
        );
        
        // Log slow commands
        if ($metrics['duration_ms'] > config('modular-ddd.performance.monitoring.slow_command_threshold')) {
            Log::warning('Slow command detected', [
                'command' => $command,
                'duration_ms' => $metrics['duration_ms'],
                'memory_used_mb' => $metrics['memory_used_mb'],
                'peak_memory_mb' => $metrics['peak_memory_mb'],
            ]);
        }
    }
}
```

### Benchmark Commands

```php
class BenchmarkCommand extends Command
{
    protected $signature = 'ddd:benchmark 
                            {--operations=1000 : Number of operations to perform}
                            {--type=mixed : Type of benchmark (command|query|event|mixed)}
                            {--profile=growth : Performance profile to use}';
    
    public function handle(): void
    {
        $operations = $this->option('operations');
        $type = $this->option('type');
        $profile = $this->option('profile');
        
        $this->info("Starting benchmark with {$operations} operations using {$profile} profile...");
        
        // Apply performance profile
        config(['modular-ddd.active_profile' => $profile]);
        
        $results = match($type) {
            'command' => $this->benchmarkCommands($operations),
            'query' => $this->benchmarkQueries($operations),
            'event' => $this->benchmarkEventStore($operations),
            'mixed' => $this->benchmarkMixed($operations),
        };
        
        $this->displayResults($results);
    }
    
    private function benchmarkCommands(int $operations): array
    {
        $commandBus = app(CommandBusInterface::class);
        $times = [];
        
        for ($i = 0; $i < $operations; $i++) {
            $command = new CreateUserCommand(
                email: "user{$i}@example.com",
                name: "User {$i}"
            );
            
            $start = microtime(true);
            $commandBus->dispatch($command);
            $times[] = (microtime(true) - $start) * 1000;
        }
        
        return $this->calculateStats($times, 'Commands');
    }
    
    private function benchmarkQueries(int $operations): array
    {
        $queryBus = app(QueryBusInterface::class);
        $times = [];
        
        // Pre-populate some data
        $userIds = $this->createTestUsers(min(100, $operations));
        
        for ($i = 0; $i < $operations; $i++) {
            $userId = $userIds[array_rand($userIds)];
            $query = new GetUserQuery($userId);
            
            $start = microtime(true);
            $queryBus->ask($query);
            $times[] = (microtime(true) - $start) * 1000;
        }
        
        return $this->calculateStats($times, 'Queries');
    }
    
    private function calculateStats(array $times, string $type): array
    {
        sort($times);
        $count = count($times);
        
        return [
            'type' => $type,
            'operations' => $count,
            'total_time_ms' => array_sum($times),
            'avg_time_ms' => array_sum($times) / $count,
            'min_time_ms' => min($times),
            'max_time_ms' => max($times),
            'median_time_ms' => $times[intval($count / 2)],
            'p95_time_ms' => $times[intval($count * 0.95)],
            'p99_time_ms' => $times[intval($count * 0.99)],
            'ops_per_second' => $count / (array_sum($times) / 1000),
        ];
    }
}
```

This performance guide provides comprehensive optimization strategies for all aspects of the Laravel Modular DDD package, from basic configuration to advanced profiling and benchmarking techniques.
