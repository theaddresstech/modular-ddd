<?php

declare(strict_types=1);

namespace LaravelModularDDD\Testing\Generators;

use Illuminate\Filesystem\Filesystem;
use LaravelModularDDD\Generators\StubProcessor;
use LaravelModularDDD\Support\ModuleRegistry;

/**
 * UnitTestGenerator
 *
 * Generates unit tests for domain components (aggregates, entities, value objects).
 * Focuses on testing business logic in isolation with proper mocking.
 */
final class UnitTestGenerator
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly StubProcessor $stubProcessor,
        private readonly ModuleRegistry $moduleRegistry
    ) {}

    /**
     * Generate unit tests for an aggregate.
     */
    public function generateAggregateTests(string $module, string $aggregate, array $options = []): array
    {
        $files = [];

        $moduleInfo = $this->moduleRegistry->getModule($module);
        $testPath = $this->getUnitTestPath($moduleInfo, 'Aggregates');

        // Ensure test directory exists
        if (!$this->filesystem->exists($testPath)) {
            $this->filesystem->makeDirectory($testPath, 0755, true);
        }

        // Analyze aggregate to identify testable methods
        $aggregateAnalysis = $this->analyzeAggregate($moduleInfo, $aggregate);

        $variables = [
            'module' => $module,
            'aggregate' => $aggregate,
            'moduleNamespace' => $this->getModuleNamespace($module),
            'testNamespace' => $this->getTestNamespace($module),
            'aggregateNamespace' => $this->getAggregateNamespace($module, $aggregate),
            'methods' => $aggregateAnalysis['methods'],
            'events' => $aggregateAnalysis['events'],
            'invariants' => $aggregateAnalysis['invariants'],
            'dependencies' => $aggregateAnalysis['dependencies'],
        ];

        $content = $this->stubProcessor->process('test-aggregate', $variables);
        $testFile = $testPath . '/' . $aggregate . 'Test.php';
        $this->filesystem->put($testFile, $content);
        $files[] = $testFile;

        // Generate specific behavior tests if aggregate has complex business logic
        if (!empty($aggregateAnalysis['behaviors'])) {
            $behaviorTestFiles = $this->generateBehaviorTests($moduleInfo, $aggregate, $aggregateAnalysis['behaviors']);
            $files = array_merge($files, $behaviorTestFiles);
        }

        return $files;
    }

    /**
     * Generate unit tests for command handlers.
     */
    public function generateCommandTests(string $module, string $command, array $options = []): array
    {
        $files = [];

        $moduleInfo = $this->moduleRegistry->getModule($module);
        $testPath = $this->getUnitTestPath($moduleInfo, 'Commands');

        // Ensure test directory exists
        if (!$this->filesystem->exists($testPath)) {
            $this->filesystem->makeDirectory($testPath, 0755, true);
        }

        // Analyze command and its handler
        $commandAnalysis = $this->analyzeCommand($moduleInfo, $command);

        $variables = [
            'module' => $module,
            'command' => $command,
            'commandHandler' => $command . 'Handler',
            'moduleNamespace' => $this->getModuleNamespace($module),
            'testNamespace' => $this->getTestNamespace($module),
            'commandNamespace' => $this->getCommandNamespace($module, $command),
            'handlerNamespace' => $this->getCommandHandlerNamespace($module, $command),
            'validation_rules' => $commandAnalysis['validation'],
            'dependencies' => $commandAnalysis['dependencies'],
            'expected_events' => $commandAnalysis['events'],
            'side_effects' => $commandAnalysis['side_effects'],
        ];

        $content = $this->stubProcessor->process('test-command', $variables);
        $testFile = $testPath . '/' . $command . 'HandlerTest.php';
        $this->filesystem->put($testFile, $content);
        $files[] = $testFile;

        // Generate command validation tests
        if (!empty($commandAnalysis['validation'])) {
            $validationTestFile = $this->generateCommandValidationTests($moduleInfo, $command, $commandAnalysis['validation']);
            $files[] = $validationTestFile;
        }

        return $files;
    }

    /**
     * Generate unit tests for query handlers.
     */
    public function generateQueryTests(string $module, string $query, array $options = []): array
    {
        $files = [];

        $moduleInfo = $this->moduleRegistry->getModule($module);
        $testPath = $this->getUnitTestPath($moduleInfo, 'Queries');

        // Ensure test directory exists
        if (!$this->filesystem->exists($testPath)) {
            $this->filesystem->makeDirectory($testPath, 0755, true);
        }

        // Analyze query and its handler
        $queryAnalysis = $this->analyzeQuery($moduleInfo, $query);

        $variables = [
            'module' => $module,
            'query' => $query,
            'queryHandler' => $query . 'Handler',
            'moduleNamespace' => $this->getModuleNamespace($module),
            'testNamespace' => $this->getTestNamespace($module),
            'queryNamespace' => $this->getQueryNamespace($module, $query),
            'handlerNamespace' => $this->getQueryHandlerNamespace($module, $query),
            'parameters' => $queryAnalysis['parameters'],
            'return_type' => $queryAnalysis['return_type'],
            'dependencies' => $queryAnalysis['dependencies'],
            'caching' => $queryAnalysis['caching'],
            'filters' => $queryAnalysis['filters'],
        ];

        $content = $this->stubProcessor->process('test-query', $variables);
        $testFile = $testPath . '/' . $query . 'HandlerTest.php';
        $this->filesystem->put($testFile, $content);
        $files[] = $testFile;

        return $files;
    }

    /**
     * Generate unit tests for value objects.
     */
    public function generateValueObjectTests(string $module, string $valueObject, array $options = []): array
    {
        $files = [];

        $moduleInfo = $this->moduleRegistry->getModule($module);
        $testPath = $this->getUnitTestPath($moduleInfo, 'ValueObjects');

        // Ensure test directory exists
        if (!$this->filesystem->exists($testPath)) {
            $this->filesystem->makeDirectory($testPath, 0755, true);
        }

        // Analyze value object
        $valueObjectAnalysis = $this->analyzeValueObject($moduleInfo, $valueObject);

        $variables = [
            'module' => $module,
            'valueObject' => $valueObject,
            'moduleNamespace' => $this->getModuleNamespace($module),
            'testNamespace' => $this->getTestNamespace($module),
            'valueObjectNamespace' => $this->getValueObjectNamespace($module, $valueObject),
            'properties' => $valueObjectAnalysis['properties'],
            'validation_rules' => $valueObjectAnalysis['validation'],
            'methods' => $valueObjectAnalysis['methods'],
            'equality_checks' => $valueObjectAnalysis['equality'],
        ];

        $content = $this->stubProcessor->process('test-value-object', $variables);
        $testFile = $testPath . '/' . $valueObject . 'Test.php';
        $this->filesystem->put($testFile, $content);
        $files[] = $testFile;

        return $files;
    }

    /**
     * Generate unit tests for domain events.
     */
    public function generateEventTests(string $module, string $event, array $options = []): array
    {
        $files = [];

        $moduleInfo = $this->moduleRegistry->getModule($module);
        $testPath = $this->getUnitTestPath($moduleInfo, 'Events');

        // Ensure test directory exists
        if (!$this->filesystem->exists($testPath)) {
            $this->filesystem->makeDirectory($testPath, 0755, true);
        }

        $variables = [
            'module' => $module,
            'event' => $event,
            'moduleNamespace' => $this->getModuleNamespace($module),
            'testNamespace' => $this->getTestNamespace($module),
            'eventNamespace' => $this->getEventNamespace($module, $event),
        ];

        $content = $this->stubProcessor->process('test-event', $variables);
        $testFile = $testPath . '/' . $event . 'Test.php';
        $this->filesystem->put($testFile, $content);
        $files[] = $testFile;

        return $files;
    }

    /**
     * Analyze aggregate to identify testable components.
     */
    private function analyzeAggregate(array $moduleInfo, string $aggregate): array
    {
        $aggregatePath = $moduleInfo['path'] . '/Domain/Aggregates/' . $aggregate . '.php';

        if (!$this->filesystem->exists($aggregatePath)) {
            throw new \InvalidArgumentException("Aggregate file not found: {$aggregatePath}");
        }

        // Basic analysis - in a real implementation, this would use reflection or AST parsing
        $content = $this->filesystem->get($aggregatePath);

        return [
            'methods' => $this->extractMethods($content),
            'events' => $this->extractEvents($content),
            'invariants' => $this->extractInvariants($content),
            'dependencies' => $this->extractDependencies($content),
            'behaviors' => $this->extractBehaviors($content),
        ];
    }

    /**
     * Analyze command for testing requirements.
     */
    private function analyzeCommand(array $moduleInfo, string $command): array
    {
        $commandPath = $moduleInfo['path'] . '/Application/Commands/' . $command . '.php';
        $handlerPath = $moduleInfo['path'] . '/Application/Commands/' . $command . 'Handler.php';

        $analysis = [
            'validation' => [],
            'dependencies' => [],
            'events' => [],
            'side_effects' => [],
        ];

        if ($this->filesystem->exists($commandPath)) {
            $commandContent = $this->filesystem->get($commandPath);
            $analysis['validation'] = $this->extractValidationRules($commandContent);
        }

        if ($this->filesystem->exists($handlerPath)) {
            $handlerContent = $this->filesystem->get($handlerPath);
            $analysis['dependencies'] = $this->extractDependencies($handlerContent);
            $analysis['events'] = $this->extractEvents($handlerContent);
            $analysis['side_effects'] = $this->extractSideEffects($handlerContent);
        }

        return $analysis;
    }

    /**
     * Analyze query for testing requirements.
     */
    private function analyzeQuery(array $moduleInfo, string $query): array
    {
        $queryPath = $moduleInfo['path'] . '/Application/Queries/' . $query . '.php';
        $handlerPath = $moduleInfo['path'] . '/Application/Queries/' . $query . 'Handler.php';

        $analysis = [
            'parameters' => [],
            'return_type' => 'mixed',
            'dependencies' => [],
            'caching' => false,
            'filters' => [],
        ];

        if ($this->filesystem->exists($queryPath)) {
            $queryContent = $this->filesystem->get($queryPath);
            $analysis['parameters'] = $this->extractParameters($queryContent);
            $analysis['filters'] = $this->extractFilters($queryContent);
        }

        if ($this->filesystem->exists($handlerPath)) {
            $handlerContent = $this->filesystem->get($handlerPath);
            $analysis['dependencies'] = $this->extractDependencies($handlerContent);
            $analysis['return_type'] = $this->extractReturnType($handlerContent);
            $analysis['caching'] = $this->detectCaching($handlerContent);
        }

        return $analysis;
    }

    /**
     * Analyze value object for testing requirements.
     */
    private function analyzeValueObject(array $moduleInfo, string $valueObject): array
    {
        $valueObjectPath = $moduleInfo['path'] . '/Domain/ValueObjects/' . $valueObject . '.php';

        if (!$this->filesystem->exists($valueObjectPath)) {
            throw new \InvalidArgumentException("Value object file not found: {$valueObjectPath}");
        }

        $content = $this->filesystem->get($valueObjectPath);

        return [
            'properties' => $this->extractProperties($content),
            'validation' => $this->extractValidationRules($content),
            'methods' => $this->extractMethods($content),
            'equality' => $this->extractEqualityMethods($content),
        ];
    }

    /**
     * Generate behavior-specific tests for complex aggregates.
     */
    private function generateBehaviorTests(array $moduleInfo, string $aggregate, array $behaviors): array
    {
        $files = [];

        foreach ($behaviors as $behavior) {
            $testPath = $this->getUnitTestPath($moduleInfo, 'Behaviors');

            if (!$this->filesystem->exists($testPath)) {
                $this->filesystem->makeDirectory($testPath, 0755, true);
            }

            $variables = [
                'module' => $moduleInfo['name'],
                'aggregate' => $aggregate,
                'behavior' => $behavior,
                'moduleNamespace' => $this->getModuleNamespace($moduleInfo['name']),
                'testNamespace' => $this->getTestNamespace($moduleInfo['name']),
            ];

            $content = $this->stubProcessor->process('test-behavior', $variables);
            $testFile = $testPath . '/' . $aggregate . $behavior . 'Test.php';
            $this->filesystem->put($testFile, $content);
            $files[] = $testFile;
        }

        return $files;
    }

    /**
     * Generate command validation tests.
     */
    private function generateCommandValidationTests(array $moduleInfo, string $command, array $validationRules): string
    {
        $testPath = $this->getUnitTestPath($moduleInfo, 'Commands/Validation');

        if (!$this->filesystem->exists($testPath)) {
            $this->filesystem->makeDirectory($testPath, 0755, true);
        }

        $variables = [
            'module' => $moduleInfo['name'],
            'command' => $command,
            'moduleNamespace' => $this->getModuleNamespace($moduleInfo['name']),
            'testNamespace' => $this->getTestNamespace($moduleInfo['name']),
            'validation_rules' => $validationRules,
        ];

        $content = $this->stubProcessor->process('test-command-validation', $variables);
        $testFile = $testPath . '/' . $command . 'ValidationTest.php';
        $this->filesystem->put($testFile, $content);

        return $testFile;
    }

    /**
     * Extract methods from class content.
     */
    private function extractMethods(string $content): array
    {
        preg_match_all('/public function (\w+)\s*\([^)]*\)/', $content, $matches);
        return $matches[1] ?? [];
    }

    /**
     * Extract events from content.
     */
    private function extractEvents(string $content): array
    {
        preg_match_all('/(\w+Event)/', $content, $matches);
        return array_unique($matches[1] ?? []);
    }

    /**
     * Extract business invariants from content.
     */
    private function extractInvariants(string $content): array
    {
        preg_match_all('/private function ensure(\w+)\s*\(/', $content, $matches);
        return $matches[1] ?? [];
    }

    /**
     * Extract dependencies from constructor or methods.
     */
    private function extractDependencies(string $content): array
    {
        preg_match_all('/private readonly (\w+)/', $content, $matches);
        return $matches[1] ?? [];
    }

    /**
     * Extract complex behaviors from aggregate.
     */
    private function extractBehaviors(string $content): array
    {
        preg_match_all('/public function (\w+)\s*\([^)]*\):\s*void/', $content, $matches);
        return array_filter($matches[1] ?? [], fn($method) => !in_array($method, ['__construct', '__destruct']));
    }

    /**
     * Extract validation rules from command or value object.
     */
    private function extractValidationRules(string $content): array
    {
        // Simple extraction - in real implementation would be more sophisticated
        if (str_contains($content, 'validate')) {
            return ['required', 'string', 'min:1'];
        }
        return [];
    }

    /**
     * Extract parameters from query or command.
     */
    private function extractParameters(string $content): array
    {
        preg_match_all('/public readonly (\w+) \$(\w+)/', $content, $matches);
        return array_combine($matches[2] ?? [], $matches[1] ?? []);
    }

    /**
     * Extract filters from query.
     */
    private function extractFilters(string $content): array
    {
        if (str_contains($content, 'filter')) {
            return ['status', 'created_at', 'type'];
        }
        return [];
    }

    /**
     * Extract return type from handler.
     */
    private function extractReturnType(string $content): string
    {
        preg_match('/public function handle\([^)]*\):\s*(\w+)/', $content, $matches);
        return $matches[1] ?? 'mixed';
    }

    /**
     * Detect caching in query handler.
     */
    private function detectCaching(string $content): bool
    {
        return str_contains($content, 'cache') || str_contains($content, 'remember');
    }

    /**
     * Extract properties from value object.
     */
    private function extractProperties(string $content): array
    {
        preg_match_all('/private readonly (\w+) \$(\w+)/', $content, $matches);
        return array_combine($matches[2] ?? [], $matches[1] ?? []);
    }

    /**
     * Extract equality methods from value object.
     */
    private function extractEqualityMethods(string $content): array
    {
        $methods = [];
        if (str_contains($content, 'equals')) {
            $methods[] = 'equals';
        }
        if (str_contains($content, '__toString')) {
            $methods[] = '__toString';
        }
        return $methods;
    }

    /**
     * Extract side effects from command handler.
     */
    private function extractSideEffects(string $content): array
    {
        $effects = [];
        if (str_contains($content, 'save')) {
            $effects[] = 'persistence';
        }
        if (str_contains($content, 'event')) {
            $effects[] = 'events';
        }
        if (str_contains($content, 'notify')) {
            $effects[] = 'notifications';
        }
        return $effects;
    }

    /**
     * Get unit test directory path.
     */
    private function getUnitTestPath(array $moduleInfo, string $subDirectory = ''): string
    {
        $basePath = $moduleInfo['path'] . '/Tests/Unit';
        return $subDirectory ? $basePath . '/' . $subDirectory : $basePath;
    }

    /**
     * Get module namespace.
     */
    private function getModuleNamespace(string $module): string
    {
        return "Modules\\{$module}";
    }

    /**
     * Get test namespace.
     */
    private function getTestNamespace(string $module): string
    {
        return "Modules\\{$module}\\Tests\\Unit";
    }

    /**
     * Get aggregate namespace.
     */
    private function getAggregateNamespace(string $module, string $aggregate): string
    {
        return "Modules\\{$module}\\Domain\\Aggregates\\{$aggregate}";
    }

    /**
     * Get command namespace.
     */
    private function getCommandNamespace(string $module, string $command): string
    {
        return "Modules\\{$module}\\Application\\Commands\\{$command}";
    }

    /**
     * Get command handler namespace.
     */
    private function getCommandHandlerNamespace(string $module, string $command): string
    {
        return "Modules\\{$module}\\Application\\Commands\\{$command}Handler";
    }

    /**
     * Get query namespace.
     */
    private function getQueryNamespace(string $module, string $query): string
    {
        return "Modules\\{$module}\\Application\\Queries\\{$query}";
    }

    /**
     * Get query handler namespace.
     */
    private function getQueryHandlerNamespace(string $module, string $query): string
    {
        return "Modules\\{$module}\\Application\\Queries\\{$query}Handler";
    }

    /**
     * Get value object namespace.
     */
    private function getValueObjectNamespace(string $module, string $valueObject): string
    {
        return "Modules\\{$module}\\Domain\\ValueObjects\\{$valueObject}";
    }

    /**
     * Get event namespace.
     */
    private function getEventNamespace(string $module, string $event): string
    {
        return "Modules\\{$module}\\Domain\\Events\\{$event}";
    }
}