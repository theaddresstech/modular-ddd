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
        $this->app->singleton(\LaravelModularDDD\Core\Infrastructure\Repository\EventSourcedAggregateRepository::class, function ($app) {
            return new \LaravelModularDDD\Core\Infrastructure\Repository\EventSourcedAggregateRepository(
                $app->make(EventStoreInterface::class),
                $app->make(SnapshotStoreInterface::class),
                $app->make(SnapshotStrategyInterface::class)
            );
        });

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

        // Register test generators
        $this->app->singleton(\LaravelModularDDD\Testing\Generators\TestGenerator::class);
        $this->app->singleton(\LaravelModularDDD\Testing\Generators\FactoryGenerator::class);
        $this->app->singleton(\LaravelModularDDD\Testing\Generators\UnitTestGenerator::class);
        $this->app->singleton(\LaravelModularDDD\Testing\Generators\FeatureTestGenerator::class);
        $this->app->singleton(\LaravelModularDDD\Testing\Generators\IntegrationTestGenerator::class);
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
        ];
    }
}