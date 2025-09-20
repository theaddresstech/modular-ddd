<?php

declare(strict_types=1);

namespace LaravelModularDDD\Generators;

use LaravelModularDDD\Generators\Contracts\GeneratorInterface;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Service Generator Agent
 *
 * Specialized generator for creating domain and application services
 * following proper DDD service patterns.
 */
final class ServiceGenerator implements GeneratorInterface
{
    private Filesystem $filesystem;
    private StubProcessor $stubProcessor;

    public function __construct(Filesystem $filesystem, StubProcessor $stubProcessor)
    {
        $this->filesystem = $filesystem;
        $this->stubProcessor = $stubProcessor;
    }

    public function generate(string $moduleName, string $serviceName, array $options = []): array
    {
        $this->validate($moduleName, $serviceName, $options);

        $createdFiles = [];
        $layer = $options['layer'] ?? 'domain';

        if ($layer === 'domain') {
            $createdFiles[] = $this->generateDomainService($moduleName, $serviceName, $options);
        } else {
            $createdFiles[] = $this->generateApplicationService($moduleName, $serviceName, $options);
        }

        return $createdFiles;
    }

    public function validate(string $moduleName, string $serviceName, array $options = []): bool
    {
        if (empty($moduleName) || empty($serviceName)) {
            return false;
        }

        if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $moduleName) || !preg_match('/^[A-Z][a-zA-Z0-9]*$/', $serviceName)) {
            return false;
        }

        $layer = $options['layer'] ?? 'domain';
        if (!in_array($layer, ['domain', 'application'])) {
            return false;
        }

        return true;
    }

    public function getName(): string
    {
        return 'service';
    }

    public function getSupportedOptions(): array
    {
        return [
            'layer' => 'Service layer (domain or application)',
            'aggregate' => 'Related aggregate name',
            'with-interface' => 'Generate service interface',
            'with-events' => 'Include event dispatching',
        ];
    }

    private function generateDomainService(string $moduleName, string $serviceName, array $options): string
    {
        $namespace = "Modules\\{$moduleName}\\Domain\\Services";
        $className = "{$serviceName}Service";

        $content = $this->stubProcessor->process('domain-service', [
            'namespace' => $namespace,
            'class' => $className,
            'service' => $serviceName,
            'service_lower' => Str::lower($serviceName),
            'aggregate' => $options['aggregate'] ?? $moduleName,
            'module' => $moduleName,
            'with_interface' => $options['with-interface'] ?? false,
            'with_events' => $options['with-events'] ?? false,
        ]);

        $path = $this->getModulePath($moduleName) . "/Domain/Services/{$className}.php";
        $this->filesystem->put($path, $content);

        return $path;
    }

    private function generateApplicationService(string $moduleName, string $serviceName, array $options): string
    {
        $namespace = "Modules\\{$moduleName}\\Application\\Services";
        $className = "{$serviceName}Service";

        $content = $this->stubProcessor->process('application-service', [
            'namespace' => $namespace,
            'class' => $className,
            'service' => $serviceName,
            'service_lower' => Str::lower($serviceName),
            'aggregate' => $options['aggregate'] ?? $moduleName,
            'module' => $moduleName,
            'with_interface' => $options['with-interface'] ?? false,
            'with_events' => $options['with-events'] ?? true,
        ]);

        $path = $this->getModulePath($moduleName) . "/Application/Services/{$className}.php";
        $this->filesystem->put($path, $content);

        return $path;
    }

    private function getModulePath(string $moduleName): string
    {
        return config('modular-ddd.modules_path', base_path('modules')) . '/' . $moduleName;
    }
}