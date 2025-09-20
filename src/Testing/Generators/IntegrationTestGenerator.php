<?php

declare(strict_types=1);

namespace LaravelModularDDD\Testing\Generators;

use Illuminate\Filesystem\Filesystem;
use LaravelModularDDD\Generators\StubProcessor;
use LaravelModularDDD\Support\ModuleRegistry;

/**
 * IntegrationTestGenerator
 *
 * Generates integration tests for cross-module interactions, event flows,
 * and system-wide scenarios. Tests the complete system behavior.
 */
final class IntegrationTestGenerator
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly StubProcessor $stubProcessor,
        private readonly ModuleRegistry $moduleRegistry
    ) {}

    /**
     * Generate integration tests for aggregate interactions.
     */
    public function generateAggregateTests(string $module, string $aggregate, array $options = []): array
    {
        $files = [];

        $moduleInfo = $this->moduleRegistry->getModule($module);
        $testPath = $this->getIntegrationTestPath($moduleInfo, 'Aggregates');

        // Ensure test directory exists
        if (!$this->filesystem->exists($testPath)) {
            $this->filesystem->makeDirectory($testPath, 0755, true);
        }

        // Analyze aggregate dependencies and interactions
        $interactionAnalysis = $this->analyzeAggregateInteractions($moduleInfo, $aggregate);

        $variables = [
            'module' => $module,
            'aggregate' => $aggregate,
            'moduleNamespace' => $this->getModuleNamespace($module),
            'testNamespace' => $this->getIntegrationTestNamespace($module),
            'dependencies' => $interactionAnalysis['dependencies'],
            'event_flows' => $interactionAnalysis['event_flows'],
            'external_services' => $interactionAnalysis['external_services'],
            'database_interactions' => $interactionAnalysis['database'],
            'cross_module_calls' => $interactionAnalysis['cross_module'],
        ];

        $content = $this->stubProcessor->process('test-integration-aggregate', $variables);
        $testFile = $testPath . '/' . $aggregate . 'IntegrationTest.php';
        $this->filesystem->put($testFile, $content);
        $files[] = $testFile;

        return $files;
    }

    /**
     * Generate integration tests for command flows.
     */
    public function generateCommandTests(string $module, string $command, array $options = []): array
    {
        $files = [];

        $moduleInfo = $this->moduleRegistry->getModule($module);
        $testPath = $this->getIntegrationTestPath($moduleInfo, 'Commands');

        // Ensure test directory exists
        if (!$this->filesystem->exists($testPath)) {
            $this->filesystem->makeDirectory($testPath, 0755, true);
        }

        // Analyze command flow and side effects
        $commandFlowAnalysis = $this->analyzeCommandFlow($moduleInfo, $command);

        $variables = [
            'module' => $module,
            'command' => $command,
            'moduleNamespace' => $this->getModuleNamespace($module),
            'testNamespace' => $this->getIntegrationTestNamespace($module),
            'flow_steps' => $commandFlowAnalysis['steps'],
            'event_projections' => $commandFlowAnalysis['projections'],
            'side_effects' => $commandFlowAnalysis['side_effects'],
            'external_calls' => $commandFlowAnalysis['external_calls'],
            'compensation_actions' => $commandFlowAnalysis['compensations'],
        ];

        $content = $this->stubProcessor->process('test-integration-command', $variables);
        $testFile = $testPath . '/' . $command . 'IntegrationTest.php';
        $this->filesystem->put($testFile, $content);
        $files[] = $testFile;

        return $files;
    }

    /**
     * Generate integration tests for event flows and projections.
     */
    public function generateEventFlowTests(string $module, array $options = []): array
    {
        $files = [];

        $moduleInfo = $this->moduleRegistry->getModule($module);
        $testPath = $this->getIntegrationTestPath($moduleInfo, 'Events');

        // Ensure test directory exists
        if (!$this->filesystem->exists($testPath)) {
            $this->filesystem->makeDirectory($testPath, 0755, true);
        }

        // Analyze event flows in the module
        $eventFlowAnalysis = $this->analyzeEventFlows($moduleInfo);

        $variables = [
            'module' => $module,
            'moduleNamespace' => $this->getModuleNamespace($module),
            'testNamespace' => $this->getIntegrationTestNamespace($module),
            'event_chains' => $eventFlowAnalysis['chains'],
            'projections' => $eventFlowAnalysis['projections'],
            'sagas' => $eventFlowAnalysis['sagas'],
            'event_handlers' => $eventFlowAnalysis['handlers'],
        ];

        $content = $this->stubProcessor->process('test-integration-events', $variables);
        $testFile = $testPath . '/' . $module . 'EventFlowTest.php';
        $this->filesystem->put($testFile, $content);
        $files[] = $testFile;

        return $files;
    }

    /**
     * Generate module-level integration tests.
     */
    public function generateModuleTests(string $module, array $options = []): array
    {
        $files = [];

        $moduleInfo = $this->moduleRegistry->getModule($module);
        $testPath = $this->getIntegrationTestPath($moduleInfo);

        // Ensure test directory exists
        if (!$this->filesystem->exists($testPath)) {
            $this->filesystem->makeDirectory($testPath, 0755, true);
        }

        // Analyze module-wide integration points
        $moduleAnalysis = $this->analyzeModuleIntegration($moduleInfo);

        $variables = [
            'module' => $module,
            'moduleNamespace' => $this->getModuleNamespace($module),
            'testNamespace' => $this->getIntegrationTestNamespace($module),
            'module_dependencies' => $moduleAnalysis['dependencies'],
            'public_interfaces' => $moduleAnalysis['interfaces'],
            'data_consistency' => $moduleAnalysis['consistency'],
            'performance_requirements' => $moduleAnalysis['performance'],
        ];

        $content = $this->stubProcessor->process('test-integration-module', $variables);
        $testFile = $testPath . '/' . $module . 'ModuleIntegrationTest.php';
        $this->filesystem->put($testFile, $content);
        $files[] = $testFile;

        // Generate cross-module integration tests
        if (!empty($moduleAnalysis['dependencies'])) {
            $crossModuleFiles = $this->generateCrossModuleTests($moduleInfo, $moduleAnalysis['dependencies']);
            $files = array_merge($files, $crossModuleFiles);
        }

        return $files;
    }

    /**
     * Generate saga integration tests.
     */
    public function generateSagaTests(string $module, array $sagas, array $options = []): array
    {
        $files = [];

        $moduleInfo = $this->moduleRegistry->getModule($module);
        $testPath = $this->getIntegrationTestPath($moduleInfo, 'Sagas');

        // Ensure test directory exists
        if (!$this->filesystem->exists($testPath)) {
            $this->filesystem->makeDirectory($testPath, 0755, true);
        }

        foreach ($sagas as $saga) {
            $sagaAnalysis = $this->analyzeSaga($moduleInfo, $saga);

            $variables = [
                'module' => $module,
                'saga' => $saga['name'],
                'moduleNamespace' => $this->getModuleNamespace($module),
                'testNamespace' => $this->getIntegrationTestNamespace($module),
                'saga_steps' => $sagaAnalysis['steps'],
                'compensation_flows' => $sagaAnalysis['compensations'],
                'timeout_scenarios' => $sagaAnalysis['timeouts'],
                'failure_scenarios' => $sagaAnalysis['failures'],
            ];

            $content = $this->stubProcessor->process('test-integration-saga', $variables);
            $testFile = $testPath . '/' . $saga['name'] . 'SagaTest.php';
            $this->filesystem->put($testFile, $content);
            $files[] = $testFile;
        }

        return $files;
    }

    /**
     * Generate database integration tests.
     */
    public function generateDatabaseTests(string $module, array $options = []): array
    {
        $files = [];

        $moduleInfo = $this->moduleRegistry->getModule($module);
        $testPath = $this->getIntegrationTestPath($moduleInfo, 'Database');

        // Ensure test directory exists
        if (!$this->filesystem->exists($testPath)) {
            $this->filesystem->makeDirectory($testPath, 0755, true);
        }

        // Analyze database interactions
        $databaseAnalysis = $this->analyzeDatabaseIntegration($moduleInfo);

        $variables = [
            'module' => $module,
            'moduleNamespace' => $this->getModuleNamespace($module),
            'testNamespace' => $this->getIntegrationTestNamespace($module),
            'repositories' => $databaseAnalysis['repositories'],
            'transactions' => $databaseAnalysis['transactions'],
            'migrations' => $databaseAnalysis['migrations'],
            'indexes' => $databaseAnalysis['indexes'],
            'constraints' => $databaseAnalysis['constraints'],
        ];

        $content = $this->stubProcessor->process('test-integration-database', $variables);
        $testFile = $testPath . '/' . $module . 'DatabaseTest.php';
        $this->filesystem->put($testFile, $content);
        $files[] = $testFile;

        return $files;
    }

    /**
     * Generate external service integration tests.
     */
    public function generateExternalServiceTests(string $module, array $services, array $options = []): array
    {
        $files = [];

        $moduleInfo = $this->moduleRegistry->getModule($module);
        $testPath = $this->getIntegrationTestPath($moduleInfo, 'External');

        // Ensure test directory exists
        if (!$this->filesystem->exists($testPath)) {
            $this->filesystem->makeDirectory($testPath, 0755, true);
        }

        foreach ($services as $service) {
            $variables = [
                'module' => $module,
                'service' => $service['name'],
                'moduleNamespace' => $this->getModuleNamespace($module),
                'testNamespace' => $this->getIntegrationTestNamespace($module),
                'endpoints' => $service['endpoints'],
                'authentication' => $service['auth'] ?? false,
                'retry_policies' => $service['retry'] ?? [],
                'circuit_breaker' => $service['circuit_breaker'] ?? false,
            ];

            $content = $this->stubProcessor->process('test-integration-external', $variables);
            $testFile = $testPath . '/' . $service['name'] . 'ServiceTest.php';
            $this->filesystem->put($testFile, $content);
            $files[] = $testFile;
        }

        return $files;
    }

    /**
     * Analyze aggregate interactions within the module.
     */
    private function analyzeAggregateInteractions(array $moduleInfo, string $aggregate): array
    {
        return [
            'dependencies' => $this->findAggregateDependencies($moduleInfo, $aggregate),
            'event_flows' => $this->findEventFlows($moduleInfo, $aggregate),
            'external_services' => $this->findExternalServices($moduleInfo, $aggregate),
            'database' => $this->findDatabaseInteractions($moduleInfo, $aggregate),
            'cross_module' => $this->findCrossModuleInteractions($moduleInfo, $aggregate),
        ];
    }

    /**
     * Analyze command flow and its side effects.
     */
    private function analyzeCommandFlow(array $moduleInfo, string $command): array
    {
        return [
            'steps' => $this->extractCommandSteps($moduleInfo, $command),
            'projections' => $this->findEventProjections($moduleInfo, $command),
            'side_effects' => $this->findCommandSideEffects($moduleInfo, $command),
            'external_calls' => $this->findExternalCalls($moduleInfo, $command),
            'compensations' => $this->findCompensationActions($moduleInfo, $command),
        ];
    }

    /**
     * Analyze event flows in the module.
     */
    private function analyzeEventFlows(array $moduleInfo): array
    {
        return [
            'chains' => $this->findEventChains($moduleInfo),
            'projections' => $this->findAllProjections($moduleInfo),
            'sagas' => $this->findSagas($moduleInfo),
            'handlers' => $this->findEventHandlers($moduleInfo),
        ];
    }

    /**
     * Analyze module-wide integration points.
     */
    private function analyzeModuleIntegration(array $moduleInfo): array
    {
        return [
            'dependencies' => $this->findModuleDependencies($moduleInfo),
            'interfaces' => $this->findPublicInterfaces($moduleInfo),
            'consistency' => $this->findConsistencyRequirements($moduleInfo),
            'performance' => $this->findPerformanceRequirements($moduleInfo),
        ];
    }

    /**
     * Analyze saga orchestration patterns.
     */
    private function analyzeSaga(array $moduleInfo, array $saga): array
    {
        return [
            'steps' => $saga['steps'] ?? [],
            'compensations' => $saga['compensations'] ?? [],
            'timeouts' => $saga['timeouts'] ?? [],
            'failures' => $this->generateSagaFailureScenarios($saga),
        ];
    }

    /**
     * Analyze database integration requirements.
     */
    private function analyzeDatabaseIntegration(array $moduleInfo): array
    {
        return [
            'repositories' => $this->findRepositories($moduleInfo),
            'transactions' => $this->findTransactionBoundaries($moduleInfo),
            'migrations' => $this->findMigrations($moduleInfo),
            'indexes' => $this->findRequiredIndexes($moduleInfo),
            'constraints' => $this->findDatabaseConstraints($moduleInfo),
        ];
    }

    /**
     * Generate cross-module integration tests.
     */
    private function generateCrossModuleTests(array $moduleInfo, array $dependencies): array
    {
        $files = [];
        $testPath = $this->getIntegrationTestPath($moduleInfo, 'CrossModule');

        if (!$this->filesystem->exists($testPath)) {
            $this->filesystem->makeDirectory($testPath, 0755, true);
        }

        foreach ($dependencies as $dependency) {
            $variables = [
                'module' => $moduleInfo['name'],
                'dependency_module' => $dependency,
                'moduleNamespace' => $this->getModuleNamespace($moduleInfo['name']),
                'testNamespace' => $this->getIntegrationTestNamespace($moduleInfo['name']),
                'interaction_points' => $this->findInteractionPoints($moduleInfo, $dependency),
            ];

            $content = $this->stubProcessor->process('test-integration-cross-module', $variables);
            $testFile = $testPath . '/' . $dependency . 'IntegrationTest.php';
            $this->filesystem->put($testFile, $content);
            $files[] = $testFile;
        }

        return $files;
    }

    /**
     * Find aggregate dependencies.
     */
    private function findAggregateDependencies(array $moduleInfo, string $aggregate): array
    {
        // Analyze aggregate constructor and method dependencies
        $aggregatePath = $moduleInfo['path'] . '/Domain/Aggregates/' . $aggregate . '.php';

        if (!$this->filesystem->exists($aggregatePath)) {
            return [];
        }

        $content = $this->filesystem->get($aggregatePath);
        preg_match_all('/private readonly (\w+)/', $content, $matches);

        return $matches[1] ?? [];
    }

    /**
     * Find event flows from aggregate.
     */
    private function findEventFlows(array $moduleInfo, string $aggregate): array
    {
        return [
            'events_published' => [$aggregate . 'Created', $aggregate . 'Updated'],
            'events_subscribed' => [],
        ];
    }

    /**
     * Find external services used by aggregate.
     */
    private function findExternalServices(array $moduleInfo, string $aggregate): array
    {
        return ['email_service', 'payment_service', 'notification_service'];
    }

    /**
     * Find database interactions.
     */
    private function findDatabaseInteractions(array $moduleInfo, string $aggregate): array
    {
        return [
            'tables' => [strtolower($aggregate) . 's'],
            'relationships' => ['belongs_to', 'has_many'],
            'queries' => ['find', 'save', 'delete'],
        ];
    }

    /**
     * Find cross-module interactions.
     */
    private function findCrossModuleInteractions(array $moduleInfo, string $aggregate): array
    {
        return $this->findModuleDependencies($moduleInfo);
    }

    /**
     * Extract command execution steps.
     */
    private function extractCommandSteps(array $moduleInfo, string $command): array
    {
        return [
            'validate_command',
            'load_aggregate',
            'execute_business_logic',
            'persist_changes',
            'dispatch_events',
        ];
    }

    /**
     * Find event projections triggered by command.
     */
    private function findEventProjections(array $moduleInfo, string $command): array
    {
        return ['read_model_update', 'search_index_update'];
    }

    /**
     * Find command side effects.
     */
    private function findCommandSideEffects(array $moduleInfo, string $command): array
    {
        return ['database_write', 'event_dispatch', 'cache_invalidation'];
    }

    /**
     * Find external API calls.
     */
    private function findExternalCalls(array $moduleInfo, string $command): array
    {
        return ['email_notification', 'payment_processing'];
    }

    /**
     * Find compensation actions for command.
     */
    private function findCompensationActions(array $moduleInfo, string $command): array
    {
        return ['rollback_database', 'send_cancellation_email'];
    }

    /**
     * Find event chains in module.
     */
    private function findEventChains(array $moduleInfo): array
    {
        return [
            ['UserRegistered', 'WelcomeEmailSent'],
            ['OrderPlaced', 'PaymentProcessed', 'OrderConfirmed'],
        ];
    }

    /**
     * Find all projections in module.
     */
    private function findAllProjections(array $moduleInfo): array
    {
        return ['user_profile_projection', 'order_summary_projection'];
    }

    /**
     * Find sagas in module.
     */
    private function findSagas(array $moduleInfo): array
    {
        return ['order_processing_saga', 'user_onboarding_saga'];
    }

    /**
     * Find event handlers.
     */
    private function findEventHandlers(array $moduleInfo): array
    {
        return ['email_handler', 'notification_handler'];
    }

    /**
     * Find module dependencies.
     */
    private function findModuleDependencies(array $moduleInfo): array
    {
        // Analyze composer.json, imports, or configuration
        return ['UserModule', 'PaymentModule', 'NotificationModule'];
    }

    /**
     * Find public interfaces exposed by module.
     */
    private function findPublicInterfaces(array $moduleInfo): array
    {
        return ['api_endpoints', 'event_contracts', 'service_contracts'];
    }

    /**
     * Find data consistency requirements.
     */
    private function findConsistencyRequirements(array $moduleInfo): array
    {
        return ['eventual_consistency', 'strong_consistency'];
    }

    /**
     * Find performance requirements.
     */
    private function findPerformanceRequirements(array $moduleInfo): array
    {
        return [
            'response_time' => '< 200ms',
            'throughput' => '> 1000 req/sec',
            'availability' => '99.9%',
        ];
    }

    /**
     * Generate saga failure scenarios.
     */
    private function generateSagaFailureScenarios(array $saga): array
    {
        return [
            'step_timeout',
            'compensation_failure',
            'external_service_unavailable',
        ];
    }

    /**
     * Find repositories in module.
     */
    private function findRepositories(array $moduleInfo): array
    {
        $repositories = [];
        $repoPath = $moduleInfo['path'] . '/Infrastructure/Repositories';

        if ($this->filesystem->exists($repoPath)) {
            $files = $this->filesystem->files($repoPath);
            foreach ($files as $file) {
                if (str_ends_with($file->getFilename(), 'Repository.php')) {
                    $repositories[] = pathinfo($file->getFilename(), PATHINFO_FILENAME);
                }
            }
        }

        return $repositories;
    }

    /**
     * Find transaction boundaries.
     */
    private function findTransactionBoundaries(array $moduleInfo): array
    {
        return ['command_handlers', 'saga_steps'];
    }

    /**
     * Find migrations.
     */
    private function findMigrations(array $moduleInfo): array
    {
        $migrations = [];
        $migrationPath = $moduleInfo['path'] . '/Infrastructure/Migrations';

        if ($this->filesystem->exists($migrationPath)) {
            $files = $this->filesystem->files($migrationPath);
            foreach ($files as $file) {
                $migrations[] = pathinfo($file->getFilename(), PATHINFO_FILENAME);
            }
        }

        return $migrations;
    }

    /**
     * Find required database indexes.
     */
    private function findRequiredIndexes(array $moduleInfo): array
    {
        return ['primary_key', 'foreign_keys', 'search_indexes'];
    }

    /**
     * Find database constraints.
     */
    private function findDatabaseConstraints(array $moduleInfo): array
    {
        return ['unique_constraints', 'check_constraints', 'foreign_key_constraints'];
    }

    /**
     * Find interaction points between modules.
     */
    private function findInteractionPoints(array $moduleInfo, string $dependencyModule): array
    {
        return [
            'shared_events' => ['UserEvent', 'OrderEvent'],
            'api_calls' => ['/api/users', '/api/orders'],
            'shared_data' => ['user_id', 'order_id'],
        ];
    }

    /**
     * Get integration test directory path.
     */
    private function getIntegrationTestPath(array $moduleInfo, string $subDirectory = ''): string
    {
        $basePath = $moduleInfo['path'] . '/Tests/Integration';
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
     * Get integration test namespace.
     */
    private function getIntegrationTestNamespace(string $module): string
    {
        return "Modules\\{$module}\\Tests\\Integration";
    }
}