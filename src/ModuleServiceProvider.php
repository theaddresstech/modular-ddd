<?php

declare(strict_types=1);

namespace LaravelModularDDD;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use LaravelModularDDD\Console\Commands\ModuleMakeCommand;
use LaravelModularDDD\Console\Commands\AggregateGenerateCommand;
use LaravelModularDDD\Console\Commands\CommandGenerateCommand;
use LaravelModularDDD\Console\Commands\QueryGenerateCommand;
use LaravelModularDDD\Console\Commands\RepositoryGenerateCommand;
use LaravelModularDDD\Generators\ModuleGenerator;
use LaravelModularDDD\Generators\AggregateGenerator;
use LaravelModularDDD\Generators\CommandGenerator;
use LaravelModularDDD\Generators\QueryGenerator;
use LaravelModularDDD\Generators\RepositoryGenerator;
use LaravelModularDDD\Generators\ServiceGenerator;
use LaravelModularDDD\Generators\StubProcessor;
use LaravelModularDDD\Support\ModuleDiscovery;
use LaravelModularDDD\Support\ModuleRegistry;
use LaravelModularDDD\Support\CommandBusManager;
use LaravelModularDDD\Support\QueryBusManager;

/**
 * ModuleServiceProvider
 *
 * Main service provider for the Laravel DDD Modules package.
 * Registers generators, commands, and module discovery services.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    /**
     * Register package services.
     */
    public function register(): void
    {
        $this->registerConfig();
        $this->registerGenerators();
        $this->registerManagers();
        $this->registerModuleServices();
    }

    /**
     * Boot package services.
     */
    public function boot(): void
    {
        $this->bootConfig();
        $this->bootCommands();
        $this->bootModuleDiscovery();
        $this->bootEventSourcing();
    }

    /**
     * Register configuration.
     */
    private function registerConfig(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/modular-monolith.php',
            'modular-monolith'
        );
    }

    /**
     * Register generators.
     */
    private function registerGenerators(): void
    {
        $this->app->singleton(StubProcessor::class, function (Application $app) {
            return new StubProcessor(
                $app->make('files'),
                __DIR__ . '/../resources/stubs'
            );
        });

        $this->app->singleton(ModuleGenerator::class, function (Application $app) {
            return new ModuleGenerator(
                $app->make(StubProcessor::class),
                $app->make('files'),
                [
                    $app->make(AggregateGenerator::class),
                    $app->make(CommandGenerator::class),
                    $app->make(QueryGenerator::class),
                    $app->make(RepositoryGenerator::class),
                    $app->make(ServiceGenerator::class),
                ]
            );
        });

        $this->app->singleton(AggregateGenerator::class, function (Application $app) {
            return new AggregateGenerator(
                $app->make(StubProcessor::class),
                $app->make('files')
            );
        });

        $this->app->singleton(CommandGenerator::class, function (Application $app) {
            return new CommandGenerator(
                $app->make(StubProcessor::class),
                $app->make('files')
            );
        });

        $this->app->singleton(QueryGenerator::class, function (Application $app) {
            return new QueryGenerator(
                $app->make(StubProcessor::class),
                $app->make('files')
            );
        });

        $this->app->singleton(RepositoryGenerator::class, function (Application $app) {
            return new RepositoryGenerator(
                $app->make(StubProcessor::class),
                $app->make('files')
            );
        });

        $this->app->singleton(ServiceGenerator::class, function (Application $app) {
            return new ServiceGenerator(
                $app->make(StubProcessor::class),
                $app->make('files')
            );
        });
    }

    /**
     * Register managers.
     */
    private function registerManagers(): void
    {
        $this->app->singleton(CommandBusManager::class, function (Application $app) {
            return new CommandBusManager($app);
        });

        $this->app->singleton(QueryBusManager::class, function (Application $app) {
            return new QueryBusManager($app);
        });
    }

    /**
     * Register module services.
     */
    private function registerModuleServices(): void
    {
        $this->app->singleton(ModuleDiscovery::class, function (Application $app) {
            return new ModuleDiscovery(
                base_path('Modules'),
                $app->make('files')
            );
        });

        $this->app->singleton(ModuleRegistry::class, function (Application $app) {
            return new ModuleRegistry(
                $app->make(ModuleDiscovery::class),
                $app->make('cache.store')
            );
        });
    }

    /**
     * Boot configuration.
     */
    private function bootConfig(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/modular-monolith.php' => config_path('modular-monolith.php'),
            ], 'modular-monolith-config');

            $this->publishes([
                __DIR__ . '/../resources/stubs' => resource_path('stubs/modular-monolith'),
            ], 'modular-monolith-stubs');
        }
    }

    /**
     * Boot console commands.
     */
    private function bootCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ModuleMakeCommand::class,
                AggregateGenerateCommand::class,
                CommandGenerateCommand::class,
                QueryGenerateCommand::class,
                RepositoryGenerateCommand::class,
            ]);
        }
    }

    /**
     * Boot module discovery.
     */
    private function bootModuleDiscovery(): void
    {
        if (!config('modular-monolith.auto_discovery', true)) {
            return;
        }

        $registry = $this->app->make(ModuleRegistry::class);
        $modules = $registry->getRegisteredModules();

        foreach ($modules as $module) {
            $this->bootModule($module);
        }
    }

    /**
     * Boot individual module.
     */
    private function bootModule(array $module): void
    {
        $moduleName = $module['name'];
        $modulePath = $module['path'];

        // Register module service provider if it exists
        $serviceProviderClass = "Modules\\{$moduleName}\\{$moduleName}ServiceProvider";
        if (class_exists($serviceProviderClass)) {
            $this->app->register($serviceProviderClass);
        }

        // Load module routes
        $this->loadModuleRoutes($modulePath);

        // Load module migrations
        $this->loadModuleMigrations($modulePath);

        // Load module config
        $this->loadModuleConfig($moduleName, $modulePath);

        // Register module commands
        $this->registerModuleCommands($modulePath);
    }

    /**
     * Load module routes.
     */
    private function loadModuleRoutes(string $modulePath): void
    {
        $webRoutes = "{$modulePath}/Routes/web.php";
        $apiRoutes = "{$modulePath}/Routes/api.php";

        if (file_exists($webRoutes)) {
            $this->loadRoutesFrom($webRoutes);
        }

        if (file_exists($apiRoutes)) {
            $this->loadRoutesFrom($apiRoutes);
        }
    }

    /**
     * Load module migrations.
     */
    private function loadModuleMigrations(string $modulePath): void
    {
        $migrationsPath = "{$modulePath}/Database/Migrations";

        if (is_dir($migrationsPath)) {
            $this->loadMigrationsFrom($migrationsPath);
        }
    }

    /**
     * Load module configuration.
     */
    private function loadModuleConfig(string $moduleName, string $modulePath): void
    {
        $configPath = "{$modulePath}/Config/config.php";

        if (file_exists($configPath)) {
            $this->mergeConfigFrom($configPath, strtolower($moduleName));
        }
    }

    /**
     * Register module commands.
     */
    private function registerModuleCommands(string $modulePath): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $commandsPath = "{$modulePath}/Console/Commands";

        if (!is_dir($commandsPath)) {
            return;
        }

        $files = glob("{$commandsPath}/*.php");

        foreach ($files as $file) {
            $className = $this->getClassNameFromFile($file);

            if ($className && class_exists($className) && is_subclass_of($className, \Illuminate\Console\Command::class)) {
                $this->commands([$className]);
            }
        }
    }

    /**
     * Boot event sourcing support.
     */
    private function bootEventSourcing(): void
    {
        if (!config('modular-monolith.event_sourcing.enabled', true)) {
            return;
        }

        // Register event store
        $this->registerEventStore();

        // Register projectors
        $this->registerProjectors();

        // Register sagas
        $this->registerSagas();
    }

    /**
     * Register event store.
     */
    private function registerEventStore(): void
    {
        $this->app->singleton('event-store', function (Application $app) {
            $config = config('modular-monolith.event_sourcing.store');

            return new \LaravelModularDDD\EventSourcing\EventStore(
                $app->make('db')->connection($config['connection'] ?? null),
                $config
            );
        });
    }

    /**
     * Register projectors.
     */
    private function registerProjectors(): void
    {
        $this->app->singleton('projector-manager', function (Application $app) {
            return new \LaravelModularDDD\EventSourcing\ProjectorManager($app);
        });
    }

    /**
     * Register sagas.
     */
    private function registerSagas(): void
    {
        if (!config('modular-monolith.sagas.enabled', true)) {
            return;
        }

        $this->app->singleton('saga-manager', function (Application $app) {
            return new \LaravelModularDDD\Sagas\SagaManager($app);
        });
    }

    /**
     * Get class name from file path.
     */
    private function getClassNameFromFile(string $filePath): ?string
    {
        $content = file_get_contents($filePath);

        if (preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatches) &&
            preg_match('/class\s+(\w+)/', $content, $classMatches)) {
            return $namespaceMatches[1] . '\\' . $classMatches[1];
        }

        return null;
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            ModuleGenerator::class,
            AggregateGenerator::class,
            CommandGenerator::class,
            QueryGenerator::class,
            RepositoryGenerator::class,
            ServiceGenerator::class,
            StubProcessor::class,
            ModuleDiscovery::class,
            ModuleRegistry::class,
            CommandBusManager::class,
            QueryBusManager::class,
        ];
    }
}