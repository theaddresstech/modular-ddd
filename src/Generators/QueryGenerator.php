<?php

declare(strict_types=1);

namespace LaravelModularDDD\Generators;

use LaravelModularDDD\Generators\Contracts\GeneratorInterface;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Query Generator Agent
 *
 * Specialized generator for creating CQRS queries and handlers
 * with caching and optimization features.
 */
final class QueryGenerator implements GeneratorInterface
{
    private Filesystem $filesystem;
    private StubProcessor $stubProcessor;

    public function __construct(Filesystem $filesystem, StubProcessor $stubProcessor)
    {
        $this->filesystem = $filesystem;
        $this->stubProcessor = $stubProcessor;
    }

    public function generate(string $moduleName, string $queryName, array $options = []): array
    {
        $this->validate($moduleName, $queryName, $options);

        $createdFiles = [];

        // Generate query class
        $createdFiles[] = $this->generateQuery($moduleName, $queryName, $options);

        // Generate query handler
        $createdFiles[] = $this->generateQueryHandler($moduleName, $queryName, $options);

        return $createdFiles;
    }

    public function validate(string $moduleName, string $queryName, array $options = []): bool
    {
        if (empty($moduleName) || empty($queryName)) {
            return false;
        }

        if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $moduleName) || !preg_match('/^[A-Z][a-zA-Z0-9]*$/', $queryName)) {
            return false;
        }

        return true;
    }

    public function getName(): string
    {
        return 'query';
    }

    public function getSupportedOptions(): array
    {
        return [
            'aggregate' => 'Target aggregate name',
            'action' => 'Query action (get, list, find)',
            'with_caching' => 'Include caching strategy',
            'with_pagination' => 'Include pagination support',
        ];
    }

    public function getFilesToGenerate(string $moduleName, string $queryName, array $options = []): array
    {
        $modulePath = $this->getModulePath($moduleName);
        $queryPath = $modulePath . "/Application/Queries/{$queryName}";

        return [
            $queryPath . "/{$queryName}Query.php",
            $queryPath . "/{$queryName}Handler.php",
        ];
    }

    private function generateQuery(string $moduleName, string $queryName, array $options): string
    {
        $namespace = "Modules\\{$moduleName}\\Application\\Queries\\{$queryName}";
        $className = "{$queryName}Query";
        $aggregateName = $options['aggregate'] ?? $moduleName;
        $action = $options['action'] ?? 'get';

        $content = $this->stubProcessor->process('query', [
            'namespace' => $namespace,
            'class' => $className,
            'query' => $queryName,
            'aggregate' => $aggregateName,
            'aggregate_lower' => Str::lower($aggregateName),
            'action' => $action,
            'action_lower' => Str::lower($action),
            'module' => $moduleName,
            'module_lower' => Str::lower($moduleName),
            'with_caching' => $options['with_caching'] ?? true,
            'with_pagination' => $options['with_pagination'] ?? ($action === 'list'),
            'properties' => $this->getQueryProperties($action, $aggregateName),
            'cache_key' => $this->getCacheKey($action, $aggregateName),
            'cache_ttl' => $this->getCacheTtl($action),
        ]);

        $path = $this->getModulePath($moduleName) . "/Application/Queries/{$queryName}/{$className}.php";
        $this->filesystem->ensureDirectoryExists(dirname($path));
        $this->filesystem->put($path, $content);

        return $path;
    }

    private function generateQueryHandler(string $moduleName, string $queryName, array $options): string
    {
        $namespace = "Modules\\{$moduleName}\\Application\\Queries\\{$queryName}";
        $className = "{$queryName}Handler";
        $aggregateName = $options['aggregate'] ?? $moduleName;
        $action = $options['action'] ?? 'get';

        $content = $this->stubProcessor->process('query-handler', [
            'namespace' => $namespace,
            'class' => $className,
            'query' => $queryName,
            'query_class' => "{$queryName}Query",
            'aggregate' => $aggregateName,
            'aggregate_lower' => Str::lower($aggregateName),
            'action' => $action,
            'action_lower' => Str::lower($action),
            'module' => $moduleName,
            'module_lower' => Str::lower($moduleName),
            'read_model' => "{$aggregateName}ReadModel",
            'with_caching' => $options['with_caching'] ?? true,
            'with_pagination' => $options['with_pagination'] ?? ($action === 'list'),
            'handle_logic' => $this->getHandlerLogic($action, $aggregateName),
        ]);

        $path = $this->getModulePath($moduleName) . "/Application/Queries/{$queryName}/{$className}.php";
        $this->filesystem->put($path, $content);

        return $path;
    }

    private function getQueryProperties(string $action, string $aggregateName): array
    {
        switch ($action) {
            case 'get':
                return [
                    ['name' => 'id', 'type' => 'string', 'rules' => 'required|string'],
                ];

            case 'list':
                return [
                    ['name' => 'page', 'type' => 'int', 'rules' => 'nullable|integer|min:1'],
                    ['name' => 'perPage', 'type' => 'int', 'rules' => 'nullable|integer|min:1|max:100'],
                    ['name' => 'search', 'type' => 'string', 'rules' => 'nullable|string|max:255'],
                    ['name' => 'sortBy', 'type' => 'string', 'rules' => 'nullable|string|in:name,created_at'],
                    ['name' => 'sortDirection', 'type' => 'string', 'rules' => 'nullable|string|in:asc,desc'],
                ];

            case 'find':
                return [
                    ['name' => 'criteria', 'type' => 'array', 'rules' => 'required|array'],
                    ['name' => 'limit', 'type' => 'int', 'rules' => 'nullable|integer|min:1|max:100'],
                ];

            default:
                return [
                    ['name' => 'id', 'type' => 'string', 'rules' => 'required|string'],
                ];
        }
    }

    private function getCacheKey(string $action, string $aggregateName): string
    {
        $lowerAggregate = Str::lower($aggregateName);

        switch ($action) {
            case 'get':
                return "{$lowerAggregate}.{id}";
            case 'list':
                return "{$lowerAggregate}.list.{page}.{perPage}.{search}.{sortBy}.{sortDirection}";
            case 'find':
                return "{$lowerAggregate}.find." . md5(serialize(['{criteria}', '{limit}']));
            default:
                return "{$lowerAggregate}.{action}";
        }
    }

    private function getCacheTtl(string $action): int
    {
        switch ($action) {
            case 'get':
                return 3600; // 1 hour
            case 'list':
                return 900;  // 15 minutes
            case 'find':
                return 1800; // 30 minutes
            default:
                return 900;
        }
    }

    private function getHandlerLogic(string $action, string $aggregateName): string
    {
        $lowerAggregate = Str::lower($aggregateName);
        $readModel = "{$aggregateName}ReadModel";

        switch ($action) {
            case 'get':
                return <<<PHP
        // Find single record
        \${$lowerAggregate} = {$readModel}::find(\$query->id);

        if (!\${$lowerAggregate}) {
            return null;
        }

        return \${$lowerAggregate}->toArray();
PHP;

            case 'list':
                return <<<PHP
        // Build query
        \$queryBuilder = {$readModel}::query();

        // Apply search filter
        if (\$query->search) {
            \$queryBuilder->where('name', 'like', '%' . \$query->search . '%');
        }

        // Apply sorting
        \$sortBy = \$query->sortBy ?? 'created_at';
        \$sortDirection = \$query->sortDirection ?? 'desc';
        \$queryBuilder->orderBy(\$sortBy, \$sortDirection);

        // Apply pagination
        \$perPage = \$query->perPage ?? 15;
        \$page = \$query->page ?? 1;

        \$result = \$queryBuilder->paginate(\$perPage, ['*'], 'page', \$page);

        return [
            'data' => \$result->items(),
            'pagination' => [
                'current_page' => \$result->currentPage(),
                'per_page' => \$result->perPage(),
                'total' => \$result->total(),
                'last_page' => \$result->lastPage(),
            ],
        ];
PHP;

            case 'find':
                return <<<PHP
        // Build query with criteria
        \$queryBuilder = {$readModel}::query();

        foreach (\$query->criteria as \$field => \$value) {
            if (is_array(\$value)) {
                \$queryBuilder->whereIn(\$field, \$value);
            } else {
                \$queryBuilder->where(\$field, \$value);
            }
        }

        // Apply limit
        if (\$query->limit) {
            \$queryBuilder->limit(\$query->limit);
        }

        \$results = \$queryBuilder->get();

        return \$results->toArray();
PHP;

            default:
                return <<<PHP
        // Implement query logic here
        \$result = {$readModel}::find(\$query->id);

        return \$result ? \$result->toArray() : null;
PHP;
        }
    }

    private function getModulePath(string $moduleName): string
    {
        return config('modular-ddd.modules_path', base_path('modules')) . '/' . $moduleName;
    }
}