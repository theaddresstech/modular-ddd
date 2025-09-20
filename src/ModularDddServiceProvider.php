<?php

declare(strict_types=1);

namespace LaravelModularDDD;

use Illuminate\Support\ServiceProvider;
use LaravelModularDDD\EventSourcing\Snapshot\SnapshotStrategyInterface;
use LaravelModularDDD\EventSourcing\Snapshot\SnapshotStrategyFactory;
use LaravelModularDDD\EventSourcing\Contracts\EventStoreInterface;
use LaravelModularDDD\EventSourcing\EventStore\MySQLEventStore;
use LaravelModularDDD\EventSourcing\EventStore\RedisEventStore;
use LaravelModularDDD\EventSourcing\EventStore\TieredEventStore;
use LaravelModularDDD\EventSourcing\EventStore\EventSerializer;
use LaravelModularDDD\EventSourcing\Contracts\SnapshotStoreInterface;
use LaravelModularDDD\EventSourcing\Snapshot\SnapshotStore;
use LaravelModularDDD\CQRS\Contracts\CommandBusInterface;
use LaravelModularDDD\CQRS\Contracts\QueryBusInterface;
use LaravelModularDDD\CQRS\CommandBus;
use LaravelModularDDD\CQRS\QueryBus;
use LaravelModularDDD\CQRS\Caching\MultiTierCacheManager;
use LaravelModularDDD\CQRS\Monitoring\MetricsCollectorInterface;
use LaravelModularDDD\CQRS\Monitoring\InMemoryMetricsCollector;
use LaravelModularDDD\CQRS\Monitoring\PerformanceMonitor;
use LaravelModularDDD\CQRS\Security\CommandAuthorizationManager;
use LaravelModularDDD\CQRS\Async\AsyncStrategyInterface;
use LaravelModularDDD\CQRS\Async\AsyncStatusRepository;
use LaravelModularDDD\CQRS\Async\Strategies\LaravelQueueStrategy;
use LaravelModularDDD\CQRS\Async\Strategies\SyncStrategy;
use LaravelModularDDD\EventSourcing\Listeners\ProjectionEventBridge;
use LaravelModularDDD\EventSourcing\Projections\ProjectionStrategyInterface;
use LaravelModularDDD\EventSourcing\Projections\Strategies\AsyncProjectionStrategy;
use LaravelModularDDD\EventSourcing\Projections\Strategies\RealtimeProjectionStrategy;
use LaravelModularDDD\EventSourcing\Projections\Strategies\BatchedProjectionStrategy;
use LaravelModularDDD\CQRS\Middleware\TransactionMiddleware;
use LaravelModularDDD\CQRS\Middleware\ValidationMiddleware;
use LaravelModularDDD\CQRS\Middleware\AuthorizationMiddleware;
use LaravelModularDDD\CQRS\Middleware\EventDispatchingMiddleware;
use LaravelModularDDD\Core\Application\Contracts\TransactionManagerInterface;
use LaravelModularDDD\Core\Application\Services\TransactionManager;
use LaravelModularDDD\Monitoring\PerformanceMetricsCollector;
use LaravelModularDDD\EventSourcing\Ordering\EventSequencer;
use LaravelModularDDD\Modules\Communication\Contracts\ModuleBusInterface;
use LaravelModularDDD\Modules\Communication\ModuleBus;
use LaravelModularDDD\Support\CommandBusManager;
use LaravelModularDDD\Support\QueryBusManager;
use LaravelModularDDD\Documentation\DocumentationGenerator;
use LaravelModularDDD\CQRS\ErrorHandling\ErrorHandlerManager;
use LaravelModularDDD\CQRS\ErrorHandling\LoggingErrorHandler;
use LaravelModularDDD\CQRS\ErrorHandling\NotificationErrorHandler;
use LaravelModularDDD\CQRS\ErrorHandling\DeadLetterQueue;
use LaravelModularDDD\CQRS\ErrorHandling\CircuitBreaker;
use LaravelModularDDD\CQRS\ErrorHandling\RetryPolicy;
use LaravelModularDDD\CQRS\Caching\CacheEvictionManager;
use LaravelModularDDD\CQRS\Caching\CacheInvalidationManager;
use LaravelModularDDD\CQRS\Saga\SagaManager;
use LaravelModularDDD\CQRS\Saga\Persistence\DatabaseSagaRepository;
use LaravelModularDDD\CQRS\Saga\Persistence\SagaRepositoryInterface;
use LaravelModularDDD\CQRS\ReadModels\ReadModelManager;
use LaravelModularDDD\CQRS\ReadModels\Persistence\DatabaseReadModelRepository;
use LaravelModularDDD\CQRS\ReadModels\Persistence\ReadModelRepositoryInterface;
use LaravelModularDDD\EventSourcing\Archival\EventArchivalManager;
use LaravelModularDDD\EventSourcing\Versioning\EventVersioningManager;
use LaravelModularDDD\EventSourcing\Performance\EventDeserializationCache;
use LaravelModularDDD\EventSourcing\Performance\EventObjectPool;
use LaravelModularDDD\Core\Application\Repository\BatchAggregateRepository;
use LaravelModularDDD\CQRS\BatchQueryExecutor;
use LaravelModularDDD\CQRS\Projections\BatchProjectionLoader;
use LaravelModularDDD\CQRS\Monitoring\MemoryLeakDetector;
use LaravelModularDDD\CQRS\Integration\CQRSEventStoreIntegration;
use LaravelModularDDD\CQRS\Integration\EventProjectionBridge;

class ModularDddServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/modular-ddd.php',
            'modular-ddd'
        );

        $this->registerEventSourcing();
        $this->registerTransactionManager();
        $this->registerCQRS();
        $this->registerAsync();
        $this->registerProjections();
        $this->registerMonitoring();
        $this->registerSecurity();
        $this->registerModuleCommunication();
        $this->registerGenerators();
        $this->registerSupport();
        $this->registerErrorHandling();
        $this->registerSagas();
        $this->registerReadModels();
        $this->registerEventSourcingExtensions();
        $this->registerBatchProcessing();
        $this->registerCacheManagement();
        $this->registerDocumentation();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/modular-ddd.php' => config_path('modular-ddd.php'),
            ], 'config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'migrations');

            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

            // Register console commands
            $this->commands([
                Console\BenchmarkCommand::class,
                Console\StressTestCommand::class,
                Console\Commands\ModuleMakeCommand::class,
                Console\Commands\AggregateGenerateCommand::class,
                Console\Commands\CommandGenerateCommand::class,
                Console\Commands\QueryGenerateCommand::class,
                Console\Commands\RepositoryGenerateCommand::class,
                Console\Commands\TestFactoryCommand::class,
                Console\Commands\ModuleListCommand::class,
                Console\Commands\ModuleInfoCommand::class,
                Console\Commands\ModuleEnableCommand::class,
                Console\Commands\ModuleDisableCommand::class,
                Console\Commands\ModuleMigrateCommand::class,
                Console\Commands\ModuleMakeMigrationCommand::class,
                Console\Commands\ModuleMigrateRollbackCommand::class,
                Console\Commands\ModuleMigrateStatusCommand::class,
                Console\Commands\ModuleTestCommand::class,
                Console\Commands\ModuleHealthCommand::class,
                Console\Commands\ModuleDocumentationCommand::class,
            ]);
        }

        // Load health check routes
        $this->loadRoutesFrom(__DIR__ . '/../routes/health.php');

        // Defer projection setup to avoid circular dependencies during boot
        $this->app->booted(function () {
            $this->configureProjectionStrategies();
            $this->registerProjectionListeners();
        });
    }

    private function registerEventSourcing(): void
    {
        // Register Event Serializer
        $this->app->singleton(EventSerializer::class);

        // Register Event Sequencer
        $this->app->singleton(EventSequencer::class, function ($app) {
            $config = config('modular-ddd.event_sourcing.ordering', []);

            return new EventSequencer(
                $config['strict_ordering'] ?? true,
                $config['max_reorder_window'] ?? 100
            );
        });

        // Register Snapshot Strategy
        $this->app->singleton(SnapshotStrategyInterface::class, function ($app) {
            $config = config('modular-ddd.event_sourcing.snapshots', []);

            // Validate configuration
            $errors = SnapshotStrategyFactory::validateConfig($config);
            if (!empty($errors)) {
                throw new \InvalidArgumentException(
                    'Invalid snapshot configuration: ' . implode(', ', $errors)
                );
            }

            return SnapshotStrategyFactory::create($config);
        });

        // Register Snapshot Store
        $this->app->singleton(SnapshotStoreInterface::class, function ($app) {
            $connection = $app->make('db.connection');
            $strategy = $app->make(SnapshotStrategyInterface::class);

            return new SnapshotStore($connection, $strategy);
        });

        // Register PRD-compliant Event-Sourced Aggregate Repository
        $this->app->singleton(
            \LaravelModularDDD\Core\Infrastructure\Repository\EventSourcedAggregateRepository::class,
            function ($app) {
                return new \LaravelModularDDD\Core\Infrastructure\Repository\EventSourcedAggregateRepository(
                    $app->make(EventStoreInterface::class),
                    $app->make(SnapshotStoreInterface::class),
                    $app->make(SnapshotStrategyInterface::class)
                );
            }
        );

        // Register Event Store
        $this->app->singleton(EventStoreInterface::class, function ($app) {
            $config = config('modular-ddd.event_sourcing', []);
            $serializer = $app->make(EventSerializer::class);

            // Use tiered storage if both hot and warm are enabled
            if (isset($config['storage_tiers']['hot']['enabled']) &&
                $config['storage_tiers']['hot']['enabled'] &&
                isset($config['storage_tiers']['warm'])) {
                return new TieredEventStore(
                    $app->make(RedisEventStore::class),
                    $app->make(MySQLEventStore::class),
                    $app->make('queue'),
                    $config['storage_tiers']['async_warm_storage'] ?? true
                );
            }

            // Default to MySQL event store
            $connection = $app->make('db.connection');
            return new MySQLEventStore($connection, $serializer);
        });

        // Register Redis Event Store (for tiered storage)
        $this->app->singleton(RedisEventStore::class, function ($app) {
            $redis = $app->make('redis.connection');
            $serializer = $app->make(EventSerializer::class);
            $config = config('modular-ddd.event_sourcing.storage_tiers.hot', []);

            return new RedisEventStore(
                $redis,
                $serializer,
                $config['ttl'] ?? 3600
            );
        });

        // Register MySQL Event Store
        $this->app->singleton(MySQLEventStore::class, function ($app) {
            $connection = $app->make('db.connection');
            $serializer = $app->make(EventSerializer::class);
            $eventSequencer = $app->make(EventSequencer::class);

            return new MySQLEventStore($connection, $serializer, $eventSequencer);
        });
    }

    private function registerTransactionManager(): void
    {
        // Register Transaction Manager
        $this->app->singleton(TransactionManagerInterface::class, function ($app) {
            $connection = $app->make('db.connection');
            $config = config('modular-ddd.transactions', []);

            return new TransactionManager(
                $connection,
                $config['deadlock_retry_attempts'] ?? 3,
                $config['deadlock_retry_delay'] ?? 100
            );
        });
    }

    private function registerCQRS(): void
    {
        // Register Multi-Tier Cache Manager
        $this->app->singleton(MultiTierCacheManager::class, function ($app) {
            $config = config('modular-ddd.cqrs.query_bus', []);

            $cacheManager = new MultiTierCacheManager(
                $config['cache_stores']['l2'] ?? 'redis',
                $config['cache_stores']['l3'] ?? 'database'
            );

            // Configure memory limits
            $memoryConfig = $config['memory_limits'] ?? [];
            $cacheManager->setMemoryLimits(
                $memoryConfig['l1_max_entries'] ?? 1000,
                $memoryConfig['max_memory_mb'] ?? 128,
                $memoryConfig['eviction_threshold'] ?? 0.8
            );

            return $cacheManager;
        });

        // Register Command Bus
        $this->app->singleton(CommandBusInterface::class, function ($app) {
            $pipeline = $app->make('Illuminate\Pipeline\Pipeline');
            $queue = $app->make('Illuminate\Contracts\Queue\Queue');
            $authManager = $app->make(CommandAuthorizationManager::class);
            $asyncStrategy = $app->make(AsyncStrategyInterface::class);

            $commandBus = new CommandBus($pipeline, $queue, 'sync', $authManager, $asyncStrategy);

            // Register middleware in order of execution
            $commandBus->addMiddleware(new ValidationMiddleware());
            $commandBus->addMiddleware(new AuthorizationMiddleware($authManager));
            $commandBus->addMiddleware(new TransactionMiddleware($app->make(TransactionManagerInterface::class)));
            $commandBus->addMiddleware(new EventDispatchingMiddleware());

            return $commandBus;
        });

        // Register Query Bus
        $this->app->singleton(QueryBusInterface::class, function ($app) {
            $cacheManager = $app->make(MultiTierCacheManager::class);
            $authManager = $app->make(CommandAuthorizationManager::class);
            return new QueryBus($cacheManager, $authManager);
        });
    }

    private function registerMonitoring(): void
    {
        // Register Metrics Collector
        $this->app->singleton(MetricsCollectorInterface::class, function ($app) {
            $collector = config('modular-ddd.performance.monitoring.metrics_collector', 'memory');

            return match ($collector) {
                'memory' => new InMemoryMetricsCollector(),
                default => new InMemoryMetricsCollector(),
            };
        });

        // Register Performance Metrics Collector
        $this->app->singleton(PerformanceMetricsCollector::class);

        // Register Performance Monitor
        $this->app->singleton(PerformanceMonitor::class, function ($app) {
            $metricsCollector = $app->make(MetricsCollectorInterface::class);
            $thresholds = config('modular-ddd.performance.monitoring.performance_thresholds', []);

            return new PerformanceMonitor($metricsCollector, $thresholds);
        });
    }

    private function registerSecurity(): void
    {
        // Register Command Authorization Manager
        $this->app->singleton(CommandAuthorizationManager::class, function ($app) {
            $config = config('modular-ddd.security.authorization', []);
            $strictMode = $config['strict_mode'] ?? true;

            return new CommandAuthorizationManager($strictMode);
        });
    }

    private function registerModuleCommunication(): void
    {
        // Register Module Bus
        $this->app->singleton(ModuleBusInterface::class, function ($app) {
            $config = config('modular-ddd.module_communication', []);

            return new ModuleBus(
                $app->make(CommandBusInterface::class),
                $app->make(QueryBusInterface::class),
                $app->make('events'),
                $app->make('queue')->connection(),
                $app->make('log'),
                $config
            );
        });
    }

    private function registerAsync(): void
    {
        // Register Async Status Repository
        $this->app->singleton(AsyncStatusRepository::class);

        // Register Async Strategy
        $this->app->singleton(AsyncStrategyInterface::class, function ($app) {
            $config = config('modular-ddd.async', []);
            $strategy = $config['strategy'] ?? 'laravel_queue';

            return match ($strategy) {
                'sync' => new SyncStrategy(
                    $app->make(AsyncStatusRepository::class),
                    $app->make(CommandBusInterface::class)
                ),
                'laravel_queue' => new LaravelQueueStrategy(
                    $app->make(AsyncStatusRepository::class),
                    $config['queue'] ?? 'commands'
                ),
                default => throw new \InvalidArgumentException("Unknown async strategy: {$strategy}")
            };
        });
    }

    private function registerProjections(): void
    {
        // Register ProjectionManager first to avoid circular dependencies
        $this->app->singleton('LaravelModularDDD\\EventSourcing\\Projections\\ProjectionManager', function ($app) {
            return new \LaravelModularDDD\EventSourcing\Projections\ProjectionManager(
                $app->make(EventStoreInterface::class),
                config('modular-ddd.projections.async_processing', false)
            );
        });

        // Register Projection Event Bridge with lazy strategy loading
        $this->app->singleton(ProjectionEventBridge::class, function ($app) {
            return new ProjectionEventBridge();
        });
    }

    private function createProjectionStrategy($app, string $name, array $config): ?ProjectionStrategyInterface
    {
        return match ($name) {
            'realtime' => new RealtimeProjectionStrategy(
                $app->make('LaravelModularDDD\\EventSourcing\\Projections\\ProjectionManager'),
                $config['event_patterns'] ?? ['*']
            ),
            'async' => new AsyncProjectionStrategy(
                $app->make('Illuminate\\Contracts\\Queue\\Queue'),
                $config['event_patterns'] ?? ['*'],
                $config['queue'] ?? 'projections',
                $config['delay'] ?? 0
            ),
            'batched' => new BatchedProjectionStrategy(
                $app->make('LaravelModularDDD\\EventSourcing\\Projections\\ProjectionManager'),
                $config['event_patterns'] ?? ['*'],
                $config['batch_size'] ?? 100,
                $config['batch_timeout'] ?? 60
            ),
            default => null
        };
    }

    private function configureProjectionStrategies(): void
    {
        $bridge = $this->app->make(ProjectionEventBridge::class);

        // Register configured projection strategies
        $config = config('modular-ddd.projections', []);
        $strategies = $config['strategies'] ?? ['realtime'];

        foreach ($strategies as $strategyName => $strategyConfig) {
            if (is_numeric($strategyName)) {
                $strategyName = $strategyConfig;
                $strategyConfig = [];
            }

            $strategy = $this->createProjectionStrategy($this->app, $strategyName, $strategyConfig);
            if ($strategy) {
                $bridge->registerStrategy($strategy);
            }
        }
    }

    private function registerProjectionListeners(): void
    {
        // Register projection event bridge as a global event listener
        $this->app->make('events')->listen('*', function ($eventName, $data) {
            $bridge = $this->app->make(ProjectionEventBridge::class);

            // Handle both array and single event data
            $events = is_array($data) ? $data : [$data];

            foreach ($events as $event) {
                $bridge->handle($event);
            }
        });
    }

    private function registerGenerators(): void
    {
        // Register Module Discovery and Registry
        $this->app->singleton(\LaravelModularDDD\Support\ModuleDiscovery::class, function ($app) {
            return new \LaravelModularDDD\Support\ModuleDiscovery(
                config('modular-ddd.modules_path', base_path('Modules')),
                $app->make('files')
            );
        });

        $this->app->singleton(\LaravelModularDDD\Support\ModuleRegistry::class, function ($app) {
            return new \LaravelModularDDD\Support\ModuleRegistry(
                $app->make(\LaravelModularDDD\Support\ModuleDiscovery::class),
                $app->make('cache')->store()
            );
        });

        // Register StubProcessor first as it's a dependency for other generators
        $this->app->singleton(\LaravelModularDDD\Generators\StubProcessor::class, function ($app) {
            return new \LaravelModularDDD\Generators\StubProcessor(
                $app->make('files')
            );
        });

        // Register all generators with their dependencies
        $this->app->singleton(\LaravelModularDDD\Generators\ModuleGenerator::class, function ($app) {
            return new \LaravelModularDDD\Generators\ModuleGenerator(
                $app->make('files'),
                $app->make(\LaravelModularDDD\Generators\StubProcessor::class)
            );
        });

        $this->app->singleton(\LaravelModularDDD\Generators\AggregateGenerator::class, function ($app) {
            return new \LaravelModularDDD\Generators\AggregateGenerator(
                $app->make('files'),
                $app->make(\LaravelModularDDD\Generators\StubProcessor::class)
            );
        });

        $this->app->singleton(\LaravelModularDDD\Generators\CommandGenerator::class, function ($app) {
            return new \LaravelModularDDD\Generators\CommandGenerator(
                $app->make('files'),
                $app->make(\LaravelModularDDD\Generators\StubProcessor::class)
            );
        });

        $this->app->singleton(\LaravelModularDDD\Generators\QueryGenerator::class, function ($app) {
            return new \LaravelModularDDD\Generators\QueryGenerator(
                $app->make('files'),
                $app->make(\LaravelModularDDD\Generators\StubProcessor::class)
            );
        });

        $this->app->singleton(\LaravelModularDDD\Generators\RepositoryGenerator::class, function ($app) {
            return new \LaravelModularDDD\Generators\RepositoryGenerator(
                $app->make('files'),
                $app->make(\LaravelModularDDD\Generators\StubProcessor::class)
            );
        });

        $this->app->singleton(\LaravelModularDDD\Generators\ServiceGenerator::class, function ($app) {
            return new \LaravelModularDDD\Generators\ServiceGenerator(
                $app->make('files'),
                $app->make(\LaravelModularDDD\Generators\StubProcessor::class)
            );
        });

        // Register test generators with their dependencies - order matters!
        $this->app->singleton(\LaravelModularDDD\Testing\Generators\UnitTestGenerator::class, function ($app) {
            return new \LaravelModularDDD\Testing\Generators\UnitTestGenerator(
                $app->make('files'),
                $app->make(\LaravelModularDDD\Generators\StubProcessor::class),
                $app->make(\LaravelModularDDD\Support\ModuleRegistry::class)
            );
        });

        $this->app->singleton(\LaravelModularDDD\Testing\Generators\FeatureTestGenerator::class, function ($app) {
            return new \LaravelModularDDD\Testing\Generators\FeatureTestGenerator(
                $app->make('files'),
                $app->make(\LaravelModularDDD\Generators\StubProcessor::class),
                $app->make(\LaravelModularDDD\Support\ModuleRegistry::class)
            );
        });

        $this->app->singleton(\LaravelModularDDD\Testing\Generators\IntegrationTestGenerator::class, function ($app) {
            return new \LaravelModularDDD\Testing\Generators\IntegrationTestGenerator(
                $app->make('files'),
                $app->make(\LaravelModularDDD\Generators\StubProcessor::class),
                $app->make(\LaravelModularDDD\Support\ModuleRegistry::class)
            );
        });

        $this->app->singleton(\LaravelModularDDD\Testing\Generators\FactoryGenerator::class, function ($app) {
            return new \LaravelModularDDD\Testing\Generators\FactoryGenerator(
                $app->make('files'),
                $app->make(\LaravelModularDDD\Generators\StubProcessor::class),
                $app->make(\LaravelModularDDD\Support\ModuleRegistry::class)
            );
        });

        // Register TestGenerator last as it depends on all other test generators
        $this->app->singleton(\LaravelModularDDD\Testing\Generators\TestGenerator::class, function ($app) {
            return new \LaravelModularDDD\Testing\Generators\TestGenerator(
                $app->make('files'),
                $app->make(\LaravelModularDDD\Generators\StubProcessor::class),
                $app->make(\LaravelModularDDD\Support\ModuleRegistry::class),
                $app->make(\LaravelModularDDD\Testing\Generators\UnitTestGenerator::class),
                $app->make(\LaravelModularDDD\Testing\Generators\FeatureTestGenerator::class),
                $app->make(\LaravelModularDDD\Testing\Generators\IntegrationTestGenerator::class),
                $app->make(\LaravelModularDDD\Testing\Generators\FactoryGenerator::class)
            );
        });
    }

    private function registerSupport(): void
    {
        // Register Command Bus Manager
        $this->app->singleton(CommandBusManager::class, function ($app) {
            return new CommandBusManager($app);
        });

        // Register Query Bus Manager
        $this->app->singleton(QueryBusManager::class, function ($app) {
            return new QueryBusManager($app);
        });
    }

    private function registerErrorHandling(): void
    {
        // Register Error Handler Manager
        $this->app->singleton(ErrorHandlerManager::class, function ($app) {
            return new ErrorHandlerManager();
        });

        // Register Logging Error Handler
        $this->app->singleton(LoggingErrorHandler::class, function ($app) {
            return new LoggingErrorHandler();
        });

        // Register Notification Error Handler
        $this->app->singleton(NotificationErrorHandler::class, function ($app) {
            return new NotificationErrorHandler();
        });

        // Register Dead Letter Queue
        $this->app->singleton(DeadLetterQueue::class, function ($app) {
            return new DeadLetterQueue(
                $app->make('db.connection'),
                'dead_letter_queue'
            );
        });

        // Register Circuit Breaker
        $this->app->singleton(CircuitBreaker::class, function ($app) {
            $config = config('modular-ddd.cqrs.error_handling.circuit_breaker', []);
            return new CircuitBreaker(
                'default',
                $config['failure_threshold'] ?? 5,
                $config['recovery_timeout_seconds'] ?? 60,
                $config['request_volume_threshold'] ?? 10,
                $config['error_percentage_threshold'] ?? 50.0
            );
        });

        // Register Retry Policy
        $this->app->singleton(RetryPolicy::class, function ($app) {
            $config = config('modular-ddd.cqrs.error_handling.retry_policy', []);
            return new RetryPolicy(
                $config['max_attempts'] ?? 3,
                $config['delay'] ?? 100,
                $config['multiplier'] ?? 2
            );
        });
    }

    private function registerSagas(): void
    {
        // Register Saga Repository
        $this->app->singleton(SagaRepositoryInterface::class, function ($app) {
            return new DatabaseSagaRepository(
                $app->make('db.connection')
            );
        });

        // Register Saga Manager
        $this->app->singleton(SagaManager::class, function ($app) {
            return new SagaManager(
                $app->make(SagaRepositoryInterface::class),
                $app->make(CommandBusInterface::class),
                $app->make('events')
            );
        });
    }

    private function registerReadModels(): void
    {
        // Register Read Model Repository
        $this->app->singleton(ReadModelRepositoryInterface::class, function ($app) {
            return new DatabaseReadModelRepository(
                $app->make('db.connection')
            );
        });

        // Register Read Model Manager
        $this->app->singleton(ReadModelManager::class, function ($app) {
            return new ReadModelManager(
                $app->make(ReadModelRepositoryInterface::class),
                $app->make(EventStoreInterface::class),
                $app->make('events')
            );
        });
    }

    private function registerEventSourcingExtensions(): void
    {
        // Register Event Archival Manager
        $this->app->singleton(EventArchivalManager::class, function ($app) {
            $config = config('modular-ddd.event_sourcing.archival', []);
            return new EventArchivalManager(
                $app->make(EventStoreInterface::class),
                $app->make('filesystem'),
                $config
            );
        });

        // Register Event Versioning Manager
        $this->app->singleton(EventVersioningManager::class, function ($app) {
            return new EventVersioningManager();
        });

        // Register Event Deserialization Cache
        $this->app->singleton(EventDeserializationCache::class, function ($app) {
            return new EventDeserializationCache(
                config('modular-ddd.event_sourcing.cache.max_size', 1000)
            );
        });

        // Register Event Object Pool
        $this->app->singleton(EventObjectPool::class, function ($app) {
            return new EventObjectPool(
                config('modular-ddd.event_sourcing.pool.max_size', 100)
            );
        });

        // Register Aggregate Reconstructor
        $this->app->singleton(\LaravelModularDDD\EventSourcing\AggregateReconstructor::class, function ($app) {
            return new \LaravelModularDDD\EventSourcing\AggregateReconstructor(
                $app->make(EventStoreInterface::class),
                $app->make(SnapshotStoreInterface::class)
            );
        });
    }

    private function registerBatchProcessing(): void
    {
        // Register Batch Aggregate Repository
        $this->app->singleton(BatchAggregateRepository::class, function ($app) {
            return new BatchAggregateRepository(
                $app->make(EventStoreInterface::class),
                $app->make(SnapshotStoreInterface::class)
            );
        });

        // Register Batch Query Executor
        $this->app->singleton(BatchQueryExecutor::class, function ($app) {
            return new BatchQueryExecutor(
                $app->make(QueryBusInterface::class)
            );
        });

        // Register Batch Projection Loader
        $this->app->singleton(BatchProjectionLoader::class, function ($app) {
            return new BatchProjectionLoader(
                $app->make('LaravelModularDDD\EventSourcing\Projections\ProjectionManager'),
                $app->make(EventStoreInterface::class)
            );
        });
    }

    private function registerCacheManagement(): void
    {
        // Register Cache Eviction Manager
        $this->app->singleton(CacheEvictionManager::class, function ($app) {
            $config = config('modular-ddd.cqrs.cache.eviction', []);
            return new CacheEvictionManager(
                $app->make('cache'),
                $config['strategy'] ?? 'lru',
                $config['max_entries'] ?? 10000
            );
        });

        // Register Cache Invalidation Manager
        $this->app->singleton(CacheInvalidationManager::class, function ($app) {
            return new CacheInvalidationManager(
                $app->make('cache'),
                $app->make('events')
            );
        });

        // Register Memory Leak Detector
        $this->app->singleton(MemoryLeakDetector::class, function ($app) {
            return new MemoryLeakDetector(
                config('modular-ddd.performance.memory.threshold', 100 * 1024 * 1024),
                $app->make('log')
            );
        });
    }

    private function registerDocumentation(): void
    {
        // Register Documentation Generator
        $this->app->singleton(DocumentationGenerator::class, function ($app) {
            return new DocumentationGenerator(
                $app->make('files'),
                $app->make(\LaravelModularDDD\Support\ModuleRegistry::class)
            );
        });

        // Register Integration classes
        $this->app->singleton(CQRSEventStoreIntegration::class, function ($app) {
            return new CQRSEventStoreIntegration(
                $app->make(CommandBusInterface::class),
                $app->make(EventStoreInterface::class)
            );
        });

        $this->app->singleton(EventProjectionBridge::class, function ($app) {
            return new EventProjectionBridge(
                $app->make('LaravelModularDDD\EventSourcing\Projections\ProjectionManager'),
                $app->make('events')
            );
        });
    }

    public function provides(): array
    {
            return [
                EventStoreInterface::class,
                EventSequencer::class,
                SnapshotStoreInterface::class,
                SnapshotStrategyInterface::class,
                TransactionManagerInterface::class,
                CommandBusInterface::class,
                QueryBusInterface::class,
                MetricsCollectorInterface::class,
                PerformanceMetricsCollector::class,
                PerformanceMonitor::class,
                MultiTierCacheManager::class,
                CommandAuthorizationManager::class,
                AsyncStrategyInterface::class,
                AsyncStatusRepository::class,
                ProjectionEventBridge::class,
                'LaravelModularDDD\\EventSourcing\\Projections\\ProjectionManager',
                ModuleBusInterface::class,
                CommandBusManager::class,
                QueryBusManager::class,
                DocumentationGenerator::class,
                ErrorHandlerManager::class,
                LoggingErrorHandler::class,
                NotificationErrorHandler::class,
                DeadLetterQueue::class,
                CircuitBreaker::class,
                RetryPolicy::class,
                SagaRepositoryInterface::class,
                SagaManager::class,
                ReadModelRepositoryInterface::class,
                ReadModelManager::class,
                EventArchivalManager::class,
                EventVersioningManager::class,
                EventDeserializationCache::class,
                EventObjectPool::class,
                BatchAggregateRepository::class,
                BatchQueryExecutor::class,
                BatchProjectionLoader::class,
                CacheEvictionManager::class,
                CacheInvalidationManager::class,
                MemoryLeakDetector::class,
                CQRSEventStoreIntegration::class,
                EventProjectionBridge::class,
                \LaravelModularDDD\Support\ModuleDiscovery::class,
                \LaravelModularDDD\Support\ModuleRegistry::class,
                \LaravelModularDDD\Generators\StubProcessor::class,
                \LaravelModularDDD\Generators\ModuleGenerator::class,
                \LaravelModularDDD\Generators\AggregateGenerator::class,
                \LaravelModularDDD\Generators\CommandGenerator::class,
                \LaravelModularDDD\Generators\QueryGenerator::class,
                \LaravelModularDDD\Generators\RepositoryGenerator::class,
                \LaravelModularDDD\Generators\ServiceGenerator::class,
                \LaravelModularDDD\Testing\Generators\TestGenerator::class,
                \LaravelModularDDD\Testing\Generators\FactoryGenerator::class,
                \LaravelModularDDD\Testing\Generators\UnitTestGenerator::class,
                \LaravelModularDDD\Testing\Generators\FeatureTestGenerator::class,
                \LaravelModularDDD\Testing\Generators\IntegrationTestGenerator::class,
                \LaravelModularDDD\EventSourcing\AggregateReconstructor::class,
                \LaravelModularDDD\Core\Infrastructure\Repository\EventSourcedAggregateRepository::class,
        ];
    }
}