# Quick Reference Guide

A comprehensive cheat sheet for all Laravel Modular DDD package commands.

## Module Management

### Create and Structure

```bash
# Create complete module
php artisan module:make UserManagement

# Create with custom aggregate
php artisan module:make OrderManagement --aggregate=Order

# Preview without creating files
php artisan module:make ShoppingCart --dry-run

# Create minimal module
php artisan module:make Analytics --no-tests --no-migration --no-api
```

### Information and Status

```bash
# List all modules
php artisan module:list

# Show enabled modules only
php artisan module:list --enabled

# Detailed module information
php artisan module:list --detailed

# Module statistics
php artisan module:list --stats

# Specific module info
php artisan module:info UserManagement

# Module health check
php artisan module:health UserManagement
```

### Enable/Disable

```bash
# Enable module
php artisan module:enable UserManagement

# Disable module
php artisan module:disable UserManagement

# Force enable (ignore dependencies)
php artisan module:enable UserManagement --force
```

## Code Generation

### Aggregates

```bash
# Basic aggregate
php artisan module:aggregate UserManagement User

# With events and value objects
php artisan module:aggregate OrderManagement Order \
  --with-events=OrderCreated,OrderShipped \
  --with-value-objects=OrderId,Money

# Preview generation
php artisan module:aggregate ProductCatalog Product --dry-run
```

### Commands (CQRS)

```bash
# Basic command
php artisan module:command UserManagement CreateUserCommand

# With handler
php artisan module:command UserManagement CreateUserCommand --handler

# Async command with handler
php artisan module:command UserManagement ProcessOrderCommand --handler --async

# For specific aggregate
php artisan module:command OrderManagement CreateOrderCommand \
  --handler --aggregate=Order

# Skip validation
php artisan module:command UserManagement ImportUsersCommand \
  --handler --no-validation
```

### Queries (CQRS)

```bash
# Basic query
php artisan module:query UserManagement GetUserQuery

# With handler and caching
php artisan module:query UserManagement GetUserQuery --handler

# Paginated query
php artisan module:query UserManagement ListUsersQuery \
  --handler --paginated

# With filtering
php artisan module:query OrderManagement SearchOrdersQuery \
  --handler --filtered

# Complex query
php artisan module:query ProductCatalog GetProductCatalogQuery \
  --handler --paginated --filtered

# No caching
php artisan module:query ReportingEngine GenerateReportQuery \
  --handler --no-cache
```

### Repositories

```bash
# Event-sourced repository (default)
php artisan module:repository UserManagement User

# Eloquent repository
php artisan module:repository ProductCatalog Product --eloquent

# Interface only
php artisan module:repository OrderManagement Order --interface-only

# Implementation only
php artisan module:repository UserManagement User --implementation-only
```

## Database Operations

### Migrations

```bash
# Run module migrations
php artisan module:migrate UserManagement

# Run all module migrations
php artisan module:migrate --all

# Force in production
php artisan module:migrate UserManagement --force

# Rollback module migrations
php artisan module:migrate:rollback UserManagement

# Rollback specific steps
php artisan module:migrate:rollback UserManagement --step=2

# Check migration status
php artisan module:migrate:status

# Status for specific module
php artisan module:migrate:status UserManagement
```

### Create Migrations

```bash
# Create table migration
php artisan module:make:migration UserManagement create_users_table --create=users

# Modify table migration
php artisan module:make:migration UserManagement add_email_to_users --table=users
```

## Testing

### Generate Tests

```bash
# All test types
php artisan module:test UserManagement

# Specific test type
php artisan module:test UserManagement --type=unit
php artisan module:test UserManagement --type=feature
php artisan module:test UserManagement --type=integration
php artisan module:test UserManagement --type=factories

# For specific component
php artisan module:test UserManagement --aggregate=User
php artisan module:test UserManagement --command=CreateUserCommand
php artisan module:test UserManagement --query=GetUserQuery

# All modules
php artisan module:test --type=all

# With performance tests
php artisan module:test UserManagement --performance

# With coverage
php artisan module:test UserManagement --coverage

# Force overwrite
php artisan module:test UserManagement --force
```

### Test Factories

```bash
# Basic factory
php artisan test:factory UserManagement User

# With traits
php artisan test:factory UserManagement User --traits=WithEmail,WithProfile

# With states
php artisan test:factory OrderManagement Order --states=pending,completed

# For specific aggregate
php artisan test:factory ProductCatalog Product --aggregate=Product
```

## Performance and Monitoring

### Benchmarking

```bash
# Run all benchmarks
php artisan ddd:benchmark

# Specific component
php artisan ddd:benchmark --component=EventStore

# Custom iterations
php artisan ddd:benchmark --iterations=1000

# Output format
php artisan ddd:benchmark --output=json
php artisan ddd:benchmark --output=csv
```

### Stress Testing

```bash
# Basic stress test
php artisan ddd:stress-test

# Custom duration
php artisan ddd:stress-test --duration=300

# Concurrent operations
php artisan ddd:stress-test --concurrent=50

# Memory limit
php artisan ddd:stress-test --memory-limit=512M
```

## Documentation

### Generate Documentation

```bash
# Module documentation
php artisan module:documentation UserManagement

# All modules
php artisan module:documentation --all

# Specific format
php artisan module:documentation UserManagement --format=markdown

# Include API docs
php artisan module:documentation UserManagement --include-api
```

## Common Command Combinations

### New Module Development

```bash
# Create module
php artisan module:make ECommerce --aggregate=Product

# Add more aggregates
php artisan module:aggregate ECommerce Order
php artisan module:aggregate ECommerce Customer

# Generate CRUD commands
php artisan module:command ECommerce CreateProductCommand --handler
php artisan module:command ECommerce UpdateProductCommand --handler
php artisan module:command ECommerce DeleteProductCommand --handler

# Generate queries
php artisan module:query ECommerce GetProductQuery --handler
php artisan module:query ECommerce ListProductsQuery --handler --paginated

# Generate repositories
php artisan module:repository ECommerce Product
php artisan module:repository ECommerce Order

# Generate tests
php artisan module:test ECommerce --type=all

# Run migrations
php artisan module:migrate ECommerce
```

### Complete CRUD Setup

```bash
MODULE="UserManagement"
AGGREGATE="User"

# Generate aggregate
php artisan module:aggregate $MODULE $AGGREGATE

# Generate CRUD commands
php artisan module:command $MODULE Create${AGGREGATE}Command --handler --aggregate=$AGGREGATE
php artisan module:command $MODULE Update${AGGREGATE}Command --handler --aggregate=$AGGREGATE
php artisan module:command $MODULE Delete${AGGREGATE}Command --handler --aggregate=$AGGREGATE

# Generate queries
php artisan module:query $MODULE Get${AGGREGATE}Query --handler
php artisan module:query $MODULE List${AGGREGATE}Query --handler --paginated --filtered

# Generate repository
php artisan module:repository $MODULE $AGGREGATE

# Generate tests
php artisan module:test $MODULE --aggregate=$AGGREGATE
```

### API Development

```bash
MODULE="ProductCatalog"

# Generate API queries
php artisan module:query $MODULE GetProductApiQuery --handler --cache
php artisan module:query $MODULE SearchProductsApiQuery --handler --paginated --filtered

# Generate API commands
php artisan module:command $MODULE CreateProductApiCommand --handler --async
php artisan module:command $MODULE UpdateProductApiCommand --handler

# Generate tests
php artisan module:test $MODULE --type=api
```

## Options Reference

### Common Options

| Option | Description | Available In |
|--------|-------------|--------------|
| `--force` | Overwrite existing files | Most generation commands |
| `--dry-run` | Preview without creating | Most generation commands |
| `--handler` | Generate handler class | command, query |
| `--async` | Generate async handler | command |
| `--cache` | Enable caching | query |
| `--paginated` | Enable pagination | query |
| `--filtered` | Enable filtering | query |
| `--aggregate=` | Target aggregate | command, query |
| `--type=` | Test type | module:test |
| `--all` | Apply to all modules | migrate, test |

### Module Make Options

| Option | Description |
|--------|-------------|
| `--aggregate=` | Main aggregate name |
| `--no-tests` | Skip test generation |
| `--no-migration` | Skip migration generation |
| `--no-factory` | Skip factory generation |
| `--no-seeder` | Skip seeder generation |
| `--no-api` | Skip API components |
| `--no-web` | Skip web components |

### Aggregate Options

| Option | Description |
|--------|-------------|
| `--with-events=` | Comma-separated events |
| `--with-value-objects=` | Comma-separated value objects |
| `--no-exception` | Skip exception class |

### Repository Options

| Option | Description |
|--------|-------------|
| `--interface-only` | Generate only interface |
| `--implementation-only` | Generate only implementation |
| `--eloquent` | Eloquent-based repository |
| `--event-sourced` | Event-sourced repository (default) |

## Exit Codes

| Code | Meaning |
|------|---------|
| 0 | Success |
| 1 | General error |
| 2 | Validation error |
| 3 | File system error |
| 4 | Module not found |
| 5 | Dependency error |

## Environment Variables

```bash
# Generator configuration
AUTO_GENERATE_TESTS=true
AUTO_GENERATE_FACTORIES=true
AUTO_GENERATE_MIGRATIONS=true

# Module paths
MODULE_AUTO_DISCOVERY=true
MODULE_PRELOAD=true
MODULE_CACHE_MANIFESTS=true

# Performance
DDD_MONITORING_ENABLED=true
METRICS_COLLECTOR=memory
```

## Useful Aliases

Add to your `~/.bashrc` or `~/.zshrc`:

```bash
# Module aliases
alias mm='php artisan module:make'
alias ml='php artisan module:list'
alias mi='php artisan module:info'
alias mh='php artisan module:health'

# Generation aliases
alias ma='php artisan module:aggregate'
alias mc='php artisan module:command'
alias mq='php artisan module:query'
alias mr='php artisan module:repository'
alias mt='php artisan module:test'

# Migration aliases
alias migrate-module='php artisan module:migrate'
alias migrate-all='php artisan module:migrate --all'
alias migrate-status='php artisan module:migrate:status'

# Testing aliases
alias test-module='php artisan module:test'
alias test-all='php artisan module:test --type=all'
```

This quick reference provides instant access to all the commands and options available in the Laravel Modular DDD package, making it easy to find the right command for any development task.