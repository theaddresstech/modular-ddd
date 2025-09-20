# CQRS Implementation Guide

This guide covers the Command Query Responsibility Segregation (CQRS) implementation in the Laravel Modular DDD package, including multi-tier caching, async processing, and middleware pipeline.

## Table of Contents

- [CQRS Fundamentals](#cqrs-fundamentals)
- [Command Bus Implementation](#command-bus-implementation)
- [Query Bus Implementation](#query-bus-implementation)
- [Multi-Tier Caching System](#multi-tier-caching-system)
- [Middleware Pipeline](#middleware-pipeline)
- [Async Processing](#async-processing)
- [Authorization and Security](#authorization-and-security)
- [Performance Optimization](#performance-optimization)
- [Advanced Patterns](#advanced-patterns)
- [Best Practices](#best-practices)

## CQRS Fundamentals

### What is CQRS?

Command Query Responsibility Segregation (CQRS) separates read and write operations into different models, optimizing each for their specific purpose.

**Traditional Approach:**
```php
// Same model for reads and writes
class UserService
{
    public function updateUser(int $id, array $data): User
    {
        // Write operation
        return User::find($id)->update($data);
    }
    
    public function getUser(int $id): User
    {
        // Read operation using same model
        return User::find($id);
    }
}
```

**CQRS Approach:**
```php
// Separate command and query models
class UpdateUserCommand implements CommandInterface
{
    public function __construct(
        public readonly UserId $userId,
        public readonly string $name,
        public readonly Email $email
    ) {}
}

class GetUserQuery implements QueryInterface
{
    public function __construct(
        public readonly UserId $userId
    ) {}
}

// Separate handlers optimized for their purpose
class UpdateUserHandler implements CommandHandlerInterface
{
    public function handle(UpdateUserCommand $command): void
    {
        // Optimized for writes: transactions, validation, events
    }
}

class GetUserHandler implements QueryHandlerInterface
{
    public function handle(GetUserQuery $query): UserReadModel
    {
        // Optimized for reads: caching, denormalized data
    }
}
```

### Benefits

1. **Scalability**: Scale reads and writes independently
2. **Performance**: Optimize each side for its specific needs
3. **Flexibility**: Different models for different use cases
4. **Security**: Fine-grained authorization
5. **Caching**: Aggressive caching for queries

## Command Bus Implementation

### Basic Command Structure

```php
<?php

namespace App\Commands;

use LaravelModularDDD\CQRS\Contracts\CommandInterface;

final readonly class CreateUser implements CommandInterface
{
    public function __construct(
        public string $email,
        public string $name,
        public ?string $phone = null
    ) {}
    
    // Optional: Define validation rules
    public function rules(): array
    {
        return [
            'email' => 'required|email|unique:users',
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
        ];
    }
    
    // Optional: Define authorization requirements
    public function authorize(): bool
    {
        return auth()->user()?->can('create-users') ?? false;
    }
}
```

### Command Handler Implementation

```php
<?php

namespace App\Handlers;

use LaravelModularDDD\CQRS\Contracts\CommandHandlerInterface;
use LaravelModularDDD\Core\Infrastructure\Repository\EventSourcedAggregateRepository;
use App\Commands\CreateUser;
use App\Domain\User\User;
use App\Domain\User\UserId;
use App\Domain\User\Email;

final class CreateUserHandler implements CommandHandlerInterface
{
    public function __construct(
        private EventSourcedAggregateRepository $repository
    ) {}

    public function handle(CreateUser $command): UserId
    {
        $userId = UserId::generate();
        $email = new Email($command->email);
        
        $user = User::create(
            $userId,
            $email,
            $command->name,
            $command->phone
        );
        
        $this->repository->save($user);
        
        return $userId;
    }
}
```

### Command Bus Usage

```php
use LaravelModularDDD\CQRS\Contracts\CommandBusInterface;

class UserController extends Controller
{
    public function store(Request $request, CommandBusInterface $commandBus)
    {
        $command = new CreateUser(
            email: $request->input('email'),
            name: $request->input('name'),
            phone: $request->input('phone')
        );
        
        // Synchronous execution
        $userId = $commandBus->dispatch($command);
        
        return response()->json(['user_id' => $userId]);
    }
    
    public function storeAsync(Request $request, CommandBusInterface $commandBus)
    {
        $command = new CreateUser(
            email: $request->input('email'),
            name: $request->input('name'),
            phone: $request->input('phone')
        );
        
        // Asynchronous execution
        $jobId = $commandBus->dispatchAsync($command);
        
        return response()->json([
            'job_id' => $jobId,
            'status' => 'pending'
        ]);
    }
}
```

### Command Bus Configuration

```php
'cqrs' => [
    'command_bus' => [
        'default_mode' => env('CQRS_DEFAULT_MODE', 'sync'), // sync, async
        'timeout' => env('COMMAND_TIMEOUT', 30),
        'retry_attempts' => 3,
        'middleware' => [
            'validation' => true,
            'authorization' => true,
            'logging' => env('CQRS_LOGGING', true),
            'transactions' => true,
        ],
    ],
],
```

## Query Bus Implementation

### Query Structure

```php
<?php

namespace App\Queries;

use LaravelModularDDD\CQRS\Contracts\QueryInterface;
use LaravelModularDDD\CQRS\Contracts\CacheableInterface;

final readonly class GetUser implements QueryInterface, CacheableInterface
{
    public function __construct(
        public UserId $userId
    ) {}
    
    // Cache configuration
    public function getCacheKey(): string
    {
        return "user:{$this->userId->toString()}";
    }
    
    public function getCacheTtl(): int
    {
        return 900; // 15 minutes
    }
    
    public function getCacheTags(): array
    {
        return ['users', "user:{$this->userId->toString()}"];
    }
}
```

### Query Handler Implementation

```php
<?php

namespace App\Handlers;

use LaravelModularDDD\CQRS\Contracts\QueryHandlerInterface;
use App\Queries\GetUser;
use App\ReadModels\UserReadModel;

final class GetUserHandler implements QueryHandlerInterface
{
    public function handle(GetUser $query): ?UserReadModel
    {
        return UserReadModel::where('id', $query->userId->toString())->first();
    }
}
```

### Complex Query Example

```php
final readonly class SearchUsers implements QueryInterface, CacheableInterface
{
    public function __construct(
        public string $search = '',
        public array $filters = [],
        public string $sortBy = 'created_at',
        public string $sortDirection = 'desc',
        public int $page = 1,
        public int $perPage = 20
    ) {}
    
    public function getCacheKey(): string
    {
        return 'users:search:' . md5(serialize([
            'search' => $this->search,
            'filters' => $this->filters,
            'sort' => $this->sortBy . ':' . $this->sortDirection,
            'page' => $this->page,
            'per_page' => $this->perPage,
        ]));
    }
    
    public function getCacheTtl(): int
    {
        return 300; // 5 minutes for search results
    }
    
    public function getCacheTags(): array
    {
        return ['users', 'user-search'];
    }
}

final class SearchUsersHandler implements QueryHandlerInterface
{
    public function handle(SearchUsers $query): LengthAwarePaginator
    {
        $builder = UserReadModel::query();
        
        // Apply search
        if ($query->search) {
            $builder->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query->search}%")
                  ->orWhere('email', 'like', "%{$query->search}%");
            });
        }
        
        // Apply filters
        foreach ($query->filters as $filter => $value) {
            $builder->where($filter, $value);
        }
        
        // Apply sorting
        $builder->orderBy($query->sortBy, $query->sortDirection);
        
        // Paginate
        return $builder->paginate(
            $query->perPage,
            ['*'],
            'page',
            $query->page
        );
    }
}
```

### Query Bus Usage

```php
use LaravelModularDDD\CQRS\Contracts\QueryBusInterface;

class UserController extends Controller
{
    public function show(string $id, QueryBusInterface $queryBus)
    {
        $query = new GetUser(new UserId($id));
        $user = $queryBus->ask($query);
        
        return response()->json($user);
    }
    
    public function index(Request $request, QueryBusInterface $queryBus)
    {
        $query = new SearchUsers(
            search: $request->input('search', ''),
            filters: $request->input('filters', []),
            sortBy: $request->input('sort_by', 'created_at'),
            sortDirection: $request->input('sort_direction', 'desc'),
            page: $request->input('page', 1),
            perPage: $request->input('per_page', 20)
        );
        
        $users = $queryBus->ask($query);
        
        return response()->json($users);
    }
}
```

## Multi-Tier Caching System

The package implements a sophisticated three-tier caching system:

### L1 Cache (Memory)

**Purpose**: Ultra-fast access for frequently used data

```php
class MemoryCache
{
    private array $cache = [];
    private int $maxEntries;
    private int $maxMemoryMb;
    
    public function get(string $key): mixed
    {
        return $this->cache[$key] ?? null;
    }
    
    public function put(string $key, mixed $value, int $ttl): void
    {
        // Check memory limits
        if ($this->shouldEvict()) {
            $this->evictLeastRecentlyUsed();
        }
        
        $this->cache[$key] = [
            'value' => $value,
            'expires_at' => time() + $ttl,
            'last_accessed' => time(),
        ];
    }
    
    private function shouldEvict(): bool
    {
        return count($this->cache) >= $this->maxEntries 
            || $this->getMemoryUsage() >= $this->maxMemoryMb;
    }
}
```

### L2 Cache (Redis)

**Purpose**: Shared cache across application instances

```php
class RedisCache
{
    public function get(string $key): mixed
    {
        $data = Redis::get($key);
        return $data ? unserialize($data) : null;
    }
    
    public function put(string $key, mixed $value, int $ttl): void
    {
        Redis::setex($key, $ttl, serialize($value));
    }
    
    public function tags(array $tags): self
    {
        // Tag-based invalidation
        foreach ($tags as $tag) {
            Redis::sadd("tag:{$tag}", $key);
        }
        
        return $this;
    }
    
    public function flush(array $tags): void
    {
        foreach ($tags as $tag) {
            $keys = Redis::smembers("tag:{$tag}");
            if ($keys) {
                Redis::del($keys);
                Redis::del("tag:{$tag}");
            }
        }
    }
}
```

### L3 Cache (Database)

**Purpose**: Persistent caching for expensive computations

```sql
CREATE TABLE query_cache (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    cache_key VARCHAR(255) UNIQUE NOT NULL,
    data JSON NOT NULL,
    tags JSON,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_expires_at (expires_at),
    INDEX idx_tags (tags)
);
```

### Multi-Tier Cache Manager

```php
class MultiTierCacheManager
{
    public function __construct(
        private MemoryCache $l1Cache,
        private RedisCache $l2Cache,
        private DatabaseCache $l3Cache
    ) {}
    
    public function get(string $key): mixed
    {
        // Try L1 first
        $value = $this->l1Cache->get($key);
        if ($value !== null) {
            return $value;
        }
        
        // Try L2
        $value = $this->l2Cache->get($key);
        if ($value !== null) {
            // Promote to L1
            $this->l1Cache->put($key, $value, 300);
            return $value;
        }
        
        // Try L3
        $value = $this->l3Cache->get($key);
        if ($value !== null) {
            // Promote to L2 and L1
            $this->l2Cache->put($key, $value, 900);
            $this->l1Cache->put($key, $value, 300);
            return $value;
        }
        
        return null;
    }
    
    public function put(string $key, mixed $value, int $ttl, array $tags = []): void
    {
        // Write to all tiers with appropriate TTLs
        $this->l1Cache->put($key, $value, min($ttl, 300));
        $this->l2Cache->put($key, $value, min($ttl, 3600));
        $this->l3Cache->put($key, $value, $ttl, $tags);
    }
    
    public function invalidate(array $tags): void
    {
        $this->l1Cache->flush($tags);
        $this->l2Cache->flush($tags);
        $this->l3Cache->flush($tags);
    }
}
```

### Cache Configuration

```php
'cqrs' => [
    'query_bus' => [
        'cache_enabled' => env('QUERY_CACHE_ENABLED', true),
        'cache_ttl' => env('QUERY_CACHE_TTL', 900), // 15 minutes
        'cache_driver' => env('QUERY_CACHE_DRIVER', 'redis'),
        
        // Multi-tier cache stores
        'cache_stores' => [
            'l2' => env('QUERY_L2_CACHE_STORE', 'redis'),
            'l3' => env('QUERY_L3_CACHE_STORE', 'database'),
        ],
        
        // Memory management for L1 cache
        'memory_limits' => [
            'l1_max_entries' => env('QUERY_L1_MAX_ENTRIES', 1000),
            'max_memory_mb' => env('QUERY_MAX_MEMORY_MB', 128),
            'eviction_threshold' => env('QUERY_EVICTION_THRESHOLD', 0.8),
        ],
    ],
],
```

## Middleware Pipeline

The command bus uses a middleware pipeline for cross-cutting concerns:

### Validation Middleware

```php
class ValidationMiddleware implements MiddlewareInterface
{
    public function handle(CommandInterface $command, callable $next): mixed
    {
        // Check if command has validation rules
        if (method_exists($command, 'rules')) {
            $validator = Validator::make(
                $this->extractData($command),
                $command->rules()
            );
            
            if ($validator->fails()) {
                throw new ValidationException($validator);
            }
        }
        
        return $next($command);
    }
    
    private function extractData(CommandInterface $command): array
    {
        $reflection = new \ReflectionClass($command);
        $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);
        
        $data = [];
        foreach ($properties as $property) {
            $data[$property->getName()] = $property->getValue($command);
        }
        
        return $data;
    }
}
```

### Authorization Middleware

```php
class AuthorizationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private CommandAuthorizationManager $authManager
    ) {}
    
    public function handle(CommandInterface $command, callable $next): mixed
    {
        // Check command-level authorization
        if (method_exists($command, 'authorize')) {
            if (!$command->authorize()) {
                throw new UnauthorizedException(
                    'User not authorized to execute this command'
                );
            }
        }
        
        // Check policy-based authorization
        if (!$this->authManager->authorize($command)) {
            throw new UnauthorizedException(
                'Access denied by authorization policy'
            );
        }
        
        return $next($command);
    }
}
```

### Transaction Middleware

```php
class TransactionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private TransactionManagerInterface $transactionManager
    ) {}
    
    public function handle(CommandInterface $command, callable $next): mixed
    {
        return $this->transactionManager->transaction(function () use ($command, $next) {
            return $next($command);
        });
    }
}
```

### Event Dispatching Middleware

```php
class EventDispatchingMiddleware implements MiddlewareInterface
{
    public function handle(CommandInterface $command, callable $next): mixed
    {
        $result = $next($command);
        
        // Dispatch any recorded domain events
        $events = $this->extractDomainEvents($result);
        foreach ($events as $event) {
            event($event);
        }
        
        return $result;
    }
}
```

### Logging Middleware

```php
class LoggingMiddleware implements MiddlewareInterface
{
    public function handle(CommandInterface $command, callable $next): mixed
    {
        $start = microtime(true);
        $commandClass = get_class($command);
        
        Log::info("Executing command: {$commandClass}");
        
        try {
            $result = $next($command);
            
            $duration = (microtime(true) - $start) * 1000;
            Log::info("Command completed: {$commandClass}", [
                'duration_ms' => $duration,
                'result_type' => gettype($result),
            ]);
            
            return $result;
        } catch (\Throwable $e) {
            $duration = (microtime(true) - $start) * 1000;
            Log::error("Command failed: {$commandClass}", [
                'duration_ms' => $duration,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw $e;
        }
    }
}
```

## Async Processing

### Async Command Execution

```php
class LaravelQueueStrategy implements AsyncStrategyInterface
{
    public function __construct(
        private AsyncStatusRepository $statusRepository,
        private string $queue = 'commands'
    ) {}
    
    public function execute(CommandInterface $command): string
    {
        $jobId = Str::uuid()->toString();
        
        // Store initial status
        $this->statusRepository->create($jobId, 'pending', $command);
        
        // Dispatch to queue
        ProcessAsyncCommand::dispatch($command, $jobId)
            ->onQueue($this->queue);
        
        return $jobId;
    }
    
    public function getStatus(string $jobId): AsyncStatus
    {
        return $this->statusRepository->find($jobId);
    }
}
```

### Async Command Job

```php
class ProcessAsyncCommand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public function __construct(
        private CommandInterface $command,
        private string $jobId
    ) {}
    
    public function handle(
        CommandBusInterface $commandBus,
        AsyncStatusRepository $statusRepository
    ): void {
        try {
            $statusRepository->updateStatus($this->jobId, 'processing');
            
            $result = $commandBus->dispatch($this->command);
            
            $statusRepository->complete($this->jobId, $result);
        } catch (\Throwable $e) {
            $statusRepository->fail($this->jobId, $e->getMessage());
            throw $e;
        }
    }
    
    public function failed(\Throwable $exception): void
    {
        app(AsyncStatusRepository::class)->fail(
            $this->jobId,
            $exception->getMessage()
        );
    }
}
```

### Async Status Tracking

```php
class AsyncStatus
{
    public function __construct(
        public readonly string $jobId,
        public readonly string $status, // pending, processing, completed, failed
        public readonly ?mixed $result = null,
        public readonly ?string $error = null,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $completedAt = null
    ) {}
    
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
    
    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }
    
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
    
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
```

## Authorization and Security

### Command Authorization Manager

```php
class CommandAuthorizationManager
{
    private array $policies = [];
    
    public function registerPolicy(string $commandClass, callable $policy): void
    {
        $this->policies[$commandClass] = $policy;
    }
    
    public function authorize(CommandInterface $command): bool
    {
        $commandClass = get_class($command);
        
        // Check registered policies
        if (isset($this->policies[$commandClass])) {
            return $this->policies[$commandClass]($command, auth()->user());
        }
        
        // Check Laravel gates
        $gateName = $this->getGateName($commandClass);
        if (Gate::has($gateName)) {
            return Gate::allows($gateName, $command);
        }
        
        // Default: allow if no specific policy
        return true;
    }
    
    private function getGateName(string $commandClass): string
    {
        // Convert class name to gate name
        // CreateUser -> create-user
        $className = class_basename($commandClass);
        return Str::kebab($className);
    }
}
```

### Security Policies Example

```php
// In a service provider
class AuthorizationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $authManager = app(CommandAuthorizationManager::class);
        
        // Register command-specific policies
        $authManager->registerPolicy(
            CreateUser::class,
            function (CreateUser $command, ?User $user) {
                return $user?->can('create-users') ?? false;
            }
        );
        
        $authManager->registerPolicy(
            DeleteUser::class,
            function (DeleteUser $command, ?User $user) {
                // Can only delete if admin or own account
                return $user?->isAdmin() 
                    || $user?->id === $command->userId->toString();
            }
        );
    }
}
```

## Performance Optimization

### Query Performance Monitoring

```php
class QueryPerformanceMiddleware implements MiddlewareInterface
{
    public function handle(QueryInterface $query, callable $next): mixed
    {
        $start = microtime(true);
        
        $result = $next($query);
        
        $duration = (microtime(true) - $start) * 1000;
        
        // Log slow queries
        if ($duration > config('modular-ddd.performance.monitoring.slow_query_threshold')) {
            Log::warning('Slow query detected', [
                'query' => get_class($query),
                'duration_ms' => $duration,
                'cache_hit' => $this->wasCacheHit($query),
            ]);
        }
        
        // Collect metrics
        app(MetricsCollectorInterface::class)->recordQueryExecution(
            get_class($query),
            $duration,
            $this->wasCacheHit($query)
        );
        
        return $result;
    }
}
```

### Cache Hit Rate Monitoring

```php
class CacheMetrics
{
    private array $stats = [
        'hits' => 0,
        'misses' => 0,
        'total_queries' => 0,
    ];
    
    public function recordHit(): void
    {
        $this->stats['hits']++;
        $this->stats['total_queries']++;
    }
    
    public function recordMiss(): void
    {
        $this->stats['misses']++;
        $this->stats['total_queries']++;
    }
    
    public function getHitRate(): float
    {
        if ($this->stats['total_queries'] === 0) {
            return 0.0;
        }
        
        return $this->stats['hits'] / $this->stats['total_queries'];
    }
    
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'hit_rate' => $this->getHitRate(),
        ]);
    }
}
```

### Memory Usage Optimization

```php
class MemoryOptimizedQueryBus implements QueryBusInterface
{
    public function ask(QueryInterface $query): mixed
    {
        $memoryBefore = memory_get_usage(true);
        
        try {
            $result = $this->processQuery($query);
            
            $memoryAfter = memory_get_usage(true);
            $memoryUsed = $memoryAfter - $memoryBefore;
            
            // Check memory threshold
            if ($memoryUsed > $this->config['memory_threshold']) {
                Log::warning('High memory usage in query', [
                    'query' => get_class($query),
                    'memory_used_mb' => $memoryUsed / 1024 / 1024,
                ]);
            }
            
            return $result;
        } finally {
            // Force garbage collection for large queries
            if (memory_get_usage(true) > 100 * 1024 * 1024) { // 100MB
                gc_collect_cycles();
            }
        }
    }
}
```

## Advanced Patterns

### Query Composition

```php
class CompositeQuery implements QueryInterface
{
    public function __construct(
        private array $queries
    ) {}
    
    public function getQueries(): array
    {
        return $this->queries;
    }
}

class CompositeQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private QueryBusInterface $queryBus
    ) {}
    
    public function handle(CompositeQuery $query): array
    {
        $results = [];
        
        foreach ($query->getQueries() as $subQuery) {
            $results[] = $this->queryBus->ask($subQuery);
        }
        
        return $results;
    }
}
```

### Command Aggregation

```php
class BatchCommand implements CommandInterface
{
    public function __construct(
        private array $commands
    ) {}
    
    public function getCommands(): array
    {
        return $this->commands;
    }
}

class BatchCommandHandler implements CommandHandlerInterface
{
    public function handle(BatchCommand $command): array
    {
        $results = [];
        
        DB::transaction(function () use ($command, &$results) {
            foreach ($command->getCommands() as $subCommand) {
                $results[] = $this->commandBus->dispatch($subCommand);
            }
        });
        
        return $results;
    }
}
```

### Circuit Breaker Pattern

```php
class CircuitBreakerMiddleware implements MiddlewareInterface
{
    private array $failures = [];
    private array $lastFailure = [];
    
    public function handle(CommandInterface $command, callable $next): mixed
    {
        $commandClass = get_class($command);
        
        // Check if circuit is open
        if ($this->isCircuitOpen($commandClass)) {
            throw new CircuitBreakerOpenException(
                "Circuit breaker open for {$commandClass}"
            );
        }
        
        try {
            $result = $next($command);
            
            // Reset failure count on success
            $this->failures[$commandClass] = 0;
            
            return $result;
        } catch (\Throwable $e) {
            // Increment failure count
            $this->failures[$commandClass] = ($this->failures[$commandClass] ?? 0) + 1;
            $this->lastFailure[$commandClass] = time();
            
            throw $e;
        }
    }
    
    private function isCircuitOpen(string $commandClass): bool
    {
        $failures = $this->failures[$commandClass] ?? 0;
        $threshold = config('cqrs.circuit_breaker.failure_threshold', 5);
        $timeout = config('cqrs.circuit_breaker.timeout', 60);
        
        if ($failures < $threshold) {
            return false;
        }
        
        $lastFailure = $this->lastFailure[$commandClass] ?? 0;
        return (time() - $lastFailure) < $timeout;
    }
}
```

## Best Practices

### 1. Command Design

**Good Command Design:**
```php
// ✅ Good: Immutable, intention-revealing, self-contained
final readonly class TransferMoney implements CommandInterface
{
    public function __construct(
        public AccountId $fromAccount,
        public AccountId $toAccount,
        public Money $amount,
        public string $reference
    ) {}
    
    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:0.01',
            'reference' => 'required|string|max:255',
        ];
    }
}
```

**Poor Command Design:**
```php
// ❌ Bad: Mutable, unclear intention, missing validation
class AccountCommand
{
    public string $action;
    public array $data;
}
```

### 2. Query Optimization

```php
// ✅ Good: Specific, cacheable, optimized
final readonly class GetUserDashboard implements QueryInterface, CacheableInterface
{
    public function __construct(
        public UserId $userId
    ) {}
    
    public function getCacheKey(): string
    {
        return "dashboard:user:{$this->userId->toString()}";
    }
    
    public function getCacheTags(): array
    {
        return ['dashboards', "user:{$this->userId->toString()}"];
    }
}
```

### 3. Handler Responsibility

```php
// ✅ Good: Single responsibility, clear dependencies
final class ProcessPaymentHandler implements CommandHandlerInterface
{
    public function __construct(
        private PaymentGateway $gateway,
        private EventStore $eventStore,
        private NotificationService $notifications
    ) {}
    
    public function handle(ProcessPayment $command): PaymentResult
    {
        // Single responsibility: process payment
        $result = $this->gateway->charge(
            $command->amount,
            $command->paymentMethod
        );
        
        // Record events
        $events = $result->isSuccessful() 
            ? [new PaymentSucceeded(/* ... */)]
            : [new PaymentFailed(/* ... */)];
            
        $this->eventStore->append($command->orderId, $events);
        
        return $result;
    }
}
```

### 4. Error Handling

```php
class RobustCommandHandler implements CommandHandlerInterface
{
    public function handle(CommandInterface $command): mixed
    {
        try {
            return $this->doHandle($command);
        } catch (DomainException $e) {
            // Business rule violation - don't retry
            Log::info('Domain exception in command', [
                'command' => get_class($command),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } catch (InfrastructureException $e) {
            // Infrastructure issue - can be retried
            Log::error('Infrastructure exception in command', [
                'command' => get_class($command),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
```

### 5. Testing

```php
class CommandHandlerTest extends TestCase
{
    public function test_can_create_user(): void
    {
        // Arrange
        $command = new CreateUser(
            email: 'test@example.com',
            name: 'Test User'
        );
        
        // Act
        $userId = $this->commandBus->dispatch($command);
        
        // Assert
        $this->assertInstanceOf(UserId::class, $userId);
        $this->assertEventWasRecorded(UserCreated::class);
        
        // Verify side effects
        $user = $this->queryBus->ask(new GetUser($userId));
        $this->assertEquals('test@example.com', $user->email);
    }
    
    public function test_handles_validation_errors(): void
    {
        $command = new CreateUser(
            email: 'invalid-email',
            name: ''
        );
        
        $this->expectException(ValidationException::class);
        $this->commandBus->dispatch($command);
    }
}
```

This comprehensive CQRS guide covers all aspects of the implementation in the Laravel Modular DDD package. The system is designed for scalability, performance, and maintainability in production environments.
