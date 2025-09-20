<?php

declare(strict_types=1);

namespace LaravelModularDDD\Generators;

use LaravelModularDDD\Generators\Contracts\GeneratorInterface;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Module Generator Agent
 *
 * Responsible for generating complete DDD modules with all components.
 * Implements full code generation without placeholders or incomplete methods.
 */
final class ModuleGenerator implements GeneratorInterface
{
    private Filesystem $filesystem;
    private StubProcessor $stubProcessor;
    private array $generators;

    public function __construct(Filesystem $filesystem, StubProcessor $stubProcessor)
    {
        $this->filesystem = $filesystem;
        $this->stubProcessor = $stubProcessor;
        $this->initializeGenerators();
    }

    public function generate(string $moduleName, string $componentName, array $options = []): array
    {
        // For module generation, componentName is not used - moduleName is the component
        return $this->generateModule($moduleName, $options);
    }

    public function validate(string $moduleName, string $componentName, array $options = []): bool
    {
        if (empty($moduleName) || !preg_match('/^[A-Z][a-zA-Z0-9]*$/', $moduleName)) {
            return false;
        }

        $modulePath = $this->getModulePath($moduleName);
        if ($this->filesystem->exists($modulePath) && !($options['force'] ?? false)) {
            throw new InvalidArgumentException("Module {$moduleName} already exists. Use --force to overwrite.");
        }

        return true;
    }

    public function getName(): string
    {
        return 'module';
    }

    public function getSupportedOptions(): array
    {
        return [
            'aggregate' => 'Name of the main aggregate (defaults to module name)',
            'generate_api' => 'Generate API endpoints',
            'generate_web' => 'Generate web routes and views',
            'generate_tests' => 'Generate test files',
            'generate_migration' => 'Generate migration files',
            'generate_factory' => 'Generate factory files',
            'generate_seeder' => 'Generate seeder files',
            'force' => 'Overwrite existing module',
            'dry_run' => 'Show what would be generated without creating files',
        ];
    }

    /**
     * Get list of files that would be generated (for dry-run)
     */
    public function getFilesToGenerate(string $moduleName, string $componentName, array $options = []): array
    {
        // For dry-run, we simulate the file generation without actually creating files
        $files = [];
        $modulePath = $this->getModulePath($moduleName);
        $aggregateName = $options['aggregate'] ?? $moduleName;

        // Core module files
        $files[] = $modulePath . '/manifest.json';
        $files[] = $modulePath . "/Domain/Models/{$aggregateName}.php";
        $files[] = $modulePath . "/Domain/ValueObjects/{$aggregateName}Id.php";

        // Domain events
        $events = ["{$aggregateName}Created", "{$aggregateName}Updated", "{$aggregateName}Deleted"];
        foreach ($events as $event) {
            $files[] = $modulePath . "/Domain/Events/{$event}.php";
        }

        // CQRS commands and queries
        $commands = ['Create', 'Update', 'Delete'];
        foreach ($commands as $action) {
            $commandName = "{$action}{$aggregateName}";
            $files[] = $modulePath . "/Application/Commands/{$commandName}/{$commandName}Command.php";
            $files[] = $modulePath . "/Application/Commands/{$commandName}/{$commandName}Handler.php";
        }

        $queries = ['Get', 'List'];
        foreach ($queries as $action) {
            $queryName = "{$action}{$aggregateName}";
            $files[] = $modulePath . "/Application/Queries/{$queryName}/{$queryName}Query.php";
            $files[] = $modulePath . "/Application/Queries/{$queryName}/{$queryName}Handler.php";
        }

        // Repository
        $files[] = $modulePath . "/Domain/Repositories/{$aggregateName}RepositoryInterface.php";
        $files[] = $modulePath . "/Infrastructure/Persistence/Eloquent/Repositories/{$aggregateName}Repository.php";

        // Read models and projections
        $files[] = $modulePath . "/Infrastructure/ReadModels/{$aggregateName}ReadModel.php";
        $files[] = $modulePath . "/Infrastructure/Projections/{$aggregateName}Projector.php";

        // Service provider
        $files[] = $modulePath . "/Providers/{$moduleName}ServiceProvider.php";

        // Configuration
        $files[] = $modulePath . '/Config/config.php';

        // Factories and seeders
        $files[] = $modulePath . "/Database/Factories/{$aggregateName}Factory.php";
        $files[] = $modulePath . "/Database/Seeders/{$moduleName}DatabaseSeeder.php";

        // Controller and related classes if API/Web is enabled
        if (($options['generate_api'] ?? true) || ($options['generate_web'] ?? true)) {
            $files[] = $modulePath . "/Presentation/Http/Controllers/{$aggregateName}Controller.php";
            $files[] = $modulePath . "/Presentation/Http/Requests/Store{$aggregateName}Request.php";
            $files[] = $modulePath . "/Presentation/Http/Requests/Update{$aggregateName}Request.php";
            $files[] = $modulePath . "/Presentation/Http/Resources/{$aggregateName}Resource.php";
        }

        // Routes
        if ($options['generate_api'] ?? true) {
            $files[] = $modulePath . '/Routes/api.php';
        }
        if ($options['generate_web'] ?? true) {
            $files[] = $modulePath . '/Routes/web.php';
        }

        // Tests if enabled
        if ($options['generate_tests'] ?? true) {
            $files[] = $modulePath . "/Tests/Unit/Domain/{$aggregateName}Test.php";
            foreach ($commands as $action) {
                $files[] = $modulePath . "/Tests/Feature/Application/{$action}{$aggregateName}CommandTest.php";
            }
            $files[] = $modulePath . "/Tests/Integration/Infrastructure/{$aggregateName}RepositoryTest.php";
        }

        return $files;
    }

    /**
     * Generate complete module structure
     */
    private function generateModule(string $moduleName, array $options): array
    {
        $this->validate($moduleName, '', $options);

        $modulePath = $this->getModulePath($moduleName);
        $createdFiles = [];

        // Create module directory structure
        $this->createDirectoryStructure($modulePath);

        // Generate manifest.json
        $createdFiles[] = $this->generateManifest($moduleName, $modulePath, $options);

        // Generate main aggregate
        $aggregateName = $options['aggregate'] ?? $moduleName;
        $createdFiles = array_merge(
            $createdFiles,
            $this->generateAggregate($moduleName, $aggregateName, $options)
        );

        // Generate value objects
        $createdFiles = array_merge(
            $createdFiles,
            $this->generateValueObjects($moduleName, $aggregateName, $options)
        );

        // Generate domain events
        $createdFiles = array_merge(
            $createdFiles,
            $this->generateDomainEvents($moduleName, $aggregateName, $options)
        );

        // Generate basic CQRS components
        $createdFiles = array_merge(
            $createdFiles,
            $this->generateBasicCQRS($moduleName, $aggregateName, $options)
        );

        // Generate repository
        $createdFiles = array_merge(
            $createdFiles,
            $this->generateRepository($moduleName, $aggregateName, $options)
        );

        // Generate read models and projections
        $createdFiles = array_merge(
            $createdFiles,
            $this->generateReadModels($moduleName, $aggregateName, $options)
        );

        // Generate service provider
        $createdFiles[] = $this->generateServiceProvider($moduleName, $options);

        // Generate controller if requested
        if (($options['generate_api'] ?? true) || ($options['generate_web'] ?? true)) {
            $createdFiles = array_merge(
                $createdFiles,
                $this->generateController($moduleName, $aggregateName, $options)
            );
        }

        // Generate tests if not skipped
        if ($options['generate_tests'] ?? true) {
            $createdFiles = array_merge(
                $createdFiles,
                $this->generateTests($moduleName, $aggregateName, $options)
            );
        }

        // Generate routes
        $createdFiles = array_merge(
            $createdFiles,
            $this->generateRoutes($moduleName, $options)
        );

        // Generate configuration
        $createdFiles[] = $this->generateModuleConfig($moduleName, $options);

        // Generate factories and seeders
        $createdFiles = array_merge(
            $createdFiles,
            $this->generateFactoriesAndSeeders($moduleName, $aggregateName, $options)
        );

        return $createdFiles;
    }

    private function initializeGenerators(): void
    {
        $this->generators = [
            'aggregate' => new AggregateGenerator($this->filesystem, $this->stubProcessor),
            'command' => new CommandGenerator($this->filesystem, $this->stubProcessor),
            'query' => new QueryGenerator($this->filesystem, $this->stubProcessor),
            'repository' => new RepositoryGenerator($this->filesystem, $this->stubProcessor),
            'service' => new ServiceGenerator($this->filesystem, $this->stubProcessor),
        ];
    }

    private function getModulePath(string $moduleName): string
    {
        return config('modular-ddd.modules_path', base_path('modules')) . '/' . $moduleName;
    }

    private function createDirectoryStructure(string $modulePath): void
    {
        $directories = [
            'Domain/Models',
            'Domain/Entities',
            'Domain/ValueObjects',
            'Domain/Events',
            'Domain/Services',
            'Domain/Repositories',
            'Domain/Specifications',
            'Domain/Exceptions',
            'Application/Commands',
            'Application/Queries',
            'Application/DTOs',
            'Application/Services',
            'Application/Sagas',
            'Infrastructure/Persistence/Eloquent/Models',
            'Infrastructure/Persistence/Eloquent/Repositories',
            'Infrastructure/Persistence/EventStore',
            'Infrastructure/ReadModels',
            'Infrastructure/Projections',
            'Infrastructure/Cache',
            'Infrastructure/External',
            'Infrastructure/Messaging',
            'Presentation/Http/Controllers',
            'Presentation/Http/Requests',
            'Presentation/Http/Resources',
            'Presentation/Http/Middleware',
            'Presentation/Console/Commands',
            'Presentation/Broadcasting',
            'Database/Migrations',
            'Database/Seeders',
            'Database/Factories',
            'Routes',
            'Tests/Unit/Domain',
            'Tests/Feature/Application',
            'Tests/Integration/Infrastructure',
            'Resources/views',
            'Resources/lang',
            'Resources/assets',
            'Config',
            'Providers',
        ];

        foreach ($directories as $directory) {
            $this->filesystem->ensureDirectoryExists($modulePath . '/' . $directory);
        }
    }

    private function generateManifest(string $moduleName, string $modulePath, array $options): string
    {
        $aggregateName = $options['aggregate'] ?? $moduleName;

        $manifestData = [
            'name' => $moduleName,
            'version' => '1.0.0',
            'description' => "Domain module for {$moduleName}",
            'author' => 'Generated by Laravel Modular DDD',
            'dependencies' => [],
            'provides' => [
                'aggregates' => [$aggregateName],
                'commands' => [
                    "Create{$aggregateName}",
                    "Update{$aggregateName}",
                    "Delete{$aggregateName}",
                ],
                'queries' => [
                    "Get{$aggregateName}",
                    "List{$aggregateName}",
                ],
                'events' => [
                    "{$aggregateName}Created",
                    "{$aggregateName}Updated",
                    "{$aggregateName}Deleted",
                ],
            ],
            'autoload' => [
                'psr-4' => [
                    "Modules\\{$moduleName}\\" => './',
                ],
            ],
            'config' => [
                'enabled' => true,
                'priority' => 100,
                'auto_discovery' => true,
            ],
            'routes' => [
                'api' => $options['generate_api'] ?? true,
                'web' => $options['generate_web'] ?? true,
            ],
            'migrations' => true,
            'tests' => $options['generate_tests'] ?? true,
        ];

        $manifestPath = $modulePath . '/manifest.json';
        $this->filesystem->put($manifestPath, json_encode($manifestData, JSON_PRETTY_PRINT));

        return $manifestPath;
    }

    private function generateAggregate(string $moduleName, string $aggregateName, array $options): array
    {
        return $this->generators['aggregate']->generate($moduleName, $aggregateName, $options);
    }

    private function generateValueObjects(string $moduleName, string $aggregateName, array $options): array
    {
        $createdFiles = [];
        $modulePath = $this->getModulePath($moduleName);

        // Generate ID value object
        $idClassName = "{$aggregateName}Id";
        $content = $this->stubProcessor->process('value-object-id', [
            'namespace' => "Modules\\{$moduleName}\\Domain\\ValueObjects",
            'class' => $idClassName,
            'aggregate' => $aggregateName,
        ]);

        $path = $modulePath . "/Domain/ValueObjects/{$idClassName}.php";
        $this->filesystem->put($path, $content);
        $createdFiles[] = $path;

        return $createdFiles;
    }

    private function generateDomainEvents(string $moduleName, string $aggregateName, array $options): array
    {
        $createdFiles = [];
        $modulePath = $this->getModulePath($moduleName);

        $events = [
            "{$aggregateName}Created",
            "{$aggregateName}Updated",
            "{$aggregateName}Deleted",
        ];

        foreach ($events as $eventName) {
            $content = $this->stubProcessor->process('domain-event', [
                'namespace' => "Modules\\{$moduleName}\\Domain\\Events",
                'class' => $eventName,
                'aggregate' => $aggregateName,
                'aggregate_lower' => Str::lower($aggregateName),
            ]);

            $path = $modulePath . "/Domain/Events/{$eventName}.php";
            $this->filesystem->put($path, $content);
            $createdFiles[] = $path;
        }

        return $createdFiles;
    }

    private function generateBasicCQRS(string $moduleName, string $aggregateName, array $options): array
    {
        $createdFiles = [];

        // Generate CRUD commands
        $commands = ['Create', 'Update', 'Delete'];
        foreach ($commands as $action) {
            $commandName = "{$action}{$aggregateName}";
            $createdFiles = array_merge(
                $createdFiles,
                $this->generators['command']->generate($moduleName, $commandName, array_merge($options, [
                    'aggregate' => $aggregateName,
                    'action' => strtolower($action),
                ]))
            );
        }

        // Generate queries
        $queries = ['Get', 'List'];
        foreach ($queries as $action) {
            $queryName = "{$action}{$aggregateName}";
            $createdFiles = array_merge(
                $createdFiles,
                $this->generators['query']->generate($moduleName, $queryName, array_merge($options, [
                    'aggregate' => $aggregateName,
                    'action' => strtolower($action),
                ]))
            );
        }

        return $createdFiles;
    }

    private function generateRepository(string $moduleName, string $aggregateName, array $options): array
    {
        return $this->generators['repository']->generate($moduleName, $aggregateName, $options);
    }

    private function generateReadModels(string $moduleName, string $aggregateName, array $options): array
    {
        $createdFiles = [];
        $modulePath = $this->getModulePath($moduleName);

        // Generate read model
        $readModelContent = $this->stubProcessor->process('read-model', [
            'namespace' => "Modules\\{$moduleName}\\Infrastructure\\ReadModels",
            'class' => "{$aggregateName}ReadModel",
            'aggregate' => $aggregateName,
            'aggregate_lower' => Str::lower($aggregateName),
            'table' => Str::snake(Str::plural($aggregateName)),
        ]);

        $readModelPath = $modulePath . "/Infrastructure/ReadModels/{$aggregateName}ReadModel.php";
        $this->filesystem->put($readModelPath, $readModelContent);
        $createdFiles[] = $readModelPath;

        // Generate projector
        $projectorContent = $this->stubProcessor->process('projector', [
            'namespace' => "Modules\\{$moduleName}\\Infrastructure\\Projections",
            'class' => "{$aggregateName}Projector",
            'aggregate' => $aggregateName,
            'aggregate_lower' => Str::lower($aggregateName),
            'module' => $moduleName,
            'module_lower' => Str::lower($moduleName),
        ]);

        $projectorPath = $modulePath . "/Infrastructure/Projections/{$aggregateName}Projector.php";
        $this->filesystem->put($projectorPath, $projectorContent);
        $createdFiles[] = $projectorPath;

        return $createdFiles;
    }

    private function generateServiceProvider(string $moduleName, array $options): string
    {
        $namespace = "Modules\\{$moduleName}\\Providers";
        $className = "{$moduleName}ServiceProvider";
        $aggregateName = $options['aggregate'] ?? $moduleName;

        $content = $this->stubProcessor->process('service-provider', [
            'namespace' => $namespace,
            'class' => $className,
            'module' => $moduleName,
            'module_lower' => Str::lower($moduleName),
            'aggregate' => $aggregateName,
            'with_api' => $options['generate_api'] ?? true,
            'with_web' => $options['generate_web'] ?? true,
        ]);

        $path = $this->getModulePath($moduleName) . "/Providers/{$className}.php";
        $this->filesystem->put($path, $content);

        return $path;
    }

    private function generateController(string $moduleName, string $aggregateName, array $options): array
    {
        $createdFiles = [];
        $namespace = "Modules\\{$moduleName}\\Presentation\\Http\\Controllers";
        $className = "{$aggregateName}Controller";

        $content = $this->stubProcessor->process('controller', [
            'namespace' => $namespace,
            'class' => $className,
            'aggregate' => $aggregateName,
            'aggregate_lower' => Str::lower($aggregateName),
            'module' => $moduleName,
            'with_api' => $options['generate_api'] ?? true,
            'with_web' => $options['generate_web'] ?? true,
        ]);

        $path = $this->getModulePath($moduleName) . "/Presentation/Http/Controllers/{$className}.php";
        $this->filesystem->put($path, $content);
        $createdFiles[] = $path;

        // Generate request classes
        $requests = ['Store', 'Update'];
        foreach ($requests as $requestType) {
            $requestClassName = "{$requestType}{$aggregateName}Request";
            $requestContent = $this->stubProcessor->process('form-request', [
                'namespace' => "Modules\\{$moduleName}\\Presentation\\Http\\Requests",
                'class' => $requestClassName,
                'aggregate' => $aggregateName,
                'aggregate_lower' => Str::lower($aggregateName),
                'module' => $moduleName,
                'module_lower' => Str::lower($moduleName),
                'type' => strtolower($requestType),
            ]);

            $requestPath = $this->getModulePath($moduleName) . "/Presentation/Http/Requests/{$requestClassName}.php";
            $this->filesystem->put($requestPath, $requestContent);
            $createdFiles[] = $requestPath;
        }

        // Generate resource class
        $resourceClassName = "{$aggregateName}Resource";
        $resourceContent = $this->stubProcessor->process('api-resource', [
            'namespace' => "Modules\\{$moduleName}\\Presentation\\Http\\Resources",
            'class' => $resourceClassName,
            'aggregate' => $aggregateName,
            'aggregate_lower' => Str::lower($aggregateName),
            'module' => $moduleName,
            'module_lower' => Str::lower($moduleName),
        ]);

        $resourcePath = $this->getModulePath($moduleName) . "/Presentation/Http/Resources/{$resourceClassName}.php";
        $this->filesystem->put($resourcePath, $resourceContent);
        $createdFiles[] = $resourcePath;

        return $createdFiles;
    }

    private function generateTests(string $moduleName, string $aggregateName, array $options): array
    {
        $createdFiles = [];
        $modulePath = $this->getModulePath($moduleName);

        // Unit test for aggregate
        $unitTestContent = $this->stubProcessor->process('test-unit-aggregate', [
            'namespace' => "Modules\\{$moduleName}\\Tests\\Unit\\Domain",
            'class' => "{$aggregateName}Test",
            'aggregate' => $aggregateName,
            'aggregate_lower' => Str::lower($aggregateName),
            'module' => $moduleName,
            'module_lower' => Str::lower($moduleName),
        ]);

        $unitTestPath = $modulePath . "/Tests/Unit/Domain/{$aggregateName}Test.php";
        $this->filesystem->put($unitTestPath, $unitTestContent);
        $createdFiles[] = $unitTestPath;

        // Feature tests for commands
        $commands = ['Create', 'Update', 'Delete'];
        foreach ($commands as $action) {
            $featureTestContent = $this->stubProcessor->process('test-feature-command', [
                'namespace' => "Modules\\{$moduleName}\\Tests\\Feature\\Application",
                'class' => "{$action}{$aggregateName}CommandTest",
                'aggregate' => $aggregateName,
                'aggregate_lower' => Str::lower($aggregateName),
                'module' => $moduleName,
                'module_lower' => Str::lower($moduleName),
                'action' => $action,
            ]);

            $featureTestPath = $modulePath . "/Tests/Feature/Application/{$action}{$aggregateName}CommandTest.php";
            $this->filesystem->put($featureTestPath, $featureTestContent);
            $createdFiles[] = $featureTestPath;
        }

        // Integration test for repository
        $integrationTestContent = $this->stubProcessor->process('test-integration-repository', [
            'namespace' => "Modules\\{$moduleName}\\Tests\\Integration\\Infrastructure",
            'class' => "{$aggregateName}RepositoryTest",
            'aggregate' => $aggregateName,
            'aggregate_lower' => Str::lower($aggregateName),
            'module' => $moduleName,
            'module_lower' => Str::lower($moduleName),
        ]);

        $integrationTestPath = $modulePath . "/Tests/Integration/Infrastructure/{$aggregateName}RepositoryTest.php";
        $this->filesystem->put($integrationTestPath, $integrationTestContent);
        $createdFiles[] = $integrationTestPath;

        return $createdFiles;
    }

    private function generateRoutes(string $moduleName, array $options): array
    {
        $createdFiles = [];
        $modulePath = $this->getModulePath($moduleName);
        $aggregateName = $options['aggregate'] ?? $moduleName;

        // API routes
        if ($options['generate_api'] ?? true) {
            $apiRoutesContent = $this->stubProcessor->process('routes-api', [
                'module' => $moduleName,
                'module_lower' => Str::lower($moduleName),
                'aggregate' => $aggregateName,
                'aggregate_lower' => Str::lower($aggregateName),
            ]);

            $apiRoutesPath = $modulePath . '/Routes/api.php';
            $this->filesystem->put($apiRoutesPath, $apiRoutesContent);
            $createdFiles[] = $apiRoutesPath;
        }

        // Web routes
        if ($options['generate_web'] ?? true) {
            $webRoutesContent = $this->stubProcessor->process('routes-web', [
                'module' => $moduleName,
                'module_lower' => Str::lower($moduleName),
                'aggregate' => $aggregateName,
                'aggregate_lower' => Str::lower($aggregateName),
            ]);

            $webRoutesPath = $modulePath . '/Routes/web.php';
            $this->filesystem->put($webRoutesPath, $webRoutesContent);
            $createdFiles[] = $webRoutesPath;
        }

        return $createdFiles;
    }

    private function generateModuleConfig(string $moduleName, array $options): string
    {
        $configContent = $this->stubProcessor->process('module-config', [
            'moduleName' => $moduleName,
            'module_lower' => Str::lower($moduleName),
            'namespace' => 'Modules\\' . $moduleName,
            'aggregateName' => $options['aggregate'] ?? $moduleName,
        ]);

        $configPath = $this->getModulePath($moduleName) . '/Config/config.php';
        $this->filesystem->put($configPath, $configContent);

        return $configPath;
    }

    private function generateFactoriesAndSeeders(string $moduleName, string $aggregateName, array $options): array
    {
        $createdFiles = [];
        $modulePath = $this->getModulePath($moduleName);

        // Generate factory
        $factoryContent = $this->stubProcessor->process('factory', [
            'namespace' => "Modules\\{$moduleName}\\Database\\Factories",
            'class' => "{$aggregateName}Factory",
            'aggregate' => $aggregateName,
            'aggregate_lower' => Str::lower($aggregateName),
            'module' => $moduleName,
            'module_lower' => Str::lower($moduleName),
        ]);

        $factoryPath = $modulePath . "/Database/Factories/{$aggregateName}Factory.php";
        $this->filesystem->put($factoryPath, $factoryContent);
        $createdFiles[] = $factoryPath;

        // Generate seeder
        $seederContent = $this->stubProcessor->process('seeder', [
            'namespace' => "Modules\\{$moduleName}\\Database\\Seeders",
            'class' => "{$moduleName}DatabaseSeeder",
            'aggregate' => $aggregateName,
            'aggregate_lower' => Str::lower($aggregateName),
            'module' => $moduleName,
            'module_lower' => Str::lower($moduleName),
        ]);

        $seederPath = $modulePath . "/Database/Seeders/{$moduleName}DatabaseSeeder.php";
        $this->filesystem->put($seederPath, $seederContent);
        $createdFiles[] = $seederPath;

        return $createdFiles;
    }
}