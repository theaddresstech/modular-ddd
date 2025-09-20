<?php

declare(strict_types=1);

namespace LaravelModularDDD\Generators;

use LaravelModularDDD\Generators\Contracts\GeneratorInterface;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Command Generator Agent
 *
 * Specialized generator for creating CQRS commands and handlers
 * with proper validation and authorization.
 */
final class CommandGenerator implements GeneratorInterface
{
    private Filesystem $filesystem;
    private StubProcessor $stubProcessor;

    public function __construct(Filesystem $filesystem, StubProcessor $stubProcessor)
    {
        $this->filesystem = $filesystem;
        $this->stubProcessor = $stubProcessor;
    }

    public function generate(string $moduleName, string $commandName, array $options = []): array
    {
        $this->validate($moduleName, $commandName, $options);

        $createdFiles = [];

        // Generate command class
        $createdFiles[] = $this->generateCommand($moduleName, $commandName, $options);

        // Generate command handler
        $createdFiles[] = $this->generateCommandHandler($moduleName, $commandName, $options);

        return $createdFiles;
    }

    public function validate(string $moduleName, string $commandName, array $options = []): bool
    {
        if (empty($moduleName) || empty($commandName)) {
            return false;
        }

        if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $moduleName) || !preg_match('/^[A-Z][a-zA-Z0-9]*$/', $commandName)) {
            return false;
        }

        return true;
    }

    public function getName(): string
    {
        return 'command';
    }

    public function getSupportedOptions(): array
    {
        return [
            'aggregate' => 'Target aggregate name',
            'action' => 'Command action (create, update, delete)',
            'async' => 'Make command asynchronous',
            'with_authorization' => 'Include authorization checks',
        ];
    }

    private function generateCommand(string $moduleName, string $commandName, array $options): string
    {
        $namespace = "Modules\\{$moduleName}\\Application\\Commands\\{$commandName}";
        $className = "{$commandName}Command";
        $aggregateName = $options['aggregate'] ?? $moduleName;
        $action = $options['action'] ?? 'create';

        $content = $this->stubProcessor->process('command', [
            'namespace' => $namespace,
            'class' => $className,
            'command' => $commandName,
            'aggregate' => $aggregateName,
            'aggregate_lower' => Str::lower($aggregateName),
            'action' => $action,
            'module' => $moduleName,
            'async' => $options['async'] ?? false,
            'with_authorization' => $options['with_authorization'] ?? true,
            'properties' => $this->getCommandProperties($action, $aggregateName),
        ]);

        $path = $this->getModulePath($moduleName) . "/Application/Commands/{$commandName}/{$className}.php";
        $this->filesystem->ensureDirectoryExists(dirname($path));
        $this->filesystem->put($path, $content);

        return $path;
    }

    private function generateCommandHandler(string $moduleName, string $commandName, array $options): string
    {
        $namespace = "Modules\\{$moduleName}\\Application\\Commands\\{$commandName}";
        $className = "{$commandName}Handler";
        $aggregateName = $options['aggregate'] ?? $moduleName;
        $action = $options['action'] ?? 'create';

        $content = $this->stubProcessor->process('command-handler', [
            'namespace' => $namespace,
            'class' => $className,
            'command' => $commandName,
            'command_class' => "{$commandName}Command",
            'aggregate' => $aggregateName,
            'aggregate_lower' => Str::lower($aggregateName),
            'action' => $action,
            'module' => $moduleName,
            'repository_interface' => "{$aggregateName}RepositoryInterface",
            'handle_logic' => $this->getHandlerLogic($action, $aggregateName),
        ]);

        $path = $this->getModulePath($moduleName) . "/Application/Commands/{$commandName}/{$className}.php";
        $this->filesystem->put($path, $content);

        return $path;
    }

    private function getCommandProperties(string $action, string $aggregateName): array
    {
        switch ($action) {
            case 'create':
                return [
                    ['name' => 'name', 'type' => 'string', 'rules' => 'required|string|max:255'],
                    ['name' => 'description', 'type' => 'string', 'rules' => 'nullable|string|max:1000'],
                ];

            case 'update':
                return [
                    ['name' => 'id', 'type' => "{$aggregateName}Id", 'rules' => 'required'],
                    ['name' => 'name', 'type' => 'string', 'rules' => 'required|string|max:255'],
                    ['name' => 'description', 'type' => 'string', 'rules' => 'nullable|string|max:1000'],
                ];

            case 'delete':
                return [
                    ['name' => 'id', 'type' => "{$aggregateName}Id", 'rules' => 'required'],
                ];

            default:
                return [
                    ['name' => 'id', 'type' => "{$aggregateName}Id", 'rules' => 'required'],
                ];
        }
    }

    private function getHandlerLogic(string $action, string $aggregateName): string
    {
        $lowerAggregate = Str::lower($aggregateName);

        switch ($action) {
            case 'create':
                return <<<PHP
        // Create new aggregate
        \${$lowerAggregate} = {$aggregateName}::create(
            {$aggregateName}Id::generate(),
            \$command->name,
            \$command->description
        );

        // Save to repository
        \$this->repository->save(\${$lowerAggregate});

        return \${$lowerAggregate}->getId();
PHP;

            case 'update':
                return <<<PHP
        // Load aggregate from repository
        \${$lowerAggregate} = \$this->repository->findById(\$command->id);

        if (!\${$lowerAggregate}) {
            throw {$aggregateName}Exception::notFound(\$command->id);
        }

        // Update aggregate
        \${$lowerAggregate}->update(
            \$command->name,
            \$command->description
        );

        // Save changes
        \$this->repository->save(\${$lowerAggregate});

        return \${$lowerAggregate}->getId();
PHP;

            case 'delete':
                return <<<PHP
        // Load aggregate from repository
        \${$lowerAggregate} = \$this->repository->findById(\$command->id);

        if (!\${$lowerAggregate}) {
            throw {$aggregateName}Exception::notFound(\$command->id);
        }

        // Mark as deleted
        \${$lowerAggregate}->delete();

        // Save changes
        \$this->repository->save(\${$lowerAggregate});

        return \$command->id;
PHP;

            default:
                return <<<PHP
        // Load aggregate from repository
        \${$lowerAggregate} = \$this->repository->findById(\$command->id);

        if (!\${$lowerAggregate}) {
            throw {$aggregateName}Exception::notFound(\$command->id);
        }

        // Implement command logic here

        // Save changes
        \$this->repository->save(\${$lowerAggregate});

        return \${$lowerAggregate}->getId();
PHP;
        }
    }

    private function getModulePath(string $moduleName): string
    {
        return config('modular-ddd.modules_path', base_path('modules')) . '/' . $moduleName;
    }
}