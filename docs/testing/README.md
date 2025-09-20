# Testing Framework

The Laravel DDD Modules package includes a comprehensive testing framework designed specifically for Domain-Driven Design, Event Sourcing, and CQRS architectures.

## 📋 Quick Reference

### Generate Tests

```bash
# Generate all tests for a module
php artisan module:test UserModule

# Generate specific test types
php artisan module:test UserModule --type=unit
php artisan module:test UserModule --type=feature
php artisan module:test UserModule --type=integration
```

### Generate Factories

```bash
# Generate all factories for a module
php artisan module:factory UserModule --all

# Generate factory for specific component
php artisan module:factory UserModule aggregate User
```

### Run Tests

```bash
# Run all tests
php artisan test

# Run specific test suite
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature
php artisan test --testsuite=Integration

# Run with coverage
php artisan test --coverage
```

## 📚 Documentation

- **[Test Framework Guide](test-framework-guide.md)** - Complete guide to using the test framework
- **[Unit Testing](unit-testing.md)** - Testing domain components in isolation
- **[Feature Testing](feature-testing.md)** - Testing API endpoints and workflows
- **[Integration Testing](integration-testing.md)** - Testing cross-module interactions
- **[Test Factories](test-factories.md)** - Generating domain-compliant test data
- **[Assertions](assertions.md)** - Domain-specific assertion helpers
- **[Test Traits](test-traits.md)** - Reusable testing behaviors

## 🏗️ Architecture

The testing framework follows a layered architecture:

```
Testing Framework
├── Generators/          # Test and factory generation
│   ├── TestGenerator        # Main orchestrator
│   ├── UnitTestGenerator    # Unit test generation
│   ├── FeatureTestGenerator # Feature test generation
│   ├── IntegrationTestGenerator # Integration test generation
│   └── FactoryGenerator     # Test factory generation
├── Assertions/          # Domain-specific assertions
│   ├── DomainAssertions     # Business rule assertions
│   ├── EventAssertions      # Event sourcing assertions
│   └── AggregateAssertions  # Aggregate consistency assertions
├── Traits/              # Reusable testing behaviors
│   ├── MocksRepositories    # Repository mocking utilities
│   ├── GeneratesTestData    # Test data generation
│   ├── AssertsInvariants    # Business invariant validation
│   ├── TestsCommands        # CQRS command testing
│   └── TestsQueries         # CQRS query testing
└── Factories/           # Base factory classes
    └── BaseFactory          # Foundation for all test factories
```

## 🎯 Test Types

### Unit Tests
- Test domain components in isolation
- Focus on business logic and domain rules
- Mock external dependencies
- Fast execution

### Feature Tests
- Test complete API workflows
- Verify request-response cycles
- Include authentication and authorization
- Test business scenarios end-to-end

### Integration Tests
- Test cross-module interactions
- Verify event flows and sagas
- Test system-wide workflows
- Include database and external services

## 🏭 Test Factories

Test factories generate domain-compliant test data:

```php
// Basic usage
$user = UserFactory::make();
$users = UserFactory::times(5)->create();

// With states
$activeUser = UserFactory::active()->create();
$suspendedUser = UserFactory::suspended()->create();

// Business scenarios
$enterpriseClient = UserFactory::forScenario('enterprise_client')->create();
```

## 🔍 Assertions

Domain-specific assertions for DDD patterns:

```php
// Domain assertions
$this->assertInvariantHolds($user, 'user_must_have_valid_email');
$this->assertBusinessRuleSatisfied($order, 'order_total_must_be_positive');

// Event assertions
$this->assertEventDispatched(UserCreated::class);
$this->assertEventsDispatchedInOrder([UserCreated::class, UserActivated::class]);

// Aggregate assertions
$this->assertAggregateConsistency($user);
$this->assertAggregateCanBeReconstructed($user, $events);
```

## 🛠️ Console Commands

### module:test
Generate comprehensive test suites for modules.

**Syntax:**
```bash
php artisan module:test {module?} [options]
```

**Options:**
- `--type=TYPE` - Test type (unit, feature, integration, all)
- `--aggregate=NAME` - Specific aggregate to test
- `--command=NAME` - Specific command to test
- `--query=NAME` - Specific query to test
- `--force` - Overwrite existing files
- `--performance` - Include performance tests
- `--coverage` - Generate coverage analysis

### module:factory
Generate test data factories for DDD components.

**Syntax:**
```bash
php artisan module:factory {module} {type?} {name?} [options]
```

**Options:**
- `--all` - Generate factories for all components
- `--states` - Include state factories
- `--sequences` - Include sequence generators
- `--registry` - Generate factory registry
- `--force` - Overwrite existing files

## 📈 Best Practices

1. **Follow AAA Pattern**: Arrange, Act, Assert
2. **Test Business Behavior**: Focus on domain rules and workflows
3. **Use Descriptive Names**: `it_should_do_something_when_condition`
4. **Leverage Factories**: Generate realistic test data
5. **Mock Dependencies**: Keep tests isolated and fast
6. **Test Edge Cases**: Boundary values and error conditions
7. **Maintain High Coverage**: Aim for >80% code coverage
8. **Run Tests Continuously**: Automated execution on every commit

## 🚀 Getting Started

1. **Generate your first test suite:**
   ```bash
   php artisan module:test UserModule
   ```

2. **Create test factories:**
   ```bash
   php artisan module:factory UserModule --all
   ```

3. **Run the tests:**
   ```bash
   php artisan test
   ```

4. **Review and customize** the generated tests to match your business requirements

5. **Add to CI/CD pipeline** for automated testing

## 📖 Examples

See the generated test files for practical examples of:
- Domain unit tests with business rule validation
- Feature tests with complete API workflows
- Integration tests with cross-module interactions
- Test factories with business scenario states
- Custom assertions for domain-specific validation

## 🤝 Contributing

When adding new testing capabilities:
1. Follow the existing patterns and conventions
2. Add comprehensive documentation
3. Include examples and best practices
4. Ensure backward compatibility
5. Add tests for the testing framework itself