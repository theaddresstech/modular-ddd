# Laravel Modular DDD - Testing Guide

This guide provides comprehensive documentation for testing the Laravel Modular DDD package, including setup, execution, and best practices.

## Table of Contents

- [Quick Start](#quick-start)
- [Test Structure](#test-structure)
- [Running Tests](#running-tests)
- [Test Categories](#test-categories)
- [CI/CD Pipeline](#cicd-pipeline)
- [Performance Testing](#performance-testing)
- [Coverage Reports](#coverage-reports)
- [Best Practices](#best-practices)
- [Troubleshooting](#troubleshooting)

## Quick Start

### Prerequisites

- PHP 8.1+ with required extensions
- Composer
- MySQL, PostgreSQL, or SQLite
- Redis (optional, for caching tests)

### Installation

```bash
# Clone and install dependencies
git clone <repository-url>
cd laravel-ddd-modules
make install

# Or manually:
composer install
cp tests/Support/.env.ci .env.testing
```

### Run All Tests

```bash
# Using Makefile (recommended)
make test

# Using automation script
./scripts/test-automation.sh

# Using PHPUnit directly
vendor/bin/phpunit
```

## Test Structure

The testing suite is organized into multiple layers following the package architecture:

```
tests/
├── Unit/                          # Unit tests (isolated components)
│   ├── EventSourcing/            # Event Store, Snapshots, Sequencing
│   ├── CQRS/                     # Command/Query buses, handlers
│   ├── Modules/                  # Module communication
│   ├── Generators/               # Code generation
│   └── Console/                  # Console commands
├── Feature/                       # Feature tests (Laravel integration)
├── Integration/                   # Integration tests (multi-component)
│   └── EndToEndSystemTest.php    # Full system workflows
├── Performance/                   # Performance and benchmarking
└── Support/                      # Test configuration and helpers
    ├── .env.ci                   # CI environment
    ├── .env.performance          # Performance testing
    └── TestCase.php              # Base test case
```

### Test Categories

#### 1. Unit Tests

Test individual components in isolation:

```bash
# Run all unit tests
make test-unit

# Run specific component tests
vendor/bin/phpunit --testsuite=EventSourcing
vendor/bin/phpunit --testsuite=CQRS
vendor/bin/phpunit --testsuite=Modules
```

**Key Test Files:**
- `RealEventStoreTest.php` - Event Store with actual database
- `SnapshotSystemTest.php` - All snapshot strategies
- `EventSequencerTest.php` - Event ordering and consistency
- `RealCommandBusTest.php` - Command processing pipeline
- `RealQueryBusTest.php` - Query processing with caching
- `RealModuleBusTest.php` - Inter-module communication
- `GeneratorSystemTest.php` - Code generation system
- `ConsoleCommandsTest.php` - CLI commands

#### 2. Feature Tests

Test Laravel framework integration:

```bash
make test-feature
```

#### 3. Integration Tests

Test complete workflows and system interactions:

```bash
make test-integration
```

**Key Integration Tests:**
- `EndToEndSystemTest.php` - Complete user journeys
- Cross-module communication scenarios
- Data consistency across projections
- Snapshot restoration workflows
- Concurrent operations handling
- System recovery after errors

#### 4. Performance Tests

Validate system performance under load:

```bash
make test-performance
make benchmark
make stress
```

## Running Tests

### Using Makefile (Recommended)

The Makefile provides convenient commands for all testing scenarios:

```bash
# Quick commands
make t          # Run all tests
make tu         # Unit tests
make tf         # Feature tests
make ti         # Integration tests
make tp         # Performance tests
make tc         # Coverage report

# Quality checks
make q          # All quality checks
make l          # Lint (PHP CS Fixer)
make s          # Static analysis (PHPStan)
make psalm      # Psalm analysis
make security   # Security audit

# Development
make watch      # Watch for changes
make clean      # Clean up generated files
```

### Using Test Automation Script

The automation script provides advanced testing capabilities:

```bash
# Run all tests with default settings
./scripts/test-automation.sh

# Run specific test suites
./scripts/test-automation.sh unit
./scripts/test-automation.sh integration
./scripts/test-automation.sh performance

# Use different database
./scripts/test-automation.sh --database=mysql all
./scripts/test-automation.sh --database=pgsql integration

# Enable parallel testing
./scripts/test-automation.sh --parallel unit

# Disable coverage
./scripts/test-automation.sh --no-coverage all

# Verbose output
./scripts/test-automation.sh --verbose unit
```

### Using PHPUnit Directly

For fine-grained control:

```bash
# Run all tests
vendor/bin/phpunit

# Run specific test suites
vendor/bin/phpunit --testsuite=Unit
vendor/bin/phpunit --testsuite=Integration

# Run specific test file
vendor/bin/phpunit tests/Unit/EventSourcing/RealEventStoreTest.php

# Run specific test method
vendor/bin/phpunit --filter test_can_append_and_load_events_for_real

# Generate coverage
vendor/bin/phpunit --coverage-html=coverage/html
```

## Test Environments

### SQLite (Default)

Fast in-memory testing for development:

```bash
# Uses SQLite in-memory database
vendor/bin/phpunit
```

### MySQL

For production-like testing:

```bash
# Start MySQL service
# Configure database credentials in .env.testing

DATABASE=mysql ./scripts/test-automation.sh
```

### PostgreSQL

For PostgreSQL compatibility testing:

```bash
# Start PostgreSQL service
# Configure database credentials in .env.testing

DATABASE=pgsql ./scripts/test-automation.sh
```

## CI/CD Pipeline

The GitHub Actions workflow (`.github/workflows/ci.yml`) provides comprehensive testing:

### Test Matrix

- **PHP Versions:** 8.1, 8.2, 8.3
- **Laravel Versions:** 10.x, 11.x
- **Databases:** SQLite, MySQL, PostgreSQL
- **Services:** Redis for caching tests

### Pipeline Stages

1. **Tests** - Unit, Feature, Integration tests across matrix
2. **Performance** - Benchmarks and stress tests
3. **Security** - Vulnerability scanning and taint analysis
4. **Compatibility** - PHP version compatibility checks
5. **Documentation** - API docs generation and deployment
6. **Release** - Automated release management

### Local CI Testing

Run the same checks locally:

```bash
# Run full CI pipeline
make ci

# Run specific CI stages
make ci-test
make ci-performance
```

## Performance Testing

### Benchmarks

Test system performance under normal load:

```bash
# Run all benchmarks
make benchmark

# Individual benchmarks
php artisan benchmark:event-store --iterations=1000
php artisan benchmark:cqrs --iterations=500
php artisan benchmark:modules --iterations=200
```

### Stress Tests

Test system behavior under heavy load:

```bash
# Run all stress tests
make stress

# Individual stress tests
php artisan stress:event-sourcing --concurrent=10 --duration=60
php artisan stress:cqrs --concurrent=5 --duration=30
```

### Performance Expectations

- **Event Store:** 1000+ events/second append rate
- **Command Processing:** 500+ commands/second
- **Query Processing:** 1000+ queries/second (cached)
- **Module Communication:** 200+ messages/second
- **Memory Usage:** < 128MB for standard test suite
- **Snapshot Restoration:** < 100ms for 1000 events

## Coverage Reports

### Generating Coverage

```bash
# HTML report
make test-coverage

# Multiple formats
vendor/bin/phpunit --coverage-html=coverage/html \
                   --coverage-clover=coverage/clover.xml \
                   --coverage-cobertura=coverage/cobertura.xml
```

### Coverage Targets

- **Overall Coverage:** > 90%
- **Unit Tests:** > 95%
- **Integration Tests:** > 85%
- **Critical Components:** 100% (Event Store, CQRS buses)

### Viewing Reports

```bash
# Open HTML report
open coverage/html/index.html

# View text summary
cat coverage/coverage.txt
```

## Best Practices

### Writing Tests

1. **Follow Naming Conventions:**
   ```php
   public function test_it_can_append_events_successfully(): void
   public function it_validates_command_data_correctly(): void
   public function it_handles_concurrent_operations(): void
   ```

2. **Use Descriptive Test Names:**
   - Start with `it_` for behavior tests
   - Use `test_` for simple action tests
   - Include expected outcome in name

3. **Structure Tests with AAA Pattern:**
   ```php
   public function test_example(): void
   {
       // Arrange
       $aggregate = new TestAggregate($id);

       // Act
       $result = $aggregate->doSomething();

       // Assert
       $this->assertEquals('expected', $result);
   }
   ```

4. **Use Test Factories and Builders:**
   ```php
   $command = new CreateUserCommandBuilder()
       ->withId($userId)
       ->withEmail('test@example.com')
       ->build();
   ```

### Performance Testing

1. **Set Realistic Expectations:**
   - Test with production-like data volumes
   - Use appropriate timeouts
   - Account for CI environment performance

2. **Use Performance Assertions:**
   ```php
   $this->assertExecutionTimeWithinLimits(function () {
       // Operation under test
   }, 1000); // 1 second max

   $this->assertMemoryUsageWithinLimits(64); // 64MB max
   ```

### Integration Testing

1. **Test Real Scenarios:**
   - Use actual databases, not mocks
   - Test complete user journeys
   - Include error scenarios

2. **Ensure Test Isolation:**
   - Clean up test data
   - Reset application state
   - Use transactions when possible

## Troubleshooting

### Common Issues

#### Tests Failing in CI

```bash
# Check CI-specific environment
grep -r "CI" tests/
cat .github/workflows/ci.yml

# Run tests with CI environment locally
cp tests/Support/.env.ci .env.testing
vendor/bin/phpunit
```

#### Performance Test Timeouts

```bash
# Increase timeout limits
export PERFORMANCE_TESTING_ENABLED=true
export BENCHMARK_ITERATIONS=100  # Reduce iterations
export STRESS_TEST_DURATION=30   # Reduce duration
```

#### Memory Issues

```bash
# Increase memory limit
export MEMORY_LIMIT=512M
php -d memory_limit=512M vendor/bin/phpunit
```

#### Database Connection Issues

```bash
# Check database configuration
cat tests/Support/.env.ci

# Test database connection
php artisan migrate:status --env=testing
```

### Debugging Tests

1. **Use Verbose Output:**
   ```bash
   vendor/bin/phpunit --verbose
   ./scripts/test-automation.sh --verbose
   ```

2. **Run Single Test:**
   ```bash
   vendor/bin/phpunit --filter test_specific_functionality
   ```

3. **Enable Debug Mode:**
   ```bash
   APP_DEBUG=true vendor/bin/phpunit
   ```

4. **Check Logs:**
   ```bash
   tail -f storage/logs/laravel.log
   ```

### Getting Help

1. **Check Test Output:**
   - Read error messages carefully
   - Check test failure context
   - Review stack traces

2. **Run Diagnostics:**
   ```bash
   make info     # System information
   make status   # Project status
   php --version
   composer diagnose
   ```

3. **Documentation:**
   - Package documentation
   - Laravel testing guide
   - PHPUnit documentation

## Docker Testing

For isolated testing environments:

```bash
# Run tests in Docker
make docker-test

# Run performance tests in Docker
make docker-performance

# Run security tests in Docker
make docker-security
```

## Continuous Testing

For development workflows:

```bash
# Watch for file changes and run tests
make watch

# Quick development cycle
make dev-setup  # Setup development environment
make tu         # Run unit tests
make fix        # Fix code style
make s          # Run static analysis
```

This comprehensive testing infrastructure ensures the Laravel Modular DDD package maintains high quality, performance, and reliability across all supported environments and use cases.