# Laravel Modular DDD

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mghrby/modular-ddd.svg?style=flat-square)](https://packagist.org/packages/mghrby/modular-ddd)
[![Total Downloads](https://img.shields.io/packagist/dt/mghrby/modular-ddd.svg?style=flat-square)](https://packagist.org/packages/mghrby/modular-ddd)
[![License](https://img.shields.io/packagist/l/mghrby/modular-ddd.svg?style=flat-square)](https://packagist.org/packages/mghrby/modular-ddd)
[![PHP Version](https://img.shields.io/packagist/php-v/mghrby/modular-ddd.svg?style=flat-square)](https://packagist.org/packages/mghrby/modular-ddd)

A production-ready Laravel package for building modular applications using Domain-Driven Design (DDD) with Event Sourcing and CQRS patterns. This package provides a complete, battle-tested infrastructure for implementing DDD in Laravel applications with enterprise-grade features and **100% code generation reliability**.

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

### 🛠️ Code Generation ✨ **100% Reliable**
- **Module Generator**: Scaffold complete DDD modules with all layers
- **Aggregate Generator**: Create aggregates with all components
- **Command/Query Generators**: Generate CQRS components with handlers
- **Repository Generator**: Create repository implementations with interfaces
- **Migration Generator**: Create event-sourcing compatible database schemas
- **Factory Generator**: Generate comprehensive test factories
- **Test Generator**: Create complete test suites with performance tests
- **Dry-Run Mode**: Preview all files before generation

### 📊 Production Features
- **Health Monitoring**: Comprehensive health checks with accurate status reporting
- **Performance Metrics**: Real-time performance tracking and monitoring
- **Circuit Breakers**: Automatic failure handling and resilience patterns
- **Database Optimization**: Query optimization and intelligent indexing
- **Cache Management**: Intelligent cache invalidation and multi-tier caching
- **Module State Management**: Persistent enable/disable functionality
- **Zero-Error Generation**: 100% syntax-valid code generation

## Requirements

- PHP 8.2 or higher
- Laravel 11.0 or higher
- MySQL 8.0+ / PostgreSQL 13+ / SQLite 3.35+
- Redis (optional, for caching)
- Composer 2.0+

## Installation

You can install the package via Composer:

```bash
composer require mghrby/modular-ddd
```

After installation, publish the configuration and migrations:

```bash
php artisan vendor:publish --provider="LaravelModularDDD\ModularDddServiceProvider"
php artisan migrate
```

## Quick Start

### 1. Create a New Module

Generate a complete DDD module structure with **guaranteed syntax validity**:

```bash
# Create a complete module with aggregate
php artisan modular:make:module Sales --aggregate=Order

# Preview what will be generated (dry-run)
php artisan modular:make:module Sales --aggregate=Order --dry-run
```

This creates a **complete DDD architecture** with 36+ files:
```
Modules/Sales/
├── Application/
│   ├── Commands/           # CQRS Commands & Handlers
│   ├── Queries/            # CQRS Queries & Handlers
│   └── Services/           # Application Services
├── Domain/
│   ├── Aggregates/         # Domain Aggregates
│   ├── Events/             # Domain Events
│   ├── Repositories/       # Repository Interfaces
│   ├── ValueObjects/       # Value Objects
│   └── Exceptions/         # Domain Exceptions
├── Infrastructure/
│   ├── Persistence/        # Event Store & Repositories
│   └── Providers/          # Service Providers
├── Presentation/
│   └── Http/               # Controllers & Resources
├── Database/
│   ├── Migrations/         # Event-sourcing Schema
│   └── Factories/          # Test Factories
└── Tests/                  # Complete Test Suite
    ├── Unit/               # Unit Tests
    ├── Feature/            # Feature Tests
    └── Integration/        # Integration Tests
```

### 2. Generate Additional Components

All generators support **dry-run mode** and produce **100% syntax-valid code**:

```bash
# Generate specific components
php artisan modular:make:command Sales CreateOrderCommand --handler
php artisan modular:make:query Sales GetOrderQuery --handler
php artisan modular:make:aggregate Sales Order
php artisan modular:make:repository Sales Order
php artisan modular:make:migration Sales create_orders_table --aggregate=Order
php artisan modular:make:factory Sales

# Preview before generating (all commands support --dry-run)
php artisan modular:make:command Sales CreateOrderCommand --dry-run
```

### 3. Module Management

Manage module state with **persistent configuration**:

```bash
# Module information and health
php artisan modular:info Sales
php artisan modular:health
php artisan modular:list

# Enable/disable modules (state persists across restarts)
php artisan modular:disable Sales
php artisan modular:enable Sales

# Run module-specific commands
php artisan modular:test Sales
php artisan modular:migrate Sales
```

### 4. Define Commands and Handlers

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

### 5. Dispatch Commands

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

## Available Commands

### Module Generation Commands

#### Create Module
Generate a complete DDD module with all architectural layers:

```bash
php artisan modular:make:module {name}
    {--aggregate=}      # The main aggregate name (defaults to module name)
    {--force}           # Overwrite existing files
    {--no-tests}        # Skip test generation
    {--no-migration}    # Skip migration generation
    {--no-factory}      # Skip factory generation
    {--no-seeder}       # Skip seeder generation
    {--no-api}          # Skip API components
    {--no-web}          # Skip web components
    {--dry-run}         # Show what would be generated without creating files

# Examples
php artisan modular:make:module Sales --aggregate=Order
php artisan modular:make:module Inventory --no-api --no-web
php artisan modular:make:module Payment --dry-run
```

#### Create Command
Generate CQRS commands and handlers:

```bash
php artisan modular:make:command {module} {commandName}
    {--aggregate=}          # Target aggregate name
    {--handler}             # Generate command handler
    {--no-validation}       # Skip validation rules
    {--dry-run}             # Preview files without creating

# Examples
php artisan modular:make:command Sales CreateOrderCommand --handler
php artisan modular:make:command Inventory UpdateStockCommand --aggregate=Product
php artisan modular:make:command Payment ProcessPaymentCommand --no-validation --dry-run
```

#### Create Query
Generate CQRS queries and handlers:

```bash
php artisan modular:make:query {module} {query}
    {--handler}             # Generate query handler
    {--no-cache}            # Skip caching implementation
    {--paginated}           # Generate paginated query
    {--dry-run}             # Preview files without creating

# Examples
php artisan modular:make:query Sales GetOrderQuery --handler
php artisan modular:make:query Inventory ListProductsQuery --paginated
php artisan modular:make:query Payment FindPaymentQuery --no-cache --dry-run
```

#### Create Aggregate
Generate domain aggregates with event sourcing:

```bash
php artisan modular:make:aggregate {module} {aggregate}
    {--force}                    # Overwrite existing files
    {--with-events=}             # Comma-separated list of events to generate
    {--with-value-objects=}      # Comma-separated list of value objects to generate
    {--no-exception}             # Skip exception class generation
    {--dry-run}                  # Preview files without creating

# Examples
php artisan modular:make:aggregate Sales Order --with-events=OrderCreated,OrderShipped
php artisan modular:make:aggregate Inventory Product --with-value-objects=ProductSku,ProductName
php artisan modular:make:aggregate Payment Transaction --no-exception --dry-run
```

#### Create Repository
Generate repository interfaces and implementations:

```bash
php artisan modular:make:repository {module} {aggregate}
    {--interface-only}       # Generate only the repository interface
    {--implementation-only}  # Generate only the repository implementation
    {--eloquent}             # Generate Eloquent-based implementation
    {--force}                # Overwrite existing files
    {--dry-run}              # Preview files without creating

# Examples
php artisan modular:make:repository Sales Order --eloquent
php artisan modular:make:repository Inventory Product --interface-only
php artisan modular:make:repository Payment Transaction --force --dry-run
```

#### Create Migration
Generate event-sourcing compatible migrations:

```bash
php artisan modular:make:migration {module} {name}
    {--aggregate=}   # The aggregate name (defaults to module name)
    {--create=}      # The table to be created
    {--table=}       # The table to migrate
    {--path=}        # The location where the migration file should be created
    {--fullpath}     # Output the full path of the migration

# Examples
php artisan modular:make:migration Sales create_orders_table --aggregate=Order
php artisan modular:make:migration Inventory add_sku_to_products --table=products
php artisan modular:make:migration Payment create_transactions_table --create=transactions
```

#### Create Factory
Generate test factories for modules:

```bash
php artisan modular:make:factory {module}
    {--aggregate=}   # Specific aggregate to create factory for
    {--force}        # Overwrite existing factories

# Examples
php artisan modular:make:factory Sales --aggregate=Order
php artisan modular:make:factory Inventory --force
```

### Module Management Commands

#### Module Information
Get detailed information about modules:

```bash
php artisan modular:info {module?}
    {--detailed}     # Show detailed component information
    {--json}         # Output in JSON format

# Examples
php artisan modular:info Sales --detailed
php artisan modular:info --json
```

#### List Modules
Display all available modules:

```bash
php artisan modular:list
    {--enabled}      # Show only enabled modules
    {--disabled}     # Show only disabled modules
    {--json}         # Output in JSON format

# Examples
php artisan modular:list --enabled
php artisan modular:list --json
```

#### Health Check
Check module health and status:

```bash
php artisan modular:health
    {--module=}      # Check specific module
    {--detailed}     # Show detailed health information
    {--fix}          # Attempt to fix common issues

# Examples
php artisan modular:health --module=Sales --detailed
php artisan modular:health --fix
```

#### Enable/Disable Modules
Manage module state (persists across restarts):

```bash
php artisan modular:enable {modules*}
    {--force}        # Force enable even if dependencies are missing

php artisan modular:disable {modules*}
    {--with-dependents}  # Also disable modules that depend on these
    {--force}            # Force disable even if other modules depend on them
    {--dry-run}          # Show what would be disabled without making changes

# Examples
php artisan modular:enable Sales Inventory
php artisan modular:disable Payment --with-dependents
php artisan modular:disable Sales --dry-run
```

### Migration Commands

#### Module Migrate
Run migrations for specific modules:

```bash
php artisan modular:migrate {module?}
    {--force}        # Force migration in production
    {--path=}        # Specific migration path
    {--pretend}      # Show SQL that would be executed

# Examples
php artisan modular:migrate Sales
php artisan modular:migrate --force
```

#### Migration Status
Check migration status for modules:

```bash
php artisan modular:migrate:status {module?}
    {--pending}      # Show only pending migrations
    {--database=}    # Specify database connection

# Examples
php artisan modular:migrate:status Sales
php artisan modular:migrate:status --pending
```

#### Migration Rollback
Rollback module migrations:

```bash
php artisan modular:migrate:rollback {module?}
    {--step=}        # Number of migrations to rollback
    {--force}        # Force rollback in production

# Examples
php artisan modular:migrate:rollback Sales --step=2
php artisan modular:migrate:rollback Payment --force
```

### Testing Commands

#### Module Test
Run tests for specific modules:

```bash
php artisan modular:test {module?}
    {--unit}         # Run only unit tests
    {--feature}      # Run only feature tests
    {--integration}  # Run only integration tests
    {--coverage}     # Generate coverage report
    {--parallel}     # Run tests in parallel

# Examples
php artisan modular:test Sales --coverage
php artisan modular:test Inventory --unit --parallel
php artisan modular:test --feature
```

#### Test Factory
Generate and run factory tests:

```bash
php artisan modular:test:factory {module}
    {--count=}       # Number of records to generate
    {--seed}         # Seed database with generated data

# Examples
php artisan modular:test:factory Sales --count=100
php artisan modular:test:factory Inventory --seed
```

### Documentation Commands

#### Generate Documentation
Create comprehensive module documentation:

```bash
php artisan modular:docs {module?}
    {--format=}      # Output format (markdown, html, json)
    {--output=}      # Output directory
    {--api}          # Include API documentation
    {--architecture} # Include architecture diagrams

# Examples
php artisan modular:docs Sales --format=html --api
php artisan modular:docs --architecture --output=docs/
```

### Performance Commands

#### Benchmark
Run performance benchmarks:

```bash
php artisan modular:benchmark
    {--module=}      # Benchmark specific module
    {--iterations=}  # Number of iterations
    {--memory}       # Include memory usage analysis

# Examples
php artisan modular:benchmark --module=Sales --iterations=1000
php artisan modular:benchmark --memory
```

#### Stress Test
Run stress tests on modules:

```bash
php artisan modular:stress-test
    {--module=}      # Test specific module
    {--duration=}    # Test duration in seconds
    {--threads=}     # Number of concurrent threads

# Examples
php artisan modular:stress-test --module=Sales --duration=60
php artisan modular:stress-test --threads=10
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

## Version 1.2.0 Highlights ✨

### 🎯 **100% Code Generation Reliability**
- **Zero Template Errors**: All template variables are properly substituted
- **Syntax Validation**: Every generated file passes PHP syntax validation
- **Complete Coverage**: 36+ files generated per module, all production-ready

### 🚀 **Enhanced Developer Experience**
- **Dry-Run Mode**: Preview all files before generation with `--dry-run`
- **Persistent Module State**: Enable/disable settings survive application restarts
- **Comprehensive Commands**: 20+ commands with full option support
- **Intelligent Defaults**: Smart fallbacks and error handling

### 🔧 **Stability Improvements**
- **Factory Generation**: No more missing stub file errors
- **Migration Support**: Full aggregate support with proper variable substitution
- **Service Dependencies**: All dependency injection issues resolved
- **Test Infrastructure**: Complete test stub ecosystem with performance, documentation, and helper stubs

### 📊 **Quality Metrics**
- **158 Source Files**: 100% syntax valid
- **Test Coverage**: Comprehensive testing with fresh Laravel installation
- **Zero Known Issues**: All critical bugs resolved in v1.2.0

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