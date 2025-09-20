# Generator Commands Deep Dive

This guide provides detailed information about the code generation commands in the Laravel Modular DDD package, including advanced usage patterns, customization options, and best practices.

## Table of Contents

- [Generator Architecture](#generator-architecture)
- [Stub System](#stub-system)
- [Advanced Generation Patterns](#advanced-generation-patterns)
- [Customization and Configuration](#customization-and-configuration)
- [Generator Workflows](#generator-workflows)
- [Troubleshooting](#troubleshooting)

## Generator Architecture

The package uses a sophisticated generator system built around several key components:

### Core Generator Classes

```php
// Generator Interface
LaravelModularDDD\Generators\Contracts\GeneratorInterface

// Concrete Generators
LaravelModularDDD\Generators\ModuleGenerator
LaravelModularDDD\Generators\AggregateGenerator
LaravelModularDDD\Generators\CommandGenerator
LaravelModularDDD\Generators\QueryGenerator
LaravelModularDDD\Generators\RepositoryGenerator
```

### Generator Features

1. **Template-based Generation**: Uses customizable stub files
2. **Namespace Resolution**: Automatic namespace handling
3. **Dependency Injection**: Proper service provider registration
4. **Test Generation**: Comprehensive test suite creation
5. **Validation**: Input validation and conflict detection
6. **Dry Run Mode**: Preview generation without file creation

## Stub System

### Stub Locations

Stubs are located in `resources/stubs/ddd/` and can be customized:

```
resources/stubs/ddd/
├── module/
│   ├── aggregate.stub
│   ├── command.stub
│   ├── query.stub
│   └── repository.stub
├── tests/
│   ├── unit-test.stub
│   ├── feature-test.stub
│   └── factory.stub
└── infrastructure/
    ├── service-provider.stub
    └── migration.stub
```

### Stub Variables

All stubs support these replacement variables:

| Variable | Description | Example |
|----------|-------------|---------|
| `{{MODULE}}` | Module name | `UserManagement` |
| `{{AGGREGATE}}` | Aggregate name | `User` |
| `{{COMMAND}}` | Command name | `CreateUserCommand` |
| `{{QUERY}}` | Query name | `GetUserQuery` |
| `{{NAMESPACE}}` | Full namespace | `Modules\UserManagement\Domain` |
| `{{CLASS}}` | Class name | `UserAggregate` |
| `{{VARIABLE}}` | Variable name | `userAggregate` |
| `{{TABLE}}` | Database table | `users` |
| `{{TIMESTAMP}}` | Current timestamp | `2024-01-15 10:30:00` |

### Custom Stub Sets

Configure different stub sets in `config/modular-ddd.php`:

```php
'generators' => [
    'stubs_path' => resource_path('stubs/ddd'),
    'default_stub_set' => 'default', // default, minimal, complete

    'stub_sets' => [
        'minimal' => [
            'includes' => ['aggregate', 'repository'],
            'excludes' => ['tests', 'factories']
        ],
        'complete' => [
            'includes' => ['*'],
            'features' => ['validation', 'caching', 'events']
        ]
    ]
]
```

## Advanced Generation Patterns

### 1. Module Generation with Dependencies

Generate modules that depend on other modules:

```bash
# Generate module with explicit dependencies
php artisan module:make OrderManagement \
  --aggregate=Order \
  --depends=UserManagement,ProductCatalog
```

### 2. Aggregate Generation with Rich Domain Model

Create aggregates with comprehensive domain components:

```bash
php artisan module:aggregate OrderManagement Order \
  --with-events=OrderCreated,OrderUpdated,OrderCancelled,OrderCompleted \
  --with-value-objects=OrderId,Money,CustomerInfo,ShippingAddress \
  --with-policies=OrderPolicy,RefundPolicy
```

### 3. CQRS Command Generation with Validation

Generate commands with comprehensive validation:

```bash
php artisan module:command OrderManagement CreateOrderCommand \
  --handler \
  --aggregate=Order \
  --validation=strict \
  --async \
  --authorize
```

Example generated command with validation:

```php
<?php

namespace Modules\OrderManagement\Application\Commands;

use LaravelModularDDD\CQRS\Command;
use Modules\OrderManagement\Domain\ValueObjects\Money;
use Modules\OrderManagement\Domain\ValueObjects\CustomerInfo;

final class CreateOrderCommand extends Command
{
    public function __construct(
        public readonly CustomerInfo $customerInfo,
        public readonly array $items,
        public readonly Money $totalAmount,
        public readonly ?string $couponCode = null
    ) {}

    public function rules(): array
    {
        return [
            'customerInfo.email' => 'required|email',
            'customerInfo.name' => 'required|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.productId' => 'required|uuid',
            'items.*.quantity' => 'required|integer|min:1',
            'totalAmount.amount' => 'required|numeric|min:0',
            'totalAmount.currency' => 'required|string|size:3',
            'couponCode' => 'nullable|string|max:50'
        ];
    }

    public function authorize(): bool
    {
        // Authorization logic here
        return auth()->check();
    }
}
```

### 4. Query Generation with Advanced Features

Generate queries with caching, pagination, and filtering:

```bash
php artisan module:query OrderManagement SearchOrdersQuery \
  --handler \
  --paginated \
  --filtered \
  --cache \
  --cache-ttl=3600 \
  --sort-fields=created_at,total_amount,status
```

Example generated query:

```php
<?php

namespace Modules\OrderManagement\Application\Queries;

use LaravelModularDDD\CQRS\Query;

final class SearchOrdersQuery extends Query
{
    public function __construct(
        public readonly ?string $customerId = null,
        public readonly ?string $status = null,
        public readonly ?string $dateFrom = null,
        public readonly ?string $dateTo = null,
        public readonly ?float $minAmount = null,
        public readonly ?float $maxAmount = null,
        public readonly int $page = 1,
        public readonly int $perPage = 15,
        public readonly string $sortBy = 'created_at',
        public readonly string $sortDirection = 'desc'
    ) {}

    public function getCacheKey(): string
    {
        return 'orders:search:' . md5(serialize($this->toArray()));
    }

    public function getCacheTtl(): int
    {
        return 3600; // 1 hour
    }

    public function getFilters(): array
    {
        return array_filter([
            'customer_id' => $this->customerId,
            'status' => $this->status,
            'created_at_from' => $this->dateFrom,
            'created_at_to' => $this->dateTo,
            'total_amount_min' => $this->minAmount,
            'total_amount_max' => $this->maxAmount,
        ]);
    }
}
```

### 5. Repository Generation with Multiple Implementations

Generate repositories with both event-sourced and Eloquent implementations:

```bash
php artisan module:repository OrderManagement Order \
  --event-sourced \
  --eloquent \
  --cache-enabled \
  --batch-operations
```

## Customization and Configuration

### Generator Configuration

Configure generators in `config/modular-ddd.php`:

```php
'generators' => [
    'stubs_path' => resource_path('stubs/ddd'),
    'default_stub_set' => 'default',

    'namespaces' => [
        'domain' => 'Domain',
        'application' => 'Application',
        'infrastructure' => 'Infrastructure',
        'presentation' => 'Presentation',
    ],

    'auto_generate' => [
        'tests' => env('AUTO_GENERATE_TESTS', true),
        'factories' => env('AUTO_GENERATE_FACTORIES', true),
        'migrations' => env('AUTO_GENERATE_MIGRATIONS', true),
        'seeders' => env('AUTO_GENERATE_SEEDERS', false),
        'api_docs' => env('AUTO_GENERATE_API_DOCS', true),
    ],

    'code_style' => [
        'strict_types' => true,
        'final_classes' => true,
        'readonly_properties' => true,
        'typed_properties' => true,
        'phpstan_level' => 8,
    ],

    'templates' => [
        'aggregate' => [
            'includes' => ['constructor', 'business_methods', 'events'],
            'features' => ['validation', 'immutability', 'event_sourcing']
        ],
        'command' => [
            'includes' => ['validation', 'authorization'],
            'features' => ['async_support', 'middleware']
        ],
        'query' => [
            'includes' => ['caching', 'pagination', 'filtering'],
            'features' => ['result_transformation', 'eager_loading']
        ]
    ]
]
```

### Custom Generators

Create custom generators by extending the base generator:

```php
<?php

namespace App\Generators;

use LaravelModularDDD\Generators\BaseGenerator;

class EventListenerGenerator extends BaseGenerator
{
    public function generate(string $module, string $listener, array $options = []): array
    {
        $stub = $this->getStub('event-listener');

        $replacements = [
            '{{MODULE}}' => $module,
            '{{LISTENER}}' => $listener,
            '{{NAMESPACE}}' => $this->getListenerNamespace($module),
            '{{EVENT}}' => $options['event'] ?? 'DomainEvent',
        ];

        $content = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $stub
        );

        $path = $this->getListenerPath($module, $listener);

        return $this->writeFile($path, $content, $options);
    }

    protected function getListenerNamespace(string $module): string
    {
        return "Modules\\{$module}\\Application\\Listeners";
    }

    protected function getListenerPath(string $module, string $listener): string
    {
        return base_path("Modules/{$module}/Application/Listeners/{$listener}.php");
    }
}
```

### Template Customization

Create custom templates by overriding stubs:

```php
// resources/stubs/ddd/custom/aggregate.stub
<?php

declare(strict_types=1);

namespace {{NAMESPACE}}\Domain\Aggregates;

use LaravelModularDDD\Core\Domain\AggregateRoot;
use {{NAMESPACE}}\Domain\Events\{{AGGREGATE}}Created;
use {{NAMESPACE}}\Domain\ValueObjects\{{AGGREGATE}}Id;

final class {{AGGREGATE}} extends AggregateRoot
{
    private {{AGGREGATE}}Id $id;

    public function __construct({{AGGREGATE}}Id $id)
    {
        $this->id = $id;
    }

    public static function create({{AGGREGATE}}Id $id): self
    {
        ${{VARIABLE}} = new self($id);

        ${{VARIABLE}}->recordEvent(new {{AGGREGATE}}Created($id));

        return ${{VARIABLE}};
    }

    public function getId(): {{AGGREGATE}}Id
    {
        return $this->id;
    }

    // Add your business methods here
}
```

## Generator Workflows

### 1. Domain-First Development

Start with domain modeling and work outward:

```bash
# 1. Create module structure
php artisan module:make ECommerce --dry-run

# 2. Model core aggregates
php artisan module:aggregate ECommerce Product --with-events=ProductCreated,PriceChanged
php artisan module:aggregate ECommerce Order --with-events=OrderPlaced,OrderShipped
php artisan module:aggregate ECommerce Customer --with-events=CustomerRegistered

# 3. Define value objects
php artisan module:value-object ECommerce Money
php artisan module:value-object ECommerce Address

# 4. Create repositories
php artisan module:repository ECommerce Product --event-sourced
php artisan module:repository ECommerce Order --event-sourced

# 5. Build application layer
php artisan module:command ECommerce CreateProductCommand --handler
php artisan module:query ECommerce GetProductCatalogQuery --handler --paginated
```

### 2. API-First Development

Start with API design and generate supporting infrastructure:

```bash
# 1. Generate API controllers
php artisan module:controller ECommerce ProductController --api

# 2. Generate commands for write operations
php artisan module:command ECommerce CreateProductCommand --handler --async
php artisan module:command ECommerce UpdateProductCommand --handler

# 3. Generate queries for read operations
php artisan module:query ECommerce GetProductQuery --handler --cache
php artisan module:query ECommerce SearchProductsQuery --handler --filtered

# 4. Generate supporting components
php artisan module:request ECommerce CreateProductRequest --validation
php artisan module:resource ECommerce ProductResource --collection
```

### 3. Test-Driven Development

Generate comprehensive test suites before implementation:

```bash
# 1. Generate test structure
php artisan module:test ECommerce --type=all --dry-run

# 2. Generate specific test types
php artisan module:test ECommerce --aggregate=Product --type=unit
php artisan module:test ECommerce --command=CreateProductCommand --type=feature
php artisan module:test ECommerce --query=GetProductQuery --type=integration

# 3. Generate test factories
php artisan test:factory ECommerce Product --traits=WithCategories,WithInventory
php artisan test:factory ECommerce Order --states=pending,completed,cancelled

# 4. Generate performance tests
php artisan module:test ECommerce --performance --benchmark
```

## Troubleshooting

### Common Issues and Solutions

#### 1. Namespace Conflicts

**Problem**: Generated classes have incorrect namespaces.

**Solution**: Check module configuration and ensure consistent naming:

```bash
# Check module registry
php artisan module:list --detailed

# Verify namespace configuration
php artisan config:show modular-ddd.generators.namespaces
```

#### 2. Stub Not Found

**Problem**: Generator cannot find stub files.

**Solution**: Verify stub path configuration:

```bash
# Check stub path
ls -la resources/stubs/ddd/

# Publish default stubs if missing
php artisan vendor:publish --tag=ddd-stubs
```

#### 3. File Generation Failures

**Problem**: Files are not being created or have incorrect content.

**Solution**: Use dry-run mode to debug:

```bash
# Preview generation
php artisan module:make TestModule --dry-run

# Check permissions
chmod -R 755 Modules/

# Use verbose mode
php artisan module:make TestModule --verbose
```

#### 4. Dependency Resolution Issues

**Problem**: Generated code has missing dependencies.

**Solution**: Ensure proper service provider registration:

```php
// config/app.php
'providers' => [
    // Other providers...
    LaravelModularDDD\ModularDddServiceProvider::class,
    Modules\YourModule\YourModuleServiceProvider::class,
],
```

### Performance Optimization

#### 1. Batch Generation

Generate multiple components efficiently:

```bash
# Use shell scripting for batch operations
for component in Product Order Customer; do
    php artisan module:aggregate ECommerce $component &
done
wait
```

#### 2. Parallel Processing

Configure generators for parallel execution:

```php
// config/modular-ddd.php
'generators' => [
    'parallel_generation' => true,
    'max_workers' => 4,
    'batch_size' => 10,
]
```

#### 3. Caching

Enable generator caching for faster repeated operations:

```php
// config/modular-ddd.php
'generators' => [
    'cache_enabled' => true,
    'cache_ttl' => 3600,
    'cache_invalidation' => 'smart', // smart, manual, disabled
]
```

This comprehensive guide provides all the tools and knowledge needed to effectively use the Laravel Modular DDD package's sophisticated code generation system. The generators are designed to accelerate development while maintaining high code quality and adherence to DDD principles.