<?php

declare(strict_types=1);

namespace LaravelModularDDD\Generators;

use LaravelModularDDD\Generators\Contracts\GeneratorInterface;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Aggregate Generator Agent
 *
 * Specialized generator for creating event-sourced aggregate roots
 * following DDD tactical patterns.
 */
final class AggregateGenerator implements GeneratorInterface
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
        $modulePath = $this->getModulePath($moduleName);

        // Generate aggregate root
        $createdFiles[] = $this->generateAggregateRoot($moduleName, $aggregateName, $options);

        // Generate aggregate exception
        $createdFiles[] = $this->generateAggregateException($moduleName, $aggregateName, $options);

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
        return 'aggregate';
    }

    public function getSupportedOptions(): array
    {
        return [
            'with-validation' => 'Include business rule validation',
            'with-specifications' => 'Generate specification classes',
            'with-entities' => 'Generate related entities',
        ];
    }

    private function generateAggregateRoot(string $moduleName, string $aggregateName, array $options): string
    {
        $namespace = "Modules\\{$moduleName}\\Domain\\Models";
        $className = $aggregateName;

        $content = $this->stubProcessor->process('aggregate-root', [
            'namespace' => $namespace,
            'class' => $className,
            'aggregate' => $aggregateName,
            'aggregate_lower' => Str::lower($aggregateName),
            'module' => $moduleName,
            'id_class' => "{$aggregateName}Id",
            'with_validation' => $options['with-validation'] ?? true,
        ]);

        $path = $this->getModulePath($moduleName) . "/Domain/Models/{$className}.php";
        $this->filesystem->put($path, $content);

        return $path;
    }

    private function generateAggregateException(string $moduleName, string $aggregateName, array $options): string
    {
        $namespace = "Modules\\{$moduleName}\\Domain\\Exceptions";
        $className = "{$aggregateName}Exception";

        $content = $this->stubProcessor->process('aggregate-exception', [
            'namespace' => $namespace,
            'class' => $className,
            'aggregate' => $aggregateName,
            'aggregate_lower' => Str::lower($aggregateName),
        ]);

        $path = $this->getModulePath($moduleName) . "/Domain/Exceptions/{$className}.php";
        $this->filesystem->put($path, $content);

        return $path;
    }

    private function getModulePath(string $moduleName): string
    {
        return config('modular-ddd.modules_path', base_path('modules')) . '/' . $moduleName;
    }
}