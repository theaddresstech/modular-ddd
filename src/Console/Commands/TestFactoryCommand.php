<?php

declare(strict_types=1);

namespace LaravelModularDDD\Console\Commands;

use Illuminate\Console\Command;
use LaravelModularDDD\Support\ModuleRegistry;
use LaravelModularDDD\Testing\Generators\FactoryGenerator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * TestFactoryCommand
 *
 * Generates test data factories for DDD aggregates, entities, and value objects.
 * Creates factories that respect domain invariants and business rules.
 */
final class TestFactoryCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'modular:make:factory
                           {module : The module to generate factories for}
                           {type? : Type of factory to generate (aggregate, entity, value-object)}
                           {name? : Specific name of the component to generate factory for}
                           {--all : Generate factories for all components in the module}
                           {--states : Include state factories for aggregates}
                           {--sequences : Include sequence generators}
                           {--registry : Generate factory registry}
                           {--force : Overwrite existing factory files}';

    /**
     * The console command description.
     */
    protected $description = 'Generate test data factories for DDD components';

    public function __construct(
        private readonly ModuleRegistry $moduleRegistry,
        private readonly FactoryGenerator $factoryGenerator
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $module = $this->argument('module');
            $type = $this->argument('type');
            $name = $this->argument('name');

            if (!$this->moduleRegistry->hasModule($module)) {
                $this->error("Module '{$module}' not found.");
                return Command::FAILURE;
            }

            $this->info("🏭 Generating test factories for module: {$module}");

            if ($this->option('all')) {
                return $this->generateAllFactories($module);
            }

            if ($type && $name) {
                return $this->generateSpecificFactory($module, $type, $name);
            }

            return $this->interactiveFactoryGeneration($module);

        } catch (\Exception $e) {
            $this->error("Factory generation failed: {$e->getMessage()}");

            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }

    /**
     * Generate factories for all components in the module.
     */
    private function generateAllFactories(string $module): int
    {
        $this->line("  📦 Analyzing module components...");

        $moduleInfo = $this->moduleRegistry->getModule($module);
        $components = $this->analyzeModuleComponents($moduleInfo);

        $results = [
            'files_created' => [],
            'warnings' => [],
        ];

        // Generate base factory first
        $this->line("  🏗️ Generating base factory...");
        $baseFactoryFile = $this->factoryGenerator->generateBaseFactory($module, $this->buildOptions());
        $results['files_created'][] = $baseFactoryFile;

        // Generate aggregate factories
        if (!empty($components['aggregates'])) {
            $this->line("  🔨 Generating aggregate factories...");
            foreach ($components['aggregates'] as $aggregate) {
                $aggregateResult = $this->factoryGenerator->generateForAggregate($module, $aggregate, $this->buildOptions());
                $results['files_created'] = array_merge($results['files_created'], $aggregateResult);
            }
        }

        // Generate entity factories
        if (!empty($components['entities'])) {
            $this->line("  🧱 Generating entity factories...");
            foreach ($components['entities'] as $entity) {
                $entityResult = $this->factoryGenerator->generateForEntity($module, $entity, $this->buildOptions());
                $results['files_created'] = array_merge($results['files_created'], $entityResult);
            }
        }

        // Generate value object factories
        if (!empty($components['value_objects'])) {
            $this->line("  💎 Generating value object factories...");
            foreach ($components['value_objects'] as $valueObject) {
                $valueObjectResult = $this->factoryGenerator->generateForValueObject($module, $valueObject, $this->buildOptions());
                $results['files_created'] = array_merge($results['files_created'], $valueObjectResult);
            }
        }

        // Generate sequence generators if requested
        if ($this->option('sequences')) {
            $this->line("  🔢 Generating sequence generators...");
            $sequences = $this->getSequenceDefinitions($module);
            $sequenceFile = $this->factoryGenerator->generateSequenceGenerator($module, $sequences, $this->buildOptions());
            $results['files_created'][] = $sequenceFile;
        }

        // Generate factory registry if requested
        if ($this->option('registry')) {
            $this->line("  📋 Generating factory registry...");
            $factories = $this->getFactoryList($components);
            $registryFile = $this->factoryGenerator->generateFactoryRegistry($module, $factories, $this->buildOptions());
            $results['files_created'][] = $registryFile;
        }

        $this->displayFactoryResults($module, $results);

        return Command::SUCCESS;
    }

    /**
     * Generate factory for a specific component.
     */
    private function generateSpecificFactory(string $module, string $type, string $name): int
    {
        $this->line("  🎯 Generating {$type} factory for {$name}...");

        $options = $this->buildOptions();
        $result = ['files_created' => []];

        switch (strtolower($type)) {
            case 'aggregate':
                $files = $this->factoryGenerator->generateForAggregate($module, $name, $options);
                $result['files_created'] = $files;
                break;

            case 'entity':
                $files = $this->factoryGenerator->generateForEntity($module, $name, $options);
                $result['files_created'] = $files;
                break;

            case 'value-object':
            case 'valueobject':
                $files = $this->factoryGenerator->generateForValueObject($module, $name, $options);
                $result['files_created'] = $files;
                break;

            default:
                $this->error("Unknown factory type: {$type}");
                return Command::FAILURE;
        }

        $this->displayFactoryResults($module, $result, $type, $name);

        return Command::SUCCESS;
    }

    /**
     * Interactive factory generation.
     */
    private function interactiveFactoryGeneration(string $module): int
    {
        $this->info("🎮 Interactive factory generation mode");

        $moduleInfo = $this->moduleRegistry->getModule($module);
        $components = $this->analyzeModuleComponents($moduleInfo);

        // Ask what type of factory to generate
        $type = $this->choice(
            'What type of factory would you like to generate?',
            ['aggregate', 'entity', 'value-object', 'all'],
            'aggregate'
        );

        if ($type === 'all') {
            return $this->generateAllFactories($module);
        }

        // Get available components of the selected type
        $availableComponents = $components[$type . 's'] ?? [];

        if (empty($availableComponents)) {
            $this->warn("No {$type}s found in module {$module}");
            return Command::SUCCESS;
        }

        // Ask which specific component
        $name = $this->choice(
            "Which {$type} would you like to generate a factory for?",
            array_merge($availableComponents, ['all'])
        );

        if ($name === 'all') {
            return $this->generateFactoriesForType($module, $type, $availableComponents);
        }

        return $this->generateSpecificFactory($module, $type, $name);
    }

    /**
     * Generate factories for all components of a specific type.
     */
    private function generateFactoriesForType(string $module, string $type, array $components): int
    {
        $this->line("  🎯 Generating all {$type} factories...");

        $results = ['files_created' => [], 'warnings' => []];

        foreach ($components as $component) {
            $this->line("    📝 Generating factory for {$component}...");

            try {
                switch ($type) {
                    case 'aggregate':
                        $files = $this->factoryGenerator->generateForAggregate($module, $component, $this->buildOptions());
                        break;
                    case 'entity':
                        $files = $this->factoryGenerator->generateForEntity($module, $component, $this->buildOptions());
                        break;
                    case 'value-object':
                        $files = $this->factoryGenerator->generateForValueObject($module, $component, $this->buildOptions());
                        break;
                    default:
                        continue 2;
                }

                $results['files_created'] = array_merge($results['files_created'], $files);
            } catch (\Exception $e) {
                $results['warnings'][] = "Failed to generate factory for {$component}: {$e->getMessage()}";
            }
        }

        $this->displayFactoryResults($module, $results, $type);

        return Command::SUCCESS;
    }

    /**
     * Build factory generation options.
     */
    private function buildOptions(): array
    {
        return [
            'force' => $this->option('force'),
            'include_states' => $this->option('states'),
            'include_sequences' => $this->option('sequences'),
            'include_registry' => $this->option('registry'),
        ];
    }

    /**
     * Display factory generation results.
     */
    private function displayFactoryResults(string $module, array $result, string $type = null, string $name = null): void
    {
        if ($type && $name) {
            $this->info("\n✅ Factory generation completed for {$type} '{$name}' in module {$module}");
        } elseif ($type) {
            $this->info("\n✅ Factory generation completed for all {$type}s in module {$module}");
        } else {
            $this->info("\n✅ Factory generation completed for module {$module}");
        }

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

        $this->displayFactoryNextSteps($module);
    }

    /**
     * Display next steps for using generated factories.
     */
    private function displayFactoryNextSteps(string $module): void
    {
        $this->info("\n🎯 Next Steps:");
        $this->line("  1. Review the generated factory files");
        $this->line("  2. Customize factory states and traits as needed");
        $this->line("  3. Use factories in your tests:");
        $this->line("     \$user = UserFactory::make();");
        $this->line("     \$users = UserFactory::times(5)->create();");
        $this->line("  4. Create custom states:");
        $this->line("     \$activeUser = UserFactory::active()->create();");

        $this->info("\n💡 Factory Tips:");
        $this->line("  • Use states for different scenarios");
        $this->line("  • Respect domain invariants in factory data");
        $this->line("  • Create factories for edge cases");
        $this->line("  • Use sequences for unique values");
        $this->line("  • Keep factories simple and focused");

        $this->info("\n📚 Example Usage:");
        $this->line("  // Create single instance");
        $this->line("  \$order = OrderFactory::make();");
        $this->line("");
        $this->line("  // Create with custom attributes");
        $this->line("  \$order = OrderFactory::make(['status' => 'completed']);");
        $this->line("");
        $this->line("  // Create multiple instances");
        $this->line("  \$orders = OrderFactory::times(10)->create();");
        $this->line("");
        $this->line("  // Use states");
        $this->line("  \$completedOrder = OrderFactory::completed()->create();");
    }

    /**
     * Analyze module components to determine what factories can be generated.
     */
    private function analyzeModuleComponents(array $moduleInfo): array
    {
        // This would analyze the actual module structure
        // For now, return mock data based on common DDD patterns
        return [
            'aggregates' => $this->findAggregates($moduleInfo),
            'entities' => $this->findEntities($moduleInfo),
            'value_objects' => $this->findValueObjects($moduleInfo),
        ];
    }

    /**
     * Find aggregates in the module.
     */
    private function findAggregates(array $moduleInfo): array
    {
        $aggregatesPath = $moduleInfo['path'] . '/Domain/Aggregates';

        if (!is_dir($aggregatesPath)) {
            return [];
        }

        $aggregates = [];
        $files = glob($aggregatesPath . '/*.php');

        foreach ($files as $file) {
            $aggregates[] = pathinfo($file, PATHINFO_FILENAME);
        }

        return $aggregates;
    }

    /**
     * Find entities in the module.
     */
    private function findEntities(array $moduleInfo): array
    {
        $entitiesPath = $moduleInfo['path'] . '/Domain/Entities';

        if (!is_dir($entitiesPath)) {
            return [];
        }

        $entities = [];
        $files = glob($entitiesPath . '/*.php');

        foreach ($files as $file) {
            $entities[] = pathinfo($file, PATHINFO_FILENAME);
        }

        return $entities;
    }

    /**
     * Find value objects in the module.
     */
    private function findValueObjects(array $moduleInfo): array
    {
        $valueObjectsPath = $moduleInfo['path'] . '/Domain/ValueObjects';

        if (!is_dir($valueObjectsPath)) {
            return [];
        }

        $valueObjects = [];
        $files = glob($valueObjectsPath . '/*.php');

        foreach ($files as $file) {
            $valueObjects[] = pathinfo($file, PATHINFO_FILENAME);
        }

        return $valueObjects;
    }

    /**
     * Get sequence definitions for the module.
     */
    private function getSequenceDefinitions(string $module): array
    {
        return [
            'user_sequence' => ['prefix' => 'USR-', 'format' => 'numeric'],
            'order_sequence' => ['prefix' => 'ORD-', 'format' => 'numeric'],
            'invoice_sequence' => ['prefix' => 'INV-', 'format' => 'numeric'],
        ];
    }

    /**
     * Get list of factories for registry.
     */
    private function getFactoryList(array $components): array
    {
        $factories = [];

        foreach ($components['aggregates'] as $aggregate) {
            $factories[] = $aggregate . 'Factory';
        }

        foreach ($components['entities'] as $entity) {
            $factories[] = $entity . 'Factory';
        }

        foreach ($components['value_objects'] as $valueObject) {
            $factories[] = $valueObject . 'Factory';
        }

        return $factories;
    }

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['module', InputArgument::REQUIRED, 'The module to generate factories for'],
            ['type', InputArgument::OPTIONAL, 'Type of factory to generate'],
            ['name', InputArgument::OPTIONAL, 'Specific name of the component'],
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['all', null, InputOption::VALUE_NONE, 'Generate factories for all components'],
            ['states', null, InputOption::VALUE_NONE, 'Include state factories for aggregates'],
            ['sequences', null, InputOption::VALUE_NONE, 'Include sequence generators'],
            ['registry', null, InputOption::VALUE_NONE, 'Generate factory registry'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite existing factory files'],
        ];
    }
}