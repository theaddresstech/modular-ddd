<?php

declare(strict_types=1);

namespace LaravelModularDDD\Console\Commands;

use Illuminate\Console\Command;
use LaravelModularDDD\Generators\RepositoryGenerator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * RepositoryGenerateCommand
 *
 * Artisan command to generate repository interfaces and implementations.
 * Creates both contract interfaces and event-sourced implementations.
 */
final class RepositoryGenerateCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'module:repository
                           {module : The module name}
                           {aggregate : The aggregate name}
                           {--interface-only : Generate only the repository interface}
                           {--implementation-only : Generate only the repository implementation}
                           {--eloquent : Generate Eloquent-based implementation}
                           {--event-sourced : Generate event-sourced implementation (default)}
                           {--force : Overwrite existing files}
                           {--dry-run : Show what would be generated}';

    /**
     * The console command description.
     */
    protected $description = 'Generate repository interface and implementation for an aggregate';

    public function __construct(
        private readonly RepositoryGenerator $repositoryGenerator
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $moduleName = $this->argument('module');
        $aggregateName = $this->argument('aggregate');

        $this->info("🗄️ Generating repository for {$aggregateName} in module {$moduleName}");

        try {
            $options = $this->buildOptions();

            if ($options['dry_run']) {
                $this->performDryRun($moduleName, $aggregateName, $options);
                return Command::SUCCESS;
            }

            $this->validateInput($moduleName, $aggregateName);

            $result = $this->repositoryGenerator->generate($moduleName, $aggregateName, $options);

            $this->displayResults($result, $aggregateName);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Repository generation failed: {$e->getMessage()}");

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
        $implementationType = 'event_sourced'; // default

        if ($this->option('eloquent')) {
            $implementationType = 'eloquent';
        }

        return [
            'generate_interface' => !$this->option('implementation-only'),
            'generate_implementation' => !$this->option('interface-only'),
            'implementation_type' => $implementationType,
            'force' => $this->option('force'),
            'dry_run' => $this->option('dry-run'),
        ];
    }

    /**
     * Perform dry run.
     */
    private function performDryRun(string $moduleName, string $aggregateName, array $options): void
    {
        $this->warn("🔍 DRY RUN - No files will be created");

        $files = $this->repositoryGenerator->getFilesToGenerate($moduleName, $aggregateName, $options);

        $this->info("\n📄 Files that would be generated:");
        foreach ($files as $file) {
            $this->line("  ✓ {$file}");
        }

        $this->info("\n🔧 Configuration:");
        $this->line("  • Interface: " . ($options['generate_interface'] ? 'Yes' : 'No'));
        $this->line("  • Implementation: " . ($options['generate_implementation'] ? 'Yes' : 'No'));
        $this->line("  • Type: " . ucfirst(str_replace('_', ' ', $options['implementation_type'])));
    }

    /**
     * Validate input parameters.
     */
    private function validateInput(string $moduleName, string $aggregateName): void
    {
        if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $moduleName)) {
            throw new \InvalidArgumentException('Module name must be PascalCase');
        }

        if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $aggregateName)) {
            throw new \InvalidArgumentException('Aggregate name must be PascalCase');
        }

        $modulePath = base_path("Modules/{$moduleName}");
        if (!is_dir($modulePath)) {
            throw new \InvalidArgumentException("Module {$moduleName} does not exist. Run 'php artisan module:make {$moduleName}' first.");
        }

        // Check if aggregate exists
        $aggregatePath = "{$modulePath}/Domain/Aggregates/{$aggregateName}.php";
        if (!file_exists($aggregatePath)) {
            $this->warn("⚠️ Aggregate {$aggregateName} does not exist. Consider running 'php artisan module:aggregate {$moduleName} {$aggregateName}' first.");
        }
    }

    /**
     * Display generation results.
     */
    private function displayResults(array $result, string $aggregateName): void
    {
        $this->info("\n✅ Repository for {$aggregateName} generated successfully!");

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

        $this->displayRepositoryGuidance();
        $this->displayNextSteps($aggregateName);
    }

    /**
     * Display repository pattern guidance.
     */
    private function displayRepositoryGuidance(): void
    {
        $this->info("\n📚 Repository Pattern Guidance:");
        $this->line("  • Repositories abstract data access from business logic");
        $this->line("  • Use interfaces to decouple domain from infrastructure");
        $this->line("  • Event-sourced repositories store events, not state");
        $this->line("  • Implement optimistic concurrency control");
        $this->line("  • Keep repository methods focused on aggregate operations");
    }

    /**
     * Display next steps.
     */
    private function displayNextSteps(string $aggregateName): void
    {
        $this->info("\n🎯 Next Steps:");
        $this->line("  1. Bind repository interface to implementation in service provider");
        $this->line("  2. Implement custom query methods if needed");
        $this->line("  3. Configure event store connection settings");
        $this->line("  4. Add repository methods to aggregate service layer");
        $this->line("  5. Write integration tests for repository operations");

        $this->info("\n💡 Tips:");
        $this->line("  • Keep repositories aggregate-focused");
        $this->line("  • Use repository for aggregate root access only");
        $this->line("  • Implement proper exception handling");
        $this->line("  • Consider using specifications for complex queries");

        $this->info("\n🔗 Service Provider Binding:");
        $this->line("  Add to your service provider's register() method:");
        $this->line("  \$this->app->bind(");
        $this->line("      {$aggregateName}RepositoryInterface::class,");
        $this->line("      EventSourced{$aggregateName}Repository::class");
        $this->line("  );");
    }

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['module', InputArgument::REQUIRED, 'The module name'],
            ['aggregate', InputArgument::REQUIRED, 'The aggregate name'],
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['interface-only', null, InputOption::VALUE_NONE, 'Generate only the repository interface'],
            ['implementation-only', null, InputOption::VALUE_NONE, 'Generate only the repository implementation'],
            ['eloquent', null, InputOption::VALUE_NONE, 'Generate Eloquent-based implementation'],
            ['event-sourced', null, InputOption::VALUE_NONE, 'Generate event-sourced implementation'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files'],
            ['dry-run', null, InputOption::VALUE_NONE, 'Show what would be generated'],
        ];
    }
}