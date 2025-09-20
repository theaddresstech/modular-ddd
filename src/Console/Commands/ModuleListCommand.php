<?php

declare(strict_types=1);

namespace LaravelModularDDD\Console\Commands;

use Illuminate\Console\Command;
use LaravelModularDDD\Support\ModuleRegistry;
use Symfony\Component\Console\Input\InputOption;

/**
 * ModuleListCommand
 *
 * Lists all discovered modules with their status and information.
 * Provides filtering and detailed view options.
 */
final class ModuleListCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'modular:list
                           {--enabled : Show only enabled modules}
                           {--disabled : Show only disabled modules}
                           {--detailed : Show detailed module information}
                           {--stats : Show module statistics}
                           {--json : Output as JSON}';

    /**
     * The console command description.
     */
    protected $description = 'List all discovered modules with their status and information';

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
        $modules = $this->getFilteredModules();

        if ($this->option('json')) {
            $this->outputJson($modules);
            return Command::SUCCESS;
        }

        if ($this->option('stats')) {
            $this->displayStatistics();
            return Command::SUCCESS;
        }

        if ($this->option('detailed')) {
            $this->displayDetailedModules($modules);
        } else {
            $this->displayModuleTable($modules);
        }

        return Command::SUCCESS;
    }

    /**
     * Get filtered modules based on options.
     */
    private function getFilteredModules()
    {
        if ($this->option('enabled')) {
            return $this->moduleRegistry->getEnabledModules();
        }

        if ($this->option('disabled')) {
            return $this->moduleRegistry->getDisabledModules();
        }

        return $this->moduleRegistry->getRegisteredModules();
    }

    /**
     * Display modules in table format.
     */
    private function displayModuleTable($modules): void
    {
        if ($modules->isEmpty()) {
            $this->warn('No modules found.');
            return;
        }

        $headers = ['Module', 'Status', 'Service Provider', 'Commands', 'Queries', 'Aggregates', 'Tests'];
        $rows = [];

        foreach ($modules as $name => $module) {
            $components = $module['components'] ?? [];

            $rows[] = [
                $name,
                $module['enabled'] ? '✅ Enabled' : '❌ Disabled',
                $module['service_provider'] ? '✓' : '❌',
                count($components['commands'] ?? []),
                count($components['queries'] ?? []),
                count($components['aggregates'] ?? []),
                $this->getTestCount($components['tests'] ?? []),
            ];
        }

        $this->table($headers, $rows);

        $this->info("\n📊 Summary:");
        $this->line("  Total modules: {$modules->count()}");
        $this->line("  Enabled: " . $modules->where('enabled', true)->count());
        $this->line("  Disabled: " . $modules->where('enabled', false)->count());
    }

    /**
     * Display detailed module information.
     */
    private function displayDetailedModules($modules): void
    {
        if ($modules->isEmpty()) {
            $this->warn('No modules found.');
            return;
        }

        foreach ($modules as $name => $module) {
            $this->displayModuleDetails($name, $module);
            $this->line('');
        }
    }

    /**
     * Display details for a single module.
     */
    private function displayModuleDetails(string $name, array $module): void
    {
        $status = $module['enabled'] ? '✅ Enabled' : '❌ Disabled';

        $this->info("📦 {$name} ({$status})");
        $this->line("   Path: {$module['relative_path']}");

        if ($module['service_provider']) {
            $this->line("   Service Provider: {$module['service_provider']}");
        }

        if ($module['version']) {
            $this->line("   Version: {$module['version']}");
        }

        $this->displayComponentCounts($module['components'] ?? []);
        $this->displayDependencies($module['dependencies'] ?? []);
        $this->displayRoutes($module['routes'] ?? []);
        $this->displayMigrations($module['migrations'] ?? []);
    }

    /**
     * Display component counts.
     */
    private function displayComponentCounts(array $components): void
    {
        $counts = [
            'Aggregates' => count($components['aggregates'] ?? []),
            'Commands' => count($components['commands'] ?? []),
            'Queries' => count($components['queries'] ?? []),
            'Events' => count($components['events'] ?? []),
            'Controllers' => count($components['controllers'] ?? []),
            'Value Objects' => count($components['value_objects'] ?? []),
        ];

        $this->line("   Components:");
        foreach ($counts as $type => $count) {
            if ($count > 0) {
                $this->line("     {$type}: {$count}");
            }
        }
    }

    /**
     * Display module dependencies.
     */
    private function displayDependencies(array $dependencies): void
    {
        $moduleDeps = $dependencies['module_dependencies'] ?? [];

        if (!empty($moduleDeps)) {
            $this->line("   Module Dependencies: " . implode(', ', $moduleDeps));
        }
    }

    /**
     * Display routes information.
     */
    private function displayRoutes(array $routes): void
    {
        if (!empty($routes)) {
            $routeTypes = array_keys($routes);
            $this->line("   Routes: " . implode(', ', $routeTypes));
        }
    }

    /**
     * Display migrations information.
     */
    private function displayMigrations(array $migrations): void
    {
        if (!empty($migrations)) {
            $this->line("   Migrations: " . count($migrations));
        }
    }

    /**
     * Get total test count.
     */
    private function getTestCount(array $tests): int
    {
        return count($tests['unit'] ?? []) +
               count($tests['feature'] ?? []) +
               count($tests['integration'] ?? []);
    }

    /**
     * Display module statistics.
     */
    private function displayStatistics(): void
    {
        $stats = $this->moduleRegistry->getStatistics();
        $componentStats = $this->moduleRegistry->getComponentStatistics();

        $this->info("📊 Module Statistics");
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

        $this->info("\n📦 Modules:");
        foreach ($stats as $key => $value) {
            $label = ucwords(str_replace('_', ' ', $key));
            $this->line("  {$label}: {$value}");
        }

        $this->info("\n🔧 Components:");
        foreach ($componentStats as $component => $count) {
            $label = ucwords(str_replace('_', ' ', $component));
            $this->line("  {$label}: {$count}");
        }

        // Dependency analysis
        $circular = $this->moduleRegistry->findCircularDependencies();
        if (!empty($circular)) {
            $this->warn("\n⚠️ Circular Dependencies Found:");
            foreach ($circular as $dependency) {
                $this->line("  {$dependency[0]} → {$dependency[1]}");
            }
        } else {
            $this->info("\n✅ No circular dependencies found");
        }

        // Health report
        $health = $this->moduleRegistry->getHealthReport();
        $this->info("\n🏥 Health Status: " . ucfirst($health['overall_health']));

        if (!empty($health['issues'])) {
            $this->warn("  Issues:");
            foreach ($health['issues'] as $issue) {
                $this->line("    • {$issue['message']}");
            }
        }

        if (!empty($health['recommendations'])) {
            $this->info("  Recommendations:");
            foreach ($health['recommendations'] as $recommendation) {
                $this->line("    • {$recommendation['message']}");
            }
        }
    }

    /**
     * Output modules as JSON.
     */
    private function outputJson($modules): void
    {
        $output = [
            'modules' => $modules->toArray(),
            'statistics' => $this->moduleRegistry->getStatistics(),
            'component_statistics' => $this->moduleRegistry->getComponentStatistics(),
            'generated_at' => now()->toISOString(),
        ];

        $this->line(json_encode($output, JSON_PRETTY_PRINT));
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['enabled', null, InputOption::VALUE_NONE, 'Show only enabled modules'],
            ['disabled', null, InputOption::VALUE_NONE, 'Show only disabled modules'],
            ['detailed', 'd', InputOption::VALUE_NONE, 'Show detailed module information'],
            ['stats', 's', InputOption::VALUE_NONE, 'Show module statistics'],
            ['json', null, InputOption::VALUE_NONE, 'Output as JSON'],
        ];
    }
}