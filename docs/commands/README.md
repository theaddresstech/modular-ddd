# Generator Commands Reference

This comprehensive guide covers all the generator commands available in the Laravel Modular DDD package. These commands help you quickly scaffold DDD modules, aggregates, CQRS components, tests, and more following best practices.

## Table of Contents

- [Quick Reference](#quick-reference)
- [Module Management Commands](#module-management-commands)
- [Code Generation Commands](#code-generation-commands)
- [Database Commands](#database-commands)
- [Testing Commands](#testing-commands)
- [Performance Commands](#performance-commands)
- [Common Workflows](#common-workflows)

## Quick Reference

| Command | Purpose | Example |
|---------|---------|---------|
| `module:make` | Create complete DDD module | `php artisan module:make UserManagement` |
| `module:aggregate` | Generate aggregate root | `php artisan module:aggregate User UserAggregate` |
| `module:command` | Generate CQRS command | `php artisan module:command User CreateUserCommand --handler` |
| `module:query` | Generate CQRS query | `php artisan module:query User GetUserQuery --handler --paginated` |
| `module:repository` | Generate repository | `php artisan module:repository User UserAggregate` |
| `module:test` | Generate test suite | `php artisan module:test User --type=all` |
| `module:list` | List all modules | `php artisan module:list --detailed` |
| `module:migrate` | Run migrations | `php artisan module:migrate User` |

## Module Management Commands

### `module:make` - Create Complete DDD Module

Creates a complete DDD module with all necessary components following the four-layer architecture.

```bash
php artisan module:make {name}
    {--aggregate= : The main aggregate name (defaults to module name)}
    {--force : Overwrite existing files}
    {--no-tests : Skip test generation}
    {--no-migration : Skip migration generation}
    {--no-factory : Skip factory generation}
    {--no-seeder : Skip seeder generation}
    {--no-api : Skip API components}
    {--no-web : Skip web components}
    {--dry-run : Show what would be generated without creating files}
```

**Examples:**
```bash
# Create a basic user management module
php artisan module:make UserManagement

# Create module with custom aggregate name
php artisan module:make UserManagement --aggregate=User

# Preview what would be generated
php artisan module:make UserManagement --dry-run

# Create minimal module (no tests, migrations, etc.)
php artisan module:make UserManagement --no-tests --no-migration --no-factory
```

**Generated Structure:**
```
Modules/UserManagement/
├── Domain/
│   ├── Aggregates/
│   ├── Events/
│   ├── ValueObjects/
│   └── Exceptions/
├── Application/
│   ├── Commands/
│   ├── Queries/
│   └── Handlers/
├── Infrastructure/
│   ├── Repositories/
│   ├── Persistence/
│   └── External/
└── Presentation/
    ├── Http/Controllers/
    ├── Resources/
    └── Requests/
```

### `module:list` - List All Modules

Displays all discovered modules with their status and information.

```bash
php artisan module:list
    {--enabled : Show only enabled modules}
    {--disabled : Show only disabled modules}
    {--detailed : Show detailed module information}
    {--stats : Show module statistics}
    {--json : Output as JSON}
```

**Examples:**
```bash
# List all modules
php artisan module:list

# Show only enabled modules with details
php artisan module:list --enabled --detailed

# Get module statistics
php artisan module:list --stats

# Export as JSON for processing
php artisan module:list --json
```

### `module:info` - Display Module Information

Shows detailed information about a specific module including dependencies, services, and health status.

```bash
php artisan module:info {module}
    {--dependencies : Show module dependencies}
    {--services : Show registered services}
    {--health : Show health check status}
    {--performance : Show performance metrics}
```

### `module:enable` / `module:disable` - Module State Management

Enable or disable modules in the application.

```bash
php artisan module:enable {module}
php artisan module:disable {module}
    {--force : Force enable/disable even with dependency issues}
```

### `module:health` - Check Module Health

Performs comprehensive health checks on modules.

```bash
php artisan module:health {module?}
    {--fix : Attempt to fix detected issues}
    {--detailed : Show detailed health report}
```

## Code Generation Commands

### `module:aggregate` - Generate Aggregate Root

Creates an aggregate root with related domain components including value objects and domain events.

```bash
php artisan module:aggregate {module} {aggregate}
    {--force : Overwrite existing files}
    {--with-events= : Comma-separated list of events to generate}
    {--with-value-objects= : Comma-separated list of value objects to generate}
    {--no-exception : Skip exception class generation}
    {--dry-run : Show what would be generated}
```

**Examples:**
```bash
# Basic aggregate generation
php artisan module:aggregate UserManagement User

# Generate with specific events and value objects
php artisan module:aggregate UserManagement User \
  --with-events=UserCreated,UserUpdated,UserDeleted \
  --with-value-objects=Email,Username,Password

# Preview generation
php artisan module:aggregate UserManagement User --dry-run
```

**Generated Files:**
- `Domain/Aggregates/User.php` - Aggregate root
- `Domain/Events/UserCreated.php` - Domain events
- `Domain/ValueObjects/Email.php` - Value objects
- `Domain/Exceptions/UserException.php` - Aggregate exception

### `module:command` - Generate CQRS Command

Creates CQRS commands with optional handlers for state-changing operations.

```bash
php artisan module:command {module} {command}
    {--aggregate= : The target aggregate name}
    {--handler : Generate command handler}
    {--no-validation : Skip validation rules}
    {--async : Generate async command handler}
    {--force : Overwrite existing files}
    {--dry-run : Show what would be generated}
```

**Examples:**
```bash
# Generate command with handler
php artisan module:command UserManagement CreateUserCommand \
  --handler --aggregate=User

# Generate async command
php artisan module:command UserManagement ProcessUserDataCommand \
  --handler --async

# Generate command without validation
php artisan module:command UserManagement ImportUsersCommand \
  --handler --no-validation
```

**Generated Files:**
- `Application/Commands/CreateUserCommand.php` - Command class
- `Application/Handlers/CreateUserCommandHandler.php` - Command handler
- Tests for both command and handler

### `module:query` - Generate CQRS Query

Creates CQRS queries with optional handlers for data retrieval operations.

```bash
php artisan module:query {module} {query}
    {--handler : Generate query handler}
    {--no-cache : Skip caching implementation}
    {--paginated : Generate paginated query}
    {--filtered : Include filtering capabilities}
    {--force : Overwrite existing files}
    {--dry-run : Show what would be generated}
```

**Examples:**
```bash
# Generate basic query with handler
php artisan module:query UserManagement GetUserQuery --handler

# Generate paginated query with filtering
php artisan module:query UserManagement ListUsersQuery \
  --handler --paginated --filtered

# Generate query without caching
php artisan module:query UserManagement GetUserStatsQuery \
  --handler --no-cache
```

**Generated Files:**
- `Application/Queries/GetUserQuery.php` - Query class
- `Application/Handlers/GetUserQueryHandler.php` - Query handler
- Caching and pagination support as configured

### `module:repository` - Generate Repository

Creates repository interfaces and implementations for aggregate persistence.

```bash
php artisan module:repository {module} {aggregate}
    {--interface-only : Generate only the repository interface}
    {--implementation-only : Generate only the repository implementation}
    {--eloquent : Generate Eloquent-based implementation}
    {--event-sourced : Generate event-sourced implementation (default)}
    {--force : Overwrite existing files}
    {--dry-run : Show what would be generated}
```

**Examples:**
```bash
# Generate event-sourced repository (default)
php artisan module:repository UserManagement User

# Generate Eloquent-based repository
php artisan module:repository UserManagement User --eloquent

# Generate only interface
php artisan module:repository UserManagement User --interface-only
```

**Generated Files:**
- `Domain/Contracts/UserRepositoryInterface.php` - Repository contract
- `Infrastructure/Repositories/EventSourcedUserRepository.php` - Implementation
- Repository service provider bindings

### `test:factory` - Generate Test Factory

Creates test factories for generating test data that follows domain rules.

```bash
php artisan test:factory {module} {model}
    {--aggregate= : The aggregate this factory belongs to}
    {--traits= : Comma-separated list of factory traits}
    {--states= : Comma-separated list of factory states}
    {--force : Overwrite existing files}
```

**Examples:**
```bash
# Generate basic factory
php artisan test:factory UserManagement User

# Generate factory with traits and states
php artisan test:factory UserManagement User \
  --traits=WithEmail,WithProfile \
  --states=active,inactive,pending
```

## Database Commands

### `module:migrate` - Run Module Migrations

Executes migrations for specific modules or all modules.

```bash
php artisan module:migrate {module?}
    {--all : Run migrations for all modules}
    {--force : Force migrations in production}
    {--step : Show each migration step}
```

### `module:migrate:rollback` - Rollback Module Migrations

Rolls back migrations for specific modules.

```bash
php artisan module:migrate:rollback {module?}
    {--all : Rollback all module migrations}
    {--step= : Number of batches to rollback}
```

### `module:migrate:status` - Check Migration Status

Shows the status of all module migrations.

```bash
php artisan module:migrate:status {module?}
    {--pending : Show only pending migrations}
    {--completed : Show only completed migrations}
```

### `module:make:migration` - Create New Migration

Creates a new migration file for a module.

```bash
php artisan module:make:migration {module} {name}
    {--create= : Create a new table}
    {--table= : Modify an existing table}
```

## Testing Commands

### `module:test` - Generate Test Suite

Generates comprehensive test suites for modules using the test-framework-generator.

```bash
php artisan module:test {module?}
    {--type=all : Type of tests to generate (unit, feature, integration, factories, all)}
    {--aggregate= : Specific aggregate to generate tests for}
    {--command= : Specific command to generate tests for}
    {--query= : Specific query to generate tests for}
    {--force : Overwrite existing test files}
    {--performance : Include performance tests}
    {--coverage : Generate coverage analysis}
```

**Examples:**
```bash
# Generate complete test suite for a module
php artisan module:test UserManagement

# Generate only unit tests
php artisan module:test UserManagement --type=unit

# Generate tests for specific aggregate
php artisan module:test UserManagement --aggregate=User

# Generate tests for all modules
php artisan module:test --type=all

# Generate with performance tests
php artisan module:test UserManagement --performance --coverage
```

**Generated Test Types:**
- **Unit Tests**: Domain logic, value objects, aggregates
- **Feature Tests**: Complete user workflows, API endpoints
- **Integration Tests**: Cross-module interactions, external services
- **Performance Tests**: Load testing, benchmarks
- **Test Factories**: Domain-compliant test data generation

## Performance Commands

### `ddd:benchmark` - Run Performance Benchmarks

Executes performance benchmarks for the DDD package components.

```bash
php artisan ddd:benchmark
    {--component= : Specific component to benchmark}
    {--iterations= : Number of iterations to run}
    {--output= : Output format (table, json, csv)}
```

### `ddd:stress-test` - Run Stress Tests

Performs stress testing on the DDD package under various load conditions.

```bash
php artisan ddd:stress-test
    {--duration= : Test duration in seconds}
    {--concurrent= : Number of concurrent operations}
    {--memory-limit= : Memory limit for the test}
```

## Common Workflows

### 1. Creating a New Module from Scratch

```bash
# 1. Create the module
php artisan module:make OrderManagement --aggregate=Order

# 2. Generate additional aggregates if needed
php artisan module:aggregate OrderManagement Product
php artisan module:aggregate OrderManagement Customer

# 3. Generate commands for state changes
php artisan module:command OrderManagement CreateOrderCommand --handler
php artisan module:command OrderManagement UpdateOrderStatusCommand --handler

# 4. Generate queries for data retrieval
php artisan module:query OrderManagement GetOrderQuery --handler
php artisan module:query OrderManagement ListOrdersQuery --handler --paginated

# 5. Generate repositories
php artisan module:repository OrderManagement Order
php artisan module:repository OrderManagement Product

# 6. Run migrations
php artisan module:migrate OrderManagement

# 7. Generate comprehensive tests
php artisan module:test OrderManagement --performance
```

### 2. Adding New Functionality to Existing Module

```bash
# 1. Generate new command
php artisan module:command UserManagement UpdateUserProfileCommand --handler

# 2. Generate corresponding query
php artisan module:query UserManagement GetUserProfileQuery --handler --cache

# 3. Generate tests for new components
php artisan module:test UserManagement --command=UpdateUserProfileCommand
php artisan module:test UserManagement --query=GetUserProfileQuery

# 4. Create migration if needed
php artisan module:make:migration UserManagement add_profile_fields_to_users_table
```

### 3. Development and Testing Workflow

```bash
# 1. List all modules and their status
php artisan module:list --detailed

# 2. Check module health
php artisan module:health UserManagement

# 3. Run migrations
php artisan module:migrate --all

# 4. Generate missing tests
php artisan module:test --type=all

# 5. Run performance benchmarks
php artisan ddd:benchmark --component=EventStore

# 6. Check migration status
php artisan module:migrate:status
```

## Best Practices

### Command Naming Conventions
- **Commands**: End with "Command" (e.g., `CreateUserCommand`)
- **Queries**: End with "Query" (e.g., `GetUserQuery`)
- **Aggregates**: Use singular nouns (e.g., `User`, `Order`)
- **Modules**: Use PascalCase (e.g., `UserManagement`, `OrderProcessing`)

### Generation Strategy
1. Start with `module:make` for the basic structure
2. Use `--dry-run` to preview before generating
3. Generate aggregates before commands/queries
4. Always generate tests alongside components
5. Use `--force` carefully in existing projects

### Testing Strategy
- Generate unit tests for domain logic
- Generate feature tests for complete workflows
- Generate integration tests for cross-module interactions
- Include performance tests for critical paths
- Use factories for consistent test data

### Performance Considerations
- Use caching for frequently accessed queries
- Generate paginated queries for large datasets
- Include filtering capabilities for complex searches
- Benchmark critical components regularly
- Monitor memory usage with stress tests

This comprehensive guide provides all the tools needed to effectively use the Laravel Modular DDD package's code generation capabilities. Each command is designed to follow DDD best practices and create production-ready code with comprehensive testing support.