<?php

declare(strict_types=1);

namespace LaravelModularDDD\Generators;

use LaravelModularDDD\Generators\Contracts\GeneratorInterface;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Repository Generator Agent
 *
 * Specialized generator for creating event-sourced repository patterns
 * with proper abstraction and infrastructure separation.
 */
final class RepositoryGenerator implements GeneratorInterface
{
    private Filesystem $filesystem;
    private StubProcessor $stubProcessor;

    public function __construct(Filesystem $filesystem, StubProcessor $stubProcessor)
    {
        $this->filesystem = $filesystem;
        $this->stubProcessor = $stubProcessor;
    }

    public function generate(string $moduleName, string $aggregateName, array $options = []): array
    {
        $this->validate($moduleName, $aggregateName, $options);

        $createdFiles = [];

        // Generate repository interface (Domain layer)
        $createdFiles[] = $this->generateRepositoryInterface($moduleName, $aggregateName, $options);

        // Generate event-sourced repository implementation (Infrastructure layer)
        $createdFiles[] = $this->generateEventSourcedRepository($moduleName, $aggregateName, $options);

        return $createdFiles;
    }

    public function validate(string $moduleName, string $aggregateName, array $options = []): bool
    {
        if (empty($moduleName) || empty($aggregateName)) {
            return false;
        }

        if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $moduleName) || !preg_match('/^[A-Z][a-zA-Z0-9]*$/', $aggregateName)) {
            return false;
        }

        return true;
    }

    public function getName(): string
    {
        return 'repository';
    }

    public function getSupportedOptions(): array
    {
        return [
            'with_caching' => 'Include caching layer',
            'with_specifications' => 'Include specification pattern support',
            'event-sourced' => 'Use event sourcing (default: true)',
        ];
    }

    private function generateRepositoryInterface(string $moduleName, string $aggregateName, array $options): string
    {
        $namespace = "Modules\\{$moduleName}\\Domain\\Repositories";
        $className = "{$aggregateName}RepositoryInterface";

        $content = $this->stubProcessor->process('repository-interface', [
            'namespace' => $namespace,
            'class' => $className,
            'aggregate' => $aggregateName,
            'aggregate_lower' => Str::lower($aggregateName),
            'module' => $moduleName,
            'module_lower' => Str::lower($moduleName),
            'id_class' => "{$aggregateName}Id",
            'with_specifications' => $options['with_specifications'] ?? false,
        ]);

        $path = $this->getModulePath($moduleName) . "/Domain/Repositories/{$className}.php";
        $this->filesystem->put($path, $content);

        return $path;
    }

    private function generateEventSourcedRepository(string $moduleName, string $aggregateName, array $options): string
    {
        $namespace = "Modules\\{$moduleName}\\Infrastructure\\Persistence\\EventStore";
        $className = "EventSourced{$aggregateName}Repository";

        $content = $this->stubProcessor->process('event-sourced-repository', [
            'namespace' => $namespace,
            'class' => $className,
            'aggregate' => $aggregateName,
            'aggregate_lower' => Str::lower($aggregateName),
            'id_class' => "{$aggregateName}Id",
            'interface' => "{$aggregateName}RepositoryInterface",
            'module' => $moduleName,
            'module_lower' => Str::lower($moduleName),
            'with_caching' => $options['with_caching'] ?? true,
            'with_specifications' => $options['with_specifications'] ?? false,
        ]);

        $path = $this->getModulePath($moduleName) . "/Infrastructure/Persistence/EventStore/{$className}.php";
        $this->filesystem->put($path, $content);

        return $path;
    }

    private function getModulePath(string $moduleName): string
    {
        return config('modular-ddd.modules_path', base_path('modules')) . '/' . $moduleName;
    }
}