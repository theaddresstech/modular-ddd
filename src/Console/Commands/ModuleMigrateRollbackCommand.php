<?php

declare(strict_types=1);

namespace LaravelModularDDD\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use LaravelModularDDD\Support\ModuleRegistry;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * ModuleMigrateRollbackCommand
 *
 * Rolls back migrations for specific modules.
 * Handles dependency checking to prevent breaking dependent modules.
 */
final class ModuleMigrateRollbackCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'module:migrate:rollback
                           {module : The module to rollback}
                           {--step=1 : The number of migrations to rollback}
                           {--force : Force the operation to run when in production}
                           {--pretend : Dump the SQL queries that would be run}
                           {--dry-run : Show what migrations would be rolled back}';

    /**
     * The console command description.
     */
    protected $description = 'Rollback migrations for a specific module';

    public function __construct(
        private readonly ModuleRegistry $moduleRegistry,
        private readonly Migrator $migrator
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $moduleName = $this->argument('module');

            if ($this->option('dry-run')) {
                return $this->performDryRun($moduleName);
            }

            return $this->rollbackModule($moduleName);

        } catch (\Exception $e) {
            $this->error("Rollback failed: {$e->getMessage()}");

            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }

    /**
     * Perform dry run showing what migrations would be rolled back.
     */
    private function performDryRun(string $moduleName): int
    {
        $this->warn("🔍 DRY RUN - No migrations will be rolled back");

        $module = $this->getModuleForRollback($moduleName);
        $migrations = $this->getMigrationsToRollback($module);

        if (empty($migrations)) {
            $this->info("✅ No migrations to rollback for module {$moduleName}");
            return Command::SUCCESS;
        }

        $this->info("\n📄 Migrations that would be rolled back:");
        foreach ($migrations as $migration) {
            $this->line("  ✓ {$migration}");
        }

        $this->info("\n🔧 Total migrations: " . count($migrations));

        // Check for dependent modules
        $dependents = $this->findDependentModules($moduleName);
        if (!empty($dependents)) {
            $this->warn("\n⚠️ Warning: The following modules depend on {$moduleName}:");
            foreach ($dependents as $dependent) {
                $this->line("  • {$dependent}");
            }
            $this->warn("Rolling back {$moduleName} may break these modules.");
        }

        return Command::SUCCESS;
    }

    /**
     * Rollback migrations for a specific module.
     */
    private function rollbackModule(string $moduleName): int
    {
        $module = $this->getModuleForRollback($moduleName);

        // Check for dependent modules
        $dependents = $this->findDependentModules($moduleName);
        if (!empty($dependents) && !$this->option('force')) {
            $dependentsList = implode(', ', $dependents);
            $this->error("Cannot rollback {$moduleName}. The following modules depend on it: {$dependentsList}");
            $this->line("Use --force to ignore this warning.");
            return Command::FAILURE;
        }

        $migrations = $this->getMigrationsToRollback($module);

        if (empty($migrations)) {
            $this->info("✅ No migrations to rollback for module {$moduleName}");
            return Command::SUCCESS;
        }

        $this->info("🔄 Rolling back {$moduleName} module...");

        if (!empty($dependents)) {
            $this->warn("⚠️ Warning: This may break dependent modules: " . implode(', ', $dependents));
        }

        if (!$this->confirmRollback($moduleName, $migrations)) {
            $this->info("Rollback cancelled.");
            return Command::SUCCESS;
        }

        foreach ($migrations as $migration) {
            $this->rollbackMigration($migration, $moduleName);
        }

        $this->info("✅ Module {$moduleName} rollback completed successfully");

        return Command::SUCCESS;
    }

    /**
     * Get module for rollback with validation.
     */
    private function getModuleForRollback(string $moduleName): array
    {
        if (!$this->moduleRegistry->hasModule($moduleName)) {
            throw new \InvalidArgumentException("Module '{$moduleName}' not found");
        }

        return $this->moduleRegistry->getModule($moduleName);
    }

    /**
     * Get migrations to rollback for a module.
     */
    private function getMigrationsToRollback(array $module): array
    {
        $moduleName = $module['name'];
        $migrationsPath = "{$module['path']}/Database/Migrations";

        if (!is_dir($migrationsPath)) {
            return [];
        }

        // Get all migration files for this module
        $migrationFiles = glob("{$migrationsPath}/*.php");
        $migrationNames = array_map(
            fn($file) => basename($file, '.php'),
            $migrationFiles
        );

        // Get ran migrations from repository
        $ranMigrations = $this->migrator->getRepository()->getRan();

        // Filter to only migrations that have been run for this module
        $moduleMigrations = array_filter($ranMigrations, function ($migration) use ($migrationNames) {
            return in_array($migration, $migrationNames, true);
        });

        // Sort in reverse order for rollback
        $moduleMigrations = array_reverse($moduleMigrations);

        // Limit by step option
        $steps = (int) $this->option('step');
        if ($steps > 0) {
            $moduleMigrations = array_slice($moduleMigrations, 0, $steps);
        }

        return $moduleMigrations;
    }

    /**
     * Find modules that depend on the given module.
     */
    private function findDependentModules(string $moduleName): array
    {
        $dependents = [];
        $allModules = $this->moduleRegistry->getEnabledModules();

        foreach ($allModules as $name => $module) {
            if ($name === $moduleName) {
                continue;
            }

            $dependencies = $module['dependencies']['module_dependencies'] ?? [];
            if (in_array($moduleName, $dependencies, true)) {
                $dependents[] = $name;
            }
        }

        return $dependents;
    }

    /**
     * Confirm rollback operation.
     */
    private function confirmRollback(string $moduleName, array $migrations): bool
    {
        if ($this->option('force') || $this->option('pretend')) {
            return true;
        }

        $migrationCount = count($migrations);
        $migrationWord = $migrationCount === 1 ? 'migration' : 'migrations';

        return $this->confirm(
            "Are you sure you want to rollback {$migrationCount} {$migrationWord} for module {$moduleName}?"
        );
    }

    /**
     * Rollback a single migration.
     */
    private function rollbackMigration(string $migrationName, string $moduleName): void
    {
        if ($this->option('pretend')) {
            $this->line("  🔍 Would rollback: {$migrationName}");
            return;
        }

        try {
            $this->line("  ⏳ Rolling back: {$migrationName}");

            // Find the migration file
            $module = $this->moduleRegistry->getModule($moduleName);
            $migrationPath = $this->findMigrationFile($module, $migrationName);

            if (!$migrationPath) {
                throw new \RuntimeException("Migration file not found for {$migrationName}");
            }

            // Include the migration file
            require_once $migrationPath;

            // Get the migration class
            $migrationClass = $this->getMigrationClass($migrationPath);

            if ($migrationClass) {
                // Create migration instance and run down method
                $migration = new $migrationClass();
                $migration->down();
            } else {
                // Handle anonymous class migrations
                $this->runAnonymousMigrationDown($migrationPath);
            }

            // Remove from migrations table
            $this->migrator->getRepository()->delete($migrationName);

            $this->line("  ✅ Rolled back: {$migrationName}");

        } catch (\Exception $e) {
            $this->error("  ❌ Failed: {$migrationName} - {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Find migration file by name.
     */
    private function findMigrationFile(array $module, string $migrationName): ?string
    {
        $migrationsPath = "{$module['path']}/Database/Migrations";
        $migrationFile = "{$migrationsPath}/{$migrationName}.php";

        return file_exists($migrationFile) ? $migrationFile : null;
    }

    /**
     * Get migration class name from file.
     */
    private function getMigrationClass(string $migrationPath): ?string
    {
        $content = file_get_contents($migrationPath);

        if (preg_match('/return new class[^{]*extends Migration/', $content)) {
            // Anonymous class migration
            return null;
        }

        if (preg_match('/class\s+(\w+)\s+extends\s+Migration/', $content, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Run down method for anonymous class migration.
     */
    private function runAnonymousMigrationDown(string $migrationPath): void
    {
        // For anonymous class migrations, we need to include and execute
        $migration = include $migrationPath;

        if (is_object($migration) && method_exists($migration, 'down')) {
            $migration->down();
        } else {
            throw new \RuntimeException("Invalid migration structure in {$migrationPath}");
        }
    }

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['module', InputArgument::REQUIRED, 'The module to rollback'],
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['step', null, InputOption::VALUE_OPTIONAL, 'The number of migrations to rollback', 1],
            ['force', null, InputOption::VALUE_NONE, 'Force the operation to run when in production'],
            ['pretend', null, InputOption::VALUE_NONE, 'Dump the SQL queries that would be run'],
            ['dry-run', null, InputOption::VALUE_NONE, 'Show what migrations would be rolled back'],
        ];
    }
}