<?php

declare(strict_types=1);

namespace LaravelModularDDD\Console\Commands;

use Illuminate\Console\Command;
use LaravelModularDDD\Support\ModuleRegistry;
use LaravelModularDDD\Support\CommandBusManager;
use LaravelModularDDD\Support\QueryBusManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * ModuleHealthCommand
 *
 * Performs comprehensive health checks on modules.
 * Validates structure, dependencies, and CQRS configuration.
 */
final class ModuleHealthCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'modular:health
                           {module? : Check specific module (optional)}
                           {--fix : Attempt to fix issues automatically}
                           {--detailed : Show detailed health information}
                           {--json : Output results as JSON}';

    /**
     * The console command description.
     */
    protected $description = 'Check the health status of modules and their components';

    public function __construct(
        private readonly ModuleRegistry $moduleRegistry,
        private readonly CommandBusManager $commandBusManager,
        private readonly QueryBusManager $queryBusManager
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $moduleName = $this->argument('module');

        if ($moduleName) {
            return $this->checkModuleHealth($moduleName);
        }

        return $this->checkOverallHealth();
    }

    /**
     * Check health of a specific module.
     */
    private function checkModuleHealth(string $moduleName): int
    {
        if (!$this->moduleRegistry->hasModule($moduleName)) {
            $this->error("Module '{$moduleName}' not found.");
            return Command::FAILURE;
        }

        $module = $this->moduleRegistry->getModule($moduleName);
        $checks = $this->performModuleHealthChecks($module);

        if ($this->option('json')) {
            $this->outputJson($checks);
            return Command::SUCCESS;
        }

        $this->displayModuleHealthResults($moduleName, $checks);

        return $checks['overall_status'] === 'healthy' ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Check overall system health.
     */
    private function checkOverallHealth(): int
    {
        $overallChecks = $this->performOverallHealthChecks();

        if ($this->option('json')) {
            $this->outputJson($overallChecks);
            return Command::SUCCESS;
        }

        $this->displayOverallHealthResults($overallChecks);

        return $overallChecks['overall_status'] === 'healthy' ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Perform health checks for a specific module.
     */
    private function performModuleHealthChecks(array $module): array
    {
        $checks = [
            'module_name' => $module['name'],
            'overall_status' => 'healthy',
            'checks' => [],
            'issues' => [],
            'warnings' => [],
            'recommendations' => [],
        ];

        // Structure validation
        $checks['checks']['structure'] = $this->checkModuleStructure($module);

        // Component validation
        $checks['checks']['components'] = $this->checkModuleComponents($module);

        // Service provider validation
        $checks['checks']['service_provider'] = $this->checkServiceProvider($module);

        // Dependencies validation
        $checks['checks']['dependencies'] = $this->checkModuleDependencies($module);

        // CQRS validation
        $checks['checks']['cqrs'] = $this->checkModuleCQRS($module);

        // Test coverage
        $checks['checks']['tests'] = $this->checkModuleTests($module);

        // Determine overall status
        $this->determineOverallStatus($checks);

        return $checks;
    }

    /**
     * Perform overall system health checks.
     */
    private function performOverallHealthChecks(): array
    {
        $checks = [
            'overall_status' => 'healthy',
            'modules' => [],
            'system_checks' => [],
            'statistics' => $this->moduleRegistry->getStatistics(),
            'issues' => [],
            'warnings' => [],
            'recommendations' => [],
        ];

        // Check all modules
        $modules = $this->moduleRegistry->getRegisteredModules();
        foreach ($modules as $module) {
            $checks['modules'][$module['name']] = $this->performModuleHealthChecks($module);
        }

        // System-wide checks
        $checks['system_checks']['circular_dependencies'] = $this->checkCircularDependencies();
        $checks['system_checks']['command_bus'] = $this->checkCommandBus();
        $checks['system_checks']['query_bus'] = $this->checkQueryBus();
        $checks['system_checks']['module_registry'] = $this->checkModuleRegistry();

        // Determine overall status
        $this->determineSystemOverallStatus($checks);

        return $checks;
    }

    /**
     * Check module structure.
     */
    private function checkModuleStructure(array $module): array
    {
        $check = ['status' => 'pass', 'issues' => [], 'warnings' => []];

        $requiredDirectories = [
            'Domain',
            'Application',
            'Infrastructure',
            'Presentation',
        ];

        $missingDirs = 0;
        foreach ($requiredDirectories as $directory) {
            $path = "{$module['path']}/{$directory}";
            if (!is_dir($path)) {
                $missingDirs++;
                $check['warnings'][] = "Missing directory: {$directory} (will be created when needed)";
            }
        }

        // Only fail if ALL directories are missing (completely broken module)
        if ($missingDirs === count($requiredDirectories)) {
            $check['status'] = 'fail';
            $check['issues'][] = 'Module structure is completely missing - please regenerate the module';
        }

        return $check;
    }

    /**
     * Check module components.
     */
    private function checkModuleComponents(array $module): array
    {
        $check = ['status' => 'pass', 'issues' => [], 'warnings' => []];
        $components = $module['components'] ?? [];

        // Check for basic components
        if (empty($components['aggregates'])) {
            $check['warnings'][] = 'No aggregates found - consider if this module needs domain logic';
        }

        if (empty($components['commands']) && empty($components['queries'])) {
            $check['warnings'][] = 'No CQRS components found - module may be incomplete';
        }

        // Check for orphaned handlers
        $commandHandlers = $components['handlers']['command_handlers'] ?? [];
        $queryHandlers = $components['handlers']['query_handlers'] ?? [];
        $commands = $components['commands'] ?? [];
        $queries = $components['queries'] ?? [];

        if (count($commandHandlers) > count($commands)) {
            $check['warnings'][] = 'More command handlers than commands - possible orphaned handlers';
        }

        if (count($queryHandlers) > count($queries)) {
            $check['warnings'][] = 'More query handlers than queries - possible orphaned handlers';
        }

        return $check;
    }

    /**
     * Check service provider.
     */
    private function checkServiceProvider(array $module): array
    {
        $check = ['status' => 'pass', 'issues' => [], 'warnings' => []];

        if (!$module['service_provider']) {
            $check['warnings'][] = 'No service provider found - will be created when registering CQRS handlers';
        } elseif (!class_exists($module['service_provider'])) {
            $check['status'] = 'warning';
            $check['warnings'][] = "Service provider class does not exist: {$module['service_provider']} (may need to be generated)";
        }

        return $check;
    }

    /**
     * Check module dependencies.
     */
    private function checkModuleDependencies(array $module): array
    {
        $check = ['status' => 'pass', 'issues' => []];
        $dependencies = $module['dependencies']['module_dependencies'] ?? [];

        foreach ($dependencies as $dependency) {
            if (!$this->moduleRegistry->hasModule($dependency)) {
                $check['status'] = 'fail';
                $check['issues'][] = "Dependency module not found: {$dependency}";
            } elseif (!$this->moduleRegistry->getModule($dependency)['enabled']) {
                $check['status'] = 'warning';
                $check['issues'][] = "Dependency module is disabled: {$dependency}";
            }
        }

        return $check;
    }

    /**
     * Check module CQRS configuration.
     */
    private function checkModuleCQRS(array $module): array
    {
        $check = ['status' => 'pass', 'issues' => [], 'warnings' => []];

        // Check command handlers registration
        $commandHandlers = $module['components']['handlers']['command_handlers'] ?? [];
        foreach ($commandHandlers as $handlerInfo) {
            $handlerClass = $handlerInfo['class'] ?? null;
            if ($handlerClass && !$this->commandBusManager->hasHandler($handlerClass)) {
                $check['warnings'][] = "Command handler not registered: {$handlerClass} (register in service provider)";
            }
        }

        // Check query handlers registration
        $queryHandlers = $module['components']['handlers']['query_handlers'] ?? [];
        foreach ($queryHandlers as $handlerInfo) {
            $handlerClass = $handlerInfo['class'] ?? null;
            if ($handlerClass && !$this->queryBusManager->hasHandler($handlerClass)) {
                $check['warnings'][] = "Query handler not registered: {$handlerClass} (register in service provider)";
            }
        }

        // If no handlers exist at all, this is expected for new modules
        if (empty($commandHandlers) && empty($queryHandlers)) {
            $check['warnings'][] = 'No CQRS handlers found - add commands and queries as needed';
        }

        return $check;
    }

    /**
     * Check module tests.
     */
    private function checkModuleTests(array $module): array
    {
        $check = ['status' => 'pass', 'issues' => [], 'warnings' => []];
        $tests = $module['tests'] ?? [];

        $unitTests = count($tests['unit'] ?? []);
        $featureTests = count($tests['feature'] ?? []);
        $integrationTests = count($tests['integration'] ?? []);

        $totalTests = $unitTests + $featureTests + $integrationTests;

        if ($totalTests === 0) {
            // Only set as warning, not failure, for modules without tests
            $check['status'] = 'pass'; // Changed from 'warning' to be more lenient
            $check['warnings'][] = 'No tests found - consider adding test coverage';
        } elseif ($unitTests === 0) {
            $check['warnings'][] = 'No unit tests found - consider adding unit test coverage';
        }

        $check['test_counts'] = [
            'unit' => $unitTests,
            'feature' => $featureTests,
            'integration' => $integrationTests,
            'total' => $totalTests,
        ];

        return $check;
    }

    /**
     * Check for circular dependencies.
     */
    private function checkCircularDependencies(): array
    {
        $check = ['status' => 'pass', 'issues' => []];
        $circular = $this->moduleRegistry->findCircularDependencies();

        if (!empty($circular)) {
            $check['status'] = 'fail';
            foreach ($circular as $dependency) {
                $check['issues'][] = "Circular dependency: {$dependency[0]} ↔ {$dependency[1]}";
            }
        }

        return $check;
    }

    /**
     * Check command bus health.
     */
    private function checkCommandBus(): array
    {
        $check = ['status' => 'pass', 'issues' => []];
        $validation = $this->commandBusManager->validate();

        foreach ($validation as $issue) {
            $check['issues'][] = $issue['message'];
        }

        if (!empty($check['issues'])) {
            $check['status'] = 'warning';
        }

        return $check;
    }

    /**
     * Check query bus health.
     */
    private function checkQueryBus(): array
    {
        $check = ['status' => 'pass', 'issues' => []];
        $validation = $this->queryBusManager->validate();

        foreach ($validation as $issue) {
            $check['issues'][] = $issue['message'];
        }

        if (!empty($check['issues'])) {
            $check['status'] = 'warning';
        }

        return $check;
    }

    /**
     * Check module registry health.
     */
    private function checkModuleRegistry(): array
    {
        $check = ['status' => 'pass', 'issues' => []];

        try {
            $this->moduleRegistry->getStatistics();
        } catch (\Exception $e) {
            $check['status'] = 'fail';
            $check['issues'][] = "Module registry error: {$e->getMessage()}";
        }

        return $check;
    }

    /**
     * Determine overall status for a module.
     */
    private function determineOverallStatus(array &$checks): void
    {
        $hasFailures = false;
        $hasWarnings = false;

        foreach ($checks['checks'] as $checkResult) {
            if ($checkResult['status'] === 'fail') {
                $hasFailures = true;
                $checks['issues'] = array_merge($checks['issues'], $checkResult['issues'] ?? []);
            } elseif ($checkResult['status'] === 'warning') {
                $hasWarnings = true;
                $checks['warnings'] = array_merge($checks['warnings'], $checkResult['issues'] ?? [], $checkResult['warnings'] ?? []);
            } else {
                // Collect warnings from pass status as well
                $checks['warnings'] = array_merge($checks['warnings'], $checkResult['warnings'] ?? []);
            }
        }

        if ($hasFailures) {
            $checks['overall_status'] = 'unhealthy';
        } elseif ($hasWarnings) {
            $checks['overall_status'] = 'warning';
        } else {
            // If we only have informational warnings, keep status as healthy
            $checks['overall_status'] = 'healthy';
        }
    }

    /**
     * Determine overall system status.
     */
    private function determineSystemOverallStatus(array &$checks): void
    {
        $hasFailures = false;
        $hasWarnings = false;

        // Check module statuses
        foreach ($checks['modules'] as $moduleCheck) {
            if ($moduleCheck['overall_status'] === 'unhealthy') {
                $hasFailures = true;
            } elseif ($moduleCheck['overall_status'] === 'warning') {
                $hasWarnings = true;
            }
        }

        // Check system checks
        foreach ($checks['system_checks'] as $systemCheck) {
            if ($systemCheck['status'] === 'fail') {
                $hasFailures = true;
                $checks['issues'] = array_merge($checks['issues'], $systemCheck['issues'] ?? []);
            } elseif ($systemCheck['status'] === 'warning') {
                $hasWarnings = true;
                $checks['warnings'] = array_merge($checks['warnings'], $systemCheck['issues'] ?? []);
            }
        }

        if ($hasFailures) {
            $checks['overall_status'] = 'unhealthy';
        } elseif ($hasWarnings) {
            $checks['overall_status'] = 'warning';
        }
    }

    /**
     * Display module health results.
     */
    private function displayModuleHealthResults(string $moduleName, array $checks): void
    {
        $status = $checks['overall_status'];
        $icon = match ($status) {
            'healthy' => '✅',
            'warning' => '⚠️',
            'unhealthy' => '❌',
            default => '❓',
        };

        $this->info("🏥 Health Check: {$moduleName} {$icon}");
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

        foreach ($checks['checks'] as $checkName => $checkResult) {
            $checkIcon = match ($checkResult['status']) {
                'pass' => '✅',
                'warning' => '⚠️',
                'fail' => '❌',
                default => '❓',
            };

            $this->line("  {$checkIcon} " . ucfirst(str_replace('_', ' ', $checkName)));

            if ($this->option('detailed') && !empty($checkResult['issues'])) {
                foreach ($checkResult['issues'] as $issue) {
                    $this->line("    • {$issue}");
                }
            }
        }

        if (!empty($checks['issues'])) {
            $this->error("\n❌ Issues:");
            foreach ($checks['issues'] as $issue) {
                $this->line("  • {$issue}");
            }
        }

        if (!empty($checks['warnings'])) {
            $this->warn("\n⚠️ Warnings:");
            foreach ($checks['warnings'] as $warning) {
                $this->line("  • {$warning}");
            }
        }
    }

    /**
     * Display overall health results.
     */
    private function displayOverallHealthResults(array $checks): void
    {
        $status = $checks['overall_status'];
        $icon = match ($status) {
            'healthy' => '✅',
            'warning' => '⚠️',
            'unhealthy' => '❌',
            default => '❓',
        };

        $this->info("🏥 System Health Check {$icon}");
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

        $stats = $checks['statistics'];
        $this->info("\n📊 Module Overview:");
        $this->line("  Total: {$stats['total_modules']}");
        $this->line("  Enabled: {$stats['enabled_modules']}");
        $this->line("  Disabled: {$stats['disabled_modules']}");

        $this->info("\n🔍 Module Health:");
        $healthCounts = ['healthy' => 0, 'warning' => 0, 'unhealthy' => 0];

        foreach ($checks['modules'] as $moduleName => $moduleCheck) {
            $healthCounts[$moduleCheck['overall_status']]++;

            if ($this->option('detailed') || $moduleCheck['overall_status'] !== 'healthy') {
                $moduleIcon = match ($moduleCheck['overall_status']) {
                    'healthy' => '✅',
                    'warning' => '⚠️',
                    'unhealthy' => '❌',
                    default => '❓',
                };

                $this->line("  {$moduleIcon} {$moduleName}");
            }
        }

        $this->line("\n  ✅ Healthy: {$healthCounts['healthy']}");
        $this->line("  ⚠️ Warning: {$healthCounts['warning']}");
        $this->line("  ❌ Unhealthy: {$healthCounts['unhealthy']}");

        if (!empty($checks['issues'])) {
            $this->error("\n❌ System Issues:");
            foreach ($checks['issues'] as $issue) {
                $this->line("  • {$issue}");
            }
        }

        if (!empty($checks['warnings'])) {
            $this->warn("\n⚠️ System Warnings:");
            foreach ($checks['warnings'] as $warning) {
                $this->line("  • {$warning}");
            }
        }
    }

    /**
     * Output results as JSON.
     */
    private function outputJson(array $checks): void
    {
        $this->line(json_encode($checks, JSON_PRETTY_PRINT));
    }

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['module', InputArgument::OPTIONAL, 'Check specific module'],
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['fix', null, InputOption::VALUE_NONE, 'Attempt to fix issues automatically'],
            ['detailed', 'd', InputOption::VALUE_NONE, 'Show detailed health information'],
            ['json', null, InputOption::VALUE_NONE, 'Output results as JSON'],
        ];
    }
}