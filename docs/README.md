# Laravel Modular DDD Package

A production-ready Laravel package for building modular applications using Domain-Driven Design with Event Sourcing and CQRS patterns.

## Table of Contents

- [Architecture Overview](architecture/README.md)
- [Getting Started](getting-started/README.md)
- [Event Sourcing Guide](event-sourcing/README.md)
- [CQRS Implementation](cqrs/README.md)
- [Module Communication](module-communication/README.md)
- [Production Deployment](production/README.md)
- [API Reference](api-reference/README.md)
- [Performance Guide](performance/README.md)
- [Testing Guide](testing/README.md)
- [Migration Guide](migration/README.md)
- **[Generator Commands Reference](commands/README.md)** - Complete guide to all code generation commands

## Key Features

### Event Sourcing
- **Tiered Storage**: Hot tier (Redis) + Warm tier (MySQL) for optimal performance
- **Snapshots**: Configurable strategies (Simple, Adaptive, Time-based)
- **Event Ordering**: Strict sequencing guarantees per aggregate
- **Batch Operations**: Optimized bulk loading and processing
- **Event Versioning**: Built-in support for event schema evolution

### CQRS (Command Query Responsibility Segregation)
- **Multi-tier Caching**: L1 (Memory) + L2 (Redis) + L3 (Database)
- **Command Pipeline**: Validation, authorization, transactions, event dispatching
- **Query Optimization**: Intelligent caching with memory management
- **Async Processing**: Queue-based command processing with status tracking

### Transaction Management
- **Distributed Transactions**: Two-phase commit support
- **Deadlock Recovery**: Automatic retry with exponential backoff
- **Isolation Levels**: Configurable transaction isolation
- **Timeout Management**: Configurable transaction timeouts

### Module Communication
- **Event-driven Architecture**: Inter-module communication via events
- **Message Bus**: Direct module-to-module messaging
- **Async Processing**: Queue-based async communication
- **Circuit Breaker**: Resilience patterns for module dependencies

### Production Monitoring
- **Health Checks**: Comprehensive system health monitoring
- **Performance Metrics**: Real-time performance tracking
- **Alerting**: Configurable performance thresholds
- **Circuit Breakers**: Automatic failure handling

### Testing Framework
- **Event Store Testing**: In-memory stores for testing
- **CQRS Testing**: Command and query testing utilities
- **Integration Testing**: Full-stack testing support
- **Performance Testing**: Built-in benchmarking tools

## Quick Start

```bash
composer require laravel/modular-ddd
php artisan vendor:publish --provider="LaravelModularDDD\ModularDddServiceProvider"
php artisan migrate
```

## Architecture at a Glance

```
┌─────────────────────────────────────────────────────────────┐
│                     Laravel Application                     │
├─────────────────────────────────────────────────────────────┤
│  Module A        │  Module B        │  Module C            │
│ ┌─────────────┐  │ ┌─────────────┐  │ ┌─────────────┐      │
│ │ Application │  │ │ Application │  │ │ Application │      │
│ │ Domain      │  │ │ Domain      │  │ │ Domain      │      │
│ │ Infrastructure│ │ │ Infrastructure│ │ │ Infrastructure│   │
│ └─────────────┘  │ └─────────────┘  │ └─────────────┘      │
├─────────────────────────────────────────────────────────────┤
│                    Shared Kernel                            │
│ ┌─────────────┐ ┌─────────────┐ ┌─────────────┐           │
│ │ Event       │ │ CQRS        │ │ Module      │           │
│ │ Sourcing    │ │ Bus         │ │ Communication│          │
│ └─────────────┘ └─────────────┘ └─────────────┘           │
├─────────────────────────────────────────────────────────────┤
│          Storage Layer (Redis + MySQL + Cache)             │
└─────────────────────────────────────────────────────────────┘
```

## Performance Profiles

The package includes pre-configured performance profiles:

- **Startup**: Minimal resources for small applications
- **Growth**: Balanced performance for growing applications
- **Scale**: High performance for large-scale applications
- **Enterprise**: Maximum performance for enterprise applications

See the [Performance Guide](performance/README.md) for detailed configuration.

## Support

- Documentation: [Full documentation](https://laravel-modular-ddd.readthedocs.io)
- Issues: [GitHub Issues](https://github.com/laravel/modular-ddd/issues)
- Discussions: [GitHub Discussions](https://github.com/laravel/modular-ddd/discussions)

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).
