<?php

declare(strict_types=1);

namespace LaravelModularDDD\Console\Commands;

use Illuminate\Console\Command;
use LaravelModularDDD\Generators\AggregateGenerator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * AggregateGenerateCommand
 *
 * Artisan command to generate aggregate components.
 * Creates aggregate root, value objects, and domain events.
 */
final class AggregateGenerateCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'modular:make:aggregate
                           {module : The module name}
                           {aggregate : The aggregate name}
                           {--force : Overwrite existing files}
                           {--with-events= : Comma-separated list of events to generate}
                           {--with-value-objects= : Comma-separated list of value objects to generate}
                           {--no-exception : Skip exception class generation}
                           {--dry-run : Show what would be generated}';

    /**
     * The console command description.
     */
    protected $description = 'Generate an aggregate root with related components in a module';

    public function __construct(
        private readonly AggregateGenerator $aggregateGenerator
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

        $this->info("🔨 Generating aggregate: {$aggregateName} in module {$moduleName}");

        try {
            $options = $this->buildOptions();

            if ($options['dry_run']) {
                $this->performDryRun($moduleName, $aggregateName, $options);
                return Command::SUCCESS;
            }

            $this->validateInput($moduleName, $aggregateName);

            $result = $this->aggregateGenerator->generate($moduleName, $aggregateName, $options);

            $this->displayResults($result, $aggregateName);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Aggregate generation failed: {$e->getMessage()}");

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
        $events = $this->option('with-events');
        $valueObjects = $this->option('with-value-objects');

        return [
            'force' => $this->option('force'),
            'generate_exception' => !$this->option('no-exception'),
            'events' => $events ? array_map('trim', explode(',', $events)) : [],
            'value_objects' => $valueObjects ? array_map('trim', explode(',', $valueObjects)) : [],
            'dry_run' => $this->option('dry-run'),
        ];
    }

    /**
     * Perform dry run.
     */
    private function performDryRun(string $moduleName, string $aggregateName, array $options): void
    {
        $this->warn("🔍 DRY RUN - No files will be created");

        $files = $this->aggregateGenerator->getFilesToGenerate($moduleName, $aggregateName, $options);

        $this->info("\n📄 Files that would be generated:");
        foreach ($files as $category => $categoryFiles) {
            $this->line("\n  {$category}:");
            foreach ($categoryFiles as $file) {
                $this->line("    ✓ {$file}");
            }
        }
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
    }

    /**
     * Display generation results.
     */
    private function displayResults(array $result, string $aggregateName): void
    {
        $this->info("\n✅ Aggregate {$aggregateName} generated successfully!");

        if (isset($result['created_files'])) {
            $this->info("\n📄 Generated files:");
            foreach ($result['created_files'] as $category => $files) {
                $this->line("\n  {$category}:");
                foreach ($files as $file) {
                    $this->line("    ✓ {$file}");
                }
            }
        }

        if (isset($result['skipped_files']) && !empty($result['skipped_files'])) {
            $this->warn("\n⚠️ Skipped files (already exist):");
            foreach ($result['skipped_files'] as $file) {
                $this->line("  - {$file}");
            }
        }

        $this->displayNextSteps($aggregateName);
    }

    /**
     * Display next steps.
     */
    private function displayNextSteps(string $aggregateName): void
    {
        $this->info("\n🎯 Next Steps:");
        $this->line("  1. Implement business logic in {$aggregateName} aggregate");
        $this->line("  2. Define domain events for state changes");
        $this->line("  3. Add validation rules to value objects");
        $this->line("  4. Create command and query handlers");
        $this->line("  5. Write unit tests for domain logic");

        $this->info("\n💡 Tips:");
        $this->line("  • Keep business rules in the aggregate root");
        $this->line("  • Use value objects for complex data validation");
        $this->line("  • Emit domain events for significant state changes");
        $this->line("  • Test business rules thoroughly");
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
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files'],
            ['with-events', null, InputOption::VALUE_OPTIONAL, 'Comma-separated list of events'],
            ['with-value-objects', null, InputOption::VALUE_OPTIONAL, 'Comma-separated list of value objects'],
            ['no-exception', null, InputOption::VALUE_NONE, 'Skip exception class generation'],
            ['dry-run', null, InputOption::VALUE_NONE, 'Show what would be generated'],
        ];
    }
}