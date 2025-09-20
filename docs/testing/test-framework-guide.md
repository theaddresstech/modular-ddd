# Test Framework Generator Guide

The Laravel DDD Modules package includes a comprehensive test generation framework that creates tests specifically designed for Domain-Driven Design patterns, Event Sourcing, and CQRS architectures.

## Overview

The test framework generator provides:

- **Unit Tests**: Isolated tests for domain components (Aggregates, Commands, Queries, Value Objects)
- **Feature Tests**: API endpoint and workflow tests
- **Integration Tests**: Cross-module interactions and system integration tests
- **Test Factories**: Domain-aware test data factories that respect business invariants
- **Assertion Helpers**: Specialized assertions for DDD patterns
- **Test Traits**: Reusable testing behaviors for common scenarios

## Quick Start

### Generate Tests for a Module

```bash
# Generate comprehensive test suite for a module
php artisan module:test UserModule

# Generate specific test types
php artisan module:test UserModule --type=unit
php artisan module:test UserModule --type=feature
php artisan module:test UserModule --type=integration

# Generate tests for specific components
php artisan module:test UserModule --aggregate=User
php artisan module:test UserModule --command=CreateUser
php artisan module:test UserModule --query=GetUser
```

### Generate Test Factories

```bash
# Generate factories for all components in a module
php artisan module:factory UserModule --all

# Generate factory for specific component
php artisan module:factory UserModule aggregate User

# Generate with additional features
php artisan module:factory UserModule --all --states --sequences --registry
```

## Test Types

### Unit Tests

Unit tests focus on isolated domain logic without external dependencies.

**Example generated unit test:**
```php
final class UserAggregateTest extends TestCase
{
    use MocksRepositories;
    use AssertsInvariants;

    /** @test */
    public function it_can_create_user_with_valid_data(): void
    {
        // Arrange
        $userId = UserId::generate();
        $email = Email::fromString('john@example.com');
        $name = UserName::fromString('John Doe');

        // Act
        $user = User::create($userId, $email, $name);

        // Assert
        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals($userId, $user->getId());
        $this->assertEquals($email, $user->getEmail());
        $this->assertEquals($name, $user->getName());
        $this->assertInvariantHolds($user, 'user_must_have_valid_email');
    }
}
```

### Feature Tests

Feature tests verify complete API workflows and business scenarios.

**Example generated feature test:**
```php
final class UserFeatureTest extends TestCase
{
    use RefreshDatabase;
    use GeneratesTestData;

    /** @test */
    public function it_can_create_user_via_api(): void
    {
        // Arrange
        $admin = User::factory()->create();
        $userData = [
            'email' => $this->generateEmail(),
            'name' => $this->generateName(),
        ];

        // Act
        $response = $this->actingAs($admin)
            ->postJson('/api/users', $userData);

        // Assert
        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => ['id', 'email', 'name', 'created_at']
            ]);

        $this->assertDatabaseHas('users', [
            'email' => $userData['email'],
            'name' => $userData['name'],
        ]);
    }
}
```

### Integration Tests

Integration tests verify cross-module interactions and system-wide workflows.

**Example generated integration test:**
```php
final class UserModuleIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use TestsCommands;
    use TestsQueries;

    /** @test */
    public function it_handles_user_registration_workflow(): void
    {
        // Arrange
        $command = new RegisterUserCommand(
            UserId::generate(),
            Email::fromString('john@example.com'),
            UserName::fromString('John Doe')
        );

        // Act
        $this->dispatchCommand($command);

        // Assert
        $this->assertEventDispatched(UserRegistered::class);
        $this->assertCommandSucceeded($command);

        // Verify side effects in other modules
        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $command->getUserId()->toString(),
        ]);
    }
}
```

## Test Factories

Test factories generate domain-compliant test data that respects business rules.

### Basic Factory Usage

```php
// Create single instance
$user = UserFactory::make();

// Create with custom attributes
$user = UserFactory::make(['email' => 'custom@example.com']);

// Create multiple instances
$users = UserFactory::times(5)->create();

// Use states
$activeUser = UserFactory::active()->create();
$suspendedUser = UserFactory::suspended()->create();
```

### Factory States

Factories come with predefined states for common business scenarios:

```php
// User factory states
UserFactory::active()->create();
UserFactory::inactive()->create();
UserFactory::suspended()->create();
UserFactory::recent()->create();
UserFactory::old()->create();

// Business scenario states
UserFactory::forScenario('new_customer_onboarding')->create();
UserFactory::forScenario('enterprise_client')->create();
UserFactory::forScenario('legacy_migration')->create();
```

### Custom Factory Methods

```php
// Create with relationships
$userWithProfile = UserFactory::withProfile()->create();

// Create for performance testing
$heavyUser = UserFactory::forPerformanceTest()->create();

// Create invalid instances for validation testing
$invalidUser = UserFactory::invalid()->create();

// Create with boundary values
$boundaryUser = UserFactory::boundary()->create();
```

## Assertion Helpers

### Domain Assertions

```php
use LaravelModularDDD\Testing\Assertions\DomainAssertions;

// Assert business invariants
$this->assertInvariantHolds($user, 'user_must_have_valid_email');
$this->assertBusinessRuleSatisfied($order, 'order_total_must_be_positive');

// Assert value object equality
$this->assertValueObjectEquals($expectedEmail, $actualEmail);

// Assert aggregate state
$this->assertAggregateInState($user, 'active');
```

### Event Assertions

```php
use LaravelModularDDD\Testing\Assertions\EventAssertions;

// Assert events were dispatched
$this->assertEventDispatched(UserCreated::class);
$this->assertEventNotDispatched(UserDeleted::class);

// Assert event order
$this->assertEventsDispatchedInOrder([
    UserCreated::class,
    UserActivated::class,
    WelcomeEmailSent::class,
]);

// Assert event data
$this->assertEventDispatched(UserCreated::class, [
    'user_id' => $userId->toString(),
    'email' => 'john@example.com',
]);
```

### Aggregate Assertions

```php
use LaravelModularDDD\Testing\Assertions\AggregateAssertions;

// Assert aggregate consistency
$this->assertAggregateConsistency($user);

// Assert aggregate can be reconstructed from events
$this->assertAggregateCanBeReconstructed($user, $events);

// Assert uncommitted events
$this->assertAggregateHasUncommittedEvents($user, 2);
```

## Test Traits

### MocksRepositories

Provides utilities for mocking repositories and external dependencies:

```php
use LaravelModularDDD\Testing\Traits\MocksRepositories;

public function test_user_service_creates_user(): void
{
    // Mock repository
    $userRepo = $this->mockAggregateRepository(UserRepository::class);
    $userRepo->expects('save')->once();

    // Test service
    $service = new UserService($userRepo);
    $service->createUser($userData);
}
```

### GeneratesTestData

Provides Faker-based test data generation:

```php
use LaravelModularDDD\Testing\Traits\GeneratesTestData;

public function test_with_generated_data(): void
{
    $email = $this->generateEmail();
    $name = $this->generateName();
    $company = $this->generateCompanyName();
    $address = $this->generateAddress();
}
```

### TestsCommands

Utilities for testing CQRS commands:

```php
use LaravelModularDDD\Testing\Traits\TestsCommands;

public function test_command_execution(): void
{
    $command = new CreateUserCommand($userId, $email, $name);

    $this->dispatchCommand($command);
    $this->assertCommandSucceeded($command);
    $this->assertCommandValidated($command);
}
```

### TestsQueries

Utilities for testing CQRS queries:

```php
use LaravelModularDDD\Testing\Traits\TestsQueries;

public function test_query_execution(): void
{
    $query = new GetUserQuery($userId);

    $result = $this->executeQuery($query);
    $this->assertQuerySucceeded($query);
    $this->assertQueryResultMatchesSchema($result, $expectedSchema);
}
```

## Configuration

### Test Environment Setup

Add to your `phpunit.xml`:

```xml
<testsuites>
    <testsuite name="Unit">
        <directory suffix="Test.php">./modules/*/tests/Unit</directory>
    </testsuite>
    <testsuite name="Feature">
        <directory suffix="Test.php">./modules/*/tests/Feature</directory>
    </testsuite>
    <testsuite name="Integration">
        <directory suffix="Test.php">./modules/*/tests/Integration</directory>
    </testsuite>
</testsuites>
```

### Database Configuration

For integration tests, configure a separate test database:

```php
// config/database.php
'connections' => [
    'mysql_testing' => [
        'driver' => 'mysql',
        'host' => env('DB_TEST_HOST', '127.0.0.1'),
        'database' => env('DB_TEST_DATABASE', 'laravel_test'),
        'username' => env('DB_TEST_USERNAME', 'root'),
        'password' => env('DB_TEST_PASSWORD', ''),
    ],
],
```

## Running Tests

### Basic Test Execution

```bash
# Run all tests
php artisan test

# Run specific test suites
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature
php artisan test --testsuite=Integration

# Run tests for specific module
php artisan test modules/UserModule/tests

# Run with coverage
php artisan test --coverage
php artisan test --coverage-html coverage
```

### Performance and Profiling

```bash
# Run performance tests
php artisan test --group=performance

# Profile slow tests
php artisan test --profile

# Memory usage analysis
php artisan test --memory-limit=512M
```

## Best Practices

### Test Organization

1. **Follow the AAA Pattern**: Arrange, Act, Assert
2. **One assertion per test**: Focus on testing one behavior
3. **Descriptive test names**: Use `it_should_do_something_when_condition`
4. **Use factories**: Don't create test data manually
5. **Mock external dependencies**: Keep unit tests isolated

### Domain Testing

1. **Test business invariants**: Verify domain rules are enforced
2. **Test state transitions**: Verify aggregate state changes
3. **Test event emission**: Verify correct events are dispatched
4. **Test edge cases**: Boundary values and error conditions
5. **Test performance**: Critical business operations

### Test Data Management

1. **Use factories**: Generate consistent, valid test data
2. **Create realistic scenarios**: Business-focused test data
3. **Isolate test data**: Each test should be independent
4. **Clean up**: Use database transactions or refresh database
5. **Seed reference data**: Common lookup values

### Continuous Integration

1. **Run tests on every commit**: Automated test execution
2. **Maintain high coverage**: Aim for >80% code coverage
3. **Monitor test performance**: Track slow tests
4. **Parallel execution**: Speed up test suites
5. **Generate reports**: Coverage and quality metrics

## Troubleshooting

### Common Issues

**Tests are slow:**
- Use database transactions instead of `RefreshDatabase`
- Mock external services
- Optimize database queries in tests
- Run tests in parallel

**Tests are flaky:**
- Avoid time-dependent assertions
- Use fixed seeds for random data
- Isolate test data properly
- Mock external dependencies

**Coverage is low:**
- Add tests for edge cases
- Test error conditions
- Test private methods through public interfaces
- Add integration tests for workflows

### Debugging Tests

```bash
# Run with verbose output
php artisan test --verbose

# Debug specific test
php artisan test --filter="test_method_name"

# Print debug information
// In test methods
dump($variable);
dd($variable); // Die and dump
```

## Advanced Topics

### Custom Assertions

Create domain-specific assertions:

```php
class CustomAssertions
{
    public static function assertUserCanPerformAction(User $user, string $action): void
    {
        static::assertTrue(
            $user->hasPermission($action),
            "User {$user->getId()} cannot perform action: {$action}"
        );
    }
}
```

### Test Doubles

Create sophisticated mocks for complex dependencies:

```php
class MockEventStore extends EventStore
{
    private array $events = [];

    public function append(AggregateId $id, array $events): void
    {
        $this->events[$id->toString()] = array_merge(
            $this->events[$id->toString()] ?? [],
            $events
        );
    }

    public function getEvents(AggregateId $id): array
    {
        return $this->events[$id->toString()] ?? [];
    }
}
```

### Performance Testing

```php
class UserPerformanceTest extends TestCase
{
    /** @test */
    public function it_can_handle_bulk_user_creation(): void
    {
        $startTime = microtime(true);

        UserFactory::times(1000)->create();

        $executionTime = microtime(true) - $startTime;
        $this->assertLessThan(5.0, $executionTime, 'Bulk creation took too long');
    }
}
```

## Support

For issues and questions:
- Check the documentation
- Review existing tests as examples
- Use the generated test templates as starting points
- Follow DDD testing best practices
- Keep tests focused on business behavior