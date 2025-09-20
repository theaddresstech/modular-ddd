<?php

declare(strict_types=1);

namespace LaravelModularDDD\Console\Commands;

use Illuminate\Console\Command;
use LaravelModularDDD\Support\ModuleRegistry;
use LaravelModularDDD\Documentation\DocumentationGenerator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * ModuleDocumentationCommand
 *
 * Generates comprehensive documentation for modules.
 * Creates README files, API documentation, and architectural diagrams.
 */
final class ModuleDocumentationCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'modular:docs
                           {module? : The module to document (optional, documents all if not specified)}
                           {--format=markdown : Output format (markdown, html, json)}
                           {--output= : Output directory (defaults to docs/)}
                           {--include-api : Include API documentation}
                           {--include-diagrams : Generate architectural diagrams}
                           {--include-tests : Include test documentation}
                           {--force : Overwrite existing documentation}';

    /**
     * The console command description.
     */
    protected $description = 'Generate comprehensive documentation for modules';

    public function __construct(
        private readonly ModuleRegistry $moduleRegistry,
        private readonly DocumentationGenerator $documentationGenerator
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
                return $this->documentModule($moduleName);
            }

            return $this->documentAllModules();

        } catch (\Exception $e) {
            $this->error("Documentation generation failed: {$e->getMessage()}");

            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }

    /**
     * Document a specific module.
     */
    private function documentModule(string $moduleName): int
    {
        if (!$this->moduleRegistry->hasModule($moduleName)) {
            $this->error("Module '{$moduleName}' not found.");
            return Command::FAILURE;
        }

        $module = $this->moduleRegistry->getModule($moduleName);

        $this->info("📖 Generating documentation for module: {$moduleName}");

        $options = $this->buildDocumentationOptions();
        $result = $this->documentationGenerator->generateModuleDocumentation($module, $options);

        $this->displayDocumentationResults($moduleName, $result);

        return Command::SUCCESS;
    }

    /**
     * Document all modules.
     */
    private function documentAllModules(): int
    {
        $modules = $this->moduleRegistry->getRegisteredModules();

        if ($modules->isEmpty()) {
            $this->info("✅ No modules found to document.");
            return Command::SUCCESS;
        }

        $this->info("📖 Generating documentation for all modules...");

        $options = $this->buildDocumentationOptions();
        $results = [];

        // Generate individual module documentation
        foreach ($modules as $module) {
            $this->line("  📦 Documenting {$module['name']}...");
            $result = $this->documentationGenerator->generateModuleDocumentation($module, $options);
            $results[$module['name']] = $result;
        }

        // Generate overview documentation
        $this->line("  📊 Generating system overview...");
        $overviewResult = $this->documentationGenerator->generateSystemOverview($modules, $options);
        $results['_overview'] = $overviewResult;

        $this->displayAllDocumentationResults($results);

        return Command::SUCCESS;
    }

    /**
     * Build documentation options from command options.
     */
    private function buildDocumentationOptions(): array
    {
        return [
            'format' => $this->option('format'),
            'output_directory' => $this->option('output') ?: 'docs',
            'include_api' => $this->option('include-api'),
            'include_diagrams' => $this->option('include-diagrams'),
            'include_tests' => $this->option('include-tests'),
            'force' => $this->option('force'),
        ];
    }

    /**
     * Display documentation results for a single module.
     */
    private function displayDocumentationResults(string $moduleName, array $result): void
    {
        $this->info("\n✅ Documentation generated for {$moduleName}");

        if (isset($result['files_created'])) {
            $this->info("\n📄 Files created:");
            foreach ($result['files_created'] as $file) {
                $this->line("  ✓ {$file}");
            }
        }

        if (isset($result['files_updated'])) {
            $this->info("\n📝 Files updated:");
            foreach ($result['files_updated'] as $file) {
                $this->line("  ✓ {$file}");
            }
        }

        if (isset($result['warnings'])) {
            $this->warn("\n⚠️ Warnings:");
            foreach ($result['warnings'] as $warning) {
                $this->line("  • {$warning}");
            }
        }

        $this->displayNextSteps($moduleName, $result);
    }

    /**
     * Display documentation results for all modules.
     */
    private function displayAllDocumentationResults(array $results): void
    {
        $totalFiles = 0;
        $totalWarnings = 0;

        foreach ($results as $moduleName => $result) {
            $totalFiles += count($result['files_created'] ?? []) + count($result['files_updated'] ?? []);
            $totalWarnings += count($result['warnings'] ?? []);
        }

        $this->info("\n✅ Documentation generation completed");
        $this->info("📊 Summary:");
        $this->line("  Modules documented: " . (count($results) - 1)); // -1 for overview
        $this->line("  Total files: {$totalFiles}");

        if ($totalWarnings > 0) {
            $this->warn("  Warnings: {$totalWarnings}");
        }

        $overviewResult = $results['_overview'] ?? [];
        if (isset($overviewResult['overview_file'])) {
            $this->info("\n📋 System overview: {$overviewResult['overview_file']}");
        }

        $this->displayGlobalNextSteps($results);
    }

    /**
     * Display next steps for a specific module.
     */
    private function displayNextSteps(string $moduleName, array $result): void
    {
        $this->info("\n🎯 Next Steps:");
        $this->line("  1. Review the generated documentation");
        $this->line("  2. Customize the content as needed");

        if (isset($result['api_docs'])) {
            $this->line("  3. Verify API documentation accuracy");
        }

        if (isset($result['diagrams'])) {
            $this->line("  4. Check architectural diagrams");
        }

        $this->line("  5. Share documentation with your team");

        $this->info("\n💡 Tips:");
        $this->line("  • Use --include-api to generate API docs");
        $this->line("  • Use --include-diagrams for visual architecture");
        $this->line("  • Documentation is automatically updated when you run this command again");
    }

    /**
     * Display global next steps.
     */
    private function displayGlobalNextSteps(array $results): void
    {
        $this->info("\n🎯 Next Steps:");
        $this->line("  1. Review the system overview documentation");
        $this->line("  2. Set up a documentation site (GitBook, Docsify, etc.)");
        $this->line("  3. Configure CI/CD to auto-update docs");
        $this->line("  4. Share documentation links with stakeholders");

        $this->info("\n🌐 Publishing Options:");
        $this->line("  • GitHub Pages: Automatically publish from docs/ folder");
        $this->line("  • GitBook: Import markdown files");
        $this->line("  • Confluence: Convert and upload documentation");
        $this->line("  • Internal wiki: Copy content to your company wiki");

        $outputDir = $this->option('output') ?: 'docs';
        $this->info("\n📂 Documentation location: {$outputDir}/");
    }

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['module', InputArgument::OPTIONAL, 'The module to document'],
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['format', null, InputOption::VALUE_OPTIONAL, 'Output format (markdown, html, json)', 'markdown'],
            ['output', 'o', InputOption::VALUE_OPTIONAL, 'Output directory'],
            ['include-api', null, InputOption::VALUE_NONE, 'Include API documentation'],
            ['include-diagrams', null, InputOption::VALUE_NONE, 'Generate architectural diagrams'],
            ['include-tests', null, InputOption::VALUE_NONE, 'Include test documentation'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite existing documentation'],
        ];
    }
}