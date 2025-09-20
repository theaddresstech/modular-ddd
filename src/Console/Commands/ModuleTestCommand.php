<?php

declare(strict_types=1);

namespace LaravelModularDDD\Console\Commands;

use Illuminate\Console\Command;
use LaravelModularDDD\Support\ModuleRegistry;
use LaravelModularDDD\Testing\Generators\TestGenerator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * ModuleTestCommand
 *
 * Generates comprehensive test suites for modules using the test-framework-generator.
 * Creates unit, feature, and integration tests with factories and helpers.
 */
final class ModuleTestCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'module:test
                           {module? : The module to generate tests for (optional, generates for all if not specified)}
                           {--type=all : Type of tests to generate (unit, feature, integration, factories, all)}
                           {--aggregate= : Specific aggregate to generate tests for}
                           {--command= : Specific command to generate tests for}
                           {--query= : Specific query to generate tests for}
                           {--force : Overwrite existing test files}
                           {--performance : Include performance tests}
                           {--coverage : Generate coverage analysis}';

    /**
     * The console command description.
     */
    protected $description = 'Generate comprehensive test suites for DDD modules';

    public function __construct(
        private readonly ModuleRegistry $moduleRegistry,
        private readonly TestGenerator $testGenerator
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
            $testType = $this->option('type');

            if ($moduleName) {
                return $this->generateTestsForModule($moduleName, $testType);
            }

            return $this->generateTestsForAllModules($testType);

        } catch (\Exception $e) {
            $this->error("Test generation failed: {$e->getMessage()}");

            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }

    /**
     * Generate tests for a specific module.
     */
    private function generateTestsForModule(string $moduleName, string $testType): int
    {
        if (!$this->moduleRegistry->hasModule($moduleName)) {
            $this->error("Module '{$moduleName}' not found.");
            return Command::FAILURE;
        }

        $this->info("🧪 Generating tests for module: {$moduleName}");

        $options = $this->buildTestOptions();

        if ($this->option('aggregate')) {
            return $this->generateAggregateTests($moduleName, $this->option('aggregate'), $options);
        }

        if ($this->option('command')) {
            return $this->generateCommandTests($moduleName, $this->option('command'), $options);
        }

        if ($this->option('query')) {
            return $this->generateQueryTests($moduleName, $this->option('query'), $options);
        }

        return $this->generateModuleTests($moduleName, $testType, $options);
    }

    /**
     * Generate tests for all modules.
     */
    private function generateTestsForAllModules(string $testType): int
    {
        $modules = $this->moduleRegistry->getRegisteredModules();

        if ($modules->isEmpty()) {
            $this->info("✅ No modules found to generate tests for.");
            return Command::SUCCESS;
        }

        $this->info("🧪 Generating tests for all modules...");

        $options = $this->buildTestOptions();
        $results = [];
        $totalFiles = 0;

        foreach ($modules as $module) {
            $this->line("  📦 Generating tests for {$module['name']}...");

            try {
                $result = $this->generateModuleTests($module['name'], $testType, $options);
                $results[$module['name']] = $result;
                $totalFiles += count($result['files_created'] ?? []);
            } catch (\Exception $e) {
                $this->warn("  ⚠️ Failed to generate tests for {$module['name']}: {$e->getMessage()}");
                $results[$module['name']] = ['error' => $e->getMessage()];
            }
        }

        $this->displayAllTestResults($results, $totalFiles);

        return Command::SUCCESS;
    }

    /**
     * Generate tests for a specific aggregate.
     */
    private function generateAggregateTests(string $module, string $aggregate, array $options): int
    {
        $this->line("  🏗️ Generating aggregate tests for {$aggregate}...");

        $result = $this->testGenerator->generateForAggregate($module, $aggregate, $options);

        $this->displayTestResults($module, $result, 'aggregate');

        return Command::SUCCESS;
    }

    /**
     * Generate tests for a specific command.
     */
    private function generateCommandTests(string $module, string $command, array $options): int
    {
        $this->line("  ⚡ Generating command tests for {$command}...");

        $result = $this->testGenerator->generateForCommand($module, $command, $options);

        $this->displayTestResults($module, $result, 'command');

        return Command::SUCCESS;
    }

    /**
     * Generate tests for a specific query.
     */
    private function generateQueryTests(string $module, string $query, array $options): int
    {
        $this->line("  🔍 Generating query tests for {$query}...");

        $result = $this->testGenerator->generateForQuery($module, $query, $options);

        $this->displayTestResults($module, $result, 'query');

        return Command::SUCCESS;
    }

    /**
     * Generate comprehensive tests for a module.
     */
    private function generateModuleTests(string $moduleName, string $testType, array $options): array
    {
        switch ($testType) {
            case 'unit':
                $this->line("  🔬 Generating unit tests...");
                return $this->generateUnitTests($moduleName, $options);

            case 'feature':
                $this->line("  🌟 Generating feature tests...");
                return $this->generateFeatureTests($moduleName, $options);

            case 'integration':
                $this->line("  🔗 Generating integration tests...");
                return $this->generateIntegrationTests($moduleName, $options);

            case 'factories':
                $this->line("  🏭 Generating test factories...");
                return $this->generateTestFactories($moduleName, $options);

            case 'all':
            default:
                $this->line("  🎯 Generating comprehensive test suite...");
                return $this->testGenerator->generateForModule($moduleName, $options);
        }
    }

    /**
     * Generate only unit tests.
     */
    private function generateUnitTests(string $module, array $options): array
    {
        // Get module components and generate unit tests
        $moduleInfo = $this->moduleRegistry->getModule($module);
        $components = $this->analyzeModuleComponents($moduleInfo);

        $result = ['files_created' => [], 'warnings' => []];

        foreach ($components['aggregates'] as $aggregate) {
            $aggregateResult = $this->testGenerator->generateForAggregate($module, $aggregate, $options);
            $result['files_created'] = array_merge($result['files_created'], $aggregateResult['files_created']);
        }

        return $result;
    }

    /**
     * Generate only feature tests.
     */
    private function generateFeatureTests(string $module, array $options): array
    {
        $moduleInfo = $this->moduleRegistry->getModule($module);
        $components = $this->analyzeModuleComponents($moduleInfo);

        $result = ['files_created' => [], 'warnings' => []];

        foreach ($components['aggregates'] as $aggregate) {
            // Generate feature tests for aggregates
            $featureTestFiles = $this->generateAggregateFeatureTests($module, $aggregate, $options);
            $result['files_created'] = array_merge($result['files_created'], $featureTestFiles);
        }

        return $result;
    }

    /**
     * Generate only integration tests.
     */
    private function generateIntegrationTests(string $module, array $options): array
    {
        $moduleInfo = $this->moduleRegistry->getModule($module);
        $components = $this->analyzeModuleComponents($moduleInfo);

        $result = ['files_created' => [], 'warnings' => []];

        foreach ($components['aggregates'] as $aggregate) {
            // Generate integration tests for aggregates
            $integrationTestFiles = $this->generateAggregateIntegrationTests($module, $aggregate, $options);
            $result['files_created'] = array_merge($result['files_created'], $integrationTestFiles);
        }

        return $result;
    }

    /**
     * Generate only test factories.
     */
    private function generateTestFactories(string $module, array $options): array
    {
        $moduleInfo = $this->moduleRegistry->getModule($module);
        $components = $this->analyzeModuleComponents($moduleInfo);

        $result = ['files_created' => [], 'warnings' => []];

        foreach ($components['aggregates'] as $aggregate) {
            $factoryResult = $this->testGenerator->generateForAggregate($module, $aggregate, $options);
            // Filter only factory files
            $factoryFiles = array_filter($factoryResult['files_created'], fn($file) => str_contains($file, 'Factory'));
            $result['files_created'] = array_merge($result['files_created'], $factoryFiles);
        }

        return $result;
    }

    /**
     * Build test generation options from command options.
     */
    private function buildTestOptions(): array
    {
        return [
            'force' => $this->option('force'),
            'include_performance' => $this->option('performance'),
            'include_coverage' => $this->option('coverage'),
            'test_type' => $this->option('type'),
        ];
    }

    /**
     * Display test generation results for a single component.
     */
    private function displayTestResults(string $module, array $result, string $component): void
    {
        $this->info("\n✅ Test generation completed for {$component} in {$module}");

        if (isset($result['files_created']) && !empty($result['files_created'])) {
            $this->info("\n📄 Files created:");
            foreach ($result['files_created'] as $file) {
                $this->line("  ✓ {$file}");
            }
        }

        if (isset($result['warnings']) && !empty($result['warnings'])) {
            $this->warn("\n⚠️ Warnings:");
            foreach ($result['warnings'] as $warning) {
                $this->line("  • {$warning}");
            }
        }

        $this->displayTestNextSteps($module, $component);
    }

    /**
     * Display test generation results for all modules.
     */
    private function displayAllTestResults(array $results, int $totalFiles): void
    {
        $successCount = 0;
        $failureCount = 0;

        foreach ($results as $module => $result) {
            if (isset($result['error'])) {
                $failureCount++;
            } else {
                $successCount++;
            }
        }

        $this->info("\n✅ Test generation completed");
        $this->info("📊 Summary:");
        $this->line("  Modules processed: " . count($results));
        $this->line("  Successful: {$successCount}");
        $this->line("  Failed: {$failureCount}");
        $this->line("  Total test files created: {$totalFiles}");

        $this->displayGlobalTestNextSteps();
    }

    /**
     * Display next steps for specific component.
     */
    private function displayTestNextSteps(string $module, string $component): void
    {
        $this->info("\n🎯 Next Steps:");
        $this->line("  1. Review the generated test files");
        $this->line("  2. Customize test scenarios as needed");
        $this->line("  3. Run the tests: php artisan test Modules/{$module}/Tests");
        $this->line("  4. Check test coverage: php artisan test --coverage");

        if ($component === 'aggregate') {
            $this->line("  5. Verify business logic tests cover all scenarios");
        } elseif ($component === 'command') {
            $this->line("  5. Test command validation and side effects");
        } elseif ($component === 'query') {
            $this->line("  5. Verify query performance and caching");
        }
    }

    /**
     * Display global next steps.
     */
    private function displayGlobalTestNextSteps(): void
    {
        $this->info("\n🎯 Next Steps:");
        $this->line("  1. Run all tests: php artisan test");
        $this->line("  2. Generate coverage report: php artisan test --coverage-html coverage");
        $this->line("  3. Set up CI/CD test automation");
        $this->line("  4. Configure test database seeding");

        $this->info("\n💡 Testing Tips:");
        $this->line("  • Use factories for consistent test data");
        $this->line("  • Test both happy paths and edge cases");
        $this->line("  • Mock external dependencies in unit tests");
        $this->line("  • Use feature tests for complete workflows");
        $this->line("  • Keep tests fast and independent");
    }

    /**
     * Analyze module components (simplified version).
     */
    private function analyzeModuleComponents(array $moduleInfo): array
    {
        // This would analyze the actual module structure
        // For now, return mock data
        return [
            'aggregates' => ['User', 'Order', 'Product'],
            'commands' => ['CreateUser', 'UpdateUser'],
            'queries' => ['GetUser', 'ListUsers'],
        ];
    }

    /**
     * Generate feature tests for aggregate (placeholder).
     */
    private function generateAggregateFeatureTests(string $module, string $aggregate, array $options): array
    {
        // Implementation would use FeatureTestGenerator
        return [];
    }

    /**
     * Generate integration tests for aggregate (placeholder).
     */
    private function generateAggregateIntegrationTests(string $module, string $aggregate, array $options): array
    {
        // Implementation would use IntegrationTestGenerator
        return [];
    }

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['module', InputArgument::OPTIONAL, 'The module to generate tests for'],
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['type', null, InputOption::VALUE_OPTIONAL, 'Type of tests to generate', 'all'],
            ['aggregate', null, InputOption::VALUE_OPTIONAL, 'Specific aggregate to generate tests for'],
            ['command', null, InputOption::VALUE_OPTIONAL, 'Specific command to generate tests for'],
            ['query', null, InputOption::VALUE_OPTIONAL, 'Specific query to generate tests for'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite existing test files'],
            ['performance', null, InputOption::VALUE_NONE, 'Include performance tests'],
            ['coverage', null, InputOption::VALUE_NONE, 'Generate coverage analysis'],
        ];
    }
}