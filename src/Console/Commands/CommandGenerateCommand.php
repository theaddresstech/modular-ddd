<?php

declare(strict_types=1);

namespace LaravelModularDDD\Console\Commands;

use Illuminate\Console\Command;
use LaravelModularDDD\Generators\CommandGenerator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * CommandGenerateCommand
 *
 * Artisan command to generate CQRS commands and handlers.
 * Creates command classes with validation and corresponding handlers.
 */
final class CommandGenerateCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'module:command
                           {module : The module name}
                           {command : The command name}
                           {--aggregate= : The target aggregate name}
                           {--handler : Generate command handler}
                           {--no-validation : Skip validation rules}
                           {--async : Generate async command handler}
                           {--force : Overwrite existing files}
                           {--dry-run : Show what would be generated}';

    /**
     * The console command description.
     */
    protected $description = 'Generate a CQRS command with optional handler in a module';

    public function __construct(
        private readonly CommandGenerator $commandGenerator
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $moduleName = $this->argument('module');
        $commandName = $this->argument('command');

        $this->info("⚡ Generating command: {$commandName} in module {$moduleName}");

        try {
            $options = $this->buildOptions();

            if ($options['dry_run']) {
                $this->performDryRun($moduleName, $commandName, $options);
                return Command::SUCCESS;
            }

            $this->validateInput($moduleName, $commandName);

            $result = $this->commandGenerator->generate($moduleName, $commandName, $options);

            $this->displayResults($result, $commandName);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Command generation failed: {$e->getMessage()}");

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
            'aggregate' => $this->option('aggregate'),
            'generate_handler' => $this->option('handler'),
            'include_validation' => !$this->option('no-validation'),
            'async_handler' => $this->option('async'),
            'force' => $this->option('force'),
            'dry_run' => $this->option('dry-run'),
        ];
    }

    /**
     * Perform dry run.
     */
    private function performDryRun(string $moduleName, string $commandName, array $options): void
    {
        $this->warn("🔍 DRY RUN - No files will be created");

        $files = $this->commandGenerator->getFilesToGenerate($moduleName, $commandName, $options);

        $this->info("\n📄 Files that would be generated:");
        foreach ($files as $file) {
            $this->line("  ✓ {$file}");
        }

        $this->info("\n🔧 Configuration:");
        $this->line("  • Aggregate: " . ($options['aggregate'] ?? 'Not specified'));
        $this->line("  • Handler: " . ($options['generate_handler'] ? 'Yes' : 'No'));
        $this->line("  • Validation: " . ($options['include_validation'] ? 'Yes' : 'No'));
        $this->line("  • Async: " . ($options['async_handler'] ? 'Yes' : 'No'));
    }

    /**
     * Validate input parameters.
     */
    private function validateInput(string $moduleName, string $commandName): void
    {
        if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $moduleName)) {
            throw new \InvalidArgumentException('Module name must be PascalCase');
        }

        if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $commandName)) {
            throw new \InvalidArgumentException('Command name must be PascalCase');
        }

        if (!str_ends_with($commandName, 'Command')) {
            $this->warn("💡 Command name '{$commandName}' doesn't end with 'Command'. Consider using '{$commandName}Command'.");
        }

        $modulePath = base_path("Modules/{$moduleName}");
        if (!is_dir($modulePath)) {
            throw new \InvalidArgumentException("Module {$moduleName} does not exist. Run 'php artisan module:make {$moduleName}' first.");
        }
    }

    /**
     * Display generation results.
     */
    private function displayResults(array $result, string $commandName): void
    {
        $this->info("\n✅ Command {$commandName} generated successfully!");

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

        $this->displayCommandGuidance($commandName);
        $this->displayNextSteps($commandName);
    }

    /**
     * Display command pattern guidance.
     */
    private function displayCommandGuidance(string $commandName): void
    {
        $this->info("\n📚 Command Pattern Guidance:");
        $this->line("  • Commands represent intentions to change state");
        $this->line("  • Commands should be immutable and contain all necessary data");
        $this->line("  • Command handlers should be focused and do one thing well");
        $this->line("  • Use validation to ensure command integrity");
        $this->line("  • Commands can be dispatched synchronously or asynchronously");
    }

    /**
     * Display next steps.
     */
    private function displayNextSteps(string $commandName): void
    {
        $this->info("\n🎯 Next Steps:");
        $this->line("  1. Implement validation rules in the command class");
        $this->line("  2. Add business logic to the command handler");
        $this->line("  3. Register the handler in the service provider");
        $this->line("  4. Write unit tests for the handler");
        $this->line("  5. Create integration tests for the complete flow");

        $this->info("\n💡 Tips:");
        $this->line("  • Keep commands simple and focused");
        $this->line("  • Validate at the command level, not in handlers");
        $this->line("  • Use result objects for handler responses");
        $this->line("  • Consider command versioning for API evolution");

        $this->info("\n🔗 Related Commands:");
        $this->line("  • php artisan module:query - Generate query classes");
        $this->line("  • php artisan module:aggregate - Generate aggregate roots");
        $this->line("  • php artisan module:test - Generate test classes");
    }

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['module', InputArgument::REQUIRED, 'The module name'],
            ['command', InputArgument::REQUIRED, 'The command name'],
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['aggregate', null, InputOption::VALUE_OPTIONAL, 'The target aggregate name'],
            ['handler', null, InputOption::VALUE_NONE, 'Generate command handler'],
            ['no-validation', null, InputOption::VALUE_NONE, 'Skip validation rules'],
            ['async', null, InputOption::VALUE_NONE, 'Generate async command handler'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files'],
            ['dry-run', null, InputOption::VALUE_NONE, 'Show what would be generated'],
        ];
    }
}