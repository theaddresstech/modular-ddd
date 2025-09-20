<?php

declare(strict_types=1);

namespace LaravelModularDDD\Testing\Generators;

use Illuminate\Filesystem\Filesystem;
use LaravelModularDDD\Generators\StubProcessor;
use LaravelModularDDD\Support\ModuleRegistry;

/**
 * FactoryGenerator
 *
 * Generates test data factories for aggregates, entities, and value objects.
 * Creates factories that respect domain invariants and business rules.
 */
final class FactoryGenerator
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly StubProcessor $stubProcessor,
        private readonly ModuleRegistry $moduleRegistry
    ) {}

    /**
     * Generate factory for an aggregate.
     */
    public function generateForAggregate(string $module, string $aggregate, array $options = []): array
    {
        $files = [];

        $moduleInfo = $this->moduleRegistry->getModule($module);
        $factoryPath = $this->getFactoryPath($moduleInfo);

        // Ensure factory directory exists
        if (!$this->filesystem->exists($factoryPath)) {
            $this->filesystem->makeDirectory($factoryPath, 0755, true);
        }

        // Analyze aggregate structure for factory generation
        $aggregateAnalysis = $this->analyzeAggregateStructure($moduleInfo, $aggregate);

        $variables = [
            'module' => $module,
            'aggregate' => $aggregate,
            'moduleNamespace' => $this->getModuleNamespace($module),
            'factoryNamespace' => $this->getFactoryNamespace($module),
            'aggregateNamespace' => $this->getAggregateNamespace($module, $aggregate),
            'properties' => $aggregateAnalysis['properties'],
            'required_properties' => $aggregateAnalysis['required'],
            'optional_properties' => $aggregateAnalysis['optional'],
            'relationships' => $aggregateAnalysis['relationships'],
            'value_objects' => $aggregateAnalysis['value_objects'],
            'states' => $aggregateAnalysis['states'],
            'business_rules' => $aggregateAnalysis['business_rules'],
        ];

        $content = $this->stubProcessor->process('factory-aggregate', $variables);
        $factoryFile = $factoryPath . '/' . $aggregate . 'Factory.php';
        $this->filesystem->put($factoryFile, $content);
        $files[] = $factoryFile;

        // Generate state factories for complex aggregates
        if (!empty($aggregateAnalysis['states'])) {
            $stateFiles = $this->generateStateFactories($moduleInfo, $aggregate, $aggregateAnalysis['states']);
            $files = array_merge($files, $stateFiles);
        }

        // Generate value object factories
        if (!empty($aggregateAnalysis['value_objects'])) {
            $valueObjectFiles = $this->generateValueObjectFactories($moduleInfo, $aggregateAnalysis['value_objects']);
            $files = array_merge($files, $valueObjectFiles);
        }

        return $files;
    }

    /**
     * Generate factory for an entity.
     */
    public function generateForEntity(string $module, string $entity, array $options = []): array
    {
        $files = [];

        $moduleInfo = $this->moduleRegistry->getModule($module);
        $factoryPath = $this->getFactoryPath($moduleInfo);

        // Ensure factory directory exists
        if (!$this->filesystem->exists($factoryPath)) {
            $this->filesystem->makeDirectory($factoryPath, 0755, true);
        }

        // Analyze entity structure
        $entityAnalysis = $this->analyzeEntityStructure($moduleInfo, $entity);

        $variables = [
            'module' => $module,
            'entity' => $entity,
            'moduleNamespace' => $this->getModuleNamespace($module),
            'factoryNamespace' => $this->getFactoryNamespace($module),
            'entityNamespace' => $this->getEntityNamespace($module, $entity),
            'properties' => $entityAnalysis['properties'],
            'required_properties' => $entityAnalysis['required'],
            'relationships' => $entityAnalysis['relationships'],
            'value_objects' => $entityAnalysis['value_objects'],
        ];

        $content = $this->stubProcessor->process('factory-entity', $variables);
        $factoryFile = $factoryPath . '/' . $entity . 'Factory.php';
        $this->filesystem->put($factoryFile, $content);
        $files[] = $factoryFile;

        return $files;
    }

    /**
     * Generate factory for a value object.
     */
    public function generateForValueObject(string $module, string $valueObject, array $options = []): array
    {
        $files = [];

        $moduleInfo = $this->moduleRegistry->getModule($module);
        $factoryPath = $this->getFactoryPath($moduleInfo, 'ValueObjects');

        // Ensure factory directory exists
        if (!$this->filesystem->exists($factoryPath)) {
            $this->filesystem->makeDirectory($factoryPath, 0755, true);
        }

        // Analyze value object structure
        $valueObjectAnalysis = $this->analyzeValueObjectStructure($moduleInfo, $valueObject);

        $variables = [
            'module' => $module,
            'valueObject' => $valueObject,
            'moduleNamespace' => $this->getModuleNamespace($module),
            'factoryNamespace' => $this->getFactoryNamespace($module),
            'valueObjectNamespace' => $this->getValueObjectNamespace($module, $valueObject),
            'properties' => $valueObjectAnalysis['properties'],
            'validation_rules' => $valueObjectAnalysis['validation'],
            'valid_examples' => $valueObjectAnalysis['examples'],
            'invalid_examples' => $valueObjectAnalysis['invalid_examples'],
        ];

        $content = $this->stubProcessor->process('factory-value-object', $variables);
        $factoryFile = $factoryPath . '/' . $valueObject . 'Factory.php';
        $this->filesystem->put($factoryFile, $content);
        $files[] = $factoryFile;

        return $files;
    }

    /**
     * Generate base factory class.
     */
    public function generateBaseFactory(string $module, array $options = []): string
    {
        $moduleInfo = $this->moduleRegistry->getModule($module);
        $factoryPath = $this->getFactoryPath($moduleInfo);

        // Ensure factory directory exists
        if (!$this->filesystem->exists($factoryPath)) {
            $this->filesystem->makeDirectory($factoryPath, 0755, true);
        }

        $variables = [
            'module' => $module,
            'moduleNamespace' => $this->getModuleNamespace($module),
            'factoryNamespace' => $this->getFactoryNamespace($module),
        ];

        $content = $this->stubProcessor->process('factory-base', $variables);
        $factoryFile = $factoryPath . '/BaseFactory.php';
        $this->filesystem->put($factoryFile, $content);

        return $factoryFile;
    }

    /**
     * Generate factory registry for the module.
     */
    public function generateFactoryRegistry(string $module, array $factories, array $options = []): string
    {
        $moduleInfo = $this->moduleRegistry->getModule($module);
        $factoryPath = $this->getFactoryPath($moduleInfo);

        $variables = [
            'module' => $module,
            'moduleNamespace' => $this->getModuleNamespace($module),
            'factoryNamespace' => $this->getFactoryNamespace($module),
            'registered_factories' => $factories,
        ];

        $content = $this->stubProcessor->process('factory-registry', $variables);
        $registryFile = $factoryPath . '/FactoryRegistry.php';
        $this->filesystem->put($registryFile, $content);

        return $registryFile;
    }

    /**
     * Generate sequence generators for unique values.
     */
    public function generateSequenceGenerator(string $module, array $sequences, array $options = []): string
    {
        $moduleInfo = $this->moduleRegistry->getModule($module);
        $factoryPath = $this->getFactoryPath($moduleInfo);

        $variables = [
            'module' => $module,
            'moduleNamespace' => $this->getModuleNamespace($module),
            'factoryNamespace' => $this->getFactoryNamespace($module),
            'sequences' => $sequences,
        ];

        $content = $this->stubProcessor->process('factory-sequence', $variables);
        $sequenceFile = $factoryPath . '/SequenceGenerator.php';
        $this->filesystem->put($sequenceFile, $content);

        return $sequenceFile;
    }

    /**
     * Analyze aggregate structure for factory generation.
     */
    private function analyzeAggregateStructure(array $moduleInfo, string $aggregate): array
    {
        $aggregatePath = $moduleInfo['path'] . '/Domain/Aggregates/' . $aggregate . '.php';

        if (!$this->filesystem->exists($aggregatePath)) {
            throw new \InvalidArgumentException("Aggregate file not found: {$aggregatePath}");
        }

        $content = $this->filesystem->get($aggregatePath);

        return [
            'properties' => $this->extractProperties($content),
            'required' => $this->extractRequiredProperties($content),
            'optional' => $this->extractOptionalProperties($content),
            'relationships' => $this->extractRelationships($content),
            'value_objects' => $this->extractValueObjects($content),
            'states' => $this->extractAggregateStates($content),
            'business_rules' => $this->extractBusinessRules($content),
        ];
    }

    /**
     * Analyze entity structure for factory generation.
     */
    private function analyzeEntityStructure(array $moduleInfo, string $entity): array
    {
        $entityPath = $moduleInfo['path'] . '/Domain/Entities/' . $entity . '.php';

        if (!$this->filesystem->exists($entityPath)) {
            throw new \InvalidArgumentException("Entity file not found: {$entityPath}");
        }

        $content = $this->filesystem->get($entityPath);

        return [
            'properties' => $this->extractProperties($content),
            'required' => $this->extractRequiredProperties($content),
            'relationships' => $this->extractRelationships($content),
            'value_objects' => $this->extractValueObjects($content),
        ];
    }

    /**
     * Analyze value object structure for factory generation.
     */
    private function analyzeValueObjectStructure(array $moduleInfo, string $valueObject): array
    {
        $valueObjectPath = $moduleInfo['path'] . '/Domain/ValueObjects/' . $valueObject . '.php';

        if (!$this->filesystem->exists($valueObjectPath)) {
            throw new \InvalidArgumentException("Value object file not found: {$valueObjectPath}");
        }

        $content = $this->filesystem->get($valueObjectPath);

        return [
            'properties' => $this->extractProperties($content),
            'validation' => $this->extractValidationRules($content),
            'examples' => $this->generateValidExamples($valueObject, $content),
            'invalid_examples' => $this->generateInvalidExamples($valueObject, $content),
        ];
    }

    /**
     * Generate state factories for different aggregate states.
     */
    private function generateStateFactories(array $moduleInfo, string $aggregate, array $states): array
    {
        $files = [];
        $statePath = $this->getFactoryPath($moduleInfo, 'States');

        if (!$this->filesystem->exists($statePath)) {
            $this->filesystem->makeDirectory($statePath, 0755, true);
        }

        foreach ($states as $state) {
            $variables = [
                'module' => $moduleInfo['name'],
                'aggregate' => $aggregate,
                'state' => $state['name'],
                'moduleNamespace' => $this->getModuleNamespace($moduleInfo['name']),
                'factoryNamespace' => $this->getFactoryNamespace($moduleInfo['name']),
                'state_properties' => $state['properties'],
                'state_conditions' => $state['conditions'],
            ];

            $content = $this->stubProcessor->process('factory-state', $variables);
            $stateFile = $statePath . '/' . $aggregate . $state['name'] . 'StateFactory.php';
            $this->filesystem->put($stateFile, $content);
            $files[] = $stateFile;
        }

        return $files;
    }

    /**
     * Generate value object factories.
     */
    private function generateValueObjectFactories(array $moduleInfo, array $valueObjects): array
    {
        $files = [];

        foreach ($valueObjects as $valueObject) {
            $valueObjectFiles = $this->generateForValueObject($moduleInfo['name'], $valueObject, []);
            $files = array_merge($files, $valueObjectFiles);
        }

        return $files;
    }

    /**
     * Extract properties from class content.
     */
    private function extractProperties(string $content): array
    {
        $properties = [];
        preg_match_all('/private readonly (\w+) \$(\w+)/', $content, $matches);

        for ($i = 0; $i < count($matches[1]); $i++) {
            $properties[$matches[2][$i]] = [
                'type' => $matches[1][$i],
                'name' => $matches[2][$i],
            ];
        }

        return $properties;
    }

    /**
     * Extract required properties.
     */
    private function extractRequiredProperties(string $content): array
    {
        // Look for constructor parameters without default values
        preg_match('/public function __construct\(([^)]+)\)/', $content, $matches);

        if (!isset($matches[1])) {
            return [];
        }

        $parameters = explode(',', $matches[1]);
        $required = [];

        foreach ($parameters as $param) {
            if (!str_contains($param, '=')) {
                preg_match('/\$(\w+)/', $param, $paramMatch);
                if (isset($paramMatch[1])) {
                    $required[] = $paramMatch[1];
                }
            }
        }

        return $required;
    }

    /**
     * Extract optional properties.
     */
    private function extractOptionalProperties(string $content): array
    {
        // Look for constructor parameters with default values
        preg_match('/public function __construct\(([^)]+)\)/', $content, $matches);

        if (!isset($matches[1])) {
            return [];
        }

        $parameters = explode(',', $matches[1]);
        $optional = [];

        foreach ($parameters as $param) {
            if (str_contains($param, '=')) {
                preg_match('/\$(\w+)/', $param, $paramMatch);
                if (isset($paramMatch[1])) {
                    $optional[] = $paramMatch[1];
                }
            }
        }

        return $optional;
    }

    /**
     * Extract relationships from content.
     */
    private function extractRelationships(string $content): array
    {
        $relationships = [];

        // Look for collection properties
        if (str_contains($content, 'Collection')) {
            preg_match_all('/Collection<(\w+)>/', $content, $matches);
            foreach ($matches[1] as $related) {
                $relationships[] = [
                    'type' => 'has_many',
                    'related' => $related,
                ];
            }
        }

        // Look for single entity references
        preg_match_all('/private readonly (\w+Id) \$(\w+)/', $content, $matches);
        for ($i = 0; $i < count($matches[1]); $i++) {
            $relationships[] = [
                'type' => 'belongs_to',
                'related' => str_replace('Id', '', $matches[1][$i]),
                'property' => $matches[2][$i],
            ];
        }

        return $relationships;
    }

    /**
     * Extract value objects from content.
     */
    private function extractValueObjects(string $content): array
    {
        $valueObjects = [];
        preg_match_all('/(\w+ValueObject)/', $content, $matches);
        return array_unique($matches[1] ?? []);
    }

    /**
     * Extract aggregate states.
     */
    private function extractAggregateStates(string $content): array
    {
        $states = [];

        // Look for state enum or constants
        if (str_contains($content, 'State')) {
            $states[] = [
                'name' => 'Active',
                'properties' => ['isActive' => true],
                'conditions' => ['status' => 'active'],
            ];
            $states[] = [
                'name' => 'Inactive',
                'properties' => ['isActive' => false],
                'conditions' => ['status' => 'inactive'],
            ];
        }

        return $states;
    }

    /**
     * Extract business rules.
     */
    private function extractBusinessRules(string $content): array
    {
        $rules = [];

        // Look for validation methods
        preg_match_all('/private function ensure(\w+)\s*\(/', $content, $matches);
        foreach ($matches[1] as $rule) {
            $rules[] = [
                'name' => $rule,
                'description' => "Ensures {$rule} business rule",
            ];
        }

        return $rules;
    }

    /**
     * Extract validation rules from value object.
     */
    private function extractValidationRules(string $content): array
    {
        $rules = [];

        if (str_contains($content, 'empty')) {
            $rules[] = 'not_empty';
        }
        if (str_contains($content, 'length')) {
            $rules[] = 'min_length';
        }
        if (str_contains($content, 'email')) {
            $rules[] = 'email_format';
        }
        if (str_contains($content, 'positive')) {
            $rules[] = 'positive_number';
        }

        return $rules;
    }

    /**
     * Generate valid examples for value object.
     */
    private function generateValidExamples(string $valueObject, string $content): array
    {
        return match (strtolower($valueObject)) {
            'email' => ['user@example.com', 'test@domain.org'],
            'name' => ['John Doe', 'Jane Smith'],
            'age' => [25, 30, 45],
            'price' => [9.99, 100.00, 1500.50],
            default => ['valid_example_1', 'valid_example_2'],
        };
    }

    /**
     * Generate invalid examples for value object.
     */
    private function generateInvalidExamples(string $valueObject, string $content): array
    {
        return match (strtolower($valueObject)) {
            'email' => ['invalid-email', 'missing@domain'],
            'name' => ['', '   ', 'X'],
            'age' => [-1, 0, 200],
            'price' => [-10.00, 'invalid'],
            default => ['', null, 'invalid'],
        };
    }

    /**
     * Get factory directory path.
     */
    private function getFactoryPath(array $moduleInfo, string $subDirectory = ''): string
    {
        $basePath = $moduleInfo['path'] . '/Tests/Factories';
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
     * Get factory namespace.
     */
    private function getFactoryNamespace(string $module): string
    {
        return "Modules\\{$module}\\Tests\\Factories";
    }

    /**
     * Get aggregate namespace.
     */
    private function getAggregateNamespace(string $module, string $aggregate): string
    {
        return "Modules\\{$module}\\Domain\\Aggregates\\{$aggregate}";
    }

    /**
     * Get entity namespace.
     */
    private function getEntityNamespace(string $module, string $entity): string
    {
        return "Modules\\{$module}\\Domain\\Entities\\{$entity}";
    }

    /**
     * Get value object namespace.
     */
    private function getValueObjectNamespace(string $module, string $valueObject): string
    {
        return "Modules\\{$module}\\Domain\\ValueObjects\\{$valueObject}";
    }
}