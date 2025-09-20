<?php

declare(strict_types=1);

namespace LaravelModularDDD\Testing\Generators;

use Illuminate\Filesystem\Filesystem;
use LaravelModularDDD\Generators\StubProcessor;
use LaravelModularDDD\Support\ModuleRegistry;

/**
 * TestGenerator
 *
 * Main orchestrator for generating comprehensive test suites for DDD modules.
 * Creates unit, feature, and integration tests with factories and helpers.
 */
final class TestGenerator
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly StubProcessor $stubProcessor,
        private readonly ModuleRegistry $moduleRegistry,
        private readonly UnitTestGenerator $unitTestGenerator,
        private readonly FeatureTestGenerator $featureTestGenerator,
        private readonly IntegrationTestGenerator $integrationTestGenerator,
        private readonly FactoryGenerator $factoryGenerator
    ) {}

    /**
     * Generate comprehensive test suite for an aggregate.
     */
    public function generateForAggregate(string $module, string $aggregate, array $options = []): array
    {
        $results = [
            'files_created' => [],
            'warnings' => [],
        ];

        // Validate module exists
        if (!$this->moduleRegistry->hasModule($module)) {
            throw new \InvalidArgumentException("Module '{$module}' not found");
        }

        $moduleInfo = $this->moduleRegistry->getModule($module);
        $aggregatePath = $this->getAggregatePath($moduleInfo, $aggregate);

        if (!$this->filesystem->exists($aggregatePath)) {
            throw new \InvalidArgumentException("Aggregate '{$aggregate}' not found in module '{$module}'");
        }

        // Generate unit tests
        $unitTestFiles = $this->unitTestGenerator->generateAggregateTests($module, $aggregate, $options);
        $results['files_created'] = array_merge($results['files_created'], $unitTestFiles);

        // Generate feature tests
        $featureTestFiles = $this->featureTestGenerator->generateAggregateTests($module, $aggregate, $options);
        $results['files_created'] = array_merge($results['files_created'], $featureTestFiles);

        // Generate integration tests
        $integrationTestFiles = $this->integrationTestGenerator->generateAggregateTests($module, $aggregate, $options);
        $results['files_created'] = array_merge($results['files_created'], $integrationTestFiles);

        // Generate factory
        $factoryFiles = $this->factoryGenerator->generateForAggregate($module, $aggregate, $options);
        $results['files_created'] = array_merge($results['files_created'], $factoryFiles);

        return $results;
    }

    /**
     * Generate test suite for a command.
     */
    public function generateForCommand(string $module, string $command, array $options = []): array
    {
        $results = [
            'files_created' => [],
            'warnings' => [],
        ];

        // Generate command handler tests
        $commandTestFiles = $this->unitTestGenerator->generateCommandTests($module, $command, $options);
        $results['files_created'] = array_merge($results['files_created'], $commandTestFiles);

        // Generate integration tests for command flow
        $integrationTestFiles = $this->integrationTestGenerator->generateCommandTests($module, $command, $options);
        $results['files_created'] = array_merge($results['files_created'], $integrationTestFiles);

        return $results;
    }

    /**
     * Generate test suite for a query.
     */
    public function generateForQuery(string $module, string $query, array $options = []): array
    {
        $results = [
            'files_created' => [],
            'warnings' => [],
        ];

        // Generate query handler tests
        $queryTestFiles = $this->unitTestGenerator->generateQueryTests($module, $query, $options);
        $results['files_created'] = array_merge($results['files_created'], $queryTestFiles);

        // Generate feature tests for query endpoints
        $featureTestFiles = $this->featureTestGenerator->generateQueryTests($module, $query, $options);
        $results['files_created'] = array_merge($results['files_created'], $featureTestFiles);

        return $results;
    }

    /**
     * Generate comprehensive test suite for an entire module.
     */
    public function generateForModule(string $module, array $options = []): array
    {
        $results = [
            'files_created' => [],
            'warnings' => [],
            'statistics' => [
                'aggregates_tested' => 0,
                'commands_tested' => 0,
                'queries_tested' => 0,
                'factories_created' => 0,
            ],
        ];

        // Validate module exists
        if (!$this->moduleRegistry->hasModule($module)) {
            throw new \InvalidArgumentException("Module '{$module}' not found");
        }

        $moduleInfo = $this->moduleRegistry->getModule($module);
        $components = $this->analyzeModuleComponents($moduleInfo);

        // Generate tests for all aggregates
        foreach ($components['aggregates'] as $aggregate) {
            $aggregateResults = $this->generateForAggregate($module, $aggregate, $options);
            $results['files_created'] = array_merge($results['files_created'], $aggregateResults['files_created']);
            $results['warnings'] = array_merge($results['warnings'], $aggregateResults['warnings']);
            $results['statistics']['aggregates_tested']++;
        }

        // Generate tests for all commands
        foreach ($components['commands'] as $command) {
            $commandResults = $this->generateForCommand($module, $command, $options);
            $results['files_created'] = array_merge($results['files_created'], $commandResults['files_created']);
            $results['warnings'] = array_merge($results['warnings'], $commandResults['warnings']);
            $results['statistics']['commands_tested']++;
        }

        // Generate tests for all queries
        foreach ($components['queries'] as $query) {
            $queryResults = $this->generateForQuery($module, $query, $options);
            $results['files_created'] = array_merge($results['files_created'], $queryResults['files_created']);
            $results['warnings'] = array_merge($results['warnings'], $queryResults['warnings']);
            $results['statistics']['queries_tested']++;
        }

        // Generate module-level integration tests
        $moduleIntegrationTests = $this->integrationTestGenerator->generateModuleTests($module, $options);
        $results['files_created'] = array_merge($results['files_created'], $moduleIntegrationTests);

        // Generate test helper traits specific to this module
        $helperTraits = $this->generateModuleTestHelpers($module, $options);
        $results['files_created'] = array_merge($results['files_created'], $helperTraits);

        return $results;
    }

    /**
     * Generate performance tests for a module.
     */
    public function generatePerformanceTests(string $module, array $options = []): array
    {
        $results = [
            'files_created' => [],
            'warnings' => [],
        ];

        $moduleInfo = $this->moduleRegistry->getModule($module);
        $performanceTestPath = $this->getPerformanceTestPath($moduleInfo);

        $variables = [
            'module' => $module,
            'moduleNamespace' => $this->getModuleNamespace($module),
            'testNamespace' => $this->getTestNamespace($module),
        ];

        $content = $this->stubProcessor->process('test-performance', $variables);
        $this->filesystem->put($performanceTestPath, $content);
        $results['files_created'][] = $performanceTestPath;

        return $results;
    }

    /**
     * Generate test documentation for a module.
     */
    public function generateTestDocumentation(string $module, array $testResults): string
    {
        $variables = [
            'module' => $module,
            'statistics' => $testResults['statistics'] ?? [],
            'files_created' => $testResults['files_created'] ?? [],
            'test_commands' => $this->generateTestCommands($module),
        ];

        return $this->stubProcessor->process('test-documentation', $variables);
    }

    /**
     * Analyze module components to determine what needs testing.
     */
    private function analyzeModuleComponents(array $moduleInfo): array
    {
        $components = [
            'aggregates' => [],
            'commands' => [],
            'queries' => [],
            'events' => [],
            'value_objects' => [],
        ];

        $modulePath = $moduleInfo['path'];

        // Find aggregates
        $aggregatesPath = $modulePath . '/Domain/Aggregates';
        if ($this->filesystem->exists($aggregatesPath)) {
            $files = $this->filesystem->files($aggregatesPath);
            foreach ($files as $file) {
                if (str_ends_with($file->getFilename(), '.php')) {
                    $components['aggregates'][] = pathinfo($file->getFilename(), PATHINFO_FILENAME);
                }
            }
        }

        // Find commands
        $commandsPath = $modulePath . '/Application/Commands';
        if ($this->filesystem->exists($commandsPath)) {
            $files = $this->filesystem->files($commandsPath);
            foreach ($files as $file) {
                if (str_ends_with($file->getFilename(), '.php') && !str_contains($file->getFilename(), 'Handler')) {
                    $components['commands'][] = pathinfo($file->getFilename(), PATHINFO_FILENAME);
                }
            }
        }

        // Find queries
        $queriesPath = $modulePath . '/Application/Queries';
        if ($this->filesystem->exists($queriesPath)) {
            $files = $this->filesystem->files($queriesPath);
            foreach ($files as $file) {
                if (str_ends_with($file->getFilename(), '.php') && !str_contains($file->getFilename(), 'Handler')) {
                    $components['queries'][] = pathinfo($file->getFilename(), PATHINFO_FILENAME);
                }
            }
        }

        // Find events
        $eventsPath = $modulePath . '/Domain/Events';
        if ($this->filesystem->exists($eventsPath)) {
            $files = $this->filesystem->files($eventsPath);
            foreach ($files as $file) {
                if (str_ends_with($file->getFilename(), '.php')) {
                    $components['events'][] = pathinfo($file->getFilename(), PATHINFO_FILENAME);
                }
            }
        }

        // Find value objects
        $valueObjectsPath = $modulePath . '/Domain/ValueObjects';
        if ($this->filesystem->exists($valueObjectsPath)) {
            $files = $this->filesystem->files($valueObjectsPath);
            foreach ($files as $file) {
                if (str_ends_with($file->getFilename(), '.php')) {
                    $components['value_objects'][] = pathinfo($file->getFilename(), PATHINFO_FILENAME);
                }
            }
        }

        return $components;
    }

    /**
     * Generate module-specific test helper traits.
     */
    private function generateModuleTestHelpers(string $module, array $options): array
    {
        $files = [];

        $moduleInfo = $this->moduleRegistry->getModule($module);
        $helpersPath = $this->getTestHelpersPath($moduleInfo);

        // Ensure helpers directory exists
        if (!$this->filesystem->exists($helpersPath)) {
            $this->filesystem->makeDirectory($helpersPath, 0755, true);
        }

        // Generate module-specific test helper trait
        $variables = [
            'module' => $module,
            'moduleNamespace' => $this->getModuleNamespace($module),
            'testNamespace' => $this->getTestNamespace($module),
        ];

        $helperContent = $this->stubProcessor->process('test-helper-trait', $variables);
        $helperFile = $helpersPath . '/Tests' . $module . '.php';
        $this->filesystem->put($helperFile, $helperContent);
        $files[] = $helperFile;

        return $files;
    }

    /**
     * Get aggregate file path.
     */
    private function getAggregatePath(array $moduleInfo, string $aggregate): string
    {
        return $moduleInfo['path'] . '/Domain/Aggregates/' . $aggregate . '.php';
    }

    /**
     * Get performance test path.
     */
    private function getPerformanceTestPath(array $moduleInfo): string
    {
        $testsPath = $moduleInfo['path'] . '/Tests/Performance';
        if (!$this->filesystem->exists($testsPath)) {
            $this->filesystem->makeDirectory($testsPath, 0755, true);
        }
        return $testsPath . '/' . $moduleInfo['name'] . 'PerformanceTest.php';
    }

    /**
     * Get test helpers path.
     */
    private function getTestHelpersPath(array $moduleInfo): string
    {
        $helpersPath = $moduleInfo['path'] . '/Tests/Helpers';
        if (!$this->filesystem->exists($helpersPath)) {
            $this->filesystem->makeDirectory($helpersPath, 0755, true);
        }
        return $helpersPath;
    }

    /**
     * Get module namespace.
     */
    private function getModuleNamespace(string $module): string
    {
        return "Modules\\{$module}";
    }

    /**
     * Get test namespace for module.
     */
    private function getTestNamespace(string $module): string
    {
        return "Modules\\{$module}\\Tests";
    }

    /**
     * Generate test commands for documentation.
     */
    private function generateTestCommands(string $module): array
    {
        return [
            "# Run all tests for {$module} module",
            "php artisan test Modules/{$module}/Tests",
            "",
            "# Run only unit tests",
            "php artisan test Modules/{$module}/Tests/Unit",
            "",
            "# Run only feature tests",
            "php artisan test Modules/{$module}/Tests/Feature",
            "",
            "# Run only integration tests",
            "php artisan test Modules/{$module}/Tests/Integration",
            "",
            "# Run performance tests",
            "php artisan test Modules/{$module}/Tests/Performance",
            "",
            "# Run tests with coverage",
            "php artisan test Modules/{$module}/Tests --coverage",
        ];
    }
}