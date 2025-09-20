<?php

declare(strict_types=1);

namespace LaravelModularDDD\Console\Commands;

use Illuminate\Console\Command;
use LaravelModularDDD\Generators\ModuleGenerator;
use LaravelModularDDD\Generators\Contracts\GeneratorInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * ModuleMakeCommand
 *
 * Artisan command to generate complete DDD modules.
 * Creates all necessary components following DDD patterns.
 */
final class ModuleMakeCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'module:make
                           {name : The name of the module}
                           {--aggregate= : The main aggregate name (defaults to module name)}
                           {--force : Overwrite existing files}
                           {--no-tests : Skip test generation}
                           {--no-migration : Skip migration generation}
                           {--no-factory : Skip factory generation}
                           {--no-seeder : Skip seeder generation}
                           {--no-api : Skip API components}
                           {--no-web : Skip web components}
                           {--dry-run : Show what would be generated without creating files}';

    /**
     * The console command description.
     */
    protected $description = 'Generate a complete DDD module with all necessary components';

    public function __construct(
        private readonly ModuleGenerator $moduleGenerator
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $moduleName = $this->argument('name');
        $aggregateName = $this->option('aggregate') ?? $moduleName;

        $this->info("🚀 Generating DDD module: {$moduleName}");

        try {
            $options = $this->buildGenerationOptions();

            if ($options['dry_run']) {
                $this->performDryRun($moduleName, $aggregateName, $options);
                return Command::SUCCESS;
            }

            $this->validateModuleName($moduleName);
            $this->checkExistingModule($moduleName, $options['force']);

            $result = $this->moduleGenerator->generate($moduleName, $aggregateName, $options);

            $this->displayResults($result);
            $this->displayNextSteps($moduleName);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Module generation failed: {$e->getMessage()}");

            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }

    /**
     * Build options array from command options.
     */
    private function buildGenerationOptions(): array
    {
        return [
            'force' => $this->option('force'),
            'generate_tests' => !$this->option('no-tests'),
            'generate_migration' => !$this->option('no-migration'),
            'generate_factory' => !$this->option('no-factory'),
            'generate_seeder' => !$this->option('no-seeder'),
            'generate_api' => !$this->option('no-api'),
            'generate_web' => !$this->option('no-web'),
            'dry_run' => $this->option('dry-run'),
        ];
    }

    /**
     * Perform a dry run showing what would be generated.
     */
    private function performDryRun(string $moduleName, string $aggregateName, array $options): void
    {
        $this->warn("🔍 DRY RUN - No files will be created");

        $files = $this->moduleGenerator->getFilesToGenerate($moduleName, $aggregateName, $options);

        $this->info("\n📁 Directory structure that would be created:");
        $this->displayDirectoryStructure($files);

        $this->info("\n📄 Files that would be generated:");
        foreach ($files as $file) {
            $this->line("  ✓ {$file}");
        }

        $this->info("\n🔧 Total files: " . count($files));
    }

    /**
     * Validate the module name format.
     */
    private function validateModuleName(string $moduleName): void
    {
        if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $moduleName)) {
            throw new \InvalidArgumentException(
                'Module name must start with an uppercase letter and contain only alphanumeric characters'
            );
        }

        if (strlen($moduleName) > 50) {
            throw new \InvalidArgumentException('Module name cannot exceed 50 characters');
        }
    }

    /**
     * Check if module already exists and handle accordingly.
     */
    private function checkExistingModule(string $moduleName, bool $force): void
    {
        $modulePath = base_path("Modules/{$moduleName}");

        if (is_dir($modulePath) && !$force) {
            if (!$this->confirm("Module {$moduleName} already exists. Do you want to continue and overwrite existing files?")) {
                throw new \RuntimeException('Module generation cancelled by user');
            }
        }
    }

    /**
     * Display generation results.
     */
    private function displayResults(array $result): void
    {
        $this->info("\n✅ Module generation completed successfully!");

        if (isset($result['created_files'])) {
            $this->info("\n📄 Generated files:");
            foreach ($result['created_files'] as $file) {
                $this->line("  ✓ {$file}");
            }
        }

        if (isset($result['skipped_files'])) {
            $this->warn("\n⚠️ Skipped files (already exist):");
            foreach ($result['skipped_files'] as $file) {
                $this->line("  - {$file}");
            }
        }

        if (isset($result['errors'])) {
            $this->error("\n❌ Errors encountered:");
            foreach ($result['errors'] as $error) {
                $this->line("  ✗ {$error}");
            }
        }

        $stats = $result['stats'] ?? [];
        $this->displayStats($stats);
    }

    /**
     * Display generation statistics.
     */
    private function displayStats(array $stats): void
    {
        if (empty($stats)) {
            return;
        }

        $this->info("\n📊 Generation Statistics:");

        foreach ($stats as $key => $value) {
            $label = ucwords(str_replace('_', ' ', $key));
            $this->line("  {$label}: {$value}");
        }
    }

    /**
     * Display next steps for the user.
     */
    private function displayNextSteps(string $moduleName): void
    {
        $this->info("\n🎯 Next Steps:");
        $this->line("  1. Register the module service provider in config/app.php:");
        $this->line("     Modules\\{$moduleName}\\{$moduleName}ServiceProvider::class");

        $this->line("\n  2. Run migrations:");
        $this->line("     php artisan migrate");

        $this->line("\n  3. Run tests:");
        $this->line("     php artisan test Modules/{$moduleName}/Tests");

        $this->line("\n  4. Review and customize generated code:");
        $this->line("     - Domain business rules in Domain/Aggregates");
        $this->line("     - Command/Query handlers in Application layer");
        $this->line("     - API controllers in Presentation layer");

        $this->line("\n  5. Configure module settings:");
        $this->line("     config/{strtolower($moduleName)}.php");

        $this->info("\n🎉 Happy coding!");
    }

    /**
     * Display directory structure preview.
     */
    private function displayDirectoryStructure(array $files): void
    {
        $structure = [];

        foreach ($files as $file) {
            $parts = explode('/', $file);
            $current = &$structure;

            foreach ($parts as $part) {
                if (!isset($current[$part])) {
                    $current[$part] = [];
                }
                $current = &$current[$part];
            }
        }

        $this->printTree($structure, '  ');
    }

    /**
     * Recursively print directory tree.
     */
    private function printTree(array $tree, string $prefix = ''): void
    {
        foreach ($tree as $name => $children) {
            $this->line("{$prefix}├── {$name}");

            if (is_array($children) && !empty($children)) {
                $this->printTree($children, $prefix . '│   ');
            }
        }
    }

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['name', InputArgument::REQUIRED, 'The name of the module'],
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['aggregate', null, InputOption::VALUE_OPTIONAL, 'The main aggregate name'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files'],
            ['no-tests', null, InputOption::VALUE_NONE, 'Skip test generation'],
            ['no-migration', null, InputOption::VALUE_NONE, 'Skip migration generation'],
            ['no-factory', null, InputOption::VALUE_NONE, 'Skip factory generation'],
            ['no-seeder', null, InputOption::VALUE_NONE, 'Skip seeder generation'],
            ['no-api', null, InputOption::VALUE_NONE, 'Skip API components'],
            ['no-web', null, InputOption::VALUE_NONE, 'Skip web components'],
            ['dry-run', null, InputOption::VALUE_NONE, 'Show what would be generated'],
        ];
    }
}