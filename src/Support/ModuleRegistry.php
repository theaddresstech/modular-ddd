<?php

declare(strict_types=1);

namespace LaravelModularDDD\Support;

use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;

/**
 * ModuleRegistry
 *
 * Central registry for managing module information and state.
 * Provides caching and performance optimization for module discovery.
 */
final class ModuleRegistry
{
    private const CACHE_KEY = 'modular_monolith.modules';
    private const CACHE_TTL = 3600; // 1 hour

    public function __construct(
        private readonly ModuleDiscovery $discovery,
        private readonly CacheRepository $cache,
        private readonly Filesystem $filesystem
    ) {}

    /**
     * Get all registered modules.
     */
    public function getRegisteredModules(): Collection
    {
        if (config('modular-monolith.cache_modules', true)) {
            return $this->cache->remember(
                self::CACHE_KEY,
                self::CACHE_TTL,
                fn() => $this->discovery->discover()
            );
        }

        return $this->discovery->discover();
    }

    /**
     * Get a specific module by name.
     */
    public function getModule(string $name): ?array
    {
        return $this->getRegisteredModules()->get($name);
    }

    /**
     * Check if a module exists.
     */
    public function hasModule(string $name): bool
    {
        return $this->getRegisteredModules()->has($name);
    }

    /**
     * Get enabled modules only.
     */
    public function getEnabledModules(): Collection
    {
        return $this->getRegisteredModules()
            ->filter(fn($module) => $module['enabled'] === true);
    }

    /**
     * Get disabled modules only.
     */
    public function getDisabledModules(): Collection
    {
        return $this->getRegisteredModules()
            ->filter(fn($module) => $module['enabled'] === false);
    }

    /**
     * Get modules with service providers.
     */
    public function getModulesWithProviders(): Collection
    {
        return $this->getRegisteredModules()
            ->filter(fn($module) => $module['service_provider'] !== null);
    }

    /**
     * Get module statistics.
     */
    public function getStatistics(): array
    {
        $modules = $this->getRegisteredModules();

        return [
            'total_modules' => $modules->count(),
            'enabled_modules' => $modules->where('enabled', true)->count(),
            'disabled_modules' => $modules->where('enabled', false)->count(),
            'modules_with_providers' => $modules->whereNotNull('service_provider')->count(),
            'modules_with_routes' => $modules->filter(fn($m) => !empty($m['routes']))->count(),
            'modules_with_migrations' => $modules->filter(fn($m) => !empty($m['migrations']))->count(),
            'modules_with_tests' => $modules->filter(fn($m) => !empty($m['tests']['unit']) || !empty($m['tests']['feature']))->count(),
        ];
    }

    /**
     * Get component statistics across all modules.
     */
    public function getComponentStatistics(): array
    {
        $modules = $this->getRegisteredModules();
        $stats = [
            'aggregates' => 0,
            'commands' => 0,
            'queries' => 0,
            'events' => 0,
            'handlers' => 0,
            'repositories' => 0,
            'services' => 0,
            'controllers' => 0,
            'value_objects' => 0,
            'exceptions' => 0,
        ];

        foreach ($modules as $module) {
            $components = $module['components'] ?? [];

            foreach ($stats as $component => &$count) {
                if (isset($components[$component])) {
                    if (is_array($components[$component])) {
                        $count += count($components[$component]);
                    } elseif (is_array($components[$component]) && isset($components[$component]['interfaces'])) {
                        // For repositories with interfaces/implementations
                        $count += count($components[$component]['interfaces'] ?? []);
                        $count += count($components[$component]['implementations'] ?? []);
                    }
                }
            }

            // Handle nested handler counts
            if (isset($components['handlers'])) {
                $stats['handlers'] += count($components['handlers']['command_handlers'] ?? []);
                $stats['handlers'] += count($components['handlers']['query_handlers'] ?? []);
            }

            // Handle nested service counts
            if (isset($components['services'])) {
                $stats['services'] += count($components['services']['domain_services'] ?? []);
                $stats['services'] += count($components['services']['application_services'] ?? []);
            }
        }

        return $stats;
    }

    /**
     * Get module dependency graph.
     */
    public function getDependencyGraph(): array
    {
        $modules = $this->getRegisteredModules();
        $graph = [];

        foreach ($modules as $moduleName => $module) {
            $dependencies = $module['dependencies']['module_dependencies'] ?? [];
            $graph[$moduleName] = $dependencies;
        }

        return $graph;
    }

    /**
     * Find circular dependencies.
     */
    public function findCircularDependencies(): array
    {
        $graph = $this->getDependencyGraph();
        $circular = [];
        $visited = [];
        $stack = [];

        foreach (array_keys($graph) as $module) {
            if (!isset($visited[$module])) {
                $this->detectCircularDependency($module, $graph, $visited, $stack, $circular);
            }
        }

        return $circular;
    }

    /**
     * Detect circular dependency using DFS.
     */
    private function detectCircularDependency(string $module, array $graph, array &$visited, array &$stack, array &$circular): bool
    {
        $visited[$module] = true;
        $stack[$module] = true;

        $dependencies = $graph[$module] ?? [];

        foreach ($dependencies as $dependency) {
            if (!isset($visited[$dependency])) {
                if ($this->detectCircularDependency($dependency, $graph, $visited, $stack, $circular)) {
                    return true;
                }
            } elseif (isset($stack[$dependency]) && $stack[$dependency]) {
                $circular[] = [$module, $dependency];
                return true;
            }
        }

        $stack[$module] = false;
        return false;
    }

    /**
     * Get modules ordered by dependencies (topological sort).
     */
    public function getModulesInDependencyOrder(): Collection
    {
        $graph = $this->getDependencyGraph();
        $sorted = [];
        $visited = [];

        foreach (array_keys($graph) as $module) {
            if (!isset($visited[$module])) {
                $this->topologicalSort($module, $graph, $visited, $sorted);
            }
        }

        $modules = $this->getRegisteredModules();

        return collect(array_reverse($sorted))
            ->map(fn($name) => $modules->get($name))
            ->filter();
    }

    /**
     * Topological sort using DFS.
     */
    private function topologicalSort(string $module, array $graph, array &$visited, array &$sorted): void
    {
        $visited[$module] = true;

        $dependencies = $graph[$module] ?? [];

        foreach ($dependencies as $dependency) {
            if (!isset($visited[$dependency])) {
                $this->topologicalSort($dependency, $graph, $visited, $sorted);
            }
        }

        $sorted[] = $module;
    }

    /**
     * Clear module cache.
     */
    public function clearCache(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * Refresh module cache.
     */
    public function refreshCache(): Collection
    {
        $this->clearCache();
        return $this->getRegisteredModules();
    }

    /**
     * Register a new module.
     */
    public function registerModule(string $name, string $path): bool
    {
        if (!$this->discovery->isValidModuleName($name)) {
            return false;
        }

        if (!$this->discovery->isValidModuleStructure($path)) {
            return false;
        }

        $this->clearCache();
        return true;
    }

    /**
     * Unregister a module.
     */
    public function unregisterModule(string $name): bool
    {
        if (!$this->hasModule($name)) {
            return false;
        }

        $this->clearCache();
        return true;
    }

    /**
     * Enable a module.
     */
    public function enableModule(string $name): bool
    {
        if (!$this->hasModule($name)) {
            return false;
        }

        // Update runtime configuration
        config(["modular-monolith.modules.{$name}.enabled" => true]);

        // Persist to module config file
        $this->updateModuleConfig($name, ['enabled' => true]);

        $this->clearCache();
        return true;
    }

    /**
     * Disable a module.
     */
    public function disableModule(string $name): bool
    {
        if (!$this->hasModule($name)) {
            return false;
        }

        // Update runtime configuration
        config(["modular-monolith.modules.{$name}.enabled" => false]);

        // Persist to module config file
        $this->updateModuleConfig($name, ['enabled' => false]);

        $this->clearCache();
        return true;
    }

    /**
     * Update module configuration file with new settings.
     */
    private function updateModuleConfig(string $moduleName, array $updates): bool
    {
        $module = $this->getModule($moduleName);
        if (!$module) {
            return false;
        }

        $modulePath = $module['path'];
        $configPaths = [
            "{$modulePath}/Config/config.php",
            "{$modulePath}/config.php",
        ];

        // Find existing config file or create new one
        $configFile = null;
        foreach ($configPaths as $path) {
            if ($this->filesystem->exists($path)) {
                $configFile = $path;
                break;
            }
        }

        // If no config file exists, create one in Config/config.php
        if (!$configFile) {
            $configFile = "{$modulePath}/Config/config.php";
            $this->filesystem->ensureDirectoryExists(dirname($configFile));
        }

        // Load existing config or create new array
        $config = [];
        if ($this->filesystem->exists($configFile)) {
            $config = include $configFile;
            if (!is_array($config)) {
                $config = [];
            }
        }

        // Merge updates
        $config = array_merge($config, $updates);

        // Generate PHP config file content
        $content = "<?php\n\n";
        $content .= "return " . $this->arrayToPhpCode($config) . ";\n";

        // Write to file
        return $this->filesystem->put($configFile, $content) !== false;
    }

    /**
     * Convert array to PHP code representation.
     */
    private function arrayToPhpCode(array $array, int $indent = 0): string
    {
        $indentStr = str_repeat('    ', $indent);
        $code = "[\n";

        foreach ($array as $key => $value) {
            $code .= $indentStr . '    ';

            if (is_string($key)) {
                $code .= "'" . addslashes($key) . "' => ";
            }

            if (is_array($value)) {
                $code .= $this->arrayToPhpCode($value, $indent + 1);
            } elseif (is_bool($value)) {
                $code .= $value ? 'true' : 'false';
            } elseif (is_null($value)) {
                $code .= 'null';
            } elseif (is_string($value)) {
                $code .= "'" . addslashes($value) . "'";
            } else {
                $code .= $value;
            }

            $code .= ",\n";
        }

        $code .= $indentStr . ']';
        return $code;
    }

    /**
     * Get modules that need updates.
     */
    public function getModulesNeedingUpdates(): Collection
    {
        $modules = $this->getRegisteredModules();

        return $modules->filter(function ($module) {
            // Check if module has newer version available
            $currentVersion = $module['version'];
            $latestVersion = $this->getLatestVersion($module['name']);

            return $currentVersion && $latestVersion && version_compare($currentVersion, $latestVersion, '<');
        });
    }

    /**
     * Get latest version for a module (placeholder implementation).
     */
    private function getLatestVersion(string $moduleName): ?string
    {
        // In a real implementation, this might check a package registry
        // or version control system for the latest version
        return null;
    }

    /**
     * Export module registry data.
     */
    public function export(): array
    {
        return [
            'modules' => $this->getRegisteredModules()->toArray(),
            'statistics' => $this->getStatistics(),
            'component_statistics' => $this->getComponentStatistics(),
            'dependency_graph' => $this->getDependencyGraph(),
            'circular_dependencies' => $this->findCircularDependencies(),
            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * Generate module health report.
     */
    public function getHealthReport(): array
    {
        $modules = $this->getRegisteredModules();
        $report = [
            'overall_health' => 'good',
            'issues' => [],
            'recommendations' => [],
        ];

        // Check for circular dependencies
        $circular = $this->findCircularDependencies();
        if (!empty($circular)) {
            $report['overall_health'] = 'warning';
            $report['issues'][] = [
                'type' => 'circular_dependencies',
                'message' => 'Circular dependencies detected',
                'details' => $circular,
            ];
        }

        // Check for modules without service providers
        $withoutProviders = $modules->filter(fn($m) => $m['service_provider'] === null);
        if ($withoutProviders->isNotEmpty()) {
            $report['recommendations'][] = [
                'type' => 'missing_service_providers',
                'message' => 'Some modules are missing service providers',
                'modules' => $withoutProviders->keys()->toArray(),
            ];
        }

        // Check for modules without tests
        $withoutTests = $modules->filter(function ($module) {
            $tests = $module['tests'] ?? [];
            return empty($tests['unit']) && empty($tests['feature']);
        });

        if ($withoutTests->isNotEmpty()) {
            $report['recommendations'][] = [
                'type' => 'missing_tests',
                'message' => 'Some modules are missing tests',
                'modules' => $withoutTests->keys()->toArray(),
            ];
        }

        return $report;
    }
}