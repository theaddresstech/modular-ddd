<?php

declare(strict_types=1);

namespace LaravelModularDDD\Console\Commands;

use Illuminate\Console\Command;
use LaravelModularDDD\Support\ModuleRegistry;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * ModuleEnableCommand
 *
 * Enables one or more modules in the application.
 * Handles dependency resolution and validation.
 */
final class ModuleEnableCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'module:enable
                           {modules* : The module names to enable}
                           {--all : Enable all modules}
                           {--with-dependencies : Also enable required dependencies}
                           {--force : Force enable even if dependencies are missing}
                           {--dry-run : Show what would be enabled without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Enable one or more modules';

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
            $moduleNames = $this->getModulesToEnable();

            if (empty($moduleNames)) {
                $this->error('No modules specified. Use --all to enable all modules.');
                return Command::FAILURE;
            }

            if ($this->option('dry-run')) {
                $this->performDryRun($moduleNames);
                return Command::SUCCESS;
            }

            $this->validateModules($moduleNames);

            if ($this->option('with-dependencies')) {
                $moduleNames = $this->resolveDependencies($moduleNames);
            }

            $results = $this->enableModules($moduleNames);
            $this->displayResults($results);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Failed to enable modules: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    /**
     * Get modules to enable based on arguments and options.
     */
    private function getModulesToEnable(): array
    {
        if ($this->option('all')) {
            return $this->moduleRegistry->getDisabledModules()->keys()->toArray();
        }

        return $this->argument('modules');
    }

    /**
     * Perform dry run showing what would be enabled.
     */
    private function performDryRun(array $moduleNames): void
    {
        $this->warn("🔍 DRY RUN - No modules will be enabled");

        $this->info("\n📦 Modules that would be enabled:");
        foreach ($moduleNames as $moduleName) {
            $module = $this->moduleRegistry->getModule($moduleName);

            if (!$module) {
                $this->line("  ❌ {$moduleName} (not found)");
                continue;
            }

            $status = $module['enabled'] ? 'already enabled' : 'would be enabled';
            $this->line("  ✓ {$moduleName} ({$status})");
        }

        if ($this->option('with-dependencies')) {
            $this->info("\n🔗 Dependencies that would be resolved:");
            $resolved = $this->resolveDependencies($moduleNames, true);
            $dependencies = array_diff($resolved, $moduleNames);

            foreach ($dependencies as $dependency) {
                $this->line("  ➜ {$dependency} (dependency)");
            }
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
     * Resolve module dependencies.
     */
    private function resolveDependencies(array $moduleNames, bool $dryRun = false): array
    {
        $resolved = [];
        $processing = [];

        foreach ($moduleNames as $moduleName) {
            $this->resolveDependenciesRecursive($moduleName, $resolved, $processing, $dryRun);
        }

        return array_unique($resolved);
    }

    /**
     * Recursively resolve dependencies for a module.
     */
    private function resolveDependenciesRecursive(string $moduleName, array &$resolved, array &$processing, bool $dryRun): void
    {
        if (in_array($moduleName, $resolved, true)) {
            return;
        }

        if (in_array($moduleName, $processing, true)) {
            throw new \RuntimeException("Circular dependency detected involving module: {$moduleName}");
        }

        $module = $this->moduleRegistry->getModule($moduleName);

        if (!$module) {
            if (!$this->option('force') && !$dryRun) {
                throw new \InvalidArgumentException("Required dependency '{$moduleName}' does not exist");
            }
            return;
        }

        $processing[] = $moduleName;

        $dependencies = $module['dependencies']['module_dependencies'] ?? [];

        foreach ($dependencies as $dependency) {
            $this->resolveDependenciesRecursive($dependency, $resolved, $processing, $dryRun);
        }

        $resolved[] = $moduleName;
        $processing = array_diff($processing, [$moduleName]);
    }

    /**
     * Enable the specified modules.
     */
    private function enableModules(array $moduleNames): array
    {
        $results = [
            'enabled' => [],
            'already_enabled' => [],
            'failed' => [],
        ];

        foreach ($moduleNames as $moduleName) {
            try {
                $module = $this->moduleRegistry->getModule($moduleName);

                if ($module['enabled']) {
                    $results['already_enabled'][] = $moduleName;
                    continue;
                }

                if ($this->moduleRegistry->enableModule($moduleName)) {
                    $results['enabled'][] = $moduleName;
                    $this->info("✅ Enabled module: {$moduleName}");
                } else {
                    $results['failed'][] = $moduleName;
                    $this->error("❌ Failed to enable module: {$moduleName}");
                }

            } catch (\Exception $e) {
                $results['failed'][] = $moduleName;
                $this->error("❌ Failed to enable module {$moduleName}: {$e->getMessage()}");
            }
        }

        return $results;
    }

    /**
     * Display the results of the enable operation.
     */
    private function displayResults(array $results): void
    {
        $enabled = $results['enabled'];
        $alreadyEnabled = $results['already_enabled'];
        $failed = $results['failed'];

        $this->info("\n📊 Enable Results:");

        if (!empty($enabled)) {
            $this->info("  ✅ Enabled (" . count($enabled) . "):");
            foreach ($enabled as $module) {
                $this->line("    • {$module}");
            }
        }

        if (!empty($alreadyEnabled)) {
            $this->warn("  ⚠️ Already Enabled (" . count($alreadyEnabled) . "):");
            foreach ($alreadyEnabled as $module) {
                $this->line("    • {$module}");
            }
        }

        if (!empty($failed)) {
            $this->error("  ❌ Failed (" . count($failed) . "):");
            foreach ($failed as $module) {
                $this->line("    • {$module}");
            }
        }

        $this->displayNextSteps($enabled);
    }

    /**
     * Display next steps after enabling modules.
     */
    private function displayNextSteps(array $enabledModules): void
    {
        if (empty($enabledModules)) {
            return;
        }

        $this->info("\n🎯 Next Steps:");
        $this->line("  1. Clear application cache:");
        $this->line("     php artisan cache:clear");

        $this->line("\n  2. Run migrations for enabled modules:");
        $this->line("     php artisan migrate");

        $this->line("\n  3. Publish module assets if needed:");
        foreach ($enabledModules as $module) {
            $this->line("     php artisan vendor:publish --tag={$module}");
        }

        $this->line("\n  4. Verify modules are working:");
        $this->line("     php artisan module:list --enabled");

        $this->info("\n💡 Tip: Use 'php artisan module:health' to check module status");
    }

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['modules', InputArgument::IS_ARRAY, 'The module names to enable'],
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['all', null, InputOption::VALUE_NONE, 'Enable all modules'],
            ['with-dependencies', 'd', InputOption::VALUE_NONE, 'Also enable required dependencies'],
            ['force', 'f', InputOption::VALUE_NONE, 'Force enable even if dependencies are missing'],
            ['dry-run', null, InputOption::VALUE_NONE, 'Show what would be enabled'],
        ];
    }
}