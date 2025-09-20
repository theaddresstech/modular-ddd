# Contributing to Laravel Modular DDD

First off, thank you for considering contributing to Laravel Modular DDD! It's people like you that make this package a great tool for the Laravel community.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [Getting Started](#getting-started)
- [Development Setup](#development-setup)
- [How Can I Contribute?](#how-can-i-contribute)
- [Coding Standards](#coding-standards)
- [Testing](#testing)
- [Pull Request Process](#pull-request-process)
- [Reporting Bugs](#reporting-bugs)
- [Suggesting Enhancements](#suggesting-enhancements)
- [Documentation](#documentation)

## Code of Conduct

This project and everyone participating in it is governed by the Laravel Code of Conduct. By participating, you are expected to uphold this code. Please report unacceptable behavior to support@laravel.com.

## Getting Started

1. Fork the repository on GitHub
2. Clone your fork locally
3. Create a new branch for your feature or bug fix
4. Make your changes
5. Push to your fork and submit a pull request

## Development Setup

### Prerequisites

- PHP 8.2 or higher
- Composer 2.0+
- MySQL/PostgreSQL/SQLite for testing
- Redis (optional, for cache testing)
- Git

### Installation

1. Clone your fork:
```bash
git clone https://github.com/YOUR-USERNAME/modular-ddd.git
cd modular-ddd
```

2. Install dependencies:
```bash
composer install
```

3. Copy the test environment file:
```bash
cp .env.testing.example .env.testing
```

4. Configure your test database in `.env.testing`

5. Run tests to ensure everything is working:
```bash
composer test
```

## How Can I Contribute?

### Reporting Bugs

Before creating bug reports, please check the issue list as you might find out that you don't need to create one. When you are creating a bug report, please include as many details as possible:

- **Use a clear and descriptive title**
- **Describe the exact steps to reproduce the problem**
- **Provide specific examples to demonstrate the steps**
- **Describe the behavior you observed after following the steps**
- **Explain which behavior you expected to see instead and why**
- **Include code samples and error messages**
- **Include your environment details** (PHP version, Laravel version, OS)

### Suggesting Enhancements

Enhancement suggestions are tracked as GitHub issues. When creating an enhancement suggestion, please include:

- **Use a clear and descriptive title**
- **Provide a step-by-step description of the suggested enhancement**
- **Provide specific examples to demonstrate the enhancement**
- **Describe the current behavior and explain which behavior you expected**
- **Explain why this enhancement would be useful**
- **List some other packages where this enhancement exists, if applicable**

### Pull Requests

1. **Follow the coding standards** defined below
2. **Update the documentation** if you're changing functionality
3. **Add tests** for new features or bug fixes
4. **Ensure all tests pass** before submitting
5. **Update the CHANGELOG.md** with your changes
6. **One pull request per feature** - If you want to do more than one thing, send multiple pull requests

## Coding Standards

This project follows PSR-12 coding standards and Laravel best practices:

### PHP Code Style

```bash
# Check code style
composer check-style

# Fix code style automatically
composer fix-style
```

### Key Guidelines

1. **Namespace Structure**
   - Follow PSR-4 autoloading
   - Use meaningful namespace organization
   - Keep domain logic separate from infrastructure

2. **Class Design**
   - Single Responsibility Principle
   - Prefer composition over inheritance
   - Use dependency injection
   - Type declarations for parameters and return types

3. **Method Guidelines**
   - Keep methods small and focused
   - Use descriptive method names
   - Document complex logic with comments
   - Avoid more than 3 parameters

4. **Documentation**
   - Add PHPDoc blocks for all public methods
   - Include parameter and return type descriptions
   - Document exceptions that can be thrown
   - Add examples for complex functionality

### Example Code Style

```php
<?php

declare(strict_types=1);

namespace LaravelModularDDD\EventSourcing;

use LaravelModularDDD\Contracts\EventStore as EventStoreContract;
use LaravelModularDDD\Exceptions\AggregateNotFoundException;

/**
 * High-performance event store implementation.
 */
class EventStore implements EventStoreContract
{
    /**
     * Load events for an aggregate.
     *
     * @param string $aggregateId The aggregate identifier
     * @param string $aggregateType The aggregate class name
     * @return array<Event> The events for the aggregate
     * @throws AggregateNotFoundException When aggregate not found
     */
    public function load(string $aggregateId, string $aggregateType): array
    {
        // Implementation
    }
}
```

## Testing

### Running Tests

```bash
# Run all tests
composer test

# Run unit tests only
composer test -- --testsuite=Unit

# Run integration tests only
composer test -- --testsuite=Integration

# Run with coverage
composer test-coverage
```

### Writing Tests

1. **Test Organization**
   - Place unit tests in `tests/Unit`
   - Place integration tests in `tests/Integration`
   - Place feature tests in `tests/Feature`

2. **Test Naming**
   - Use descriptive test names
   - Start with `test_` or use `@test` annotation
   - Include the scenario and expected outcome

3. **Test Structure**
   - Follow Arrange-Act-Assert pattern
   - One assertion per test when possible
   - Use data providers for multiple scenarios

### Example Test

```php
<?php

namespace Tests\Unit\EventSourcing;

use Tests\TestCase;
use LaravelModularDDD\EventSourcing\EventStore;

class EventStoreTest extends TestCase
{
    /** @test */
    public function it_stores_and_retrieves_events(): void
    {
        // Arrange
        $store = new EventStore();
        $aggregateId = 'test-123';
        $events = [new TestEvent('data')];

        // Act
        $store->save($aggregateId, $events);
        $retrieved = $store->load($aggregateId);

        // Assert
        $this->assertEquals($events, $retrieved);
    }
}
```

## Static Analysis

Run PHPStan to ensure code quality:

```bash
# Run static analysis
composer analyse

# Run at max level
composer analyse -- --level=max
```

## Documentation

- Update README.md for user-facing changes
- Update relevant documentation in `/docs`
- Add PHPDoc blocks for new methods
- Include code examples for new features
- Update CHANGELOG.md following [Keep a Changelog](https://keepachangelog.com/)

## Pull Request Process

1. **Before submitting:**
   - Rebase your branch on the latest main branch
   - Ensure all tests pass
   - Run code style fixes
   - Run static analysis
   - Update documentation

2. **PR Description should include:**
   - Summary of changes
   - Motivation for changes
   - Any breaking changes
   - Testing performed
   - Screenshots (if UI changes)

3. **After submitting:**
   - Respond to code review comments
   - Make requested changes
   - Keep your branch up to date with main

## Release Process

Releases are managed by the maintainers. The process includes:

1. Update CHANGELOG.md
2. Update version in composer.json
3. Create git tag
4. Push to Packagist
5. Create GitHub release

## Questions?

Feel free to open an issue for any questions about contributing. We're here to help!

## Recognition

Contributors will be recognized in:
- The README.md contributors section
- The GitHub contributors page
- Release notes when applicable

Thank you for contributing to Laravel Modular DDD!