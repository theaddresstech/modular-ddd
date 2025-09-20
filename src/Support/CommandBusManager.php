<?php

declare(strict_types=1);

namespace LaravelModularDDD\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Collection;

/**
 * CommandBusManager
 *
 * Manages CQRS command bus operations across modules.
 * Handles command routing, handler registration, and middleware.
 */
final class CommandBusManager
{
    private array $handlers = [];
    private array $middleware = [];
    private array $routes = [];

    public function __construct(
        private readonly Application $app
    ) {}

    /**
     * Register a command handler.
     */
    public function registerHandler(string $command, string $handler): void
    {
        $this->handlers[$command] = $handler;
        $this->routes[$command] = $handler;
    }

    /**
     * Register multiple handlers.
     */
    public function registerHandlers(array $handlers): void
    {
        foreach ($handlers as $command => $handler) {
            $this->registerHandler($command, $handler);
        }
    }

    /**
     * Get handler for a command.
     */
    public function getHandler(string $command): ?string
    {
        return $this->handlers[$command] ?? null;
    }

    /**
     * Check if command has a registered handler.
     */
    public function hasHandler(string $command): bool
    {
        return isset($this->handlers[$command]);
    }

    /**
     * Get all registered handlers.
     */
    public function getHandlers(): array
    {
        return $this->handlers;
    }

    /**
     * Register global middleware.
     */
    public function addMiddleware(string $middleware): void
    {
        if (!in_array($middleware, $this->middleware, true)) {
            $this->middleware[] = $middleware;
        }
    }

    /**
     * Register middleware for specific command.
     */
    public function addCommandMiddleware(string $command, string $middleware): void
    {
        if (!isset($this->middleware[$command])) {
            $this->middleware[$command] = [];
        }

        if (!in_array($middleware, $this->middleware[$command], true)) {
            $this->middleware[$command][] = $middleware;
        }
    }

    /**
     * Get middleware for command.
     */
    public function getMiddleware(string $command): array
    {
        $global = array_filter($this->middleware, 'is_string');
        $specific = $this->middleware[$command] ?? [];

        return array_merge($global, $specific);
    }

    /**
     * Dispatch a command.
     */
    public function dispatch(object $command): mixed
    {
        $commandClass = get_class($command);
        $handler = $this->getHandler($commandClass);

        if (!$handler) {
            throw new \InvalidArgumentException("No handler registered for command: {$commandClass}");
        }

        $handlerInstance = $this->app->make($handler);
        $middleware = $this->getMiddleware($commandClass);

        return $this->executeWithMiddleware($command, $handlerInstance, $middleware);
    }

    /**
     * Execute command with middleware pipeline.
     */
    private function executeWithMiddleware(object $command, object $handler, array $middleware): mixed
    {
        $pipeline = array_reduce(
            array_reverse($middleware),
            function ($carry, $middlewareClass) {
                return function ($command) use ($carry, $middlewareClass) {
                    $middleware = $this->app->make($middlewareClass);
                    return $middleware->handle($command, $carry);
                };
            },
            function ($command) use ($handler) {
                return $handler->handle($command);
            }
        );

        return $pipeline($command);
    }

    /**
     * Auto-discover command handlers in modules.
     */
    public function discoverHandlers(): void
    {
        $registry = $this->app->make(ModuleRegistry::class);
        $modules = $registry->getEnabledModules();

        foreach ($modules as $module) {
            $this->discoverModuleHandlers($module);
        }
    }

    /**
     * Discover handlers in a specific module.
     */
    private function discoverModuleHandlers(array $module): void
    {
        $moduleName = $module['name'];
        $commandHandlers = $module['components']['handlers']['command_handlers'] ?? [];

        foreach ($commandHandlers as $handlerInfo) {
            $handlerClass = $handlerInfo['class'] ?? null;

            if (!$handlerClass || !class_exists($handlerClass)) {
                continue;
            }

            $commandClass = $this->extractCommandFromHandler($handlerClass);

            if ($commandClass && class_exists($commandClass)) {
                $this->registerHandler($commandClass, $handlerClass);
            }
        }
    }

    /**
     * Extract command class from handler.
     */
    private function extractCommandFromHandler(string $handlerClass): ?string
    {
        // Try to determine command from handler name
        // e.g., CreateUserCommandHandler -> CreateUserCommand
        if (preg_match('/(.+)Handler$/', $handlerClass, $matches)) {
            $commandName = $matches[1];
            $commandClass = str_replace('\\Commands\\Handlers\\', '\\Commands\\', $handlerClass);
            $commandClass = str_replace($handlerClass, $commandName, $commandClass);

            return $commandClass;
        }

        // Try reflection to find handle method parameter type
        try {
            $reflection = new \ReflectionClass($handlerClass);
            $handleMethod = $reflection->getMethod('handle');
            $parameters = $handleMethod->getParameters();

            if (count($parameters) > 0) {
                $firstParam = $parameters[0];
                $type = $firstParam->getType();

                if ($type instanceof \ReflectionNamedType) {
                    return $type->getName();
                }
            }
        } catch (\ReflectionException $e) {
            // Ignore reflection errors
        }

        return null;
    }

    /**
     * Get command routing statistics.
     */
    public function getStatistics(): array
    {
        return [
            'total_handlers' => count($this->handlers),
            'global_middleware' => count(array_filter($this->middleware, 'is_string')),
            'commands_with_specific_middleware' => count(array_filter($this->middleware, 'is_array')),
            'modules_with_handlers' => $this->getModulesWithHandlers(),
        ];
    }

    /**
     * Get modules that have command handlers.
     */
    private function getModulesWithHandlers(): int
    {
        $modules = [];

        foreach ($this->handlers as $command => $handler) {
            if (preg_match('/Modules\\\\(\w+)\\\\/', $handler, $matches)) {
                $modules[$matches[1]] = true;
            }
        }

        return count($modules);
    }

    /**
     * Validate command bus configuration.
     */
    public function validate(): array
    {
        $issues = [];

        // Check for handlers without corresponding commands
        foreach ($this->handlers as $command => $handler) {
            if (!class_exists($command)) {
                $issues[] = [
                    'type' => 'missing_command',
                    'message' => "Command class does not exist: {$command}",
                    'handler' => $handler,
                ];
            }

            if (!class_exists($handler)) {
                $issues[] = [
                    'type' => 'missing_handler',
                    'message' => "Handler class does not exist: {$handler}",
                    'command' => $command,
                ];
            }
        }

        // Check for duplicate handlers
        $handlerCounts = array_count_values($this->handlers);
        foreach ($handlerCounts as $handler => $count) {
            if ($count > 1) {
                $commands = array_keys($this->handlers, $handler);
                $issues[] = [
                    'type' => 'duplicate_handler',
                    'message' => "Handler {$handler} is registered for multiple commands",
                    'commands' => $commands,
                ];
            }
        }

        // Check middleware classes
        $allMiddleware = array_merge(
            array_filter($this->middleware, 'is_string'),
            ...array_filter($this->middleware, 'is_array')
        );

        foreach (array_unique($allMiddleware) as $middleware) {
            if (!class_exists($middleware)) {
                $issues[] = [
                    'type' => 'missing_middleware',
                    'message' => "Middleware class does not exist: {$middleware}",
                ];
            }
        }

        return $issues;
    }

    /**
     * Export command bus configuration.
     */
    public function export(): array
    {
        return [
            'handlers' => $this->handlers,
            'middleware' => $this->middleware,
            'routes' => $this->routes,
            'statistics' => $this->getStatistics(),
            'validation_issues' => $this->validate(),
        ];
    }

    /**
     * Clear all registered handlers and middleware.
     */
    public function clear(): void
    {
        $this->handlers = [];
        $this->middleware = [];
        $this->routes = [];
    }

    /**
     * Get command execution metrics.
     */
    public function getMetrics(): array
    {
        // In a real implementation, this would track:
        // - Command execution times
        // - Success/failure rates
        // - Handler performance
        // - Middleware impact

        return [
            'total_executions' => 0,
            'average_execution_time' => 0,
            'success_rate' => 100,
            'most_used_commands' => [],
            'slowest_handlers' => [],
        ];
    }
}