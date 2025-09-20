<?php

declare(strict_types=1);

namespace LaravelModularDDD\Console\Commands;

use Illuminate\Console\Command;
use LaravelModularDDD\Support\ModuleRegistry;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * ModuleDisableCommand
 *
 * Disables one or more modules in the application.
 * Handles dependent module checking and validation.
 */
final class ModuleDisableCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'modular:disable
                           {modules* : The module names to disable}
                           {--with-dependents : Also disable modules that depend on these}
                           {--force : Force disable even if other modules depend on them}
                           {--dry-run : Show what would be disabled without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Disable one or more modules';

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
        try {
            $moduleNames = $this->argument('modules');

            if (empty($moduleNames)) {
                $this->error('No modules specified.');
                return Command::FAILURE;
            }

            if ($this->option('dry-run')) {
                $this->performDryRun($moduleNames);
                return Command::SUCCESS;
            }

            $this->validateModules($moduleNames);

            if ($this->option('with-dependents')) {
                $moduleNames = $this->includeDependents($moduleNames);
            } elseif (!$this->option('force')) {
                $this->checkForDependents($moduleNames);
            }

            $results = $this->disableModules($moduleNames);
            $this->displayResults($results);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Failed to disable modules: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    /**
     * Perform dry run showing what would be disabled.
     */
    private function performDryRun(array $moduleNames): void
    {
        $this->warn("🔍 DRY RUN - No modules will be disabled");

        $this->info("\n📦 Modules that would be disabled:");
        foreach ($moduleNames as $moduleName) {
            $module = $this->moduleRegistry->getModule($moduleName);

            if (!$module) {
                $this->line("  ❌ {$moduleName} (not found)");
                continue;
            }

            $status = $module['enabled'] ? 'would be disabled' : 'already disabled';
            $this->line("  ✓ {$moduleName} ({$status})");
        }

        if ($this->option('with-dependents')) {
            $this->info("\n🔗 Dependents that would be disabled:");
            $withDependents = $this->includeDependents($moduleNames, true);
            $dependents = array_diff($withDependents, $moduleNames);

            foreach ($dependents as $dependent) {
                $this->line("  ➜ {$dependent} (dependent)");
            }
        } else {
            $this->checkForDependents($moduleNames, true);
        }
    }

    /**
     * Validate that all specified modules exist.
     */
    private function validateModules(array $moduleNames): void
    {
        $errors = [];

        foreach ($moduleNames as $moduleName) {
            if (!$this->moduleRegistry->hasModule($moduleName)) {
                $errors[] = "Module '{$moduleName}' does not exist";
            }
        }

        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode("\n", $errors));
        }
    }

    /**
     * Include modules that depend on the specified modules.
     */
    private function includeDependents(array $moduleNames, bool $dryRun = false): array
    {
        $allModules = $this->moduleRegistry->getRegisteredModules();
        $dependencyGraph = $this->moduleRegistry->getDependencyGraph();
        $toDisable = $moduleNames;

        do {
            $newDependents = [];

            foreach ($allModules as $moduleName => $module) {
                if (in_array($moduleName, $toDisable, true)) {
                    continue;
                }

                $dependencies = $dependencyGraph[$moduleName] ?? [];

                if (array_intersect($dependencies, $toDisable)) {
                    $newDependents[] = $moduleName;
                }
            }

            if (!empty($newDependents) && !$dryRun) {
                $this->info("Including dependent modules: " . implode(', ', $newDependents));
            }

            $toDisable = array_merge($toDisable, $newDependents);

        } while (!empty($newDependents));

        return array_unique($toDisable);
    }

    /**
     * Check for modules that depend on the specified modules.
     */
    private function checkForDependents(array $moduleNames, bool $dryRun = false): void
    {
        $allModules = $this->moduleRegistry->getEnabledModules();
        $dependencyGraph = $this->moduleRegistry->getDependencyGraph();
        $dependents = [];

        foreach ($allModules as $moduleName => $module) {
            if (in_array($moduleName, $moduleNames, true)) {
                continue;
            }

            $dependencies = $dependencyGraph[$moduleName] ?? [];

            if (array_intersect($dependencies, $moduleNames)) {
                $dependents[$moduleName] = array_intersect($dependencies, $moduleNames);
            }
        }

        if (!empty($dependents)) {
            if ($dryRun) {
                $this->warn("\n⚠️ Warning: Other modules depend on these:");
                foreach ($dependents as $dependent => $dependencies) {
                    $this->line("    {$dependent} depends on: " . implode(', ', $dependencies));
                }
            } else {
                $dependentsList = implode(', ', array_keys($dependents));
                throw new \RuntimeException(
                    "Cannot disable modules. The following modules depend on them: {$dependentsList}. " .
                    "Use --with-dependents to disable them too, or --force to ignore dependencies."
                );
            }
        }
    }

    /**
     * Disable the specified modules.
     */
    private function disableModules(array $moduleNames): array
    {
        $results = [
            'disabled' => [],
            'already_disabled' => [],
            'failed' => [],
        ];

        // Disable in reverse dependency order to avoid issues
        $orderedModules = $this->getModulesInDisableOrder($moduleNames);

        foreach ($orderedModules as $moduleName) {
            try {
                $module = $this->moduleRegistry->getModule($moduleName);

                if (!$module['enabled']) {
                    $results['already_disabled'][] = $moduleName;
                    continue;
                }

                if ($this->moduleRegistry->disableModule($moduleName)) {
                    $results['disabled'][] = $moduleName;
                    $this->info("✅ Disabled module: {$moduleName}");
                } else {
                    $results['failed'][] = $moduleName;
                    $this->error("❌ Failed to disable module: {$moduleName}");
                }

            } catch (\Exception $e) {
                $results['failed'][] = $moduleName;
                $this->error("❌ Failed to disable module {$moduleName}: {$e->getMessage()}");
            }
        }

        return $results;
    }

    /**
     * Get modules in the correct order for disabling (reverse dependency order).
     */
    private function getModulesInDisableOrder(array $moduleNames): array
    {
        $allModules = $this->moduleRegistry->getModulesInDependencyOrder();
        $filtered = $allModules->filter(function ($module) use ($moduleNames) {
            return in_array($module['name'], $moduleNames, true);
        });

        // Reverse the order for disabling
        return $filtered->reverse()->pluck('name')->toArray();
    }

    /**
     * Display the results of the disable operation.
     */
    private function displayResults(array $results): void
    {
        $disabled = $results['disabled'];
        $alreadyDisabled = $results['already_disabled'];
        $failed = $results['failed'];

        $this->info("\n📊 Disable Results:");

        if (!empty($disabled)) {
            $this->info("  ✅ Disabled (" . count($disabled) . "):");
            foreach ($disabled as $module) {
                $this->line("    • {$module}");
            }
        }

        if (!empty($alreadyDisabled)) {
            $this->warn("  ⚠️ Already Disabled (" . count($alreadyDisabled) . "):");
            foreach ($alreadyDisabled as $module) {
                $this->line("    • {$module}");
            }
        }

        if (!empty($failed)) {
            $this->error("  ❌ Failed (" . count($failed) . "):");
            foreach ($failed as $module) {
                $this->line("    • {$module}");
            }
        }

        $this->displayNextSteps($disabled);
    }

    /**
     * Display next steps after disabling modules.
     */
    private function displayNextSteps(array $disabledModules): void
    {
        if (empty($disabledModules)) {
            return;
        }

        $this->info("\n🎯 Next Steps:");
        $this->line("  1. Clear application cache:");
        $this->line("     php artisan cache:clear");

        $this->line("\n  2. Clear route cache:");
        $this->line("     php artisan route:clear");

        $this->line("\n  3. Clear config cache:");
        $this->line("     php artisan config:clear");

        $this->line("\n  4. Verify modules are disabled:");
        $this->line("     php artisan module:list --disabled");

        $this->warn("\n⚠️ Note: Module data and migrations are not affected.");
        $this->line("   Use 'php artisan module:remove' to completely remove modules.");

        $this->info("\n💡 Tip: Use 'php artisan module:enable' to re-enable modules later");
    }

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['modules', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'The module names to disable'],
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['with-dependents', 'd', InputOption::VALUE_NONE, 'Also disable modules that depend on these'],
            ['force', 'f', InputOption::VALUE_NONE, 'Force disable even if other modules depend on them'],
            ['dry-run', null, InputOption::VALUE_NONE, 'Show what would be disabled'],
        ];
    }
}