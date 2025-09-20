<?php

declare(strict_types=1);

namespace LaravelModularDDD\Testing\Generators;

use Illuminate\Filesystem\Filesystem;
use LaravelModularDDD\Generators\StubProcessor;
use LaravelModularDDD\Support\ModuleRegistry;

/**
 * FeatureTestGenerator
 *
 * Generates feature tests for API endpoints and complete user scenarios.
 * Tests the full request-response cycle including authentication and authorization.
 */
final class FeatureTestGenerator
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly StubProcessor $stubProcessor,
        private readonly ModuleRegistry $moduleRegistry
    ) {}

    /**
     * Generate feature tests for aggregate-related endpoints.
     */
    public function generateAggregateTests(string $module, string $aggregate, array $options = []): array
    {
        $files = [];

        $moduleInfo = $this->moduleRegistry->getModule($module);
        $testPath = $this->getFeatureTestPath($moduleInfo, 'Aggregates');

        // Ensure test directory exists
        if (!$this->filesystem->exists($testPath)) {
            $this->filesystem->makeDirectory($testPath, 0755, true);
        }

        // Analyze aggregate endpoints
        $endpointAnalysis = $this->analyzeAggregateEndpoints($moduleInfo, $aggregate);

        $variables = [
            'module' => $module,
            'aggregate' => $aggregate,
            'aggregateLower' => strtolower($aggregate),
            'moduleNamespace' => $this->getModuleNamespace($module),
            'testNamespace' => $this->getFeatureTestNamespace($module),
            'endpoints' => $endpointAnalysis['endpoints'],
            'authentication' => $endpointAnalysis['authentication'],
            'authorization' => $endpointAnalysis['authorization'],
            'validation_scenarios' => $endpointAnalysis['validation'],
            'business_scenarios' => $endpointAnalysis['scenarios'],
        ];

        $content = $this->stubProcessor->process('test-feature-aggregate', $variables);
        $testFile = $testPath . '/' . $aggregate . 'FeatureTest.php';
        $this->filesystem->put($testFile, $content);
        $files[] = $testFile;

        // Generate CRUD feature tests if endpoints support it
        if ($endpointAnalysis['has_crud']) {
            $crudTestFile = $this->generateCrudFeatureTests($moduleInfo, $aggregate, $endpointAnalysis);
            $files[] = $crudTestFile;
        }

        // Generate business scenario tests
        if (!empty($endpointAnalysis['scenarios'])) {
            $scenarioFiles = $this->generateBusinessScenarioTests($moduleInfo, $aggregate, $endpointAnalysis['scenarios']);
            $files = array_merge($files, $scenarioFiles);
        }

        return $files;
    }

    /**
     * Generate feature tests for query endpoints.
     */
    public function generateQueryTests(string $module, string $query, array $options = []): array
    {
        $files = [];

        $moduleInfo = $this->moduleRegistry->getModule($module);
        $testPath = $this->getFeatureTestPath($moduleInfo, 'Queries');

        // Ensure test directory exists
        if (!$this->filesystem->exists($testPath)) {
            $this->filesystem->makeDirectory($testPath, 0755, true);
        }

        // Analyze query endpoints
        $queryAnalysis = $this->analyzeQueryEndpoints($moduleInfo, $query);

        $variables = [
            'module' => $module,
            'query' => $query,
            'queryLower' => strtolower($query),
            'moduleNamespace' => $this->getModuleNamespace($module),
            'testNamespace' => $this->getFeatureTestNamespace($module),
            'endpoint' => $queryAnalysis['endpoint'],
            'parameters' => $queryAnalysis['parameters'],
            'filters' => $queryAnalysis['filters'],
            'pagination' => $queryAnalysis['pagination'],
            'response_format' => $queryAnalysis['response_format'],
            'caching' => $queryAnalysis['caching'],
        ];

        $content = $this->stubProcessor->process('test-feature-query', $variables);
        $testFile = $testPath . '/' . $query . 'FeatureTest.php';
        $this->filesystem->put($testFile, $content);
        $files[] = $testFile;

        return $files;
    }

    /**
     * Generate API authentication and authorization tests.
     */
    public function generateAuthTests(string $module, array $options = []): array
    {
        $files = [];

        $moduleInfo = $this->moduleRegistry->getModule($module);
        $testPath = $this->getFeatureTestPath($moduleInfo, 'Auth');

        // Ensure test directory exists
        if (!$this->filesystem->exists($testPath)) {
            $this->filesystem->makeDirectory($testPath, 0755, true);
        }

        // Analyze authentication requirements
        $authAnalysis = $this->analyzeAuthenticationRequirements($moduleInfo);

        $variables = [
            'module' => $module,
            'moduleNamespace' => $this->getModuleNamespace($module),
            'testNamespace' => $this->getFeatureTestNamespace($module),
            'auth_methods' => $authAnalysis['methods'],
            'protected_endpoints' => $authAnalysis['protected_endpoints'],
            'roles' => $authAnalysis['roles'],
            'permissions' => $authAnalysis['permissions'],
        ];

        $content = $this->stubProcessor->process('test-feature-auth', $variables);
        $testFile = $testPath . '/' . $module . 'AuthTest.php';
        $this->filesystem->put($testFile, $content);
        $files[] = $testFile;

        return $files;
    }

    /**
     * Generate API validation tests.
     */
    public function generateValidationTests(string $module, array $options = []): array
    {
        $files = [];

        $moduleInfo = $this->moduleRegistry->getModule($module);
        $testPath = $this->getFeatureTestPath($moduleInfo, 'Validation');

        // Ensure test directory exists
        if (!$this->filesystem->exists($testPath)) {
            $this->filesystem->makeDirectory($testPath, 0755, true);
        }

        // Analyze validation requirements across all endpoints
        $validationAnalysis = $this->analyzeValidationRequirements($moduleInfo);

        $variables = [
            'module' => $module,
            'moduleNamespace' => $this->getModuleNamespace($module),
            'testNamespace' => $this->getFeatureTestNamespace($module),
            'validation_rules' => $validationAnalysis['rules'],
            'error_responses' => $validationAnalysis['errors'],
            'validation_scenarios' => $validationAnalysis['scenarios'],
        ];

        $content = $this->stubProcessor->process('test-feature-validation', $variables);
        $testFile = $testPath . '/' . $module . 'ValidationTest.php';
        $this->filesystem->put($testFile, $content);
        $files[] = $testFile;

        return $files;
    }

    /**
     * Generate workflow and process tests.
     */
    public function generateWorkflowTests(string $module, array $workflows, array $options = []): array
    {
        $files = [];

        $moduleInfo = $this->moduleRegistry->getModule($module);
        $testPath = $this->getFeatureTestPath($moduleInfo, 'Workflows');

        // Ensure test directory exists
        if (!$this->filesystem->exists($testPath)) {
            $this->filesystem->makeDirectory($testPath, 0755, true);
        }

        foreach ($workflows as $workflow) {
            $variables = [
                'module' => $module,
                'workflow' => $workflow['name'],
                'moduleNamespace' => $this->getModuleNamespace($module),
                'testNamespace' => $this->getFeatureTestNamespace($module),
                'steps' => $workflow['steps'],
                'expected_outcomes' => $workflow['outcomes'],
                'failure_scenarios' => $workflow['failures'] ?? [],
            ];

            $content = $this->stubProcessor->process('test-feature-workflow', $variables);
            $testFile = $testPath . '/' . $workflow['name'] . 'WorkflowTest.php';
            $this->filesystem->put($testFile, $content);
            $files[] = $testFile;
        }

        return $files;
    }

    /**
     * Generate API performance tests.
     */
    public function generatePerformanceTests(string $module, array $options = []): array
    {
        $files = [];

        $moduleInfo = $this->moduleRegistry->getModule($module);
        $testPath = $this->getFeatureTestPath($moduleInfo, 'Performance');

        // Ensure test directory exists
        if (!$this->filesystem->exists($testPath)) {
            $this->filesystem->makeDirectory($testPath, 0755, true);
        }

        // Analyze performance-critical endpoints
        $performanceAnalysis = $this->analyzePerformanceRequirements($moduleInfo);

        $variables = [
            'module' => $module,
            'moduleNamespace' => $this->getModuleNamespace($module),
            'testNamespace' => $this->getFeatureTestNamespace($module),
            'critical_endpoints' => $performanceAnalysis['endpoints'],
            'response_time_limits' => $performanceAnalysis['limits'],
            'load_test_scenarios' => $performanceAnalysis['load_tests'],
        ];

        $content = $this->stubProcessor->process('test-feature-performance', $variables);
        $testFile = $testPath . '/' . $module . 'PerformanceTest.php';
        $this->filesystem->put($testFile, $content);
        $files[] = $testFile;

        return $files;
    }

    /**
     * Analyze aggregate endpoints for testing.
     */
    private function analyzeAggregateEndpoints(array $moduleInfo, string $aggregate): array
    {
        $controllerPath = $moduleInfo['path'] . '/Presentation/Http/Controllers/' . $aggregate . 'Controller.php';

        $analysis = [
            'endpoints' => [],
            'authentication' => false,
            'authorization' => false,
            'validation' => [],
            'scenarios' => [],
            'has_crud' => false,
        ];

        if ($this->filesystem->exists($controllerPath)) {
            $content = $this->filesystem->get($controllerPath);

            // Extract endpoints
            $analysis['endpoints'] = $this->extractEndpoints($content);
            $analysis['has_crud'] = $this->detectCrudOperations($content);
            $analysis['authentication'] = $this->detectAuthentication($content);
            $analysis['authorization'] = $this->detectAuthorization($content);
            $analysis['validation'] = $this->extractValidationScenarios($content);
            $analysis['scenarios'] = $this->extractBusinessScenarios($aggregate, $content);
        } else {
            // Generate default CRUD endpoints
            $aggregateLower = strtolower($aggregate);
            $analysis['endpoints'] = [
                ['method' => 'GET', 'path' => "/{$aggregateLower}", 'action' => 'index'],
                ['method' => 'POST', 'path' => "/{$aggregateLower}", 'action' => 'store'],
                ['method' => 'GET', 'path' => "/{$aggregateLower}/{id}", 'action' => 'show'],
                ['method' => 'PUT', 'path' => "/{$aggregateLower}/{id}", 'action' => 'update'],
                ['method' => 'DELETE', 'path' => "/{$aggregateLower}/{id}", 'action' => 'destroy'],
            ];
            $analysis['has_crud'] = true;
        }

        return $analysis;
    }

    /**
     * Analyze query endpoints for testing.
     */
    private function analyzeQueryEndpoints(array $moduleInfo, string $query): array
    {
        $controllerPath = $moduleInfo['path'] . '/Presentation/Http/Controllers/' . $query . 'Controller.php';

        $analysis = [
            'endpoint' => ['method' => 'GET', 'path' => '/' . strtolower($query)],
            'parameters' => [],
            'filters' => [],
            'pagination' => false,
            'response_format' => 'json',
            'caching' => false,
        ];

        if ($this->filesystem->exists($controllerPath)) {
            $content = $this->filesystem->get($controllerPath);

            $analysis['parameters'] = $this->extractQueryParameters($content);
            $analysis['filters'] = $this->extractQueryFilters($content);
            $analysis['pagination'] = $this->detectPagination($content);
            $analysis['caching'] = $this->detectCaching($content);
        }

        return $analysis;
    }

    /**
     * Generate CRUD feature tests.
     */
    private function generateCrudFeatureTests(array $moduleInfo, string $aggregate, array $endpointAnalysis): string
    {
        $testPath = $this->getFeatureTestPath($moduleInfo, 'Crud');

        if (!$this->filesystem->exists($testPath)) {
            $this->filesystem->makeDirectory($testPath, 0755, true);
        }

        $variables = [
            'module' => $moduleInfo['name'],
            'aggregate' => $aggregate,
            'aggregateLower' => strtolower($aggregate),
            'moduleNamespace' => $this->getModuleNamespace($moduleInfo['name']),
            'testNamespace' => $this->getFeatureTestNamespace($moduleInfo['name']),
            'endpoints' => $endpointAnalysis['endpoints'],
        ];

        $content = $this->stubProcessor->process('test-feature-crud', $variables);
        $testFile = $testPath . '/' . $aggregate . 'CrudTest.php';
        $this->filesystem->put($testFile, $content);

        return $testFile;
    }

    /**
     * Generate business scenario tests.
     */
    private function generateBusinessScenarioTests(array $moduleInfo, string $aggregate, array $scenarios): array
    {
        $files = [];
        $testPath = $this->getFeatureTestPath($moduleInfo, 'Scenarios');

        if (!$this->filesystem->exists($testPath)) {
            $this->filesystem->makeDirectory($testPath, 0755, true);
        }

        foreach ($scenarios as $scenario) {
            $variables = [
                'module' => $moduleInfo['name'],
                'aggregate' => $aggregate,
                'scenario' => $scenario['name'],
                'moduleNamespace' => $this->getModuleNamespace($moduleInfo['name']),
                'testNamespace' => $this->getFeatureTestNamespace($moduleInfo['name']),
                'steps' => $scenario['steps'],
                'expected_outcome' => $scenario['outcome'],
            ];

            $content = $this->stubProcessor->process('test-feature-scenario', $variables);
            $testFile = $testPath . '/' . $aggregate . $scenario['name'] . 'ScenarioTest.php';
            $this->filesystem->put($testFile, $content);
            $files[] = $testFile;
        }

        return $files;
    }

    /**
     * Analyze authentication requirements.
     */
    private function analyzeAuthenticationRequirements(array $moduleInfo): array
    {
        return [
            'methods' => ['bearer_token', 'session'],
            'protected_endpoints' => $this->findProtectedEndpoints($moduleInfo),
            'roles' => ['admin', 'user', 'guest'],
            'permissions' => $this->extractPermissions($moduleInfo),
        ];
    }

    /**
     * Analyze validation requirements.
     */
    private function analyzeValidationRequirements(array $moduleInfo): array
    {
        return [
            'rules' => $this->extractAllValidationRules($moduleInfo),
            'errors' => $this->extractErrorResponses($moduleInfo),
            'scenarios' => $this->extractValidationScenarioNames($moduleInfo),
        ];
    }

    /**
     * Analyze performance requirements.
     */
    private function analyzePerformanceRequirements(array $moduleInfo): array
    {
        return [
            'endpoints' => $this->findCriticalEndpoints($moduleInfo),
            'limits' => ['response_time' => 200, 'memory' => '50MB'],
            'load_tests' => $this->generateLoadTestScenarios($moduleInfo),
        ];
    }

    /**
     * Extract endpoints from controller content.
     */
    private function extractEndpoints(string $content): array
    {
        $endpoints = [];
        preg_match_all('/public function (\w+)\s*\([^)]*\)/', $content, $matches);

        foreach ($matches[1] as $method) {
            $endpoints[] = [
                'method' => $this->mapMethodToHttpVerb($method),
                'action' => $method,
                'path' => $this->generatePathFromAction($method),
            ];
        }

        return $endpoints;
    }

    /**
     * Detect CRUD operations in controller.
     */
    private function detectCrudOperations(string $content): bool
    {
        $crudMethods = ['index', 'store', 'show', 'update', 'destroy'];
        foreach ($crudMethods as $method) {
            if (str_contains($content, "function {$method}")) {
                return true;
            }
        }
        return false;
    }

    /**
     * Detect authentication middleware.
     */
    private function detectAuthentication(string $content): bool
    {
        return str_contains($content, 'auth:') || str_contains($content, 'authenticate');
    }

    /**
     * Detect authorization middleware.
     */
    private function detectAuthorization(string $content): bool
    {
        return str_contains($content, 'authorize') || str_contains($content, 'can:');
    }

    /**
     * Extract validation scenarios.
     */
    private function extractValidationScenarios(string $content): array
    {
        // Simplified extraction - in real implementation would be more sophisticated
        if (str_contains($content, 'validate')) {
            return [
                'required_fields' => ['name', 'email'],
                'optional_fields' => ['description'],
                'format_validations' => ['email' => 'email format'],
            ];
        }
        return [];
    }

    /**
     * Extract business scenarios from aggregate.
     */
    private function extractBusinessScenarios(string $aggregate, string $content): array
    {
        // Example scenarios based on common patterns
        return [
            [
                'name' => 'Create' . $aggregate,
                'steps' => ['validate_input', 'create_aggregate', 'dispatch_events'],
                'outcome' => 'aggregate_created',
            ],
            [
                'name' => 'Update' . $aggregate,
                'steps' => ['find_aggregate', 'validate_changes', 'update_aggregate'],
                'outcome' => 'aggregate_updated',
            ],
        ];
    }

    /**
     * Extract query parameters.
     */
    private function extractQueryParameters(string $content): array
    {
        preg_match_all('/\$request->get\([\'"](\w+)[\'"]\)/', $content, $matches);
        return $matches[1] ?? [];
    }

    /**
     * Extract query filters.
     */
    private function extractQueryFilters(string $content): array
    {
        if (str_contains($content, 'filter')) {
            return ['status', 'created_at', 'type'];
        }
        return [];
    }

    /**
     * Detect pagination in query.
     */
    private function detectPagination(string $content): bool
    {
        return str_contains($content, 'paginate') || str_contains($content, 'page');
    }

    /**
     * Detect caching in controller.
     */
    private function detectCaching(string $content): bool
    {
        return str_contains($content, 'cache') || str_contains($content, 'remember');
    }

    /**
     * Map controller method to HTTP verb.
     */
    private function mapMethodToHttpVerb(string $method): string
    {
        return match ($method) {
            'index', 'show' => 'GET',
            'store' => 'POST',
            'update' => 'PUT',
            'destroy' => 'DELETE',
            default => 'GET',
        };
    }

    /**
     * Generate path from controller action.
     */
    private function generatePathFromAction(string $action): string
    {
        return match ($action) {
            'index', 'store' => '/',
            'show', 'update', 'destroy' => '/{id}',
            default => '/' . strtolower($action),
        };
    }

    /**
     * Find protected endpoints in module.
     */
    private function findProtectedEndpoints(array $moduleInfo): array
    {
        // Simplified - would analyze middleware in real implementation
        return ['/admin', '/profile', '/settings'];
    }

    /**
     * Extract permissions from module.
     */
    private function extractPermissions(array $moduleInfo): array
    {
        // Simplified - would analyze authorization policies
        return ['create', 'read', 'update', 'delete'];
    }

    /**
     * Extract all validation rules from module.
     */
    private function extractAllValidationRules(array $moduleInfo): array
    {
        return [
            'required' => ['name', 'email'],
            'string' => ['name', 'description'],
            'email' => ['email'],
            'numeric' => ['age', 'price'],
        ];
    }

    /**
     * Extract error responses.
     */
    private function extractErrorResponses(array $moduleInfo): array
    {
        return [
            '400' => 'Bad Request',
            '401' => 'Unauthorized',
            '403' => 'Forbidden',
            '404' => 'Not Found',
            '422' => 'Validation Error',
        ];
    }

    /**
     * Extract validation scenario names.
     */
    private function extractValidationScenarioNames(array $moduleInfo): array
    {
        return [
            'missing_required_fields',
            'invalid_email_format',
            'duplicate_entries',
            'invalid_data_types',
        ];
    }

    /**
     * Find performance-critical endpoints.
     */
    private function findCriticalEndpoints(array $moduleInfo): array
    {
        return [
            ['path' => '/api/search', 'expected_time' => 100],
            ['path' => '/api/dashboard', 'expected_time' => 200],
        ];
    }

    /**
     * Generate load test scenarios.
     */
    private function generateLoadTestScenarios(array $moduleInfo): array
    {
        return [
            ['name' => 'concurrent_users', 'users' => 100, 'duration' => 60],
            ['name' => 'peak_load', 'users' => 500, 'duration' => 300],
        ];
    }

    /**
     * Get feature test directory path.
     */
    private function getFeatureTestPath(array $moduleInfo, string $subDirectory = ''): string
    {
        $basePath = $moduleInfo['path'] . '/Tests/Feature';
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
     * Get feature test namespace.
     */
    private function getFeatureTestNamespace(string $module): string
    {
        return "Modules\\{$module}\\Tests\\Feature";
    }
}