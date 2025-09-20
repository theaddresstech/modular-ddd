# Getting Started Guide

This guide will help you install, configure, and create your first module using the Laravel Modular DDD package.

## Table of Contents

- [Installation](#installation)
- [Configuration](#configuration)
- [Environment Setup](#environment-setup)
- [Creating Your First Module](#creating-your-first-module)
- [Understanding the Architecture](#understanding-the-architecture)
- [Next Steps](#next-steps)

## Installation

### Requirements

- PHP 8.2 or higher
- Laravel 11.0 or higher
- MySQL 8.0 or PostgreSQL 13+ (for event store)
- Redis 6.0+ (for caching and hot tier storage)

### Step 1: Install the Package

```bash
composer require laravel/modular-ddd
```

### Step 2: Publish Configuration and Migrations

```bash
# Publish configuration file
php artisan vendor:publish --provider="LaravelModularDDD\ModularDddServiceProvider" --tag="config"

# Run migrations to create event store tables
php artisan migrate
```

### Step 3: Publish Stubs (Optional)

```bash
# Publish code generation stubs for customization
php artisan vendor:publish --provider="LaravelModularDDD\ModularDddServiceProvider" --tag="stubs"
```

## Configuration

### Basic Configuration

The package is configured through the `config/modular-ddd.php` file. Here's a minimal configuration to get started:

```php
<?php

return [
    // Module configuration
    'modules_path' => base_path('modules'),
    'module_namespace' => 'Modules',

    // Event sourcing (basic setup)
    'event_sourcing' => [
        'enabled' => true,
        'storage_tiers' => [
            'hot' => [
                'driver' => 'redis',
                'enabled' => env('EVENT_STORE_HOT_ENABLED', true),
                'ttl' => 86400, // 24 hours
            ],
            'warm' => [
                'driver' => 'mysql',
                'table' => 'event_store',
            ],
        ],
        'snapshots' => [
            'enabled' => true,
            'strategy' => 'simple',
            'threshold' => 10, // Snapshot every 10 events
        ],
    ],

    // CQRS configuration
    'cqrs' => [
        'command_bus' => [
            'default_mode' => 'sync',
            'middleware' => [
                'validation' => true,
                'authorization' => true,
                'transactions' => true,
            ],
        ],
        'query_bus' => [
            'cache_enabled' => true,
            'cache_ttl' => 900, // 15 minutes
        ],
    ],

    // Module communication
    'module_communication' => [
        'enabled' => true,
        'async_processing' => [
            'enabled' => true,
            'queue' => 'modules',
        ],
    ],
];
```

### Environment Variables

Add these variables to your `.env` file:

```env
# Event Sourcing
EVENT_SOURCING_ENABLED=true
EVENT_STORE_HOT_ENABLED=true
EVENT_STORE_HOT_TTL=86400
SNAPSHOT_STRATEGY=simple
SNAPSHOT_THRESHOLD=10

# CQRS
CQRS_DEFAULT_MODE=sync
QUERY_CACHE_ENABLED=true
QUERY_CACHE_TTL=900

# Module Communication
MODULE_COMMUNICATION_ENABLED=true
MODULE_ASYNC_ENABLED=true
MODULE_COMMUNICATION_QUEUE=modules

# Monitoring
DDD_MONITORING_ENABLED=true
PERFORMANCE_COMMAND_THRESHOLD=200
PERFORMANCE_QUERY_THRESHOLD=100

# Security
AUDIT_LOGGING_ENABLED=true
DDD_ACCESS_CONTROL=true
```

## Environment Setup

### Database Configuration

Ensure your database connection is properly configured in `config/database.php`:

```php
'mysql' => [
    'driver' => 'mysql',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '3306'),
    'database' => env('DB_DATABASE', 'laravel'),
    'username' => env('DB_USERNAME', 'forge'),
    'password' => env('DB_PASSWORD', ''),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => true,
    'engine' => 'InnoDB',
    // Important for event sourcing
    'options' => [
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
],
```

### Redis Configuration

Configure Redis for caching and hot tier storage in `config/database.php`:

```php
'redis' => [
    'client' => env('REDIS_CLIENT', 'phpredis'),
    'options' => [
        'cluster' => env('REDIS_CLUSTER', 'redis'),
        'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_database_'),
    ],
    'default' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_DB', '0'),
    ],
],
```

### Queue Configuration

For async processing, configure queues in `config/queue.php`:

```php
'connections' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => env('REDIS_QUEUE', 'default'),
        'retry_after' => 90,
        'block_for' => null,
    ],
],
```

## Creating Your First Module

### Step 1: Generate Module Structure

```bash
php artisan ddd:make:module UserManagement
```

This creates a complete module structure:

```
modules/UserManagement/
├── Domain/
│   ├── Aggregates/
│   │   └── User.php
│   ├── Entities/
│   ├── ValueObjects/
│   │   ├── UserId.php
│   │   └── Email.php
│   ├── Events/
│   │   ├── UserCreated.php
│   │   └── UserUpdated.php
│   └── Services/
├── Application/
│   ├── Commands/
│   │   ├── CreateUser.php
│   │   └── UpdateUser.php
│   ├── Queries/
│   │   ├── GetUser.php
│   │   └── ListUsers.php
│   ├── Handlers/
│   │   ├── CreateUserHandler.php
│   │   └── GetUserHandler.php
│   └── Projectors/
│       └── UserProjector.php
├── Infrastructure/
│   ├── Repositories/
│   │   └── UserRepository.php
│   └── Projections/
│       └── UserReadModel.php
├── Presentation/
│   ├── Controllers/
│   │   └── UserController.php
│   ├── Resources/
│   └── Requests/
└── Tests/
    ├── Unit/
    ├── Integration/
    └── Feature/
```

### Step 2: Implement Domain Logic

**User Aggregate (Domain/Aggregates/User.php)**

```php
<?php

namespace Modules\UserManagement\Domain\Aggregates;

use LaravelModularDDD\Core\Domain\AggregateRoot;
use Modules\UserManagement\Domain\ValueObjects\UserId;
use Modules\UserManagement\Domain\ValueObjects\Email;
use Modules\UserManagement\Domain\Events\UserCreated;
use Modules\UserManagement\Domain\Events\UserUpdated;

class User extends AggregateRoot
{
    private function __construct(
        private UserId $id,
        private Email $email,
        private string $name,
        private \DateTimeImmutable $createdAt
    ) {}

    public static function create(
        UserId $id,
        Email $email,
        string $name
    ): self {
        $user = new self(
            $id,
            $email,
            $name,
            new \DateTimeImmutable()
        );

        $user->recordEvent(new UserCreated(
            $id,
            $email,
            $name,
            new \DateTimeImmutable()
        ));

        return $user;
    }

    public function updateEmail(Email $email): void
    {
        if ($this->email->equals($email)) {
            return;
        }

        $oldEmail = $this->email;
        $this->email = $email;

        $this->recordEvent(new UserUpdated(
            $this->id,
            $oldEmail,
            $email,
            new \DateTimeImmutable()
        ));
    }

    // Getters
    public function getId(): UserId { return $this->id; }
    public function getEmail(): Email { return $this->email; }
    public function getName(): string { return $this->name; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
```

### Step 3: Implement Commands and Handlers

**Create User Command (Application/Commands/CreateUser.php)**

```php
<?php

namespace Modules\UserManagement\Application\Commands;

use LaravelModularDDD\CQRS\Contracts\CommandInterface;

final readonly class CreateUser implements CommandInterface
{
    public function __construct(
        public string $email,
        public string $name
    ) {}
}
```

**Command Handler (Application/Handlers/CreateUserHandler.php)**

```php
<?php

namespace Modules\UserManagement\Application\Handlers;

use LaravelModularDDD\CQRS\Contracts\CommandHandlerInterface;
use LaravelModularDDD\Core\Infrastructure\Repository\EventSourcedAggregateRepository;
use Modules\UserManagement\Application\Commands\CreateUser;
use Modules\UserManagement\Domain\Aggregates\User;
use Modules\UserManagement\Domain\ValueObjects\UserId;
use Modules\UserManagement\Domain\ValueObjects\Email;

final class CreateUserHandler implements CommandHandlerInterface
{
    public function __construct(
        private EventSourcedAggregateRepository $repository
    ) {}

    public function handle(CreateUser $command): UserId
    {
        $userId = UserId::generate();
        $email = new Email($command->email);

        $user = User::create($userId, $email, $command->name);

        $this->repository->save($user);

        return $userId;
    }
}
```

### Step 4: Register Module

Create a module service provider:

```php
<?php

namespace Modules\UserManagement;

use LaravelModularDDD\ModuleServiceProvider;
use Modules\UserManagement\Application\Handlers\CreateUserHandler;
use Modules\UserManagement\Application\Commands\CreateUser;

class UserManagementServiceProvider extends ModuleServiceProvider
{
    protected array $commandHandlers = [
        CreateUser::class => CreateUserHandler::class,
    ];

    protected array $queryHandlers = [
        // Query handlers will be registered here
    ];

    protected array $projectors = [
        // Projectors will be registered here
    ];
}
```

### Step 5: Test Your Module

```php
<?php

namespace Modules\UserManagement\Tests\Feature;

use Tests\TestCase;
use LaravelModularDDD\CQRS\Contracts\CommandBusInterface;
use Modules\UserManagement\Application\Commands\CreateUser;

class CreateUserTest extends TestCase
{
    public function test_can_create_user(): void
    {
        $commandBus = app(CommandBusInterface::class);

        $userId = $commandBus->dispatch(new CreateUser(
            email: 'john@example.com',
            name: 'John Doe'
        ));

        $this->assertNotNull($userId);
        
        // Assert events were recorded
        $this->assertEventWasRecorded('UserCreated');
    }
}
```

## Understanding the Architecture

### Command Flow

1. **HTTP Request** → Controller receives request
2. **Command Creation** → Request data converted to command
3. **Command Bus** → Command routed through middleware pipeline
4. **Validation** → Command validated
5. **Authorization** → User permissions checked
6. **Transaction** → Database transaction started
7. **Handler Execution** → Business logic executed
8. **Event Storage** → Events saved to event store
9. **Commit** → Transaction committed
10. **Event Publishing** → Events published for projections

### Query Flow

1. **HTTP Request** → Controller receives request
2. **Query Creation** → Request converted to query
3. **Cache Check** → Multi-tier cache consulted
4. **Handler Execution** → Query handler executed (if cache miss)
5. **Response** → Data returned to client

### Event Processing

Events are processed through projectors that update read models:

```php
class UserProjector extends AbstractProjector
{
    public function whenUserCreated(UserCreated $event): void
    {
        // Update read model
        DB::table('user_read_models')->insert([
            'id' => $event->userId->toString(),
            'email' => $event->email->toString(),
            'name' => $event->name,
            'created_at' => $event->occurredAt,
        ]);
    }
}
```

## Next Steps

### Production Checklist

- [ ] Configure Redis clustering for high availability
- [ ] Set up database read replicas for query scaling
- [ ] Configure queue workers for async processing
- [ ] Set up monitoring and health checks
- [ ] Configure proper backup strategies
- [ ] Set up log aggregation
- [ ] Configure security policies

### Learning Path

1. **[Event Sourcing Guide](../event-sourcing/README.md)** - Deep dive into event sourcing patterns
2. **[CQRS Guide](../cqrs/README.md)** - Master command and query patterns
3. **[Module Communication](../module-communication/README.md)** - Inter-module messaging
4. **[Performance Guide](../performance/README.md)** - Optimization strategies
5. **[Production Deployment](../production/README.md)** - Production best practices

### Common Patterns

- **[Aggregates and Entities](../examples/aggregates.md)** - Domain modeling patterns
- **[Value Objects](../examples/value-objects.md)** - Immutable value types
- **[Domain Events](../examples/domain-events.md)** - Event design patterns
- **[Projections](../examples/projections.md)** - Read model strategies
- **[Sagas](../examples/sagas.md)** - Long-running processes

### Tools and Utilities

```bash
# Code generation
php artisan ddd:make:aggregate User --module=UserManagement
php artisan ddd:make:command CreateUser --module=UserManagement
php artisan ddd:make:query GetUser --module=UserManagement

# Testing
php artisan ddd:test:events
php artisan ddd:test:projections
php artisan ddd:benchmark

# Monitoring
php artisan ddd:health
php artisan ddd:metrics
php artisan ddd:performance
```

Congratulations! You now have a working DDD module with event sourcing and CQRS. The next guides will help you master the advanced features and production deployment strategies.
