<?php

declare(strict_types=1);

namespace LaravelModularDDD\Console\Commands;

use Illuminate\Console\Command;
use LaravelModularDDD\Generators\QueryGenerator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * QueryGenerateCommand
 *
 * Artisan command to generate CQRS queries and handlers.
 * Creates query classes with caching and corresponding handlers.
 */
final class QueryGenerateCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'module:query
                           {module : The module name}
                           {query : The query name}
                           {--handler : Generate query handler}
                           {--no-cache : Skip caching implementation}
                           {--paginated : Generate paginated query}
                           {--filtered : Include filtering capabilities}
                           {--force : Overwrite existing files}
                           {--dry-run : Show what would be generated}';

    /**
     * The console command description.
     */
    protected $description = 'Generate a CQRS query with optional handler in a module';

    public function __construct(
        private readonly QueryGenerator $queryGenerator
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $moduleName = $this->argument('module');
        $queryName = $this->argument('query');

        $this->info("🔍 Generating query: {$queryName} in module {$moduleName}");

        try {
            $options = $this->buildOptions();

            if ($options['dry_run']) {
                $this->performDryRun($moduleName, $queryName, $options);
                return Command::SUCCESS;
            }

            $this->validateInput($moduleName, $queryName);

            $result = $this->queryGenerator->generate($moduleName, $queryName, $options);

            $this->displayResults($result, $queryName);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Query generation failed: {$e->getMessage()}");

            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }

    /**
     * Build options from command input.
     */
    private function buildOptions(): array
    {
        return [
            'generate_handler' => $this->option('handler'),
            'enable_caching' => !$this->option('no-cache'),
            'is_paginated' => $this->option('paginated'),
            'include_filtering' => $this->option('filtered'),
            'force' => $this->option('force'),
            'dry_run' => $this->option('dry-run'),
        ];
    }

    /**
     * Perform dry run.
     */
    private function performDryRun(string $moduleName, string $queryName, array $options): void
    {
        $this->warn("🔍 DRY RUN - No files will be created");

        $files = $this->queryGenerator->getFilesToGenerate($moduleName, $queryName, $options);

        $this->info("\n📄 Files that would be generated:");
        foreach ($files as $file) {
            $this->line("  ✓ {$file}");
        }

        $this->info("\n🔧 Configuration:");
        $this->line("  • Handler: " . ($options['generate_handler'] ? 'Yes' : 'No'));
        $this->line("  • Caching: " . ($options['enable_caching'] ? 'Yes' : 'No'));
        $this->line("  • Pagination: " . ($options['is_paginated'] ? 'Yes' : 'No'));
        $this->line("  • Filtering: " . ($options['include_filtering'] ? 'Yes' : 'No'));
    }

    /**
     * Validate input parameters.
     */
    private function validateInput(string $moduleName, string $queryName): void
    {
        if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $moduleName)) {
            throw new \InvalidArgumentException('Module name must be PascalCase');
        }

        if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $queryName)) {
            throw new \InvalidArgumentException('Query name must be PascalCase');
        }

        if (!str_ends_with($queryName, 'Query')) {
            $this->warn("💡 Query name '{$queryName}' doesn't end with 'Query'. Consider using '{$queryName}Query'.");
        }

        $modulePath = base_path("Modules/{$moduleName}");
        if (!is_dir($modulePath)) {
            throw new \InvalidArgumentException("Module {$moduleName} does not exist. Run 'php artisan module:make {$moduleName}' first.");
        }
    }

    /**
     * Display generation results.
     */
    private function displayResults(array $result, string $queryName): void
    {
        $this->info("\n✅ Query {$queryName} generated successfully!");

        if (isset($result['created_files'])) {
            $this->info("\n📄 Generated files:");
            foreach ($result['created_files'] as $file) {
                $this->line("  ✓ {$file}");
            }
        }

        if (isset($result['skipped_files']) && !empty($result['skipped_files'])) {
            $this->warn("\n⚠️ Skipped files (already exist):");
            foreach ($result['skipped_files'] as $file) {
                $this->line("  - {$file}");
            }
        }

        $this->displayQueryGuidance($queryName);
        $this->displayNextSteps($queryName);
    }

    /**
     * Display query pattern guidance.
     */
    private function displayQueryGuidance(string $queryName): void
    {
        $this->info("\n📚 Query Pattern Guidance:");
        $this->line("  • Queries are read-only operations that return data");
        $this->line("  • Queries should not modify application state");
        $this->line("  • Use caching to optimize frequently accessed data");
        $this->line("  • Consider pagination for large result sets");
        $this->line("  • Implement filtering for flexible data access");
    }

    /**
     * Display next steps.
     */
    private function displayNextSteps(string $queryName): void
    {
        $this->info("\n🎯 Next Steps:");
        $this->line("  1. Define query parameters and validation rules");
        $this->line("  2. Implement data retrieval logic in the handler");
        $this->line("  3. Configure caching strategy if enabled");
        $this->line("  4. Register the handler in the service provider");
        $this->line("  5. Write unit tests for the handler");

        $this->info("\n💡 Tips:");
        $this->line("  • Keep queries focused on specific use cases");
        $this->line("  • Use read models for optimized data structures");
        $this->line("  • Consider database indexes for query performance");
        $this->line("  • Cache expensive queries with appropriate TTL");

        $this->info("\n🔗 Related Commands:");
        $this->line("  • php artisan module:command - Generate command classes");
        $this->line("  • php artisan module:projector - Generate read model projectors");
        $this->line("  • php artisan module:test - Generate test classes");
    }

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['module', InputArgument::REQUIRED, 'The module name'],
            ['query', InputArgument::REQUIRED, 'The query name'],
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['handler', null, InputOption::VALUE_NONE, 'Generate query handler'],
            ['no-cache', null, InputOption::VALUE_NONE, 'Skip caching implementation'],
            ['paginated', null, InputOption::VALUE_NONE, 'Generate paginated query'],
            ['filtered', null, InputOption::VALUE_NONE, 'Include filtering capabilities'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files'],
            ['dry-run', null, InputOption::VALUE_NONE, 'Show what would be generated'],
        ];
    }
}