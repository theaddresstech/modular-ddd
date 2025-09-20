<?php

declare(strict_types=1);

namespace LaravelModularDDD\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use LaravelModularDDD\Support\ModuleRegistry;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * ModuleMigrateCommand
 *
 * Runs migrations for specific modules or all modules.
 * Provides module-specific migration management with dependency handling.
 */
final class ModuleMigrateCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'modular:migrate
                           {module? : The module to migrate (optional, migrates all if not specified)}
                           {--force : Force the operation to run when in production}
                           {--pretend : Dump the SQL queries that would be run}
                           {--seed : Indicates if the seed task should be run}
                           {--step : Force the migrations to be run so they can be rolled back individually}
                           {--with-dependencies : Include dependency modules in migration}
                           {--dry-run : Show what migrations would be run without executing them}';

    /**
     * The console command description.
     */
    protected $description = 'Run migrations for one or all modules';

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

            if ($moduleName) {
                return $this->migrateModule($moduleName);
            }

            return $this->migrateAllModules();

        } catch (\Exception $e) {
            $this->error("Migration failed: {$e->getMessage()}");

            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }

    /**
     * Perform dry run showing what migrations would be executed.
     */
    private function performDryRun(?string $moduleName): int
    {
        $this->warn("🔍 DRY RUN - No migrations will be executed");

        $modules = $moduleName ? [$this->getModuleForMigration($moduleName)] : $this->getModulesForMigration();
        $allMigrations = [];

        foreach ($modules as $module) {
            $migrations = $this->getModuleMigrations($module);
            $pendingMigrations = $this->getPendingMigrations($migrations);

            if (!empty($pendingMigrations)) {
                $allMigrations[$module['name']] = $pendingMigrations;
            }
        }

        if (empty($allMigrations)) {
            $this->info("✅ No pending migrations found");
            return Command::SUCCESS;
        }

        $this->info("\n📄 Migrations that would be executed:");
        foreach ($allMigrations as $moduleName => $migrations) {
            $this->line("\n  📦 {$moduleName}:");
            foreach ($migrations as $migration) {
                $this->line("    ✓ " . basename($migration, '.php'));
            }
        }

        $totalMigrations = array_sum(array_map('count', $allMigrations));
        $this->info("\n🔧 Total migrations: {$totalMigrations}");

        return Command::SUCCESS;
    }

    /**
     * Migrate a specific module.
     */
    private function migrateModule(string $moduleName): int
    {
        $module = $this->getModuleForMigration($moduleName);
        $modules = $this->option('with-dependencies') ? $this->getModulesWithDependencies([$module]) : [$module];

        $this->info("🚀 Migrating module(s): " . implode(', ', array_column($modules, 'name')));

        foreach ($modules as $moduleToMigrate) {
            $this->migrateModuleInternal($moduleToMigrate);
        }

        $this->info("✅ Module migration completed successfully");

        if ($this->option('seed')) {
            $this->runModuleSeeds($modules);
        }

        return Command::SUCCESS;
    }

    /**
     * Migrate all enabled modules.
     */
    private function migrateAllModules(): int
    {
        $modules = $this->getModulesForMigration();

        if (empty($modules)) {
            $this->info("✅ No modules found for migration");
            return Command::SUCCESS;
        }

        $this->info("🚀 Migrating all modules...");

        // Sort modules by dependency order
        $sortedModules = $this->moduleRegistry->getModulesInDependencyOrder()
            ->filter(fn($module) => in_array($module['name'], array_column($modules, 'name'), true))
            ->toArray();

        foreach ($sortedModules as $module) {
            $this->migrateModuleInternal($module);
        }

        $this->info("✅ All module migrations completed successfully");

        if ($this->option('seed')) {
            $this->runModuleSeeds($sortedModules);
        }

        return Command::SUCCESS;
    }

    /**
     * Get module for migration with validation.
     */
    private function getModuleForMigration(string $moduleName): array
    {
        if (!$this->moduleRegistry->hasModule($moduleName)) {
            throw new \InvalidArgumentException("Module '{$moduleName}' not found");
        }

        $module = $this->moduleRegistry->getModule($moduleName);

        if (!$module['enabled']) {
            throw new \InvalidArgumentException("Module '{$moduleName}' is disabled");
        }

        return $module;
    }

    /**
     * Get all modules for migration.
     */
    private function getModulesForMigration(): array
    {
        return $this->moduleRegistry->getEnabledModules()
            ->filter(function ($module) {
                $migrations = $this->getModuleMigrations($module);
                return !empty($migrations);
            })
            ->toArray();
    }

    /**
     * Get modules with their dependencies.
     */
    private function getModulesWithDependencies(array $modules): array
    {
        $moduleNames = array_column($modules, 'name');
        $allModules = [];

        foreach ($moduleNames as $moduleName) {
            $this->collectModuleDependencies($moduleName, $allModules);
        }

        return array_map(
            fn($name) => $this->moduleRegistry->getModule($name),
            array_unique($allModules)
        );
    }

    /**
     * Collect module dependencies recursively.
     */
    private function collectModuleDependencies(string $moduleName, array &$collected): void
    {
        if (in_array($moduleName, $collected, true)) {
            return;
        }

        $module = $this->moduleRegistry->getModule($moduleName);
        $dependencies = $module['dependencies']['module_dependencies'] ?? [];

        foreach ($dependencies as $dependency) {
            $this->collectModuleDependencies($dependency, $collected);
        }

        $collected[] = $moduleName;
    }

    /**
     * Migrate a single module internally.
     */
    private function migrateModuleInternal(array $module): void
    {
        $moduleName = $module['name'];
        $migrations = $this->getModuleMigrations($module);

        if (empty($migrations)) {
            $this->line("  📦 {$moduleName}: No migrations");
            return;
        }

        $this->line("  📦 {$moduleName}: Checking migrations...");

        $pendingMigrations = $this->getPendingMigrations($migrations);

        if (empty($pendingMigrations)) {
            $this->line("    ✅ No pending migrations");
            return;
        }

        $this->line("    🔄 Running " . count($pendingMigrations) . " migration(s)...");

        foreach ($pendingMigrations as $migration) {
            $this->runMigration($migration, $moduleName);
        }

        $this->line("    ✅ Migration completed");
    }

    /**
     * Get migrations for a module.
     */
    private function getModuleMigrations(array $module): array
    {
        $migrationsPath = "{$module['path']}/Database/Migrations";

        if (!is_dir($migrationsPath)) {
            return [];
        }

        $files = glob("{$migrationsPath}/*.php");

        return array_filter($files, function ($file) {
            return preg_match('/\d{4}_\d{2}_\d{2}_\d{6}_.*\.php$/', basename($file));
        });
    }

    /**
     * Get pending migrations.
     */
    private function getPendingMigrations(array $migrations): array
    {
        $ran = $this->migrator->getRepository()->getRan();

        return array_filter($migrations, function ($migration) use ($ran) {
            $migrationName = $this->getMigrationName($migration);
            return !in_array($migrationName, $ran, true);
        });
    }

    /**
     * Get migration name from file path.
     */
    private function getMigrationName(string $migrationPath): string
    {
        return basename($migrationPath, '.php');
    }

    /**
     * Run a single migration.
     */
    private function runMigration(string $migrationPath, string $moduleName): void
    {
        $migrationName = $this->getMigrationName($migrationPath);

        if ($this->option('pretend')) {
            $this->line("    🔍 Would run: {$migrationName}");
            return;
        }

        try {
            $this->line("    ⏳ Running: {$migrationName}");

            // Include the migration file
            require_once $migrationPath;

            // Get the migration class
            $migrationClass = $this->getMigrationClass($migrationPath);

            if (!$migrationClass) {
                throw new \RuntimeException("Could not determine migration class for {$migrationName}");
            }

            // Create migration instance
            $migration = new $migrationClass();

            // Run the migration
            if ($this->option('step')) {
                $this->migrator->runUp($migrationPath, 1);
            } else {
                $migration->up();
                $this->migrator->getRepository()->log($migrationName, 1);
            }

            $this->line("    ✅ Migrated: {$migrationName}");

        } catch (\Exception $e) {
            $this->error("    ❌ Failed: {$migrationName} - {$e->getMessage()}");
            throw $e;
        }
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
     * Run module seeds.
     */
    private function runModuleSeeds(array $modules): void
    {
        $this->info("\n🌱 Running module seeds...");

        foreach ($modules as $module) {
            $this->runModuleSeed($module);
        }

        $this->info("✅ Module seeding completed");
    }

    /**
     * Run seed for a specific module.
     */
    private function runModuleSeed(array $module): void
    {
        $moduleName = $module['name'];
        $seederPath = "{$module['path']}/Database/Seeders";

        if (!is_dir($seederPath)) {
            $this->line("  📦 {$moduleName}: No seeders");
            return;
        }

        $seederClass = "Modules\\{$moduleName}\\Database\\Seeders\\{$moduleName}Seeder";

        if (!class_exists($seederClass)) {
            $this->line("  📦 {$moduleName}: Seeder class not found");
            return;
        }

        try {
            $this->line("  📦 {$moduleName}: Running seeder...");

            $seeder = new $seederClass();
            $seeder->run();

            $this->line("    ✅ Seeded successfully");

        } catch (\Exception $e) {
            $this->error("    ❌ Seeding failed: {$e->getMessage()}");
        }
    }

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['module', InputArgument::OPTIONAL, 'The module to migrate'],
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['force', null, InputOption::VALUE_NONE, 'Force the operation to run when in production'],
            ['pretend', null, InputOption::VALUE_NONE, 'Dump the SQL queries that would be run'],
            ['seed', null, InputOption::VALUE_NONE, 'Indicates if the seed task should be run'],
            ['step', null, InputOption::VALUE_NONE, 'Force the migrations to be run so they can be rolled back individually'],
            ['with-dependencies', 'd', InputOption::VALUE_NONE, 'Include dependency modules in migration'],
            ['dry-run', null, InputOption::VALUE_NONE, 'Show what migrations would be run'],
        ];
    }
}