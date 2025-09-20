<?php

declare(strict_types=1);

namespace LaravelModularDDD\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use LaravelModularDDD\Support\ModuleRegistry;
use LaravelModularDDD\Generators\StubProcessor;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * ModuleMakeMigrationCommand
 *
 * Creates a new migration file for a specific module.
 * Generates properly structured migration files with module-specific naming.
 */
final class ModuleMakeMigrationCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'module:make:migration
                           {module : The module name}
                           {name : The name of the migration}
                           {--create= : The table to be created}
                           {--table= : The table to migrate}
                           {--path= : The location where the migration file should be created}
                           {--fullpath : Output the full path of the migration}';

    /**
     * The console command description.
     */
    protected $description = 'Create a new migration file for a module';

    public function __construct(
        private readonly ModuleRegistry $moduleRegistry,
        private readonly StubProcessor $stubProcessor
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
            $migrationName = $this->argument('name');

            $this->validateModule($moduleName);

            $migrationPath = $this->createMigration($moduleName, $migrationName);

            if ($this->option('fullpath')) {
                $this->line($migrationPath);
            } else {
                $this->info("Migration created successfully: " . basename($migrationPath));
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Failed to create migration: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    /**
     * Validate that the module exists.
     */
    private function validateModule(string $moduleName): void
    {
        if (!$this->moduleRegistry->hasModule($moduleName)) {
            throw new \InvalidArgumentException("Module '{$moduleName}' not found");
        }
    }

    /**
     * Create the migration file.
     */
    private function createMigration(string $moduleName, string $migrationName): string
    {
        $module = $this->moduleRegistry->getModule($moduleName);
        $migrationsPath = $this->getMigrationsPath($module);

        // Ensure migrations directory exists
        if (!is_dir($migrationsPath)) {
            mkdir($migrationsPath, 0755, true);
        }

        // Generate migration filename
        $filename = $this->generateMigrationFilename($migrationName);
        $migrationPath = "{$migrationsPath}/{$filename}";

        // Check if migration already exists
        if (file_exists($migrationPath)) {
            throw new \RuntimeException("Migration already exists: {$filename}");
        }

        // Generate migration content
        $content = $this->generateMigrationContent($moduleName, $migrationName);

        // Write migration file
        file_put_contents($migrationPath, $content);

        return $migrationPath;
    }

    /**
     * Get the migrations path for the module.
     */
    private function getMigrationsPath(array $module): string
    {
        if ($this->option('path')) {
            return $this->option('path');
        }

        return "{$module['path']}/Database/Migrations";
    }

    /**
     * Generate migration filename with timestamp.
     */
    private function generateMigrationFilename(string $name): string
    {
        $timestamp = date('Y_m_d_His');
        $name = Str::snake(trim($name));

        return "{$timestamp}_{$name}.php";
    }

    /**
     * Generate migration content based on type.
     */
    private function generateMigrationContent(string $moduleName, string $migrationName): string
    {
        $className = $this->generateMigrationClassName($migrationName);
        $tableName = $this->getTableName($migrationName);

        $variables = [
            'class' => $className,
            'table' => $tableName,
            'module' => $moduleName,
            'module_lower' => strtolower($moduleName),
        ];

        if ($this->option('create')) {
            $variables['table'] = $this->option('create');
            return $this->stubProcessor->process('migration-create', $variables);
        }

        if ($this->option('table')) {
            $variables['table'] = $this->option('table');
            return $this->stubProcessor->process('migration-table', $variables);
        }

        // Default migration template
        return $this->stubProcessor->process('migration', $variables);
    }

    /**
     * Generate migration class name.
     */
    private function generateMigrationClassName(string $name): string
    {
        return Str::studly($name);
    }

    /**
     * Get table name from migration name.
     */
    private function getTableName(string $migrationName): string
    {
        // Try to extract table name from migration name
        $name = strtolower($migrationName);

        // Common patterns
        if (preg_match('/create_(.+)_table/', $name, $matches)) {
            return $matches[1];
        }

        if (preg_match('/add_.+_to_(.+)_table/', $name, $matches)) {
            return $matches[1];
        }

        if (preg_match('/drop_(.+)_table/', $name, $matches)) {
            return $matches[1];
        }

        if (preg_match('/modify_(.+)_table/', $name, $matches)) {
            return $matches[1];
        }

        // Fallback: use migration name as table name
        return Str::snake(Str::plural($migrationName));
    }

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['module', InputArgument::REQUIRED, 'The module name'],
            ['name', InputArgument::REQUIRED, 'The name of the migration'],
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['create', null, InputOption::VALUE_OPTIONAL, 'The table to be created'],
            ['table', null, InputOption::VALUE_OPTIONAL, 'The table to migrate'],
            ['path', null, InputOption::VALUE_OPTIONAL, 'The location where the migration file should be created'],
            ['fullpath', null, InputOption::VALUE_NONE, 'Output the full path of the migration'],
        ];
    }
}