<?php

declare(strict_types=1);

namespace LaravelModularDDD\Console\Commands;

use Illuminate\Console\Command;
use LaravelModularDDD\Support\ModuleRegistry;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * ModuleInfoCommand
 *
 * Displays detailed information about a specific module.
 * Shows structure, components, dependencies, and metrics.
 */
final class ModuleInfoCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'modular:info
                           {module : The module name to get information about}
                           {--components : Show detailed component information}
                           {--dependencies : Show dependency analysis}
                           {--files : Show file listing}
                           {--json : Output as JSON}';

    /**
     * The console command description.
     */
    protected $description = 'Show detailed information about a specific module';

    public function __construct(
        private readonly ModuleRegistry $moduleRegistry
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $moduleName = $this->argument('module');

        if (!$this->moduleRegistry->hasModule($moduleName)) {
            $this->error("Module '{$moduleName}' not found.");

            $this->suggestSimilarModules($moduleName);

            return Command::FAILURE;
        }

        $module = $this->moduleRegistry->getModule($moduleName);

        if ($this->option('json')) {
            $this->outputJson($module);
            return Command::SUCCESS;
        }

        $this->displayModuleInfo($module);

        return Command::SUCCESS;
    }

    /**
     * Display comprehensive module information.
     */
    private function displayModuleInfo(array $module): void
    {
        $this->displayBasicInfo($module);
        $this->displayComponentsSummary($module);

        if ($this->option('components')) {
            $this->displayDetailedComponents($module);
        }

        if ($this->option('dependencies')) {
            $this->displayDependencyAnalysis($module);
        }

        if ($this->option('files')) {
            $this->displayFileStructure($module);
        }

        $this->displayConfiguration($module);
        $this->displayRoutes($module);
        $this->displayMigrations($module);
        $this->displayTests($module);
    }

    /**
     * Display basic module information.
     */
    private function displayBasicInfo(array $module): void
    {
        $status = $module['enabled'] ? '✅ Enabled' : '❌ Disabled';

        $this->info("📦 Module: {$module['name']} ({$status})");
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

        $this->line("📍 Path: {$module['relative_path']}");

        if ($module['version']) {
            $this->line("🏷️ Version: {$module['version']}");
        }

        if ($module['service_provider']) {
            $this->line("⚙️ Service Provider: {$module['service_provider']}");
        } else {
            $this->warn("⚠️ No service provider found");
        }

        $lastModified = date('Y-m-d H:i:s', $module['last_modified']);
        $this->line("📅 Last Modified: {$lastModified}");
    }

    /**
     * Display components summary.
     */
    private function displayComponentsSummary(array $module): void
    {
        $components = $module['components'] ?? [];

        $this->info("\n🔧 Components Summary:");

        $summary = [
            'Aggregates' => count($components['aggregates'] ?? []),
            'Commands' => count($components['commands'] ?? []),
            'Queries' => count($components['queries'] ?? []),
            'Events' => count($components['events'] ?? []),
            'Value Objects' => count($components['value_objects'] ?? []),
            'Exceptions' => count($components['exceptions'] ?? []),
            'Controllers' => count($components['controllers'] ?? []),
        ];

        // Add handler counts
        $handlers = $components['handlers'] ?? [];
        $summary['Command Handlers'] = count($handlers['command_handlers'] ?? []);
        $summary['Query Handlers'] = count($handlers['query_handlers'] ?? []);

        // Add repository counts
        $repositories = $components['repositories'] ?? [];
        $summary['Repository Interfaces'] = count($repositories['interfaces'] ?? []);
        $summary['Repository Implementations'] = count($repositories['implementations'] ?? []);

        // Add service counts
        $services = $components['services'] ?? [];
        $summary['Domain Services'] = count($services['domain_services'] ?? []);
        $summary['Application Services'] = count($services['application_services'] ?? []);

        foreach ($summary as $type => $count) {
            $icon = $count > 0 ? '✓' : '○';
            $this->line("  {$icon} {$type}: {$count}");
        }
    }

    /**
     * Display detailed component information.
     */
    private function displayDetailedComponents(array $module): void
    {
        $components = $module['components'] ?? [];

        $this->info("\n📋 Detailed Components:");

        foreach ($components as $componentType => $componentList) {
            if (empty($componentList)) {
                continue;
            }

            $title = ucwords(str_replace('_', ' ', $componentType));
            $this->line("\n  {$title}:");

            if ($componentType === 'handlers') {
                $this->displayHandlers($componentList);
            } elseif ($componentType === 'repositories') {
                $this->displayRepositories($componentList);
            } elseif ($componentType === 'services') {
                $this->displayServices($componentList);
            } else {
                $this->displayGenericComponents($componentList);
            }
        }
    }

    /**
     * Display handlers information.
     */
    private function displayHandlers(array $handlers): void
    {
        if (!empty($handlers['command_handlers'])) {
            $this->line("    Command Handlers:");
            foreach ($handlers['command_handlers'] as $handler) {
                $this->line("      • {$handler['name']}");
            }
        }

        if (!empty($handlers['query_handlers'])) {
            $this->line("    Query Handlers:");
            foreach ($handlers['query_handlers'] as $handler) {
                $this->line("      • {$handler['name']}");
            }
        }
    }

    /**
     * Display repositories information.
     */
    private function displayRepositories(array $repositories): void
    {
        if (!empty($repositories['interfaces'])) {
            $this->line("    Interfaces:");
            foreach ($repositories['interfaces'] as $interface) {
                $this->line("      • {$interface['name']}");
            }
        }

        if (!empty($repositories['implementations'])) {
            $this->line("    Implementations:");
            foreach ($repositories['implementations'] as $implementation) {
                $this->line("      • {$implementation['name']}");
            }
        }
    }

    /**
     * Display services information.
     */
    private function displayServices(array $services): void
    {
        if (!empty($services['domain_services'])) {
            $this->line("    Domain Services:");
            foreach ($services['domain_services'] as $service) {
                $this->line("      • {$service['name']}");
            }
        }

        if (!empty($services['application_services'])) {
            $this->line("    Application Services:");
            foreach ($services['application_services'] as $service) {
                $this->line("      • {$service['name']}");
            }
        }
    }

    /**
     * Display generic components.
     */
    private function displayGenericComponents(array $components): void
    {
        foreach ($components as $component) {
            $name = $component['name'] ?? $component;
            $this->line("    • {$name}");
        }
    }

    /**
     * Display dependency analysis.
     */
    private function displayDependencyAnalysis(array $module): void
    {
        $dependencies = $module['dependencies'] ?? [];

        $this->info("\n🔗 Dependency Analysis:");

        // Module dependencies
        $moduleDeps = $dependencies['module_dependencies'] ?? [];
        if (!empty($moduleDeps)) {
            $this->line("  Module Dependencies:");
            foreach ($moduleDeps as $dep) {
                $depModule = $this->moduleRegistry->getModule($dep);
                $status = $depModule ? ($depModule['enabled'] ? '✅' : '❌') : '❓';
                $this->line("    {$status} {$dep}");
            }
        } else {
            $this->line("  ✓ No module dependencies");
        }

        // Find modules that depend on this one
        $dependents = $this->findDependentModules($module['name']);
        if (!empty($dependents)) {
            $this->line("\n  Modules that depend on this:");
            foreach ($dependents as $dependent) {
                $this->line("    • {$dependent}");
            }
        }

        // External dependencies
        $require = $dependencies['require'] ?? [];
        if (!empty($require)) {
            $this->line("\n  External Dependencies:");
            foreach ($require as $package => $version) {
                $this->line("    • {$package}: {$version}");
            }
        }
    }

    /**
     * Find modules that depend on the given module.
     */
    private function findDependentModules(string $moduleName): array
    {
        $dependents = [];
        $allModules = $this->moduleRegistry->getRegisteredModules();

        foreach ($allModules as $name => $module) {
            if ($name === $moduleName) {
                continue;
            }

            $deps = $module['dependencies']['module_dependencies'] ?? [];
            if (in_array($moduleName, $deps, true)) {
                $dependents[] = $name;
            }
        }

        return $dependents;
    }

    /**
     * Display file structure.
     */
    private function displayFileStructure(array $module): void
    {
        $this->info("\n📁 File Structure:");

        $directories = [
            'Domain' => ['Aggregates', 'ValueObjects', 'Events', 'Exceptions', 'Services', 'Repositories'],
            'Application' => ['Commands', 'Queries', 'Services'],
            'Infrastructure' => ['Persistence', 'Services'],
            'Presentation' => ['Http/Controllers', 'Http/Requests', 'Http/Resources'],
        ];

        foreach ($directories as $layer => $subdirs) {
            $this->line("  📂 {$layer}/");

            foreach ($subdirs as $subdir) {
                $path = "{$module['path']}/{$layer}/{$subdir}";
                $exists = is_dir($path);
                $icon = $exists ? '📁' : '📂';
                $count = $exists ? count(glob("{$path}/*.php")) : 0;
                $countText = $count > 0 ? " ({$count} files)" : '';

                $this->line("    {$icon} {$subdir}/{$countText}");
            }
        }
    }

    /**
     * Display configuration.
     */
    private function displayConfiguration(array $module): void
    {
        $config = $module['config'];

        $this->info("\n⚙️ Configuration:");

        if ($config) {
            $this->line("  ✓ Config file: " . basename($config));
        } else {
            $this->line("  ○ No configuration file");
        }
    }

    /**
     * Display routes information.
     */
    private function displayRoutes(array $module): void
    {
        $routes = $module['routes'] ?? [];

        $this->info("\n🛣️ Routes:");

        if (!empty($routes)) {
            foreach ($routes as $type => $path) {
                $this->line("  ✓ {$type}: " . basename($path));
            }
        } else {
            $this->line("  ○ No route files");
        }
    }

    /**
     * Display migrations information.
     */
    private function displayMigrations(array $module): void
    {
        $migrations = $module['migrations'] ?? [];

        $this->info("\n🗃️ Migrations:");

        if (!empty($migrations)) {
            $this->line("  ✓ " . count($migrations) . " migration(s) found");

            if (count($migrations) <= 5) {
                foreach ($migrations as $migration) {
                    $this->line("    • " . basename($migration));
                }
            } else {
                foreach (array_slice($migrations, 0, 3) as $migration) {
                    $this->line("    • " . basename($migration));
                }
                $remaining = count($migrations) - 3;
                $this->line("    ... and {$remaining} more");
            }
        } else {
            $this->line("  ○ No migrations");
        }
    }

    /**
     * Display tests information.
     */
    private function displayTests(array $module): void
    {
        $tests = $module['tests'] ?? [];

        $this->info("\n🧪 Tests:");

        $testTypes = ['unit', 'feature', 'integration'];
        $totalTests = 0;

        foreach ($testTypes as $type) {
            $count = count($tests[$type] ?? []);
            $totalTests += $count;
            $icon = $count > 0 ? '✓' : '○';
            $this->line("  {$icon} " . ucfirst($type) . ": {$count}");
        }

        if ($totalTests === 0) {
            $this->warn("  ⚠️ No tests found - consider adding test coverage");
        }
    }

    /**
     * Output module information as JSON.
     */
    private function outputJson(array $module): void
    {
        $this->line(json_encode($module, JSON_PRETTY_PRINT));
    }

    /**
     * Suggest similar module names.
     */
    private function suggestSimilarModules(string $moduleName): void
    {
        $allModules = $this->moduleRegistry->getRegisteredModules()->keys();
        $suggestions = [];

        foreach ($allModules as $existing) {
            $similarity = 0;
            similar_text(strtolower($moduleName), strtolower($existing), $similarity);

            if ($similarity > 60) {
                $suggestions[] = $existing;
            }
        }

        if (!empty($suggestions)) {
            $this->info("\nDid you mean:");
            foreach ($suggestions as $suggestion) {
                $this->line("  • {$suggestion}");
            }
        }

        $this->info("\nUse 'php artisan module:list' to see all available modules.");
    }

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['module', InputArgument::REQUIRED, 'The module name to get information about'],
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['components', 'c', InputOption::VALUE_NONE, 'Show detailed component information'],
            ['dependencies', 'd', InputOption::VALUE_NONE, 'Show dependency analysis'],
            ['files', 'f', InputOption::VALUE_NONE, 'Show file listing'],
            ['json', null, InputOption::VALUE_NONE, 'Output as JSON'],
        ];
    }
}