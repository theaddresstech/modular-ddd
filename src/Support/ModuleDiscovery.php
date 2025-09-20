<?php

declare(strict_types=1);

namespace LaravelModularDDD\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;

/**
 * ModuleDiscovery
 *
 * Discovers and analyzes modules in the filesystem.
 * Provides module information and validation capabilities.
 */
final class ModuleDiscovery
{
    public function __construct(
        private readonly string $modulesPath,
        private readonly Filesystem $filesystem
    ) {}

    /**
     * Discover all modules in the modules directory.
     */
    public function discover(): Collection
    {
        if (!$this->filesystem->isDirectory($this->modulesPath)) {
            return collect();
        }

        $directories = $this->filesystem->directories($this->modulesPath);

        return collect($directories)
            ->map(fn($dir) => $this->analyzeModule($dir))
            ->filter(fn($module) => $module !== null)
            ->keyBy('name');
    }

    /**
     * Analyze a specific module directory.
     */
    public function analyzeModule(string $modulePath): ?array
    {
        $moduleName = basename($modulePath);

        if (!$this->isValidModuleName($moduleName)) {
            return null;
        }

        if (!$this->isValidModuleStructure($modulePath)) {
            return null;
        }

        return [
            'name' => $moduleName,
            'path' => $modulePath,
            'relative_path' => str_replace(base_path() . '/', '', $modulePath),
            'service_provider' => $this->findServiceProvider($modulePath, $moduleName),
            'components' => $this->analyzeComponents($modulePath),
            'dependencies' => $this->analyzeDependencies($modulePath),
            'config' => $this->findConfig($modulePath),
            'routes' => $this->findRoutes($modulePath),
            'migrations' => $this->findMigrations($modulePath),
            'tests' => $this->findTests($modulePath),
            'version' => $this->getModuleVersion($modulePath),
            'enabled' => $this->isModuleEnabled($modulePath, $moduleName),
            'last_modified' => $this->getLastModified($modulePath),
        ];
    }

    /**
     * Check if module name is valid.
     */
    public function isValidModuleName(string $name): bool
    {
        return preg_match('/^[A-Z][a-zA-Z0-9]*$/', $name) === 1;
    }

    /**
     * Check if module has valid DDD structure.
     */
    public function isValidModuleStructure(string $modulePath): bool
    {
        $requiredDirectories = [
            'Domain',
            'Application',
            'Infrastructure',
            'Presentation',
        ];

        foreach ($requiredDirectories as $directory) {
            if (!$this->filesystem->isDirectory("{$modulePath}/{$directory}")) {
                return false;
            }
        }

        return true;
    }

    /**
     * Find module service provider.
     */
    private function findServiceProvider(string $modulePath, string $moduleName): ?string
    {
        $serviceProviderPath = "{$modulePath}/{$moduleName}ServiceProvider.php";

        if ($this->filesystem->exists($serviceProviderPath)) {
            return "Modules\\{$moduleName}\\{$moduleName}ServiceProvider";
        }

        // Check alternative locations
        $alternativePaths = [
            "{$modulePath}/Providers/{$moduleName}ServiceProvider.php",
            "{$modulePath}/Providers/ModuleServiceProvider.php",
        ];

        foreach ($alternativePaths as $path) {
            if ($this->filesystem->exists($path)) {
                return $this->extractClassNameFromFile($path);
            }
        }

        return null;
    }

    /**
     * Analyze module components.
     */
    private function analyzeComponents(string $modulePath): array
    {
        return [
            'aggregates' => $this->findAggregates($modulePath),
            'commands' => $this->findCommands($modulePath),
            'queries' => $this->findQueries($modulePath),
            'events' => $this->findEvents($modulePath),
            'handlers' => $this->findHandlers($modulePath),
            'repositories' => $this->findRepositories($modulePath),
            'services' => $this->findServices($modulePath),
            'controllers' => $this->findControllers($modulePath),
            'value_objects' => $this->findValueObjects($modulePath),
            'exceptions' => $this->findExceptions($modulePath),
        ];
    }

    /**
     * Find aggregates in module.
     */
    private function findAggregates(string $modulePath): array
    {
        $aggregatesPath = "{$modulePath}/Domain/Aggregates";

        if (!$this->filesystem->isDirectory($aggregatesPath)) {
            return [];
        }

        return $this->findPhpFiles($aggregatesPath);
    }

    /**
     * Find commands in module.
     */
    private function findCommands(string $modulePath): array
    {
        $commandsPath = "{$modulePath}/Application/Commands";

        if (!$this->filesystem->isDirectory($commandsPath)) {
            return [];
        }

        return $this->findPhpFiles($commandsPath);
    }

    /**
     * Find queries in module.
     */
    private function findQueries(string $modulePath): array
    {
        $queriesPath = "{$modulePath}/Application/Queries";

        if (!$this->filesystem->isDirectory($queriesPath)) {
            return [];
        }

        return $this->findPhpFiles($queriesPath);
    }

    /**
     * Find events in module.
     */
    private function findEvents(string $modulePath): array
    {
        $eventsPath = "{$modulePath}/Domain/Events";

        if (!$this->filesystem->isDirectory($eventsPath)) {
            return [];
        }

        return $this->findPhpFiles($eventsPath);
    }

    /**
     * Find handlers in module.
     */
    private function findHandlers(string $modulePath): array
    {
        $commandHandlersPath = "{$modulePath}/Application/Commands/Handlers";
        $queryHandlersPath = "{$modulePath}/Application/Queries/Handlers";

        $handlers = [];

        if ($this->filesystem->isDirectory($commandHandlersPath)) {
            $handlers['command_handlers'] = $this->findPhpFiles($commandHandlersPath);
        }

        if ($this->filesystem->isDirectory($queryHandlersPath)) {
            $handlers['query_handlers'] = $this->findPhpFiles($queryHandlersPath);
        }

        return $handlers;
    }

    /**
     * Find repositories in module.
     */
    private function findRepositories(string $modulePath): array
    {
        $repositoriesPath = "{$modulePath}/Domain/Repositories";
        $implementationsPath = "{$modulePath}/Infrastructure/Persistence";

        $repositories = [];

        if ($this->filesystem->isDirectory($repositoriesPath)) {
            $repositories['interfaces'] = $this->findPhpFiles($repositoriesPath);
        }

        if ($this->filesystem->isDirectory($implementationsPath)) {
            $repositories['implementations'] = $this->findPhpFiles($implementationsPath);
        }

        return $repositories;
    }

    /**
     * Find services in module.
     */
    private function findServices(string $modulePath): array
    {
        $domainServicesPath = "{$modulePath}/Domain/Services";
        $applicationServicesPath = "{$modulePath}/Application/Services";

        $services = [];

        if ($this->filesystem->isDirectory($domainServicesPath)) {
            $services['domain_services'] = $this->findPhpFiles($domainServicesPath);
        }

        if ($this->filesystem->isDirectory($applicationServicesPath)) {
            $services['application_services'] = $this->findPhpFiles($applicationServicesPath);
        }

        return $services;
    }

    /**
     * Find controllers in module.
     */
    private function findControllers(string $modulePath): array
    {
        $controllersPath = "{$modulePath}/Presentation/Http/Controllers";

        if (!$this->filesystem->isDirectory($controllersPath)) {
            return [];
        }

        return $this->findPhpFiles($controllersPath);
    }

    /**
     * Find value objects in module.
     */
    private function findValueObjects(string $modulePath): array
    {
        $valueObjectsPath = "{$modulePath}/Domain/ValueObjects";

        if (!$this->filesystem->isDirectory($valueObjectsPath)) {
            return [];
        }

        return $this->findPhpFiles($valueObjectsPath);
    }

    /**
     * Find exceptions in module.
     */
    private function findExceptions(string $modulePath): array
    {
        $exceptionsPath = "{$modulePath}/Domain/Exceptions";

        if (!$this->filesystem->isDirectory($exceptionsPath)) {
            return [];
        }

        return $this->findPhpFiles($exceptionsPath);
    }

    /**
     * Analyze module dependencies.
     */
    private function analyzeDependencies(string $modulePath): array
    {
        $composerFile = "{$modulePath}/composer.json";

        if (!$this->filesystem->exists($composerFile)) {
            return [];
        }

        $composer = json_decode($this->filesystem->get($composerFile), true);

        return [
            'require' => $composer['require'] ?? [],
            'require_dev' => $composer['require-dev'] ?? [],
            'module_dependencies' => $this->extractModuleDependencies($modulePath),
        ];
    }

    /**
     * Extract dependencies on other modules.
     */
    private function extractModuleDependencies(string $modulePath): array
    {
        $dependencies = [];

        // Scan PHP files for module imports
        $phpFiles = $this->filesystem->allFiles($modulePath);

        foreach ($phpFiles as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = $this->filesystem->get($file->getPathname());

            if (preg_match_all('/use\s+Modules\\\\(\w+)\\\\/', $content, $matches)) {
                foreach ($matches[1] as $module) {
                    if (!in_array($module, $dependencies, true)) {
                        $dependencies[] = $module;
                    }
                }
            }
        }

        return $dependencies;
    }

    /**
     * Find module configuration file.
     */
    private function findConfig(string $modulePath): ?string
    {
        $configPaths = [
            "{$modulePath}/Config/config.php",
            "{$modulePath}/config.php",
        ];

        foreach ($configPaths as $path) {
            if ($this->filesystem->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Find module routes.
     */
    private function findRoutes(string $modulePath): array
    {
        $routes = [];

        $routePaths = [
            'web' => "{$modulePath}/Routes/web.php",
            'api' => "{$modulePath}/Routes/api.php",
            'console' => "{$modulePath}/Routes/console.php",
        ];

        foreach ($routePaths as $type => $path) {
            if ($this->filesystem->exists($path)) {
                $routes[$type] = $path;
            }
        }

        return $routes;
    }

    /**
     * Find module migrations.
     */
    private function findMigrations(string $modulePath): array
    {
        $migrationsPath = "{$modulePath}/Database/Migrations";

        if (!$this->filesystem->isDirectory($migrationsPath)) {
            return [];
        }

        return collect($this->filesystem->files($migrationsPath))
            ->filter(fn($file) => $file->getExtension() === 'php')
            ->map(fn($file) => $file->getPathname())
            ->toArray();
    }

    /**
     * Find module tests.
     */
    private function findTests(string $modulePath): array
    {
        $testsPath = "{$modulePath}/Tests";

        if (!$this->filesystem->isDirectory($testsPath)) {
            return [];
        }

        return [
            'unit' => $this->findPhpFiles("{$testsPath}/Unit"),
            'feature' => $this->findPhpFiles("{$testsPath}/Feature"),
            'integration' => $this->findPhpFiles("{$testsPath}/Integration"),
        ];
    }

    /**
     * Get module version.
     */
    private function getModuleVersion(string $modulePath): ?string
    {
        $composerFile = "{$modulePath}/composer.json";

        if (!$this->filesystem->exists($composerFile)) {
            return null;
        }

        $composer = json_decode($this->filesystem->get($composerFile), true);

        return $composer['version'] ?? null;
    }

    /**
     * Check if module is enabled.
     */
    private function isModuleEnabled(string $modulePath, string $moduleName): bool
    {
        // Check global config
        if (config("modular-monolith.modules.{$moduleName}.enabled") === false) {
            return false;
        }

        // Check module-specific config
        $moduleConfig = $this->findConfig($modulePath);

        if ($moduleConfig) {
            $config = include $moduleConfig;

            if (is_array($config) && isset($config['enabled'])) {
                return $config['enabled'];
            }
        }

        return true;
    }

    /**
     * Get last modified timestamp.
     */
    private function getLastModified(string $modulePath): int
    {
        $files = $this->filesystem->allFiles($modulePath);
        $lastModified = 0;

        foreach ($files as $file) {
            $modified = $file->getMTime();

            if ($modified > $lastModified) {
                $lastModified = $modified;
            }
        }

        return $lastModified;
    }

    /**
     * Find PHP files in directory.
     */
    private function findPhpFiles(string $directory): array
    {
        if (!$this->filesystem->isDirectory($directory)) {
            return [];
        }

        return collect($this->filesystem->files($directory))
            ->filter(fn($file) => $file->getExtension() === 'php')
            ->map(fn($file) => [
                'name' => $file->getBasename('.php'),
                'path' => $file->getPathname(),
                'relative_path' => str_replace(base_path() . '/', '', $file->getPathname()),
                'class' => $this->extractClassNameFromFile($file->getPathname()),
            ])
            ->toArray();
    }

    /**
     * Extract class name from PHP file.
     */
    private function extractClassNameFromFile(string $filePath): ?string
    {
        $content = $this->filesystem->get($filePath);

        if (preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatches) &&
            preg_match('/(?:class|interface|trait)\s+(\w+)/', $content, $classMatches)) {
            return $namespaceMatches[1] . '\\' . $classMatches[1];
        }

        return null;
    }

    /**
     * Get modules path.
     */
    public function getModulesPath(): string
    {
        return $this->modulesPath;
    }
}