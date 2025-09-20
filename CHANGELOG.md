# Changelog

All notable changes to `theaddresstech/modular-ddd` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2024-01-20

### Added
- Initial release of Laravel Modular DDD package
- Complete Domain-Driven Design (DDD) architecture implementation
- Event Sourcing with tiered storage (Redis + MySQL)
- CQRS (Command Query Responsibility Segregation) with multi-tier caching
- Module-based application structure
- Code generation commands for rapid development
- Comprehensive testing framework

#### Core Features
- **Event Sourcing**
  - High-performance event store with tiered architecture
  - Multiple snapshot strategies (Simple, Adaptive, Time-based)
  - Event replay and projection support
  - Event versioning for schema evolution
  - Batch operations for optimized performance

- **CQRS Implementation**
  - Command Bus with middleware pipeline
  - Query Bus with intelligent caching
  - Multi-tier caching (L1 Memory + L2 Redis + L3 Database)
  - Async command processing via queues
  - Built-in validation and authorization

- **Module System**
  - Clean module separation following DDD principles
  - Event-driven communication between modules
  - Direct module-to-module messaging via Module Bus
  - Circuit breakers for resilient module dependencies
  - Async messaging support

- **Code Generators**
  - `modular:make:module` - Generate complete DDD module structure
  - `modular:make:aggregate` - Create aggregates with event sourcing
  - `modular:make:command` - Generate commands and handlers
  - `modular:make:query` - Generate queries and handlers
  - `modular:make:event` - Create domain events
  - `modular:make:repository` - Generate repository implementations
  - `modular:make:value-object` - Create value objects
  - `modular:make:service` - Generate domain services

- **Production Features**
  - Health monitoring system
  - Performance metrics tracking
  - Circuit breakers for failure handling
  - Database query optimization
  - Intelligent cache management
  - Configurable performance profiles

- **Testing Utilities**
  - Aggregate testing framework
  - In-memory event store for tests
  - Command and query testing helpers
  - Integration test support
  - Performance benchmarking tools

### Configuration
- Flexible configuration via `config/modular-ddd.php`
- Environment-based settings support
- Performance profile selection (Startup, Growth, Scale, Enterprise)
- Customizable cache TTL and strategies
- Configurable snapshot thresholds

### Documentation
- Comprehensive documentation in `/docs` directory
- Architecture overview and best practices
- Getting started guide
- API reference documentation
- Performance tuning guide
- Production deployment guide

### Requirements
- PHP 8.2 or higher
- Laravel 11.0 or higher
- MySQL 8.0+ / PostgreSQL 13+ / SQLite 3.35+
- Redis (optional, for caching)
- Composer 2.0+

[Unreleased]: https://github.com/theaddresstech/modular-ddd/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/theaddresstech/modular-ddd/releases/tag/v1.0.0