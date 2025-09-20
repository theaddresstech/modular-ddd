<?php

declare(strict_types=1);

namespace LaravelModularDDD\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use LaravelModularDDD\Support\ModuleRegistry;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * ModuleMigrateStatusCommand
 *
 * Shows the status of migrations for modules.
 * Displays which migrations have been run and which are pending.
 */
final class ModuleMigrateStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'modular:migrate:status
                           {module? : The module to check status for (optional)}
                           {--pending : Show only pending migrations}
                           {--ran : Show only completed migrations}
                           {--json : Output as JSON}';

    /**
     * The console command description.
     */
    protected $description = 'Show the status of migrations for modules';

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

            if ($moduleName) {
                return $this->showModuleStatus($moduleName);
            }

            return $this->showAllModulesStatus();

        } catch (\Exception $e) {
            $this->error("Failed to get migration status: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    /**
     * Show migration status for a specific module.
     */
    private function showModuleStatus(string $moduleName): int
    {
        if (!$this->moduleRegistry->hasModule($moduleName)) {
            $this->error("Module '{$moduleName}' not found.");
            return Command::FAILURE;
        }

        $module = $this->moduleRegistry->getModule($moduleName);
        $status = $this->getModuleMigrationStatus($module);

        if ($this->option('json')) {
            $this->outputJsonStatus([$moduleName => $status]);
            return Command::SUCCESS;
        }

        $this->displayModuleStatus($moduleName, $status);

        return Command::SUCCESS;
    }

    /**
     * Show migration status for all modules.
     */
    private function showAllModulesStatus(): int
    {
        $modules = $this->moduleRegistry->getRegisteredModules();
        $allStatus = [];

        foreach ($modules as $moduleName => $module) {
            $status = $this->getModuleMigrationStatus($module);
            $allStatus[$moduleName] = $status;
        }

        if ($this->option('json')) {
            $this->outputJsonStatus($allStatus);
            return Command::SUCCESS;
        }

        $this->displayAllModulesStatus($allStatus);

        return Command::SUCCESS;
    }

    /**
     * Get migration status for a module.
     */
    private function getModuleMigrationStatus(array $module): array
    {
        $moduleName = $module['name'];
        $migrationsPath = "{$module['path']}/Database/Migrations";

        $status = [
            'module_name' => $moduleName,
            'enabled' => $module['enabled'],
            'migrations_path' => $migrationsPath,
            'migrations_exist' => is_dir($migrationsPath),
            'total_migrations' => 0,
            'ran_migrations' => 0,
            'pending_migrations' => 0,
            'migrations' => [],
        ];

        if (!$status['migrations_exist']) {
            return $status;
        }

        // Get all migration files
        $migrationFiles = glob("{$migrationsPath}/*.php");
        $migrationFiles = array_filter($migrationFiles, function ($file) {
            return preg_match('/\d{4}_\d{2}_\d{2}_\d{6}_.*\.php$/', basename($file));
        });

        $status['total_migrations'] = count($migrationFiles);

        // Get ran migrations
        $ranMigrations = $this->migrator->getRepository()->getRan();

        foreach ($migrationFiles as $file) {
            $migrationName = basename($file, '.php');
            $isRan = in_array($migrationName, $ranMigrations, true);

            $migrationInfo = [
                'name' => $migrationName,
                'file' => basename($file),
                'path' => $file,
                'status' => $isRan ? 'ran' : 'pending',
                'batch' => null,
                'executed_at' => null,
            ];

            if ($isRan) {
                $status['ran_migrations']++;
                $migrationDetails = $this->getMigrationDetails($migrationName);
                $migrationInfo['batch'] = $migrationDetails['batch'] ?? null;
                $migrationInfo['executed_at'] = $migrationDetails['executed_at'] ?? null;
            } else {
                $status['pending_migrations']++;
            }

            $status['migrations'][] = $migrationInfo;
        }

        // Sort migrations by name (which includes timestamp)
        usort($status['migrations'], fn($a, $b) => strcmp($a['name'], $b['name']));

        return $status;
    }

    /**
     * Get migration details from database.
     */
    private function getMigrationDetails(string $migrationName): array
    {
        try {
            $migration = \DB::table('migrations')
                ->where('migration', $migrationName)
                ->first();

            return [
                'batch' => $migration->batch ?? null,
                'executed_at' => $migration->created_at ?? null,
            ];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Display status for a single module.
     */
    private function displayModuleStatus(string $moduleName, array $status): void
    {
        $enabledStatus = $status['enabled'] ? '✅ Enabled' : '❌ Disabled';

        $this->info("📦 Module: {$moduleName} ({$enabledStatus})");
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

        if (!$status['migrations_exist']) {
            $this->warn("⚠️ No migrations directory found");
            return;
        }

        $this->line("📊 Migration Summary:");
        $this->line("  Total: {$status['total_migrations']}");
        $this->line("  Ran: {$status['ran_migrations']}");
        $this->line("  Pending: {$status['pending_migrations']}");

        if (empty($status['migrations'])) {
            $this->line("\n📄 No migrations found");
            return;
        }

        $this->displayMigrationsList($status['migrations']);
    }

    /**
     * Display status for all modules.
     */
    private function displayAllModulesStatus(array $allStatus): void
    {
        $this->info("📊 Migration Status Summary");
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

        // Summary table
        $headers = ['Module', 'Status', 'Total', 'Ran', 'Pending'];
        $rows = [];

        $totalMigrations = 0;
        $totalRan = 0;
        $totalPending = 0;

        foreach ($allStatus as $moduleName => $status) {
            $enabledIcon = $status['enabled'] ? '✅' : '❌';
            $migrationsIcon = $status['migrations_exist'] ? '📁' : '❌';

            $rows[] = [
                $moduleName,
                $enabledIcon . ' ' . $migrationsIcon,
                $status['total_migrations'],
                $status['ran_migrations'],
                $status['pending_migrations'],
            ];

            $totalMigrations += $status['total_migrations'];
            $totalRan += $status['ran_migrations'];
            $totalPending += $status['pending_migrations'];
        }

        // Add totals row
        $rows[] = ['---', '---', '---', '---', '---'];
        $rows[] = ['TOTAL', '', $totalMigrations, $totalRan, $totalPending];

        $this->table($headers, $rows);

        // Filter options
        if ($this->option('pending') || $this->option('ran')) {
            $this->displayFilteredMigrations($allStatus);
        }

        $this->displayMigrationStatistics($allStatus);
    }

    /**
     * Display list of migrations with their status.
     */
    private function displayMigrationsList(array $migrations): void
    {
        $showPending = $this->option('pending');
        $showRan = $this->option('ran');

        if ($showPending || $showRan) {
            $migrations = array_filter($migrations, function ($migration) use ($showPending, $showRan) {
                if ($showPending && $migration['status'] === 'pending') {
                    return true;
                }
                if ($showRan && $migration['status'] === 'ran') {
                    return true;
                }
                return !$showPending && !$showRan;
            });
        }

        if (empty($migrations)) {
            $this->line("\n📄 No migrations match the criteria");
            return;
        }

        $this->line("\n📄 Migrations:");

        foreach ($migrations as $migration) {
            $icon = $migration['status'] === 'ran' ? '✅' : '⏳';
            $batch = $migration['batch'] ? " (batch {$migration['batch']})" : '';
            $executedAt = $migration['executed_at'] ? " - {$migration['executed_at']}" : '';

            $this->line("  {$icon} {$migration['name']}{$batch}{$executedAt}");
        }
    }

    /**
     * Display filtered migrations across all modules.
     */
    private function displayFilteredMigrations(array $allStatus): void
    {
        $showPending = $this->option('pending');
        $showRan = $this->option('ran');

        $filteredMigrations = [];

        foreach ($allStatus as $moduleName => $status) {
            foreach ($status['migrations'] as $migration) {
                if ($showPending && $migration['status'] === 'pending') {
                    $filteredMigrations[] = array_merge($migration, ['module' => $moduleName]);
                } elseif ($showRan && $migration['status'] === 'ran') {
                    $filteredMigrations[] = array_merge($migration, ['module' => $moduleName]);
                }
            }
        }

        if (empty($filteredMigrations)) {
            return;
        }

        $title = $showPending ? 'Pending Migrations' : 'Completed Migrations';
        $this->info("\n🔍 {$title}:");

        foreach ($filteredMigrations as $migration) {
            $icon = $migration['status'] === 'ran' ? '✅' : '⏳';
            $batch = $migration['batch'] ? " (batch {$migration['batch']})" : '';

            $this->line("  {$icon} [{$migration['module']}] {$migration['name']}{$batch}");
        }
    }

    /**
     * Display migration statistics.
     */
    private function displayMigrationStatistics(array $allStatus): void
    {
        $stats = [
            'modules_with_migrations' => 0,
            'modules_without_migrations' => 0,
            'modules_with_pending' => 0,
            'enabled_modules' => 0,
            'disabled_modules' => 0,
        ];

        foreach ($allStatus as $status) {
            if ($status['enabled']) {
                $stats['enabled_modules']++;
            } else {
                $stats['disabled_modules']++;
            }

            if ($status['total_migrations'] > 0) {
                $stats['modules_with_migrations']++;
            } else {
                $stats['modules_without_migrations']++;
            }

            if ($status['pending_migrations'] > 0) {
                $stats['modules_with_pending']++;
            }
        }

        $this->info("\n📈 Statistics:");
        $this->line("  Modules: {$stats['enabled_modules']} enabled, {$stats['disabled_modules']} disabled");
        $this->line("  With migrations: {$stats['modules_with_migrations']}");
        $this->line("  Without migrations: {$stats['modules_without_migrations']}");
        $this->line("  With pending migrations: {$stats['modules_with_pending']}");
    }

    /**
     * Output status as JSON.
     */
    private function outputJsonStatus(array $status): void
    {
        $output = [
            'migration_status' => $status,
            'generated_at' => now()->toISOString(),
        ];

        $this->line(json_encode($output, JSON_PRETTY_PRINT));
    }

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['module', InputArgument::OPTIONAL, 'The module to check status for'],
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['pending', null, InputOption::VALUE_NONE, 'Show only pending migrations'],
            ['ran', null, InputOption::VALUE_NONE, 'Show only completed migrations'],
            ['json', null, InputOption::VALUE_NONE, 'Output as JSON'],
        ];
    }
}