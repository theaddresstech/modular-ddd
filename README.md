# Laravel Modular DDD

[![Latest Version on Packagist](https://img.shields.io/packagist/v/theaddresstech/modular-ddd.svg?style=flat-square)](https://packagist.org/packages/theaddresstech/modular-ddd)
[![Total Downloads](https://img.shields.io/packagist/dt/theaddresstech/modular-ddd.svg?style=flat-square)](https://packagist.org/packages/theaddresstech/modular-ddd)
[![License](https://img.shields.io/packagist/l/theaddresstech/modular-ddd.svg?style=flat-square)](https://packagist.org/packages/theaddresstech/modular-ddd)
[![PHP Version](https://img.shields.io/packagist/php-v/theaddresstech/modular-ddd.svg?style=flat-square)](https://packagist.org/packages/theaddresstech/modular-ddd)

A production-ready Laravel package for building modular applications using Domain-Driven Design (DDD) with Event Sourcing and CQRS patterns. This package provides a complete infrastructure for implementing DDD in Laravel applications with enterprise-grade features.

## Features

### 🏗️ Domain-Driven Design Architecture
- **Modular Structure**: Organize your application into bounded contexts
- **Aggregate Pattern**: First-class support for DDD aggregates
- **Value Objects**: Built-in value object support
- **Domain Events**: Complete event-driven architecture
- **Repository Pattern**: Clean abstraction for data access

### 📦 Event Sourcing
- **Event Store**: High-performance event storage with tiered architecture
- **Snapshots**: Multiple snapshot strategies (Simple, Adaptive, Time-based)
- **Event Replay**: Rebuild aggregate state from events
- **Event Versioning**: Built-in support for event schema evolution
- **Projections**: Automatic projection updates from events

### 🚀 CQRS (Command Query Responsibility Segregation)
- **Command Bus**: Pipeline-based command processing
- **Query Bus**: Optimized query handling with caching
- **Multi-tier Caching**: L1 (Memory) + L2 (Redis) + L3 (Database)
- **Async Processing**: Queue-based command execution
- **Middleware Pipeline**: Validation, authorization, and transaction handling

### 🔄 Module Communication
- **Event-driven Communication**: Loosely coupled module interaction
- **Message Bus**: Direct module-to-module messaging
- **Circuit Breakers**: Resilience patterns for module dependencies
- **Async Messaging**: Queue-based communication between modules

### 🛠️ Code Generation
- **Module Generator**: Scaffold complete DDD modules
- **Aggregate Generator**: Create aggregates with all components
- **Command/Query Generators**: Generate CQRS components
- **Repository Generator**: Create repository implementations
- **Event Generator**: Generate domain events

### 📊 Production Features
- **Health Monitoring**: Comprehensive health checks
- **Performance Metrics**: Real-time performance tracking
- **Circuit Breakers**: Automatic failure handling
- **Database Optimization**: Query optimization and indexing
- **Cache Management**: Intelligent cache invalidation

## Requirements

- PHP 8.2 or higher
- Laravel 11.0 or higher
- MySQL 8.0+ / PostgreSQL 13+ / SQLite 3.35+
- Redis (optional, for caching)
- Composer 2.0+

## Installation

You can install the package via Composer:

```bash
composer require theaddresstech/modular-ddd
```

After installation, publish the configuration and migrations:

```bash
php artisan vendor:publish --provider="LaravelModularDDD\ModularDddServiceProvider"
php artisan migrate
```

## Quick Start

### 1. Create a New Module

Generate a complete DDD module structure:

```bash
php artisan modular:make:module Sales
```

This creates:
```
Modules/Sales/
├── Application/
│   ├── Commands/
│   ├── Queries/
│   └── Services/
├── Domain/
│   ├── Aggregates/
│   ├── Events/
│   ├── Repositories/
│   └── ValueObjects/
└── Infrastructure/
    ├── Persistence/
    └── Providers/
```

### 2. Create an Aggregate

Generate an aggregate with event sourcing support:

```bash
php artisan modular:make:aggregate Order --module=Sales
```

### 3. Define Commands and Handlers

```php
// Modules/Sales/Application/Commands/CreateOrderCommand.php
namespace Modules\Sales\Application\Commands;

use LaravelModularDDD\CQRS\Command;

class CreateOrderCommand extends Command
{
    public function __construct(
        public readonly string $customerId,
        public readonly array $items,
        public readonly float $totalAmount
    ) {}
}
```

```php
// Modules/Sales/Application/Commands/CreateOrderHandler.php
namespace Modules\Sales\Application\Commands;

use LaravelModularDDD\CQRS\CommandHandler;
use Modules\Sales\Domain\Aggregates\Order;
use Modules\Sales\Domain\Repositories\OrderRepository;

class CreateOrderHandler extends CommandHandler
{
    public function __construct(
        private OrderRepository $repository
    ) {}

    public function handle(CreateOrderCommand $command): void
    {
        $order = Order::create(
            $command->customerId,
            $command->items,
            $command->totalAmount
        );

        $this->repository->save($order);
    }
}
```

### 4. Dispatch Commands

```php
use LaravelModularDDD\CQRS\CommandBus;
use Modules\Sales\Application\Commands\CreateOrderCommand;

class OrderController extends Controller
{
    public function __construct(
        private CommandBus $commandBus
    ) {}

    public function store(Request $request)
    {
        $command = new CreateOrderCommand(
            customerId: $request->customer_id,
            items: $request->items,
            totalAmount: $request->total
        );

        $this->commandBus->dispatch($command);

        return response()->json(['message' => 'Order created']);
    }
}
```

## Advanced Usage

### Event Sourcing with Snapshots

```php
use LaravelModularDDD\EventSourcing\EventStore;
use LaravelModularDDD\EventSourcing\SnapshotStore;

// Configure snapshot strategy
config(['modular-ddd.snapshots.strategy' => 'adaptive']);

// Load aggregate with automatic snapshot optimization
$order = $eventStore->load($orderId, Order::class);

// Events are automatically stored and snapshots created based on strategy
$order->process($payment);
$eventStore->save($order);
```

### CQRS with Multi-tier Caching

```php
use LaravelModularDDD\CQRS\QueryBus;
use Modules\Sales\Application\Queries\GetOrderDetailsQuery;

// Query with automatic caching
$query = new GetOrderDetailsQuery($orderId);
$orderDetails = $queryBus->ask($query);

// Cache is automatically managed across L1, L2, and L3 tiers
```

### Module Communication

```php
use LaravelModularDDD\Modules\ModuleBus;

// Send message to another module
$moduleBus->send('Inventory', 'ReserveStock', [
    'order_id' => $orderId,
    'items' => $items
]);

// Listen for module events
$moduleBus->listen('Payment', 'PaymentCompleted', function ($event) {
    // Handle payment completion
});
```

## Configuration

The package can be configured via `config/modular-ddd.php`:

```php
return [
    'modules_path' => base_path('Modules'),

    'event_store' => [
        'connection' => env('EVENT_STORE_CONNECTION', 'mysql'),
        'table' => 'event_store',
        'chunk_size' => 1000,
    ],

    'snapshots' => [
        'enabled' => true,
        'strategy' => 'adaptive', // simple, adaptive, time_based
        'threshold' => 100,
    ],

    'cqrs' => [
        'cache' => [
            'l1_enabled' => true,
            'l2_enabled' => true,
            'l3_enabled' => true,
            'ttl' => 3600,
        ],
        'async' => [
            'enabled' => false,
            'queue' => 'default',
        ],
    ],

    'performance' => [
        'profile' => 'balanced', // startup, growth, scale, enterprise
    ],
];
```

## Testing

The package includes comprehensive testing utilities:

```bash
# Run all tests
composer test

# Run specific test suites
composer test -- --testsuite=Unit
composer test -- --testsuite=Integration

# Run with coverage
composer test -- --coverage-html=coverage
```

### Testing Your Modules

```php
use LaravelModularDDD\Testing\AggregateTestCase;
use Modules\Sales\Domain\Aggregates\Order;
use Modules\Sales\Domain\Events\OrderCreated;

class OrderTest extends AggregateTestCase
{
    public function test_order_creation()
    {
        $this->given([])
            ->when(fn() => Order::create('customer-1', ['item-1'], 100.00))
            ->then([
                new OrderCreated('customer-1', ['item-1'], 100.00)
            ]);
    }
}
```

## Performance Optimization

### Choose the Right Performance Profile

```php
// config/modular-ddd.php
'performance' => [
    'profile' => 'enterprise', // For high-load applications
]
```

### Optimize Event Store Queries

```bash
php artisan modular:optimize:event-store
```

### Monitor Performance

```bash
php artisan modular:health:check
php artisan modular:metrics:show
```

## Documentation

Comprehensive documentation is available in the `/docs` directory:

- [Architecture Overview](docs/architecture/README.md)
- [Getting Started Guide](docs/getting-started/README.md)
- [Event Sourcing Guide](docs/event-sourcing/README.md)
- [CQRS Implementation](docs/cqrs/README.md)
- [Module Communication](docs/module-communication/README.md)
- [Production Deployment](docs/production/README.md)
- [API Reference](docs/api-reference/README.md)
- [Performance Tuning](docs/performance/README.md)

## Contributing

We welcome contributions! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details on how to contribute to this project.

### Development

```bash
# Clone the repository
git clone https://github.com/theaddresstech/modular-ddd.git
cd modular-ddd

# Install dependencies
composer install

# Run tests
composer test

# Run static analysis
composer analyse

# Fix code style
composer format
```

## Security

If you discover any security-related issues, please email security@laravel.com instead of using the issue tracker.

## Credits

- [The Address Tech](https://github.com/theaddresstech)
- [Laravel Team](https://github.com/laravel)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

## Support

- [Documentation](https://github.com/theaddresstech/modular-ddd/tree/main/docs)
- [Issues](https://github.com/theaddresstech/modular-ddd/issues)
- [Discussions](https://github.com/theaddresstech/modular-ddd/discussions)
- [Stack Overflow](https://stackoverflow.com/questions/tagged/laravel-modular-ddd)

---

Made with ❤️ by The Address Tech Team